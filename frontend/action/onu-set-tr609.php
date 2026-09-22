<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../backend/db.php';
require_once __DIR__ . '/../../backend/driver.php';
if (!isset($_SESSION['smartolt_role'])) { echo json_encode(['success'=>false,'message'=>'Akses ditolak']); exit; }

$onu_id = (int)($_POST['onu_id'] ?? 0);
$profile_name = trim($_POST['tr609_profile'] ?? 'ACS-Smartolt');

if (!$onu_id) { echo json_encode(['success'=>false,'message'=>'ONU ID tidak valid']); exit; }

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

if ($profile_name === 'Nonaktif') {
    // Hapus tr069-mgmt dari ONU
    $result = call_driver($olt, 'set_tr069_profile', [
        $row['pon_port'], (int)$row['onu_id'],
        '', '', '',  // acs_url kosong = nonaktif
    ]);
} else {
    if ($profile_name === 'ACS-Smartolt') {
        $profile = $pdo->query("SELECT * FROM tr069_profiles WHERE is_default=1 LIMIT 1")->fetch();
    } else {
        $stmt2 = $pdo->prepare("SELECT * FROM tr069_profiles WHERE name=? LIMIT 1");
        $stmt2->execute([$profile_name]);
        $profile = $stmt2->fetch();
    }
    if (!$profile) { echo json_encode(['success'=>false,'message'=>'Profil tidak ditemukan']); exit; }

    $result = call_driver($olt, 'set_tr069_profile', [
        $row['pon_port'], (int)$row['onu_id'],
        $profile['acs_url'],
        $profile['acs_username'] ?? '',
        $profile['acs_password'] ?? '',
    ]);
}

// Simpan ke DB
$pdo->prepare("UPDATE onus SET tr069_profile=? WHERE id=?")->execute([$profile_name, $onu_id]);

echo json_encode([
    'success' => $result['success'] ?? false,
    'message' => $result['message'] ?? 'Gagal push ke OLT',
    'log' => $result['log'] ?? ''
]);
