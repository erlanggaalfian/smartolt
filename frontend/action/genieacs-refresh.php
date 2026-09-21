<?php
// ==============================================================================
# Action Handler: Trigger refresh parameter dari device GenieACS (superadmin only)
// ==============================================================================
header('Content-Type: application/json');
require_once __DIR__ . '/../../backend/db.php';
require_once __DIR__ . '/../../backend/genieacs.php';

if (!isset($_SESSION['smartolt_role']) || $_SESSION['smartolt_role'] !== 'superadmin') {
    echo json_encode(['success' => false, 'message' => 'Akses ditolak! Anda tidak memiliki izin.']);
    exit;
}
session_write_close();

$body = json_decode(file_get_contents('php://input'), true);
$deviceId = $body['device_id'] ?? '';
if (!$deviceId) {
    echo json_encode(['success' => false, 'message' => 'Device ID tidak ada.']);
    exit;
}

$result = genieacs_refresh($deviceId);
echo json_encode(['success' => $result !== null, 'message' => $result !== null ? 'OK' : 'Gagal menghubungi ACS (device mungkin offline).']);
