<?php
// ==============================================================================
# Action Handler: Data Lengkap ONU (JSON)
#
# Alur: frontend -> action ini -> driver.php -> driver vendor.
# Driver mengembalikan data MATANG (nama, rx power, IP PPPoE, VLAN, dst),
# action ini hanya menyimpan ke DB lalu membungkusnya jadi JSON.
# Tidak ada perintah CLI / regex vendor di layer ini.
// ==============================================================================
header('Content-Type: application/json');
require_once __DIR__ . '/../../backend/db.php';
require_once __DIR__ . '/../../backend/driver.php';

if (!isset($_SESSION['smartolt_role'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Akses ditolak! Silakan login terlebih dahulu.']);
    exit;
}
session_write_close(); // Lepas lock sesi agar request lain bisa berjalan paralel
set_time_limit(0); // CLI + SNMP calls can exceed 30s default


$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'ID ONU tidak valid.']);
    exit;
}

$stmt = $pdo->prepare("SELECT onus.*, olts.name AS olt_name, olts.ip AS olt_ip,
                              olts.username AS olt_username, olts.password AS olt_password,
                              olts.ssh_port, olts.type AS olt_type, olts.protocol,
                              olts.snmp_port, olts.snmp_community, olts.snmp_community_rw
                       FROM onus
                       JOIN olts ON onus.olt_id = olts.id
                       WHERE onus.id = ?");
$stmt->execute([$id]);
$onu = $stmt->fetch();

if (!$onu) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'ONU tidak ditemukan.']);
    exit;
}

if (function_exists('has_olt_access') && !has_olt_access($onu['olt_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Anda tidak punya akses ke OLT ini.']);
    exit;
}

$olt = [
    'ip'                => $onu['olt_ip'],
    'username'          => $onu['olt_username'],
    'password'          => $onu['olt_password'],
    'ssh_port'          => $onu['ssh_port'],
    'type'              => $onu['olt_type'],
    'protocol'          => $onu['protocol'] ?? 'SSH',
    'snmp_port'         => $onu['snmp_port'] ?? 161,
    'snmp_community'    => $onu['snmp_community'] ?? 'public',
    'snmp_community_rw' => $onu['snmp_community_rw'] ?? 'public',
];

// Mode ringan (default): hanya sinyal + IP, dipakai polling 15 detik.
// Mode penuh (?full=1): sekalian VLAN/PPPoE/mode, dipakai saat halaman dibuka.
// Mode snmp (?mode=snmp): SNMP-only untuk polling 15 detik, zero VTY/SSH.
$full = isset($_GET['full']) && $_GET['full'] === '1';
$snmp_lite = isset($_GET['mode']) && $_GET['mode'] === 'snmp';

