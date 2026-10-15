<?php
// =============================================================================
# Action Handler: Export Configured ONUs to CSV
# Respects all filters from configured.php (search, status, zone, odb, pon, olt_id)
// =============================================================================
require_once __DIR__ . '/../../backend/db.php';

if (!isset($_SESSION['smartolt_role'])) {
    http_response_code(403);
    echo 'Akses ditolak!';
    exit;
}

session_write_close();
set_time_limit(120);

$allowed_ids = get_allowed_olt_ids();
$allowed_ids_str = implode(',', array_map('intval', $allowed_ids)) ?: '0';

$olt_id_raw = isset($_GET['olt_id']) ? $_GET['olt_id'] : '';
if (is_array($olt_id_raw)) {
    $selected_olts = [];
    foreach ($olt_id_raw as $oid) {
        $oid = (int)$oid;
        if ($oid > 0 && has_olt_access($oid)) $selected_olts[] = $oid;
    }
    $selected_olt_id = count($selected_olts) === 1 ? $selected_olts[0] : '';
} else {
    $selected_olt_id = ($olt_id_raw !== '' && $olt_id_raw !== null) ? (int)$olt_id_raw : '';
    if ($selected_olt_id !== '' && !has_olt_access($selected_olt_id)) $selected_olt_id = '';
    $selected_olts = $selected_olt_id !== '' ? [$selected_olt_id] : [];
}
$olt_filter_in = !empty($selected_olts) ? implode(',', $selected_olts) : $allowed_ids_str;

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status = isset($_GET['status']) ? $_GET['status'] : '';
$filter_zone = isset($_GET['zone']) ? trim($_GET['zone']) : '';
$filter_odb  = isset($_GET['odb']) ? $_GET['odb'] : '';
if (!is_array($filter_odb)) $filter_odb = $filter_odb !== '' ? [$filter_odb] : [];
$filter_pon     = isset($_GET['pon_port']) ? trim($_GET['pon_port']) : '';
$filter_signal  = isset($_GET['signal']) ? $_GET['signal'] : '';
$filter_type    = isset($_GET['onu_type']) ? trim($_GET['onu_type']) : '';
$filter_vlan    = isset($_GET['vlan']) ? trim($_GET['vlan']) : '';
$filter_profile = isset($_GET['profile']) ? trim($_GET['profile']) : '';

$filter_sql = "";
$params = [];

if ($search !== '') {
    $filter_sql .= " AND (onus.name LIKE ? OR onus.serial_number LIKE ? OR onus.onu_id LIKE ? OR onus.pppoe_username LIKE ? OR onus.address LIKE ? OR onus.description LIKE ? OR onus.external_id LIKE ? OR onus.contact LIKE ? OR olts.name LIKE ? OR olts.ip LIKE ? OR onus.vlan LIKE ? OR onus.pon_port LIKE ? OR onus.wan_mode LIKE ? OR onus.onu_type LIKE ? OR onus.last_down_cause LIKE ? OR onus.splitter LIKE ? OR onus.zone LIKE ? OR onus.download_profile LIKE ? OR onus.upload_profile LIKE ? OR onus.config_preset LIKE ? OR onus.mgmt_ip LIKE ? OR onus.odb_port LIKE ? OR onus.pppoe_ip LIKE ?)";
    for ($i = 0; $i < 23; $i++) $params[] = "%{$search}%";
}
if (!empty($selected_olts)) {
    $ph = implode(',', array_fill(0, count($selected_olts), '?'));
    $filter_sql .= " AND onus.olt_id IN ($ph)";
    foreach ($selected_olts as $oid) $params[] = $oid;
} else {
    $filter_sql .= " AND onus.olt_id IN ($allowed_ids_str)";
}
if ($status !== '') {
    $filter_sql .= " AND onus.status = ?";
    $params[] = $status;
}
if ($filter_zone !== '') {
    if (strtolower($filter_zone) === 'none') {
        $filter_sql .= " AND (onus.zone IS NULL OR onus.zone = '' OR onus.zone = 'None')";
    } else {
        $filter_sql .= " AND onus.zone = ?";
        $params[] = $filter_zone;
    }
}
if (!empty($filter_odb)) {
    $has_none = false;
    $odb_vals = [];
    foreach ($filter_odb as $o) {
        if (strtolower($o) === 'none') $has_none = true;
        elseif ($o !== '') $odb_vals[] = $o;
    }
    $parts = [];
    if (!empty($odb_vals)) {
        $ph = implode(',', array_fill(0, count($odb_vals), '?'));
        $parts[] = "onus.splitter IN ($ph)";
        foreach ($odb_vals as $o) $params[] = $o;
    }
    if ($has_none) $parts[] = "(onus.splitter IS NULL OR onus.splitter = '' OR onus.splitter = 'None')";
    if (!empty($parts)) $filter_sql .= " AND (" . implode(' OR ', $parts) . ")";
}
if ($filter_pon !== '') {
    $filter_sql .= " AND onus.pon_port = ?";
    $params[] = $filter_pon;
}
if ($filter_signal !== '') {
    $signal_ranges = [
        'critical' => ['max' => -30],
        'weak'     => ['min' => -30, 'max' => -28],
        'fair'     => ['min' => -28, 'max' => -25],
        'good'     => ['min' => -25],
    ];
    if (isset($signal_ranges[$filter_signal])) {
        $range = $signal_ranges[$filter_signal];
        if (isset($range['min']) && isset($range['max'])) {
            $filter_sql .= " AND onus.last_rx_power IS NOT NULL AND onus.last_rx_power >= ? AND onus.last_rx_power < ?";
            $params[] = $range['min'];
            $params[] = $range['max'];
        } elseif (isset($range['max'])) {
            $filter_sql .= " AND onus.last_rx_power IS NOT NULL AND onus.last_rx_power < ?";
            $params[] = $range['max'];
        } else {
            $filter_sql .= " AND onus.last_rx_power IS NOT NULL AND onus.last_rx_power >= ?";
            $params[] = $range['min'];
        }
    }
}
if ($filter_type !== '') {
    $filter_sql .= " AND onus.onu_type = ?";
    $params[] = $filter_type;
}
if ($filter_vlan !== '') {
    $filter_sql .= " AND onus.vlan = ?";
    $params[] = $filter_vlan;
}
if ($filter_profile !== '') {
    $filter_sql .= " AND onus.download_profile = ?";
    $params[] = $filter_profile;
}

