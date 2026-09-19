<?php
// ==============================================================================
# Action Handler: Otorisasi ONU Baru
// ==============================================================================
require_once __DIR__ . '/../../backend/db.php';
require_once __DIR__ . '/../../backend/driver.php';

if (!isset($_SESSION['smartolt_role'])) {
    $_SESSION['error'] = 'Akses ditolak! Silakan login terlebih dahulu.';
    header('Location: ../dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token()) {
        $_SESSION['error'] = 'Token keamanan tidak valid. Silakan coba lagi.';
        header('Location: ../unconfigured.php');
        exit;
    }
    set_time_limit(0); // authorize + assign speed profile + setup PPPoE bisa 3x SSH round-trip, lebih dari 30s default
    // Enable debug mode if requested
    if (isset($_POST['debug_mode']) && $_POST['debug_mode'] == '1') {
        $_SESSION['debug_mode'] = true;
    }

    $olt_id = (int)$_POST['olt_id'];
    $pon_port = trim($_POST['pon_port']);
    $serial_number = trim($_POST['serial_number']);
    $name = cli_safe(trim($_POST['name'] ?? ''));
    if ($name === '') {
        $name = 'None';
    }
    $vlan = (int)$_POST['vlan'] ?: null;

    $download_profile = trim($_POST['download_profile'] ?? '');
    $upload_profile   = trim($_POST['upload_profile'] ?? '');

    // Parameter baru untuk ont description terstruktur
    $onu_type      = cli_safe(trim($_POST['onu_type'] ?? 'ALL-ONT'));
    $config_preset = cli_safe(trim($_POST['config_preset'] ?? 'None'));
    $zone          = cli_safe(trim($_POST['zone'] ?? 'None'));
    $splitter      = cli_safe(trim($_POST['splitter'] ?? 'None'));
    $address       = cli_safe(trim($_POST['address'] ?? 'None'));
    $contact       = cli_safe(trim($_POST['contact'] ?? 'None'));
    $splitter_port = trim($_POST['splitter_port'] ?? '') !== '' ? trim($_POST['splitter_port']) : null;
    $onu_mode      = in_array($_POST['onu_mode'] ?? '', ['Routing', 'Bridging'], true) ? $_POST['onu_mode'] : 'Routing';
    $external_id   = trim($_POST['external_id'] ?? '') !== '' ? cli_safe(trim($_POST['external_id'])) : null;
    $latitude      = is_numeric($_POST['latitude'] ?? '') ? (float)$_POST['latitude'] : null;
    $longitude     = is_numeric($_POST['longitude'] ?? '') ? (float)$_POST['longitude'] : null;

    $wan_mode       = in_array($_POST['wan_mode'] ?? '', ['Setup via ONU webpage', 'DHCP', 'Static', 'PPPoE'], true) ? $_POST['wan_mode'] : 'Setup via ONU webpage';
    $pppoe_username = cli_safe_email(trim($_POST['pppoe_username'] ?? ''));
    $pppoe_password = cli_safe_password(trim($_POST['pppoe_password'] ?? ''));

    if (empty($olt_id) || empty($pon_port) || empty($serial_number)) {
        $_SESSION['error'] = 'Data input tidak lengkap!';
        header('Location: ../unconfigured.php?olt_id=' . $olt_id);
        exit;
    }
    if ($download_profile === '' || $upload_profile === '') {
        $_SESSION['error'] = 'Download speed dan Upload speed wajib dipilih!';
        header('Location: ../auth-onu.php?olt_id=' . $olt_id . '&pon=' . urlencode($pon_port) . '&sn=' . urlencode($serial_number));
        exit;
    }
    check_olt_access_or_redirect($olt_id, '../unconfigured.php?olt_id=' . $olt_id);

    // Ambil data OLT
    $stmt = $pdo->prepare("SELECT * FROM olts WHERE id = ?");
    $stmt->execute([$olt_id]);
    $olt = $stmt->fetch();

    if (!$olt) {
        $_SESSION['error'] = 'OLT tidak ditemukan!';
        header('Location: ../unconfigured.php');
        exit;
    }

    // Format Deskripsi Terstruktur OLT
    $auth_date = date('Ymd');
    $structured_desc = "name_{$name}_zone_{$zone}_descr_{$address}_odb_{$splitter}_authd_{$auth_date}";
    if ($contact !== 'None' && $contact !== '') {
        $structured_desc .= "_contact_{$contact}";
    }
    if ($external_id !== null && $external_id !== '') {
        $structured_desc .= "_extid_{$external_id}";
    }

    $onu_id = isset($_POST['onu_id']) && $_POST['onu_id'] !== '' ? (int)$_POST['onu_id'] : null;

    // Untuk kolom DB: 'None' hanya sentinel internal buat structured_desc di atas,
    // jangan disimpan literal ke DB -- ubah ke NULL.
    $name_db          = ($name !== 'None' && $name !== '') ? $name : null;
    $zone_db          = ($zone !== 'None' && $zone !== '') ? $zone : null;
    $splitter_db      = ($splitter !== 'None' && $splitter !== '') ? $splitter : null;
    $address_db       = ($address !== 'None' && $address !== '') ? $address : null;
    $contact_db       = ($contact !== 'None' && $contact !== '') ? $contact : null;
    $config_preset_db = ($config_preset !== 'None' && $config_preset !== '') ? $config_preset : null;

    session_write_close(); // Lepas lock sesi selama komunikasi dengan OLT
    // Panggil fungsi driver untuk otorisasi (tcont/gemport/service-port/pon-onu-mng/PPPoE tergabung 1x SSH round-trip)
    $res = authorize_onu($olt, $pon_port, $serial_number, $name, $vlan, $structured_desc, $onu_id, $wan_mode, $pppoe_username, $pppoe_password, $upload_profile, $download_profile);

    // Skip get_onu_signal() di sini — hemat 1x SSH round-trip (~5-15 detik) + 1 slot VTY OLT.
    // ONU baru diotorisasi butuh beberapa detik untuk online; cron sync / onu-detail page
    // akan update signal+status aktual dalam <1 menit.
    $rx_power = null;
    $status = 'offline';
    session_start(); // Mulai kembali sesi untuk menulis sukses/error/debug log

    if (!empty($_SESSION['debug_mode']) && isset($res['log'])) {
        $cmds_display = isset($res['commands']) ? implode("\n", $res['commands']) : '(tidak tersedia)';
        $_SESSION['debug_log'] = "Commands Sent to OLT:\n{$cmds_display}\n\nOLT CLI Response Log:\n" . $res['log'];
    }

    if ($res['success']) {
        try {

            // Simpan ke database local cache (kolom name diisi clean name)
            $stmt_ins = $pdo->prepare("
                INSERT INTO onus (
                    olt_id, pon_port, onu_id, name, serial_number, vlan, status, last_rx_power,
                    onu_type, config_preset, zone, splitter, odb_port, address, contact,
                    onu_mode, external_id, latitude, longitude, download_profile, upload_profile,
                    wan_mode, pppoe_username, pppoe_password, description, wan_remote_access
                ) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt_ins->execute([
                $olt_id,
                $pon_port,
                $res['onu_id'],
                $name_db,
                $serial_number,
                $vlan,
                $status,
                $rx_power,
                $onu_type,
                $config_preset_db,
                $zone_db,
                $splitter_db,
                $splitter_port,
                $address_db,
                $contact_db,
                $onu_mode,
                $external_id,
                $latitude,
                $longitude,
                $download_profile,
                $upload_profile,
                $wan_mode,
                $pppoe_username !== '' ? $pppoe_username : null,
                $pppoe_password !== '' ? $pppoe_password : null,
                $structured_desc,
                'yes' // authorize always enables security-mgmt 998/999 (remote access)
            ]);

            // Catat audit log
            write_audit_log($olt_id, 'ONU_AUTHORIZE', "ONU {$serial_number} ({$name}) berhasil diotorisasi di PON {$pon_port} ID {$res['onu_id']}. Description: {$structured_desc}");

            $_SESSION['success'] = "ONU {$serial_number} berhasil diotorisasi di OLT {$olt['name']}!"
                . (!empty($res['speed_assign_warning']) ? " (Peringatan: speed profile gagal di-assign: {$res['speed_assign_warning']})" : '')
                . (!empty($res['wan_setup_warning']) ? " (Peringatan: setup PPPoE gagal: {$res['wan_setup_warning']})" : '');
            header('Location: ../configured.php');
            exit;
        } catch (PDOException $e) {
            error_log("[SmartOLT auth-onu] Database Error: " . $e->getMessage());
            $_SESSION['error'] = 'Gagal menyimpan ke database lokal karena kesalahan sistem internal.';
        }
    } else {
        $_SESSION['error'] = 'Gagal otorisasi OLT: ' . $res['message'];
    }

    header('Location: ../unconfigured.php?olt_id=' . $olt_id);
    exit;
}