try {
    if ($full) {
        $d = sync_onu_config_from_olt($olt, $onu);
        // Tarik juga sinyal & status realtime agar tidak kosong/offline saat full sync
        try {
            $sig = get_onu_signal($olt, $onu);
            if (is_array($sig)) {
                $d = array_merge($d, $sig);
            }
        } catch (Exception $e) {
            // Abaikan error sinyal agar tidak membatalkan pembacaan konfigurasi dasar
        }
    } elseif ($snmp_lite) {
        // SNMP-only: Rx power + traffic counters + status, tanpa SSH/VTY
        $d = ['status' => $onu['status'], 'rx_onu' => $onu['last_rx_power'],
              'rx_olt' => $onu['last_rx_olt_power'], 'pppoe_ip' => $onu['pppoe_ip'] ?? null,
              'distance_m' => null, 'traffic_rx_octets' => null, 'traffic_tx_octets' => null,
              'traffic_rx_packets' => null, 'traffic_tx_packets' => null];
        $vendor = (stripos($onu['olt_type'], 'CDATA') !== false) ? 'cdata' : 'zte';
        $python_bin = dirname(dirname(__DIR__)) . '/backend/python_engine/venv/bin/python3';
        $snmp_script = dirname(dirname(__DIR__)) . '/backend/python_engine/snmp_module.py';
        $snmp_port = (int)($onu['snmp_port'] ?? 161);
        $cmd = escapeshellarg($python_bin) . ' -c '
             . escapeshellarg("import sys,json; sys.path.insert(0," . escapeshellarg(dirname($snmp_script)) . "); import snmp_module as s; r=s.get_onu_signal_snmp(" . escapeshellarg($onu['olt_ip']) . "," . escapeshellarg($onu['snmp_community'] ?? 'public') . "," . escapeshellarg($onu['pon_port']) . "," . (int)$onu['onu_id'] . ",port=" . $snmp_port . ",vendor=" . escapeshellarg($vendor) . "); print(json.dumps(r))");
        $out = trim(shell_exec($cmd));
        if ($out) {
            $snmp_result = json_decode($out, true);
            if ($snmp_result && !empty($snmp_result['success'])) {
                $d['status'] = $snmp_result['status'] ?? $d['status'];
                $d['rx_onu'] = $snmp_result['rx_onu'] ?? $d['rx_onu'];
                $d['traffic_rx_octets'] = $snmp_result['traffic_rx_octets'] ?? null;
                $d['traffic_tx_octets'] = $snmp_result['traffic_tx_octets'] ?? null;
                $d['traffic_rx_packets'] = $snmp_result['traffic_rx_packets'] ?? null;
                $d['traffic_tx_packets'] = $snmp_result['traffic_tx_packets'] ?? null;
            }
        }
    } else {
        $d = get_onu_signal($olt, $onu);
    }
} catch (Exception $e) {
    error_log("[SmartOLT onu-data] OLT error for ONU #$id: " . $e->getMessage());
    http_response_code(502);
    echo json_encode(['success' => false, 'message' => 'OLT tidak dapat dihubungi. Silakan coba lagi.']);
    exit;
}

// Normalisasi: 'N/A' / '' dari driver dianggap tidak ada nilai.
$val = static function ($v) {
    return ($v === 'N/A' || $v === '' || $v === null) ? null : $v;
};

$status   = $val($d['status'] ?? null) ?: 'offline';
$rx_onu   = $val($d['rx_onu'] ?? null);
$rx_olt   = $val($d['rx_olt'] ?? null);
$pppoe_ip = $val($d['pppoe_ip'] ?? null);

