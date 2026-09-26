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
 * Ambil IP TR-069 device (host dari ConnectionRequestURL yang device lapor
 * ke ACS saat Inform — ini IP manajemen sebenarnya yang device pakai,
 * sama dengan IP Manajemen SNMP/CLI karena satu jalur VLAN management).
 */
function genieacs_get_tr069_ip(string $deviceId): ?string {
    $device = genieacs_get_device($deviceId);
    $url = $device['InternetGatewayDevice']['ManagementServer']['ConnectionRequestURL']['_value'] ?? null;
    if (!$url) return null;
    $host = parse_url($url, PHP_URL_HOST);
    return $host ?: null;
}


/**
 * Ambil IP eksternal WAN PPPoE dari TR-069 (WANPPPConnection.1.ExternalIPAddress).
 * Dipakai saat config_method=TR069 supaya IP PPPoE tampil dari sumber kebenaran ACS,
 * bukan cache CLI OLT yang tidak lagi disinkronkan.
 */
function genieacs_get_ppp_wan_ip(string $deviceId): ?string {
    $device = genieacs_get_device($deviceId);
    if (!$device) return null;
    $ip = $device['InternetGatewayDevice']['WANDevice']['1']['WANConnectionDevice']['1']['WANPPPConnection']['1']['ExternalIPAddress']['_value'] ?? null;
    if (!$ip || $ip === '0.0.0.0') return null;
    return $ip;
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
function genieacs_set_params(string $deviceId, array $paramValues, int $timeout = 4): ?array {
    // $paramValues: ['path' => ['value', 'xsd:string']]
    $list = [];
    foreach ($paramValues as $path => $val) {
        $list[] = [$path, $val[0], $val[1] ?? 'xsd:string'];
    }
    $task = ['name' => 'setParameterValues', 'parameterValues' => $list];
    return genieacs_request('POST', "/devices/" . rawurlencode($deviceId) . "/tasks?connection_request", $task, $timeout);
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
 * Refresh interfaces via TR-069 (RPC refreshObject scoped ke WANDevice/LANDevice)
 * -- setara tombol "Refresh interfaces" di GenieACS UI, dipanggil dari SmartOLT
 * supaya admin tak perlu buka GenieACS terpisah.
 */
function genieacs_refresh_interfaces(string $serial): array {
    $deviceId = genieacs_find_device_id($serial);
    if (!$deviceId) return ['success' => false, 'message' => 'Device tidak ditemukan di GenieACS.'];
    $result = genieacs_request('POST', "/devices/" . rawurlencode($deviceId) . "/tasks?connection_request",
        ['name' => 'refreshObject', 'objectName' => 'InternetGatewayDevice.WANDevice'], 15);
    if ($result === null) return ['success' => false, 'message' => 'Gagal refresh interfaces -- device mungkin offline/tidak reachable.'];
    return ['success' => true, 'message' => 'Perintah refresh interfaces berhasil dikirim via TR-069.'];
}

/** Reboot device via TR-069 RPC bawaan (task name "reboot"). */
function genieacs_reboot_device(string $serial): array {
    $deviceId = genieacs_find_device_id($serial);
    if (!$deviceId) return ['success' => false, 'message' => 'Device tidak ditemukan di GenieACS.'];
    $result = genieacs_request('POST', "/devices/" . rawurlencode($deviceId) . "/tasks?connection_request",
        ['name' => 'reboot'], 15);
    if ($result === null) return ['success' => false, 'message' => 'Gagal mengirim perintah reboot -- device mungkin offline/tidak reachable.'];
    return ['success' => true, 'message' => 'Perintah reboot berhasil dikirim via TR-069.'];
}

/** Reset ke factory default via TR-069 RPC bawaan (task name "factoryReset"). */
function genieacs_factory_reset_device(string $serial): array {
    $deviceId = genieacs_find_device_id($serial);
    if (!$deviceId) return ['success' => false, 'message' => 'Device tidak ditemukan di GenieACS.'];
    $result = genieacs_request('POST', "/devices/" . rawurlencode($deviceId) . "/tasks?connection_request",
        ['name' => 'factoryReset'], 15);
    if ($result === null) return ['success' => false, 'message' => 'Gagal mengirim perintah factory reset -- device mungkin offline/tidak reachable.'];
    return ['success' => true, 'message' => 'Perintah reset ke factory default berhasil dikirim via TR-069.'];
}

/**
 * Hapus record device dari GenieACS (DELETE /devices/{id}) -- dipanggil saat ONU
 * dihapus/unbind dari SmartOLT supaya tidak ada data "sampah" (SSID/password/WAN lama)
 * nyangkut di ACS kalau SN yang sama dipasang ulang ke pelanggan lain nanti.
 * Device offline juga tetap bisa dihapus (DELETE langsung ke NBI/MongoDB, tidak butuh
 * connection-request ke CPE).
 */
function genieacs_delete_device(string $serial): array {
    $deviceId = genieacs_find_device_id($serial);
    if (!$deviceId) {
        // Tidak ketemu di GenieACS = tidak ada yang perlu dihapus, bukan kegagalan.
        return ['success' => true, 'message' => 'Device tidak ditemukan di GenieACS (tidak ada yang dihapus).'];
    }
    // genieacs_request return null KHUSUS kalau curl gagal / HTTP >=400 -- itu sinyal error asli
    // (beda dari respons sukses kosong yang balik '' bukan null). Pakai itu utk deteksi gagal.
    $result = genieacs_request('DELETE', '/devices/' . rawurlencode($deviceId), null, 10);
    if ($result === null) {
        return ['success' => false, 'message' => "Gagal menghapus device {$deviceId} dari GenieACS (HTTP error / timeout)."];
    }
    return ['success' => true, 'message' => "Device {$deviceId} dihapus dari GenieACS."];
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
function genieacs_edit_ppp_params(string $serial, array $fields): array {
    $deviceId = genieacs_find_device_id($serial);
    if (!$deviceId) {
        return ['success' => false, 'message' => 'Device tidak ditemukan di GenieACS.'];
    }
    $base = 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1';
    $map = [
        'conn_name' => ["{$base}.Name", 'xsd:string', 'string'],
        'vlan' => ["{$base}.X_HW_VLAN", 'xsd:unsignedInt', 'int'],
        'max_mru' => ["{$base}.MaxMRUSize", 'xsd:unsignedInt', 'int'],
        'username' => ["{$base}.Username", 'xsd:string', 'string'],
        'password' => ["{$base}.Password", 'xsd:string', 'string'],
        'svc_name' => ["{$base}.PPPoEServiceName", 'xsd:string', 'string'],
        'trigger' => ["{$base}.ConnectionTrigger", 'xsd:string', 'string'],
        'nat' => ["{$base}.NATEnabled", 'xsd:boolean', 'bool'],
        'lcp' => ["{$base}.PPPLCPEcho", 'xsd:boolean', 'bool'],
        'mac_clone' => ["{$base}.MACAddressOverride", 'xsd:boolean', 'bool'],
        'dmz' => ["{$base}.X_HW_DMZ.Enable", 'xsd:boolean', 'bool'],
        'dmz_ip' => ["{$base}.X_HW_DMZ.HostIPAddress", 'xsd:string', 'string'],
    ];
    // Kirim tiap field sebagai task setParameterValues TERPISAH (bukan 1 batch besar) —
    // device ZTE/Huawei sering reject SELURUH batch (cpeFault 9003 "Invalid arguments")
    // kalau salah SATU parameter di dalamnya tidak didukung firmware, sehingga field lain
    // yang sebenarnya valid ikut gagal tak jelas. Per-field task: 1 gagal tidak menghambat lainnya.
    $sent = [];
    $failed = [];
    foreach ($map as $key => [$path, $type, $cast]) {
        if (!isset($fields[$key]) || $fields[$key] === '') continue;
        $val = $fields[$key];
        if ($cast === 'int') $val = (int)$val;
        elseif ($cast === 'bool') $val = ($val === '1' || $val === 1 || $val === true);
        $result = genieacs_set_params($deviceId, [$path => [$val, $type]], 15);
        if ($result === null) { $failed[] = $key; } else { $sent[] = $key; }
    }
    if (!$sent && !$failed) {
        return ['success' => false, 'message' => 'Tidak ada field untuk diubah.'];
    }
    if ($failed) {
        return ['success' => false, 'message' => 'Sebagian gagal terkirim: ' . implode(', ', $failed) . ($sent ? ' (berhasil: ' . implode(', ', $sent) . ')' : '')];
    }
    return ['success' => true, 'message' => 'Perubahan PPP berhasil dikirim via TR-069 (' . implode(', ', $sent) . ').'];
}

/**
 * Edit parameter WLAN (SSID, password, security, channel, dst) via TR-069.
 * Password WiFi WPA/WPA2 disimpan di KeyPassphrase (bukan PreSharedKey langsung —
 * device auto-derive PSK dari passphrase). Kirim per-field terpisah (lihat alasan
 * di genieacs_edit_ppp_params — hindari cpeFault 9003 saat 1 field ditolak device).
 */
function genieacs_edit_wlan_params(string $serial, array $fields, int $wlanIndex = 1): array {
    $deviceId = genieacs_find_device_id($serial);
    if (!$deviceId) {
        return ['success' => false, 'message' => 'Device tidak ditemukan di GenieACS.'];
    }
    $base = "InternetGatewayDevice.LANDevice.1.WLANConfiguration.{$wlanIndex}";
    $map = [
        'ssid' => ["{$base}.SSID", 'xsd:string', 'string'],
        'enable' => ["{$base}.Enable", 'xsd:boolean', 'bool'],
        'password' => ["{$base}.PreSharedKey.1.KeyPassphrase", 'xsd:string', 'string'],
        'security' => ["{$base}.WPAEncryptionModes", 'xsd:string', 'string'],
        'channel' => ["{$base}.Channel", 'xsd:unsignedInt', 'int'],
        'auto_channel' => ["{$base}.AutoChannelEnable", 'xsd:boolean', 'bool'],
        'regulatory_domain' => ["{$base}.RegulatoryDomain", 'xsd:string', 'string'],
        'ssid_broadcast' => ["{$base}.SSIDAdvertisementEnabled", 'xsd:boolean', 'bool'],
        'tx_power' => ["{$base}.TransmitPower", 'xsd:unsignedInt', 'int'],
        'bandwidth' => ["{$base}.X_HW_HT20", 'xsd:boolean', 'bool'],
        'wireless_mode' => ["{$base}.X_HW_Standard", 'xsd:string', 'string'],
        'max_devices' => ["{$base}.X_HW_AssociateNum", 'xsd:unsignedInt', 'int'],
        'mac_filter' => ["{$base}.MACAddressControlEnabled", 'xsd:boolean', 'bool'],
        'wmm' => ["{$base}.WMMEnable", 'xsd:boolean', 'bool'],
        'vlan' => ["{$base}.X_HW_VLAN", 'xsd:string', 'string'],
    ];
    $sent = [];
    $failed = [];
    foreach ($map as $key => [$path, $type, $cast]) {
        if (!isset($fields[$key]) || $fields[$key] === '') continue;
        $val = $fields[$key];
        if ($cast === 'int') $val = (int)$val;
        elseif ($cast === 'bool') $val = ($val === '1' || $val === 1 || $val === true);
        $result = genieacs_set_params($deviceId, [$path => [$val, $type]], 15);
        if ($result === null) { $failed[] = $key; } else { $sent[] = $key; }
    }
    if (!$sent && !$failed) {
        return ['success' => false, 'message' => 'Tidak ada field untuk diubah.'];
    }
    if ($failed) {
        return ['success' => false, 'message' => 'Sebagian gagal terkirim: ' . implode(', ', $failed) . ($sent ? ' (berhasil: ' . implode(', ', $sent) . ')' : '')];
    }
    return ['success' => true, 'message' => 'Perubahan WiFi berhasil dikirim via TR-069 (' . implode(', ', $sent) . ').'];
}

/**
 * Edit User Interface (CLI SSH/Telnet, Web login) via TR-069. Per-field task
 * terpisah, sama alasan seperti genieacs_edit_ppp_params (hindari cpeFault 9003).
 */
function genieacs_edit_user_interface_params(string $serial, array $fields): array {
    $deviceId = genieacs_find_device_id($serial);
    if (!$deviceId) {
        return ['success' => false, 'message' => 'Device tidak ditemukan di GenieACS.'];
    }
    $map = [
        'ssh' => ['InternetGatewayDevice.UserInterface.X_HW_CLISSHControl.Enable', 'xsd:boolean', 'bool'],
        'telnet' => ['InternetGatewayDevice.UserInterface.X_HW_CLITelnetAccess.Access', 'xsd:boolean', 'bool'],
        'telnet_port' => ['InternetGatewayDevice.UserInterface.X_HW_CLITelnetAccess.TelnetPort', 'xsd:unsignedInt', 'int'],
        'web_user' => ['InternetGatewayDevice.UserInterface.X_HW_WebUserInfo.1.UserName', 'xsd:string', 'string'],
        'web_pass' => ['InternetGatewayDevice.UserInterface.X_HW_WebUserInfo.1.Password', 'xsd:string', 'string'],
    ];
    $sent = [];
    $failed = [];
    foreach ($map as $key => [$path, $type, $cast]) {
        if (!isset($fields[$key]) || $fields[$key] === '') continue;
        $val = $fields[$key];
        if ($cast === 'int') $val = (int)$val;
        elseif ($cast === 'bool') $val = ($val === '1' || $val === 1 || $val === true || $val === 'true');
        $result = genieacs_set_params($deviceId, [$path => [$val, $type]], 15);
        if ($result === null) { $failed[] = $key; } else { $sent[] = $key; }
    }
    if (!$sent && !$failed) {
        return ['success' => false, 'message' => 'Tidak ada field untuk diubah.'];
    }
    if ($failed) {
        return ['success' => false, 'message' => 'Sebagian gagal terkirim: ' . implode(', ', $failed) . ($sent ? ' (berhasil: ' . implode(', ', $sent) . ')' : '')];
    }
    return ['success' => true, 'message' => 'Perubahan User Interface berhasil dikirim via TR-069 (' . implode(', ', $sent) . ').'];
}

/**
 * Edit Voice line (Enable, DirectoryNumber, SIP account) via TR-069. Per-field
 * task terpisah, sama alasan seperti genieacs_edit_ppp_params.
 */
function genieacs_edit_voice_line_params(string $serial, int $lineNum, array $fields, int $voiceServiceIdx = 1, int $voiceProfileIdx = 1): array {
    $deviceId = genieacs_find_device_id($serial);
    if (!$deviceId) {
        return ['success' => false, 'message' => 'Device tidak ditemukan di GenieACS.'];
    }
    $base = "InternetGatewayDevice.Services.VoiceService.{$voiceServiceIdx}.VoiceProfile.{$voiceProfileIdx}.Line.{$lineNum}";
    $map = [
        'enable' => ["{$base}.Enable", 'xsd:string', 'string'],
        'directory_number' => ["{$base}.DirectoryNumber", 'xsd:string', 'string'],
        'sip_user' => ["{$base}.SIP.AuthUserName", 'xsd:string', 'string'],
        'sip_pass' => ["{$base}.SIP.AuthPassword", 'xsd:string', 'string'],
        'sip_uri' => ["{$base}.SIP.URI", 'xsd:string', 'string'],
    ];
    $sent = [];
    $failed = [];
    foreach ($map as $key => [$path, $type, $cast]) {
        if (!isset($fields[$key]) || $fields[$key] === '') continue;
        $result = genieacs_set_params($deviceId, [$path => [$fields[$key], $type]], 15);
        if ($result === null) { $failed[] = $key; } else { $sent[] = $key; }
    }
    if (!$sent && !$failed) {
        return ['success' => false, 'message' => 'Tidak ada field untuk diubah.'];
    }
    if ($failed) {
        return ['success' => false, 'message' => 'Sebagian gagal terkirim: ' . implode(', ', $failed) . ($sent ? ' (berhasil: ' . implode(', ', $sent) . ')' : '')];
    }
    return ['success' => true, 'message' => 'Perubahan Voice Line ' . $lineNum . ' berhasil dikirim via TR-069 (' . implode(', ', $sent) . ').'];
}

/**
 * Edit generic TR-069 parameters (dipakai section tanpa card khusus: Port
 * Forward, IP Interface, LAN DHCP Server, LAN Ports, Security, dst). Terima
 * daftar {path, value, type} dari frontend — path WAJIB diawali
 * "InternetGatewayDevice." (whitelist domain, cegah path arbitrary). Per-field
 * task terpisah, sama alasan seperti genieacs_edit_ppp_params.
 */
function genieacs_edit_generic_params(string $serial, array $items): array {
    $deviceId = genieacs_find_device_id($serial);
    if (!$deviceId) {
        return ['success' => false, 'message' => 'Device tidak ditemukan di GenieACS.'];
    }
    // Gabung SEMUA field jadi 1 task setParameterValues (bukan loop N task terpisah)
    // — loop lama sering silently kehilangan field di tengah jalan (device lambat
    // respond ke connection-request field sebelumnya, request field berikutnya
    // timeout tanpa dilaporkan jelas). Satu task = atomic, lebih cepat, tidak race.
    $paramValues = [];
    $failed = [];
    foreach ($items as $item) {
        $path = (string)($item['path'] ?? '');
        if (strpos($path, 'InternetGatewayDevice.') !== 0) { $failed[] = $path ?: '(path kosong)'; continue; }
        // Guard: segmen terakhir path TR-069 harus nama field (huruf), BUKAN angka
        // index murni — kalau angka, hampir pasti bug frontend lupa suffix nama field
        // (mis. "...WANPPPConnection.1" tanpa ".X_HW_SERVICELIST" di belakangnya).
        // Kirim path begini akan bikin SELURUH task 1-RPC ditolak device (all-or-nothing),
        // jadi field lain yang sudah benar pun ikut gagal — makanya wajib dicegat di sini.
        $lastSegment = substr($path, strrpos($path, '.') + 1);
        if (ctype_digit($lastSegment)) { $failed[] = $path . ' (path tidak lengkap — kurang nama field di akhir)'; continue; }
        $type = (string)($item['type'] ?? 'xsd:string');
        $val = $item['value'] ?? '';
        if ($type === 'xsd:unsignedInt' || $type === 'xsd:int') $val = (int)$val;
        elseif ($type === 'xsd:boolean') $val = ($val === '1' || $val === 1 || $val === true || $val === 'true');
        $paramValues[$path] = [$val, $type];
    }
    if (!$paramValues && !$failed) {
        return ['success' => false, 'message' => 'Tidak ada field untuk diubah.'];
    }
    if ($failed) {
        return ['success' => false, 'message' => 'Path tidak valid: ' . implode(', ', $failed)];
    }
    $result = genieacs_set_params($deviceId, $paramValues, 15);
    if ($result === null) {
        return ['success' => false, 'message' => 'Gagal mengirim task ke GenieACS (device mungkin offline).'];
    }
    return ['success' => true, 'message' => 'Perubahan berhasil dikirim via TR-069 (' . count($paramValues) . ' field, 1 task).'];
}

/** Reset koneksi PPP — set Enable=false lalu true (trigger reconnect). */
function genieacs_ppp_reset(string $serial): array {
    $deviceId = genieacs_find_device_id($serial);
    if (!$deviceId) {
        return ['success' => false, 'message' => 'Device tidak ditemukan di GenieACS.'];
    }
    $base = 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1';
    $off = genieacs_set_params($deviceId, [$base . '.Enable' => [false, 'xsd:boolean']], 15);
    if ($off === null) {
        return ['success' => false, 'message' => 'Gagal mengirim task reset (request gagal/timeout).'];
    }
    $on = genieacs_set_params($deviceId, [$base . '.Enable' => [true, 'xsd:boolean']], 15);
    if ($on === null) {
        return ['success' => false, 'message' => 'Task disable terkirim tapi enable-ulang gagal — cek koneksi device.'];
    }
    return ['success' => true, 'message' => 'Reset koneksi PPP dikirim via TR-069 (disable lalu enable ulang).'];
}

/** Hapus instance WANPPPConnection dari device (deleteObject). Destruktif — device kembali ke WAN kosong/DHCP default. */
function genieacs_ppp_remove(string $serial): array {
    $deviceId = genieacs_find_device_id($serial);
    if (!$deviceId) {
        return ['success' => false, 'message' => 'Device tidak ditemukan di GenieACS.'];
    }
    $result = genieacs_request('POST', "/devices/" . rawurlencode($deviceId) . "/tasks?connection_request",
        ['name' => 'deleteObject', 'objectName' => 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1'], 15);
    if ($result === null) {
        return ['success' => false, 'message' => 'Gagal menghapus (device tidak reachable).'];
    }
    return ['success' => true, 'message' => 'Instance PPP WAN dihapus dari device via TR-069.'];
}

/**
 * Edit parameter WANIPConnection (IP Interface) via TR-069 — Static Address/Subnet/
 * Gateway/DNS/MTU/NAT. Path pakai wanDevIdx.connDevIdx.ipConnIdx dinamis (bukan
 * hardcode 1.1.1) — device bisa punya lebih dari 1 IP Interface (mis. WAN internet
 * DHCP + WAN TR069 management terpisah, masing-masing index beda).
 */
function genieacs_edit_ip_params(string $serial, string $wanPath, array $fields): array {
    $deviceId = genieacs_find_device_id($serial);
    if (!$deviceId) {
        return ['success' => false, 'message' => 'Device tidak ditemukan di GenieACS.'];
    }
    if (!preg_match('/^InternetGatewayDevice\.WANDevice\.\d+\.WANConnectionDevice\.\d+\.WANIPConnection\.\d+$/', $wanPath)) {
        return ['success' => false, 'message' => 'Path WAN IP Interface tidak valid.'];
    }
    $map = [
        'ip_address'   => ["{$wanPath}.ExternalIPAddress", 'xsd:string', 'string'],
        'subnet_mask'  => ["{$wanPath}.SubnetMask", 'xsd:string', 'string'],
        'gateway'      => ["{$wanPath}.DefaultGateway", 'xsd:string', 'string'],
        'dns'          => ["{$wanPath}.DNSServers", 'xsd:string', 'string'],
        'mtu'          => ["{$wanPath}.MaxMTUSize", 'xsd:unsignedInt', 'int'],
        'nat'          => ["{$wanPath}.NATEnabled", 'xsd:boolean', 'bool'],
    ];
    $sent = [];
    $failed = [];
    foreach ($map as $key => [$path, $type, $cast]) {
        if (!isset($fields[$key]) || $fields[$key] === '') continue;
        $val = $fields[$key];
        if ($cast === 'int') $val = (int)$val;
        elseif ($cast === 'bool') $val = ($val === '1' || $val === 1 || $val === true);
        $result = genieacs_set_params($deviceId, [$path => [$val, $type]], 15);
        if ($result === null) { $failed[] = $key; } else { $sent[] = $key; }
    }
    if (!$sent && !$failed) {
        return ['success' => false, 'message' => 'Tidak ada field untuk diubah.'];
    }
    if ($failed) {
        return ['success' => false, 'message' => 'Sebagian gagal terkirim: ' . implode(', ', $failed) . ($sent ? ' (berhasil: ' . implode(', ', $sent) . ')' : '')];
    }
    return ['success' => true, 'message' => 'Perubahan IP Interface berhasil dikirim via TR-069 (' . implode(', ', $sent) . ').'];
}

/** Hapus instance WANIPConnection dari device (deleteObject). Destruktif — WAN itu hilang dari device. */
function genieacs_ip_remove(string $serial, string $wanPath): array {
    $deviceId = genieacs_find_device_id($serial);
    if (!$deviceId) {
        return ['success' => false, 'message' => 'Device tidak ditemukan di GenieACS.'];
    }
    if (!preg_match('/^InternetGatewayDevice\.WANDevice\.\d+\.WANConnectionDevice\.\d+\.WANIPConnection\.\d+$/', $wanPath)) {
        return ['success' => false, 'message' => 'Path WAN IP Interface tidak valid.'];
    }
    $result = genieacs_request('POST', "/devices/" . rawurlencode($deviceId) . "/tasks?connection_request",
        ['name' => 'deleteObject', 'objectName' => $wanPath], 15);
    if ($result === null) {
        return ['success' => false, 'message' => 'Gagal menghapus (device tidak reachable).'];
    }
    return ['success' => true, 'message' => 'Instance IP WAN dihapus dari device via TR-069.'];
}

/**
 * Cari LINTAS semua WANDevice.*.WANConnectionDevice.* -- bukan cuma WANDevice.1.WANConnectionDevice.1.
 * Reuse path yang SUDAH ADA supaya klik Simpan berulang tidak bikin instance dobel;
 * addObject hanya dipakai kalau device benar-benar belum punya instance jenis ini sama sekali.
 * $objectType: 'WANPPPConnection' atau 'WANIPConnection'.
 */
function genieacs_find_wan_instance_anywhere(string $deviceId, string $objectType): array {
    $result = genieacs_request('GET', "/devices/?query=" . rawurlencode(json_encode(['_id' => $deviceId]))
        . "&projection=InternetGatewayDevice.WANDevice", null, 10);
    $wanDevices = (is_array($result) && !empty($result[0]['InternetGatewayDevice']['WANDevice']))
        ? $result[0]['InternetGatewayDevice']['WANDevice'] : null;
    if (is_array($wanDevices)) {
        foreach ($wanDevices as $wKey => $wVal) {
            if (((string) $wKey)[0] === '_' || !is_array($wVal)) continue;
            $connDevices = $wVal['WANConnectionDevice'] ?? null;
            if (!is_array($connDevices)) continue;
            foreach ($connDevices as $cKey => $cVal) {
                if (((string) $cKey)[0] === '_' || !is_array($cVal)) continue;
                $objNode = $cVal[$objectType] ?? null;
                if (!is_array($objNode)) continue;
                $indices = array_filter(array_keys($objNode), fn($k) => ((string) $k)[0] !== '_');
                if (!empty($indices)) {
                    return [
                        'path' => "InternetGatewayDevice.WANDevice.{$wKey}.WANConnectionDevice.{$cKey}.{$objectType}",
                        'index' => (string) reset($indices),
                        'needs_add' => false,
                    ];
                }
            }
        }
    }
    // Tidak ketemu sama sekali di device manapun -- pakai default base untuk addObject.
    return [
        'path' => "InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.{$objectType}",
        'index' => '1',
        'needs_add' => true,
    ];
}

function genieacs_push_wan(string $serial, string $wan_mode, array $wan): array {
    $deviceId = genieacs_find_device_id($serial);
    if (!$deviceId) {
        return ['success' => false, 'message' => 'Device tidak ditemukan di GenieACS.'];
    }

    $params = [];

    if ($wan_mode === 'PPPoE') {
        $inst = genieacs_find_wan_instance_anywhere($deviceId, 'WANPPPConnection');
        $pppPath = $inst['path'];
        $pppIndex = $inst['index'];
        if ($inst['needs_add']) {
            $add_result = genieacs_request('POST', "/devices/" . rawurlencode($deviceId) . "/tasks?connection_request",
                ['name' => 'addObject', 'objectName' => $pppPath], 15);
            if ($add_result === null) {
                return ['success' => false, 'message' => 'Gagal membuat instance WANPPPConnection di device (device tidak reachable).'];
            }
        }
        $params["{$pppPath}.{$pppIndex}.Username"] = [$wan['pppoe_username'] ?? '', 'xsd:string'];
        $params["{$pppPath}.{$pppIndex}.Password"] = [$wan['pppoe_password'] ?? '', 'xsd:string'];
        $params["{$pppPath}.{$pppIndex}.ConnectionType"] = ['IP_Routed', 'xsd:string'];
        $params["{$pppPath}.{$pppIndex}.Enable"] = [true, 'xsd:boolean'];
        // NAT wajib aktif di IP_Routed, kalau tidak trafik LAN pelanggan tidak di-translate.
        $params["{$pppPath}.{$pppIndex}.NATEnabled"] = [true, 'xsd:boolean'];
        // WAN Service ini adalah jalur internet utama pelanggan (bukan akses manajemen ACS),
        // jadi service type WAJIB "INTERNET" — kalau device default/kepake "OTHER" pelanggan
        // tidak bisa browsing walau PPPoE-nya sendiri connect.
        $params["{$pppPath}.{$pppIndex}.X_HW_SERVICELIST"] = ['INTERNET', 'xsd:string'];
        // VLAN wajib match VLAN service ONU di OLT -- kalau device masih default/beda VLAN,
        // PPP tetap bisa "Connected" ke BRAS tapi trafik salah VLAN, pelanggan gagal browsing.
        // Kirim KEDUA param: X_HW_VLAN (vendor Huawei) dan VLANID (param TR-069 standar,
        // dipakai FiberHome/vendor lain) -- device abaikan param yang tidak ia kenal.
        if (!empty($wan['vlan_service'])) {
            $params["{$pppPath}.{$pppIndex}.X_HW_VLAN"] = [(int) $wan['vlan_service'], 'xsd:int'];
            $params["{$pppPath}.{$pppIndex}.VLANEnable"] = [true, 'xsd:boolean'];
            $params["{$pppPath}.{$pppIndex}.VLANID"] = [(int) $wan['vlan_service'], 'xsd:unsignedInt'];
        }
    } elseif ($wan_mode === 'Static' || $wan_mode === 'DHCP') {
        // Sama seperti PPPoE: jangan hardcode index '.1', device migrasi bisa sudah punya
        // instance WANIPConnection dengan index lain -- reuse, jangan addObject sembarangan.
        $inst = genieacs_find_wan_instance_anywhere($deviceId, 'WANIPConnection');
        $ipPath = $inst['path'];
        $ipIndex = $inst['index'];
        if ($inst['needs_add']) {
            $add_result = genieacs_request('POST', "/devices/" . rawurlencode($deviceId) . "/tasks?connection_request",
                ['name' => 'addObject', 'objectName' => $ipPath], 15);
            if ($add_result === null) {
                return ['success' => false, 'message' => 'Gagal membuat instance WANIPConnection di device (device tidak reachable).'];
            }
        }
        if ($wan_mode === 'Static') {
            $params["{$ipPath}.{$ipIndex}.AddressingType"] = ['Static', 'xsd:string'];
            $params["{$ipPath}.{$ipIndex}.ExternalIPAddress"] = [$wan['static_ip'] ?? '', 'xsd:string'];
            $params["{$ipPath}.{$ipIndex}.SubnetMask"] = [$wan['static_netmask'] ?? '', 'xsd:string'];
            $params["{$ipPath}.{$ipIndex}.DefaultGateway"] = [$wan['static_gateway'] ?? '', 'xsd:string'];
            $dns = trim(($wan['static_dns_primary'] ?? '') . ',' . ($wan['static_dns_secondary'] ?? ''), ',');
            $params["{$ipPath}.{$ipIndex}.DNSServers"] = [$dns, 'xsd:string'];
        } else {
            $params["{$ipPath}.{$ipIndex}.AddressingType"] = ['DHCP', 'xsd:string'];
        }
        $params["{$ipPath}.{$ipIndex}.Enable"] = [true, 'xsd:boolean'];
        $params["{$ipPath}.{$ipIndex}.X_HW_SERVICELIST"] = ['INTERNET', 'xsd:string'];
        // Sama seperti PPPoE: NAT wajib aktif, kalau tidak trafik LAN tidak ditranslate.
        $params["{$ipPath}.{$ipIndex}.NATEnabled"] = [true, 'xsd:boolean'];
        // Sama seperti PPPoE: VLAN wajib match VLAN service ONU di OLT.
        // Kirim KEDUA param: X_HW_VLAN (Huawei) dan VLANID (standar, dipakai FiberHome dkk).
        if (!empty($wan['vlan_service'])) {
            $params["{$ipPath}.{$ipIndex}.X_HW_VLAN"] = [(int) $wan['vlan_service'], 'xsd:int'];
            $params["{$ipPath}.{$ipIndex}.VLANEnable"] = [true, 'xsd:boolean'];
            $params["{$ipPath}.{$ipIndex}.VLANID"] = [(int) $wan['vlan_service'], 'xsd:unsignedInt'];
        }
    } else {
        // 'Setup via ONU webpage' — tidak ada parameter WAN yang dipush.
        return ['success' => true, 'message' => 'Mode "Setup via ONU webpage" — tidak ada perubahan WAN dikirim via TR-069.'];
    }

    // Timeout 15s: update-onu-mode.php sudah set_time_limit(0) untuk flow ini,
    // jadi boleh tunggu connection-request + digest-auth CWMP session yang real.
    $result = genieacs_set_params($deviceId, $params, 15);
    if ($result === null) {
        return ['success' => false, 'message' => 'Gagal mengirim task TR-069 ke GenieACS (request gagal/timeout).'];
    }
    return ['success' => true, 'message' => 'Konfigurasi WAN berhasil dikirim via TR-069.'];
}
