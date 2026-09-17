<?php
// Location: frontend/action/get-autofind.php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../backend/db.php';
require_once __DIR__ . '/../../backend/driver.php';

if (!isset($_SESSION['smartolt_role'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Akses ditolak! Silakan login terlebih dahulu.']);
    exit;
}

set_time_limit(0); // CLI autofind query can exceed 30s default on large OLTs

$olt_id = isset($_GET['olt_id']) ? (int)$_GET['olt_id'] : 0;
if ($olt_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'ID OLT tidak valid.']);
    exit;
}

if (function_exists('has_olt_access') && !has_olt_access($olt_id)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Anda tidak memiliki akses ke OLT ini.']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT * FROM olts WHERE id = ?");
    $stmt->execute([$olt_id]);
    $active_olt = $stmt->fetch();

    if (!$active_olt) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'OLT tidak ditemukan.']);
        exit;
    }

    session_write_close(); // Lepas lock sesi selama komunikasi dengan OLT
    $res_autofind = get_olt_autofind($active_olt);
    session_start(); // Mulai kembali sesi jika perlu menulis/membaca sesi setelahnya

    echo json_encode([
        'success' => $res_autofind['success'] ?? false,
        'message' => $res_autofind['message'] ?? '',
        'onus' => $res_autofind['onus'] ?? []
    ]);
} catch (Throwable $e) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    error_log("[SmartOLT get-autofind] Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Kesalahan sistem internal pada server.']);
}
