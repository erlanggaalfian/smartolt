<?php
// =============================================================================
// Action Handler: Login Pengguna
// Lokasi: /frontend/action/login.php
// =============================================================================
require_once __DIR__ . '/../../backend/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    if (empty($username) || empty($password)) {
        $_SESSION['error'] = 'Username dan password wajib diisi!';
        header('Location: ../login.php');
        exit;
    }

    // Brute-force protection: max 5 attempts per 15 minutes per session
    $max_attempts = 5;
    $lockout_seconds = 900; // 15 menit
    if (!isset($_SESSION['login_attempts'])) $_SESSION['login_attempts'] = 0;
    if (!isset($_SESSION['login_lockout_until'])) $_SESSION['login_lockout_until'] = 0;

    if ($_SESSION['login_attempts'] >= $max_attempts && time() < $_SESSION['login_lockout_until']) {
        $remaining = $_SESSION['login_lockout_until'] - time();
        $_SESSION['error'] = "Terlalu banyak percobaan login gagal. Coba lagi dalam {$remaining} detik.";
        header('Location: ../login.php');
        exit;
    }

    // Reset lockout if window expired
    if (time() >= $_SESSION['login_lockout_until']) {
        $_SESSION['login_attempts'] = 0;
    }

    try {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            // Login sukses — reset counter
            $_SESSION['login_attempts'] = 0;
            $_SESSION['login_lockout_until'] = 0;

            // Regenerasi ID Sesi untuk menghindari Session Fixation
            session_regenerate_id(true);

            // Login sukses, simpan sesi
            $_SESSION['smartolt_user_id'] = $user['id'];
            $_SESSION['smartolt_username'] = $user['username'];
            $_SESSION['smartolt_role'] = $user['role'];

            // Catat audit log
            write_audit_log(null, 'USER_LOGIN', "User {$username} ({$user['role']}) berhasil login ke sistem.");

            header('Location: ../dashboard.php');
            exit;
        } else {
            // Gagal — increment counter
            $_SESSION['login_attempts'] = ($_SESSION['login_attempts'] ?? 0) + 1;
            if ($_SESSION['login_attempts'] >= $max_attempts) {
                $_SESSION['login_lockout_until'] = time() + $lockout_seconds;
                write_audit_log(null, 'LOGIN_LOCKOUT', "IP login terkunci selama {$lockout_seconds} detik setelah {$max_attempts} percobaan gagal (username: {$username}).");
                $_SESSION['error'] = 'Terlalu banyak percobaan gagal. Akun dikunci selama 15 menit.';
            } else {
                $sisa = $max_attempts - $_SESSION['login_attempts'];
                $_SESSION['error'] = "Username atau password salah! ({$sisa} percobaan tersisa)";
            }
        }
    } catch (PDOException $e) {
        error_log("[SmartOLT login] Database Error: " . $e->getMessage());
        $_SESSION['error'] = 'Terjadi kesalahan sistem internal pada database.';
    }

    header('Location: ../login.php');
    exit;
}
