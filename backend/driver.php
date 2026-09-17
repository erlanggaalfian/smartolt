<?php
// =============================================================================
// SmartOLT Backend - OLT Driver Wrapper
// Lokasi: /backend/driver.php
//
// Seluruh logika vendor ada di Python Engine (backend/python_engine/drivers/).
// File ini hanya: registry, call_driver() bridge, dan fungsi wrapper.
// =============================================================================

/**
 * REGISTRY DRIVER OLT — satu-satunya tempat pendaftaran vendor.
 *
 * Menambah brand/model OLT baru = tambahkan satu entri di sini + satu berkas
 * driver di `backend/python_engine/drivers/`. Tidak ada berkas lain yang perlu disentuh.
 *
 * Kunci array = nilai kolom `type` pada tabel `olts` (dipakai juga sebagai
 * value pada dropdown pemilihan tipe OLT di frontend).
 */
function olt_driver_registry(): array {
    return [
        'CDATA FD1602SB1(GPON)' => [
            'label' => 'CDATA FD1602SB1 (GPON)',
            // 'class' dikirim ke Python Engine untuk memilih driver.
            'class' => 'OltCdataFd1602sb1Driver',
            // Nilai `type` lama yang mungkin masih tersimpan di database dan harus
            // tetap dipetakan ke driver ini. Dicocokkan longgar (huruf & angka saja).
            'aliases' => ['GPON', 'CDATA', 'FD1602S', 'FD1602SB1', 'CDATA FD1602S-B1'],
            'info'    => ['brand' => 'CDATA', 'model' => 'FD1602S-B1', 'tech' => 'GPON', 'pon_type' => 'gpon'],
            'panels'  => [
                'olt-details' => ['label' => 'OLT Details', 'icon' => 'server'],
                'olt-cards'   => ['label' => 'OLT Cards',   'icon' => 'layout-grid'],
                'pon-ports'   => ['label' => 'PON Ports',   'icon' => 'plug-zap'],
                'interfaces'  => ['label' => 'Interfaces',  'icon' => 'ethernet-port'],
            ],
        ],
        'ZTE C320' => [
            'label' => 'ZTE C320 (GPON)',
            'class' => 'OltZteC320Driver',
            'aliases' => ['ZTE', 'C320', 'ZTE C320'],
            'info'    => ['brand' => 'ZTE', 'model' => 'C320', 'tech' => 'GPON', 'pon_type' => 'gpon'],
            // Semua panel di bawah sumber datanya sudah diverifikasi langsung
            // pada perangkat (lihat zte_olt_manual_book.md).
            'panels'  => [
                'olt-details' => ['label' => 'OLT Details', 'icon' => 'server'],
                'olt-cards'   => ['label' => 'OLT Cards',   'icon' => 'layout-grid'],
                'pon-ports'   => ['label' => 'PON Ports',   'icon' => 'plug-zap'],
                'interfaces'  => ['label' => 'Interfaces',  'icon' => 'ethernet-port'],
            ],
        ],
        'ZTE C300' => [
            'label' => 'ZTE C300 (GPON)',
            'class' => 'OltZteC300Driver',
            'aliases' => ['C300', 'ZTE C300'],
            'info'    => ['brand' => 'ZTE', 'model' => 'C300', 'tech' => 'GPON', 'pon_type' => 'gpon'],
            'panels'  => [
                'olt-details' => ['label' => 'OLT Details', 'icon' => 'server'],
                'olt-cards'   => ['label' => 'OLT Cards',   'icon' => 'layout-grid'],
                'pon-ports'   => ['label' => 'PON Ports',   'icon' => 'plug-zap'],
                'interfaces'  => ['label' => 'Interfaces',  'icon' => 'ethernet-port'],
            ],
        ],
    ];
}

/**
 * Daftar tipe OLT yang didukung, untuk mengisi dropdown di frontend.
 * Balikan: ['<type>' => '<label tampil>', ...]
 */
function get_supported_olt_types(): array {
    $out = [];
    foreach (olt_driver_registry() as $type => $meta) {
        $out[$type] = $meta['label'];
    }
    return $out;
}

