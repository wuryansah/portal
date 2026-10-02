<?php
session_set_cookie_params([
    'lifetime' => 86400,
    'path' => '/',
    'httponly' => true,
    'secure' => !empty($_SERVER['HTTPS']),
    'samesite' => 'Lax',
]);
ini_set('session.use_strict_mode', '1');
ini_set('session.gc_maxlifetime', 86400);
session_start();

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');

require_once __DIR__ . '/env.php';
$appName = getenv('APP_NAME') ?: 'portal.manggaduasquare.co.id';

$requestUri = $_SERVER['REQUEST_URI'];
$path = parse_url($requestUri, PHP_URL_PATH);

if (strpos($path, '/api') === 0) {
    require __DIR__ . '/api/index.php';
    return;
}

$scriptDir = dirname($_SERVER['SCRIPT_NAME']);
$basePath = $scriptDir === '/' || $scriptDir === '\\' ? '' : $scriptDir;
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($appName) ?></title>
    <base href="<?= $basePath ?>/">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css?v=<?= @filemtime('assets/css/style.css') ?>">
</head>
<body>
    <div id="app">
        <!-- Loading Screen -->
        <div class="loading-screen" id="loadingScreen">
            <div class="spinner"></div>
            <p>Loading <?= htmlspecialchars($appName) ?>...</p>
        </div>

        <!-- Public Header (shown before login) -->
        <div class="public-header" id="publicHeader" style="display:none">
            <div class="public-header-inner">
                <div class="public-header-logo">
                    <img src = "assets/img/m2s.png" width=160px alt = "Mangga Dua Square"></img>
                    <!-- <i class="fas fa-store"></i> -->
                    <!-- <span> Customer Portal</span> -->
                </div>
                <div>
                    <button class="btn-primary" id="publicLoginBtn" onclick="showLoginPage()">
                        <i class="fas fa-sign-in-alt"></i> Login
                    </button>
                </div>
            </div>
        </div>

        <!-- Public Content (shown before login) -->
        <div class="public-content" id="publicContent" style="display:none"></div>

        <!-- Public Footer -->
        <footer class="main-footer" id="publicFooter" style="display:none">
            Created by Wuryansah &copy; 2026
        </footer>

        <!-- Login Page -->
        <div class="login-page" id="loginPage" style="display:none">
            <div class="login-container">
                <div class="login-left">
                    <div class="login-branding">
                        <div class="login-logo">
                            <i class="fas fa-store"></i>
                        </div>
                        <h1><?= htmlspecialchars($appName) ?></h1>
                        <p>Access your account, billing, and stay updated with the latest mall news.</p>
                    </div>
                    <div class="login-illustration">
                        <i class="fas fa-shopping-bag"></i>
                        <i class="fas fa-receipt"></i>
                        <i class="fas fa-bullhorn"></i>
                    </div>
                </div>
                <div class="login-right">
                    <div class="login-form-wrapper">
                        <h2>Welcome Back</h2>
                        <p class="login-subtitle">Sign in to your account</p>
                        <form id="loginForm" onsubmit="return handleLogin(event)">
                            <div class="form-group">
                                <label><i class="fas fa-building"></i> Unit Number</label>
                                <input type="text" id="loginUnit" placeholder="Enter your unit number" required>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-lock"></i> Password</label>
                                <input type="password" id="loginPassword" placeholder="Enter your password" required>
                            </div>
                            <div class="form-options">
                                <label class="checkbox-label">
                                    <input type="checkbox" checked> Remember me
                                </label>
                                <a href="#" class="forgot-link" onclick="event.preventDefault();showForgotPasswordModal();">Forgot Password?</a>
                            </div>
                            <button type="submit" class="btn-primary btn-block" id="loginBtn">
                                <span>Sign In</span>
                                <i class="fas fa-arrow-right"></i>
                            </button>
                        </form>
                        </div>
                        <p class="login-error" id="loginError"></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main App -->
        <div class="app-layout" id="appLayout" style="display:none">
            <!-- Sidebar -->
            <aside class="sidebar" id="sidebar">
                <div class="sidebar-header">
                    <div class="sidebar-logo">
                        <i class="fas fa-store"></i>
                        <span><?= htmlspecialchars($appName) ?></span>
                    </div>
                    <button class="sidebar-toggle" onclick="toggleSidebar()">
                        <i class="fas fa-bars"></i>
                    </button>
                </div>
                <div class="sidebar-user">
                    <div class="user-avatar" id="userAvatar">JD</div>
                    <div class="user-info">
                        <span class="user-name" id="sidebarUserName">User</span>
                        <span class="user-role" id="sidebarUserRole">Customer</span>
                    </div>
                </div>
                <nav class="sidebar-nav" id="sidebarNav">
                    <a href="#" class="nav-item active" data-page="dashboard" onclick="navigate('dashboard')">
                        <i class="fas fa-th-large"></i><span>Dashboard</span>
                    </a>
                    <a href="#" class="nav-item" data-page="billing" onclick="navigate('billing')">
                        <i class="fas fa-file-invoice-dollar"></i><span>Billing</span>
                    </a>
                    <a href="#" class="nav-item" data-page="payments" onclick="navigate('payments')">
                        <i class="fas fa-credit-card"></i><span>Payments</span>
                    </a>
                    <a href="#" class="nav-item" data-page="news" onclick="navigate('news')">
                        <i class="fas fa-newspaper"></i><span>News & Updates</span>
                    </a>
                    <a href="#" class="nav-item" data-page="documents" onclick="navigate('documents')">
                        <i class="fas fa-folder-open"></i><span>Documents</span>
                    </a>
                    <a href="#" class="nav-item" data-page="support" onclick="navigate('support')">
                        <i class="fas fa-headset"></i><span>Support Center</span><span class="nav-badge" id="supportBadge" style="display:none"></span>
                    </a>
                    <a href="#" class="nav-item" data-page="profile" onclick="navigate('profile')">
                        <i class="fas fa-user-cog"></i><span>Profile</span>
                    </a>
                    <div class="admin-section" id="adminNav" style="display:none">
                        <div class="nav-section-label">Administration</div>
                        <a href="#" class="nav-item" data-page="admin-dashboard" onclick="navigate('admin-dashboard')">
                            <i class="fas fa-chart-pie"></i><span>Admin Dashboard</span>
                        </a>
                        <a href="#" class="nav-item" data-page="admin-billing" onclick="navigate('admin-billing')">
                            <i class="fas fa-file-invoice"></i><span>Manage Billing</span>
                        </a>
                        <a href="#" class="nav-item" data-page="admin-news" onclick="navigate('admin-news')">
                            <i class="fas fa-edit"></i><span>Manage News</span>
                        </a>
                        <a href="#" class="nav-item" data-page="admin-documents" onclick="navigate('admin-documents')">
                            <i class="fas fa-file-upload"></i><span>Manage Documents</span>
                        </a>
                        <a href="#" class="nav-item" data-page="admin-tickets" onclick="navigate('admin-tickets')">
                            <i class="fas fa-tasks"></i><span>Support Tickets</span><span class="nav-badge" id="adminTicketsBadge" style="display:none"></span>
                        </a>
                        <a href="#" class="nav-item" data-page="admin-users" onclick="navigate('admin-users')">
                            <i class="fas fa-users"></i><span>Users</span>
                        </a>
                        <a href="#" class="nav-item" data-page="admin-report-billing" onclick="navigate('admin-report-billing')">
                            <i class="fas fa-chart-bar"></i><span>Billing Report</span>
                        </a>
                        <div class="nav-accordion" id="astAccordion">
                            <button type="button" class="nav-item nav-accordion-toggle" onclick="toggleSidebarAccordion('astAccordion')">
                                <i class="fas fa-database"></i><span>AST App</span>
                                <i class="fas fa-chevron-down nav-accordion-chevron"></i>
                            </button>
                            <div class="nav-accordion-body">
                                <a href="#" class="nav-item" data-page="admin-wa-billing" onclick="navigate('admin-wa-billing')">
                                    <i class="fab fa-whatsapp"></i><span>Send Billing WA</span>
                                </a>
                                <a href="#" class="nav-item" data-page="admin-payments" onclick="navigate('admin-payments')">
                                    <i class="fas fa-credit-card"></i><span>Manage Payments</span>
                                </a>
                                <a href="#" class="nav-item" data-page="admin-dup-payments" onclick="navigate('admin-dup-payments')">
                                    <i class="fas fa-copy"></i><span>Duplicate Payments</span>
                                </a>
                                <a href="#" class="nav-item" data-page="admin-ast-paytrx" onclick="navigate('admin-ast-paytrx')">
                                    <i class="fas fa-database"></i><span>AST Payment Trx</span>
                                </a>
                                <a href="#" class="nav-item" data-page="admin-logs" onclick="navigate('admin-logs')">
                                    <i class="fas fa-clipboard-list"></i><span>Audit Logs</span>
                                </a>
                            </div>
                        </div>
                    </div>
                </nav>
                <div class="sidebar-footer">
                    <div class="theme-toggle">
                        <i class="fas fa-moon"></i>
                        <label class="toggle-switch">
                            <input type="checkbox" id="themeToggle" onchange="toggleTheme()">
                            <span class="toggle-slider"></span>
                        </label>
                        <i class="fas fa-sun"></i>
                    </div>
                    <a href="#" class="nav-item logout-btn" onclick="handleLogout()">
                        <i class="fas fa-sign-out-alt"></i><span>Logout</span>
                    </a>
                </div>
            </aside>

            <!-- Overlay for mobile -->
            <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

            <!-- Main Content -->
            <main class="main-content">
                <header class="top-header">
                    <div class="header-left">
                        <button class="mobile-menu-btn" onclick="toggleSidebar()">
                            <i class="fas fa-bars"></i>
                        </button>
                        <div class="page-title">
                            <h2 id="pageTitle">Dashboard</h2>
                            <p id="pageSubtitle">Welcome back, User</p>
                        </div>
                    </div>
                    <div class="header-right">
                        <div class="header-notifications" onclick="navigate('dashboard')">
                            <i class="fas fa-bell"></i>
                            <span class="notification-dot"></span>
                        </div>
                        <div class="header-profile" onclick="navigate('profile')">
                            <span class="header-avatar" id="headerAvatar">JD</span>
                        </div>
                    </div>
                </header>

                <div class="content-area" id="contentArea">
                    <!-- Dynamic content loads here -->
                </div>

                <footer class="main-footer">
                    Created by Wuryansah &copy; 2026
                </footer>
            </main>
        </div>
    </div>

    <script src="assets/vendor/chart.umd.min.js"></script>
    <script src="assets/js/app.js?v=<?= @filemtime('assets/js/app.js') ?>"></script>
</body>
</html>
