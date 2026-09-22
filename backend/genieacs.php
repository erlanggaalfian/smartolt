<?php
// ==============================================================================
// SmartOLT Backend - GenieACS NBI Client Helper
// Lokasi: /backend/genieacs.php
// Semua akses ke GenieACS NBI wajib lewat sini (server-side only — NBI tanpa
// auth bawaan / CVE-2025-56015, jangan pernah expose NBI URL ke browser).
// ==============================================================================

define('GENIEACS_NBI_URL', 'http://127.0.0.1:7559');

/** Low-level HTTP call ke NBI. Return decoded JSON atau null kalau gagal. */
function genieacs_request(string $method, string $path, $body = null, int $timeout = 10) {
    $ch = curl_init(GENIEACS_NBI_URL . $path);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_TIMEOUT => $timeout,
    ];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = is_string($body) ? $body : json_encode($body);
        $opts[CURLOPT_HTTPHEADER] = ['Content-Type: application/json'];
    }
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($resp === false || $code >= 400) return null;
    $json = json_decode($resp, true);
    return $json ?? $resp;
}

/** List semua device ZTE/Huawei terdaftar di ACS. */
function genieacs_list_devices(): array {
    $q = urlencode(json_encode([]));
    $proj = 'InternetGatewayDevice.DeviceInfo.Manufacturer,InternetGatewayDevice.DeviceInfo.ModelName,InternetGatewayDevice.DeviceInfo.ProductClass,InternetGatewayDevice.DeviceInfo.SerialNumber,InternetGatewayDevice.DeviceInfo.SoftwareVersion,VirtualParameters.OpticalPower,_lastInform,_registered';
    $data = genieacs_request('GET', "/devices/?query={$q}&projection={$proj}");
    return is_array($data) ? $data : [];
}

/** Detail 1 device by ID (full parameter tree). */
function genieacs_get_device(string $id): ?array {
    $q = urlencode(json_encode(['_id' => $id]));
    $data = genieacs_request('GET', "/devices/?query={$q}");
    return $data[0] ?? null;
}

/** Deteksi vendor dari ProductClass — dipakai buat resolve parameter path. */
function genieacs_detect_vendor(string $manufacturer, string $productClass): string {
    $m = strtoupper($manufacturer);
    if (strpos($m, 'ZTE') !== false) return 'zte';
    if (strpos($m, 'HUAWEI') !== false) return 'huawei';
    return 'unknown';
}

/**
 * Parameter path map per kategori & vendor (TR-098, root InternetGatewayDevice).
 * Wildcard [n] diganti index instance (default 1) saat resolve.
 */
function genieacs_param_map(): array {
    return [
        'wan' => [
            'ip' => 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.ExternalIPAddress',
            'ip_ppp' => 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.ExternalIPAddress',
            'ppp_username' => 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.Username',
            'ppp_password' => 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.Password',
            'connection_type' => 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.ConnectionType',
            'mac' => 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.MACAddress',
        ],
        'lan' => [
            'ip' => 'InternetGatewayDevice.LANDevice.1.LANHostConfigManagement.IPInterface.1.IPInterfaceIPAddress',
            'subnet' => 'InternetGatewayDevice.LANDevice.1.LANHostConfigManagement.IPInterface.1.IPInterfaceSubnetMask',
            'dhcp_enable' => 'InternetGatewayDevice.LANDevice.1.LANHostConfigManagement.DHCPServerEnable',
            'dhcp_start' => 'InternetGatewayDevice.LANDevice.1.LANHostConfigManagement.MinAddress',
            'dhcp_end' => 'InternetGatewayDevice.LANDevice.1.LANHostConfigManagement.MaxAddress',
        ],
        'wlan' => [
            'ssid' => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID',
            'enable' => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.Enable',
            'channel' => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.Channel',
            'standard' => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.Standard',
            'ssid_broadcast' => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSIDAdvertisementEnabled',
        ],
        'security' => [
            'auth_mode' => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.BeaconType',
            'encryption' => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.WPAEncryptionModes',
            'passphrase' => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.PreSharedKey.1.KeyPassphrase',
        ],
        'optical' => [
            'rx_zte' => 'InternetGatewayDevice.WANDevice.1.X_ZTE-COM_WANPONInterfaceConfig.RXPower',
            'tx_zte' => 'InternetGatewayDevice.WANDevice.1.X_ZTE-COM_WANPONInterfaceConfig.TXPower',
            'rx_huawei' => 'InternetGatewayDevice.WANDevice.1.X_GponInterafceConfig.RXPower',
            'tx_huawei' => 'InternetGatewayDevice.WANDevice.1.X_GponInterafceConfig.TXPower',
        ],
    ];
}

