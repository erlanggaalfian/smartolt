<?php
// Location: frontend/action/get-next-onu-id.php
require_once __DIR__ . '/../../backend/db.php';
require_once __DIR__ . '/../../backend/driver.php';

header('Content-Type: application/json');

if (!isset($_SESSION['smartolt_role'])) {
    echo json_encode(['success' => false, 'message' => 'Akses ditolak! Silakan login terlebih dahulu.']);
    exit;
}

$olt_id = (int)($_GET['olt_id'] ?? 0);
$pon_port = trim($_GET['pon_port'] ?? '');

if ($olt_id <= 0 || empty($pon_port)) {
    echo json_encode(['success' => false, 'message' => 'Parameter OLT ID atau PON Port tidak lengkap.']);
    exit;
}
check_olt_access_json($olt_id);

try {
    // Ambil data OLT
    $stmt = $pdo->prepare("SELECT * FROM olts WHERE id = ?");
    $stmt->execute([$olt_id]);
    $olt = $stmt->fetch();

    if (!$olt) {
        echo json_encode(['success' => false, 'message' => 'OLT tidak ditemukan.']);
        exit;
    }

    set_time_limit(0);
    session_write_close(); // Lepas lock sesi selama komunikasi dengan OLT
    $result = get_next_free_onu_id($olt, $pon_port);
    session_start(); // Mulai kembali sesi jika perlu menulis/membaca sesi setelahnya
    unset($result['log']);

    echo json_encode($result);
} catch (Throwable $e) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    error_log("[SmartOLT get-next-onu-id] Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Kesalahan sistem internal pada server.']);
}