$sql = "SELECT onus.name, onus.serial_number, onus.onu_id, onus.pon_port,
               onus.vlan, onus.status, onus.onu_type, onus.wan_mode, onus.onu_mode,
               onus.config_method,
               onus.pppoe_username, onus.pppoe_password,
               onus.zone, onus.splitter, onus.odb_port, onus.address, onus.contact, onus.external_id,
               onus.config_preset,
               onus.download_profile, onus.upload_profile,
               onus.description,
               onus.last_rx_power, onus.last_rx_olt_power, onus.last_down_cause,
               onus.wan_remote_access, onus.mgmt_ip, onus.pppoe_ip,
               onus.latitude, onus.longitude,
               onus.updated_at,
               olts.name AS olt_name
        FROM onus
        JOIN olts ON onus.olt_id = olts.id
        WHERE 1=1" . $filter_sql . "
        ORDER BY onus.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$filename = 'smartolt_export_' . date('Y-m-d_His') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache');

$output = fopen('php://output', 'w');
// BOM for Excel UTF-8 compatibility
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

fputcsv($output, [
    'Name', 'SN', 'ONU ID', 'PON Port', 'VLAN', 'Status', 'ONU Type', 'WAN Mode', 'ONU Mode',
    'Config Method',
    'PPPoE User', 'PPPoE Pass', 'Zone', 'ODB/Splitter', 'ODB Port', 'Address', 'Contact', 'External ID',
    'Config Preset',
    'Download Profile', 'Upload Profile',
    'Description',
    'Rx Power (dBm)', 'Rx OLT (dBm)', 'Last Down Cause',
    'WAN Remote Access', 'Mgmt IP', 'PPPoE IP',
    'Latitude', 'Longitude',
    'Last Updated', 'OLT'
]);

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    fputcsv($output, [
        $row['name'],
        $row['serial_number'],
        $row['onu_id'],
        $row['pon_port'],
        $row['vlan'],
        $row['status'],
        $row['onu_type'],
        $row['wan_mode'],
        $row['onu_mode'],
        $row['config_method'] ?? 'OMCI',
        $row['pppoe_username'],
        $row['pppoe_password'],
        $row['zone'],
        $row['splitter'],
        $row['odb_port'],
        $row['address'],
        $row['contact'],
        $row['external_id'],
        $row['config_preset'],
        $row['download_profile'],
        $row['upload_profile'],
        $row['description'],
        $row['last_rx_power'],
        $row['last_rx_olt_power'],
        $row['last_down_cause'],
        $row['wan_remote_access'],
        $row['mgmt_ip'],
        $row['pppoe_ip'],
        $row['latitude'],
        $row['longitude'],
        $row['updated_at'],
        $row['olt_name'],
    ]);
}

fclose($output);
exit;
