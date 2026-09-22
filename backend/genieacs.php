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

/**
 * Resolve GenieACS device _id dari serial ONU. Coba: ID langsung, query SerialNumber,
 * lalu Huawei GPON→TR-069 hex convert (HWTCxxxx → 48575443xxxx), lalu suffix-match fallback.
 */
function genieacs_find_device_id(string $serial): ?string {
    $serial = preg_replace('/[^a-zA-Z0-9_-]/', '', $serial);
    if (!$serial) return null;

    $alt_serial = '';
    if (preg_match('/^HWTC([A-F0-9]+)$/i', $serial, $m)) {
        $alt_serial = bin2hex('HWTC') . strtoupper($m[1]);
    }
    $serials = array_filter(array_unique([$serial, $alt_serial]));

    foreach ($serials as $s) {
        $data = genieacs_request('GET', '/devices/' . rawurlencode($s));
        if (is_array($data) && !empty($data['_id'])) return $data['_id'];

        foreach (['InternetGatewayDevice', 'Device'] as $root) {
            $query = urlencode(json_encode(["{$root}.DeviceInfo.SerialNumber._value" => $s]));
            $arr = genieacs_request('GET', "/devices/?query={$query}&projection=_id");
            if (is_array($arr) && !empty($arr[0]['_id'])) return $arr[0]['_id'];
        }
    }

    // Last resort: suffix match against all device IDs
    $suffix = substr($serial, -8);
    $all = genieacs_request('GET', '/devices/?projection=_id');
    if (is_array($all)) {
        foreach ($all as $dev) {
            if (stripos($dev['_id'] ?? '', $suffix) !== false) return $dev['_id'];
        }
    }
    return null;
}

/**
 * Push konfigurasi WAN (PPPoE/DHCP/Static) ke ONU via TR-069 (GenieACS setParameterValues),
 * dipakai sebagai pengganti CLI OLT ketika config_method ONU = 'TR069'.
 * Index WANDevice/WANConnectionDevice di-hardcode ke 1.1 (topologi paling umum).
 * ponytail: index hardcoded 1.1, upgrade ke auto-detect index kalau ada ONU dengan
 * struktur WANDevice/WANConnectionDevice selain 1 (belum ditemukan kasusnya).
 */
function genieacs_push_wan(string $serial, string $wan_mode, array $wan): array {
    $deviceId = genieacs_find_device_id($serial);
    if (!$deviceId) {
        return ['success' => false, 'message' => 'Device tidak ditemukan di GenieACS.'];
    }

    $base = 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1';
    $params = [];

    if ($wan_mode === 'PPPoE') {
        $params["{$base}.WANPPPConnection.1.Username"] = [$wan['pppoe_username'] ?? '', 'xsd:string'];
        $params["{$base}.WANPPPConnection.1.Password"] = [$wan['pppoe_password'] ?? '', 'xsd:string'];
        $params["{$base}.WANPPPConnection.1.ConnectionType"] = ['IP_Routed', 'xsd:string'];
        $params["{$base}.WANPPPConnection.1.Enable"] = [true, 'xsd:boolean'];
    } elseif ($wan_mode === 'Static') {
        $params["{$base}.WANIPConnection.1.AddressingType"] = ['Static', 'xsd:string'];
        $params["{$base}.WANIPConnection.1.ExternalIPAddress"] = [$wan['static_ip'] ?? '', 'xsd:string'];
        $params["{$base}.WANIPConnection.1.SubnetMask"] = [$wan['static_netmask'] ?? '', 'xsd:string'];
        $params["{$base}.WANIPConnection.1.DefaultGateway"] = [$wan['static_gateway'] ?? '', 'xsd:string'];
        $dns = trim(($wan['static_dns_primary'] ?? '') . ',' . ($wan['static_dns_secondary'] ?? ''), ',');
        $params["{$base}.WANIPConnection.1.DNSServers"] = [$dns, 'xsd:string'];
        $params["{$base}.WANIPConnection.1.Enable"] = [true, 'xsd:boolean'];
    } elseif ($wan_mode === 'DHCP') {
        $params["{$base}.WANIPConnection.1.AddressingType"] = ['DHCP', 'xsd:string'];
        $params["{$base}.WANIPConnection.1.Enable"] = [true, 'xsd:boolean'];
    } else {
        // 'Setup via ONU webpage' — tidak ada parameter WAN yang dipush.
        return ['success' => true, 'message' => 'Mode "Setup via ONU webpage" — tidak ada perubahan WAN dikirim via TR-069.'];
    }

    $result = genieacs_set_params($deviceId, $params);
    if ($result === null) {
        return ['success' => false, 'message' => 'Gagal mengirim task TR-069 ke GenieACS (request gagal/timeout).'];
    }
    return ['success' => true, 'message' => 'Konfigurasi WAN berhasil dikirim via TR-069.'];
}
