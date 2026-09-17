<?php
// Action Handler: Get cached signal values from Local MySQL DB (JSON Response)
// Location: frontend/action/get-signals-db.php

header('Content-Type: application/json');
require_once __DIR__ . '/../../backend/db.php';

if (!isset($_SESSION['smartolt_role'])) {
    echo json_encode(['success' => false, 'message' => 'Akses ditolak! Silakan login terlebih dahulu.']);
    exit;
}

$olt_id_raw = $_GET['olt_id'] ?? '';
$olt_id = ($olt_id_raw !== '' && $olt_id_raw !== null) ? (int)$olt_id_raw : '';

if ($olt_id !== '') {
    check_olt_access_json($olt_id);
}

try {
    // Compute allowed OLT IDs for "all OLTs" mode
    if ($olt_id === '') {
        $allowed_ids = get_allowed_olt_ids();
        $allowed_ids_str = implode(',', array_map('intval', $allowed_ids)) ?: '0';
    }
    // Lightweight ETag check: XOR-CRC32 per row avoids GROUP_CONCAT 1024-byte limit.
    // Cron bumps updated_at every cycle but signal data rarely changes.
    $hash_sql = $olt_id !== ''
        ? "SELECT BIT_XOR(CRC32(CONCAT_WS('|',status,COALESCE(last_rx_power,''),COALESCE(last_rx_olt_power,''),COALESCE(last_down_cause,''),COALESCE(vlan,''),COALESCE(wan_mode,''),COALESCE(download_profile,''),COALESCE(upload_profile,''),COALESCE(onu_type,''),COALESCE(name,'')))) AS data_crc, COUNT(*) AS cnt FROM onus WHERE olt_id = ?"
        : "SELECT BIT_XOR(CRC32(CONCAT_WS('|',status,COALESCE(last_rx_power,''),COALESCE(last_rx_olt_power,''),COALESCE(last_down_cause,''),COALESCE(vlan,''),COALESCE(wan_mode,''),COALESCE(download_profile,''),COALESCE(upload_profile,''),COALESCE(onu_type,''),COALESCE(name,'')))) AS data_crc, COUNT(*) AS cnt FROM onus WHERE olt_id IN ($allowed_ids_str)";
    $meta_sth = $olt_id !== '' ? $pdo->prepare($hash_sql) : $pdo->query($hash_sql);
    if ($olt_id !== '') $meta_sth->execute([$olt_id]);
    $meta = $meta_sth->fetch(PDO::FETCH_ASSOC);
    $etag = '"' . dechex($meta['data_crc'] ?? 0) . '-' . ($meta['cnt'] ?? 0) . '"';

    header("ETag: $etag");
    header('Cache-Control: max-age=0, must-revalidate');

    if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
        http_response_code(304);
        exit;
    }

    if ($olt_id !== '') {
        $stmt = $pdo->prepare("SELECT id, name, status, vlan, last_rx_power, last_rx_olt_power, last_down_cause, wan_mode, download_profile, upload_profile, onu_type FROM onus WHERE olt_id = ?");
        $stmt->execute([$olt_id]);
    } else {
        $stmt = $pdo->query("SELECT id, name, status, vlan, last_rx_power, last_rx_olt_power, last_down_cause, wan_mode, download_profile, upload_profile, onu_type FROM onus WHERE olt_id IN ($allowed_ids_str)");
    }

    $onus = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format response
    $data = [];
    foreach ($onus as $onu) {
        $data[] = [
            'id' => (int)$onu['id'],
            'name' => $onu['name'] ?? '',
            'customer_name' => extract_customer_name($onu['name'] ?? ''),
            'status' => $onu['status'],
            'vlan' => $onu['vlan'] !== null ? (int)$onu['vlan'] : null,
            'rx_onu' => $onu['last_rx_power'] !== null ? number_format((float)$onu['last_rx_power'], 2) : 'N/A',
            'rx_olt' => $onu['last_rx_olt_power'] !== null ? number_format((float)$onu['last_rx_olt_power'], 2) : 'N/A',
            'last_down_cause' => $onu['last_down_cause'] ?? '',
            'wan_mode' => $onu['wan_mode'] ?? '',
            'download_profile' => $onu['download_profile'] ?? '',
            'upload_profile' => $onu['upload_profile'] ?? '',
            'onu_type' => $onu['onu_type'] ?? ''
        ];
    }
    
    echo json_encode(['success' => true, 'onus' => $data]);
} catch (Exception $e) {
    error_log("[SmartOLT get-signals-db] Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Terjadi kesalahan sistem internal pada database.']);
}
exit;
?>
