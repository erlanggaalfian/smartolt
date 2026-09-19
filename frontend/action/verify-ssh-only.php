<?php
// ==============================================================================
// Action Handler: Verifikasi Koneksi SSH Saja (JSON Response)
// Lokasi: /frontend/action/verify-ssh-only.php
// ==============================================================================
header('Content-Type: application/json');
require_once __DIR__ . '/../../backend/db.php';
require_once __DIR__ . '/../../backend/driver.php';

if (!isset($_SESSION['smartolt_role']) || $_SESSION['smartolt_role'] !== 'superadmin') {
    echo json_encode(['success' => false, 'message' => 'Akses ditolak!']);
    exit;
}

set_time_limit(0); // SSH verification can exceed 30s default

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token()) {
        echo json_encode(['success' => false, 'message' => 'Token keamanan tidak valid. Silakan muat ulang halaman.']);
        exit;
    }
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $ip = trim($_POST['ip'] ?? '');
    $type = trim($_POST['type'] ?? '');
    $protocol = trim($_POST['protocol'] ?? 'SSH');
    $ssh_port = (int)($_POST['ssh_port'] ?? ($protocol === 'TELNET' ? 23 : 22));
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    // Jika password kosong dan ada ID OLT, ambil password yang lama dari database
    if (empty($password) && $id > 0) {
        $stmt = $pdo->prepare("SELECT password FROM olts WHERE id = ?");
        $stmt->execute([$id]);
        $password = decrypt_password($stmt->fetchColumn());
    }

    if (empty($ip) || empty($username) || empty($password)) {
        echo json_encode(['success' => false, 'message' => 'IP, Username, dan Password wajib diisi!']);
        exit;
    }

    // Tipe OLT menentukan driver yang dipakai untuk uji koneksi; tanpa ini driver tak bisa dipilih.
    if (find_olt_driver_meta($type) === null) {
        echo json_encode(['success' => false, 'message' => 'Tipe OLT tidak didukung. Pilih tipe yang tersedia pada daftar.']);
        exit;
    }

    $olt = [
        'ip' => $ip,
        'type' => $type,
        'protocol' => $protocol,
        'ssh_port' => $ssh_port,
        'username' => $username,
        'password' => $password
    ];

    session_write_close(); // Lepas lock sesi selama komunikasi dengan OLT
    $test = check_olt_connection($olt);
    session_start(); // Mulai kembali sesi jika perlu menulis/membaca sesi setelahnya

    if (!$test['success']) {
        echo json_encode(['success' => false, 'message' => $test['message']]);
        exit;
    }

    echo json_encode(['success' => true, 'message' => "Koneksi {$protocol} berhasil diverifikasi!"]);
    exit;
}