/**
 * Menormalkan nama tipe OLT untuk pencocokan longgar (huruf & angka saja).
 */
function normalize_olt_type(string $type): string {
    return preg_replace('/[^A-Z0-9]/', '', strtoupper($type));
}

/**
 * Mencari metadata driver untuk sebuah tipe OLT. Balikan null bila tak ada.
 *
 * Pencocokan bertingkat:
 *   1. sama persis dengan kunci registry;
 *   2. kunci registry dinormalkan (tahan beda spasi/tanda baca);
 *   3. daftar `aliases` pada entri registry — menampung nilai `type` lama yang
 *      sudah terlanjur tersimpan di database (mis. default kolom 'GPON').
 */
function find_olt_driver_meta(string $type): ?array {
    $registry = olt_driver_registry();

    if (isset($registry[$type])) {
        return $registry[$type];
    }

    $norm = normalize_olt_type($type);
    if ($norm === '') {
        return null;
    }

    foreach ($registry as $key => $meta) {
        if (normalize_olt_type($key) === $norm) {
            return $meta;
        }
        foreach ($meta['aliases'] ?? [] as $alias) {
            if (normalize_olt_type($alias) === $norm) {
                return $meta;
            }
        }
    }

    return null;
}

/**
 * Membungkus pemanggilan method driver agar tipe OLT tak dikenal / fitur yang
 * tidak didukung driver mengembalikan pesan rapi, bukan fatal error.
 *
 * Seluruh logika vendor kini ada di Python Engine (backend/python_engine/drivers/).
 * Driver PHP sudah dihapus (#234), jadi tidak ada lagi jalur cadangan native.
 *
 * @param array  $olt      Baris OLT dari database.
 * @param string $method   Nama method pada driver.
 * @param array  $args     Argumen setelah $olt.
 * @param array  $fallback Nilai tambahan yang digabung ke balikan saat gagal.
 */
/**
 * Meng-escape argumen command line secara aman untuk Windows dan Linux.
 * PHP escapeshellarg() bawaan pada Windows merusak tanda kutip dua (") dalam string JSON.
 */
function escape_cli_arg(string $arg): string {
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        return '"' . str_replace('"', '""', $arg) . '"';
    }
    return escapeshellarg($arg);
}

/**
 * Memanggil Python Engine untuk menjalankan perintah ke OLT.
 *
 * Seluruh logika vendor kini ada di Python Engine (backend/python_engine/drivers/).
 * Driver PHP sudah dihapus (#234), jadi tidak ada lagi jalur cadangan native.
 *
 * @param array  $olt      Baris OLT dari database.
 * @param string $method   Nama method pada driver.
 * @param array  $args     Argumen setelah $olt.
 * @param array  $fallback Nilai tambahan yang digabung ke balikan saat gagal.
 */
