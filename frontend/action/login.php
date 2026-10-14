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

    // Brute-force protection: max 5 attempts per 15 minutes per IP
    $max_attempts = 5;
    $lockout_seconds = 900; // 15 menit
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    try {
        // Buat tabel jika belum ada (idempotent)
        $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ip VARCHAR(45) NOT NULL,
            attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ip_time (ip, attempted_at)
        ) ENGINE=InnoDB");
    } catch (PDOException $e) {}

    // Hitung percobaan gagal dari IP ini dalam 15 menit terakhir
    $attempt_count = 0;
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL ? SECOND)");
        $stmt->execute([$ip, $lockout_seconds]);
        $attempt_count = (int)$stmt->fetchColumn();
    } catch (PDOException $e) {}

    if ($attempt_count >= $max_attempts) {
        $_SESSION['error'] = 'Terlalu banyak percobaan login gagal. Coba lagi dalam beberapa menit.';
        header('Location: ../login.php');
        exit;
    }

    try {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            // Login sukses — hapus catatan percobaan gagal untuk IP ini
            try {
                $stmt = $pdo->prepare("DELETE FROM login_attempts WHERE ip = ?");
                $stmt->execute([$ip]);
            } catch (PDOException $e) {}

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
            // Gagal — catat percobaan ke DB (berbasis IP, bukan session)
            try {
                $stmt = $pdo->prepare("INSERT INTO login_attempts (ip, attempted_at) VALUES (?, NOW())");
                $stmt->execute([$ip]);
            } catch (PDOException $e) {}
            // Pesan seragam — jangan bocorkan info username salah vs password salah
            $sisa = $max_attempts - $attempt_count - 1;
            if ($sisa <= 0) {
                write_audit_log(null, 'LOGIN_LOCKOUT', "IP {$ip} terkunci selama {$lockout_seconds} detik setelah {$max_attempts} percobaan gagal.");
                $_SESSION['error'] = 'Terlalu banyak percobaan gagal. Akun dikunci selama 15 menit.';
            } else {
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
