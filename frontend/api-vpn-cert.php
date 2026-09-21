<?php
require_once __DIR__ . '/../backend/db.php';

if (!isset($_SESSION["smartolt_role"]) || $_SESSION["smartolt_role"] !== "superadmin") {
    http_response_code(403);
    die('Access denied');
}

$lockFile = '/opt/genieacs/certs/.cert-access';
$expires = 0;
if (file_exists($lockFile)) {
    $expires = (int)file_get_contents($lockFile);
}
if (time() > $expires) {
    http_response_code(403);
    die('Cert download disabled. Enable dari halaman VPN Tunnels.');
}

$allowed = ['ca-Erlangga-SmartOLT.crt', 'VPN-Erlangga-SmartOLT.crt', 'VPN-Erlangga-SmartOLT.key'];
$file = $_GET['file'] ?? '';

if (!in_array($file, $allowed)) {
    http_response_code(400);
    die('Invalid file');
}

$path = '/opt/genieacs/certs/' . $file;
if (!file_exists($path)) {
    http_response_code(404);
    die('Not found');
}

header('Content-Type: application/octet-stream');
header("Content-Disposition: attachment; filename=\"{$file}\"");
readfile($path);
