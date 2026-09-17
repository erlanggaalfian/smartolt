<?php
// ==============================================================================
// Action Handler: Speed Profile OLT (baca cache lokal / sync dari OLT / hapus cache).
// Lokasi: frontend/action/speed-profiles.php
//
// Endpoint ini TIDAK membuat/mengubah profile di OLT — hanya membaca profile
// yang sudah ada di perangkat lalu menyimpannya sebagai cache lokal ('sync').
// Aksi 'delete' hanya menghapus baris cache, TIDAK menyentuh konfigurasi OLT.
// ==============================================================================
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../backend/db.php';
require_once __DIR__ . '/../../backend/driver.php';

if (!isset($_SESSION['smartolt_role'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Akses ditolak! Silakan login terlebih dahulu.']);
    exit;
}

set_time_limit(0); // sync reads full running-config (90-150s on large OLTs)

$action = $_POST['action'] ?? $_GET['action'] ?? 'list';

if ($action !== 'list' && $_SESSION['smartolt_role'] !== 'superadmin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Akses ditolak! Hanya superadmin yang dapat melakukan aksi ini.']);
    exit;
}

if ($action !== 'list' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Metode tidak diizinkan.']);
    exit;
}

$id = (int)($_POST['olt_id'] ?? $_GET['olt_id'] ?? 0);
if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID OLT tidak valid!']);
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

try {
    switch ($action) {
        case 'list':
            $stmt = $pdo->prepare(
                'SELECT direction, name, speed_kbps, onu_count, is_default, synced_at
                 FROM speed_profiles WHERE olt_id = ? ORDER BY direction, speed_kbps'
            );
            $stmt->execute([$id]);
            $result = ['success' => true, 'profiles' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
            break;

        case 'sync':
            session_write_close(); // Lepas lock sesi selama komunikasi Telnet/SSH ke OLT
            $pulled = get_olt_speed_profiles($olt);
            if (empty($pulled['success'])) {
                $result = ['success' => false, 'message' => $pulled['message'] ?? 'Gagal membaca speed profile dari OLT.'];
                break;
            }
            $profiles = $pulled['profiles'] ?? [];

            $pdo->beginTransaction();
            $stmt_del = $pdo->prepare('DELETE FROM speed_profiles WHERE olt_id = ?');
            $stmt_del->execute([$id]);
            $stmt_ins = $pdo->prepare(
                'INSERT INTO speed_profiles (olt_id, direction, name, speed_kbps, onu_count)
                 VALUES (?, ?, ?, ?, ?)'
            );
            foreach ($profiles as $p) {
                $stmt_ins->execute([$id, $p['direction'], $p['name'], $p['speed_kbps'], $p['onu_count'] ?? 0]);
            }
            $pdo->commit();

            write_audit_log($id, 'SPEED_PROFILE_SYNC', count($profiles) . ' speed profile berhasil disinkronkan dari OLT.');
            $result = ['success' => true, 'message' => count($profiles) . ' speed profile berhasil disinkronkan.', 'count' => count($profiles)];
            break;

        case 'save':
            session_write_close();
            $direction = (string)($_POST['direction'] ?? '');
            $name = trim((string)($_POST['name'] ?? ''));
            $speed_kbps = (int)($_POST['speed_kbps'] ?? 0);
            if (!in_array($direction, ['download', 'upload'], true) || $name === '' || $speed_kbps <= 0) {
                $result = ['success' => false, 'message' => 'Parameter tidak lengkap atau tidak valid.'];
                break;
            }
            $pushed = save_olt_speed_profile($olt, $direction, $name, $speed_kbps);
            if (empty($pushed['success'])) {
                $result = ['success' => false, 'message' => $pushed['message'] ?? 'Gagal menyimpan speed profile ke OLT.'];
                break;
            }
            $stmt = $pdo->prepare(
                'INSERT INTO speed_profiles (olt_id, direction, name, speed_kbps, onu_count)
                 VALUES (?, ?, ?, ?, 0)
                 ON DUPLICATE KEY UPDATE speed_kbps = VALUES(speed_kbps), synced_at = CURRENT_TIMESTAMP'
            );
            $stmt->execute([$id, $direction, $name, $speed_kbps]);
            write_audit_log($id, 'SPEED_PROFILE_SAVE', "Speed profile '{$name}' ({$direction}, {$speed_kbps} kbps) disimpan ke OLT.");
            $result = ['success' => true, 'message' => "Speed profile '{$name}' berhasil disimpan ke OLT."];
            break;

        case 'delete':
            session_write_close();
            $direction = (string)($_POST['direction'] ?? '');
            $name = (string)($_POST['name'] ?? '');
            $force = !empty($_POST['force']);
            if (!in_array($direction, ['download', 'upload'], true) || $name === '') {
                $result = ['success' => false, 'message' => 'Parameter tidak lengkap.'];
                break;
            }
            $stmt = $pdo->prepare('SELECT onu_count FROM speed_profiles WHERE olt_id = ? AND direction = ? AND name = ?');
            $stmt->execute([$id, $direction, $name]);
            $onuCount = (int)($stmt->fetchColumn() ?: 0);
            if ($onuCount > 0 && !$force) {
                $result = ['success' => false, 'needs_confirm' => true, 'onu_count' => $onuCount,
                    'message' => "Profile ini masih dipakai {$onuCount} ONU. Menghapusnya berisiko mengganggu layanan mereka."];
                break;
            }
            $pushed = delete_olt_speed_profile($olt, $direction, $name);
            if (empty($pushed['success'])) {
                $result = ['success' => false, 'message' => $pushed['message'] ?? 'Gagal menghapus speed profile dari OLT.'];
                break;
            }
            $stmt = $pdo->prepare('DELETE FROM speed_profiles WHERE olt_id = ? AND direction = ? AND name = ?');
            $stmt->execute([$id, $direction, $name]);
            write_audit_log($id, 'SPEED_PROFILE_DELETE', "Speed profile '{$name}' ({$direction}) dihapus dari OLT (dipakai {$onuCount} ONU saat dihapus).");
            $result = ['success' => true, 'message' => "Speed profile '{$name}' berhasil dihapus dari OLT."];
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Aksi tidak dikenali.']);
            exit;
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    error_log('[SmartOLT] speed-profiles.php gagal: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Terjadi kesalahan saat memproses speed profile.']);
    exit;
}

echo json_encode($result);
