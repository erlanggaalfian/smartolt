<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../backend/db.php';
if ($_SESSION['smartolt_role'] !== 'superadmin') { echo json_encode(['success'=>false,'message'=>'Akses ditolak']); exit; }

$body = json_decode(file_get_contents('php://input'), true);
if (!$body || empty($body['id'])) { echo json_encode(['success'=>false,'message'=>'ID wajib']); exit; }

$stmt = $pdo->prepare("SELECT is_default FROM tr069_profiles WHERE id=?");
$stmt->execute([$body['id']]);
$row = $stmt->fetch();
if (!$row) { echo json_encode(['success'=>false,'message'=>'Profil tidak ditemukan']); exit; }
if ($row['is_default']) { echo json_encode(['success'=>false,'message'=>'Profil default tidak bisa dihapus']); exit; }

$pdo->prepare("DELETE FROM tr069_profiles WHERE id=?")->execute([$body['id']]);
echo json_encode(['success'=>true]);
