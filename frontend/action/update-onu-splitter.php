<?php
/**
 * API: Update splitter for an ONU
 */
require_once __DIR__ . '/../../backend/db.php';

if (!isset($_SESSION['smartolt_role'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Akses ditolak!']);
    exit;
}

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$onu_id = intval($input['onu_id'] ?? $_POST['onu_id'] ?? 0);
$splitter = trim($input['splitter'] ?? $_POST['splitter'] ?? '');

if (!$onu_id) {
    echo json_encode(['success' => false, 'error' => 'Missing onu_id']);
    exit;
}

$stmt = $pdo->prepare("SELECT olt_id FROM onus WHERE id = ?");
$stmt->execute([$onu_id]);
$onu_row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$onu_row) {
    echo json_encode(['success' => false, 'error' => 'ONU tidak ditemukan']);
    exit;
}

if (function_exists('has_olt_access') && !has_olt_access($onu_row['olt_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Akses ditolak!']);
    exit;
}

$stmt = $pdo->prepare("UPDATE onus SET splitter = ? WHERE id = ?");
$stmt->execute([$splitter ?: null, $onu_id]);

// Audit log
$olt_id = $onu_row['olt_id'] ?: null;
write_audit_log($olt_id, 'ONU_SPLITTER_CHANGE', "ONU #$onu_id splitter changed to " . ($splitter ?: '(empty)'));

echo json_encode(['success' => true]);
