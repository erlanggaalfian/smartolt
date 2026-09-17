<?php
/**
 * API: Get splitters as JSON for dropdown
 */
require_once __DIR__ . '/../../backend/db.php';

if (!isset($_SESSION['smartolt_role'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Akses ditolak!']);
    exit;
}

header('Content-Type: application/json');

$zone = $_GET['zone'] ?? '';

$sql = "SELECT s.id, s.name, s.zone, s.capacity,
               COALESCE(cnt.c, 0) AS used
        FROM splitters s
        LEFT JOIN (SELECT splitter, COUNT(*) AS c FROM onus GROUP BY splitter) cnt ON cnt.splitter = s.name";
if ($zone) {
    $sql .= " WHERE s.zone = ? ORDER BY s.name";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$zone]);
} else {
    $stmt = $pdo->query($sql . " ORDER BY s.zone, s.name");
}
$splitters = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode(['success' => true, 'data' => $splitters]);
