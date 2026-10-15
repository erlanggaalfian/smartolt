<?php
// ==============================================================================
// SmartOLT Shared Layout Header
// ==============================================================================
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/api.php';

if (isset($_GET['toggle_debug'])) {
    $_SESSION['debug_mode'] = !empty($_SESSION['debug_mode']) ? false : true;
    $referer = $_SERVER['HTTP_REFERER'] ?? 'dashboard.php';
    // Remove query params or trailing toggle_debug to prevent loop
    $referer = preg_replace('/[?&]toggle_debug=[^&]*/', '', $referer);
    header('Location: ' . get_safe_redirect($referer, 'dashboard.php'));
    exit;
}

$current_page = basename($_SERVER['PHP_SELF']);

// 1. Cek jumlah user di database
try {
    $user_count = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
} catch (Exception $e) {
    $user_count = 0;
}

if ($user_count == 0) {
    // Belum ada user sama sekali, alihkan ke setup register pertama
    header('Location: register.php');
    exit;
}

// 2. Jika sudah ada user, pastikan user sudah login
if (!isset($_SESSION['smartolt_user_id'])) {
    header('Location: login.php');
    exit;
}

// 3. Batasi akses ke settings pages hanya untuk superadmin
if (in_array($current_page, ['settings-olt.php', 'settings-users.php', 'onu-types.php', 'splitters.php', 'settings-speed-profiles.php', 'tr069-management.php', 'vpn-tunnels.php']) && !can_access_settings()) {
    $_SESSION['error'] = 'Akses ditolak! Anda tidak memiliki izin untuk mengakses halaman tersebut.';
    header('Location: dashboard.php');
    exit;
}

// 4. Baca flash messages lalu lepas session lock SEBELUM render HTML.
//    Tanpa ini, AJAX calls dari halaman yang sama nge-block menunggu lock → lambat.
$_flash_success = $_SESSION['success'] ?? '';
$_flash_error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);
session_write_close();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SmartOLT Management System</title>
    <link rel="icon" href="favicon.svg" type="image/svg+xml">
    <!-- Google Fonts & Lucide Icons -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@600;700;800&family=Montserrat:wght@500;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@1.46.0/dist/umd/lucide.min.js" integrity="sha384-1avDoIZZ5mKtAlvTbQ2jge+TZA4z+qwhMFHdCpATww/SLVM8wKs7sROOEKMOizyV" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js" integrity="sha384-jb8JQMbMoBUzgWatfe6COACi2ljcDdZQ2OxczGA3bGNeWe+6DChMTBJemed7ZnvJ" crossorigin="anonymous"></script>
    <!-- Premium Stylesheet -->
    <link rel="stylesheet" href="public/style.css?v=<?= filemtime(__DIR__ . '/public/style.css') ?>">
    <!-- Anti-FOUC: Terapkan tema SEBELUM render agar tidak ada kedipan mode gelap -->
    <script>
        (function() {
            if (localStorage.getItem('smartolt_theme') === 'light') {
                document.documentElement.classList.add('light-mode');
            }
        })();
    </script>
    <script>
        window.CSRF_TOKEN = <?php echo json_encode($_SESSION['csrf_token'] ?? ''); ?>;
        window.API_TOKEN = <?php echo json_encode(get_api_token()); ?>;

        // JS API helper — all write operations go through Express API
        async function apiCall(method, path, data) {
            const opts = { method, headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + window.API_TOKEN } };
            if (data && method !== 'GET') opts.body = JSON.stringify(data);
            const r = await fetch('/api' + path, opts);
            return r.json();
        }

        // Automatically inject CSRF token to form submissions on the fly
        document.addEventListener('submit', function(event) {
            const form = event.target;
            if (form.method && form.method.toUpperCase() === 'POST') {
                let input = form.querySelector('input[name="csrf_token"]');
                if (!input && window.CSRF_TOKEN) {
                    input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'csrf_token';
                    input.value = window.CSRF_TOKEN;
                    form.appendChild(input);
                }
            }
        });

        // Automatically inject CSRF token to window.fetch POST requests
        if (window.fetch) {
            const originalFetch = window.fetch;
            window.fetch = function(input, init) {
                if (init && init.method && init.method.toUpperCase() === 'POST' && window.CSRF_TOKEN) {
                    init.headers = init.headers || {};
                    if (init.headers instanceof Headers) {
                        init.headers.set('X-CSRF-Token', window.CSRF_TOKEN);
                    } else {
                        init.headers['X-CSRF-Token'] = window.CSRF_TOKEN;
                    }
                }
                return originalFetch(input, init);
            };
        }
    </script>
