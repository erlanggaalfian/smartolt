<?php
// ==============================================================================
# Action Handler: ONU Query (status | running-config)
# Gabungan dari get-status.php + show-running-config.php
// ==============================================================================
require_once __DIR__ . '/../../backend/db.php';
require_once __DIR__ . '/../../backend/driver.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['smartolt_role'])) {
    echo json_encode(['success' => false, 'message' => 'Akses ditolak! Silakan login terlebih dahulu.']);
    exit;
}
set_time_limit(0); // CLI queries bisa lambat di OLT besar
session_write_close(); // Lepas lock sesi agar request lain bisa berjalan paralel


$id     = (int)($_GET['id'] ?? 0);
$action = $_GET['action'] ?? 'status';

$MAP = [
    'status' => 'get_onu_full_status',
    'config' => 'get_onu_running_config',
    'hw_sw'  => 'get_onu_hw_sw',
];

if ($id <= 0 || !isset($MAP[$action])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Parameter tidak valid.']);
    exit;
}

$stmt = $pdo->prepare("SELECT onus.*, olts.ip, olts.username, olts.password as olt_password,
                              olts.ssh_port, olts.type, olts.protocol,
                              olts.snmp_port, olts.snmp_community, olts.snmp_community_rw
                       FROM onus 
                       JOIN olts ON onus.olt_id = olts.id 
                       WHERE onus.id = ?");
$stmt->execute([$id]);
$onu = $stmt->fetch();

if (!$onu) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Pelanggan tidak ditemukan.']);
    exit;
}

if (function_exists('has_olt_access') && !has_olt_access($onu['olt_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Anda tidak punya akses ke OLT ini.']);
    exit;
}

$olt = [
    'ip'                => $onu['ip'],
    'username'          => $onu['username'],
    'password'          => $onu['olt_password'],
    'ssh_port'          => $onu['ssh_port'],
    'type'              => $onu['type'],
    'protocol'          => $onu['protocol'] ?? 'SSH',
    'snmp_port'         => $onu['snmp_port'] ?? 161,
    'snmp_community'    => $onu['snmp_community'] ?? 'public',
    'snmp_community_rw' => $onu['snmp_community_rw'] ?? 'public',
];

$fn = $MAP[$action];
try {
    $result = $fn($olt, $onu);
} catch (Throwable $e) {
    error_log("[SmartOLT onu-query] Driver error ($action, ONU #$id): " . $e->getMessage());
    http_response_code(502);
    echo json_encode(['success' => false, 'message' => 'Gagal menghubungi OLT. Silakan coba lagi.']);
    exit;
}

// Enrich optical status with SNMP data (faster, more reliable)
if (($result['success'] ?? false) && $action === 'status') {
    $pon_port = $onu['pon_port'];
    $onu_id = (int)$onu['onu_id'];
    $snmp_ip = $onu['ip'];
    $snmp_comm = $onu['snmp_community'];
    $snmp_port = (int)($onu['snmp_port'] ?? 161);

    $python_bin = dirname(dirname(__DIR__)) . '/backend/python_engine/venv/bin/python3';
    $snmp_py = dirname(dirname(__DIR__)) . '/backend/python_engine/snmp_module.py';
    $cmd = escapeshellarg($python_bin) . ' -c '
         . escapeshellarg("import sys; sys.path.insert(0, " . escapeshellarg(dirname($snmp_py)) . "); import snmp_module as s; r=s.get_onu_rx_power(" . escapeshellarg($snmp_ip) . "," . escapeshellarg($snmp_comm) . "," . escapeshellarg($pon_port) . "," . $onu_id . "," . $snmp_port . "); print(f'{r[\"rx_dbm\"]:.2f}' if r and r.get('rx_dbm') is not None else 'N/A')")
         . ' 2>&1';
    $rx_out = trim(shell_exec($cmd));
    if ($rx_out && $rx_out !== 'N/A' && is_numeric($rx_out)) {
        // Append SNMP optical data to the existing optical_status
        $result['optical_status'] = trim($result['optical_status'] ?? '') . "\n" .
            "1310nm OLT Rx for this ONU: {$rx_out} (dBm) [SNMP]";
    }
}

if ($result['success'] ?? false) {
    if ($action === 'status') {
        $status_txt = "";
        $status_txt .= "Optical status\n" . ($result['optical_status'] ?? '') . "\n\n";
        $status_txt .= "ONU CATV port\n" . ($result['onu_catv_port'] ?? 'No CATV data or command unsupported.') . "\n\n";
        $status_txt .= "ONU details\n" . ($result['onu_details'] ?? '') . "\n\n";
        $status_txt .= "History\n" . ($result['history'] ?? '') . "\n\n";
        $status_txt .= "ONU WAN Interfaces\n" . ($result['wan_interfaces'] ?? '') . "\n\n";
        $status_txt .= "ONU LAN Interfaces status\n" . ($result['lan_interfaces'] ?? '') . "\n\n";
        $status_txt .= "Realtime VLAN info\n" . ($result['vlan_info'] ?? '') . "\n\n";
        $status_txt .= "VoIP status\n" . ($result['voip_status'] ?? '') . "\n\n";
        $status_txt .= "MACs on OLT from this ONU\n" . ($result['macs'] ?? '');
        
        $stmt_update = $pdo->prepare("UPDATE onus SET last_status_cli = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt_update->execute([$status_txt, $id]);
    } else if ($action === 'config') {
        $config_txt = $result['config'] ?? '';
        $stmt_update = $pdo->prepare("UPDATE onus SET last_running_config = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt_update->execute([$config_txt, $id]);
    } else if ($action === 'hw_sw') {
        $hw_sw_txt = "ONU Details:\n" . ($result['onu_details'] ?? '') . "\n\n";
        $hw_sw_txt .= "Features reported by ONU:\n" . ($result['features'] ?? '');
        $result['hw_sw'] = $hw_sw_txt;
    }
}

unset($result['log']);
echo json_encode($result);