function call_driver(array $olt, string $method, array $args = [], array $fallback = []): array {
    // Tipe OLT wajib dikenali. JANGAN pernah diam-diam jatuh ke driver vendor
    // lain: perintah CLI antar vendor berbeda dan bisa merusak konfigurasi.
    $meta = find_olt_driver_meta($olt['type'] ?? '');
    if ($meta === null) {
        return array_merge([
            'success' => false,
            'message' => "Tipe OLT '" . ($olt['type'] ?? '') . "' tidak dikenali. Daftarkan "
                       . "driver-nya di olt_driver_registry() pada backend/driver.php.",
        ], $fallback);
    }

    $decrypted_olt = $olt;
    if (isset($olt['password'])) {
        $decrypted_olt['password'] = decrypt_password($olt['password']);
    }
    $decrypted_olt['driver_class'] = $meta['class'];

    $payload = json_encode([
        'olt'    => $decrypted_olt,
        'method' => $method,
        'args'   => $args,
    ]);

    // Jalur 1: REST API (FastAPI). Jalur utama.
    $engine_port = getenv('ENGINE_PORT') ?: 8000;
    $ch = curl_init("http://127.0.0.1:{$engine_port}/olt/call");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'X-SmartOLT-Token: ' . engine_token(),
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);

    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code === 200 && $response) {
        $decoded = json_decode($response, true);
        if ($decoded && isset($decoded['success'])) {
            return $decoded;
        }
    }

    // 404 = method memang tidak ada pada driver Python. Menjajal CLI hanya akan
    // memberi jawaban sama, jadi langsung dilaporkan.
    if ($http_code === 404) {
        $brand = trim(($meta['info']['brand'] ?? '') . ' ' . ($meta['info']['model'] ?? '')) ?: 'ini';
        return array_merge([
            'success' => false,
            'message' => "Fitur ini belum didukung oleh driver OLT {$brand}.",
        ], $fallback);
    }

    // Jalur 2: CLI langsung. Menjaga aplikasi tetap hidup bila layanan REST mati
    // (mis. belum sempat restart setelah deploy).
    $cli_path = __DIR__ . '/python_engine/cli.py';
    $is_win = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');
    $venv_py = $is_win
        ? __DIR__ . '/python_engine/venv/Scripts/python.exe'
        : __DIR__ . '/python_engine/venv/bin/python3';
    
    if (is_file($cli_path)) {
        if (is_file($venv_py)) {
            $py_exec = escapeshellarg($venv_py);
        } else {
            $py_exec = $is_win ? 'python' : 'python3';
        }
        
        $stderr_redirect = $is_win ? '2>NUL' : '2>/dev/null';
        $cmd = "{$py_exec} " . escapeshellarg($cli_path)
             . ' --olt-data ' . escape_cli_arg(json_encode($decrypted_olt))
             . ' --method ' . escapeshellarg($method)
             . ' --args ' . escape_cli_arg(json_encode($args))
             . ' ' . $stderr_redirect;

        $cli_output = [];
        $cli_status = 1;
        exec($cmd, $cli_output, $cli_status);

        // Kode 3 = EXIT_UNSUPPORTED pada cli.py.
        if ($cli_status === 3) {
            $brand = trim(($meta['info']['brand'] ?? '') . ' ' . ($meta['info']['model'] ?? '')) ?: 'ini';
            return array_merge([
                'success' => false,
                'message' => "Fitur ini belum didukung oleh driver OLT {$brand}.",
            ], $fallback);
        }

        $decoded = json_decode(implode("\n", $cli_output), true);
        if ($decoded && isset($decoded['success'])) {
            return $decoded;
        }
    }

    // Kedua jalur gagal = Python Engine tidak dapat dihubungi.
    return array_merge([
        'success' => false,
        'message' => 'Python Engine tidak merespons. Periksa layanan: '
                   . 'sudo systemctl status smartolt-python',
    ], $fallback);
}

// ==============================================================================
// GLOBAL ACTION ROUTING TO modular DRIVERS
// ==============================================================================

/**
 * Memeriksa koneksi SSH dan SNMP ke sebuah OLT.
 *
 * Driver (Python Engine) hanya menguji transport SSH/Telnet dan membaca
 * CPU/RAM/uptime. Uji SNMP dilakukan di sini memakai check_snmp_ping() (UDP
 * mentah, ~10-20ms) supaya konsisten untuk semua vendor dan tidak menambah
 * sesi VTY. Baris "SNMP Status" bawaan driver dibuang lalu ditulis ulang
 * berdasarkan hasil uji sebenarnya.
 */
function check_olt_connection(array $olt): array {
    $res = call_driver($olt, 'checkConnection');
    if (empty($res['success'])) {
        return $res;
    }

    $msg = (string)($res['message'] ?? '');
    // Buang klaim SNMP dari driver; driver tidak pernah benar-benar menguji SNMP.
    $msg = preg_replace('/^.*SNMP Status\s*:.*$/mu', '', $msg);
    $msg = rtrim($msg);

    $res['snmp_ok'] = check_snmp_ping($olt);
    $res['message'] = $msg . ($res['snmp_ok']
        ? "\n\n• SNMP Status: Koneksi Berhasil!"
        : "\n\n⚠️ Info: SNMP Timeout. Periksa Community SNMP OLT atau pastikan service snmp enable.");

    return $res;
}

