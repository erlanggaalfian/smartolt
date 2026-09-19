<?php
// ==============================================================================
# Action Handler: Edit OLT
// ==============================================================================
require_once __DIR__ . '/../../backend/db.php';
require_once __DIR__ . '/../../backend/driver.php';

if (!isset($_SESSION['smartolt_role']) || $_SESSION['smartolt_role'] !== 'superadmin') {
    $_SESSION['error'] = 'Akses ditolak! Anda tidak memiliki izin untuk mengonfigurasi OLT.';
    header('Location: ../dashboard.php');
    exit;
}
set_time_limit(0); // OLT edit + re-test connection can exceed 30s default

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token()) {
        $_SESSION['error'] = 'Token keamanan tidak valid. Silakan coba lagi.';
        header('Location: ../settings-olt.php');
        exit;
    }
    $id = (int)$_POST['id'];
    $name = trim($_POST['name']);
    $type = trim($_POST['type']);
    $ip = trim($_POST['ip']);
    $protocol = trim($_POST['protocol'] ?? 'SSH');
    $ssh_port = (int)$_POST['ssh_port'] ?: ($protocol === 'TELNET' ? 23 : 22);
    $username = trim($_POST['username']);
    $password_input = trim($_POST['password']);
    $snmp_port = (int)$_POST['snmp_port'] ?: 8161;

    if (empty($id) || empty($name) || empty($ip) || empty($username)) {
        $_SESSION['error'] = 'Seluruh field input wajib diisi!';
        header('Location: ../settings-olt.php');
        exit;
    }

    // Tipe OLT harus punya driver terdaftar.
    if (find_olt_driver_meta($type) === null) {
        $_SESSION['error'] = 'Tipe OLT tidak didukung. Pilih tipe yang tersedia pada daftar.';
        header('Location: ../settings-olt.php');
        exit;
    }

    // Ambil data OLT lama
    $stmt = $pdo->prepare("SELECT * FROM olts WHERE id = ?");
    $stmt->execute([$id]);
    $old_olt = $stmt->fetch();

    if (!$old_olt) {
        $_SESSION['error'] = 'OLT tidak ditemukan!';
        header('Location: ../settings-olt.php');
        exit;
    }

    // Tentukan password yang digunakan
    $password = !empty($password_input) ? $password_input : decrypt_password($old_olt['password']);

    // Gunakan SNMP community dari POST, fallback ke data lama jika kosong
    $snmp_community = trim($_POST['snmp_community'] ?? '');
    $snmp_community_rw = trim($_POST['snmp_community_rw'] ?? '');

    if (empty($snmp_community)) {
        $snmp_community = $old_olt['snmp_community'];
    }
    if (empty($snmp_community_rw)) {
        $snmp_community_rw = $old_olt['snmp_community_rw'];
    }

    $olt = [
        'name' => $name,
        'type' => $type,
        'ip' => $ip,
        'protocol' => $protocol,
        'ssh_port' => $ssh_port,
        'username' => $username,
        'password' => $password,
        'snmp_port' => $snmp_port,
        'snmp_community' => $snmp_community,
        'snmp_community_rw' => $snmp_community_rw
    ];

    // 1. Konfigurasi SNMP community baru di OLT — sintaks CLI ditentukan driver vendor.
    $snmp_setup = setup_olt_snmp($olt, $snmp_community, $snmp_community_rw);
    if (!$snmp_setup['success']) {
        error_log("[SmartOLT] Gagal konfigurasi SNMP community saat edit OLT: " . $snmp_setup['message']);
    }

    // 2. Tes Koneksi OLT
    $test = check_olt_connection($olt);
    if (!$test['success']) {
        $_SESSION['error'] = "Gagal Verifikasi OLT: " . $test['message'];
        header('Location: ../settings-olt.php');
        exit;
    }

    try {
        $encrypted_password = encrypt_password($password);
        $stmt = $pdo->prepare("UPDATE olts SET name = ?, ip = ?, ssh_port = ?, username = ?, password = ?, protocol = ?, snmp_port = ?, snmp_community = ?, snmp_community_rw = ?, type = ? WHERE id = ?");
        $stmt->execute([$name, $ip, $ssh_port, $username, $encrypted_password, $protocol, $snmp_port, $snmp_community, $snmp_community_rw, $type, $id]);
        $olt['id'] = $id;

        // Sinkronisasi ONU (nama, VLAN, status) dijalankan di background —
        // fetch show current-config bisa detik-menit untuk OLT besar, dan
        // form tak perlu menunggunya (cron_sync.py juga akan sinkron ulang
        // tiap menit sebagai jaring pengaman).
        $php_bin = '/usr/bin/php8.2';
        $worker = escapeshellarg(__DIR__ . '/../../backend/background_pull_onus.php');
        exec(escapeshellarg($php_bin) . ' ' . $worker . ' ' . (int)$id
            . ' > /dev/null 2>&1 &');

        // Audit Log
        write_audit_log($id, 'OLT_EDIT', "OLT {$name} ({$ip}) berhasil diperbarui dan dikonfigurasi ulang SNMP.");

        $_SESSION['success'] = "OLT {$name} ({$ip}) berhasil diperbarui!";
    } catch (PDOException $e) {
        if ($e->errorInfo[1] == 1062) {
            $_SESSION['error'] = "Kombinasi IP {$ip} + SSH port sudah digunakan OLT lain! Gunakan port SSH berbeda.";
        } else {
            error_log("[SmartOLT edit-olt] Database Error: " . $e->getMessage());
            $_SESSION['error'] = "Terjadi kesalahan sistem internal pada database.";
        }
    }
    
    $redirect = !empty($_POST['redirect']) ? trim($_POST['redirect']) : '../settings-olt.php';
    header('Location: ' . get_safe_redirect($redirect, '../settings-olt.php'));
    exit;
}
