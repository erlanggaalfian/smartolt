<?php
/**
 * API: Update ONU type for an ONU
 */
require_once __DIR__ . '/../../backend/db.php';
require_once __DIR__ . '/../../backend/driver.php';

if (!isset($_SESSION['smartolt_role'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Akses ditolak!']);
    exit;
}

set_time_limit(0); // CLI type change can exceed 30s default
session_write_close();

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$onu_id = intval($input['onu_id'] ?? $_POST['onu_id'] ?? 0);
$type = trim($input['type'] ?? $_POST['type'] ?? '');

if (!$onu_id || !$type) {
    echo json_encode(['success' => false, 'error' => 'Missing onu_id or type']);
    exit;
}

// Check OLT access before allowing update
$onu = $pdo->prepare("SELECT id, olt_id, pon_port, onu_id AS onu_idx, serial_number FROM onus WHERE id = ?");
$onu->execute([$onu_id]);
$onu = $onu->fetch(PDO::FETCH_ASSOC);

if (!$onu) {
    echo json_encode(['success' => false, 'error' => 'ONU tidak ditemukan']);
    exit;
}

if (function_exists('has_olt_access') && !has_olt_access($onu['olt_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Akses ditolak!']);
    exit;
}

// Update in DB
$stmt = $pdo->prepare("UPDATE onus SET onu_type = ? WHERE id = ?");
$stmt->execute([$type, $onu_id]);

// Send to OLT via CLI (best-effort)
$onu = $pdo->prepare("SELECT * FROM onus WHERE id = ?");
$onu->execute([$onu_id]);
$onu = $onu->fetch(PDO::FETCH_ASSOC);

write_audit_log($onu['olt_id'] ?? null, 'ONU_TYPE_CHANGE', "ONU #$onu_id type changed to '$type'");

if ($onu) {
    $olt = $pdo->prepare("SELECT * FROM olts WHERE id = ?");
    $olt->execute([$onu['olt_id']]);
    $olt = $olt->fetch(PDO::FETCH_ASSOC);
    
    if ($olt) {
        try {
            $result = call_driver($olt, 'onu_type', [
                'pon_port' => $onu['pon_port'],
                'onu_id' => (int)$onu['onu_id'],
                'serial_number' => $onu['serial_number'],
                'type' => $type
            ]);
        } catch (\Exception $e) {
            // best-effort, DB already updated
        }
    }
}

echo json_encode(['success' => true]);
