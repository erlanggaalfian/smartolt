<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../backend/db.php';
if ($_SESSION['smartolt_role'] !== 'superadmin') { echo json_encode(['success'=>false,'message'=>'Akses ditolak']); exit; }

$body = json_decode(file_get_contents('php://input'), true);
if (!$body || empty($body['name']) || empty($body['acs_url'])) {
    echo json_encode(['success'=>false,'message'=>'Nama dan URL wajib diisi']); exit;
}

$acs_url = rtrim(trim($body['acs_url']), '/');
if (!preg_match('#^https?://#', $acs_url)) { echo json_encode(['success'=>false,'message'=>'URL harus diawali http:// atau https://']); exit; }

$username = trim($body['acs_username'] ?? '');
$password = trim($body['acs_password'] ?? '');
$vlan = $body['mgmt_vlan'] !== '' && $body['mgmt_vlan'] !== null ? (int)$body['mgmt_vlan'] : null;
$priority = isset($body['mgmt_priority']) ? (int)$body['mgmt_priority'] : 2;

if (!empty($body['id'])) {
    // Edit
    $stmt = $pdo->prepare("SELECT is_default FROM tr069_profiles WHERE id=?");
    $stmt->execute([$body['id']]);
    $row = $stmt->fetch();
    if (!$row) { echo json_encode(['success'=>false,'message'=>'Profil tidak ditemukan']); exit; }

    if ($row['is_default']) {
        // Default: hanya izinkan update username/password/vlan/priority
        $stmt = $pdo->prepare("UPDATE tr069_profiles SET acs_username=?, acs_password=?, mgmt_vlan=?, mgmt_priority=?, updated_at=NOW() WHERE id=?");
        $stmt->execute([$username, $password, $vlan, $priority, $body['id']]);
    } else {
        $stmt = $pdo->prepare("UPDATE tr069_profiles SET name=?, acs_url=?, description=?, acs_username=?, acs_password=?, mgmt_vlan=?, mgmt_priority=?, updated_at=NOW() WHERE id=?");
        $stmt->execute([$body['name'], $acs_url, $body['description'] ?? '', $username, $password, $vlan, $priority, $body['id']]);
    }
} else {
    // Add
    $stmt = $pdo->prepare("INSERT INTO tr069_profiles (name, acs_url, description, acs_username, acs_password, mgmt_vlan, mgmt_priority) VALUES (?,?,?,?,?,?,?)");
    $stmt->execute([$body['name'], $acs_url, $body['description'] ?? '', $username, $password, $vlan, $priority]);
}
echo json_encode(['success'=>true]);
