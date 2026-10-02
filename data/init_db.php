<?php
$mysqli = new mysqli('127.0.0.1', 'root', '', 'mall_portal');
if ($mysqli->connect_error) {
    die("Connection failed: " . $mysqli->connect_error . "\n");
}

$mysqli->query("SET FOREIGN_KEY_CHECKS = 0");

$mysqli->query("
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role VARCHAR(50) NOT NULL DEFAULT 'customer',
    company VARCHAR(255),
    phone VARCHAR(50),
    avatar VARCHAR(255),
    notification_email TINYINT(1) DEFAULT 1,
    notification_sms TINYINT(1) DEFAULT 0,
    unit_number VARCHAR(50),
    extra_phones VARCHAR(255) DEFAULT '[]',
    extra_emails VARCHAR(255) DEFAULT '[]',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$mysqli->query("
CREATE TABLE IF NOT EXISTS billing (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    invoice_no VARCHAR(255) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    paid DECIMAL(12,2) DEFAULT 0,
    status VARCHAR(50) NOT NULL DEFAULT 'pending',
    due_date DATE NOT NULL,
    issued_date DATE NOT NULL,
    description TEXT,
    category VARCHAR(255),
    billing_period VARCHAR(100),
    virtual_account VARCHAR(100),
    unit_number VARCHAR(50),
    wa_status VARCHAR(20) DEFAULT NULL,
    wa_sent_at DATETIME DEFAULT NULL,
    wa_message_id VARCHAR(255) DEFAULT NULL,
    other_rev DECIMAL(12,2) DEFAULT 0,
    electricity DECIMAL(12,2) DEFAULT 0,
    water DECIMAL(12,2) DEFAULT 0,
    gas DECIMAL(12,2) DEFAULT 0,
    sinking_fund DECIMAL(12,2) DEFAULT 0,
    fine DECIMAL(12,2) DEFAULT 0,
    rent DECIMAL(12,2) DEFAULT 0,
    service_charge DECIMAL(12,2) DEFAULT 0,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$waCols = $mysqli->query("SHOW COLUMNS FROM billing LIKE 'wa_status'");
if ($waCols && $waCols->num_rows === 0) {
    $mysqli->query("ALTER TABLE billing ADD COLUMN wa_status VARCHAR(20) DEFAULT NULL, ADD COLUMN wa_sent_at DATETIME DEFAULT NULL, ADD COLUMN wa_message_id VARCHAR(255) DEFAULT NULL");
}

$periodIdx = $mysqli->query("SHOW INDEX FROM billing WHERE Key_name = 'idx_billing_period'");
if ($periodIdx && $periodIdx->num_rows === 0) {
    $mysqli->query("ALTER TABLE billing ADD INDEX idx_billing_period (billing_period)");
}

$mysqli->query("
CREATE TABLE IF NOT EXISTS payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    billing_id INT,
    user_id INT NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    method VARCHAR(100),
    reference VARCHAR(255),
    paid_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (billing_id) REFERENCES billing(id) ON DELETE SET NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$mysqli->query("
CREATE TABLE IF NOT EXISTS news (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    content TEXT NOT NULL,
    category VARCHAR(100) NOT NULL DEFAULT 'announcement',
    priority VARCHAR(50) DEFAULT 'normal',
    image VARCHAR(500),
    featured TINYINT(1) DEFAULT 0,
    published_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$mysqli->query("ALTER TABLE news ADD COLUMN embed_url TEXT DEFAULT '' AFTER image");

$mysqli->query("
CREATE TABLE IF NOT EXISTS documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    title VARCHAR(255) NOT NULL,
    type VARCHAR(100) NOT NULL,
    category VARCHAR(100),
    file_url VARCHAR(500),
    file_size VARCHAR(50),
    description TEXT,
    is_public TINYINT(1) DEFAULT 1,
    uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$mysqli->query("
CREATE TABLE IF NOT EXISTS support_tickets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    subject VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    category VARCHAR(100) DEFAULT 'inquiry',
    status VARCHAR(50) DEFAULT 'open',
    priority VARCHAR(50) DEFAULT 'normal',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$mysqli->query("
CREATE TABLE IF NOT EXISTS audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    action VARCHAR(255) NOT NULL,
    details TEXT,
    ip_address VARCHAR(50),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$mysqli->query("
CREATE TABLE IF NOT EXISTS wa_settings (
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
$mysqli->query("INSERT IGNORE INTO wa_settings (id) VALUES (1)");

$pw = password_hash('password123', PASSWORD_BCRYPT);

$mysqli->query("DELETE FROM audit_logs");
$mysqli->query("DELETE FROM support_tickets");
$mysqli->query("DELETE FROM documents");
$mysqli->query("DELETE FROM payments");
$mysqli->query("DELETE FROM billing");
$mysqli->query("DELETE FROM news");
$mysqli->query("DELETE FROM users");

$mysqli->query("SET FOREIGN_KEY_CHECKS = 1");

$mysqli->query("INSERT INTO users (name, email, password, role, company, phone, unit_number) VALUES
('Juan Dela Cruz', 'juan@example.com', '$pw', 'customer', 'Cruz Enterprises', '09171234567', '203'),
('Maria Santos', 'maria@example.com', '$pw', 'customer', 'Santos Trading', '09189876543', '105'),
('Admin User', 'admin@mall.com', '$pw', 'admin', 'Mall Management', '09170001111', 'MGT'),
('Finance User', 'finance@mall.com', '$pw', 'finance', 'Finance Dept', '09170002222', 'FIN')");

$mysqli->query("INSERT INTO billing (user_id, invoice_no, amount, paid, status, due_date, issued_date, description, category) VALUES
(1, 'INV-2026-001', 45000.00, 45000.00, 'paid', '2026-06-15', '2026-06-01', 'Monthly Rental - Unit 203', 'Rental'),
(1, 'INV-2026-002', 45000.00, 45000.00, 'paid', '2026-05-15', '2026-05-01', 'Monthly Rental - Unit 203', 'Rental'),
(1, 'INV-2026-003', 45000.00, 0, 'pending', '2026-07-15', '2026-07-01', 'Monthly Rental - Unit 203', 'Rental'),
(1, 'INV-2026-004', 3200.00, 0, 'pending', '2026-07-10', '2026-06-25', 'Common Area Maintenance (CAM) Fee', 'CAM Fee'),
(2, 'INV-2026-001', 28000.00, 28000.00, 'paid', '2026-06-15', '2026-06-01', 'Monthly Rental - Unit 105', 'Rental'),
(2, 'INV-2026-002', 28000.00, 0, 'pending', '2026-07-15', '2026-07-01', 'Monthly Rental - Unit 105', 'Rental'),
(2, 'INV-2026-003', 1500.00, 0, 'pending', '2026-07-10', '2026-06-25', 'Electricity Billing - Unit 105', 'Utilities')");

$mysqli->query("INSERT INTO payments (billing_id, user_id, amount, method, reference, paid_at) VALUES
(1, 1, 45000.00, 'Bank Transfer', 'BPI-REF-20260601', '2026-06-05 09:30:00'),
(2, 1, 45000.00, 'GCash', 'GC-20260503-789', '2026-05-03 14:20:00'),
(5, 2, 28000.00, 'Bank Transfer', 'MB-REF-20260602', '2026-06-02 11:00:00')");

$mysqli->query("INSERT INTO news (title, content, category, priority, image, featured) VALUES
('Grand Mall Anniversary Sale – Up to 70% Off!',
 'Celebrate our 10th anniversary with incredible deals across all stores. From fashion to electronics, enjoy massive discounts and exciting raffle prizes. Visit the mall from July 15-30 to participate in our biggest sale event of the year!',
 'promotion', 'high', 'https://placehold.co/800x400/2563eb/ffffff?text=Anniversary+Sale', 1),
('Scheduled Maintenance: Power Shutdown on July 20',
 'Please be advised that there will be a scheduled power maintenance on July 20, 2026 from 2:00 AM to 5:00 AM. All tenants are advised to shut down their equipment before the maintenance period. We apologize for any inconvenience.',
 'maintenance', 'high', 'https://placehold.co/800x400/dc2626/ffffff?text=Maintenance', 1),
('New Store Opening: Café de Luna – Grand Opening July 10',
 'We are excited to welcome Café de Luna to the 2nd floor of the mall! Enjoy buy-1-get-1 free drinks on their opening day. Come and experience the finest coffee blends in town.',
 'event', 'normal', 'https://placehold.co/800x400/059669/ffffff?text=New+Store', 1),
('Holiday Schedule: Mall Operating Hours for Independence Day',
 'In observance of Independence Day (June 12), the mall will be open from 10:00 AM to 8:00 PM. Regular operating hours resume on June 13. Plan your visit accordingly.',
 'announcement', 'normal', NULL, 0),
('Christmas Tree Lighting Ceremony – Save the Date!',
 'Join us on November 20 for our annual Christmas Tree Lighting Ceremony. Enjoy live performances, free hot chocolate, and giveaways. Bring your family and friends to kick off the holiday season!',
 'event', 'normal', NULL, 0),
('Fire Drill Notice: June 25, 2026',
 'A mandatory fire drill will be conducted on June 25 at 10:00 AM. All tenants and employees are required to participate. Proceed to the designated evacuation areas upon hearing the alarm.',
 'announcement', 'high', NULL, 0)");

$mysqli->query("INSERT INTO documents (user_id, title, type, category, file_url, file_size, description, is_public) VALUES
(NULL, 'Lease Agreement Template', 'PDF', 'lease', '/files/lease-template.pdf', '2.4 MB', 'Standard lease agreement form for tenants', 1),
(NULL, 'Mall Rules and Guidelines', 'PDF', 'guidelines', '/files/mall-guidelines.pdf', '1.8 MB', 'Updated mall rules and regulations for 2026', 1),
(NULL, 'Tenant Handbook 2026', 'PDF', 'guidelines', '/files/tenant-handbook.pdf', '5.2 MB', 'Complete guide for mall tenants', 1),
(NULL, 'Circular No. 2026-001: Revised CAM Fees', 'PDF', 'circular', '/files/circular-001.pdf', '856 KB', 'Advisory on revised Common Area Maintenance fees effective Q3 2026', 1),
(NULL, 'Event Permit Application Form', 'DOCX', 'form', '/files/event-permit.docx', '125 KB', 'Form for applying for in-mall event permits', 1),
(NULL, 'Maintenance Request Form', 'DOCX', 'form', '/files/maintenance-form.docx', '98 KB', 'Standard form for submitting maintenance requests', 1),
(1, 'Unit 203 - Lease Contract', 'PDF', 'lease', '/files/lease-203.pdf', '3.1 MB', 'Signed lease contract for Unit 203', 0),
(2, 'Unit 105 - Lease Contract', 'PDF', 'lease', '/files/lease-105.pdf', '2.9 MB', 'Signed lease contract for Unit 105', 0),
(NULL, 'Circular No. 2026-002: Holiday Schedule', 'PDF', 'circular', '/files/circular-002.pdf', '452 KB', 'Official holiday operating schedule for 2026', 1)");

$mysqli->query("INSERT INTO support_tickets (user_id, subject, message, category, status, priority) VALUES
(1, 'Request for additional parking slot', 'We would like to request an additional parking slot for our delivery vehicle. Our current allocation is insufficient for our operations.', 'inquiry', 'open', 'normal'),
(1, 'Air conditioning issue in Unit 203', 'The air conditioning unit in our store has been inconsistent for the past week. It sometimes stops cooling. Requesting inspection.', 'maintenance', 'in_progress', 'high'),
(2, 'Inquiry about renewal terms', 'We would like to know about the renewal terms for our lease which ends in December 2026. Can we schedule a meeting?', 'inquiry', 'open', 'normal')");

$mysqli->query("INSERT INTO audit_logs (user_id, action, details) VALUES
(1, 'login', 'User logged in from web portal'),
(1, 'view_billing', 'Viewed billing summary'),
(2, 'login', 'User logged in from web portal')");

$mysqli->close();

echo "Database initialized successfully.\n";
echo "Test accounts:\n";
echo "  Customer: juan@example.com / password123\n";
echo "  Customer: maria@example.com / password123\n";
echo "  Admin:    admin@mall.com / password123\n";
echo "  Finance:  finance@mall.com / password123\n";