/**
 * Mendapatkan daftar ONU Autofind (belum diotorisasi) dari OLT.
 */
function get_olt_autofind(array $olt): array {
    return call_driver($olt, 'getAutofind', [], ['onus' => []]);
}

/**
 * Mengotorisasi (mendaftarkan) ONU baru ke OLT via SSH.
 */
function authorize_onu(array $olt, string $pon_port, string $serial_number, string $name, ?int $vlan, string $description = '', ?int $onu_id = null, ?string $wan_mode = null, ?string $pppoe_username = null, ?string $pppoe_password = null, ?string $upload_profile = null, ?string $download_profile = null): array {
    return call_driver($olt, 'authorizeOnu', [$pon_port, $serial_number, $name, $vlan, $description, $onu_id, $wan_mode, $pppoe_username, $pppoe_password, $upload_profile, $download_profile]);
}

/**
 * Mereboot ONU via SSH.
 */
function reboot_onu(array $olt, array $onu): array {
    return call_driver($olt, 'rebootOnu', [$onu]);
}

/**
 * Restore ONU ke factory defaults via SSH.
 */
function restore_factory_onu(array $olt, array $onu): array {
    return call_driver($olt, 'restoreFactory', [$onu]);
}

/**
 * Menghapus (de-otorisasi) ONU dari OLT via SSH.
 */
function delete_onu_from_olt(array $olt, array $onu): array {
    return call_driver($olt, 'deleteOnu', [$onu]);
}

/**
 * Update description/name only on OLT (lightweight, no WAN config touch).
 * Used for identity edits to prevent SNMP sync from reverting local changes.
 */
function update_onu_description(array $olt, string $pon_port, int $onu_id, string $description): array {
    return call_driver($olt, 'updateOnuDescription', [$pon_port, $onu_id, $description]);
}

/**
 * Konfigurasi lengkap ONU (PPPoE / GEM / TCONT / dsb) sesuai sintaks vendor.
 */
function configure_onu_full(array $olt, array $onu, array $wan): array {
    return call_driver($olt, 'configureOnuFull', [$onu, $wan]);
}

/**
 * Membaca konfigurasi terpasang (running-config) untuk ONU tertentu.
 */
function sync_onu_config_from_olt(array $olt, array $onu): array {
    return call_driver($olt, 'syncOnuConfig', [$onu]);
}

/**
 * Membaca redaman sinyal optik (Rx Power) ONU dari OLT.
 */
function get_onu_signal(array $olt, array $onu, bool $include_ip = true): array {
    return call_driver($olt, 'getOnuSignal', [$onu, $include_ip]);
}

/**
 * Menarik daftar ONU terkonfigurasi (terdaftar) langsung dari perangkat OLT.
 */
function pull_configured_onus(array $olt): array {
    return call_driver($olt, 'pullConfiguredOnus', [], ['onus' => []]);
}

/**
 * Membaca konfigurasi OLT secara mendalam menggunakan perintah enable & show current-config
 */
function pull_onus_from_current_config(array $olt): array {
    return call_driver($olt, 'pullOnusFromCurrentConfig', [], ['onus' => []]);
}

/**
 * Menonaktifkan ONU di OLT.
 */
function disable_onu_on_olt(array $olt, array $onu): array {
    return call_driver($olt, 'disableOnu', [$onu]);
}

/**
 * Mengaktifkan kembali ONU di OLT.
 */
function enable_onu_on_olt(array $olt, array $onu): array {
    return call_driver($olt, 'enableOnu', [$onu]);
}

/**
 * Mengambil data panel detail OLT dalam bentuk terstruktur (bukan output CLI mentah).
 * Seluruh perintah CLI & parsing ditentukan oleh driver brand-spesifik.
 */
function get_olt_panel_data(array $olt, string $panel): array {
    return call_driver($olt, 'getPanelData', [$panel], ['sections' => []]);
}