if ($full) {
    // Parsing data identitas OLT ke local DB cache
    $name_val = $onu['name'];
    $desc_val = $onu['name'];

    if (!empty($d['onu_name'])) {
        $name_val = $d['onu_name'];
    }
    if (!empty($d['onu_description'])) {
        $desc_val = $d['onu_description'];
    }

    if (stripos($name_val, 'zone_') !== false || stripos($name_val, 'name_') !== false) {
        $desc_val = $name_val;
    }

    $parsed = parse_structured_description($desc_val);
    $zone_val     = ($parsed['zone'] !== 'None' && $parsed['zone'] !== '') ? $parsed['zone'] : $onu['zone'];
    $splitter_val = ($parsed['splitter'] !== 'None' && $parsed['splitter'] !== '') ? $parsed['splitter'] : $onu['splitter'];
    $address_val  = ($parsed['address'] !== 'None' && $parsed['address'] !== '') ? $parsed['address'] : $onu['address'];
    $contact_val  = ($parsed['contact'] !== 'None' && $parsed['contact'] !== '') ? $parsed['contact'] : $onu['contact'];

    $clean_name = null;
    if (stripos($desc_val, 'name_') !== false) {
        $clean_name = extract_customer_name($desc_val);
    } elseif (!empty($d['onu_name']) && !preg_match('/^onu_\d+$/i', $d['onu_name'])) {
        $clean_name = preg_replace('/^name_/i', '', $d['onu_name']);
    } elseif (!empty($onu['name']) && !preg_match('/^onu_\d+$/i', $onu['name'])) {
        $clean_name = $onu['name'];
    } else {
        $clean_name = 'ONU_' . $onu['onu_id'];
    }

    $pdo->prepare("
        UPDATE onus SET
            status            = ?,
            last_rx_power     = ?,
            last_rx_olt_power = ?,
            vlan              = COALESCE(?, vlan),
            pppoe_username    = COALESCE(?, pppoe_username),
            pppoe_password    = COALESCE(?, pppoe_password),
            onu_mode          = COALESCE(?, onu_mode),
            wan_mode          = COALESCE(?, wan_mode),
            wan_remote_access = COALESCE(?, wan_remote_access),
            mgmt_ip           = COALESCE(?, mgmt_ip),
            allow_remote_mgmt = COALESCE(?, allow_remote_mgmt),
            download_profile  = COALESCE(?, download_profile),
            upload_profile    = COALESCE(?, upload_profile),
            name              = COALESCE(?, name),
            zone              = COALESCE(?, zone),
            splitter          = COALESCE(?, splitter),
            address           = COALESCE(?, address),
            contact           = COALESCE(?, contact),
            updated_at        = CURRENT_TIMESTAMP
        WHERE id = ?
    ")->execute([
        $status,
        $rx_onu !== null ? (float)$rx_onu : null,
        $rx_olt !== null ? (float)$rx_olt : null,
        $val($d['vlan'] ?? null),
        $val($d['pppoe_username'] ?? null),
        $val($d['pppoe_password'] ?? null),
        $val($d['onu_mode'] ?? null),
        $val($d['wan_mode'] ?? null),
        $val($d['wan_remote_access'] ?? null),
        $val($d['mgmt_ip'] ?? null),
        $val($d['allow_remote_mgmt'] ?? null),
        $val($d['download_profile'] ?? null),
        $val($d['upload_profile'] ?? null),
        $clean_name,
        $zone_val,
        $splitter_val,
        $address_val,
        $contact_val,
        $id,
    ]);
} else {
    // For snmp_lite: rx_olt may be null (not available via SNMP) — don't overwrite existing DB value
    if ($snmp_lite && $rx_olt === null) {
        $pdo->prepare("UPDATE onus SET status = ?, last_rx_power = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
            ->execute([$status, $rx_onu !== null ? (float)$rx_onu : null, $id]);
    } else {
        $pdo->prepare("UPDATE onus SET status = ?, last_rx_power = ?, last_rx_olt_power = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
            ->execute([$status, $rx_onu !== null ? (float)$rx_onu : null, $rx_olt !== null ? (float)$rx_olt : null, $id]);
    }
}

// Query ulang supaya nilai yang dikirim = nilai final di DB (hasil COALESCE).
$stmt->execute([$id]);
$onu = $stmt->fetch();

echo json_encode([
    'success'           => true,
    'status'            => $onu['status'],
    'name'              => $onu['name'],
    'serial_number'     => $onu['serial_number'],
    'pon_port'          => $onu['pon_port'],
    'onu_id'            => $onu['onu_id'],
    'rx_onu'            => $rx_onu ?? 'N/A',
    'rx_olt'            => $rx_olt ?? 'N/A',
    'pppoe_ip'          => $pppoe_ip ?: 'N/A',
    'pppoe_username'    => $onu['pppoe_username'] ?: '',
    'pppoe_password'    => $onu['pppoe_password'] ?: '',
    'vlan'              => $onu['vlan'],
    'onu_mode'          => $onu['onu_mode'],
    'wan_mode'          => $onu['wan_mode'],
    'wan_remote_access' => $onu['wan_remote_access'],
    'allow_remote_mgmt' => $onu['allow_remote_mgmt'],
    'mgmt_ip'           => $onu['mgmt_ip'],
    'zone'              => $onu['zone'],
    'splitter'          => $onu['splitter'],
    'external_id'       => $onu['external_id'],
    'address'           => $onu['address'],
    'contact'           => $onu['contact'],
    'download_profile'  => $onu['download_profile'] ?? '',
    'upload_profile'    => $onu['upload_profile'] ?? '',
    'updated_at'        => $onu['updated_at'],
    'last_down_cause'   => $onu['last_down_cause'],
    'traffic_rx_octets' => $val($d['traffic_rx_octets'] ?? null),
    'traffic_tx_octets' => $val($d['traffic_tx_octets'] ?? null),
    'traffic_rx_packets' => $val($d['traffic_rx_packets'] ?? null),
    'traffic_tx_packets' => $val($d['traffic_tx_packets'] ?? null),
    'distance_m' => $val($d['distance_m'] ?? null),
]);
