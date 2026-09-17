<?php
/**
 * API: Get ONU types as JSON for dropdown
 */
require_once __DIR__ . '/../../backend/db.php';

if (!isset($_SESSION['smartolt_role'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Akses ditolak!']);
    exit;
}

header('Content-Type: application/json');

$types = $pdo->query("SELECT id, name, pon_type, capability FROM onu_types ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
echo json_encode(['success' => true, 'data' => $types]);
