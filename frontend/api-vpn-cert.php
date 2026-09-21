<?php
require_once __DIR__ . '/../backend/db.php';

$token = trim(file_get_contents('/opt/genieacs/certs/.api-token') ?: '');
$hasSession = isset($_SESSION["smartolt_role"]) && $_SESSION["smartolt_role"] === "superadmin";
$hasToken = ($_GET['token'] ?? '') === $token;

if (!$hasSession && !$hasToken) {
    http_response_code(403);
    die('Access denied');
}

$lockFile = '/opt/genieacs/certs/.cert-access';
$expires = 0;
if (file_exists($lockFile)) {
    $expires = (int)file_get_contents($lockFile);
}

// Token bypass lock (MikroTik fetch after admin enables)
if (!$hasToken && time() > $expires) {
    http_response_code(403);
    die('Cert download disabled.');
}

$allowed_static = ['ca-Erlangga-SmartOLT.crt'];
$file = $_GET['file'] ?? '';

$is_valid = in_array($file, $allowed_static) || preg_match('/^[a-zA-Z0-9_-]+\.(crt|key)$/', $file);
if (!$is_valid) {
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
