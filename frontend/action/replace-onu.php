<?php
// ==============================================================================
# Action Handler: Replace ONU (Ganti Serial Number & Otorisasi Ulang)
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
if (!verify_csrf_token()) {
    $_SESSION['error'] = 'Token keamanan tidak valid.';
    header('Location: ../onu-detail.php?id=' . ((int)($_POST['onu_id'] ?? 0)));
    exit;
}
set_time_limit(0); // delete + authorize + configure_onu_full = 3+ SSH round-trip

$onu_id            = (int)($_POST['onu_id'] ?? 0);
$new_serial_number = trim($_POST['new_serial_number'] ?? '');

if ($onu_id <= 0 || empty($new_serial_number)) {
    $_SESSION['error'] = 'ID ONU atau Serial Number baru tidak valid!';
    header('Location: ../configured.php');
    exit;
}

// 1. Ambil data ONU + OLT lama dari DB
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

// OLT-structured description format
$auth_date = date('Ymd');
$name = $row['name'];
$zone = $row['zone'] ?: 'None';
$address = $row['address'] ?: 'None';
$splitter = $row['splitter'] ?: 'None';
$contact = $row['contact'] ?: 'None';

$structured_desc = "name_{$name}_zone_{$zone}_descr_{$address}_odb_{$splitter}_authd_{$auth_date}";
if ($contact !== 'None' && $contact !== '') {
    $structured_desc .= "_contact_{$contact}";
}

// 2. Siapkan data ONU lama untuk dihapus
$old_onu_data = [
    'pon_port'      => $row['pon_port'],
    'onu_id'        => $row['onu_id'],
    'serial_number' => $row['serial_number'],
    'name'          => $row['name'],
];

session_write_close(); // Lepas lock sesi selama komunikasi dengan OLT

// STEP A: Hapus ONU lama dari OLT
$del_res = delete_onu_from_olt($olt, $old_onu_data);

if (!$del_res['success']) {
    session_start();
    $_SESSION['error'] = 'Gagal menghapus ONU lama dari OLT: ' . ($del_res['message'] ?? 'Error tidak diketahui.');
    header("Location: ../onu-detail.php?id={$onu_id}");
    exit;
}

// STEP B: Daftarkan ONU baru ke OLT dengan SN baru
// WAJIB kirim speed profiles supaya authorize_onu() buat tcont/gemport SEBELUM
// service-port — tanpa ini OLT tolak %Code 66657 (GEM port does not exist).
$auth_res = authorize_onu($olt, $row['pon_port'], $new_serial_number, $row['name'], $row['vlan'], $structured_desc, $row['onu_id'],
    $row['wan_mode'] ?: 'Setup via ONU webpage', $row['pppoe_username'], $row['pppoe_password'],
    $row['upload_profile'], $row['download_profile']);

if (!$auth_res['success']) {
    // Upaya mendaftarkan ulang ONU lama jika gagal authorize yang baru agar OLT tidak kosong (best-effort)
    authorize_onu($olt, $row['pon_port'], $row['serial_number'], $row['name'], $row['vlan'], $structured_desc, $row['onu_id'],
        $row['wan_mode'] ?: 'Setup via ONU webpage', $row['pppoe_username'], $row['pppoe_password'],
        $row['upload_profile'], $row['download_profile']);
    
    session_start();
    $_SESSION['error'] = 'Gagal mendaftarkan ONU baru pada OLT: ' . ($auth_res['message'] ?? 'Error tidak diketahui.');
    header("Location: ../onu-detail.php?id={$onu_id}");
    exit;
}

// STEP C: Kirim konfigurasi WAN / PPPoE lama ke ONU baru
$new_onu_data = [
    'pon_port'      => $row['pon_port'],
    'onu_id'        => $row['onu_id'],
    'serial_number' => $new_serial_number,
    'name'          => $row['name'],
];

$wan_config = [
    'pppoe_username'        => $row['pppoe_username'],
    'pppoe_password'        => $row['pppoe_password'],
    'vlan_service'          => $row['vlan'],
    'vlan_mgmt'             => $row['mgmt_vlan'] ?: 100,
    'dba_profile_id'        => 5, // default
    'downstream_profile_id' => 1, // default
    'priority'              => 0,
    'onu_mode'              => $row['onu_mode'] ?: 'Routing',
    'wan_mode'              => $row['wan_mode'] ?: 'Setup via ONU webpage',
    'config_method'         => $row['config_method'] ?: 'OMCI',
    'ip_protocol'           => $row['ip_protocol'] ?: 'IPv4',
    'wan_remote_access'     => $row['wan_remote_access'] ?: 'no',
    'mgmt_ip_mode'          => $row['mgmt_ip_mode'] ?: 'Inactive',
    'mgmt_ip'               => $row['mgmt_ip'],
];

$conf_res = configure_onu_full($olt, $new_onu_data, $wan_config);

// Cek status sinyal optik setelah replace
$signal = get_onu_signal($olt, $new_onu_data);
$rx_power = $signal['rx_onu'] !== 'N/A' ? (float)$signal['rx_onu'] : null;
$status = $signal['status'] ?: 'offline';

session_start(); // Mulai kembali sesi

if ($conf_res['success']) {
    try {
        // Update data ONU di database lokal dengan Serial Number baru
        $stmt_up = $pdo->prepare("
            UPDATE onus 
            SET serial_number = ?,
                status = ?,
                last_rx_power = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $stmt_up->execute([$new_serial_number, $status, $rx_power, $onu_id]);

        write_audit_log(
            $row['olt_id'],
            'ONU_REPLACE',
            "Berhasil me-replace ONT lama ({$row['serial_number']}) dengan ONT baru ({$new_serial_number}) untuk pelanggan {$row['name']}."
        );

        $_SESSION['success'] = "Berhasil mengganti ONU lama dengan ONU baru! Serial Number baru: {$new_serial_number}.";
    } catch (PDOException $e) {
        error_log("[SmartOLT replace-onu] DB Error: " . $e->getMessage());
        $_SESSION['error'] = 'Koneksi ke OLT sukses, namun gagal menyimpan Serial Number baru ke DB lokal karena kesalahan internal.';
    }
} else {
    // DB tetap di-update dengan SN baru karena authorize sudah sukses
    $pdo->prepare("UPDATE onus SET serial_number = ?, status = ?, last_rx_power = ? WHERE id = ?")
        ->execute([$new_serial_number, $status, $rx_power, $onu_id]);
        
    $_SESSION['error'] = "ONT berhasil didaftarkan dengan SN baru {$new_serial_number}, tetapi gagal mengirimkan konfigurasi WAN: " . ($conf_res['message'] ?? 'Error tidak diketahui.');
}

header("Location: ../onu-detail.php?id={$onu_id}");
exit;