/**
 * Daftar panel yang didukung oleh driver OLT tertentu.
 * Balikan berupa peta panel (bukan struktur success/message), jadi tidak lewat call_driver().
 *
 * Dibaca langsung dari olt_driver_registry(): data ini statis per-vendor dan tidak
 * menyentuh perangkat, jadi tak perlu meng-instansiasi kelas driver PHP maupun
 * menambah round-trip ke Python engine.
 */
function get_olt_supported_panels(array $olt): array {
    $meta = find_olt_driver_meta($olt['type'] ?? '');
    return $meta['panels'] ?? [];
}

/**
 * Membaca seluruh VLAN yang aktif pada perangkat OLT (realtime, bukan dari database).
 */
function get_olt_vlans(array $olt): array {
    return call_driver($olt, 'getVlans', [], ['vlans' => []]);
}

/**
 * Membaca seluruh speed profile (Upload/Download) yang terkonfigurasi pada OLT.
 */
function get_olt_speed_profiles(array $olt): array {
    return call_driver($olt, 'getSpeedProfiles', [], ['profiles' => []]);
}

/**
 * Membuat atau memperbarui (overwrite) speed profile pada perangkat OLT.
 */
function save_olt_speed_profile(array $olt, string $direction, string $name, int $speed_kbps): array {
    return call_driver($olt, 'saveSpeedProfile', [$direction, $name, $speed_kbps]);
}

/**
 * Menghapus speed profile dari perangkat OLT.
 */
function delete_olt_speed_profile(array $olt, string $direction, string $name): array {
    return call_driver($olt, 'deleteSpeedProfile', [$direction, $name]);
}

/**
 * Mengaitkan ONU ke speed profile Upload(tcont)/Download(gemport downstream).
 */
function assign_onu_speed_profile(array $olt, string $pon_port, int $onu_id, string $upload_name, string $download_name): array {
    return call_driver($olt, 'assignSpeedProfile', [$pon_port, $onu_id, $upload_name, $download_name]);
}

/**
 * Menyimpan VLAN sekaligus menugaskan keanggotaan port (tagged/untagged/none).
 * $ports_config: ['ge 0/0/1' => 'tagged'|'untagged'|'none', ...]
 */
function save_olt_vlan_ports(array $olt, int $vlan_id, string $description, array $ports_config): array {
    return call_driver($olt, 'saveVlanPorts', [$vlan_id, $description, $ports_config]);
}

/**
 * Membuat VLAN baru pada perangkat OLT.
 */
function add_olt_vlan(array $olt, int $vlan_id, string $description = ''): array {
    return call_driver($olt, 'addVlan', [$vlan_id, $description]);
}

/**
 * Menghapus VLAN dari perangkat OLT.
 */
function delete_olt_vlan(array $olt, int $vlan_id, bool $confirmed = false): array {
    return call_driver($olt, 'deleteVlan', [$vlan_id, $confirmed]);
}

/**
 * Membaca status port uplink fisik dan interface layer 3 pada perangkat OLT.
 */
function get_olt_interfaces(array $olt): array {
    return call_driver($olt, 'getInterfaces', [], ['ports' => []]);
}

/**
 * Mengkonfigurasi VLAN (mode + tagged/untagged) pada port uplink fisik OLT.
 */
function set_olt_port_vlan(array $olt, string $port, string $mode, ?string $tagged, ?string $untagged, ?int $pvid): array {
    return call_driver($olt, 'configPortVlan', [$port, $mode, $tagged, $untagged, $pvid]);
}

/**
 * Mengkonfigurasi parameter fisik port (auto-negotiation, speed, duplex) pada port uplink OLT.
 */
function set_olt_port_config(array $olt, string $port, string $auto_nego, string $speed, string $duplex): array {
    return call_driver($olt, 'configPort', [$port, $auto_nego, $speed, $duplex]);
}


/**
 * Mengambil status lengkap satu ONU (optik, info, VLAN, WAN, LAN, VoIP, MAC, dsb).
 * Seluruh perintah CLI ada di driver — action handler hanya menampilkan hasilnya.
 */
function get_onu_full_status(array $olt, array $onu): array {
    return call_driver($olt, 'getOnuFullStatus', [$onu], ['data' => []]);
}

