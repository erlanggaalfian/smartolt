<?php
// ==============================================================================
# Action Handler: ONU Admin State (enable | disable)
# Dipanggil form Enable/Disable ONU di onu-detail.php dengan POST id + action.
// ==============================================================================
require_once __DIR__ . '/../../backend/db.php';
require_once __DIR__ . '/../../backend/driver.php';

if (!isset($_SESSION['smartolt_role'])) {
    $_SESSION['error'] = 'Akses ditolak! Silakan login terlebih dahulu.';
    header('Location: ../dashboard.php');
    exit;
}

set_time_limit(0); // SNMP set + CLI fallback bisa lewat 30s default

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../configured.php');
    exit;
}

$id     = (int)($_POST['id'] ?? 0);
$action = $_POST['action'] ?? '';

// Peta aksi: menentukan fungsi driver, status DB, kode audit, dan teks pesan.
$MAP = [
    'enable' => [
        'fn'      => 'enable_onu_on_olt',
        'status'  => 'offline', // biar cron sync yang menentukan status sebenarnya
        'audit'   => 'ONU_ENABLE',
        'audit_t' => 'diaktifkan kembali secara administratif',
        'ok'      => 'berhasil diaktifkan kembali di OLT dan ditandai di DB',
        'fail'    => 'Gagal mengaktifkan ONT',
    ],
    'disable' => [
        'fn'      => 'disable_onu_on_olt',
        'status'  => 'disabled',
        'audit'   => 'ONU_DISABLE',
        'audit_t' => 'dinonaktifkan secara administratif',
        'ok'      => 'berhasil dinonaktifkan di OLT dan ditandai di DB',
        'fail'    => 'Gagal menonaktifkan ONT',
    ],
];

if ($id <= 0 || !isset($MAP[$action])) {
    header('Location: ../configured.php');
    exit;
}
$cfg = $MAP[$action];

$stmt = $pdo->prepare("SELECT onus.*, olts.id as olt_id_raw, olts.name as olt_name, olts.ip as olt_ip, olts.username, olts.password, olts.ssh_port, olts.type as olt_type, olts.protocol as olt_protocol 
                       FROM onus 
                       JOIN olts ON onus.olt_id = olts.id 
                       WHERE onus.id = ?");
$stmt->execute([$id]);
$onu = $stmt->fetch();

if (!$onu) {
    header('Location: ../configured.php');
    exit;
}

check_olt_access_or_redirect($onu['olt_id_raw'], '../configured.php');

$olt = [
    'id'       => (int)$onu['olt_id_raw'],
    'ip'       => $onu['olt_ip'],
    'username' => $onu['username'],
    'password' => $onu['password'],
    'ssh_port' => $onu['ssh_port'],
    'type'     => $onu['olt_type'],
    'protocol' => $onu['olt_protocol'] ?? 'SSH'
];

// Try SNMP first (fast, ~1s), fallback to CLI (~10s)
$snmp_success = false;
$olt_id_for_snmp = $onu['olt_id_raw'] ?? $onu['olt_id'];
$snmp_olt = null;
if ($olt_id_for_snmp) {
    $snmp_stmt = $pdo->prepare("SELECT ip, snmp_community_rw, snmp_port, type FROM olts WHERE id = ?");
    $snmp_stmt->execute([$olt_id_for_snmp]);
    $snmp_olt = $snmp_stmt->fetch();
}
if ($snmp_olt && stripos($snmp_olt['type'], 'ZTE') !== false) {
    $python_bin = dirname(dirname(__DIR__)) . '/backend/python_engine/venv/bin/python3';
    $snmp_script = dirname(dirname(__DIR__)) . '/backend/python_engine/snmp_module.py';
    $admin_val = ($action === 'disable') ? 2 : 1;
    $pon_port = $onu['pon_port'];
    $onu_id = $onu['onu_id'];
    $cmd = escapeshellarg($python_bin) . ' -c '
         . escapeshellarg("import sys; sys.path.insert(0, " . escapeshellarg(dirname($snmp_script)) . "); import snmp_module as s; r=s.snmpset_onu_admin(" . escapeshellarg($snmp_olt['ip']) . "," . escapeshellarg($snmp_olt['snmp_community_rw']) . "," . escapeshellarg($pon_port) . "," . (int)$onu_id . "," . $admin_val . "," . (int)($snmp_olt['snmp_port'] ?? 161) . "); print('OK' if r else 'FAIL')")
         . ' 2>&1';
    $out = trim(shell_exec($cmd));
    if ($out === 'OK') {
        $snmp_success = true;
        $res = ['success' => true, 'log' => 'SNMP admin state set to ' . ($action === 'disable' ? 'disable(2)' : 'enable(1)')];
    }
}

// Fallback to CLI if SNMP failed
if (!$snmp_success) {
    session_write_close();
    $res = $cfg['fn']($olt, $onu);
    session_start();
}

if (!empty($_SESSION['debug_mode']) && isset($res['log'])) {
    $cmds_display = isset($res['commands']) ? implode("\n", $res['commands']) : '(tidak tersedia)';
    $_SESSION['debug_log'] = "Commands Sent to OLT:\n{$cmds_display}\n\nOLT CLI Response Log:\n" . $res['log'];
}

if ($res['success']) {
    $stmt_upd = $pdo->prepare("UPDATE onus SET status = ? WHERE id = ?");
    $stmt_upd->execute([$cfg['status'], $id]);

    write_audit_log($onu['olt_id_raw'], $cfg['audit'], "ONT {$onu['serial_number']} ({$onu['name']}) {$cfg['audit_t']} oleh administrator.");

    $_SESSION['success'] = "ONT {$onu['serial_number']} {$cfg['ok']}.";
} else {
    $_SESSION['error'] = $cfg['fail'] . ": " . $res['message'];
}

header('Location: ../onu-detail.php?id=' . $id);
exit;
