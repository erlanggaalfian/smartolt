<?php
require_once __DIR__ . '/../backend/db.php';

if (!isset($_SESSION["smartolt_role"]) || $_SESSION["smartolt_role"] !== "superadmin") {
    http_response_code(403);
    die('Access denied');
}

header('Content-Type: application/json');
$lockFile = '/opt/genieacs/certs/.cert-access';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'enable') {
        file_put_contents($lockFile, time() + 300); // 5 menit
        echo json_encode(['ok' => true, 'expires' => time() + 300]);
    } elseif ($action === 'disable') {
        file_put_contents($lockFile, 0);
        echo json_encode(['ok' => true, 'expires' => 0]);
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'invalid action']);
    }
} else {
    $expires = 0;
    if (file_exists($lockFile)) $expires = (int)file_get_contents($lockFile);
    echo json_encode(['expires' => $expires, 'active' => time() < $expires]);
}
