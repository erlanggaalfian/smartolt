<?php
session_start();
if (!isset($_SESSION['smartolt_role'])) { http_response_code(403); echo 'Forbidden'; exit; }
require_once __DIR__ . '/../../backend/genieacs.php';
require_once __DIR__ . '/../../backend/db.php';

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

    // Auto-sync field Mode ONU/Mode setup WAN/Username-Password PPPoE di halaman
    // detail (kolom DB onus.*) dari data PPP Interface TR-069 yang barusan dibaca —
    // supaya user tidak perlu buka modal manual isi ulang data yang device sudah punya.
    // TIDAK push balik ke device (device tetap sumber kebenaran, DB cuma cermin tampilan).
    if (isset($body['sync_ppp_to_db'])) {
        $username = (string)($body['username'] ?? '');
        $password = (string)($body['password'] ?? '');
        if ($username === '') { echo json_encode(['success' => false, 'message' => 'Username kosong.']); exit; }
        $stmt = $pdo->prepare("SELECT id, onu_mode, wan_mode, pppoe_username, pppoe_password, config_method FROM onus WHERE serial_number = ? LIMIT 1");
        $stmt->execute([$serial]);
        $row = $stmt->fetch();
        if (!$row) { echo json_encode(['success' => false, 'message' => 'ONU tidak ditemukan di database.']); exit; }
        if (($row['config_method'] ?? '') !== 'TR069') {
            // Guard: ONU config_method=OMCI (WAN dikelola CLI OLT) TIDAK boleh disentuh walau
            // device kebetulan melapor PPP Interface di TR-069 (bisa monitoring-only) -- sync CLI
            // OLT akan tetap menimpa wan_mode balik, auto-sync ini malah bikin data oscillating.
            echo json_encode(['success' => false, 'message' => 'Dilewati: config_method bukan TR069.', 'changed' => false]); exit;
        }
        // Skip kalau data DB sudah sama (hindari write sia-sia tiap refresh/reload page).
        if ($row['onu_mode'] === 'Routing' && $row['wan_mode'] === 'PPPoE'
            && $row['pppoe_username'] === $username && ($password === '' || $row['pppoe_password'] === $password)) {
            echo json_encode(['success' => true, 'message' => 'Sudah sinkron.', 'changed' => false]); exit;
        }
        $upd = $pdo->prepare("UPDATE onus SET onu_mode = 'Routing', wan_mode = 'PPPoE', pppoe_username = ?, pppoe_password = COALESCE(NULLIF(?, ''), pppoe_password), updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $upd->execute([$username, $password, $row['id']]);
        echo json_encode(['success' => true, 'message' => 'Mode ONU/WAN/PPPoE disinkronkan dari TR-069.', 'changed' => true]); exit;
    }

    if (isset($body['edit_ip'])) {
        $wanPath = preg_replace('/[^a-zA-Z0-9_.]/', '', (string)($body['wan_path'] ?? ''));
        $fields = [
            'ip_address' => preg_replace('/[^0-9.]/', '', (string)($body['ip_address'] ?? '')),
            'subnet_mask' => preg_replace('/[^0-9.]/', '', (string)($body['subnet_mask'] ?? '')),
            'gateway' => preg_replace('/[^0-9.]/', '', (string)($body['gateway'] ?? '')),
            'dns' => preg_replace('/[^0-9.,]/', '', (string)($body['dns'] ?? '')),
            'mtu' => preg_replace('/[^0-9]/', '', (string)($body['mtu'] ?? '')),
            'nat' => (string)($body['nat'] ?? ''),
        ];
        echo json_encode(genieacs_edit_ip_params($serial, $wanPath, $fields)); exit;
    }

    if (!empty($body['ip_remove'])) {
        $wanPath = preg_replace('/[^a-zA-Z0-9_.]/', '', (string)($body['wan_path'] ?? ''));
        echo json_encode(genieacs_ip_remove($serial, $wanPath)); exit;
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

    if (isset($body['edit_user_interface'])) {
        $fields = [
            'ssh' => (string)($body['ssh'] ?? ''),
            'telnet' => (string)($body['telnet'] ?? ''),
            'telnet_port' => preg_replace('/[^0-9]/', '', (string)($body['telnet_port'] ?? '')),
            'web_user' => (string)($body['web_user'] ?? ''),
            'web_pass' => (string)($body['web_pass'] ?? ''),
        ];
        $result = genieacs_edit_user_interface_params($serial, $fields);
        echo json_encode($result); exit;
    }

    if (isset($body['edit_voice_line'])) {
        $lineNum = (int)($body['line_num'] ?? 0);
        if (!$lineNum) { echo '{"success":false,"message":"line_num wajib diisi"}'; exit; }
        $fields = [
            'enable' => (string)($body['enable'] ?? ''),
            'directory_number' => (string)($body['directory_number'] ?? ''),
            'sip_user' => (string)($body['sip_user'] ?? ''),
            'sip_pass' => (string)($body['sip_pass'] ?? ''),
            'sip_uri' => (string)($body['sip_uri'] ?? ''),
        ];
        $result = genieacs_edit_voice_line_params($serial, $lineNum, $fields);
        echo json_encode($result); exit;
    }

    if (isset($body['edit_generic'])) {
        $items = is_array($body['items'] ?? null) ? $body['items'] : [];
        $result = genieacs_edit_generic_params($serial, $items);
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

// Password PPPoE TIDAK PERNAH dibalikin device via TR-069 (write-only field, standar
// keamanan CWMP) — GenieACS akan selalu kasih string kosong. Satu-satunya sumber sah
// adalah value yang KITA sendiri kirim waktu push WAN (tersimpan di DB kita).
$db_pppoe_password = null;
require_once __DIR__ . '/../../backend/db.php';
$stmt = $pdo->prepare('SELECT pppoe_password FROM onus WHERE serial_number = ? LIMIT 1');
$stmt->execute([$serial]);
$db_pppoe_password = $stmt->fetchColumn() ?: null;
function emit_device(array $data, ?string $dbPassword): void {
    if ($dbPassword !== null && is_array($data['InternetGatewayDevice']['WANDevice'] ?? null)) {
        foreach ($data['InternetGatewayDevice']['WANDevice'] as &$wanDev) {
            if (!is_array($wanDev['WANConnectionDevice'] ?? null)) { continue; }
            foreach ($wanDev['WANConnectionDevice'] as &$connDev) {
                if (!is_array($connDev['WANPPPConnection'] ?? null)) { continue; }
                foreach ($connDev['WANPPPConnection'] as &$ppp) {
                    if (!is_array($ppp)) { continue; }
                    if (!is_array($ppp['Password'] ?? null)) { $ppp['Password'] = []; }
                    if (($ppp['Password']['_value'] ?? '') === '') {
                        $ppp['Password']['_value'] = $dbPassword;
                        $ppp['Password']['_from_db'] = true; // tanda ke frontend: ini bukan dari device
                    }
                }
            }
        }
    }
    echo json_encode($data); exit;
}

// Try direct ID + query by SerialNumber for each serial variant
foreach ($serials as $s) {
    // Direct ID lookup
    $ch = curl_init($nbi . '/devices/' . urlencode($s));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_HTTPHEADER => ['Accept: application/json']]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code === 200 && $resp && ($data = json_decode($resp, true)) && !empty($data['_id'])) {
        emit_device($data, $db_pppoe_password);
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
                emit_device($arr[0], $db_pppoe_password);
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
                if ($full && ($fullData = json_decode($full, true))) { emit_device($fullData, $db_pppoe_password); }
            }
        }
    }
}

echo '{"error":"Device not found in GenieACS"}';
