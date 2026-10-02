<?php
require_once __DIR__ . '/../env.php';
require_once __DIR__ . '/../lib/mailer.php';

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

register_shutdown_function(function() {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])) {
        if (!headers_sent()) {
            header('Content-Type: application/json', true, 500);
        } else {
            http_response_code(500);
        }
        echo json_encode(['error' => 'Internal server error', 'detail' => $e['message']]);
    }
});

set_error_handler(function($severity, $msg, $file, $line) {
    if (!(error_reporting() & $severity)) return false;
    http_response_code(500);
    echo json_encode(['error' => $msg, 'file' => $file, 'line' => $line]);
    exit;
});

$db = new mysqli('127.0.0.1', 'root', '', 'mall_portal', 3306);
if ($db->connect_error) { http_response_code(500); echo json_encode(['error'=>'DB connection failed: '.$db->connect_error]); exit; }
mysqli_report(MYSQLI_REPORT_OFF);

define('SILVANIX_API_URL', 'https://app.silvanix.com/api/v2/send-message');

function fetchOne($sql, $types = '', ...$params) {
    global $db;
    $stmt = $db->prepare($sql);
    if (!$stmt) { http_response_code(500); echo json_encode(['error'=>'Prepare failed: '.$db->error]); exit; }
    if ($types !== '') $stmt->bind_param($types, ...$params);
    if (!$stmt->execute()) { http_response_code(500); echo json_encode(['error'=>'Execute failed: '.$stmt->error]); exit; }
    $r = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $r ?: null;
}

function fetchAll($sql, $types = '', ...$params) {
    global $db;
    $stmt = $db->prepare($sql);
    if (!$stmt) { http_response_code(500); echo json_encode(['error'=>'Prepare failed: '.$db->error]); exit; }
    if ($types !== '') $stmt->bind_param($types, ...$params);
    if (!$stmt->execute()) { http_response_code(500); echo json_encode(['error'=>'Execute failed: '.$stmt->error]); exit; }
    $r = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $r;
}

function querySingle($sql, $types = '', ...$params) {
    global $db;
    $stmt = $db->prepare($sql);
    if (!$stmt) { http_response_code(500); echo json_encode(['error'=>'Prepare failed: '.$db->error]); exit; }
    if ($types !== '') $stmt->bind_param($types, ...$params);
    if (!$stmt->execute()) { http_response_code(500); echo json_encode(['error'=>'Execute failed: '.$stmt->error]); exit; }
    $affected = $stmt->affected_rows;
    $insertId = $stmt->insert_id;
    $stmt->close();
    return ['affected' => $affected, 'insert_id' => $insertId];
}

ini_set('session.gc_maxlifetime', 86400);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime' => 86400,
        'path' => '/',
        'httponly' => true,
        'secure' => !empty($_SERVER['HTTPS']),
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    session_start();
}

function jsonError($msg, $code = 400) { http_response_code($code); $j = json_encode(['error' => $msg]); echo $j === false ? '{"error":"json_encode_failed"}' : $j; exit; }
function jsonSuccess($data = null) { $j = json_encode($data === null ? ['success' => true] : $data); echo $j === false ? '{"error":"json_encode_failed"}' : $j; exit; }

function auditLog($userId, $action, $details = '') {
    global $db;
    $userId = $userId !== null ? (int)$userId : null;
    $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 50);
    $stmt = $db->prepare('INSERT INTO audit_logs (user_id, action, details, ip_address) VALUES (?,?,?,?)');
    if (!$stmt) return;
    $stmt->bind_param('isss', $userId, $action, $details, $ip);
    $stmt->execute();
    $stmt->close();
}

function normalizeVa($va) {
    $va = trim((string)$va);
    if ($va === '') return '';
    if (preg_match('/[eE][+-]?\d/', $va) || preg_match('/^[+-]?\.?\d+e\d+$/', $va)) return '';
    $va = preg_replace('/[^A-Za-z0-9]/', '', $va);
    if (strpos($va, '00535') !== 0) $va = '00535' . $va;
    return $va;
}

function recalcBill($billId) {
    $sum = fetchOne('SELECT COALESCE(SUM(amount),0) as total FROM payments WHERE billing_id=?', 'i', (int)$billId);
    $bill = fetchOne('SELECT id, amount FROM billing WHERE id=?', 'i', (int)$billId);
    if (!$bill) return;
    $paid = (float)($sum['total'] ?? 0);
    $status = ($paid > 0) ? 'paid' : 'pending';
    querySingle('UPDATE billing SET paid=?, status=? WHERE id=?', 'dsi', $paid, $status, (int)$billId);
}

function normDate($d) {
    $d = trim((string)$d);
    if ($d === '') return '';
    $d = preg_replace('/[T ].*\d{1,2}:\d{2}(:\d{2})?.*$/', '', $d);
    if (preg_match('#^(\d{1,4})[-/\.](\d{1,2})[-/\.](\d{1,4})$#', $d, $m)) {
        $a = (int)$m[1]; $b = (int)$m[2]; $c = (int)$m[3];
        if ($c >= 1000) return sprintf('%04d-%02d-%02d', $c, $b, $a);
        return sprintf('%04d-%02d-%02d', $a, $b, $c);
    }
    $t = strtotime($d);
    return $t === false ? $d : date('Y-m-d', $t);
}

function normalizeWaPhone($phone, $countryCode = '62') {
    $p = preg_replace('/[^\d+]/', '', (string)$phone);
    if ($p === '') return '';
    if ($p[0] === '+') return substr($p, 1);
    if ($p[0] === '0') return $countryCode . substr($p, 1);
    return $p;
}

function httpPostJson($url, $payload, $headers = [], $timeout = 15) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers),
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return ['code' => $code, 'body' => $body, 'err' => $err];
}

function httpPostForm($url, $payload, $headers = [], $timeout = 15) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($payload),
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/x-www-form-urlencoded'], $headers),
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return ['code' => $code, 'body' => $body, 'err' => $err];
}

function sendWaApi($provider, $token, $from, $phone, $message, $scheduledAt = '', $pdfUrl = '') {
    switch ($provider) {
        case 'fonnte':
            $payload = ['target' => $phone, 'message' => $message, 'countryCode' => '62'];
            if ($scheduledAt !== '') $payload['schedule'] = strtotime($scheduledAt) ?: $scheduledAt;
            if ($pdfUrl !== '') $payload['url'] = $pdfUrl;
            $r = httpPostForm('https://api.fonnte.com/send', $payload, ['Authorization: ' . $token]);
            $j = json_decode($r['body'], true) ?: [];
            if (!$r['err'] && (!empty($j['status']) || !empty($j['id']))) {
                $mid = $j['id'] ?? null;
                if (is_array($mid)) $mid = $mid[0] ?? null;
                return ['success' => true, 'message_id' => $mid, 'raw' => $j];
            }
            $reason = $j['reason'] ?? ($r['err'] ?: 'Fonnte API error');
            if (is_array($reason)) $reason = implode(', ', $reason);
            return ['success' => false, 'error' => $reason, 'raw' => $j];
        case 'silvanix':
            $payload = [
                'id' => 'WA-' . bin2hex(random_bytes(8)),
                'address' => $phone,
                'imType' => 'whatsapp',
                'contentType' => 'TEXT',
                'text' => $message,
            ];
            $r = httpPostJson(SILVANIX_API_URL, $payload, ['Authorization: ' . $token]);
            $j = json_decode($r['body'], true) ?: [];
            $code = $r['code'];
            $ok = ($code >= 200 && $code < 300) || (!empty($j['status']) && ($j['status'] === 'success' || $j['status'] === 'sent'));
            if (!$r['err'] && $ok) {
                $mid = $j['id'] ?? ($j['message_id'] ?? null);
                if (is_array($mid)) $mid = $mid[0] ?? null;
                return ['success' => true, 'message_id' => $mid, 'raw' => $j];
            }
            $reason = $j['message'] ?? ($j['error'] ?? ($j['detail'] ?? ($r['err'] ?: 'Silvanix API error')));
            if (is_array($reason)) $reason = implode(', ', $reason);
            return ['success' => false, 'error' => $reason, 'raw' => $j, 'code' => $code];
        case 'wablas':
            $endpoint = $pdfUrl !== ''
                ? 'https://patp.wablas.com/api/send-document'
                : 'https://patp.wablas.com/api/send-message';
            $payload = $pdfUrl !== ''
                ? ['phone' => $phone, 'document' => $pdfUrl, 'caption' => $message]
                : ['phone' => $phone, 'message' => $message];
            if ($scheduledAt !== '') $payload['scheduledAt'] = $scheduledAt;
            $r = httpPostJson($endpoint, $payload, ['Authorization: ' . $token]);
            $j = json_decode($r['body'], true) ?: [];
            if (($j['status'] ?? false) === true || $j['status'] === 1) {
                return ['success' => true, 'message_id' => $j['data']['id'] ?? null, 'raw' => $j];
            }
            return ['success' => false, 'error' => $j['message'] ?? ($j['data']['reason'] ?? ($r['err'] ?: 'Wablas API error')), 'raw' => $j];
        case 'twilio':
            $parts = array_pad(explode(':', $token), 2, '');
            $accountSid = $parts[0];
            $authToken = $parts[1];
            if ($accountSid === '' || $authToken === '') return ['success' => false, 'error' => 'Twilio token must be accountSid:authToken'];
            $payload = [
                'To'   => 'whatsapp:' . $phone,
                'From' => 'whatsapp:' . ($from !== '' ? $from : $accountSid),
                'Body' => $message,
            ];
            if ($pdfUrl !== '') $payload['MediaUrl'] = $pdfUrl;
            $ch = curl_init('https://api.twilio.com/2010-04-01/Accounts/' . $accountSid . '/Messages.json');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query($payload),
                CURLOPT_USERPWD => $accountSid . ':' . $authToken,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            $body = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            $j = json_decode($body, true) ?: [];
            if ($code >= 200 && $code < 300 && !empty($j['sid'])) {
                return ['success' => true, 'message_id' => $j['sid'], 'raw' => $j];
            }
            return ['success' => false, 'error' => $j['message'] ?? ($err ?: 'Twilio API error'), 'raw' => $j];
        case 'whatsapp_cloud':
        case 'meta':
            if ($from === '') return ['success' => false, 'error' => 'WhatsApp Cloud requires a Phone Number ID (set as "From")'];
            $payload = ['messaging_product' => 'whatsapp', 'to' => $phone];
            if ($pdfUrl !== '') {
                $payload['type'] = 'document';
                $payload['document'] = ['link' => $pdfUrl, 'filename' => 'billing.pdf', 'caption' => $message];
            } else {
                $payload['type'] = 'text';
                $payload['text'] = ['preview_url' => true, 'body' => $message];
            }
            $r = httpPostJson('https://graph.facebook.com/v21.0/' . $from . '/messages', $payload, ['Authorization: Bearer ' . $token]);
            $j = json_decode($r['body'], true) ?: [];
            if (!empty($j['messages'][0]['id'])) {
                return ['success' => true, 'message_id' => $j['messages'][0]['id'], 'raw' => $j];
            }
            return ['success' => false, 'error' => $j['error']['message'] ?? ($r['err'] ?: 'WhatsApp Cloud API error'), 'raw' => $j];
        default:
            return ['success' => false, 'error' => 'Unsupported provider: ' . $provider];
    }
}

