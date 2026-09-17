<?php
// ==============================================================================
// Action Handler: Konfigurasi Parameter Fisik Port (Auto-nego, Speed, Duplex).
// Lokasi: frontend/action/port-config.php
// ==============================================================================
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../backend/db.php';
require_once __DIR__ . '/../../backend/driver.php';

if (!isset($_SESSION['smartolt_role']) || $_SESSION['smartolt_role'] !== 'superadmin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Akses ditolak! Anda tidak memiliki izin.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Metode tidak diizinkan.']);
    exit;
}

$id        = (int)($_POST['olt_id'] ?? 0);
$port      = trim($_POST['port'] ?? '');
$auto_nego = trim($_POST['auto_nego'] ?? 'enable');
$speed     = trim($_POST['speed'] ?? '');
$duplex    = trim($_POST['duplex'] ?? '');

if ($id <= 0) {
    $_SESSION['error'] = 'ID OLT tidak valid!';
    header('Location: ../settings-olt.php');
    exit;
}
check_olt_access_or_redirect($id, '../settings-olt.php');

$stmt = $pdo->prepare("SELECT * FROM olts WHERE id = ?");
$stmt->execute([$id]);
$olt = $stmt->fetch();

if (!$olt) {
    echo json_encode(['success' => false, 'message' => 'OLT tidak ditemukan.']);
    exit;
}

// Lepas session lock agar request CLI berjalan asinkron tanpa memblokir sesi web.
set_time_limit(0);
session_write_close();

$olt_device = [
    'ip'                => $olt['ip'],
    'username'          => $olt['username'],
    'password'          => $olt['password'],
    'ssh_port'          => $olt['ssh_port'],
    'type'              => $olt['type'],
    'protocol'          => $olt['protocol'] ?? 'SSH',
    'snmp_port'         => $olt['snmp_port'] ?? 161,
    'snmp_community'    => $olt['snmp_community'] ?? 'public',
    'snmp_community_rw' => $olt['snmp_community_rw'] ?? 'public',
];

try {
    $result = set_olt_port_config($olt_device, $port, $auto_nego, $speed, $duplex);
} catch (Throwable $e) {
    error_log('[SmartOLT] port-config.php gagal: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Terjadi kesalahan saat berkomunikasi dengan OLT.']);
    exit;
}

// Log perangkat tidak dikirim ke klien
unset($result['log']);

// Audit log
if (!empty($result['success'])) {
    write_audit_log($id, 'PORT_CONFIG', "Port $port auto_nego=$auto_nego speed=$speed duplex=$duplex");
}

// Jangan bocorkan password OLT
if (!empty($result['message']) && !empty($olt['password'])) {
    $result['message'] = str_ireplace($olt['password'], '******', $result['message']);
}

$result['fetched_at'] = date('d/m/Y H:i:s');
echo json_encode($result);
