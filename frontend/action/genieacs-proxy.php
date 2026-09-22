<?php
// Proxy GenieACS NBI — akses dari JS client tanpa expose NBI langsung
session_start();
if (!isset($_SESSION['smartolt_role'])) { http_response_code(403); echo 'Forbidden'; exit; }

$serial = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['serial'] ?? '');
if (!$serial) { http_response_code(400); echo '{"error":"Missing serial"}'; exit; }

$nbi = 'http://127.0.0.1:7559';
header('Content-Type: application/json');

// Coba langsung by ID dulu (kalau user kirim full GenieACS ID)
$ch = curl_init($nbi . '/devices/' . urlencode($serial));
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_HTTPHEADER => ['Accept: application/json']]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($code === 200 && $resp && ($data = json_decode($resp, true)) && !empty($data['_id'])) {
    echo json_encode($data); exit;
}

// Fallback: query by SerialNumber (TR-069 root bisa InternetGatewayDevice atau Device)
foreach (['InternetGatewayDevice', 'Device'] as $root) {
    $query = json_encode(["{$root}.DeviceInfo.SerialNumber._value" => $serial]);
    $ch = curl_init($nbi . '/devices/?query=' . urlencode($query));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_HTTPHEADER => ['Accept: application/json']]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code === 200 && $resp) {
        $arr = json_decode($resp, true);
        if (is_array($arr) && !empty($arr[0]['_id'])) {
            echo json_encode($arr[0]); exit;
        }
    }
}

echo '{"error":"Device not found in GenieACS"}';