function ensureWaSettingsTable() {
    global $db;
    $db->query("CREATE TABLE IF NOT EXISTS wa_settings (
        id INT PRIMARY KEY,
        provider VARCHAR(50) NOT NULL DEFAULT 'fonnte',
        api_token VARCHAR(500) NOT NULL DEFAULT '',
        from_number VARCHAR(255) NOT NULL DEFAULT '',
        template TEXT,
        mode VARCHAR(10) NOT NULL DEFAULT 'api',
        attach_pdf TINYINT(1) NOT NULL DEFAULT 0,
        pdf_url VARCHAR(500) NOT NULL DEFAULT '',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->query("INSERT IGNORE INTO wa_settings (id) VALUES (1)");
    foreach (['mode', 'attach_pdf', 'pdf_url'] as $col) {
        $has = $db->query("SHOW COLUMNS FROM wa_settings LIKE '$col'");
        if ($has && $has->num_rows === 0) {
            $defs = ['mode' => "ADD COLUMN mode VARCHAR(10) NOT NULL DEFAULT 'api'", 'attach_pdf' => 'ADD COLUMN attach_pdf TINYINT(1) NOT NULL DEFAULT 0', 'pdf_url' => "ADD COLUMN pdf_url VARCHAR(500) NOT NULL DEFAULT ''"];
            $db->query("ALTER TABLE wa_settings " . $defs[$col]);
        }
    }
}

function ensureAstSettingsTable() {
    static $done = false;
    if ($done) return;
    $done = true;
    global $db;
    $db->query("CREATE TABLE IF NOT EXISTS ast_settings (
        id INT PRIMARY KEY,
        host VARCHAR(255) NOT NULL DEFAULT '10.10.21.87',
        port INT NOT NULL DEFAULT 1433,
        database_name VARCHAR(255) NOT NULL DEFAULT 'pms_standard',
        username VARCHAR(255) NOT NULL DEFAULT '',
        password VARCHAR(255) NOT NULL DEFAULT '',
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->query("INSERT IGNORE INTO ast_settings (id) VALUES (1)");
}

function astGetSettings() {
    ensureAstSettingsTable();
    $s = fetchOne('SELECT host, port, database_name, username, password FROM ast_settings WHERE id = 1');
    return $s ?: ['host' => '10.10.21.87', 'port' => 1433, 'database_name' => 'pms_standard', 'username' => '', 'password' => ''];
}

function astConnect($settings) {
    if (!class_exists('PDO') || !defined('PDO::SQLSRV_ATTR_ENCODING')) {
        return ['error' => 'pdo_sqlsrv extension is not available. Enable extension=pdo_sqlsrv in php.ini and restart the web server.'];
    }
    $host = trim($settings['host'] ?? '');
    $port = (int)($settings['port'] ?? 1433);
    $dbName = trim($settings['database_name'] ?? '');
    if ($host === '' || $dbName === '') return ['error' => 'SQL Server host and database are not configured. Save them in the connection settings below.'];
    $dsn = sprintf('sqlsrv:Server=%s,%d;Database=%s;LoginTimeout=5;Encrypt=yes;TrustServerCertificate=yes', $host, $port, $dbName);
    try {
        $conn = new PDO($dsn, trim($settings['username'] ?? ''), (string)($settings['password'] ?? ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8,
        ]);
        $conn->exec('SET DATEFORMAT dmy');
        return ['conn' => $conn];
    } catch (Throwable $e) {
        return ['error' => 'SQL Server connection failed: ' . $e->getMessage()];
    }
}

function astTypedValue($v) {
    if ($v instanceof DateTime) return $v->format('Y-m-d H:i:s');
    if (is_float($v)) return is_nan($v) || is_infinite($v) ? '' : (string)$v;
    if ($v === null) return '';
    return (string)$v;
}

function astTableColumns($conn) {
    try {
        $stmt = $conn->query("SELECT COLUMN_NAME, DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='ar_paytrx' ORDER BY ORDINAL_POSITION");
        $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return ['error' => 'Could not read INFORMATION_SCHEMA for ar_paytrx: ' . $e->getMessage()];
    }
    if (!count($cols)) return ['error' => 'Table dbo.ar_paytrx not found in database'];
    $cols = array_map(fn($c) => ['name' => $c['COLUMN_NAME'], 'type' => strtolower((string)$c['DATA_TYPE'])], $cols);
    $dateCol = null;
    foreach ($cols as $c) {
        if (in_array($c['type'], ['date', 'datetime', 'datetime2', 'smalldatetime'])) { $dateCol = $c['name']; break; }
    }
    if (!$dateCol) {
        foreach ($cols as $c) {
            if (preg_match('/date|tgl|trx/i', $c['name']) && in_array($c['type'], ['date', 'datetime', 'datetime2', 'smalldatetime'])) { $dateCol = $c['name']; break; }
        }
    }
    return ['cols' => $cols, 'date_col' => $dateCol];
}

function ensureWaBillingColumns() {
    static $done = false;
    if ($done) return;
    $done = true;
    global $db;
    $has = $db->query("SHOW COLUMNS FROM billing LIKE 'wa_status'");
    if ($has && $has->num_rows === 0) {
        $db->query("ALTER TABLE billing ADD COLUMN wa_status VARCHAR(20) DEFAULT NULL, ADD COLUMN wa_sent_at DATETIME DEFAULT NULL, ADD COLUMN wa_message_id VARCHAR(255) DEFAULT NULL");
    }
}

function ensureTicketResponseColumn() {
    static $done = false;
    if ($done) return;
    $done = true;
    global $db;
    $has = $db->query("SHOW COLUMNS FROM support_tickets LIKE 'response'");
    if ($has && $has->num_rows === 0) {
        $db->query("ALTER TABLE support_tickets ADD COLUMN response TEXT DEFAULT NULL, ADD COLUMN responded_at DATETIME DEFAULT NULL");
    }
}

function ensureTicketMessagesTable() {
    static $done = false;
    if ($done) return;
    $done = true;
    global $db;
    $db->query("CREATE TABLE IF NOT EXISTS ticket_messages (
        id INT NOT NULL AUTO_INCREMENT,
        ticket_id INT NOT NULL,
        sender_role VARCHAR(20) NOT NULL DEFAULT 'customer',
        message TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY ticket_id (ticket_id),
        CONSTRAINT ticket_messages_ibfk_1 FOREIGN KEY (ticket_id) REFERENCES support_tickets (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function ensureTicketReadColumns() {
    static $done = false;
    if ($done) return;
    $done = true;
    global $db;
    $has = $db->query("SHOW COLUMNS FROM support_tickets LIKE 'last_read_customer'");
    if ($has && $has->num_rows === 0) {
        $db->query("ALTER TABLE support_tickets
            ADD COLUMN last_read_customer DATETIME DEFAULT NULL,
            ADD COLUMN last_read_admin DATETIME DEFAULT NULL");
    }
}

$method = $_SERVER['REQUEST_METHOD'];
$uri = $_SERVER['REQUEST_URI'];
$uri = strtok($uri, '?');

$base = '/api/index.php';
$basePos = strpos($uri, $base);
if ($basePos === false) { http_response_code(404); echo json_encode(['error'=>'Not found']); exit; }
$path = substr($uri, $basePos + strlen($base));
if ($path === false || $path === '') $path = '/';

$input = json_decode(file_get_contents('php://input'), true) ?: [];

// --- Auth ---
if ($path === '/auth/login' && $method === 'POST') {
    $unit = $input['unit_number'] ?? '';
    $pass = $input['password'] ?? '';
    $user = fetchOne('SELECT * FROM users WHERE unit_number = ?', 's', $unit);
    if (!$user || !password_verify($pass, $user['password'])) {
        auditLog($user['id'] ?? null, 'login_failed', "Failed login attempt for unit '$unit'");
        jsonError('Invalid unit number or password', 401);
    }
    $_SESSION['user_id'] = $user['id'];
    session_regenerate_id(true);
    auditLog($user['id'], 'login', "User unit '$unit' logged in");
    unset($user['password']);
    jsonSuccess(['message' => 'Login successful', 'user' => $user]);
}

if ($path === '/auth/logout' && $method === 'POST') {
    if (!empty($_SESSION['user_id'])) {
        auditLog((int)$_SESSION['user_id'], 'logout', 'User logged out');
    }
    session_destroy();
    jsonSuccess(['message' => 'Logged out']);
}

if ($path === '/auth/forgot-password' && $method === 'POST') {
    $needle = trim($input['unit_number'] ?? $input['email'] ?? '');
    if ($needle === '') jsonError('Unit number or email is required', 400);
    ensurePasswordResetTable();
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $throttleUnit = fetchOne('SELECT id FROM password_reset_requests WHERE (unit_number = ? OR email = ?) AND created_at > (NOW() - INTERVAL 5 MINUTE) LIMIT 1', 'ss', $needle, $needle);
    $ipCount = (int)(fetchOne('SELECT COUNT(*) AS c FROM password_reset_requests WHERE ip = ? AND created_at > (NOW() - INTERVAL 60 MINUTE)', 's', $ip)['c'] ?? 0);
    $user = fetchOne('SELECT id, unit_number, name, email, extra_emails FROM users WHERE unit_number = ? OR email = ? LIMIT 1', 'ss', $needle, $needle);
    if ($user && !$throttleUnit && $ipCount < 10) {
        querySingle('INSERT INTO password_reset_requests (unit_number, email, ip) VALUES (?,?,?)', 'sss', $user['unit_number'], $user['email'], $ip);
        $newPw = bin2hex(random_bytes(8));
        $mail = sendPasswordResetMail($user, $newPw, 'self');
        if ($mail['sent']) {
            querySingle('UPDATE users SET password=? WHERE id=?', 'si', password_hash($newPw, PASSWORD_BCRYPT), $user['id']);
            auditLog($user['id'], 'forgot_password', "Password reset via forgot-password form. Email sent to ".$mail['to']);
        } else {
            auditLog($user['id'], 'forgot_password', "Forgot-password skipped for ".($user['unit_number'] ?? '').": email not delivered to ".($mail['to'] ?: 'no address'));
        }
    }
    jsonSuccess(['message' => 'If this account exists, a new password has been sent to the email on file.']);
}

function requireAuth() {
    if (empty($_SESSION['user_id'])) jsonError('Unauthorized', 401);
    $user = fetchOne('SELECT * FROM users WHERE id = ?', 'i', $_SESSION['user_id']);
    if (!$user) jsonError('User not found', 401);
    return $user;
}

function requireAdmin() {
    $user = requireAuth();
    if (!in_array($user['role'], ['admin','finance','super_admin'])) jsonError('Forbidden', 403);
    return $user;
}

function requireUserManager() {
    $user = requireAdmin();
    if ($user['role'] === 'finance') jsonError('Forbidden', 403);
    return $user;
}

function sanitizePhoneList($arr) {
    if (!is_array($arr)) return [];
    $out = [];
    foreach ($arr as $p) {
        $p = trim((string)$p);
        $p = preg_replace('/[^0-9+()\-.\s]/', '', $p);
        if ($p !== '') $out[] = $p;
    }
    return array_slice(array_values(array_unique($out)), 0, 20);
}

function sanitizeEmailList($arr) {
    if (!is_array($arr)) return [];
    $out = [];
    foreach ($arr as $e) {
        $e = trim((string)$e);
        if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) $out[] = $e;
    }
    return array_slice(array_values(array_unique($out)), 0, 20);
}

function ensurePasswordResetTable() {
    static $done = false;
    if ($done) return;
    $done = true;
    global $db;
    $db->query("CREATE TABLE IF NOT EXISTS password_reset_requests (
        id INT NOT NULL AUTO_INCREMENT,
        unit_number VARCHAR(50) DEFAULT '',
        email VARCHAR(255) DEFAULT '',
        ip VARCHAR(45) DEFAULT '',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_unit (unit_number, created_at),
        KEY idx_ip (ip, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->query("DELETE FROM password_reset_requests WHERE created_at < NOW() - INTERVAL 7 DAY");
}

function sendPasswordResetMail($row, $newPw, $reason) {
    $appName = getenv('APP_NAME') ?: 'M2S Customer Portal';
    $mailTo = trim($row['email'] ?? '');
    if (!filter_var($mailTo, FILTER_VALIDATE_EMAIL)) {
        $mailTo = '';
        foreach ((json_decode($row['extra_emails'] ?? '[]', true) ?: []) as $cand) {
            if (filter_var($cand, FILTER_VALIDATE_EMAIL)) { $mailTo = $cand; break; }
        }
    }
    if (!filter_var($mailTo, FILTER_VALIDATE_EMAIL)) return ['sent' => false, 'to' => null];
    $name = htmlspecialchars($row['name'] ?: ($row['unit_number'] ?? ''), ENT_QUOTES, 'UTF-8');
    $unit = htmlspecialchars($row['unit_number'] ?? '', ENT_QUOTES, 'UTF-8');
    $subject = 'Password Reset - ' . $appName;
    $body = '<p>Hi ' . $name . ',</p>'
        . '<p>Your password for unit <strong>' . $unit . '</strong> has been reset'
        . ($reason === 'admin' ? ' by the Mangga Dua Square management.' : ' using the forgot-password form.')
        . '</p>'
        . '<p><strong>New temporary password:</strong> <code>' . $newPw . '</code></p>'
        . '<p><a href="http://portal.manggaduasquare.co.id:8000/">Sign in to the customer portal</a></p>'
        . '<p>Please change this temporary password right after signing in.</p>'
        . '<p>If you did not request this, please contact the Mangga Dua Square management immediately.</p>';
    $res = sendMail($mailTo, $subject, $body);
    return ['sent' => (bool)($res['ok'] ?? false), 'to' => $mailTo];
}

// --- Profile ---
$isProfile = $path === '/auth/profile' || $path === '/profile';
if ($isProfile && $method === 'GET') {
    $user = requireAuth();
    $bills = fetchAll('SELECT id,invoice_no,amount,paid,status,due_date,billing_period FROM billing WHERE user_id = ? ORDER BY id DESC LIMIT 10', 'i', $user['id']);
    $tickets = fetchAll('SELECT id,subject,status,priority,created_at FROM support_tickets WHERE user_id = ? ORDER BY created_at DESC LIMIT 5', 'i', $user['id']);
    $user['billing'] = $bills;
    $user['tickets'] = $tickets;
    unset($user['password']);
    jsonSuccess($user);
}

if ($isProfile && $method === 'PUT') {
    $user = requireAuth();
    $fields = []; $types = ''; $vals = [];
    foreach (['name'=>'s','email'=>'s','phone'=>'s','company'=>'s'] as $f => $t) {
        if (isset($input[$f])) { $fields[] = "$f=?"; $types .= $t; $vals[] = trim($input[$f]); }
    }
    if (isset($input['extra_phones'])) { $fields[] = "extra_phones=?"; $types .= 's'; $vals[] = json_encode(sanitizePhoneList($input['extra_phones'])); }
    if (isset($input['extra_emails'])) { $fields[] = "extra_emails=?"; $types .= 's'; $vals[] = json_encode(sanitizeEmailList($input['extra_emails'])); }
    if (empty($fields)) jsonError('No fields to update');
    $types .= 'i'; $vals[] = $user['id'];
    querySingle('UPDATE users SET '.implode(',',$fields).' WHERE id=?', $types, ...$vals);
    $changed = str_replace('extra_phones', 'extra_phones', implode(', ', array_map(fn($f) => str_replace('=?','',$f), $fields)));
    auditLog($user['id'], 'update_profile', 'Updated profile fields: '.$changed);
    $refresh = fetchOne('SELECT * FROM users WHERE id = ?', 'i', $user['id']);
    unset($refresh['password']);
    jsonSuccess(['message' => 'Profile updated', 'user' => $refresh]);
}

if (($path === '/profile/password') && $method === 'PUT') {
    $user = requireAuth();
    $current = $input['current_password'] ?? '';
    $newPw = $input['new_password'] ?? '';
    if (!password_verify($current, $user['password'])) jsonError('Current password is incorrect', 400);
    if (strlen($newPw) < 6) jsonError('New password must be at least 6 characters', 400);
    querySingle('UPDATE users SET password=? WHERE id=?', 'si', password_hash($newPw, PASSWORD_BCRYPT), $user['id']);
    auditLog($user['id'], 'change_password', 'User changed their own password');
    jsonSuccess(['message' => 'Password updated']);
}

if ($path === '/auth/me' && $method === 'GET') {
    if (empty($_SESSION['user_id'])) jsonError('Unauthorized', 401);
    $user = fetchOne('SELECT * FROM users WHERE id = ?', 'i', $_SESSION['user_id']);
    if (!$user) jsonError('User not found', 401);
    unset($user['password']);
    jsonSuccess(['user' => $user]);
}

// --- Customer Dashboard (requires auth) ---
if ($path === '/dashboard' && $method === 'GET') {
    $user = requireAuth();
    $userId = $user['id'];

    $outstanding = fetchOne("SELECT COUNT(*) as cnt, COALESCE(SUM(COALESCE(amount,0) - COALESCE(paid,0)),0) as amount FROM billing WHERE user_id=? AND (status != 'paid' OR status IS NULL)", 'i', $userId) ?: ['cnt'=>0, 'amount'=>0];
    $totalPaid = fetchOne("SELECT COALESCE(SUM(COALESCE(paid,0)),0) as total FROM billing WHERE user_id=? AND status='paid'", 'i', $userId)['total'] ?? 0;
    $nextDue = fetchOne("SELECT due_date, amount FROM billing WHERE user_id=? AND (status IS NULL OR status != 'paid') AND due_date >= CURDATE() ORDER BY due_date ASC LIMIT 1", 'i', $userId);
    $chartPaid = fetchAll("SELECT DATE_FORMAT(COALESCE(issued_date,due_date),'%Y-%m') as month, SUM(COALESCE(paid,amount,0)) as total FROM billing WHERE user_id=? AND status='paid' GROUP BY month ORDER BY month ASC LIMIT 12", 'i', $userId);
    $chartPending = fetchAll("SELECT DATE_FORMAT(COALESCE(issued_date,due_date),'%Y-%m') as month, SUM(COALESCE(amount,0) - COALESCE(paid,0)) as total FROM billing WHERE user_id=? AND (status IS NULL OR status != 'paid') GROUP BY month ORDER BY month ASC LIMIT 12", 'i', $userId);
    $recentPayments = fetchAll("SELECT b.invoice_no, COALESCE(p.amount,b.paid,b.amount,0) as amount, p.method, p.paid_at FROM payments p JOIN billing b ON b.id=p.billing_id WHERE b.user_id=? ORDER BY p.paid_at DESC LIMIT 5", 'i', $userId);
    $announcements = fetchAll("SELECT title, content, category FROM news WHERE category='announcement' OR category='promotion' ORDER BY created_at DESC LIMIT 10");
    $upcomingEvents = fetchAll("SELECT title, published_at FROM news WHERE category='event' ORDER BY published_at DESC LIMIT 5");

    $chart = ['labels' => [], 'paid' => [], 'pending' => []];
    $months = [];
    foreach ([$chartPaid, $chartPending] as $rows) {
        foreach ($rows as $row) { $months[$row['month']] = true; }
    }
    $months = array_keys($months); sort($months);
    $paidByMonth = []; foreach ($chartPaid as $r) { $paidByMonth[$r['month']] = (float)$r['total']; }
    $pendingByMonth = []; foreach ($chartPending as $r) { $pendingByMonth[$r['month']] = (float)$r['total']; }
    foreach ($months as $m) {
        $chart['labels'][] = $m;
        $chart['paid'][] = $paidByMonth[$m] ?? 0;
        $chart['pending'][] = $pendingByMonth[$m] ?? 0;
    }

    jsonSuccess([
        'outstanding' => ['amount' => (float)$outstanding['amount'], 'count' => (int)$outstanding['cnt']],
        'total_paid' => (float)$totalPaid,
        'next_due' => $nextDue,
        'chart' => $chart,
        'recent_payments' => $recentPayments,
        'announcements' => $announcements,
        'upcoming_events' => $upcomingEvents,
    ]);
}

// --- Public News (no auth) ---
if ($path === '/news' && $method === 'GET') {
    $allNews = fetchAll('SELECT * FROM news ORDER BY created_at DESC');
    $featured = array_values(array_filter($allNews, fn($n) => (int)($n['featured'] ?? 0) === 1));
    jsonSuccess(['news' => $allNews, 'featured' => $featured]);
}

// --- Customer Documents ---
if ($path === '/documents' && $method === 'GET') {
    $user = requireAuth();
    $docs = fetchAll('SELECT * FROM documents WHERE user_id = ? OR user_id IS NULL ORDER BY uploaded_at DESC', 'i', $user['id']);
    jsonSuccess($docs);
}

// --- Billing (customer) ---
if (preg_match('#^/billing(/(\d+))?$#', $path, $m) === 1 && $method === 'GET') {
    $user = requireAuth();
    if (!empty($m[2])) {
        $bill = fetchOne('SELECT * FROM billing WHERE id = ? AND user_id = ?', 'ii', (int)$m[2], $user['id']);
        if (!$bill) jsonError('Billing not found', 404);
        jsonSuccess($bill);
    } else {
        $bills = fetchAll("SELECT b.*,
            (SELECT GROUP_CONCAT(DISTINCT p.reference ORDER BY p.paid_at SEPARATOR ', ')
             FROM payments p WHERE p.billing_id = b.id
               AND p.reference IS NOT NULL AND p.reference <> '') AS payment_references
            FROM billing b WHERE b.user_id = ? ORDER BY b.id DESC", 'i', $user['id']);
        jsonSuccess(['bills' => $bills, 'total' => count($bills)]);
    }
}

// --- Customer Payments ---
if ($path === '/payments' && $method === 'GET') {
    $user = requireAuth();
    $payments = fetchAll('SELECT p.*, b.invoice_no, b.description FROM payments p JOIN billing b ON b.id=p.billing_id WHERE b.user_id=? ORDER BY p.paid_at DESC LIMIT 50', 'i', $user['id']);
    jsonSuccess($payments);
}

// --- Static FAQ (no DB table needed) ---
if ($path === '/faq' && $method === 'GET') {
    jsonSuccess([
        ['q' => 'How do I view my bills?', 'a' => 'Click on "Billing" in the sidebar menu to view all your invoices, check payment status, and see due dates.'],
        ['q' => 'How do I make a payment?', 'a' => 'Go to your Billing page, click "Pay Now" on an unpaid invoice, and follow the payment instructions. You can pay via virtual account transfer.'],
        ['q' => 'What is my virtual account number?', 'a' => 'Your virtual account number is displayed on each invoice in the Billing page. It starts with the prefix 00535 followed by your unique account code.'],
        ['q' => 'How do I submit a support ticket?', 'a' => 'Fill out the form on this page with your subject and message, then click "Submit Ticket". Our team will respond to your inquiry.'],
        ['q' => 'How do I update my profile?', 'a' => 'Click on your name at the top-right and select "Profile" from the dropdown. You can update your contact information there.'],
        ['q' => 'How do I view uploaded documents?', 'a' => 'Go to the "Documents" section in the sidebar to view and download documents shared with you by management.'],
    ]);
}

// --- Support Tickets (customer) ---
if ($path === '/tickets' && $method === 'GET') {
    $user = requireAuth();
    ensureTicketReadColumns();
    $tickets = fetchAll(
        "SELECT t.*,
            (SELECT COUNT(*) FROM ticket_messages m
                WHERE m.ticket_id = t.id AND m.sender_role='admin'
                AND m.created_at > IFNULL(t.last_read_customer, '1970-01-01 00:00:00')) AS unread
         FROM support_tickets t WHERE t.user_id = ? ORDER BY t.created_at DESC", 'i', $user['id']);
    foreach ($tickets as &$t) { $t['unread'] = (int)$t['unread']; }
    jsonSuccess($tickets);
}

if ($path === '/tickets' && $method === 'POST') {
    $user = requireAuth();
    $subject = trim($input['subject'] ?? '');
    $message = trim($input['message'] ?? '');
    if ($subject === '' || $message === '') jsonError('Subject and message are required');
    ensureTicketMessagesTable();
    querySingle('INSERT INTO support_tickets (user_id,subject,message) VALUES (?,?,?)', 'iss', $user['id'], $subject, $message);
    $newId = $db->insert_id;
    querySingle('INSERT INTO ticket_messages (ticket_id, sender_role, message) VALUES (?,?,?)', 'iss', $newId, 'customer', $message);
    auditLog($user['id'], 'create_ticket', "Created ticket #$newId: $subject");
    jsonSuccess(['message' => 'Ticket created', 'id' => $newId]);
}

// --- Support ticket chat messages (customer) ---
if (preg_match('#^/tickets/(\d+)/messages$#', $path, $m) === 1 && $method === 'GET') {
    $user = requireAuth();
    ensureTicketMessagesTable();
    ensureTicketReadColumns();
    $ticketId = (int)$m[1];
    $ticket = fetchOne('SELECT id FROM support_tickets WHERE id = ? AND user_id = ?', 'ii', $ticketId, $user['id']);
    if (!$ticket) jsonError('Ticket not found', 404);
    $msgs = fetchAll('SELECT * FROM ticket_messages WHERE ticket_id = ? ORDER BY created_at ASC, id ASC', 'i', $ticketId);
    jsonSuccess($msgs);
}

// --- Customer mark ticket as read ---
if (preg_match('#^/tickets/(\d+)/read$#', $path, $m) === 1 && $method === 'POST') {
    $user = requireAuth();
    ensureTicketReadColumns();
    $ticketId = (int)$m[1];
    $ticket = fetchOne('SELECT id FROM support_tickets WHERE id = ? AND user_id = ?', 'ii', $ticketId, $user['id']);
    if (!$ticket) jsonError('Ticket not found', 404);
    querySingle("UPDATE support_tickets SET last_read_customer=NOW() WHERE id=?", 'i', $ticketId);
    jsonSuccess(['message' => 'Marked read']);
}

if (preg_match('#^/tickets/(\d+)/messages$#', $path, $m) === 1 && $method === 'POST') {
    $user = requireAuth();
    ensureTicketMessagesTable();
    $ticketId = (int)$m[1];
    $message = trim($input['message'] ?? '');
    if ($message === '') jsonError('Message is required');
    $ticket = fetchOne('SELECT id, status FROM support_tickets WHERE id = ? AND user_id = ?', 'ii', $ticketId, $user['id']);
    if (!$ticket) jsonError('Ticket not found', 404);
    if (in_array($ticket['status'], ['resolved', 'closed'])) {
        jsonError('This ticket is ' . $ticket['status'] . ' and can no longer receive messages', 400);
    }
    querySingle('INSERT INTO ticket_messages (ticket_id, sender_role, message) VALUES (?,?,?)', 'iss', $ticketId, 'customer', $message);
    auditLog($user['id'], 'send_message', "Sent message on ticket #$ticketId");
    jsonSuccess(['message' => 'Message sent']);
}

// --- WhatsApp delivery status webhook (public, called by provider) ---
if ($path === '/wa-webhook' && $method === 'POST') {
    ensureWaBillingColumns();
    $data = array_merge($_GET, $_POST, $input);
    $msgId = trim((string)($data['message_id'] ?? $data['MessageSid'] ?? $data['id'] ?? $data['data']['id'] ?? ''));
    $status = strtolower(trim((string)($data['status'] ?? $data['MessageStatus'] ?? $data['data']['status'] ?? '')));
    if ($msgId === '') jsonSuccess(['ok' => false, 'reason' => 'no message_id']);
    $map = [
        'delivered' => 'received', 'read' => 'received', 'received' => 'received', 'seen' => 'received',
        'accepted' => 'sent', 'queued' => 'sent', 'sending' => 'sent', 'sent' => 'sent',
        'failed' => 'failed', 'undelivered' => 'failed', 'error' => 'failed', 'rejected' => 'failed',
        'canceled' => 'failed', 'cancelled' => 'failed',
    ];
    $waStatus = $map[$status] ?? null;
    if (!$waStatus) jsonSuccess(['ok' => false, 'reason' => 'unrecognized status: ' . $status]);
    if ($waStatus === 'sent') {
        querySingle("UPDATE billing SET wa_status=? WHERE wa_message_id=? AND (wa_status IS NULL OR wa_status='')", 'ss', $waStatus, $msgId);
    } else {
        querySingle("UPDATE billing SET wa_status=? WHERE wa_message_id=?", 'ss', $waStatus, $msgId);
    }
    jsonSuccess(['ok' => true, 'status' => $waStatus]);
}

// --- Admin routing ---
$adminPrefix = '/admin';
if (strpos($path, $adminPrefix) === 0) {
    $adminUser = requireAdmin();
    $adminPath = substr($path, strlen($adminPrefix));
    if ($adminPath === '' || $adminPath === false) $adminPath = '/';
    $adminMethod = $method;
    ensureWaBillingColumns();
    ensureTicketResponseColumn();
    ensureTicketMessagesTable();
    ensureTicketReadColumns();

    // --- Admin Dashboard ---
    if ($adminPath === '/dashboard' && $adminMethod === 'GET') {
        $totalBills = fetchOne('SELECT COUNT(*) as cnt, COALESCE(SUM(amount),0) as total FROM billing') ?: ['cnt'=>0,'total'=>0];
        $paidBills = fetchOne("SELECT COUNT(*) as cnt, COALESCE(SUM(paid),0) as total FROM billing WHERE status='paid'") ?: ['cnt'=>0,'total'=>0];
        $outstanding = fetchOne("SELECT COALESCE(SUM(COALESCE(amount,0) - COALESCE(paid,0)),0) as total FROM billing") ?: ['total'=>0];
        $billingTotals = fetchOne('SELECT COALESCE(SUM(amount),0) AS billed, COALESCE(SUM(paid),0) AS collected, COALESCE(SUM(LEAST(COALESCE(paid,0),amount)),0) AS revenue, COALESCE(SUM(GREATEST(amount-COALESCE(paid,0),0)),0) AS outstanding FROM billing') ?: ['billed'=>0,'collected'=>0,'revenue'=>0,'outstanding'=>0];
        $totalUsers = fetchOne('SELECT COUNT(*) as cnt FROM users')['cnt'] ?? 0;
        $openTickets = fetchOne("SELECT COUNT(*) as cnt FROM support_tickets WHERE status != 'closed'")['cnt'] ?? 0;
        $recentBills = fetchAll('SELECT b.id, b.invoice_no, b.amount, b.status, u.name as user_name, u.unit_number FROM billing b JOIN users u ON u.id=b.user_id ORDER BY b.id DESC LIMIT 10');
        $recentTickets = fetchAll('SELECT t.id, t.subject, t.status, u.name as user_name, u.unit_number FROM support_tickets t JOIN users u ON u.id=t.user_id ORDER BY t.created_at DESC LIMIT 10');
        $recentLogs = fetchAll('SELECT l.*, u.name as user_name, u.unit_number FROM audit_logs l JOIN users u ON u.id=l.user_id ORDER BY l.created_at DESC LIMIT 10');
        $monthlyBilling = fetchAll("SELECT DATE_FORMAT(COALESCE(issued_date,due_date),'%Y-%m') as month, SUM(LEAST(COALESCE(paid,0),amount)) as total FROM billing GROUP BY month ORDER BY month DESC LIMIT 12");
        $monthlyOutstanding = fetchAll("SELECT DATE_FORMAT(COALESCE(issued_date,due_date),'%Y-%m') as month, SUM(GREATEST(amount-COALESCE(paid,0),0)) as total FROM billing GROUP BY month ORDER BY month DESC LIMIT 12");
        $billStatus = [
            'paid' => (int)(fetchOne("SELECT COUNT(*) as cnt FROM billing WHERE amount > 0 AND paid IS NOT NULL AND paid >= amount")['cnt'] ?? 0),
            'partial' => (int)(fetchOne("SELECT COUNT(*) as cnt FROM billing WHERE amount > 0 AND paid IS NOT NULL AND paid > 0 AND paid < amount")['cnt'] ?? 0),
            'unpaid' => (int)(fetchOne("SELECT COUNT(*) as cnt FROM billing WHERE amount > 0 AND (paid IS NULL OR paid = 0)")['cnt'] ?? 0),
        ];
        $ticketsMonthly = fetchAll("SELECT DATE_FORMAT(created_at,'%Y-%m') as month, COUNT(*) as total FROM support_tickets WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) GROUP BY month ORDER BY month ASC");
        jsonSuccess([
            'total_users' => (int)$totalUsers,
            'total_revenue' => (float)$billingTotals['revenue'],
            'outstanding_revenue' => (float)$billingTotals['outstanding'],
            'total_pending' => (int)($totalBills['cnt'] - $paidBills['cnt']),
            'total_bills' => (int)$totalBills['cnt'],
            'total_billed_amount' => (float)$billingTotals['billed'],
            'open_tickets' => (int)$openTickets,
            'recent_bills' => $recentBills,
            'recent_tickets' => $recentTickets,
            'monthly_billing' => $monthlyBilling,
            'monthly_outstanding' => $monthlyOutstanding,
            'bill_status' => $billStatus,
            'tickets_monthly' => $ticketsMonthly,
            'kpi_months' => array_map(function($r) { return $r['month']; }, fetchAll("SELECT DISTINCT DATE_FORMAT(COALESCE(issued_date,due_date),'%Y-%m') as month FROM billing WHERE issued_date IS NOT NULL OR due_date IS NOT NULL ORDER BY month") ?: []),
        ]);
    }

    // --- Admin Dashboard KPI totals (with optional month filter, date-month basis like the Revenue vs Outstanding chart) ---
    if ($adminPath === '/dashboard/totals' && $adminMethod === 'GET') {
        $period = trim($_GET['period'] ?? '');
        $where = ''; $types = ''; $params = [];
        if ($period !== '') { $where = " WHERE DATE_FORMAT(COALESCE(issued_date,due_date),'%Y-%m') = ?"; $types = 's'; $params = [$period]; }
        $billingTotals = fetchOne("SELECT COALESCE(SUM(amount),0) AS billed, COALESCE(SUM(LEAST(COALESCE(paid,0),amount)),0) AS revenue, COALESCE(SUM(GREATEST(amount-COALESCE(paid,0),0)),0) AS outstanding FROM billing$where", $types, ...$params) ?: ['billed'=>0,'revenue'=>0,'outstanding'=>0];
        jsonSuccess([
            'period' => $period,
            'total_billed_amount' => (float)$billingTotals['billed'],
            'total_revenue' => (float)$billingTotals['revenue'],
            'outstanding_revenue' => (float)$billingTotals['outstanding'],
        ]);
    }

    // --- Admin Code Dependency Graph ---
    if ($adminPath === '/codegraph' && $adminMethod === 'GET') {
        if (!class_exists('PDO') || !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            jsonSuccess(['available' => false, 'error' => 'pdo_sqlite is not enabled in PHP.']);
        }
        $jsonGraphPath = realpath(__DIR__ . '/../.codegraph') !== false
            ? realpath(__DIR__ . '/../.codegraph/codegraph.db')
            : false;
        if ($jsonGraphPath === false || !is_file($jsonGraphPath)) {
            jsonSuccess(['available' => false, 'error' => 'No CodeGraph index found. Run the CodeGraph CLI on the portal root (`codegraph init -y .`) to generate `.codegraph/codegraph.db`.']);
        }
        try {
            $cg = new PDO('sqlite:' . $jsonGraphPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $cg->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $files = $cg->query('SELECT path, language, node_count, size FROM files ORDER BY path')->fetchAll();
            $filesList = array_column($files, 'path');

            $symFile = trim($input['file'] ?? $_GET['file'] ?? '');
            if ($symFile !== '') {
                if (!in_array($symFile, $filesList, true)) jsonError('File not indexed', 404);
                $st = $cg->prepare('SELECT id, qualified_name as name, kind, start_line FROM nodes WHERE file_path = ? AND kind != \'file\' ORDER BY start_line');
                $st->execute([$symFile]);
                $symbols = $st->fetchAll();
                $symIdx = [];
                foreach ($symbols as $i => $s) $symIdx[$s['id']] = $i;
                $es = $cg->prepare("SELECT e.source, e.target, e.kind FROM edges e JOIN nodes ns ON ns.id=e.source JOIN nodes nt ON nt.id=e.target WHERE ns.file_path = ? AND nt.file_path = ? AND e.kind IN ('calls','references')");
                $es->execute([$symFile, $symFile]);
                $edges = [];
                foreach ($es->fetchAll() as $row) {
                    if (!isset($symIdx[$row['source']]) || !isset($symIdx[$row['target']])) continue;
                    $edges[] = [$symIdx[$row['source']], $symIdx[$row['target']], $row['kind']];
                }
                jsonSuccess(['available' => true, 'file' => $symFile, 'symbols' => $symbols, 'edges' => $edges]);
            }

            $languages = $cg->query('SELECT language, COUNT(*) AS files, SUM(node_count) AS nodes FROM files GROUP BY language ORDER BY files DESC')->fetchAll();
            $totals = $cg->query('SELECT (SELECT COUNT(*) FROM nodes) AS nodes, (SELECT COUNT(*) FROM edges) AS edges')->fetch();

            $deps = [];
            $rows = $cg->query("
                SELECT ns.file_path AS source, nt.file_path AS target, COUNT(*) AS weight
                FROM edges e
                JOIN nodes ns ON ns.id = e.source
                JOIN nodes nt ON nt.id = e.target
                WHERE e.kind IN ('calls','references') AND ns.file_path <> nt.file_path
                GROUP BY ns.file_path, nt.file_path
                ORDER BY weight DESC
            ")->fetchAll();
            foreach ($rows as $r) $deps[] = ['source' => $r['source'], 'target' => $r['target'], 'weight' => (int)$r['weight'], 'kind' => 'codegraph'];

            $rootDir = realpath(__DIR__ . '/..');
            foreach ($files as $f) {
                $abs = $rootDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $f['path']);
                $src = @file_get_contents($abs);
                if ($src === false) continue;
                $refs = [];
                if (preg_match_all('#(?:require(?:_once)?|include(?:_once)?)\s*[\s(]*["\']([^"\']+)["\']#i', $src, $m)) $refs = array_merge($refs, $m[1]);
                if (preg_match_all('#<script[^>]+src\s*=\s*["\']([^"\']+)["\']#i', $src, $m)) $refs = array_merge($refs, $m[1]);
                if (preg_match_all('#<link[^>]+href\s*=\s*["\']([^"\']+)["\']#i', $src, $m)) $refs = array_merge($refs, $m[1]);
                if (preg_match_all('#(?:\bfrom\b|\bimport\b)\s*[\s("]*["\']([^"\']+)["\']#i', $src, $m)) $refs = array_merge($refs, $m[1]);
                foreach (array_unique($refs) as $raw) {
                    $clean = preg_replace('~[#?].*$~', '', trim($raw));
                    $clean = preg_replace('#^\./#', '', $clean);
                    if ($clean === '') continue;
                    foreach ($filesList as $target) {
                        if ($target === $f['path']) continue;
                        if (strpos($clean, $target) !== false) {
                            $deps[] = ['source' => $f['path'], 'target' => $target, 'weight' => 1, 'kind' => 'module'];
                            break;
                        }
                    }
                }
            }

            jsonSuccess([
                'available' => true,
                'stats' => [
                    'files' => count($files),
                    'nodes' => (int)($totals['nodes'] ?? 0),
                    'edges' => (int)($totals['edges'] ?? 0),
                    'cross_file_edges' => count($deps),
                    'languages' => $languages,
                ],
                'files' => $files,
                'edges' => $deps,
            ]);
        } catch (Throwable $e) {
            jsonSuccess(['available' => false, 'error' => 'Could not read codegraph index: ' . $e->getMessage()]);
        }
    }

    // --- Admin Billing ---
    if (preg_match('#^/billing(/(\d+))?$#', $adminPath, $m) === 1 && $adminMethod === 'GET') {
        if (!empty($m[2])) {
            $bill = fetchOne('SELECT b.*, u.name as user_name, u.unit_number, u.virtual_account as user_virtual_account, p.method FROM billing b JOIN users u ON u.id=b.user_id LEFT JOIN payments p ON p.billing_id=b.id WHERE b.id = ?', 'i', (int)$m[2]);
            if (!$bill) jsonError('Billing not found', 404);
            jsonSuccess($bill);
        } else {
            $period = trim($input['period'] ?? $_GET['period'] ?? '');
            $q = trim($input['q'] ?? $_GET['q'] ?? '');
            $paidFilter = trim($input['paid'] ?? $_GET['paid'] ?? '');
            $statusFilter = trim($input['status'] ?? $_GET['status'] ?? '');
            $hasPageKey = array_key_exists('page', $_GET) || array_key_exists('page_size', $_GET);
            $usePaging = $period !== '' || $hasPageKey || $q !== '' || $paidFilter !== '' || $statusFilter !== '';
            $where = ''; $types = '';
            $conds = [];
            if ($period !== '') { $conds[] = 'b.billing_period = ?'; $types .= 's'; }
            if ($q !== '') { $conds[] = '(b.unit_number LIKE ? OR u.name LIKE ? OR b.invoice_no LIKE ?)'; $types .= 'sss'; }
            if ($paidFilter === 'unpaid' || $statusFilter === 'Belum Bayar') { $conds[] = "(b.amount > 0 AND (b.paid IS NULL OR b.paid = 0))"; }
            else if ($statusFilter === 'Paid') { $conds[] = '(b.amount > 0 AND b.paid IS NOT NULL AND b.paid >= b.amount)'; }
            else if ($statusFilter === 'Kurang Bayar') { $conds[] = '(b.amount > 0 AND b.paid IS NOT NULL AND b.paid > 0 AND b.paid < b.amount)'; }
            else if ($statusFilter === 'Lebih Bayar') { $conds[] = '(b.amount > 0 AND b.paid IS NOT NULL AND b.paid > b.amount)'; }
            if (!empty($conds)) $where = ' WHERE '.implode(' AND ', $conds);
            $sortKey = trim($input['sort'] ?? $_GET['sort'] ?? '');
            $sortDir = strtolower(trim($input['order'] ?? $_GET['order'] ?? '')) === 'desc' ? 'DESC' : 'ASC';
            $sortWhitelist = ['id','amount','paid','due_date','billing_period','invoice_no','unit_number','user_name'];
            $isStatusSort = $sortKey === 'payment_status';
            $orderBy = $isStatusSort
                ? "CASE WHEN b.amount > 0 AND (b.paid IS NULL OR b.paid = 0) THEN 0 WHEN b.amount > 0 AND b.paid > 0 AND b.paid < b.amount THEN 1 WHEN b.amount > 0 AND b.paid IS NOT NULL AND b.paid >= b.amount THEN 2 ELSE 3 END"
                : (in_array($sortKey, $sortWhitelist, true) ? $sortKey : 'b.id');
            if (!$isStatusSort) {
                if ($orderBy === 'user_name') $orderBy = 'u.name';
                if ($orderBy === 'unit_number') $orderBy = 'u.unit_number';
                $orderBy .= ' ' . $sortDir;
            } else {
                $orderBy .= ' ' . $sortDir . ', b.id DESC';
            }
            if ($usePaging) {
                $page = max(1, (int)($_GET['page'] ?? 1));
                $pageSize = min(1000, max(1, (int)($_GET['page_size'] ?? 50)));
                $countParams = [];
                if ($period !== '') $countParams[] = $period;
                if ($q !== '') { $like = '%'.$q.'%'; $countParams[] = $like; $countParams[] = $like; $countParams[] = $like; }
                $countRow = fetchOne('SELECT COUNT(*) as cnt FROM billing b JOIN users u ON u.id=b.user_id'.$where, $types, ...$countParams);
                $total = (int)($countRow['cnt'] ?? 0);
                $offset = ($page - 1) * $pageSize;
                $types .= 'ii';
                $dataParams = $countParams; $dataParams[] = $pageSize; $dataParams[] = $offset;
                $bills = fetchAll('SELECT b.*, u.name as user_name, u.unit_number, u.virtual_account as user_virtual_account FROM billing b JOIN users u ON u.id=b.user_id'.$where.' ORDER BY '.$orderBy.($isStatusSort ? '' : ', b.id DESC').' LIMIT ? OFFSET ?', $types, ...$dataParams);
                $periods = fetchAll('SELECT DISTINCT billing_period FROM billing WHERE billing_period IS NOT NULL AND billing_period != \'\' ORDER BY billing_period DESC');
                jsonSuccess(['bills' => $bills, 'total' => $total, 'page' => $page, 'page_size' => $pageSize, 'periods' => array_map(function($r){ return $r['billing_period']; }, $periods)]);
            }
            $bills = fetchAll('SELECT b.*, u.name as user_name, u.unit_number, u.virtual_account as user_virtual_account FROM billing b JOIN users u ON u.id=b.user_id ORDER BY b.id DESC');
            jsonSuccess(['bills' => $bills, 'total' => count($bills), 'periods' => array_map(function($r){ return $r['billing_period']; }, fetchAll('SELECT DISTINCT billing_period FROM billing WHERE billing_period IS NOT NULL AND billing_period != \'\' ORDER BY billing_period DESC'))]);
        }
    }

    if (preg_match('#^/billing(/(\d+))?$#', $adminPath, $m) === 1 && $adminMethod === 'POST' && empty($m[2])) {
        $userId = (int)($input['user_id'] ?? 0);
        $invoiceNo = trim($input['invoice_no'] ?? '');
        $amount = (float)($input['amount'] ?? 0);
        $dueDate = normDate($input['due_date'] ?? '');
        $billingPeriod = trim($input['billing_period'] ?? '');
        $description = trim($input['description'] ?? '');
        $category = trim($input['category'] ?? '');
        $va = normalizeVa($input['virtual_account'] ?? '');
        $unitNo = trim($input['unit_number'] ?? '');
        $userCheck = fetchOne('SELECT id, unit_number FROM users WHERE id = ?', 'i', $userId);
        if (!$userCheck) jsonError('User not found');
        if ($unitNo === '') $unitNo = $userCheck['unit_number'] ?? '';
        $issuedDate = $dueDate === '' ? date('Y-m-d') : $dueDate;
        if ($dueDate === '') $dueDate = date('Y-m-d');
        querySingle('INSERT INTO billing (user_id,unit_number,invoice_no,amount,due_date,issued_date,billing_period,description,category,virtual_account) VALUES (?,?,?,?,?,?,?,?,?,?)', 'isssssssss', $userId, $unitNo, $invoiceNo, $amount, $dueDate, $issuedDate, $billingPeriod, $description, $category, $va);
        $billId = $db->insert_id;
        auditLog($adminUser['id'], 'create_billing', "Created billing #$billId ($invoiceNo) for user #$userId");
        jsonSuccess(['message' => 'Billing created', 'id' => $billId]);
    }

    if (preg_match('#^/billing/(\d+)$#', $adminPath, $m) === 1 && $adminMethod === 'PUT') {
        $id = (int)$m[1];
        $map = ['invoice_no'=>'s','amount'=>'d','paid'=>'d','status'=>'s','due_date'=>'s','billing_period'=>'s','description'=>'s','category'=>'s','virtual_account'=>'s','rent'=>'d','service_charge'=>'d','electricity'=>'d','water'=>'d','gas'=>'d','sinking_fund'=>'d','other_rev'=>'d','fine'=>'d','unit_number'=>'s','issued_date'=>'s'];
        $fields = []; $types = ''; $vals = [];
        foreach ($map as $f => $t) {
            if (isset($input[$f])) { $fields[] = "$f=?"; $types .= $t; $vals[] = ($f === 'virtual_account') ? normalizeVa($input[$f]) : $input[$f]; }
        }
        if (empty($fields)) jsonError('No fields to update');
        $types .= 'i'; $vals[] = $id;
        querySingle('UPDATE billing SET '.implode(',',$fields).' WHERE id=?', $types, ...$vals);
        if (isset($input['status'])) {
            $bill = fetchOne('SELECT id, user_id, invoice_no, amount FROM billing WHERE id=?', 'i', $id);
            if ($bill) {
                if ($input['status'] === 'paid') {
                    $sum = fetchOne('SELECT COALESCE(SUM(amount),0) as total FROM payments WHERE billing_id=?', 'i', $id);
                    $currentSum = (float)($sum['total'] ?? 0);
                    $payAmt = (float)($input['paid'] ?? 0);
                    if ($payAmt <= 0) $payAmt = (float)$bill['amount'];
                    if ($payAmt > $currentSum + 0.001) {
                        $method = $input['method'] ?? 'Bank Transfer';
                        $ref = strtoupper(substr(md5($bill['id'].time().':'.($payAmt - $currentSum)), 0, 12));
                        querySingle('INSERT INTO payments (billing_id,user_id,amount,method,reference,paid_at) VALUES (?,?,?,?,?,NOW())', 'iidss', $bill['id'], $bill['user_id'], $payAmt - $currentSum, $method, $ref);
                    }
                    recalcBill($id);
                } else if ($input['status'] === 'pending') {
                    querySingle('DELETE FROM payments WHERE billing_id=?', 'i', $id);
                    recalcBill($id);
                }
            }
        }
        auditLog($adminUser['id'], 'update_billing', "Updated billing #$id");
        jsonSuccess(['message' => 'Billing updated']);
    }

    if (preg_match('#^/billing/(\d+)$#', $adminPath, $m) === 1 && $adminMethod === 'DELETE') {
        $id = (int)$m[1];
        querySingle('DELETE FROM payments WHERE billing_id = ?', 'i', $id);
        querySingle('DELETE FROM billing WHERE id = ?', 'i', $id);
        auditLog($adminUser['id'], 'delete_billing', "Deleted billing #$id");
        jsonSuccess(['message' => 'Billing deleted']);
    }

    if ($adminPath === '/billing/upload' && $adminMethod === 'POST') {
        if (!isset($_FILES['file'])) jsonError('No file uploaded');
        $tmp = $_FILES['file']['tmp_name'];
        if (!is_file($tmp)) jsonError('Upload failed');
        $fh = fopen($tmp, 'r');
        if (!$fh) jsonError('Cannot read file');
        $headers = fgetcsv($fh);
        if (!$headers) { fclose($fh); jsonError('Empty CSV'); }
        $headers = array_map(function($h) { $h = preg_replace('/^\xEF\xBB\xBF/', '', $h); return strtolower(trim(str_replace(['-',' '], '_', trim($h)))); }, $headers);
        $colMap = [];
        $expected = [
            'user_id' => ['user_id','unit_number'],
            'invoice_no' => ['invoice_no','invoice_number'],
            'amount' => ['amount','total'],
            'unit_number' => ['unit_number'],
            'due_date' => ['due_date'],
            'billing_period' => ['billing_period'],
            'description' => ['description'],
            'category' => ['category'],
            'virtual_account' => ['virtual_account'],
            'other_rev' => ['other_rev','other_revenue'],
            'electricity' => ['electricity','total_e'],
            'water' => ['water','total_w'],
            'gas' => ['gas','total_g'],
            'sinking_fund' => ['sinking_fund','f'],
            'fine' => ['fine','denda'],
            'rent' => ['rent','r'],
            'service_charge' => ['service_charge','s'],
        ];
        foreach ($expected as $col => $names) {
            foreach ($names as $name) {
                $idx = array_search($name, $headers);
                if ($idx !== false) { $colMap[$col] = $idx; break; }
            }
        }
        // For RAW CSV: invtrx_OT + invtrx_UT → other_rev
        if (!isset($colMap['other_rev'])) {
            $invtrxOtIdx = array_search('invtrx_ot', $headers);
            $invtrxUtIdx = array_search('invtrx_ut', $headers);
        } else {
            $invtrxOtIdx = false;
            $invtrxUtIdx = false;
        }
        if (!isset($colMap['user_id']) || !isset($colMap['invoice_no']) || !isset($colMap['amount'])) { fclose($fh); jsonError('CSV must have user_id/unit_number, invoice_no/invoice_number, amount/total columns'); }

        $uploaded = []; $errors = [];
        $num = function($v) { return (float)preg_replace('/[^0-9.]/', '', $v); };
        while (($row = fgetcsv($fh)) !== false) {
            $uid = trim($row[$colMap['user_id']] ?? '');
            $inv = trim($row[$colMap['invoice_no']] ?? '');
            $amt = trim($row[$colMap['amount']] ?? '');
            if ($uid === '' || $inv === '' || $amt === '') continue;
            $userCheck = fetchOne('SELECT id, unit_number FROM users WHERE unit_number = ? OR id = ?', 'ss', $uid, $uid);
            if (!$userCheck) { $errors[] = "User $uid not found"; continue; }
            $uid = $userCheck['id'];
            $unitNo = trim($row[$colMap['unit_number'] ?? $colMap['user_id'] ?? -1] ?? '');
            if ($unitNo === '') $unitNo = $userCheck['unit_number'] ?? '';
            $amount = $num($amt);
            $dueDate = normDate($row[$colMap['due_date'] ?? -1] ?? '');
            if ($dueDate === '') $dueDate = date('Y-m-d');
            $period = trim($row[$colMap['billing_period'] ?? -1] ?? '');
            $desc = trim($row[$colMap['description'] ?? -1] ?? '');
            $cat = trim($row[$colMap['category'] ?? -1] ?? '');
            $vaRaw = trim($row[$colMap['virtual_account'] ?? -1] ?? '');
            $va = normalizeVa($vaRaw);
            if ($vaRaw !== '' && $va === '') $errors[] = "Row for $inv: virtual account '$vaRaw' looks corrupted (scientific notation) — re-export with the VA column formatted as text";
            $otherRev = 0;
            if (isset($colMap['other_rev'])) {
                $otherRev = $num(trim($row[$colMap['other_rev']] ?? ''));
            } else {
                if ($invtrxOtIdx !== false) $otherRev += $num(trim($row[$invtrxOtIdx] ?? ''));
                if ($invtrxUtIdx !== false) $otherRev += $num(trim($row[$invtrxUtIdx] ?? ''));
            }
            $electricity = $num(trim($row[$colMap['electricity'] ?? -1] ?? ''));
            $water = $num(trim($row[$colMap['water'] ?? -1] ?? ''));
            $gas = $num(trim($row[$colMap['gas'] ?? -1] ?? ''));
            $sinkingFund = $num(trim($row[$colMap['sinking_fund'] ?? -1] ?? ''));
            $fine = $num(trim($row[$colMap['fine'] ?? -1] ?? ''));
            $rent = $num(trim($row[$colMap['rent'] ?? -1] ?? ''));
            $serviceCharge = $num(trim($row[$colMap['service_charge'] ?? -1] ?? ''));
            querySingle('INSERT INTO billing (user_id,unit_number,invoice_no,amount,due_date,issued_date,billing_period,description,category,virtual_account,other_rev,electricity,water,gas,sinking_fund,fine,rent,service_charge) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', 'isssssssssdddddddd', $uid, $unitNo, $inv, $amount, $dueDate, $dueDate, $period, $desc, $cat, $va, $otherRev, $electricity, $water, $gas, $sinkingFund, $fine, $rent, $serviceCharge);
            $uploaded[] = ['id' => $db->insert_id, 'invoice_no' => $inv, 'user_id' => $uid];
        }
        fclose($fh);
        auditLog($adminUser['id'], 'upload_billing', 'Imported '.count($uploaded).' billing records');
        jsonSuccess(['uploaded' => $uploaded, 'errors' => $errors, 'count' => count($uploaded)]);
    }

    if ($adminPath === '/va-import' && $adminMethod === 'POST') {
        if (!isset($_FILES['file'])) jsonError('No file uploaded');
        $tmp = $_FILES['file']['tmp_name'];
        if (!is_file($tmp)) jsonError('Upload failed');
        $raw = file_get_contents($tmp);
        if ($raw === false || trim($raw) === '') jsonError('Empty CSV');
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
        $lines = preg_split('/\r\n|\r|\n/', $raw);
        $lines = array_values(array_filter(array_map('trim', $lines), function($l) { return $l !== ''; }));

        $parseRow = function($line) {
            $cells = []; $cur = ''; $inQ = false; $len = strlen($line);
            for ($i = 0; $i < $len; $i++) {
                $ch = $line[$i];
                if ($inQ) {
                    if ($ch === '"' && $i + 1 < $len && $line[$i + 1] === '"') { $cur .= '"'; $i++; }
                    elseif ($ch === '"') { $inQ = false; }
                    else $cur .= $ch;
                } else {
                    if ($ch === '"') { $inQ = true; }
                    elseif ($ch === ',') { $cells[] = $cur; $cur = ''; }
                    else $cur .= $ch;
                }
            }
            $cells[] = $cur;
            return $cells;
        };

        $headers = array_values(array_filter(array_map(function($h) {
            $h = preg_replace('/^\xEF\xBB\xBF/', '', (string)$h);
            $h = trim($h, " \t\"'");
            return strtolower(str_replace(['-',' ','.'], '_', $h));
        }, $parseRow($lines[0] ?? '')), 'strlen'));

        if (count($lines) < 2 || empty($headers)) jsonError('Empty CSV');
        $unitIdx = array_search('unit_number', $headers);
        $vaIdx = array_search('virtual_account', $headers);
        if ($vaIdx === false) $vaIdx = array_search('va', $headers);
        if ($unitIdx === false || $vaIdx === false) { jsonError('CSV must have unit_number and virtual_account columns. Detected headers (' . count($lines) . ' lines): ' . (implode(',', $headers) ?: 'none')); }

        $updated = []; $errors = [];
        for ($li = 1; $li < count($lines); $li++) {
            $row = $parseRow($lines[$li]);
            $unitNo = trim($row[$unitIdx] ?? '');
            $vaRaw = trim($row[$vaIdx] ?? '');
            if ($unitNo === '') continue;
            $user = fetchOne('SELECT id, unit_number FROM users WHERE unit_number = ?', 's', $unitNo);
            if (!$user) { $errors[] = "Unit $unitNo not found"; continue; }
            $va = normalizeVa($vaRaw);
            if ($vaRaw !== '' && $va === '') { $errors[] = "Unit $unitNo: VA '$vaRaw' looks corrupted (scientific notation) — format the VA column as text in Excel before exporting"; continue; }
            if ($va === '') { $errors[] = "Unit $unitNo: VA is empty"; continue; }
            querySingle('UPDATE users SET virtual_account = ? WHERE id = ?', 'si', $va, $user['id']);
            querySingle('UPDATE billing SET virtual_account = ? WHERE unit_number = ?', 'ss', $va, $unitNo);
            $updated[] = ['unit_number' => $unitNo, 'virtual_account' => $va];
        }
        auditLog($adminUser['id'], 'import_va', 'Imported '.count($updated).' virtual account numbers');
        jsonSuccess(['updated' => $updated, 'errors' => $errors, 'count' => count($updated)]);
    }

    // --- Admin Duplicate Payments: same billing_period + same amount (possible double bookings) ---
    if ($adminPath === '/payments/duplicates' && $adminMethod === 'GET') {
        $period = trim($input['period'] ?? $_GET['period'] ?? '');
        $where = " b.billing_period IS NOT NULL AND b.billing_period != ''";
        $types = ''; $params = [];
        if ($period !== '') { $where .= ' AND b.billing_period = ?'; $types .= 's'; $params[] = $period; }
        $db->query('SET SESSION group_concat_max_len = 65535');
        $groups = fetchAll("SELECT b.billing_period, u.unit_number, u.name as user_name, p.amount, COUNT(*) as cnt, GROUP_CONCAT(p.id ORDER BY p.id SEPARATOR ',') as payment_ids, GROUP_CONCAT(DISTINCT b.invoice_no SEPARATOR ' | ') as invoices FROM payments p JOIN billing b ON b.id=p.billing_id JOIN users u ON u.id=p.user_id WHERE $where GROUP BY b.billing_period, u.unit_number, u.name, p.amount HAVING COUNT(*) > 1 ORDER BY cnt DESC, b.billing_period DESC, u.unit_number ASC", $types, ...$params);
        $idsAll = [];
        foreach ($groups as $g) { foreach (explode(',', $g['payment_ids']) as $pid) { $idsAll[(int)$pid] = (int)$pid; } }
        $payById = [];
        if ($idsAll) {
            $qr = str_repeat('i', count($idsAll));
            $ph = implode(',', array_fill(0, count($idsAll), '?'));
            $rows = fetchAll("SELECT p.id, p.amount, p.method, p.reference, p.paid_at, p.billing_id, b.invoice_no, b.billing_period, u.unit_number, u.name as user_name FROM payments p JOIN billing b ON b.id=p.billing_id JOIN users u ON u.id=p.user_id WHERE p.id IN ($ph)", $qr, ...array_values($idsAll));
            foreach ($rows as $r) $payById[(int)$r['id']] = $r;
        }
        foreach ($groups as &$g) {
            $gPay = [];
            foreach (explode(',', $g['payment_ids']) as $pid) { if (isset($payById[(int)$pid])) $gPay[] = $payById[(int)$pid]; }
            $g['payments'] = $gPay;
            $g['same_invoice'] = (substr_count($g['invoices'], ' | ') === 0);
            unset($g['payment_ids']);
        }
        unset($g);
        jsonSuccess(['duplicates' => $groups, 'total' => count($groups)]);
    }

    // --- Admin: remove ALL duplicate payments (keep the earliest payment per bill+amount) ---
    if ($adminPath === '/payments/duplicates/remove-all' && $adminMethod === 'POST') {
        $period = trim($input['period'] ?? $_GET['period'] ?? '');
        $where = '';
        if ($period !== '') { $where = ' WHERE b.billing_period = ?'; }
        $db->query('SET SESSION group_concat_max_len = 65535');
        $groups = fetchAll("SELECT b.id as billing_id, b.billing_period, p.amount, COUNT(*) as cnt, GROUP_CONCAT(p.id ORDER BY p.id SEPARATOR ',') as payment_ids, b.invoice_no, u.unit_number FROM payments p JOIN billing b ON b.id=p.billing_id JOIN users u ON u.id=p.user_id$where GROUP BY b.id, b.billing_period, p.amount, b.invoice_no, u.unit_number HAVING COUNT(*) > 1 ORDER BY cnt DESC", $period !== '' ? 's' : '', ...($period !== '' ? [$period] : []));
        $deleted = []; $affectedBills = [];
        foreach ($groups as $g) {
            $ids = array_map('intval', explode(',', $g['payment_ids']));
            $keep = array_shift($ids);
            $affectedBills[(int)$g['billing_id']] = true;
            foreach ($ids as $pid) {
                querySingle('DELETE FROM payments WHERE id=?', 'i', $pid);
                $deleted[] = $pid;
            }
        }
        foreach (array_keys($affectedBills) as $bid) recalcBill((int)$bid);
        auditLog($adminUser['id'], 'remove_duplicate_payments', 'Removed '.count($deleted).' duplicate payments (kept earliest per bill+amount): '.implode(',', array_slice($deleted, 0, 100)));
        jsonSuccess(['deleted' => $deleted, 'count' => count($deleted), 'groups' => count($groups)]);
    }

    // --- Admin Payments ---
    if (preg_match('#^/payments/(\d+)$#', $adminPath, $mPay) === 1 && $adminMethod === 'GET') {
        $pay = fetchOne('SELECT p.*, b.unit_number, b.invoice_no, b.billing_period, u.name as user_name FROM payments p JOIN billing b ON b.id=p.billing_id JOIN users u ON u.id=p.user_id WHERE p.id=?', 'i', (int)$mPay[1]);
        if (!$pay) jsonError('Payment not found', 404);
        jsonSuccess($pay);
    }

    if ($adminPath === '/payments' && $adminMethod === 'GET') {
        $q = trim($input['q'] ?? $_GET['q'] ?? '');
        $period = trim($input['period'] ?? $_GET['period'] ?? '');
        $methodFilter = trim($input['method'] ?? $_GET['method'] ?? '');
        $hasPageKey = array_key_exists('page', $_GET) || array_key_exists('page_size', $_GET);
        $usePaging = $q !== '' || $period !== '' || $methodFilter !== '' || $hasPageKey;
        $where = ''; $types = ''; $conds = [];
        if ($q !== '') {
            $conds[] = '(u.unit_number LIKE ? OR u.name LIKE ? OR b.invoice_no LIKE ? OR p.reference LIKE ? OR p.method LIKE ?)';
            $types .= 'sssss';
        }
        if ($period !== '') { $conds[] = 'b.billing_period = ?'; $types .= 's'; }
        if ($methodFilter !== '') { $conds[] = 'p.method = ?'; $types .= 's'; }
        if (!empty($conds)) $where = ' WHERE '.implode(' AND ', $conds);
        $baseSelect = 'SELECT p.id, p.billing_id, p.user_id, p.amount, p.method, p.reference, p.paid_at, u.unit_number, u.name as user_name, b.invoice_no, b.billing_period FROM payments p JOIN billing b ON b.id = p.billing_id JOIN users u ON u.id = p.user_id';
        if ($usePaging) {
            $page = max(1, (int)($_GET['page'] ?? 1));
            $pageSize = min(1000, max(1, (int)($_GET['page_size'] ?? 50)));
            $countParams = [];
            if ($q !== '') { for ($i=0; $i<5; $i++) $countParams[] = '%'.$q.'%'; }
            if ($period !== '') $countParams[] = $period;
            if ($methodFilter !== '') $countParams[] = $methodFilter;
            $countRow = fetchOne('SELECT COUNT(*) as cnt FROM payments p JOIN billing b ON b.id = p.billing_id JOIN users u ON u.id = p.user_id'.$where, $types, ...$countParams);
            $total = (int)($countRow['cnt'] ?? 0);
            $offset = ($page - 1) * $pageSize;
            $types .= 'ii';
            $dataParams = $countParams; $dataParams[] = $pageSize; $dataParams[] = $offset;
            $payments = fetchAll($baseSelect.$where.' ORDER BY p.paid_at DESC, p.id DESC LIMIT ? OFFSET ?', $types, ...$dataParams);
            jsonSuccess(['payments' => $payments, 'total' => $total, 'page' => $page, 'page_size' => $pageSize]);
        }
        jsonSuccess(fetchAll($baseSelect.' ORDER BY p.paid_at DESC, p.id DESC'));
    }

    // Recompute a bill's paid/status from its payment rows (see recalcBill()).

    if ($adminPath === '/payments' && $adminMethod === 'POST') {
        $billingId = (int)($input['billing_id'] ?? 0);
        if ($billingId <= 0) {
            $unitNo = trim($input['unit_number'] ?? '');
            $invoiceNo = trim($input['invoice_no'] ?? '');
            $period = trim($input['billing_period'] ?? '');
            $user = $unitNo !== '' ? fetchOne('SELECT id FROM users WHERE unit_number=?', 's', $unitNo) : null;
            if ($invoiceNo !== '') {
                $bill = fetchOne('SELECT id, user_id FROM billing WHERE invoice_no=? ORDER BY id DESC LIMIT 1', 's', $invoiceNo);
            } elseif ($period !== '' && $user) {
                $bill = fetchOne('SELECT id, user_id FROM billing WHERE user_id=? AND billing_period=? ORDER BY id DESC LIMIT 1', 'is', $user['id'], $period);
            } elseif ($user) {
                $bill = fetchOne("SELECT id, user_id FROM billing WHERE user_id=? AND (status IS NULL OR status != 'paid') ORDER BY due_date ASC, id ASC LIMIT 1", 'i', $user['id']);
            }
            if (!empty($bill)) $billingId = (int)$bill['id'];
        }
        if ($billingId <= 0) jsonError('No matching billing found. Provide billing_id, or unit_number + billing_period / invoice_no.');
        $bill = fetchOne('SELECT id, user_id, amount FROM billing WHERE id=?', 'i', $billingId);
        if (!$bill) jsonError('Billing not found', 404);
        $amount = (float)($input['amount'] ?? 0);
        if ($amount <= 0) jsonError('Invalid amount');
        $method = trim($input['method'] ?? '');
        if ($method === '') $method = 'Bank Transfer';
        $reference = trim($input['reference'] ?? '');
        if ($reference === '') $reference = strtoupper(substr(md5($billingId.':'.uniqid()), 0, 12));
        $paidAt = normDate($input['paid_at'] ?? '') . ' 12:00:00';
        if ($paidAt === ' 12:00:00') $paidAt = date('Y-m-d H:i:s');
        querySingle('INSERT INTO payments (billing_id,user_id,amount,method,reference,paid_at) VALUES (?,?,?,?,?,?)', 'iidsss', $billingId, $bill['user_id'], $amount, $method, $reference, $paidAt);
        $payId = $db->insert_id;
        recalcBill($billingId);
        auditLog($adminUser['id'], 'create_payment', "Created payment #$payId ($amount) for billing #$billingId");
        jsonSuccess(['message' => 'Payment created', 'id' => $payId]);
    }

    if (preg_match('#^/payments/(\d+)$#', $adminPath, $m) === 1 && $adminMethod === 'PUT') {
        $payId = (int)$m[1];
        $pay = fetchOne('SELECT id, billing_id, user_id FROM payments WHERE id=?', 'i', $payId);
        if (!$pay) jsonError('Payment not found', 404);
        $recalcBills = [(int)$pay['billing_id']];
        if (isset($input['billing_period']) && trim(strval($input['billing_period'])) !== '') {
            $nPeriod = trim(strval($input['billing_period']));
            $nb = fetchOne('SELECT id FROM billing WHERE user_id=? AND billing_period=? ORDER BY id ASC LIMIT 1', 'is', (int)$pay['user_id'], $nPeriod);
            if (!$nb) jsonError('No billing found for that unit in period: ' . $nPeriod);
            $input['billing_id'] = (int)$nb['id'];
            $recalcBills[] = (int)$nb['id'];
        }
        $map = ['amount'=>'d','method'=>'s','reference'=>'s','paid_at'=>'s','billing_id'=>'i'];
        $fields = []; $types = ''; $vals = [];
        foreach ($map as $f => $tt) {
            if (isset($input[$f]) && $input[$f] !== null) { $fields[] = "$f=?"; $types .= $tt; $vals[] = ($f === 'paid_at') ? normDate($input[$f]).' 12:00:00' : $input[$f]; }
        }
        if (empty($fields)) jsonError('No fields to update');
        $types .= 'i'; $vals[] = $payId;
        querySingle('UPDATE payments SET '.implode(',',$fields).' WHERE id=?', $types, ...$vals);
        foreach (array_unique($recalcBills) as $rbId) recalcBill($rbId);
        auditLog($adminUser['id'], 'update_payment', "Updated payment #$payId");
        jsonSuccess(['message' => 'Payment updated']);
    }

    if (preg_match('#^/payments/(\d+)$#', $adminPath, $m) === 1 && $adminMethod === 'DELETE') {
        $payId = (int)$m[1];
        $pay = fetchOne('SELECT id, billing_id FROM payments WHERE id=?', 'i', $payId);
        if (!$pay) jsonError('Payment not found', 404);
        querySingle('DELETE FROM payments WHERE id=?', 'i', $payId);
        recalcBill($pay['billing_id']);
        auditLog($adminUser['id'], 'delete_payment', "Deleted payment #$payId");
        jsonSuccess(['message' => 'Payment deleted']);
    }

    if ($adminPath === '/payments/upload' && $adminMethod === 'POST') {
        if (!isset($_FILES['file'])) jsonError('No file uploaded');
        $tmp = $_FILES['file']['tmp_name'];
        if (!is_file($tmp)) jsonError('Upload failed');
        $raw = file_get_contents($tmp);
        if ($raw === false || trim($raw) === '') jsonError('Empty CSV');
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
        $lines = preg_split('/\r\n|\r|\n/', $raw);
        $lines = array_values(array_filter(array_map('trim', $lines), function($l) { return $l !== ''; }));

        $parseRow = function($line) {
            $cells = []; $cur = ''; $inQ = false; $len = strlen($line);
            for ($i = 0; $i < $len; $i++) {
                $ch = $line[$i];
                if ($inQ) {
                    if ($ch === '"' && $i + 1 < $len && $line[$i + 1] === '"') { $cur .= '"'; $i++; }
                    elseif ($ch === '"') { $inQ = false; }
                    else $cur .= $ch;
                } else {
                    if ($ch === '"') { $inQ = true; }
                    elseif ($ch === ',') { $cells[] = $cur; $cur = ''; }
                    else $cur .= $ch;
                }
            }
            $cells[] = $cur;
            return $cells;
        };

        $headers = array_values(array_filter(array_map(function($h) {
            $h = preg_replace('/^\xEF\xBB\xBF/', '', (string)$h);
            $h = trim($h, " \t\"'");
            return strtolower(str_replace(['-',' ','.'], '_', $h));
        }, $parseRow($lines[0] ?? '')), 'strlen'));
        if (count($lines) < 2 || empty($headers)) jsonError('Empty CSV');

        $colMap = [];
        $expected = [
            'unit_number' => ['unit_number','unit','unit_no','unit_name'],
            'paid_amount' => ['paid_amount','amount','paid','payment','payment_amount'],
            'reference' => ['reference','ref','payment_ref'],
            'method' => ['method','payment_method'],
            'paid_at' => ['paid_at','payment_date','date'],
            'billing_period' => ['billing_period','period'],
            'invoice_no' => ['invoice_no','invoice_number'],
        ];
        foreach ($expected as $col => $names) {
            foreach ($names as $name) {
                $idx = array_search($name, $headers);
                if ($idx !== false) { $colMap[$col] = $idx; break; }
            }
        }
        if (!isset($colMap['unit_number']) || !isset($colMap['paid_amount'])) {
            jsonError('CSV must have unit_number and paid_amount columns. Detected headers: ' . (implode(',', $headers) ?: 'none'));
        }

        $num = function($v) { return (float)preg_replace('/[^0-9.]/', '', $v); };
        $recorded = []; $errors = [];
        for ($li = 1; $li < count($lines); $li++) {
            $row = $parseRow($lines[$li]);
            $unitNo = trim($row[$colMap['unit_number']] ?? '');
            $paidAmount = $num(trim($row[$colMap['paid_amount']] ?? ''));
            if ($unitNo === '') continue;
            if ($paidAmount <= 0) { $errors[] = "Unit $unitNo: invalid paid amount"; continue; }
            $user = fetchOne('SELECT id, unit_number FROM users WHERE unit_number = ?', 's', $unitNo);
            if (!$user) { $errors[] = "Unit $unitNo not found"; continue; }
            $period = trim($row[$colMap['billing_period'] ?? -1] ?? '');
            $invoiceNo = trim($row[$colMap['invoice_no'] ?? -1] ?? '');
            if ($invoiceNo !== '') {
                $bill = fetchOne('SELECT * FROM billing WHERE user_id=? AND invoice_no=? ORDER BY id DESC LIMIT 1', 'is', $user['id'], $invoiceNo);
            } elseif ($period !== '') {
                $bill = fetchOne("SELECT * FROM billing WHERE user_id=? AND billing_period=? ORDER BY (status='paid') ASC, due_date ASC, id ASC LIMIT 1", 'is', $user['id'], $period);
            } else {
                $bill = fetchOne("SELECT * FROM billing WHERE user_id=? AND (status IS NULL OR status != 'paid') ORDER BY due_date ASC, id ASC LIMIT 1", 'i', $user['id']);
            }
            if (!$bill) { $errors[] = "Unit $unitNo: no matching unpaid invoice" . ($period !== '' ? " for period $period" : ''); continue; }

            $billPeriod = $bill['billing_period'] ?? '';
            if ($billPeriod !== '') {
                $dupAmt = fetchOne('SELECT py.id FROM payments py JOIN billing b ON b.id=py.billing_id WHERE b.unit_number=? AND b.billing_period=? AND ABS(py.amount - ?) < 0.005 LIMIT 1', 'ssd', $unitNo, $billPeriod, $paidAmount);
                if ($dupAmt) { $errors[] = "Unit $unitNo (period $billPeriod): payment of $paidAmount already recorded"; continue; }
            }

            $reference = trim($row[$colMap['reference'] ?? -1] ?? '');
            if ($reference !== '') {
                $dup = fetchOne('SELECT id FROM payments WHERE billing_id=? AND reference=?', 'is', $bill['id'], $reference);
                if ($dup) { $errors[] = "Unit $unitNo ($reference): payment already recorded"; continue; }
            } else {
                $reference = strtoupper(substr(md5($bill['id'] . ':' . $unitNo . ':' . microtime()), 0, 12));
            }
            $method = trim($row[$colMap['method'] ?? -1] ?? '');
            if ($method === '') $method = 'Bank Transfer';
            $paidDate = normDate(trim($row[$colMap['paid_at'] ?? -1] ?? ''));
            $paidDateTime = $paidDate === '' ? date('Y-m-d H:i:s') : $paidDate . ' 12:00:00';

            querySingle('INSERT INTO payments (billing_id,user_id,amount,method,reference,paid_at) VALUES (?,?,?,?,?,?)', 'iidsss', $bill['id'], $user['id'], $paidAmount, $method, $reference, $paidDateTime);
            recalcBill($bill['id']);
            $updated = fetchOne('SELECT invoice_no, paid FROM billing WHERE id=?', 'i', $bill['id']);
            $newPaid = (float)($updated['paid'] ?? 0);
            $recorded[] = ['id' => $bill['id'], 'invoice_no' => $updated['invoice_no'], 'unit_number' => $unitNo, 'paid' => $newPaid];
        }
        auditLog($adminUser['id'], 'record_payments', 'Recorded '.count($recorded).' tenant payments from CSV');
        jsonSuccess(['recorded' => $recorded, 'errors' => $errors, 'count' => count($recorded)]);
    }

    // --- AST Payment Trx: book selected rows into payments ---
    if ($adminPath === '/ast-payments' && $adminMethod === 'POST') {
        $rows = $input['rows'] ?? [];
        if (!is_array($rows) || !count($rows)) jsonError('No rows selected');
        $periodInput = trim(strval($input['billing_period'] ?? ''));
        $periodKey = function($p) {
            $p = strtolower(trim((string)$p));
            if ($p === '') return null;
            if (preg_match('/^(\d{4})[-]?(\d{1,2})$/', $p, $m)) return $m[1] . '-' . str_pad($m[2], 2, '0', STR_PAD_LEFT);
            $names = ['january'=>'01','jan'=>'01','february'=>'02','feb'=>'02','march'=>'03','mar'=>'03','april'=>'04','apr'=>'04','may'=>'05','june'=>'06','jun'=>'06','july'=>'07','jul'=>'07','agustus'=>'08','august'=>'08','aug'=>'08','september'=>'09','sept'=>'09','sep'=>'09','october'=>'10','oct'=>'10','november'=>'11','nov'=>'11','december'=>'12','dec'=>'12'];
            foreach ($names as $name => $num) {
                if (strpos($p, $name) === false) continue;
                $year = null;
                if (preg_match('/(19|20)\d{2}/', $p, $ym)) $year = $ym[0];
                elseif (preg_match('/(^|[- ])(\d{2})([- ]|$)/', $p, $ym2)) $year = '20' . $ym2[2];
                if ($year === null) $year = date('Y');
                return $year . '-' . $num;
            }
            return null;
        };
        $period = $periodInput;
        if ($periodInput !== '') {
            $pk = $periodKey($periodInput);
            if ($pk !== null) {
                $toCanon = fetchAll("SELECT DISTINCT billing_period FROM billing WHERE billing_period IS NOT NULL AND billing_period != ''");
                foreach ($toCanon as $c) {
                    if ($periodKey($c['billing_period']) === $pk) { $period = $c['billing_period']; break; }
                }
            }
        }
        $recorded = []; $errors = []; $skipped = [];
        foreach ($rows as $row) {
            $acct = trim(strval($row['debtor_acct'] ?? ''));
            $cands = [];
            $cands[] = $acct;
            $slash = stripos($acct, '/');
            if ($slash !== false) $cands[] = trim(substr($acct, 0, $slash));
            if (strpos($acct, ' ') !== false) $cands[] = str_replace(' ', '', $acct);
            $cands = array_values(array_unique(array_filter($cands, fn($c) => $c !== '')));
            $user = null;
            foreach ($cands as $c) {
                $u = fetchOne('SELECT id, unit_number FROM users WHERE unit_number = ?', 's', $c);
                if ($u) { $user = $u; break; }
            }
            $unitNo = $cands ? $cands[0] : '';
            $paidAmount = (float)preg_replace('/[^0-9.]/', '', strval($row['trx_amt'] ?? ''));
            if ($unitNo === '') continue;
            if ($paidAmount <= 0) { $errors[] = "Unit $unitNo: invalid paid amount"; continue; }
            if (!$user) { $errors[] = "Unit $unitNo not found in portal"; continue; }
            if ($period !== '') {
                $bill = fetchOne("SELECT * FROM billing WHERE user_id=? AND billing_period=? ORDER BY (status='paid') ASC, due_date ASC, id ASC LIMIT 1", 'is', $user['id'], $period);
            } else {
                $bill = fetchOne("SELECT * FROM billing WHERE user_id=? AND (status IS NULL OR status != 'paid') ORDER BY due_date ASC, id ASC LIMIT 1", 'i', $user['id']);
            }
            if (!$bill) { $errors[] = "Unit $unitNo: no matching unpaid invoice" . ($periodInput !== '' ? " for period $periodInput" : ''); continue; }

            $existing = fetchOne('SELECT id FROM payments WHERE billing_id=? AND ABS(COALESCE(amount,0) - ?) < 0.005 LIMIT 1', 'id', $bill['id'], $paidAmount);
            if ($existing) {
                $skipped[] = ['unit_number' => $unitNo, 'amount' => $paidAmount, 'period' => $bill['billing_period'] ?? ''];
                continue;
            }

            $reference = trim(strval($row['doc_no'] ?? ''));
            if ($reference === '') $reference = trim(strval($row['ref_no'] ?? ''));
            if ($reference !== '') {
                $dup = fetchOne('SELECT id FROM payments WHERE billing_id=? AND reference=?', 'is', $bill['id'], $reference);
                if ($dup) { $errors[] = "Unit $unitNo ($reference): payment already recorded"; continue; }
            } else {
                $reference = strtoupper(substr(md5($bill['id'] . ':' . $unitNo . ':' . microtime()), 0, 12));
            }
            $method = 'Bank Transfer';
            $paidDate = normDate(substr(strval($row['doc_date'] ?? ''), 0, 10));
            $paidDateTime = $paidDate === '' ? date('Y-m-d H:i:s') : $paidDate . ' 12:00:00';

            querySingle('INSERT INTO payments (billing_id,user_id,amount,method,reference,paid_at) VALUES (?,?,?,?,?,?)', 'iidsss', $bill['id'], $user['id'], $paidAmount, $method, $reference, $paidDateTime);
            recalcBill($bill['id']);
            $updated = fetchOne('SELECT invoice_no, paid FROM billing WHERE id=?', 'i', $bill['id']);
            $newPaid = (float)($updated['paid'] ?? 0);
            $recorded[] = ['id' => $bill['id'], 'invoice_no' => $updated['invoice_no'], 'unit_number' => $unitNo, 'paid' => $newPaid];
        }
        auditLog($adminUser['id'], 'record_payments', 'Recorded '.count($recorded).' tenant payments from AST Payment Trx ('.count($skipped).' skipped, same amount already recorded for period)');
        jsonSuccess(['recorded' => $recorded, 'errors' => $errors, 'skipped' => $skipped, 'count' => count($recorded), 'skipped_count' => count($skipped)]);
    }

    // --- Admin Users ---
    if ($adminPath === '/users' && $adminMethod === 'POST') {
        $adminUser = requireUserManager();
        $name = trim($input['name'] ?? '');
        $unit = trim($input['unit_number'] ?? '');
        if ($name === '' || $unit === '') jsonError('Name and unit number are required');
        $email = trim($input['email'] ?? '');
        $role = trim($input['role'] ?? 'customer');
        if (!in_array($role, ['customer','finance','admin','super_admin'])) $role = 'customer';
        $password = ($input['password'] ?? '') !== '' ? $input['password'] : 'password123';
        if (fetchOne('SELECT id FROM users WHERE unit_number = ?', 's', $unit)) jsonError('Unit number already exists');
        if ($email !== '' && fetchOne('SELECT id FROM users WHERE email = ?', 's', $email)) jsonError('Email already exists');
        querySingle('INSERT INTO users (unit_number,name,email,password,role,company,phone,virtual_account) VALUES (?,?,?,?,?,?,?,?)',
            'ssssssss', $unit, $name, $email, password_hash($password, PASSWORD_BCRYPT), $role,
            trim($input['company'] ?? ''), trim($input['phone'] ?? ''), trim($input['virtual_account'] ?? ''));
        $newId = $db->insert_id;
        auditLog($adminUser['id'], 'create_user', "Created user #$newId ($unit)");
        jsonSuccess(['message' => 'User created', 'id' => $newId]);
    }

    if (preg_match('#^/users(/(\d+))?$#', $adminPath, $m) === 1 && $adminMethod === 'GET') {
        if (!empty($m[2])) {
            jsonSuccess(fetchOne('SELECT id,unit_number,name,email,role,company,phone,virtual_account,extra_phones,extra_emails,created_at FROM users WHERE id = ?', 'i', (int)$m[2]));
        } else {
            $q = trim($input['q'] ?? $_GET['q'] ?? '');
            $hasPageKey = array_key_exists('page', $_GET) || array_key_exists('page_size', $_GET);
            $usePaging = $q !== '' || $hasPageKey;
            $where = ''; $types = '';
            $conds = [];
            if ($q !== '') {
                $conds[] = '(unit_number LIKE ? OR name LIKE ? OR email LIKE ? OR company LIKE ? OR phone LIKE ? OR virtual_account LIKE ?)';
                $types .= 'ssssss';
                $like = '%'.$q.'%';
            }
            if (!empty($conds)) $where = ' WHERE '.implode(' AND ', $conds);
            $baseSelect = 'SELECT id,unit_number,name,email,role,company,phone,virtual_account,extra_phones,extra_emails,created_at FROM users';
            if ($usePaging) {
                $page = max(1, (int)($_GET['page'] ?? 1));
                $pageSize = min(1000, max(1, (int)($_GET['page_size'] ?? 50)));
                $countParams = [];
                if ($q !== '') { for ($i=0;$i<6;$i++) $countParams[] = $like; }
                $countRow = fetchOne('SELECT COUNT(*) as cnt FROM users'.$where, $types, ...$countParams);
                $total = (int)($countRow['cnt'] ?? 0);
                $offset = ($page - 1) * $pageSize;
                $types .= 'ii';
                $dataParams = $countParams; $dataParams[] = $pageSize; $dataParams[] = $offset;
                $users = fetchAll($baseSelect.$where.' ORDER BY unit_number ASC LIMIT ? OFFSET ?', $types, ...$dataParams);
                jsonSuccess(['users' => $users, 'total' => $total, 'page' => $page, 'page_size' => $pageSize]);
            }
            jsonSuccess(fetchAll($baseSelect.' ORDER BY unit_number ASC'));
        }
    }

    if (preg_match('#^/users/(\d+)$#', $adminPath, $m) === 1 && $adminMethod === 'PUT') {
        $adminUser = requireUserManager();
        $id = (int)$m[1];
        $targetRole = fetchOne('SELECT role FROM users WHERE id = ?', 'i', $id)['role'] ?? null;
        if ($targetRole === null) jsonError('User not found', 404);
        if ($targetRole === 'super_admin' && $adminUser['role'] !== 'super_admin') jsonError('Forbidden', 403);
        $fields = []; $types = ''; $vals = [];
        foreach (['name'=>'s','email'=>'s','phone'=>'s','company'=>'s','role'=>'s','unit_number'=>'s','virtual_account'=>'s'] as $f => $t) {
            if (isset($input[$f])) { $fields[] = "$f=?"; $types .= $t; $vals[] = trim($input[$f]); }
        }
        if (empty($fields)) jsonError('No fields to update');
        if (isset($input['password']) && $input['password'] !== '') {
            $fields[] = "password=?"; $types .= 's'; $vals[] = password_hash($input['password'], PASSWORD_BCRYPT);
        }
        $types .= 'i'; $vals[] = $id;
        querySingle('UPDATE users SET '.implode(',',$fields).' WHERE id=?', $types, ...$vals);
        auditLog($adminUser['id'], 'update_user', "Updated user #$id");
        jsonSuccess(['message' => 'User updated']);
    }

    if (preg_match('#^/users/(\d+)/reset-password$#', $adminPath, $m) === 1 && $adminMethod === 'POST') {
        $adminUser = requireUserManager();
        $id = (int)$m[1];
        $target = fetchOne('SELECT id, unit_number, name, email, extra_emails, role FROM users WHERE id = ?', 'i', $id);
        if (!$target) jsonError('User not found', 404);
        if ($target['role'] === 'super_admin' && $adminUser['role'] !== 'super_admin') jsonError('Forbidden', 403);
        $newPw = bin2hex(random_bytes(8));
        querySingle('UPDATE users SET password=? WHERE id=?', 'si', password_hash($newPw, PASSWORD_BCRYPT), $id);
        $mail = sendPasswordResetMail($target, $newPw, 'admin');
        $emailSent = (bool)($mail['sent'] ?? false);
        auditLog($adminUser['id'], 'reset_password', "Reset password for user #$id (".($target['unit_number'] ?? '')."). Email ".($emailSent ? 'sent to '.$mail['to'] : 'NOT sent')."");
        jsonSuccess(['message' => 'Password reset', 'new_password' => $newPw, 'email_sent' => $emailSent, 'email_to' => $emailSent ? $mail['to'] : null]);
    }

    if (preg_match('#^/users/(\d+)$#', $adminPath, $m) === 1 && $adminMethod === 'DELETE') {
        $adminUser = requireUserManager();
        $id = (int)$m[1];
        $target = fetchOne('SELECT id, unit_number, name, role FROM users WHERE id = ?', 'i', $id);
        if ($target && $target['role'] === 'super_admin' && $adminUser['role'] !== 'super_admin') jsonError('Forbidden', 403);
        querySingle('DELETE FROM payments WHERE billing_id IN (SELECT id FROM billing WHERE user_id=?)', 'i', $id);
        querySingle('DELETE FROM billing WHERE user_id=?', 'i', $id);
        querySingle('DELETE FROM support_tickets WHERE user_id=?', 'i', $id);
        querySingle('DELETE FROM documents WHERE user_id=?', 'i', $id);
        querySingle('DELETE FROM audit_logs WHERE user_id=?', 'i', $id);
        querySingle('DELETE FROM users WHERE id=?', 'i', $id);
        auditLog($adminUser['id'], 'delete_user', "Deleted user #$id".($target ? ' ('.$target['unit_number'].' '.($target['name'] ?? '').')' : ''));
        jsonSuccess(['message' => 'User deleted']);
    }

    // --- Admin User CSV Upload ---
    if ($adminPath === '/users/upload' && $adminMethod === 'POST') {
        $adminUser = requireUserManager();
        if (!isset($_FILES['file'])) jsonError('No file uploaded');
        $tmp = $_FILES['file']['tmp_name'];
        if (!is_file($tmp)) jsonError('Upload failed');
        $raw = file_get_contents($tmp);
        if ($raw === false || trim($raw) === '') jsonError('Empty CSV');
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
        $firstLine = explode("\n", $raw)[0] ?? '';
        $sep = (substr_count($firstLine, ';') > substr_count($firstLine, ',')) ? ';' : ',';
        $lines = preg_split('/\r\n|\r|\n/', $raw);
        $lines = array_values(array_filter(array_map('trim', $lines), function($l) { return $l !== ''; }));
        if (count($lines) < 2) jsonError('Empty CSV');

        $parseRow = function($line) use ($sep) {
            $cells = []; $cur = ''; $inQ = false; $len = strlen($line);
            for ($i = 0; $i < $len; $i++) {
                $ch = $line[$i];
                if ($inQ) {
                    if ($ch === '"' && $i + 1 < $len && $line[$i + 1] === '"') { $cur .= '"'; $i++; }
                    elseif ($ch === '"') { $inQ = false; }
                    else $cur .= $ch;
                } else {
                    if ($ch === '"') { $inQ = true; }
                    elseif ($ch === $sep) { $cells[] = $cur; $cur = ''; }
                    else $cur .= $ch;
                }
            }
            $cells[] = $cur;
            return $cells;
        };

        $rawHeaders = $parseRow($lines[0]);
        $headers = array_map(function($h) { $h = preg_replace('/^\xEF\xBB\xBF/', '', $h); return strtolower(trim(str_replace(['-',' '], '_', trim($h)))); }, $rawHeaders);

        $colMap = [];
        $aliases = [
            'name' => ['name','tenant_name','tenant','debtor_name'],
            'unit_number' => ['unit_number','unit','debtor_acct','debtor_account','unit_no'],
            'email' => ['email','email_address'],
            'password' => ['password','pass'],
            'role' => ['role'],
            'company' => ['company','tenant_company'],
            'phone' => ['phone','phone_number','mobile','hp'],
        ];
        foreach ($aliases as $col => $names) {
            foreach ($names as $name) {
                $idx = array_search($name, $headers);
                if ($idx !== false) { $colMap[$col] = $idx; break; }
            }
        }
        if (!isset($colMap['name'])) jsonError('CSV must have a name column');

        $created = 0; $updated = 0; $errors = []; $usedEmails = [];
        for ($li = 1; $li < count($lines); $li++) {
            $row = $parseRow($lines[$li]);
            $unitRaw = trim($row[$colMap['unit_number']] ?? '');
            $name = trim($row[$colMap['name'] ?? -1] ?? '');
            if ($unitRaw === '' && $name === '') continue;

            $unit = $unitRaw;
            $extraPhones = [];
            if (isset($colMap['phone'])) {
                $phoneRaw = trim($row[$colMap['phone']] ?? '');
                if ($phoneRaw !== '' && $phoneRaw !== '-') {
                    $phoneParts = preg_split('/\s*[\/]\s*/', $phoneRaw);
                    $phoneParts = array_filter(array_map('trim', $phoneParts), function($p) { return $p !== '' && $p !== '-'; });
                    $phoneParts = array_values($phoneParts);
                    if (count($phoneParts) > 0) {
                        $phone = $phoneParts[0];
                        if (count($phoneParts) > 1) $extraPhones = array_slice($phoneParts, 1);
                    } else {
                        $phone = '';
                    }
                } else {
                    $phone = '';
                }
            } else {
                $phone = '';
            }

            $extraEmails = [];
            if (isset($colMap['email'])) {
                $emailRaw = trim($row[$colMap['email']] ?? '');
                if ($emailRaw !== '' && $emailRaw !== '-') {
                    $emailParts = preg_split('/\s*[;]\s*/', $emailRaw);
                    $emailParts = array_filter(array_map('trim', $emailParts), function($e) { return $e !== '' && $e !== '-'; });
                    $emailParts = array_values($emailParts);
                    if (count($emailParts) > 0) {
                        $email = $emailParts[0];
                        if (count($emailParts) > 1) $extraEmails = array_slice($emailParts, 1);
                    } else {
                        $email = '';
                    }
                } else {
                    $email = '';
                }
            } else {
                $email = '';
            }
            $extraPhones = sanitizePhoneList($extraPhones);
            $extraEmails = sanitizeEmailList($extraEmails);
            if ($email === '') $email = ($unit !== '' ? 'unit_'.$unit.'@mall.local' : 'user_'.($li+1).'@mall.local');

            $name = $name !== '' ? $name : ($unit !== '' ? 'Tenant Unit '.$unit : 'User '.$li);
            $pass = trim($row[$colMap['password'] ?? -1] ?? '');
            $role = trim($row[$colMap['role'] ?? -1] ?? '') ?: 'customer';
            if (!in_array($role, ['customer','finance','admin','super_admin'])) $role = 'customer';
            $company = trim($row[$colMap['company'] ?? -1] ?? '');
            if ($company === '' && preg_match('/^(PT|CV|PT\.|CV\.)\s/i', $name)) $company = $name;

            if ($unit === '') {
                $errors[] = "Row ".($li+1).": no unit_number, skipped";
                continue;
            }

            $existing = fetchOne('SELECT id, extra_phones, extra_emails FROM users WHERE unit_number = ?', 's', $unit);
            if ($existing) {
                $emailDup = fetchOne('SELECT id FROM users WHERE email = ? AND id != ?', 'si', $email, $existing['id']);
                $ee = json_decode($existing['extra_emails'] ?? '[]', true);
                $ep = json_decode($existing['extra_phones'] ?? '[]', true);
                foreach ($extraPhones as $p) { if (!in_array($p, $ep)) $ep[] = $p; }
                foreach ($extraEmails as $e) { if (!in_array($e, $ee)) $ee[] = $e; }
                if ($emailDup || in_array($email, $usedEmails)) { if ($email !== '' && !in_array($email, $ee)) $ee[] = $email; $fb = $unit . '@mall.local'; $fi = 2; while (in_array($fb, $usedEmails) || fetchOne('SELECT id FROM users WHERE email = ?', 's', $fb)) { $fb = $unit . '_' . $fi . '@mall.local'; $fi++; } $email = $fb; }
                $usedEmails[] = $email;
                $fields = ['name=?','email=?','role=?','company=?','phone=?','extra_phones=?','extra_emails=?'];
                $types = 'sssssss'; $vals = [$name, $email, $role, $company, $phone, json_encode(sanitizePhoneList($ep)), json_encode(sanitizeEmailList($ee))];
                if ($pass !== '') { $fields[] = 'password=?'; $types .= 's'; $vals[] = password_hash($pass, PASSWORD_BCRYPT); }
                $types .= 'i'; $vals[] = $existing['id'];
                querySingle('UPDATE users SET '.implode(',',$fields).' WHERE id=?', $types, ...$vals);
                $updated++;
            } else {
                $emailCheck = fetchOne('SELECT id FROM users WHERE email = ?', 's', $email);
                $eeNew = $extraEmails;
                if ($emailCheck || in_array($email, $usedEmails)) { if ($email !== '' && !in_array($email, $eeNew)) $eeNew[] = $email; $fb = $unit . '@mall.local'; $fi = 2; while (in_array($fb, $usedEmails) || fetchOne('SELECT id FROM users WHERE email = ?', 's', $fb)) { $fb = $unit . '_' . $fi . '@mall.local'; $fi++; } $email = $fb; }
                $usedEmails[] = $email;
                querySingle('INSERT INTO users (unit_number,name,email,password,role,company,phone,extra_phones,extra_emails) VALUES (?,?,?,?,?,?,?,?,?)', 'sssssssss',
                    $unit, $name, $email, password_hash($pass ?: 'password123', PASSWORD_BCRYPT), $role, $company, $phone, json_encode(sanitizePhoneList($extraPhones)), json_encode(sanitizeEmailList($eeNew)));
                $created++;
            }
        }
        auditLog($adminUser['id'], 'import_users', "$created users imported, $updated updated from CSV");
        jsonSuccess(['message' => "$created users imported, $updated updated", 'created' => $created, 'updated' => $updated, 'errors' => $errors]);
    }

    // --- Admin News CRUD ---
    if (preg_match('#^/news(/(\d+))?$#', $adminPath, $m) === 1 && $adminMethod === 'GET') {
        if (!empty($m[2])) {
            jsonSuccess(fetchOne('SELECT * FROM news WHERE id = ?', 'i', (int)$m[2]));
        } else {
            jsonSuccess(fetchAll('SELECT * FROM news ORDER BY created_at DESC'));
        }
    }

    if (preg_match('#^/news$#', $adminPath, $m) === 1 && $adminMethod === 'POST') {
        $title = trim($input['title'] ?? '');
        $content = trim($input['content'] ?? '');
        if ($title === '' || $content === '') jsonError('Title and content are required');
        $category = trim($input['category'] ?? 'announcement');
        $priority = trim($input['priority'] ?? 'normal');
        $image = trim($input['image'] ?? '');
        $featured = isset($input['featured']) ? (int)$input['featured'] : 0;
        $embedUrl = trim($input['embed_url'] ?? '');
        querySingle('INSERT INTO news (title,content,category,priority,image,embed_url,featured) VALUES (?,?,?,?,?,?,?)', 'ssssssi', $title, $content, $category, $priority, $image, $embedUrl, $featured);
        $newsId = $db->insert_id;
        auditLog($adminUser['id'], 'create_news', "Created news #$newsId: $title");
        jsonSuccess(['message' => 'News created', 'id' => $newsId]);
    }

    if (preg_match('#^/news/(\d+)$#', $adminPath, $m) === 1 && $adminMethod === 'PUT') {
        $id = (int)$m[1];
        $fields = []; $types = ''; $vals = [];
        foreach (['title'=>'s','content'=>'s','category'=>'s','priority'=>'s','image'=>'s','embed_url'=>'s','featured'=>'i'] as $f => $t) {
            if (isset($input[$f])) { $fields[] = "$f=?"; $types .= $t; $vals[] = $input[$f]; }
        }
        if (empty($fields)) jsonError('No fields to update');
        $types .= 'i'; $vals[] = $id;
        querySingle('UPDATE news SET '.implode(',',$fields).' WHERE id=?', $types, ...$vals);
        auditLog($adminUser['id'], 'update_news', "Updated news #$id");
        jsonSuccess(['message' => 'News updated']);
    }

    if (preg_match('#^/news/(\d+)$#', $adminPath, $m) === 1 && $adminMethod === 'DELETE') {
        $id = (int)$m[1];
        $exists = fetchOne('SELECT id, title FROM news WHERE id = ?', 'i', $id);
        querySingle('DELETE FROM news WHERE id=?', 'i', $id);
        auditLog($adminUser['id'], 'delete_news', "Deleted news #$id".($exists ? ': '.$exists['title'] : ''));
        jsonSuccess(['message' => 'News deleted']);
    }

    // --- Admin Documents ---
    if ($adminPath === '/documents' && $adminMethod === 'GET') {
        $docs = fetchAll('SELECT d.*, u.name as user_name FROM documents d LEFT JOIN users u ON u.id=d.user_id ORDER BY d.uploaded_at DESC');
        jsonSuccess($docs);
    }

    if ($adminPath === '/documents' && $adminMethod === 'POST') {
        $title = trim($input['title'] ?? '');
        if ($title === '') jsonError('Title is required');
        $userId = (int)($input['user_id'] ?? 0);
        $type = trim($input['type'] ?? 'PDF');
        $category = trim($input['category'] ?? '');
        $fileUrl = trim($input['file_url'] ?? '');
        $fileSize = trim($input['file_size'] ?? '');
        $description = trim($input['description'] ?? '');
        $isPublic = (int)($input['is_public'] ?? 1);
        $uid = $userId > 0 ? $userId : null;
        querySingle('INSERT INTO documents (user_id,title,type,category,file_url,file_size,description,is_public) VALUES (?,?,?,?,?,?,?,?)', 'isssssss', $uid, $title, $type, $category, $fileUrl, $fileSize, $description, $isPublic);
        $docId = $db->insert_id;
        auditLog($adminUser['id'], 'create_document', "Added document #$docId: $title");
        jsonSuccess(['message' => 'Document added', 'id' => $docId]);
    }

    if (preg_match('#^/documents/(\d+)$#', $adminPath, $m) === 1 && $adminMethod === 'DELETE') {
        $id = (int)$m[1];
        $exists = fetchOne('SELECT id, title FROM documents WHERE id = ?', 'i', $id);
        querySingle('DELETE FROM documents WHERE id=?', 'i', $id);
        auditLog($adminUser['id'], 'delete_document', "Deleted document #$id".($exists ? ': '.$exists['title'] : ''));
        jsonSuccess(['message' => 'Document deleted']);
    }

    if (preg_match('#^/documents/(\d+)$#', $adminPath, $m) === 1 && $adminMethod === 'PUT') {
        $docId = (int)$m[1];
        $existing = fetchOne('SELECT id FROM documents WHERE id = ?', 'i', $docId);
        if (!$existing) jsonError('Document not found');
        $title = trim($input['title'] ?? '');
        $type = trim($input['type'] ?? '');
        $category = trim($input['category'] ?? '');
        $fileUrl = trim($input['file_url'] ?? '');
        $fileSize = trim($input['file_size'] ?? '');
        $description = trim($input['description'] ?? '');
        $userId = (int)($input['user_id'] ?? 0);
        $isPublic = (int)($input['is_public'] ?? 1);
        $uid = $userId > 0 ? $userId : null;
        querySingle('UPDATE documents SET title=?,type=?,category=?,file_url=?,file_size=?,description=?,user_id=?,is_public=? WHERE id=?', 'sssssssii', $title, $type, $category, $fileUrl, $fileSize, $description, $uid, $isPublic, $docId);
        auditLog($adminUser['id'], 'update_document', "Updated document #$docId");
        jsonSuccess(['message' => 'Document updated']);
    }

    if ($adminPath === '/documents/upload' && $adminMethod === 'POST') {
        if (!isset($_FILES['file'])) jsonError('No file uploaded');
        $tmp = $_FILES['file']['tmp_name'];
        if (!is_file($tmp)) jsonError('Upload failed');
        $name = basename($_FILES['file']['name']);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $allowed = ['pdf','doc','docx','xls','xlsx','jpg','png','zip'];
        if (!in_array($ext, $allowed)) jsonError('File type not allowed');
        $dest = __DIR__ . '/../uploads/documents/' . uniqid() . '.' . $ext;
        $dir = dirname($dest);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        if (!move_uploaded_file($tmp, $dest)) jsonError('Upload failed');
        auditLog($adminUser['id'], 'upload_document', "Uploaded document file: $name");
        jsonSuccess(['path' => '/uploads/documents/' . basename($dest), 'name' => $name]);
    }

    // --- Admin News Image Upload ---
    if ($adminPath === '/news/upload' && $adminMethod === 'POST') {
        if (!isset($_FILES['file'])) jsonError('No file uploaded');
        $tmp = $_FILES['file']['tmp_name'];
        if (!is_file($tmp)) jsonError('Upload failed');
        $name = basename($_FILES['file']['name']);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $allowed = ['jpg','jpeg','png','gif','webp','svg'];
        if (!in_array($ext, $allowed)) jsonError('File type not allowed');
        $dest = __DIR__ . '/../uploads/news/' . uniqid() . '.' . $ext;
        $dir = dirname($dest);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        if (!move_uploaded_file($tmp, $dest)) jsonError('Upload failed');
        auditLog($adminUser['id'], 'upload_news_image', "Uploaded news image file: $name");
        jsonSuccess(['path' => '/uploads/news/' . basename($dest)]);
    }

    // --- Admin Support Tickets ---
    if ($adminPath === '/tickets' && $adminMethod === 'GET') {
        $tickets = fetchAll(
            "SELECT t.*, u.name as user_name, u.unit_number, u.email,
                (SELECT COUNT(*) FROM ticket_messages m
                    WHERE m.ticket_id = t.id AND m.sender_role='customer'
                    AND m.created_at > IFNULL(t.last_read_admin, '1970-01-01 00:00:00')) AS unread
             FROM support_tickets t JOIN users u ON u.id=t.user_id ORDER BY t.created_at DESC");
        foreach ($tickets as &$t) { $t['unread'] = (int)$t['unread']; }
        jsonSuccess($tickets);
    }

    if (preg_match('#^/tickets/(\d+)$#', $adminPath, $m) === 1 && $adminMethod === 'GET') {
        $ticket = fetchOne('SELECT t.*, u.name as user_name, u.unit_number FROM support_tickets t JOIN users u ON u.id=t.user_id WHERE t.id = ?', 'i', (int)$m[1]);
        if (!$ticket) jsonError('Ticket not found', 404);
        jsonSuccess($ticket);
    }

    if (preg_match('#^/tickets/(\d+)$#', $adminPath, $m) === 1 && $adminMethod === 'PUT') {
        $id = (int)$m[1];
        $fields = []; $types = ''; $vals = [];
        foreach (['status'=>'s','priority'=>'s','response'=>'s'] as $f => $t) {
            if (isset($input[$f])) { $fields[] = "$f=?"; $types .= $t; $vals[] = $input[$f]; }
        }
        if (isset($input['response']) && trim((string)$input['response']) !== '') {
            $fields[] = 'responded_at=NOW()';
        }
        if (empty($fields)) jsonError('No fields to update');
        $old = fetchOne('SELECT id, status, priority FROM support_tickets WHERE id = ?', 'i', $id);
        $types .= 'i'; $vals[] = $id;
        querySingle('UPDATE support_tickets SET '.implode(',',$fields).' WHERE id=?', $types, ...$vals);
        $detail = "Updated ticket #$id";
        if ($old && isset($input['status']) && $input['status'] !== $old['status']) {
            $detail .= " (status ".$old['status']." -> ".$input['status'].")";
        }
        auditLog($adminUser['id'], 'update_ticket', $detail);
        jsonSuccess(['message' => 'Ticket updated']);
    }

    if (preg_match('#^/tickets/(\d+)/messages$#', $adminPath, $m) === 1 && $adminMethod === 'GET') {
        $ticketId = (int)$m[1];
        $msgs = fetchAll('SELECT * FROM ticket_messages WHERE ticket_id = ? ORDER BY created_at ASC, id ASC', 'i', $ticketId);
        jsonSuccess($msgs);
    }

    if (preg_match('#^/tickets/(\d+)/messages$#', $adminPath, $m) === 1 && $adminMethod === 'POST') {
        $ticketId = (int)$m[1];
        $message = trim($input['message'] ?? '');
        if ($message === '') jsonError('Message is required');
        querySingle('INSERT INTO ticket_messages (ticket_id, sender_role, message) VALUES (?,?,?)', 'iss', $ticketId, 'admin', $message);
        querySingle("UPDATE support_tickets SET responded_at=NOW(), status=IF(status='closed', status, 'in_progress') WHERE id=?", 'i', $ticketId);
        auditLog($adminUser['id'], 'send_admin_reply', "Replied to ticket #$ticketId");
        jsonSuccess(['message' => 'Message sent']);
    }

    // --- Admin mark ticket as read ---
    if (preg_match('#^/tickets/(\d+)/read$#', $adminPath, $m) === 1 && $adminMethod === 'POST') {
        $ticketId = (int)$m[1];
        querySingle("UPDATE support_tickets SET last_read_admin=NOW() WHERE id=?", 'i', $ticketId);
        jsonSuccess(['message' => 'Marked read']);
    }

    // --- Admin Audit Logs ---
    if ($adminPath === '/logs' && $adminMethod === 'GET') {
        $q = trim($input['q'] ?? $_GET['q'] ?? '');
        $actionFilter = trim($input['action'] ?? $_GET['action'] ?? '');
        $dateFrom = trim($input['from'] ?? $_GET['from'] ?? '');
        $dateTo = trim($input['to'] ?? $_GET['to'] ?? '');
        $hasPageKey = array_key_exists('page', $_GET) || array_key_exists('page_size', $_GET);
        $usePaging = $q !== '' || $actionFilter !== '' || $dateFrom !== '' || $dateTo !== '' || $hasPageKey;
        $where = ''; $types = ''; $conds = [];
        if ($q !== '') {
            $conds[] = '(u.name LIKE ? OR u.unit_number LIKE ? OR l.action LIKE ? OR l.details LIKE ?)';
            $types .= 'ssss';
        }
        if ($actionFilter !== '') { $conds[] = 'l.action = ?'; $types .= 's'; }
        if ($dateFrom !== '') { $conds[] = 'l.created_at >= ?'; $types .= 's'; }
        if ($dateTo !== '') { $conds[] = 'l.created_at <= ?'; $types .= 's'; }
        if (!empty($conds)) $where = ' WHERE '.implode(' AND ', $conds);
        $baseSelect = 'SELECT l.*, u.name as user_name, u.unit_number FROM audit_logs l LEFT JOIN users u ON u.id=l.user_id';
        if ($usePaging) {
            $like = '%'.$q.'%';
            $page = max(1, (int)($_GET['page'] ?? 1));
            $pageSize = min(200, max(10, (int)($_GET['page_size'] ?? 50)));
            $countParams = [];
            if ($q !== '') { for ($i=0; $i<4; $i++) $countParams[] = $like; }
            if ($actionFilter !== '') $countParams[] = $actionFilter;
            if ($dateFrom !== '') $countParams[] = normDate($dateFrom).' 00:00:00';
            if ($dateTo !== '') $countParams[] = normDate($dateTo).' 23:59:59';
            $countRow = fetchOne('SELECT COUNT(*) as cnt FROM audit_logs l'.($q !== '' ? ' LEFT JOIN users u ON u.id=l.user_id' : '').$where, $types, ...$countParams);
            $total = (int)($countRow['cnt'] ?? 0);
            $offset = ($page - 1) * $pageSize;
            $types .= 'ii';
            $dataParams = $countParams; $dataParams[] = $pageSize; $dataParams[] = $offset;
            $logs = fetchAll($baseSelect.$where.' ORDER BY l.created_at DESC, l.id DESC LIMIT ? OFFSET ?', $types, ...$dataParams);
            jsonSuccess(['logs' => $logs, 'total' => $total, 'page' => $page, 'page_size' => $pageSize, 'actions' => array_map(fn($r) => $r['action'], fetchAll('SELECT DISTINCT action FROM audit_logs ORDER BY action'))]);
        }
        $logs = fetchAll($baseSelect.$where.' ORDER BY l.created_at DESC, l.id DESC LIMIT 100');
        jsonSuccess(['logs' => $logs, 'total' => count($logs), 'actions' => array_map(fn($r) => $r['action'], fetchAll('SELECT DISTINCT action FROM audit_logs ORDER BY action'))]);
    }

    // --- Admin WhatsApp Billing ---
    if ($adminPath === '/wa-settings' && $adminMethod === 'GET') {
        ensureWaSettingsTable();
        $s = fetchOne('SELECT provider, api_token, from_number, template, mode, attach_pdf, pdf_url FROM wa_settings WHERE id = 1');
        if ($s && $s['api_token'] !== '') {
            $s['api_token'] = substr($s['api_token'], 0, 4) . '****' . substr($s['api_token'], -4);
            $s['token_masked'] = true;
        }
        jsonSuccess($s);
    }

    if ($adminPath === '/wa-settings' && $adminMethod === 'PUT') {
        ensureWaSettingsTable();
        $fields = []; $types = ''; $vals = [];
        foreach (['provider'=>'s','from_number'=>'s','template'=>'s','mode'=>'s','attach_pdf'=>'i','pdf_url'=>'s'] as $f => $t) {
            if (isset($input[$f])) {
                $fields[] = "$f=?"; $types .= $t;
                $vals[] = $t === 'i' ? (int)$input[$f] : trim((string)$input[$f]);
            }
        }
        if (isset($input['api_token']) && trim($input['api_token']) !== '') {
            $fields[] = "api_token=?"; $types .= 's'; $vals[] = trim($input['api_token']);
        }
        if (empty($fields)) jsonError('No settings to update');
        querySingle('UPDATE wa_settings SET '.implode(',',$fields).' WHERE id=1', $types, ...$vals);
        auditLog($adminUser['id'], 'update_wa_settings', 'Updated WhatsApp billing settings');
        jsonSuccess(['message' => 'WhatsApp settings saved']);
    }

    if ($adminPath === '/wa-billing/send' && $adminMethod === 'POST') {
        ensureWaSettingsTable();
        $settings = fetchOne('SELECT * FROM wa_settings WHERE id = 1');
        $mode = trim($input['mode'] ?? '') ?: ($settings['mode'] ?? 'link');
        $billingId = (int)($input['billing_id'] ?? 0);
        $bill = fetchOne('SELECT * FROM billing WHERE id = ?', 'i', $billingId);
        if (!$bill) jsonError('Billing not found', 404);
        $user = fetchOne('SELECT id, name, phone, unit_number, extra_phones FROM users WHERE id = ?', 'i', $bill['user_id']);
        if (!$user) jsonError('Tenant not found', 404);

        $phone = trim($input['phone'] ?? '');
        if ($phone === '') $phone = $user['phone'] ?? '';
        $phone = normalizeWaPhone($phone);
        if ($phone === '') jsonError('Tenant has no phone number');

        $message = trim($input['message'] ?? '');
        if ($message === '') jsonError('Message is empty');

        if ($mode === 'link') {
            $waLink = 'https://wa.me/' . $phone . '?text=' . rawurlencode($message);
            auditLog($adminUser['id'], 'wa_send_billing_link', "Generated WA link for billing #$billingId ($bill[invoice_no]) to " . $user['unit_number']);
            querySingle("UPDATE billing SET wa_status='sent', wa_sent_at=NOW(), wa_message_id=NULL WHERE id=?", 'i', $billingId);
            jsonSuccess(['success' => true, 'mode' => 'link', 'wa_link' => $waLink, 'wa_status' => 'sent']);
        }

        $provider = trim($input['provider'] ?? '') ?: ($settings['provider'] ?? 'fonnte');
        $apiToken = trim($input['api_token'] ?? '');
        if ($apiToken === '' && strpos(($input['api_token'] ?? ''), '****') !== false) $apiToken = '';
        if ($apiToken === '') $apiToken = $settings['api_token'] ?? '';
        if ($apiToken === '') jsonError('No WhatsApp API token configured. Save it in the Settings panel first.');
        $from = trim($input['from'] ?? '') ?: ($settings['from_number'] ?? '');
        $scheduledAt = trim($input['scheduled_at'] ?? '');
        $pdfUrl = trim($input['pdf_url'] ?? '');
        if ($pdfUrl === '') $pdfUrl = trim($settings['pdf_url'] ?? '');
        $attachPdf = (!empty($input['attach_pdf']) || !empty($settings['attach_pdf'])) && $pdfUrl !== '';

        $res = sendWaApi($provider, $apiToken, $from, $phone, $message, $scheduledAt, $attachPdf ? $pdfUrl : '');
        $details = "Sent billing #$billingId ($bill[invoice_no]) via $provider to " . $user['unit_number'];
        $mid = $res['message_id'] ?? null;
        if (is_array($mid)) $mid = $mid[0] ?? null;
        if (!empty($mid)) $details .= " (msg $mid)";
        auditLog($adminUser['id'], 'wa_send_billing_api', $details);
        if (!$res['success']) {
            $errMsg = $res['error'] ?: 'Failed to send WhatsApp message';
            if (is_array($errMsg)) $errMsg = implode(', ', $errMsg);
            jsonError($errMsg, 502);
        }
        querySingle("UPDATE billing SET wa_status='sent', wa_sent_at=NOW(), wa_message_id=? WHERE id=?", 'si', $mid, $billingId);
        jsonSuccess(['success' => true, 'mode' => 'api', 'provider' => $provider, 'message_id' => $mid, 'wa_status' => 'sent']);
    }

    // --- Admin AST Payment Trx (external SQL Server) ---
    if ($adminPath === '/ast-settings' && $adminMethod === 'GET') {
        $s = astGetSettings();
        if ($s['password'] !== '') {
            $s['password'] = substr($s['password'], 0, 2) . '****' . substr($s['password'], -2);
            $s['password_masked'] = true;
        }
        jsonSuccess($s);
    }

    if ($adminPath === '/ast-settings' && $adminMethod === 'PUT') {
        $fields = []; $types = ''; $vals = [];
        foreach (['host'=>'s','port'=>'i','database_name'=>'s','username'=>'s'] as $f => $t) {
            if (isset($input[$f])) {
                $fields[] = "$f=?"; $types .= $t;
                $vals[] = $t === 'i' ? (int)$input[$f] : trim((string)$input[$f]);
            }
        }
        $pw = isset($input['password']) ? trim((string)$input['password']) : '';
        if ($pw !== '' && strpos($pw, '****') === false) {
            $fields[] = 'password=?'; $types .= 's'; $vals[] = $pw;
        }
        if (empty($fields)) jsonError('No settings to update');
        querySingle('UPDATE ast_settings SET '.implode(',',$fields).' WHERE id=1', $types, ...$vals);
        auditLog($adminUser['id'], 'update_ast_settings', 'Updated AST payment trx SQL Server connection settings');
        jsonSuccess(['message' => 'AST connection saved']);
    }

    if ($adminPath === '/ast-paytrx' && $adminMethod === 'GET') {
        $settings = astGetSettings();
        $conn = astConnect($settings);
        if (isset($conn['error'])) jsonError($conn['error'], 502);
        $pdo = $conn['conn'];

        $meta = astTableColumns($pdo);
        if (isset($meta['error'])) jsonError($meta['error'], 502);
        $cols = $meta['cols'];
        $dateCol = $meta['date_col'];

        $month = trim(strval($input['month'] ?? $_GET['month'] ?? ''));
        $q = trim(strval($input['q'] ?? $_GET['q'] ?? ''));
        $months = [];
        if ($dateCol) {
            try {
                $mStmt = $pdo->query("SELECT DISTINCT CONVERT(varchar(7), [$dateCol], 120) AS m FROM dbo.ar_paytrx WHERE [$dateCol] IS NOT NULL ORDER BY m DESC");
                $months = array_column($mStmt->fetchAll(PDO::FETCH_ASSOC), 'm');
            } catch (Throwable $e) { /* months optional */ }
        }

        $colList = implode(',', array_map(fn($c) => '[' . $c['name'] . ']', $cols));
        $conds = []; $params = [];
        if ($dateCol && $month !== '') {
            $conds[] = 'CONVERT(varchar(7), [' . $dateCol . '], 120) = ?';
            $params[] = $month;
        }
        if ($q !== '') {
            $conds[] = "(LTRIM(RTRIM([debtor_acct])) LIKE ? OR LTRIM(RTRIM([doc_no])) LIKE ? OR LTRIM(RTRIM([ref_no])) LIKE ?)";
            $params[] = '%' . $q . '%';
            $params[] = '%' . $q . '%';
            $params[] = '%' . $q . '%';
        }
        $where = $conds ? ' WHERE ' . implode(' AND ', $conds) : '';
        $colNames = array_column($cols, 'name');
        if (in_array('audit_date', $colNames, true) && in_array('doc_no', $colNames, true)) {
            $colNames = array_values(array_filter($colNames, fn($c) => $c !== 'audit_date'));
            array_splice($colNames, array_search('doc_no', $colNames, true), 0, ['audit_date']);
        }
        $sortCol = in_array('audit_date', $colNames, true) ? 'audit_date' : $dateCol;
        $orderBy = $sortCol ? ' ORDER BY [' . $sortCol . '] DESC' . ($dateCol && $sortCol !== $dateCol ? ', [' . $dateCol . '] DESC' : '') : '';
        $total = 0;
        try {
            $cStmt = $pdo->prepare('SELECT COUNT(*) FROM dbo.ar_paytrx' . $where);
            $cStmt->execute($params);
            $total = (int)$cStmt->fetchColumn();
        } catch (Throwable $e) { /* count optional */ }
        try {
            $stmt = $pdo->prepare('SELECT TOP 2000 ' . $colList . ' FROM dbo.ar_paytrx' . $where . $orderBy);
            $stmt->execute($params);
            $rowSet = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            jsonError('SQL Server query failed: ' . $e->getMessage(), 502);
        }
        $rows = [];
        foreach ($rowSet as $row) {
            $out = [];
            foreach ($row as $k => $v) { $out[$k] = astTypedValue($v); }
            $rows[] = $out;
        }
        $portalPeriods = [];
        try {
            $pp = $db->query("SELECT DISTINCT billing_period FROM billing WHERE billing_period IS NOT NULL AND billing_period != '' ORDER BY billing_period DESC");
            if ($pp) { while ($row = $pp->fetch_assoc()) $portalPeriods[] = $row['billing_period']; }
        } catch (Throwable $e) { /* periods optional */ }
        jsonSuccess(['host' => $settings['host'], 'port' => (int)$settings['port'], 'database' => $settings['database_name'], 'columns' => array_column($cols, 'name'), 'date_col' => $dateCol, 'sort_col' => $sortCol, 'months' => $months, 'month' => $month, 'q' => $q, 'rows' => $rows, 'count' => count($rows), 'total' => $total, 'periods' => $portalPeriods]);
    }

    // --- Admin Billing Report (period summary + breakdown) ---
    if ($adminPath === '/report-billing' && $adminMethod === 'GET') {
        $rptPeriod = trim($input['period'] ?? $_GET['period'] ?? '');
        $rptFormat = strtolower(trim($input['format'] ?? $_GET['format'] ?? ''));
        $rptStatus = strtolower(trim($input['status'] ?? $_GET['status'] ?? ''));
        $rptUnit = trim($input['unit'] ?? $_GET['unit'] ?? '');
        $where = ''; $types = ''; $params = [];
        if ($rptPeriod !== '') { $where = ' WHERE billing_period = ?'; $types = 's'; $params = [$rptPeriod]; }
        $statusConds = [
            'paid' => '(COALESCE(amount,0) > 0 AND paid IS NOT NULL AND COALESCE(paid,0) >= COALESCE(amount,0))',
            'unpaid' => '(COALESCE(amount,0) > 0 AND (paid IS NULL OR COALESCE(paid,0) = 0))',
            'kurang' => '(COALESCE(paid,0) > 0 AND COALESCE(paid,0) < COALESCE(amount,0))',
            'lebih' => '(COALESCE(paid,0) > COALESCE(amount,0))',
        ];
        if ($rptStatus !== '' && isset($statusConds[$rptStatus])) {
            $where .= ($where === '' ? ' WHERE ' : ' AND ') . $statusConds[$rptStatus];
        }
        $unitWhere = ' WHERE 1=1'; $unitTypes = ''; $unitParams = [];
        if ($rptPeriod !== '') { $unitWhere .= ' AND b.billing_period = ?'; $unitTypes .= 's'; $unitParams[] = $rptPeriod; }
        if ($rptStatus !== '' && isset($statusConds[$rptStatus])) { $unitWhere .= ' AND ' . $statusConds[$rptStatus]; }
        if ($rptUnit !== '') { $unitWhere .= ' AND (b.unit_number LIKE ? OR u.name LIKE ?)'; $unitTypes .= 'ss'; $uLike = '%' . $rptUnit . '%'; $unitParams[] = $uLike; $unitParams[] = $uLike; }
        $unitRows = fetchAll(
            "SELECT b.unit_number, u.name AS user_name, b.billing_period AS period, b.invoice_no,
                b.amount,
                LEAST(COALESCE(paid,0), COALESCE(amount,0)) AS paid,
                ROUND(GREATEST(COALESCE(amount,0) - COALESCE(paid,0),0), 2) AS outstanding,
                CASE
                    WHEN COALESCE(paid,0) > COALESCE(amount,0) THEN 'Lebih Bayar'
                    WHEN COALESCE(paid,0) >= COALESCE(amount,0) AND COALESCE(amount,0) > 0 THEN 'Paid'
                    WHEN COALESCE(paid,0) > 0 AND COALESCE(paid,0) < COALESCE(amount,0) THEN 'Kurang Bayar'
                    ELSE 'Unpaid'
                END AS status,
                b.other_rev, b.electricity, b.water, b.gas, b.sinking_fund, b.fine, b.rent, b.service_charge
                FROM billing b JOIN users u ON u.id = b.user_id" . $unitWhere . "
                ORDER BY b.unit_number ASC, COALESCE(b.issued_date, b.due_date) DESC LIMIT 20000",
            $unitTypes, ...$unitParams);
        $rows = fetchAll(
            "SELECT billing_period AS period,
                COUNT(*) AS invoices,
                COALESCE(SUM(COALESCE(amount,0)),0) AS amount,
                COALESCE(SUM(LEAST(COALESCE(paid,0), COALESCE(amount,0))),0) AS paid,
                COALESCE(SUM(GREATEST(COALESCE(amount,0) - COALESCE(paid,0),0)),0) AS outstanding,
                (COUNT(*) - COALESCE(SUM(CASE WHEN COALESCE(status,'') = 'paid' THEN 1 ELSE 0 END),0)) AS outstanding_invoices,
                COALESCE(SUM(COALESCE(other_rev,0)),0) AS other_rev,
                COALESCE(SUM(COALESCE(electricity,0)),0) AS electricity,
                COALESCE(SUM(COALESCE(water,0)),0) AS water,
                COALESCE(SUM(COALESCE(gas,0)),0) AS gas,
                COALESCE(SUM(COALESCE(sinking_fund,0)),0) AS sinking_fund,
                COALESCE(SUM(COALESCE(fine,0)),0) AS fine,
                COALESCE(SUM(COALESCE(rent,0)),0) AS rent,
                COALESCE(SUM(COALESCE(service_charge,0)),0) AS service_charge
                FROM billing" . $where . " GROUP BY billing_period ORDER BY MAX(COALESCE(issued_date, due_date)) DESC",
            $types, ...$params);
        $periods = array_map(function($r){ return $r['billing_period']; },
            fetchAll("SELECT billing_period FROM billing WHERE billing_period IS NOT NULL AND billing_period != '' GROUP BY billing_period ORDER BY MAX(COALESCE(issued_date, due_date)) DESC"));

        if ($rptFormat === 'xlsx') {
            require_once __DIR__ . '/../vendor/autoload.php';
            $ss = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $ss->getActiveSheet();
            $sheet->setTitle('Billing Report');
            $cols = ['Period','Invoices','Amount','Paid','Outstanding','Outstanding Invoices','Rent','Service Charge','Electricity','Water','Gas','Sinking Fund','Other Rev','Fine'];
            foreach ($cols as $c => $h) {
                $sheet->setCellValueExplicit(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c + 1) . '1', $h, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            }
            $sheet->getStyle('A1:N1')->getFont()->setBold(true);
            $r = 2;
            foreach ($rows as $row) {
                $sheet->setCellValue('A' . $r, $row['period']);
                $sheet->setCellValue('B' . $r, (int)$row['invoices']);
                $sheet->setCellValue('C' . $r, round((float)$row['amount'], 2));
                $sheet->setCellValue('D' . $r, round((float)$row['paid'], 2));
                $sheet->setCellValue('E' . $r, round((float)$row['outstanding'], 2));
                $sheet->setCellValue('F' . $r, (int)$row['outstanding_invoices']);
                $sheet->setCellValue('G' . $r, round((float)$row['rent'], 2));
                $sheet->setCellValue('H' . $r, round((float)$row['service_charge'], 2));
                $sheet->setCellValue('I' . $r, round((float)$row['electricity'], 2));
                $sheet->setCellValue('J' . $r, round((float)$row['water'], 2));
                $sheet->setCellValue('K' . $r, round((float)$row['gas'], 2));
                $sheet->setCellValue('L' . $r, round((float)$row['sinking_fund'], 2));
                $sheet->setCellValue('M' . $r, round((float)$row['other_rev'], 2));
                $sheet->setCellValue('N' . $r, round((float)$row['fine'], 2));
                $r++;
            }
            if (count($rows) > 1) {
                foreach (['B','C','D','E','F','G','H','I','J','K','L','M','N'] as $colId) {
                    $sheet->setCellValue($colId . $r, '=SUM(' . $colId . '2:' . $colId . ($r - 1) . ')');
                }
                $sheet->setCellValue('A' . $r, 'TOTAL');
                $sheet->getStyle('A' . $r . ':N' . $r)->getFont()->setBold(true);
            }
            foreach (range('A', 'N') as $colId) {
                $sheet->getColumnDimension($colId)->setAutoSize(true);
            }
            $uSheet = $ss->createSheet(null);
            $uSheet->setTitle('Units');
            $uCols = ['Unit','Tenant','Period','Invoice','Amount','Paid','Outstanding','Status','Rent','Service Charge','Electricity','Water','Gas','Sinking Fund','Other Rev','Fine'];
            foreach ($uCols as $c => $h) {
                $uSheet->setCellValueExplicit(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c + 1) . '1', $h, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            }
            $uSheet->getStyle('A1:P1')->getFont()->setBold(true);
            $r = 2;
            foreach ($unitRows as $row) {
                $uSheet->setCellValue('A' . $r, $row['unit_number']);
                $uSheet->setCellValue('B' . $r, $row['user_name']);
                $uSheet->setCellValue('C' . $r, $row['period']);
                $uSheet->setCellValue('D' . $r, $row['invoice_no']);
                $uSheet->setCellValue('E' . $r, round((float)$row['amount'], 2));
                $uSheet->setCellValue('F' . $r, round((float)$row['paid'], 2));
                $uSheet->setCellValue('G' . $r, round((float)$row['outstanding'], 2));
                $uSheet->setCellValue('H' . $r, $row['status']);
                $uSheet->setCellValue('I' . $r, round((float)$row['rent'], 2));
                $uSheet->setCellValue('J' . $r, round((float)$row['service_charge'], 2));
                $uSheet->setCellValue('K' . $r, round((float)$row['electricity'], 2));
                $uSheet->setCellValue('L' . $r, round((float)$row['water'], 2));
                $uSheet->setCellValue('M' . $r, round((float)$row['gas'], 2));
                $uSheet->setCellValue('N' . $r, round((float)$row['sinking_fund'], 2));
                $uSheet->setCellValue('O' . $r, round((float)$row['other_rev'], 2));
                $uSheet->setCellValue('P' . $r, round((float)$row['fine'], 2));
                $r++;
            }
            foreach (range('A', 'P') as $colId) {
                $uSheet->getColumnDimension($colId)->setAutoSize(true);
            }
            $filename = 'billing_report_' . ($rptPeriod !== '' ? str_replace([' ', '/', '\\'], '_', $rptPeriod) : 'all_periods') . ($rptStatus !== '' ? '_' . $rptStatus : '') . '.xlsx';
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($ss);
            $writer->save('php://output');
            exit;
        }
        jsonSuccess(['rows' => $rows, 'unit_rows' => $unitRows, 'periods' => $periods, 'period' => $rptPeriod, 'status' => $rptStatus, 'unit' => $rptUnit]);
    }

    jsonError('Admin endpoint not found', 404);
}

http_response_code(404);
echo json_encode(['error'=>'Endpoint not found: '.$method.' '.$path]);