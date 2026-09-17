<?php
// ==============================================================================
// Action Handler: Preset ONU (kombinasi ONU Type + Download/Upload Speed + Mode).
// Lokasi: frontend/action/onu-presets.php
// Preset tidak spesifik per-OLT — hanya menyimpan NAMA profile, dicocokkan
// ke daftar speed_profiles milik OLT yang sedang dipilih saat dipakai.
// ==============================================================================
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../backend/db.php';

if (!isset($_SESSION['smartolt_role'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Akses ditolak! Silakan login terlebih dahulu.']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? 'list';

try {
    switch ($action) {
        case 'list':
            $rows = $pdo->query('SELECT id, name, onu_type, vlan, download_profile, upload_profile, onu_mode, wan_mode, zone, splitter FROM onu_presets ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
            $result = ['success' => true, 'presets' => $rows];
            break;

        case 'save':
            $name = trim((string)($_POST['name'] ?? ''));
            $onu_type = trim((string)($_POST['onu_type'] ?? 'ALL-ONT'));
            $vlan = isset($_POST['vlan']) && $_POST['vlan'] !== '' ? (int)$_POST['vlan'] : null;
            $download_profile = trim((string)($_POST['download_profile'] ?? ''));
            $upload_profile = trim((string)($_POST['upload_profile'] ?? ''));
            $onu_mode = in_array($_POST['onu_mode'] ?? '', ['Routing', 'Bridging'], true) ? $_POST['onu_mode'] : 'Routing';
            $wan_mode = in_array($_POST['wan_mode'] ?? '', ['Setup via ONU webpage', 'DHCP', 'Static', 'PPPoE'], true) ? $_POST['wan_mode'] : 'Setup via ONU webpage';
            $zone = trim((string)($_POST['zone'] ?? '')) ?: null;
            $splitter = trim((string)($_POST['splitter'] ?? '')) ?: null;
            if ($name === '' || $download_profile === '' || $upload_profile === '') {
                $result = ['success' => false, 'message' => 'Nama preset, Download speed, dan Upload speed wajib diisi.'];
                break;
            }
            $stmt = $pdo->prepare(
                'INSERT INTO onu_presets (name, onu_type, vlan, download_profile, upload_profile, onu_mode, wan_mode, zone, splitter)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE onu_type = VALUES(onu_type), vlan = VALUES(vlan), download_profile = VALUES(download_profile),
                    upload_profile = VALUES(upload_profile), onu_mode = VALUES(onu_mode), wan_mode = VALUES(wan_mode),
                    zone = VALUES(zone), splitter = VALUES(splitter)'
            );
            $stmt->execute([$name, $onu_type, $vlan, $download_profile, $upload_profile, $onu_mode, $wan_mode, $zone, $splitter]);
            write_audit_log(null, 'PRESET_SAVE', "Preset '{$name}' disimpan (type={$onu_type}, vlan={$vlan}, dl={$download_profile}, ul={$upload_profile}, mode={$onu_mode}, wan={$wan_mode}, zone={$zone}, splitter={$splitter}).");
            $result = ['success' => true, 'message' => "Preset '{$name}' berhasil disimpan."];
            break;

        case 'delete':
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                $result = ['success' => false, 'message' => 'ID preset tidak valid.'];
                break;
            }
            $stmt = $pdo->prepare('DELETE FROM onu_presets WHERE id = ?');
            $stmt->execute([$id]);
            write_audit_log(null, 'PRESET_DELETE', "Preset ID={$id} dihapus.");
            $result = ['success' => true, 'message' => 'Preset berhasil dihapus.'];
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Aksi tidak dikenali.']);
            exit;
    }
} catch (Throwable $e) {
    error_log('[SmartOLT] onu-presets.php gagal: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Terjadi kesalahan saat memproses preset.']);
    exit;
}

echo json_encode($result);
