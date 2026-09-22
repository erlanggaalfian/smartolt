<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../backend/db.php';
if (!isset($_SESSION['smartolt_role'])) { echo json_encode(['success'=>false,'message'=>'Akses ditolak']); exit; }

$onu_id = (int)($_POST['onu_id'] ?? 0);
$profile = trim($_POST['tr609_profile'] ?? 'Default');

if (!$onu_id) { echo json_encode(['success'=>false,'message'=>'ONU ID tidak valid']); exit; }

$stmt = $pdo->prepare("UPDATE onus SET tr069_profile=? WHERE id=?");
$stmt->execute([$profile ?: 'Default', $onu_id]);

echo json_encode(['success'=>true]);
