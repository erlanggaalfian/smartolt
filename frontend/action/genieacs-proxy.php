<?php
session_start();
if (!isset($_SESSION['smartolt_role'])) { http_response_code(403); echo 'Forbidden'; exit; }
require_once __DIR__ . '/../../backend/genieacs.php';

$nbi = 'http://127.0.0.1:7559';
header('Content-Type: application/json');

// POST: add provision task, or trigger refresh
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    $serial = preg_replace('/[^a-zA-Z0-9_-]/', '', $body['serial'] ?? '');
    $provision = preg_replace('/[^a-zA-Z0-9_.-]/', '', $body['provision'] ?? '');

    // Find device ID (needed for provision/refresh/edit_ppp/ppp_reset/ppp_remove) —
    // pakai genieacs_find_device_id() (support konversi serial GPON HWTCxxxx -> hex TR069 id),
    // JANGAN reinvent regex _id manual di sini (bug lama: selalu "Device not found").
    $devId = genieacs_find_device_id($serial);
    if (!$devId) { echo '{"error":"Device not found"}'; exit; }

    if (!empty($body['refresh'])) {
        // Trigger a GetParameterValues refresh task with connection request (best-effort, synchronous)
        $task = json_encode(['name' => 'refreshObject', 'objectName' => '']);
        $ch = curl_init($nbi . '/devices/' . urlencode($devId) . '/tasks?connection_request&timeout=15000');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $task,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json']
        ]);
        curl_exec($ch);
        curl_close($ch);
        echo '{"success":true}'; exit;
    }

    if (isset($body['edit_ppp'])) {
        $fields = [
            'vlan' => preg_replace('/[^0-9]/', '', (string)($body['vlan'] ?? '')),
            'max_mru' => preg_replace('/[^0-9]/', '', (string)($body['max_mru'] ?? '')),
            'username' => (string)($body['username'] ?? ''),
            'password' => (string)($body['password'] ?? ''),
            'svc_name' => (string)($body['svc_name'] ?? ''),
            'trigger' => preg_replace('/[^a-zA-Z]/', '', (string)($body['trigger'] ?? '')),
            'nat' => (string)($body['nat'] ?? ''),
            'lcp' => (string)($body['lcp'] ?? ''),
            'conn_name' => (string)($body['conn_name'] ?? ''),
            'mac_clone' => (string)($body['mac_clone'] ?? ''),
            'dmz' => (string)($body['dmz'] ?? ''),
            'dmz_ip' => preg_replace('/[^0-9.]/', '', (string)($body['dmz_ip'] ?? '')),
        ];
        $result = genieacs_edit_ppp_params($serial, $fields);
        echo json_encode($result); exit;
    }

    if (!empty($body['ppp_reset'])) {
        echo json_encode(genieacs_ppp_reset($serial)); exit;
    }

    if (!empty($body['ppp_remove'])) {
        echo json_encode(genieacs_ppp_remove($serial)); exit;
    }

    if (isset($body['edit_wlan'])) {
        $fields = [
            'ssid' => (string)($body['ssid'] ?? ''),
            'enable' => (string)($body['enable'] ?? ''),
            'password' => (string)($body['password'] ?? ''),
            'security' => (string)($body['security'] ?? ''),
            'channel' => preg_replace('/[^0-9]/', '', (string)($body['channel'] ?? '')),
            'auto_channel' => (string)($body['auto_channel'] ?? ''),
            'regulatory_domain' => preg_replace('/[^A-Za-z]/', '', (string)($body['regulatory_domain'] ?? '')),
            'ssid_broadcast' => (string)($body['ssid_broadcast'] ?? ''),
            'tx_power' => preg_replace('/[^0-9]/', '', (string)($body['tx_power'] ?? '')),
        ];
        $wlanIndex = (int)($body['wlan_index'] ?? 1) ?: 1;
        $result = genieacs_edit_wlan_params($serial, $fields, $wlanIndex);
        echo json_encode($result); exit;
    }

    if (!$serial || !$provision) { echo '{"error":"Missing serial or provision"}'; exit; }

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
