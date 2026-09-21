<?php
// ==============================================================================
# Action Handler: Set Parameter Values ke device GenieACS (superadmin only)
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
$params = $body['params'] ?? [];
if (!$deviceId || empty($params)) {
    echo json_encode(['success' => false, 'message' => 'Data tidak lengkap.']);
    exit;
}

$map = genieacs_param_map();
$paramValues = [];
foreach ($params as $key => $value) {
    // key format: "category.field", contoh "wan.ip" / "wlan.ssid"
    [$cat, $field] = array_pad(explode('.', $key, 2), 2, null);
    if (!$cat || !$field || !isset($map[$cat][$field])) continue;
    $path = $map[$cat][$field];
    $type = in_array($value, ['true', 'false'], true) ? 'xsd:boolean' : 'xsd:string';
    $paramValues[$path] = [$value, $type];
}

if (empty($paramValues)) {
    echo json_encode(['success' => false, 'message' => 'Tidak ada parameter valid untuk diubah.']);
    exit;
}

$result = genieacs_set_params($deviceId, $paramValues);
// GenieACS selalu queue task ke DB duluan sebelum coba connection request —
// task tetap tersimpan meski request timeout (device offline/belum listen).
echo json_encode(['success' => true, 'message' => $result !== null ? 'Diterapkan langsung ke device.' : 'Perubahan disimpan, akan diterapkan otomatis saat device connect berikutnya (device sedang offline).', 'task' => $result]);