</head>
<body>
    <div class="app-container">
        <!-- MAIN WORKSPACE -->
        <main class="main-content full-width">
            <!-- TOP HEADER WITH NAV -->
            <header class="top-header">
                <div class="header-left">
                    <a href="dashboard.php" class="topnav-brand">
                        <i data-lucide="network" class="icon-md"></i> SmartOLT
                    </a>
                    <nav class="topnav-links">
                        <a href="dashboard.php" class="topnav-item <?php echo $current_page === 'dashboard.php' ? 'active' : ''; ?>">
                            <i data-lucide="layout-dashboard" class="icon-sm"></i> Dashboard
                        </a>
                        <a href="unconfigured.php" class="topnav-item <?php echo $current_page === 'unconfigured.php' ? 'active' : ''; ?>">
                            <i data-lucide="scan-eye" class="icon-sm"></i> Unconfigured
                        </a>
                        <a href="configured.php" class="topnav-item <?php echo $current_page === 'configured.php' ? 'active' : ''; ?>">
                            <i data-lucide="users" class="icon-sm"></i> Configured
                        </a>
                        <?php if (can_access_settings()): ?>
                            <div class="topnav-dropdown">
                                <button class="topnav-item topnav-dropdown-toggle <?php echo ($current_page === 'settings-olt.php' || $current_page === 'settings-users.php') ? 'active' : ''; ?>" onclick="event.stopImmediatePropagation(); this.parentElement.classList.toggle('open');">
                                    <i data-lucide="settings" class="icon-sm"></i> Settings <i data-lucide="chevron-down" class="icon-xs"></i>
                                </button>
                                <div class="topnav-dropdown-menu">
                                    <a href="settings-olt.php" class="topnav-dropdown-item <?php echo $current_page === 'settings-olt.php' ? 'active' : ''; ?>">
                                        <i data-lucide="server" class="icon-sm"></i> OLT Management
                                    </a>
                                    <a href="settings-users.php" class="topnav-dropdown-item <?php echo $current_page === 'settings-users.php' ? 'active' : ''; ?>">
                                        <i data-lucide="users-round" class="icon-sm"></i> User Management
                                    </a>
                                    <a href="onu-types.php" class="topnav-dropdown-item <?php echo $current_page === 'onu-types.php' ? 'active' : ''; ?>">
                                        <i data-lucide="cpu" class="icon-sm"></i> ONU Types
                                    </a>
                                    <a href="splitters.php" class="topnav-dropdown-item <?php echo $current_page === 'splitters.php' ? 'active' : ''; ?>">
                                        <i data-lucide="git-branch" class="icon-sm"></i> Splitters
                                    </a>
                                    <a href="settings-speed-profiles.php" class="topnav-dropdown-item <?php echo $current_page === 'settings-speed-profiles.php' ? 'active' : ''; ?>">
                                        <i data-lucide="gauge" class="icon-sm"></i> Speed Profile
                                    </a>
                                    <a href="vpn-tunnels.php" class="topnav-dropdown-item <?php echo $current_page === 'vpn-tunnels.php' ? 'active' : ''; ?>">
                                        <i data-lucide="shield" class="icon-sm"></i> VPN Tunnels
                                    </a>
                                    <a href="tr069-management.php" class="topnav-dropdown-item <?php echo in_array($current_page, ['tr069-management.php']) ? 'active' : ''; ?>">
                                        <i data-lucide="file-cog" class="icon-sm"></i> TR-069 Management
                                    </a>
                                </div>
                            </div>
                        <?php endif; ?>
                        <a href="logs.php" class="topnav-item <?php echo $current_page === 'logs.php' ? 'active' : ''; ?>">
                            <i data-lucide="history" class="icon-sm"></i> Logs
                        </a>
                    </nav>
                </div>
                <div class="header-right">
                    <div class="status-badge-container">
                        <span class="dot-indicator online"></span>
                        <span>System Active</span>
                    </div>
                    <?php if (!empty($_SESSION['debug_mode'])): ?>
                        <a href="?toggle_debug=1" class="header-btn text-danger" title="Debug Mode Active">
                            <i data-lucide="bug" class="icon-sm"></i>
                        </a>
                    <?php else: ?>
                        <a href="?toggle_debug=1" class="header-btn" title="Toggle Debug Mode">
                            <i data-lucide="bug" class="icon-sm"></i>
                        </a>
                    <?php endif; ?>
                    <button id="theme-toggle" class="header-btn" title="Toggle Tema">
                        <i data-lucide="sun" class="icon-sm"></i>
                    </button>
                    <div class="topnav-user">
                        <span class="topnav-user-avatar"><?php echo isset($_SESSION['smartolt_username']) ? strtoupper(substr($_SESSION['smartolt_username'], 0, 2)) : 'US'; ?></span>
                        <span class="topnav-user-name"><?php echo isset($_SESSION['smartolt_username']) ? htmlspecialchars($_SESSION['smartolt_username']) : 'User'; ?></span>
                    </div>
                    <a href="action/logout.php" class="header-btn" title="Logout">
                        <i data-lucide="log-out" class="icon-sm"></i>
                    </a>
                </div>
            </header>

            <!-- Main Content Container -->
            <div class="view-panel">
                <?php if (isset($_SESSION['debug_log'])): ?>
                    <!-- Terminal Modal -->
                    <div class="modal open" id="debug-log-modal" role="dialog" aria-modal="true" aria-labelledby="debug-log-modal-title">
                        <div class="modal-content modal-lg debug-console">
                            <div class="modal-header">
                                <h3 id="debug-log-modal-title" class="modal-title inline-flex-center">
                                    <i data-lucide="terminal" class="icon-md"></i> OLT CLI Debug Log Tracing
                                </h3>
                                <button type="button" class="modal-close" aria-label="Tutup" onclick="document.getElementById('debug-log-modal').style.display='none'">&times;</button>
                            </div>
                            <div class="modal-body debug-console-body">
                                <div class="debug-console-caption">[Sent Commands & OLT CLI Trace Output]</div>
                                <pre class="debug-console-pre"><?php echo htmlspecialchars($_SESSION['debug_log']); ?></pre>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-success" onclick="document.getElementById('debug-log-modal').style.display='none'">Close</button>
                            </div>
                        </div>
                        </div>
                        <script>
                        // ESC key closer for debug modal
                        document.addEventListener('keydown', function(e) {
                            if (e.key === 'Escape') {
                                var m = document.getElementById('debug-log-modal');
                                if (m) m.style.display = 'none';
                            }
                        });
                        // Click outside closer for debug modal
                        document.getElementById('debug-log-modal').addEventListener('click', function(e) {
                            if (e.target === this) {
                                this.style.display = 'none';
                            }
                        });
                    </script>
                    <?php unset($_SESSION['debug_log']); ?>
                <?php endif; ?>

                <?php if (!empty($_flash_success)): ?>
                    <div class="alert alert-success">
                        <i data-lucide="check-circle" class="icon-md"></i>
                        <span><?php echo htmlspecialchars($_flash_success); ?></span>
                    </div>
                <?php endif; ?>
                <?php if (!empty($_flash_error)): ?>
                    <div class="alert alert-danger">
                        <i data-lucide="alert-triangle" class="icon-md"></i>
                        <span><?php echo htmlspecialchars($_flash_error); ?></span>
                    </div>
                <?php endif; ?>
