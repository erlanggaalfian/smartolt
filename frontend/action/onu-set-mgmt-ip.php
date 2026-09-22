<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../backend/db.php';
require_once __DIR__ . '/../../backend/driver.php';
if (!isset($_SESSION['smartolt_role'])) { echo json_encode(['success'=>false,'message'=>'Akses ditolak']); exit; }

$onu_id = (int)($_POST['onu_id'] ?? 0);
$mode = trim($_POST['mgmt_ip_mode'] ?? 'Inactive');
$vlan = $_POST['mgmt_vlan'] !== '' ? (int)$_POST['mgmt_vlan'] : 100;
$ip = trim($_POST['mgmt_ip'] ?? '');

if (!$onu_id) { echo json_encode(['success'=>false,'message'=>'ONU ID tidak valid']); exit; }
if (!in_array($mode, ['Inactive','DHCP','Static'])) { echo json_encode(['success'=>false,'message'=>'Mode tidak valid']); exit; }
if ($mode === 'Static' && $ip && !filter_var($ip, FILTER_VALIDATE_IP)) { echo json_encode(['success'=>false,'message'=>'IP address tidak valid']); exit; }

// Ambil data ONU + OLT
$stmt = $pdo->prepare("SELECT onus.*, olts.ip as olt_ip, olts.username as olt_username, olts.password as olt_password, olts.ssh_port, olts.type, olts.protocol, olts.snmp_port, olts.snmp_community, olts.snmp_community_rw FROM onus JOIN olts ON onus.olt_id = olts.id WHERE onus.id=?");
$stmt->execute([$onu_id]);
$row = $stmt->fetch();
if (!$row) { echo json_encode(['success'=>false,'message'=>'ONU tidak ditemukan']); exit; }

// Push ke OLT via driver
$olt = [
    'ip' => $row['olt_ip'], 'username' => $row['olt_username'], 'password' => $row['olt_password'],
    'ssh_port' => $row['ssh_port'], 'type' => $row['type'], 'protocol' => $row['protocol'] ?? 'ssh',
    'snmp_port' => $row['snmp_port'], 'snmp_community' => $row['snmp_community'],
    'snmp_community_rw' => $row['snmp_community_rw'] ?? '',
];
$result = call_driver($olt, 'set_mgmt_ip', [$row['pon_port'], (int)$row['onu_id'], $mode, $vlan, $ip]);

// Simpan ke DB
$pdo->prepare("UPDATE onus SET mgmt_ip_mode=?, mgmt_vlan=?, mgmt_ip=? WHERE id=?")
    ->execute([$mode, $vlan, $mode === 'Static' ? $ip : null, $onu_id]);

echo json_encode([
    'success' => $result['success'] ?? false,
    'message' => $result['message'] ?? 'Gagal push ke OLT',
    'log' => $result['log'] ?? ''
]);
