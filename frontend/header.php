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
if (in_array($current_page, ['settings-olt.php', 'settings-users.php', 'onu-types.php', 'splitters.php', 'settings-speed-profiles.php']) && $_SESSION['smartolt_role'] !== 'superadmin') {
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
    <!-- Google Fonts & Lucide Icons -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@600;700;800&family=Montserrat:wght@500;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@1.46.0/dist/umd/lucide.min.js" integrity="sha384-1avDoIZZ5mKtAlvTbQ2jge+TZA4z+qwhMFHdCpATww/SLVM8wKs7sROOEKMOizyV" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js" integrity="sha384-jb8JQMbMoBUzgWatfe6COACi2ljcDdZQ2OxczGA3bGNeWe+6DChMTBJemed7ZnvJ" crossorigin="anonymous"></script>
    <!-- Premium Stylesheet -->
    <link rel="stylesheet" href="public/style.css">
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
            <header class="top-header top-nav">
                <div class="header-left" style="display: flex; align-items: center; gap: 0;">
                    <a href="dashboard.php" class="topnav-brand">
                        <i data-lucide="network" style="width:20px; height:20px;"></i> SmartOLT
                    </a>
                    <nav class="topnav-links">
                        <a href="dashboard.php" class="topnav-item <?php echo $current_page === 'dashboard.php' ? 'active' : ''; ?>">
                            <i data-lucide="layout-dashboard" style="width:15px; height:15px;"></i> Dashboard
                        </a>
                        <a href="unconfigured.php" class="topnav-item <?php echo $current_page === 'unconfigured.php' ? 'active' : ''; ?>">
                            <i data-lucide="scan-eye" style="width:15px; height:15px;"></i> Unconfigured
                        </a>
                        <a href="configured.php" class="topnav-item <?php echo $current_page === 'configured.php' ? 'active' : ''; ?>">
                            <i data-lucide="users" style="width:15px; height:15px;"></i> Configured
                        </a>
                        <?php if (isset($_SESSION['smartolt_role']) && $_SESSION['smartolt_role'] === 'superadmin'): ?>
                            <div class="topnav-dropdown">
                                <button class="topnav-item topnav-dropdown-toggle <?php echo ($current_page === 'settings-olt.php' || $current_page === 'settings-users.php') ? 'active' : ''; ?>">
                                    <i data-lucide="settings" style="width:15px; height:15px;"></i> Settings <i data-lucide="chevron-down" style="width:12px; height:12px;"></i>
                                </button>
                                <div class="topnav-dropdown-menu">
                                    <a href="settings-olt.php" class="topnav-dropdown-item <?php echo $current_page === 'settings-olt.php' ? 'active' : ''; ?>">
                                        <i data-lucide="server" style="width:14px; height:14px;"></i> OLT Management
                                    </a>
                                    <a href="settings-users.php" class="topnav-dropdown-item <?php echo $current_page === 'settings-users.php' ? 'active' : ''; ?>">
                                        <i data-lucide="users-round" style="width:14px; height:14px;"></i> User Management
                                    </a>
                                    <a href="onu-types.php" class="topnav-dropdown-item <?php echo $current_page === 'onu-types.php' ? 'active' : ''; ?>">
                                        <i data-lucide="cpu" style="width:14px; height:14px;"></i> ONU Types
                                    </a>
                                    <a href="splitters.php" class="topnav-dropdown-item <?php echo $current_page === 'splitters.php' ? 'active' : ''; ?>">
                                        <i data-lucide="git-branch" style="width:14px; height:14px;"></i> Splitters
                                    </a>
                                    <a href="settings-speed-profiles.php" class="topnav-dropdown-item <?php echo $current_page === 'settings-speed-profiles.php' ? 'active' : ''; ?>">
                                        <i data-lucide="gauge" style="width:14px; height:14px;"></i> Speed Profile
                                    </a>
                                </div>
                            </div>
                        <?php endif; ?>
                        <a href="logs.php" class="topnav-item <?php echo $current_page === 'logs.php' ? 'active' : ''; ?>">
                            <i data-lucide="history" style="width:15px; height:15px;"></i> Logs
                        </a>
                    </nav>
                </div>
                <div class="header-right" style="display: flex; align-items: center; gap: 15px;">
                    <div class="topnav-user">
                        <span class="topnav-user-avatar"><?php echo isset($_SESSION['smartolt_username']) ? strtoupper(substr($_SESSION['smartolt_username'], 0, 2)) : 'US'; ?></span>
                        <span class="topnav-user-name"><?php echo isset($_SESSION['smartolt_username']) ? htmlspecialchars($_SESSION['smartolt_username']) : 'User'; ?></span>
                    </div>
                    <?php if (!empty($_SESSION['debug_mode'])): ?>
                        <div id="btn-show-debug-cli" style="background: rgba(239,68,68,0.15); border: 1px solid #ef4444; color: #ef4444; padding: 4px 10px; border-radius: 4px; font-size: 0.8rem; font-weight: 600; display: flex; align-items: center; gap: 6px; font-family: 'Montserrat', sans-serif; cursor: pointer; transition: all 0.15s;" onmouseover="this.style.background='rgba(239,68,68,0.25)'" onmouseout="this.style.background='rgba(239,68,68,0.15)'" title="Klik untuk membuka Terminal Log CLI OLT">
                            <span style="display: inline-block; width: 6px; height: 6px; border-radius: 50%; background: #ef4444;"></span>
                            Debug Mode Active
                        </div>
                    <?php endif; ?>
                    <div class="status-badge-container">
                        <span class="dot-indicator online"></span>
                        <span class="status-text">System Active</span>
                    </div>
                    <a href="?toggle_debug=1" class="header-btn" title="Toggle Debug Mode" style="display: flex; align-items: center; justify-content: center; width: 36px; height: 36px; border-radius: 6px; border: 1px solid #e2e8f0; color: <?php echo !empty($_SESSION['debug_mode']) ? '#ef4444' : '#64748b'; ?>; background: <?php echo !empty($_SESSION['debug_mode']) ? 'rgba(239,68,68,0.1)' : 'none'; ?>; transition: all 0.15s;">
                        <i data-lucide="bug" style="width: 18px; height: 18px;"></i>
                    </a>
                    <button id="theme-toggle" class="header-btn" title="Toggle Tema">
                        <i data-lucide="sun"></i>
                    </button>
                    <a href="action/logout.php" class="header-btn" title="Logout" style="color: #ef4444;">
                        <i data-lucide="log-out"></i>
                    </a>
                </div>
            </header>

            <!-- Main Content Container -->
            <div class="view-panel">
                <?php if (isset($_SESSION['debug_log'])): ?>
                    <!-- Terminal Modal -->
                    <div class="modal open" id="debug-log-modal" style="z-index: 9999; display: flex; align-items: center; justify-content: center; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5);">
                        <div class="modal-content" style="max-width: 800px; width: 90%; border-radius: 6px; box-shadow: 0 4px 20px rgba(0,0,0,0.25); border: none; background: #1e1e1e; color: #f8f8f2; font-family: 'Courier New', Courier, monospace; display: flex; flex-direction: column;">
                            <div class="modal-header" style="border-bottom: 1px solid #333; padding: 15px 24px; display: flex; justify-content: space-between; align-items: center; background: #2d2d2d; border-top-left-radius: 6px; border-top-right-radius: 6px;">
                                <h3 style="margin:0; font-size:1.1rem; font-weight:500; color:#fff; display: flex; align-items: center; gap: 8px;">
                                    <i data-lucide="terminal" style="width:18px; height:18px; color: #a6e22e;"></i> OLT CLI Debug Log Tracing
                                </h3>
                                <button type="button" class="close-btn" onclick="document.getElementById('debug-log-modal').style.display='none'" style="background:none; border:none; font-size:1.5rem; cursor:pointer; color:#94a3b8; line-height: 1;">&times;</button>
                            </div>
                            <div class="modal-body" style="padding: 24px; max-height: 450px; overflow-y: auto;">
                                <div style="font-weight: bold; color: #66d9ef; margin-bottom: 10px;">[Sent Commands & OLT CLI Trace Output]</div>
                                <pre style="white-space: pre-wrap; font-size: 0.9rem; margin: 0; line-height: 1.4; color: #a6e22e; font-family: 'Courier New', Courier, monospace;"><?php echo htmlspecialchars($_SESSION['debug_log']); ?></pre>
                            </div>
                            <div class="modal-footer" style="padding: 15px 24px; border-top: 1px solid #333; display:flex; justify-content:flex-end; align-items:center; background: #2d2d2d; border-bottom-left-radius: 6px; border-bottom-right-radius: 6px;">
                                <button type="button" onclick="document.getElementById('debug-log-modal').style.display='none'" style="padding:8px 24px; border-radius:4px; background:#a6e22e; color:#000; border:none; cursor:pointer; font-weight:600; font-size:0.95rem; font-family: 'Montserrat', sans-serif;">Close</button>
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

                <!-- Global Debug CLI Terminal Modal -->
                <div id="global-debug-cli-modal" style="display: none; z-index: 9999; align-items: center; justify-content: center; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6);">
                    <div style="max-width: 900px; width: 95%; border-radius: 6px; box-shadow: 0 10px 30px rgba(0,0,0,0.5); border: none; background: #1e1e1e; color: #f8f8f2; font-family: 'Courier New', Courier, monospace; display: flex; flex-direction: column;">
                        <div style="border-bottom: 1px solid #333; padding: 15px 24px; display: flex; justify-content: space-between; align-items: center; background: #2d2d2d; border-top-left-radius: 6px; border-top-right-radius: 6px;">
                            <h3 style="margin:0; font-size:1.1rem; font-weight:500; color:#fff; display: flex; align-items: center; gap: 8px;">
                                <i data-lucide="terminal" style="width:18px; height:18px; color: #ef4444;"></i> OLT CLI Terminal Real-Time Debug Log
                            </h3>
                            <div style="display: flex; align-items: center; gap: 12px;">
                                <button id="btn-refresh-debug-cli" style="background: rgba(239,68,68,0.15); border: 1px solid #ef4444; color: #ef4444; padding: 4px 12px; border-radius: 4px; cursor: pointer; font-size: 0.85rem; font-family: 'Montserrat', sans-serif; font-weight: 600; transition: all 0.15s;" onmouseover="this.style.background='rgba(239,68,68,0.25)'" onmouseout="this.style.background='rgba(239,68,68,0.15)'">Refresh</button>
                                <button type="button" onclick="document.getElementById('global-debug-cli-modal').style.display='none'" style="background:none; border:none; font-size:1.5rem; cursor:pointer; color:#94a3b8; line-height: 1;">&times;</button>
                            </div>
                        </div>
                        <div style="padding: 24px; max-height: 480px; overflow-y: auto; background: #151515;">
                            <pre id="debug-cli-pre" style="white-space: pre-wrap; font-size: 0.9rem; margin: 0; line-height: 1.4; color: #a6e22e; font-family: 'Courier New', Courier, monospace;"></pre>
                        </div>
                        <div style="padding: 15px 24px; border-top: 1px solid #333; display:flex; justify-content:space-between; align-items:center; background: #2d2d2d; border-bottom-left-radius: 6px; border-bottom-right-radius: 6px;">
                            <span style="font-size: 0.8rem; color: #66d9ef; font-family: 'Montserrat', sans-serif;">Pencatatan CLI terminal OLT aktif (debug_cli.log)</span>
                            <button type="button" onclick="document.getElementById('global-debug-cli-modal').style.display='none'" style="padding:8px 24px; border-radius:4px; background:#ef4444; color:#fff; border:none; cursor:pointer; font-weight:600; font-size:0.95rem; font-family: 'Montserrat', sans-serif;">Close</button>
                        </div>
                    </div>
                </div>
                <script>
                    document.addEventListener('DOMContentLoaded', function() {
                        const btn = document.getElementById('btn-show-debug-cli');
                        const modal = document.getElementById('global-debug-cli-modal');
                        const pre = document.getElementById('debug-cli-pre');
                        const refreshBtn = document.getElementById('btn-refresh-debug-cli');
                        
                        if (!btn || !modal) return;
                        
                        function fetchDebugLog() {
                            pre.innerHTML = '[Memuat data log CLI terminal OLT...]';
                            fetch('action/get-debug-cli.php')
                                .then(r => r.json())
                                .then(data => {
                                    if (data.success) {
                                        pre.textContent = data.log;
                                        // Scroll to bottom
                                        setTimeout(() => {
                                            pre.parentElement.scrollTop = pre.parentElement.scrollHeight;
                                        }, 50);
                                    } else {
                                        pre.textContent = 'Gagal memuat log: ' + data.message;
                                    }
                                })
                                .catch(err => {
                                    pre.textContent = 'Gagal terhubung ke server: ' + err.message;
                                });
                        }
                        
                        btn.addEventListener('click', function() {
                            modal.style.display = 'flex';
                            fetchDebugLog();
                        });
                        
                        if (refreshBtn) {
                            refreshBtn.addEventListener('click', fetchDebugLog);
                        }
                        
                        // ESC key closer
                        document.addEventListener('keydown', function(e) {
                            if (e.key === 'Escape') {
                                modal.style.display = 'none';
                            }
                        });
                        
                        // Click outside closer
                        modal.addEventListener('click', function(e) {
                            if (e.target === modal) {
                                modal.style.display = 'none';
                            }
                        });
                    });
                </script>
                <?php if (!empty($_flash_success)): ?>
                    <div style="background:rgba(16,185,129,0.15); border:1px solid #10b981; color:#10b981; padding:12px 16px; border-radius:6px; margin-bottom:20px; font-weight:600; font-size:0.9rem; display:flex; align-items:center; gap:8px;">
                        <i data-lucide="check-circle" style="width:18px; height:18px;"></i>
                        <span><?php echo htmlspecialchars($_flash_success); ?></span>
                    </div>
                <?php endif; ?>
                <?php if (!empty($_flash_error)): ?>
                    <div style="background:rgba(239,68,68,0.15); border:1px solid #ef4444; color:#ef4444; padding:12px 16px; border-radius:6px; margin-bottom:20px; font-weight:600; font-size:0.9rem; display:flex; align-items:center; gap:8px;">
                        <i data-lucide="alert-triangle" style="width:18px; height:18px;"></i>
                        <span><?php echo htmlspecialchars($_flash_error); ?></span>
                    </div>
                <?php endif; ?>
