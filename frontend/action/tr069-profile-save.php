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

if (!empty($body['id'])) {
    // Edit — tolak jika default
    $stmt = $pdo->prepare("SELECT is_default FROM tr069_profiles WHERE id=?");
    $stmt->execute([$body['id']]);
    $row = $stmt->fetch();
    if (!$row || $row['is_default']) { echo json_encode(['success'=>false,'message'=>'Profil default tidak bisa diedit']); exit; }

    $stmt = $pdo->prepare("UPDATE tr069_profiles SET name=?, acs_url=?, description=?, updated_at=NOW() WHERE id=?");
    $stmt->execute([$body['name'], $acs_url, $body['description'] ?? '', $body['id']]);
} else {
    // Add
    $stmt = $pdo->prepare("INSERT INTO tr069_profiles (name, acs_url, description) VALUES (?,?,?)");
    $stmt->execute([$body['name'], $acs_url, $body['description'] ?? '']);
}
echo json_encode(['success'=>true]);