/** Ambil value parameter dari device tree (nested path dot-notation). */
function genieacs_tree_get(array $device, string $path) {
    $parts = explode('.', $path);
    $node = $device;
    foreach ($parts as $p) {
        if (!isset($node[$p])) return null;
        $node = $node[$p];
    }
    return $node['_value'] ?? null;
}

/** Trigger connection request + queue setParameterValues, apply saat device connect.
 * Timeout pendek (4s) — task selalu ke-insert ke DB duluan sebelum connection
 * attempt, jadi request lambat/timeout tidak menahan UI lama (device sering
 * offline sebelum online pertama kali). */
function genieacs_set_params(string $deviceId, array $paramValues): ?array {
    // $paramValues: ['path' => ['value', 'xsd:string']]
    $list = [];
    foreach ($paramValues as $path => $val) {
        $list[] = [$path, $val[0], $val[1] ?? 'xsd:string'];
    }
    $task = ['name' => 'setParameterValues', 'parameterValues' => $list];
    return genieacs_request('POST', "/devices/" . rawurlencode($deviceId) . "/tasks?connection_request", $task, 4);
}

/** Health check — cek 3 service (CWMP/NBI/FS) dan MongoDB. */
function genieacs_health_check(): array {
    $services = [
        'cwmp' => ['port' => 7547, 'name' => 'CWMP'],
        'nbi'  => ['port' => 7559, 'name' => 'NBI API'],
        'fs'   => ['port' => 7567, 'name' => 'File Server'],
    ];
    $result = ['services' => [], 'mongodb' => false, 'device_count' => 0, 'online_24h' => 0];
    foreach ($services as $key => $svc) {
        $ch = curl_init("http://127.0.0.1:{$svc['port']}/");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3, CURLOPT_NOBODY => true]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        $result['services'][$key] = [
            'name' => $svc['name'],
            'port' => $svc['port'],
            'status' => ($code > 0) ? 'running' : 'down',
            'http_code' => $code,
            'error' => $err ?: null,
        ];
    }
    // Cek MongoDB + hitung device
    $devices = genieacs_list_devices();
    $result['mongodb'] = is_array($devices);
    $result['device_count'] = count($devices);
    $now = time();
    foreach ($devices as $d) {
        $last = $d['_lastInform'] ?? null;
        if ($last && (strtotime($last) > ($now - 86400))) $result['online_24h']++;
    }
    return $result;
}

/** Ambil fault / provisioning tasks dari GenieACS (recent errors). */
function genieacs_get_faults(int $limit = 50): array {
    $data = genieacs_request('GET', "/tasks/?limit={$limit}");
    return is_array($data) ? $data : [];
}

/** Ambil semua profil dari DB. */
function tr069_get_profiles(PDO $pdo): array {
    return $pdo->query("SELECT * FROM tr069_profiles ORDER BY is_default DESC, name ASC")->fetchAll(PDO::FETCH_ASSOC);
}

/** Trigger refresh (re-read) parameter tertentu dari device. Timeout pendek. */
function genieacs_refresh(string $deviceId, string $objectName = ''): ?array {
    $task = ['name' => 'refreshObject', 'objectName' => $objectName];
    return genieacs_request('POST', "/devices/" . rawurlencode($deviceId) . "/tasks?connection_request", $task, 4);
}
