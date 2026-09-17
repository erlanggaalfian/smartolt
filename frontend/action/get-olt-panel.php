<?php
// ==============================================================================
// Action Handler: Ambil data panel detail OLT dalam bentuk JSON terstruktur.
// Lokasi: frontend/action/get-olt-panel.php
//
// Endpoint ini TIDAK menerima perintah CLI dari klien. Nama panel dipetakan ke
// rangkaian perintah brand-spesifik di dalam backend/python_engine/drivers/<driver>.py,
// sehingga penambahan brand OLT baru tidak memerlukan perubahan di frontend.
// ==============================================================================
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../backend/db.php';
require_once __DIR__ . '/../../backend/driver.php';

if (!isset($_SESSION['smartolt_role']) || $_SESSION['smartolt_role'] !== 'superadmin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Akses ditolak! Anda tidak memiliki izin.']);
    exit;
}

// Lepas session lock agar request panel lain dapat berjalan paralel
set_time_limit(0); // multiple CLI calls per panel section
session_write_close();

$id    = isset($_GET['id'])    ? (int)$_GET['id'] : 0;
$panel = isset($_GET['panel']) ? trim($_GET['panel']) : '';

if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID OLT tidak valid.']);
    exit;
}
check_olt_access_json($id);

// Validasi ketat nama panel (allowlist karakter)
if ($panel === '' || !preg_match('/^[a-z0-9\-]{1,32}$/', $panel)) {
    echo json_encode(['success' => false, 'message' => 'Nama panel tidak valid.']);
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM olts WHERE id = ?");
$stmt->execute([$id]);
$olt = $stmt->fetch();

if (!$olt) {
    echo json_encode(['success' => false, 'message' => 'OLT tidak ditemukan.']);
    exit;
}

// Pastikan panel memang didukung oleh driver OLT ini
$supported = get_olt_supported_panels($olt);
if (!isset($supported[$panel])) {
    echo json_encode([
        'success' => false,
        'message' => "Panel '{$panel}' tidak didukung oleh driver perangkat ini."
    ]);
    exit;
}

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
    $result = get_olt_panel_data($olt_device, $panel);
} catch (Throwable $e) {
    error_log('[SmartOLT] get-olt-panel gagal: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Terjadi kesalahan saat mengambil data dari OLT.'
    ]);
    exit;
}

// Jangan pernah membocorkan password perangkat ke respons
if (!empty($result['message']) && !empty($olt['password'])) {
    $result['message'] = str_ireplace($olt['password'], '******', $result['message']);
}

$result['panel'] = $panel;
$result['fetched_at'] = date('d/m/Y H:i:s');

echo json_encode($result);
