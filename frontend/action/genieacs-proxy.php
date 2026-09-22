<?php
session_start();
if (!isset($_SESSION['smartolt_role'])) { http_response_code(403); echo 'Forbidden'; exit; }

$nbi = 'http://127.0.0.1:7559';
header('Content-Type: application/json');

// POST: add provision task
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    $serial = preg_replace('/[^a-zA-Z0-9_-]/', '', $body['serial'] ?? '');
    $provision = preg_replace('/[^a-zA-Z0-9_.-]/', '', $body['provision'] ?? '');
    if (!$serial || !$provision) { echo '{"error":"Missing serial or provision"}'; exit; }

    // Find device ID
    $devId = null;
    // ... reuse lookup logic
    $ch = curl_init($nbi . '/devices/?query=' . urlencode(json_encode(['_id' => ['$regex' => $serial]])) . '&projection=_id');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
    $resp = curl_exec($ch); curl_close($ch);
    if ($resp) { $arr = json_decode($resp, true); if (!empty($arr[0]['_id'])) $devId = $arr[0]['_id']; }

    if (!$devId) { echo '{"error":"Device not found"}'; exit; }

    // Create provision task
    $task = json_encode(['name' => 'provision', 'device' => $devId, 'provision' => $provision]);
    $ch = curl_init($nbi . '/tasks/' . urlencode($devId));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $task,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json']
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code >= 200 && $code < 300) { echo '{"success":true}'; } else { echo '{"error":"' . addslashes($resp ?: 'Task failed') . '"}'; }
    exit;
}

// GET: device lookup
$serial = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['serial'] ?? '');
if (!$serial) { http_response_code(400); echo '{"error":"Missing serial"}'; exit; }

// Huawei: GPON serial HWTCxxxx → TR-069 serial 48575443xxxx (hex encode ASCII prefix)
$alt_serial = '';
if (preg_match('/^HWTC([A-F0-9]+)$/i', $serial, $m)) {
    $alt_serial = bin2hex('HWTC') . strtoupper($m[1]);
}
$serials = array_filter(array_unique([$serial, $alt_serial]));

// Try direct ID + query by SerialNumber for each serial variant
foreach ($serials as $s) {
    // Direct ID lookup
    $ch = curl_init($nbi . '/devices/' . urlencode($s));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_HTTPHEADER => ['Accept: application/json']]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code === 200 && $resp && ($data = json_decode($resp, true)) && !empty($data['_id'])) {
        echo json_encode($data); exit;
    }

    // Query by SerialNumber
    foreach (['InternetGatewayDevice', 'Device'] as $root) {
        $query = json_encode(["{$root}.DeviceInfo.SerialNumber._value" => $s]);
        $ch = curl_init($nbi . '/devices/?query=' . urlencode($query));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_HTTPHEADER => ['Accept: application/json']]);
        $resp = curl_exec($ch);
        curl_close($ch);
        if ($resp) {
            $arr = json_decode($resp, true);
            if (is_array($arr) && !empty($arr[0]['_id'])) {
                echo json_encode($arr[0]); exit;
            }
        }
    }
}

// Last resort: scan all device IDs for suffix match
$suffix = substr($serial, -8);
$ch = curl_init($nbi . '/devices/?projection=_id');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_HTTPHEADER => ['Accept: application/json']]);
$resp = curl_exec($ch);
curl_close($ch);
if ($resp) {
    $all = json_decode($resp, true);
    if (is_array($all)) {
        foreach ($all as $dev) {
            if (stripos($dev['_id'] ?? '', $suffix) !== false) {
                $ch2 = curl_init($nbi . '/devices/' . urlencode($dev['_id']));
                curl_setopt_array($ch2, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
                $full = curl_exec($ch2);
                curl_close($ch2);
                if ($full) { echo $full; exit; }
            }
        }
    }
}

echo '{"error":"Device not found in GenieACS"}';
