<?php
// =============================================================================
// Action Handler: Logout Pengguna
// Lokasi: /frontend/action/logout.php
// =============================================================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Ambil username untuk log sebelum dihancurkan
$username = isset($_SESSION['smartolt_username']) ? $_SESSION['smartolt_username'] : 'Unknown';
$user_id  = isset($_SESSION['smartolt_user_id']) ? (int)$_SESSION['smartolt_user_id'] : null;

// Catat audit log sebelum sesi dihancurkan
if ($user_id !== null) {
    require_once __DIR__ . '/../../backend/db.php';
    write_audit_log(null, 'USER_LOGOUT', "User '{$username}' berhasil logout dari sistem.");
}

// Hancurkan semua data sesi
$_SESSION = [];
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}
session_destroy();

// Redirect ke login dengan pesan sukses
session_start();
$_SESSION['success'] = "Anda berhasil keluar dari sistem.";
header('Location: ../login.php');
exit;
