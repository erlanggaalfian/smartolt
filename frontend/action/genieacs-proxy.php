<?php
// Proxy GenieACS NBI — akses dari JS client tanpa expose NBI langsung
session_start();
if (!isset($_SESSION['smartolt_role'])) { http_response_code(403); echo 'Forbidden'; exit; }

$serial = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['serial'] ?? '');
if (!$serial) { http_response_code(400); echo 'Missing serial'; exit; }

$nbi_base = 'http://127.0.0.1:7559';
$path = "/devices/{$serial}";

$ch = curl_init($nbi_base . $path);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_HTTPHEADER => ['Accept: application/json'],
]);
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

header('Content-Type: application/json');
http_response_code($http_code);
echo $response ?: '{"error":"No response from GenieACS"}';
