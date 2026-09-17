<?php
// ==============================================================================
// Action Handler: Registrasi Pengguna
// Lokasi: /frontend/action/register.php
// ==============================================================================
require_once __DIR__ . '/../../backend/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    $role = trim($_POST['role']);

    $allowed_roles = ['superadmin', 'biasa'];
    $role = in_array(trim($_POST['role'] ?? ''), $allowed_roles, true) ? trim($_POST['role']) : '';

    if (empty($username) || empty($password) || empty($role)) {
        $_SESSION['error'] = 'Username dan password wajib diisi!';
        header('Location: ../register.php');
        exit;
    }

    // Cek apakah database kosong
    try {
        $user_count = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    } catch (Exception $e) {
        $user_count = 0;
    }

    $is_initial_setup = ($user_count == 0);

    // Jika bukan setup awal, pastikan user harus login & harus superadmin untuk membuat user baru
    if (!$is_initial_setup) {
        if (!isset($_SESSION['smartolt_user_id']) || $_SESSION['smartolt_role'] !== 'superadmin') {
            $_SESSION['error'] = 'Akses ditolak! Hanya Superadmin yang dapat mendaftarkan user baru.';
            header('Location: ../login.php');
            exit;
        }
    } else {
        // Pada setup awal, paksa role pertama menjadi superadmin demi keamanan
        $role = 'superadmin';
    }

    // Hash password secara aman
    $hashed_password = password_hash($password, PASSWORD_BCRYPT);

    try {
        $stmt = $pdo->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, ?)");
        $stmt->execute([$username, $hashed_password, $role]);
        $new_user_id = $pdo->lastInsertId();

        if ($is_initial_setup) {
            // Regenerasi ID Sesi untuk menghindari Session Fixation pada setup pertama
            session_regenerate_id(true);

            // Setup pertama sukses, otomatis login
            $_SESSION['smartolt_user_id'] = $new_user_id;
            $_SESSION['smartolt_username'] = $username;
            $_SESSION['smartolt_role'] = $role;

            // Catat audit log
            write_audit_log(null, 'ADMIN_SETUP', "Inisialisasi sistem berhasil. Superadmin pertama '{$username}' telah dibuat.");

            $_SESSION['success'] = "Setup sistem berhasil! Selamat datang, {$username}.";
            header('Location: ../dashboard.php');
            exit;
        } else {
            $_SESSION['success'] = "User baru '{$username}' ({$role}) berhasil didaftarkan!";
            // Catat audit log oleh superadmin yang sedang aktif
            write_audit_log(null, 'USER_CREATE', "Superadmin '{$_SESSION['smartolt_username']}' mendaftarkan user baru '{$username}' dengan peran '{$role}'.");
            header('Location: ../register.php');
            exit;
        }
    } catch (PDOException $e) {
        if ($e->errorInfo[1] == 1062) {
            $_SESSION['error'] = "Username '{$username}' sudah terdaftar di sistem!";
        } else {
            error_log("[SmartOLT register] Database Error: " . $e->getMessage());
            $_SESSION['error'] = 'Gagal menyimpan user karena kesalahan sistem internal.';
        }
    }

    header('Location: ../register.php');
    exit;
}
