<?php
// ==============================================================================
# Action Handler: Test OLT Connection (JSON Response)
// ==============================================================================
header('Content-Type: application/json');
require_once __DIR__ . '/../../backend/db.php';
require_once __DIR__ . '/../../backend/driver.php';

if (!isset($_SESSION['smartolt_role']) || $_SESSION['smartolt_role'] !== 'superadmin') {
    echo json_encode(['success' => false, 'message' => 'Akses ditolak! Anda tidak memiliki izin untuk menguji OLT.']);
    exit;
}

set_time_limit(0); // SSH connection test can exceed 30s default
session_write_close(); // Lepas session lock agar request lain bisa berjalan paralel

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID OLT tidak valid.']);
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM olts WHERE id = ?");
$stmt->execute([$id]);
$olt = $stmt->fetch();

if (!$olt) {
    echo json_encode(['success' => false, 'message' => 'OLT tidak ditemukan.']);
    exit;
}

$res = check_olt_connection($olt);
echo json_encode($res);
exit;
