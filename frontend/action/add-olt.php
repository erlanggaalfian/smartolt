<?php
// ==============================================================================
# Action Handler: Tambah OLT
// ==============================================================================
require_once __DIR__ . '/../../backend/db.php';
require_once __DIR__ . '/../../backend/driver.php';

if (!can_access_settings()) {
    $_SESSION['error'] = 'Akses ditolak! Anda tidak memiliki izin untuk mengonfigurasi OLT.';
    header('Location: ../dashboard.php');
    exit;
}
set_time_limit(0); // OLT registration + SNMP sync can exceed 30s default

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token()) {
        $_SESSION['error'] = 'Token keamanan tidak valid. Silakan coba lagi.';
        header('Location: ../settings-olt.php');
        exit;
    }
    $name = trim($_POST['name']);
    $type = trim($_POST['type']);
    $ip = trim($_POST['ip']);
    $protocol = trim($_POST['protocol'] ?? 'SSH');
    $ssh_port = (int)$_POST['ssh_port'] ?: ($protocol === 'TELNET' ? 23 : 22);
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    $snmp_port = (int)$_POST['snmp_port'] ?: 8161;

    if (empty($name) || empty($ip) || empty($username) || empty($password)) {
        $_SESSION['error'] = 'Seluruh field input wajib diisi!';
        header('Location: ../settings-olt.php');
        exit;
    }

    // Tipe OLT harus punya driver terdaftar, kalau tidak perintah CLI-nya tak akan cocok.
    if (find_olt_driver_meta($type) === null) {
        $_SESSION['error'] = 'Tipe OLT tidak didukung. Pilih tipe yang tersedia pada daftar.';
        header('Location: ../settings-olt.php');
        exit;
    }

    // Generator string community SNMP acak
    function generate_random_community($prefix) {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $str = '';
        for ($i = 0; $i < 8; $i++) {
            $str .= $chars[rand(0, strlen($chars) - 1)];
        }
        return $prefix . '_' . $str;
    }

    $snmp_community = generate_random_community('smartro');
    $snmp_community_rw = generate_random_community('smartrw');

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

    // SNMP setup, verifikasi koneksi, dan sync speed profile dipindah ke
    // background_provision_olt.php (lihat di bawah) — semua itu CLI yang bisa
    // butuh detik-menit, dan form tak perlu menunggunya. OLT langsung disimpan
    // dengan status "provisioning", hasil verifikasi menyusul via toast di frontend.

    try {
        $encrypted_password = encrypt_password($password);
        $stmt = $pdo->prepare("INSERT INTO olts (name, ip, ssh_port, username, password, protocol, snmp_port, snmp_community, snmp_community_rw, type) 
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$name, $ip, $ssh_port, $username, $encrypted_password, $protocol, $snmp_port, $snmp_community, $snmp_community_rw, $type]);
        $olt_id = $pdo->lastInsertId();
        $olt['id'] = $olt_id;

        // Sinkronisasi ONU (nama, VLAN, status) dijalankan di background —
        // fetch show current-config bisa detik-menit untuk OLT besar, dan
        // form tak perlu menunggunya (cron_sync.py juga akan sinkron ulang
        // tiap menit sebagai jaring pengaman).
        $php_bin = '/usr/bin/php8.2';
        $worker = escapeshellarg(__DIR__ . '/../../backend/background_pull_onus.php');
        exec(escapeshellarg($php_bin) . ' ' . $worker . ' ' . (int)$olt_id
            . ' > /dev/null 2>&1 &');

        // Provisioning SNMP + verifikasi koneksi + speed profile — semuanya
        // di background juga (lihat komentar di atas).
        $provision_worker = escapeshellarg(__DIR__ . '/../../backend/background_provision_olt.php');
        exec(escapeshellarg($php_bin) . ' ' . $provision_worker . ' ' . (int)$olt_id
            . ' > /dev/null 2>&1 &');

        // Audit Log
        write_audit_log($olt_id, 'OLT_ADD', "OLT {$name} ({$ip}) didaftarkan. SNMP RO '{$snmp_community}' dan RW '{$snmp_community_rw}' sedang dikonfigurasi di background.");

        $_SESSION['success'] = "OLT {$name} ({$ip}) berhasil ditambahkan! Verifikasi koneksi & konfigurasi SNMP sedang berjalan di background.";
        $_SESSION['new_olt_id'] = $olt_id; // dibaca frontend untuk poll status verifikasi via toast
    } catch (PDOException $e) {
        if ($e->errorInfo[1] == 1062) {
            $_SESSION['error'] = "Kombinasi IP {$ip} + SSH port sudah terdaftar di sistem! Gunakan port SSH berbeda.";
        } else {
            error_log("[SmartOLT add-olt] Database Error: " . $e->getMessage());
            $_SESSION['error'] = "Terjadi kesalahan sistem internal pada database.";
        }
    }

    header('Location: ../settings-olt.php');
    exit;
}
