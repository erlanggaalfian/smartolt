<?php
// ==============================================================================
// Action Handler: Manajemen VLAN OLT (baca / tambah / hapus).
// Lokasi: frontend/action/vlan.php
//
// Endpoint ini TIDAK menerima perintah CLI dari klien. Klien hanya mengirim
// aksi ('list' | 'add' | 'delete') beserta VLAN ID, sedangkan seluruh perintah
// CLI ditentukan oleh driver brand-spesifik di backend/python_engine/drivers/<driver>.py.
// ==============================================================================
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../backend/db.php';
require_once __DIR__ . '/../../backend/driver.php';

if (!isset($_SESSION['smartolt_role'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Akses ditolak! Silakan login terlebih dahulu.']);
    exit;
}

set_time_limit(0); // VLAN CLI operations can exceed 30s default
$action = $_POST['action'] ?? $_GET['action'] ?? 'list';

if ($action !== 'list' && $_SESSION['smartolt_role'] !== 'superadmin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Akses ditolak! Hanya superadmin yang dapat melakukan aksi ini.']);
    exit;
}

$id     = (int)($_POST['olt_id'] ?? $_GET['olt_id'] ?? 0);

// Aksi yang mengubah perangkat wajib melalui POST.
if ($action !== 'list' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Metode tidak diizinkan.']);
    exit;
}
if ($action !== 'list' && !verify_csrf_token()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Token keamanan tidak valid. Silakan muat ulang halaman.']);
    exit;
}

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

// Lepas session lock agar permintaan lain tidak ikut tertahan selama sesi Telnet.
session_write_close();

$olt_device = [
    'id'                => $olt['id'],
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

$vlan_id   = (int)($_POST['vlan_id'] ?? 0);
$confirmed = !empty($_POST['confirm']);

try {
    switch ($action) {
        case 'list':
            // Try to serve from cache first.
            $stmt = $pdo->prepare('SELECT vlan_id AS id, description, type, tagged, untagged, ip, protected FROM olt_vlans WHERE olt_id = ?');
            $stmt->execute([$olt_device['id']]);
            $cached = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if ($cached) {
                foreach ($cached as &$row) {
                    $row['id'] = (int)$row['id'];
                    $row['protected'] = (bool)$row['protected'];
                }
                unset($row);
                $result = ['success' => true, 'vlans' => $cached];
            } else {
                // Cache miss – fetch from OLT and store.
                require_once __DIR__ . '/../../backend/vlan_cache.php';
                $vlans = refresh_olt_vlans($olt_device);
                $result = ['success' => true, 'vlans' => $vlans];
            }
            break;

        case 'add':
            $result = add_olt_vlan($olt_device, $vlan_id, cli_safe((string)($_POST['description'] ?? '')));
            break;

        case 'delete':
            $result = delete_olt_vlan($olt_device, $vlan_id, $confirmed);
            break;

        case 'save-vlan-ports':
            // Seluruh penyusunan perintah CLI ada di driver (save_vlan_ports).
            // Handler ini hanya meneruskan NIAT pengguna, bukan sintaks vendor —
            // sesuai aturan: tidak boleh ada perintah CLI vendor di frontend/.
            $description = cli_safe((string)($_POST['description'] ?? ''));
            $ports_config = json_decode((string)($_POST['ports_config'] ?? '{}'), true) ?: [];
            $result = save_olt_vlan_ports($olt_device, $vlan_id, $description, $ports_config);
            break;

        case 'port-vlan':

            // Konfigurasi VLAN pada port uplink fisik (mode + tagged/untagged).
            $pvid_raw = $_POST['pvid'] ?? '';
            $result = set_olt_port_vlan(
                $olt_device,
                (string)($_POST['port'] ?? ''),
                (string)($_POST['mode'] ?? ''),
                (string)($_POST['tagged'] ?? ''),
                (string)($_POST['untagged'] ?? ''),
                ($pvid_raw === '' ? null : (int)$pvid_raw)
            );
            break;

        case 'set-management':
            require_once __DIR__ . '/../../backend/vlan_cache.php';
            $is_mgmt = !empty($_POST['is_management']);
            $ok = set_olt_vlan_management($id, $vlan_id, $is_mgmt);
            $result = ['success' => $ok, 'message' => $ok ? 'Status VLAN Management diperbarui.' : 'Gagal memperbarui status.'];
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Aksi tidak dikenali.']);
            exit;
    }
} catch (Throwable $e) {
    error_log('[SmartOLT] vlan.php gagal: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Terjadi kesalahan saat berkomunikasi dengan OLT.']);
    exit;
}

if (!empty($_SESSION['debug_mode']) && isset($result['log'])) {
    $cmds_display = isset($result['commands']) ? implode("\n", $result['commands']) : '';
    $_SESSION['debug_log'] = "Commands Sent to OLT:\n{$cmds_display}\n\nOLT CLI Response Log:\n" . $result['log'];
    $result['debug_log'] = $_SESSION['debug_log'];
}

// Log perangkat tidak pernah dikirim ke klien (berpotensi memuat kredensial) kecuali jika debug_mode aktif
if (empty($_SESSION['debug_mode'])) {
    unset($result['log']);
}

// Audit log for write actions
if ($action !== 'list' && !empty($result['success'])) {
    write_audit_log($id, 'VLAN_' . strtoupper($action), "VLAN $action vlan_id=$vlan_id");
}

// Jangan pernah membocorkan password perangkat ke respons.
if (!empty($result['message']) && !empty($olt['password'])) {
    $result['message'] = str_ireplace($olt['password'], '******', $result['message']);
}

$result['fetched_at'] = date('d/m/Y H:i:s');
echo json_encode($result);
