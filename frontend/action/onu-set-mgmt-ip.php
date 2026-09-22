<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../backend/db.php';
if (!isset($_SESSION['smartolt_role'])) { echo json_encode(['success'=>false,'message'=>'Akses ditolak']); exit; }

$onu_id = (int)($_POST['onu_id'] ?? 0);
$mode = trim($_POST['mgmt_ip_mode'] ?? 'Inactive');
$vlan = $_POST['mgmt_vlan'] !== '' ? (int)$_POST['mgmt_vlan'] : null;
$ip = trim($_POST['mgmt_ip'] ?? '');

if (!$onu_id) { echo json_encode(['success'=>false,'message'=>'ONU ID tidak valid']); exit; }
if (!in_array($mode, ['Inactive','DHCP','Static'])) { echo json_encode(['success'=>false,'message'=>'Mode tidak valid']); exit; }
if ($mode === 'Static' && $ip && !filter_var($ip, FILTER_VALIDATE_IP)) { echo json_encode(['success'=>false,'message'=>'IP address tidak valid']); exit; }

$stmt = $pdo->prepare("UPDATE onus SET mgmt_ip_mode=?, mgmt_vlan=?, mgmt_ip=? WHERE id=?");
$stmt->execute([$mode, $vlan, $mode === 'Static' ? $ip : null, $onu_id]);

echo json_encode(['success'=>true]);
