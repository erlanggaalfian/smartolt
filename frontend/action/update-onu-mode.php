<?php
// ==============================================================================
# Action Handler: Update ONU Mode & WAN Configuration (PPPoE, DHCP, Static, MGMT)
// ==============================================================================
require_once __DIR__ . '/../../backend/db.php';
require_once __DIR__ . '/../../backend/driver.php';

if (!isset($_SESSION['smartolt_role'])) {
    $_SESSION['error'] = 'Akses ditolak! Silakan login terlebih dahulu.';
    header('Location: ../dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../configured.php');
    exit;
}
set_time_limit(0); // configure_onu_full + assign_speed_profile = 2+ SSH round-trip, melebihi 30s default

$onu_id            = (int)($_POST['onu_id'] ?? 0);

if ($onu_id <= 0) {
    $_SESSION['error'] = 'ID Pelanggan tidak valid!';
    header('Location: ../configured.php');
    exit;
}

// Ambil data ONU + OLT
$stmt = $pdo->prepare("SELECT onus.*, olts.ip, olts.username, olts.password as olt_password,
                              olts.ssh_port, olts.type, olts.protocol,
                              olts.snmp_port, olts.snmp_community, olts.snmp_community_rw,
                              olts.name as olt_name
                       FROM onus
                       JOIN olts ON onus.olt_id = olts.id
                       WHERE onus.id = ?");
$stmt->execute([$onu_id]);
$row = $stmt->fetch();

if (!$row) {
    $_SESSION['error'] = 'ONU tidak ditemukan di database.';
    header('Location: ../configured.php');
    exit;
}
check_olt_access_or_redirect($row['olt_id'], '../onu-detail.php?id=' . $onu_id);

// Ambil parameter POST dengan fallback ke data lama dari database row (mencegah data terhapus saat edit per field)
$vlan_service      = isset($_POST['vlan_service']) ? (int)$_POST['vlan_service'] : (isset($_POST['vlan']) ? (int)$_POST['vlan'] : $row['vlan']);
$onu_mode          = in_array($_POST['onu_mode'] ?? '', ['Routing', 'Bridging'], true) ? $_POST['onu_mode'] : ($row['onu_mode'] ?: 'Routing');
$wan_mode          = in_array($_POST['wan_mode'] ?? '', ['Setup via ONU webpage', 'DHCP', 'Static', 'PPPoE'], true) ? $_POST['wan_mode'] : ($row['wan_mode'] ?: 'Setup via ONU webpage');

$config_method     = in_array($_POST['config_method'] ?? '', ['OMCI', 'TR069'], true) ? $_POST['config_method'] : ($row['config_method'] ?: 'OMCI');
$ip_protocol       = cli_safe_strict(isset($_POST['ip_protocol']) ? trim($_POST['ip_protocol']) : $row['ip_protocol']);
$pppoe_user        = cli_safe_email(isset($_POST['pppoe_username']) ? trim($_POST['pppoe_username']) : $row['pppoe_username']);
$pppoe_pass        = cli_safe_password(isset($_POST['pppoe_password']) ? trim($_POST['pppoe_password']) : $row['pppoe_password']);
$static_ip         = cli_safe_strict(isset($_POST['static_ip']) ? trim($_POST['static_ip']) : ($row['static_ip'] ?? ''));
$static_netmask    = cli_safe_strict(isset($_POST['static_netmask']) ? trim($_POST['static_netmask']) : ($row['static_netmask'] ?? ''));
$static_gateway    = cli_safe_strict(isset($_POST['static_gateway']) ? trim($_POST['static_gateway']) : ($row['static_gateway'] ?? ''));
$static_dns_primary   = cli_safe_strict(isset($_POST['static_dns_primary']) ? trim($_POST['static_dns_primary']) : ($row['static_dns_primary'] ?? ''));
$static_dns_secondary = cli_safe_strict(isset($_POST['static_dns_secondary']) ? trim($_POST['static_dns_secondary']) : ($row['static_dns_secondary'] ?? ''));
$wan_remote_access = in_array($_POST['wan_remote_access'] ?? '', ['yes', 'no'], true) ? $_POST['wan_remote_access'] : ($row['wan_remote_access'] ?: 'no');
$mgmt_ip_mode      = cli_safe_strict(isset($_POST['mgmt_ip_mode']) ? trim($_POST['mgmt_ip_mode']) : $row['mgmt_ip_mode']);
$mgmt_ip           = cli_safe_strict(isset($_POST['mgmt_ip']) ? trim($_POST['mgmt_ip']) : $row['mgmt_ip']);
$mgmt_vlan         = isset($_POST['mgmt_vlan']) ? (int)$_POST['mgmt_vlan'] : $row['mgmt_vlan'];
$serial_number     = cli_safe_strict(isset($_POST['serial_number']) ? trim($_POST['serial_number']) : $row['serial_number']);
$onu_type          = cli_safe_strict(isset($_POST['onu_type']) ? trim($_POST['onu_type']) : $row['onu_type']);
$config_preset     = cli_safe(isset($_POST['config_preset']) ? trim($_POST['config_preset']) : $row['config_preset']);
$zone              = cli_safe(isset($_POST['zone']) ? trim($_POST['zone']) : ($row['zone'] ?: 'None'));
$splitter          = cli_safe(isset($_POST['splitter']) ? trim($_POST['splitter']) : ($row['splitter'] ?: 'None'));
$odb_port          = cli_safe(isset($_POST['odb_port']) ? trim($_POST['odb_port']) : ($row['odb_port'] ?? ''));
$name              = cli_safe(isset($_POST['name']) ? trim($_POST['name']) : $row['name']);
if ($name === '') {
    $name = 'None';
}
$address           = cli_safe(isset($_POST['address']) ? trim($_POST['address']) : ($row['address'] ?: 'None'));
$contact           = cli_safe(isset($_POST['contact']) ? trim($_POST['contact']) : ($row['contact'] ?: 'None'));
$external_id       = cli_safe(isset($_POST['external_id']) ? trim($_POST['external_id']) : ($row['external_id'] ?? ''));
$latitude          = isset($_POST['latitude'])  && $_POST['latitude']  !== '' ? (float)$_POST['latitude']  : ($row['latitude']  ?? null);
$longitude         = isset($_POST['longitude']) && $_POST['longitude'] !== '' ? (float)$_POST['longitude'] : ($row['longitude'] ?? null);

// Convert 'None' sentinel to null for DB storage (same pattern as auth-onu.php)
$zone_db          = ($zone !== 'None' && $zone !== '') ? $zone : null;
$splitter_db      = ($splitter !== 'None' && $splitter !== '') ? $splitter : null;
$address_db       = ($address !== 'None' && $address !== '') ? $address : null;
$contact_db       = ($contact !== 'None' && $contact !== '') ? $contact : null;
$config_preset_db = ($config_preset !== 'None' && $config_preset !== '') ? $config_preset : null;
$name_db          = ($name !== 'None' && $name !== '') ? $name : null;

$olt = [
    'id'               => (int)$row['olt_id'],
    'ip'               => $row['ip'],
    'username'         => $row['username'],
    'password'         => $row['olt_password'],
    'ssh_port'         => $row['ssh_port'],
    'type'             => $row['type'],
    'protocol'         => $row['protocol']        ?? 'SSH',
    'snmp_port'        => $row['snmp_port']        ?? 161,
    'snmp_community'   => $row['snmp_community']   ?? 'public',
    'snmp_community_rw'=> $row['snmp_community_rw'] ?? 'public',
];

$old_desc = $row['description'] ?? $row['name'] ?? '';
$auth_date = '';
if (preg_match('/authd_(\d{8})/i', $old_desc, $m)) {
    $auth_date = $m[1];
} else {
    $auth_date = date('Ymd', strtotime($row['created_at'] ?? 'now'));
}

$new_desc = "name_{$name}_zone_{$zone}_descr_{$address}_odb_{$splitter}_authd_{$auth_date}";
if ($contact !== 'None' && $contact !== '') {
    $new_desc .= "_contact_{$contact}";
}

$onu = [
    'pon_port'      => $row['pon_port'],
    'onu_id'        => $row['onu_id'],
    'serial_number' => $serial_number ?: $row['serial_number'],
    'name'          => $name,
    'description'   => $new_desc,
];

$download_profile = trim($_POST['download_profile'] ?? '') !== '' ? trim($_POST['download_profile']) : ($row['download_profile'] ?? '');
$upload_profile   = trim($_POST['upload_profile'] ?? '') !== '' ? trim($_POST['upload_profile']) : ($row['upload_profile'] ?? '');

$wan = [
    'pppoe_username'        => $pppoe_user,
    'pppoe_password'        => $pppoe_pass,
    'static_ip'             => $static_ip,
    'static_netmask'        => $static_netmask,
    'static_gateway'        => $static_gateway,
    'static_dns_primary'    => $static_dns_primary,
    'static_dns_secondary'  => $static_dns_secondary,
    'vlan_service'          => $vlan_service,
    'priority'              => 0,
    'onu_mode'              => $onu_mode,
    'wan_mode'              => $wan_mode,
    'config_method'         => $config_method,
    'ip_protocol'           => $ip_protocol,
    'wan_remote_access'     => $wan_remote_access,
    'mgmt_ip_mode'          => $mgmt_ip_mode,
    'mgmt_ip'               => $mgmt_ip,
];

$change_scope = $_POST['change_scope'] ?? 'wan_config';

// Hanya kirim konfigurasi ke OLT kalau user benar-benar mengubah setting WAN/ONU mode.
// Edit identity/preset/onu_type hanya perlu update DB + description string —
// tidak perlu SSH round-trip ke OLT yang sia-sia dan bisa gagal kalau sesi VTY penuh.
$olt_affecting = ($change_scope === 'wan_config');

// Identity edits must also push the new description to the OLT,
// otherwise the next SNMP sync overwrites the local edits from the OLT's old description.
$desc_affecting = ($change_scope === 'identity');

session_write_close(); // Lepas lock sesi selama komunikasi dengan OLT
$configure_success = true;
$result = ['log' => '', 'commands' => []];

$desc_sync_ok = true;
if ($olt_affecting) {
    // Kirim konfigurasi lengkap ke OLT
    $result = configure_onu_full($olt, $onu, $wan);
    $configure_success = $result['success'];

} elseif ($desc_affecting) {
    // Lightweight: update only description on OLT (no WAN config touch)
    $desc_result = update_onu_description($olt, $row['pon_port'], (int)$row['onu_id'], $new_desc);
    if (!$desc_result['success']) {
        error_log("[SmartOLT update-onu-mode] Description sync to OLT failed: " . ($desc_result['message'] ?? 'unknown'));
        $desc_sync_ok = false;
        // Non-fatal: DB is already updated, sync may fix later. Don't block the success message.
    }
    $result = $desc_result;
}

// Terapkan speed profile (tcont/gemport) jika berubah dari yang tersimpan
$speed_update_ok = true;
if ($olt_affecting && $configure_success && $download_profile !== '' && $upload_profile !== ''
    && ($download_profile !== ($row['download_profile'] ?? '') || $upload_profile !== ($row['upload_profile'] ?? ''))) {
    $speed_result = assign_onu_speed_profile($olt, $row['pon_port'], (int)$row['onu_id'], $upload_profile, $download_profile);
    if (!empty($speed_result['success'])) {
        $stmt = $pdo->prepare("UPDATE onus SET download_profile = ?, upload_profile = ? WHERE id = ?");
        $stmt->execute([$download_profile, $upload_profile, $onu_id]);
    } else {
        // Speed profile gagal — jangan blokir update DB untuk field lain yang SUDAH berhasil di OLT
        $result['log'] = ($result['log'] ?? '') . "\n\n[Speed Profile] Gagal: " . ($speed_result['log'] ?? 'unknown error');
        $speed_update_ok = false;
    }
}
session_start(); // Mulai kembali sesi untuk menulis sukses/error/debug log

if (!empty($_SESSION['debug_mode']) && isset($result['log'])) {
    $cmds_display = isset($result['commands']) ? implode("\n", $result['commands']) : '(tidak tersedia)';
    $_SESSION['debug_log'] = "Commands Sent to OLT:\n{$cmds_display}\n\nOLT CLI Response Log:\n" . $result['log'];
}

if ($configure_success) {
    try {
        // Simpan setelan baru ke database
        $stmt_upd = $pdo->prepare("
            UPDATE onus 
            SET vlan = ?, 
                pppoe_username = ?, 
                pppoe_password = ?,
                static_ip = ?,
                static_netmask = ?,
                static_gateway = ?,
                static_dns_primary = ?,
                static_dns_secondary = ?,
                onu_mode = ?, 
                wan_mode = ?, 
                config_method = ?, 
                ip_protocol = ?, 
                wan_remote_access = ?, 
                mgmt_ip_mode = ?, 
                mgmt_ip = ?, 
                mgmt_vlan = ?,
                serial_number = ?,
                name = ?,
                onu_type = ?,
                config_preset = ?,
                zone = ?,
                splitter = ?,
                odb_port = ?,
                address = ?,
                contact = ?,
                external_id = ?,
                latitude = ?,
                longitude = ?,
                description = ?
            WHERE id = ?
        ");
        $stmt_upd->execute([
            $vlan_service,
            $pppoe_user,
            $pppoe_pass,
            $static_ip ?: null,
            $static_netmask ?: null,
            $static_gateway ?: null,
            $static_dns_primary ?: null,
            $static_dns_secondary ?: null,
            $onu_mode,
            $wan_mode,
            $config_method,
            $ip_protocol,
            $wan_remote_access,
            $mgmt_ip_mode,
            $mgmt_ip,
            $mgmt_vlan,
            $onu['serial_number'],
            $name_db,
            $onu_type,
            $config_preset_db,
            $zone_db,
            $splitter_db,
            $odb_port,
            $address_db,
            $contact_db,
            $external_id ?: null,
            $latitude,
            $longitude,
            $new_desc,
            $onu_id
        ]);

        write_audit_log(
            $row['olt_id'],
            $olt_affecting ? 'ONU_MODE_UPDATE' : 'ONU_IDENTITY_UPDATE',
            ($olt_affecting ? "Update ONU mode & WAN" : "Update identitas") . " {$onu['serial_number']} ({$row['name']})"
                . ($olt_affecting ? " — Mode: {$onu_mode}, WAN: {$wan_mode}, VLAN: {$vlan_service}" : " — Scope: {$change_scope}")
        );

        $_SESSION['success'] = $olt_affecting
            ? "Konfigurasi mode ONU {$onu['serial_number']} berhasil diperbarui di OLT dan disimpan di database!"
                . (!$speed_update_ok ? " (Peringatan: speed profile gagal di-assign)" : '')
            : "Data ONU {$onu['serial_number']} berhasil diperbarui"
                . ($desc_affecting ? ($desc_sync_ok ? " dan disinkronkan ke OLT" : " (sinkronisasi ke OLT gagal — deskripsi akan diperbaiki otomatis oleh sync berikutnya)") : "")
                . ".";
    } catch (PDOException $e) {
        error_log("[SmartOLT update-onu-mode] DB Error: " . $e->getMessage());
        $_SESSION['error'] = 'Koneksi ke OLT sukses, namun gagal menyimpan status ke DB lokal karena kesalahan internal.';
    }
} else {
    $_SESSION['error'] = $olt_affecting
        ? 'Gagal mengirim konfigurasi ke OLT: ' . ($result['message'] ?? 'Error tidak diketahui.')
        : 'Gagal menyimpan data ke database.';
}

header("Location: ../onu-detail.php?id={$onu_id}");
exit;
