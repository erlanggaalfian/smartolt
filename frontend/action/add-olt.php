<?php
// ==============================================================================
# Action Handler: Tambah OLT
// ==============================================================================
require_once __DIR__ . '/../../backend/db.php';
require_once __DIR__ . '/../../backend/driver.php';

if (!isset($_SESSION['smartolt_role']) || $_SESSION['smartolt_role'] !== 'superadmin') {
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

    // 1. Konfigurasi SNMP community baru di OLT — sintaks CLI ditentukan driver vendor.
    $snmp_setup = setup_olt_snmp($olt, $snmp_community, $snmp_community_rw);
    if (!$snmp_setup['success']) {
        error_log("[SmartOLT] Gagal konfigurasi SNMP community: " . $snmp_setup['message']);
    }

    // 2. Tes Koneksi OLT (CLI & SNMP yang baru saja dikonfigurasi)
    $test = check_olt_connection($olt);
    if (!$test['success']) {
        $_SESSION['error'] = "Gagal Verifikasi OLT: " . $test['message'];
        header('Location: ../settings-olt.php');
        exit;
    }

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

        // Sinkronkan speed profile (Upload/Download) dari OLT ke cache lokal.
        try {
            $sp = get_olt_speed_profiles($olt);
            if ($sp['success'] ?? false) {
                $stmt_sp = $pdo->prepare("
                    INSERT INTO speed_profiles (olt_id, direction, name, speed_kbps, onu_count, synced_at)
                    VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
                    ON DUPLICATE KEY UPDATE
                        speed_kbps = VALUES(speed_kbps),
                        onu_count = VALUES(onu_count),
                        synced_at = CURRENT_TIMESTAMP
                ");
                foreach (($sp['profiles'] ?? []) as $p) {
                    $stmt_sp->execute([$olt_id, $p['direction'], $p['name'], $p['speed_kbps'], $p['onu_count'] ?? 0]);
                }
            }
        } catch (Exception $e) {
            error_log("[SmartOLT] Gagal sinkronisasi speed profile saat tambah OLT: " . $e->getMessage());
        }

        // Audit Log
        write_audit_log($olt_id, 'OLT_ADD', "OLT {$name} ({$ip}) berhasil didaftarkan. SNMP RO '{$snmp_community}' dan RW '{$snmp_community_rw}' dikonfigurasi otomatis.");

        $_SESSION['success'] = "OLT {$name} ({$ip}) berhasil ditambahkan, dikonfigurasi SNMP, dan diverifikasi!";
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