/**
 * Mengambil ONU ID berikutnya yang masih bebas pada sebuah PON port.
 */
function get_next_free_onu_id(array $olt, string $pon_port): array {
    return call_driver($olt, 'getNextFreeOnuId', [$pon_port], ['next_onu_id' => null]);
}

/**
 * Membaca running-config milik satu ONU dari perangkat.
 */
function get_onu_running_config(array $olt, array $onu): array {
    return call_driver($olt, 'getOnuRunningConfig', [$onu], ['config' => '']);
}

/**
 * Mengambil info hardware/software ONU (SW info): vendor, HW ver, SN, OMCC,
 * model, jumlah port ETH/VoIP/CATV/WiFi. Sumber: CLI telnet/SSH OLT.
 */
function get_onu_hw_sw(array $olt, array $onu): array {
    return call_driver($olt, 'getOnuHwSw', [$onu], ['onu_details' => '', 'features' => '']);
}

/**
 * Mengaktifkan SNMP dan menyetel community read/write pada perangkat OLT.
 */
function setup_olt_snmp(array $olt, string $community_ro, string $community_rw): array {
    return call_driver($olt, 'setupSnmp', [$community_ro, $community_rw]);
}

/**
 * Menarik peta konfigurasi seluruh ONU (VLAN, PPPoE, nama, deskripsi) dari perangkat.
 * Dipakai cron sinkronisasi. Perintah CLI & regex parsing sepenuhnya milik driver.
 *
 * Balikan: ['success','message','ipconfig_map','name_map','desc_map'] dengan kunci peta
 * berformat "<pon_port>_<onu_id>", mis. "0/0/2_24".
 */
function get_olt_onu_config_maps(array $olt): array {
    return call_driver($olt, 'getOnuConfigMaps', [], [
        'ipconfig_map' => [],
        'name_map'     => [],
        'desc_map'     => [],
    ]);
}

/**
 * Metadata identitas driver (brand, model, teknologi).
 *
 * Sama seperti get_olt_supported_panels(): data statis, dibaca dari registry.
 * Kunci 'pon_type' dipakai onu-detail.php untuk memilih GPON/EPON — wajib ada.
 */
function get_olt_driver_info(array $olt): array {
    $meta = find_olt_driver_meta($olt['type'] ?? '');
    return $meta['info'] ?? [
        'brand' => 'Unknown', 'model' => $olt['type'] ?? '-', 'tech' => '-', 'pon_type' => 'gpon',
    ];
}

/**
 * Uji cepat SNMP OLT menggunakan raw UDP socket (instan, 10-20ms).
 */
function is_demo_olt(array $olt): bool {
    return ($olt["ip"] ?? "") === "127.0.0.1" || strtolower($olt["ip"] ?? "") === "demo";
}

function check_snmp_ping(array $olt, int $timeout = 1): bool {
    if (is_demo_olt($olt)) {
        return true;
    }
    
    $ip = $olt['ip'];
    $port = (int)($olt['snmp_port'] ?: 161);
    $community = $olt['snmp_community'] ?: 'public';
    $comm_len = strlen($community);
    
    $comm_hex = '';
    for ($i=0; $i<$comm_len; $i++) {
        $comm_hex .= sprintf('%02x', ord($community[$i]));
    }
    
    $pdu = hex2bin('a01c020412345678020100020100300e300c06082b060102010101000500');
    $version_bin = hex2bin('020101'); // SNMP v2c
    $comm_bin = hex2bin('04' . sprintf('%02x', $comm_len) . $comm_hex);
    
    $packet_body = $version_bin . $comm_bin . $pdu;
    $packet = hex2bin('30' . sprintf('%02x', strlen($packet_body))) . $packet_body;
    
    $socket = @fsockopen("udp://$ip", $port, $errno, $errstr, $timeout);
    if (!$socket) {
        return false;
    }
    
    stream_set_timeout($socket, $timeout);
    fwrite($socket, $packet);
    
    $response = fread($socket, 1024);
    fclose($socket);
    
    return !empty($response);
}
