<?php
// ==============================================================================
# Background worker: provisioning SNMP + health-check + speed profile sync.
# Dipanggil async (exec ... &) dari add-olt.php supaya form "Tambah OLT" tidak
# menunggu CLI setup_olt_snmp()/check_olt_connection()/get_olt_speed_profiles()
# yang bisa butuh detik-menit. Hasil disimpan ke kolom last_health_check dkk
# (sama seperti get-olt-health.php) — frontend membaca status via endpoint itu.
// ==============================================================================
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/driver.php';

$olt_id = (int)($argv[1] ?? 0);
if ($olt_id <= 0) {
    exit(1);
}

$stmt = $pdo->prepare("SELECT * FROM olts WHERE id = ?");
$stmt->execute([$olt_id]);
$olt = $stmt->fetch();
if (!$olt) {
    exit(1);
}

try {
    // 1. Konfigurasi SNMP community baru di OLT.
    $snmp_setup = setup_olt_snmp($olt, $olt['snmp_community'], $olt['snmp_community_rw']);
    if (!$snmp_setup['success']) {
        error_log("[SmartOLT] background_provision_olt: gagal konfigurasi SNMP OLT id={$olt_id}: " . $snmp_setup['message']);
    }

    // 2. Health-check CLI+SNMP, simpan hasil ke kolom last_* (dibaca frontend via get-olt-health.php).
    $test = check_olt_connection($olt);
    if ($test['success']) {
        $msg = $test['message'];
        $cpu = $ram = $temp = $uptime = 'N/A';
        if (preg_match('/CPU Load\s*:\s*([^\n\r]+)/i', $msg, $m)) $cpu = trim($m[1]);
        if (preg_match('/RAM Usage\s*:\s*([^\n\r]+)/i', $msg, $m)) $ram = trim($m[1]);
        if (preg_match('/Temperature\s*:\s*([^\n\r]+)/i', $msg, $m)) $temp = trim($m[1]);
        if (preg_match('/Uptime\s*:\s*([^\n\r]+)/i', $msg, $m)) $uptime = trim($m[1]);
        $snmp_status = (stripos($msg, 'SNMP Status: Koneksi Berhasil') !== false) ? 'Connected' : 'Timeout / Error';

        $pdo->prepare("UPDATE olts SET last_cpu=?, last_ram=?, last_temp=?, last_uptime=?, last_snmp_status=?, last_health_check=CURRENT_TIMESTAMP WHERE id=?")
            ->execute([$cpu, $ram, $temp, $uptime, $snmp_status, $olt_id]);
        error_log("[SmartOLT] background_provision_olt: OLT id={$olt_id} verifikasi sukses.");
    } else {
        $pdo->prepare("UPDATE olts SET last_cpu='N/A', last_ram='N/A', last_temp='N/A', last_uptime='N/A', last_snmp_status='Disconnected', last_health_check=CURRENT_TIMESTAMP WHERE id=?")
            ->execute([$olt_id]);
        error_log("[SmartOLT] background_provision_olt: OLT id={$olt_id} verifikasi GAGAL: " . $test['message']);
    }

    // 3. Sinkronkan speed profile (Upload/Download) dari OLT ke cache lokal.
    $sp = get_olt_speed_profiles($olt);
    if ($sp['success'] ?? false) {
        $stmt_sp = $pdo->prepare("
            INSERT INTO speed_profiles (olt_id, direction, name, speed_kbps, onu_count, synced_at)
            VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
            ON DUPLICATE KEY UPDATE
                speed_kbps = VALUES(speed_kbps),
                onu_count = VALUES(onu_count),
                synced_at = CURRENT_TIMESTAMP
        ");
        foreach (($sp['profiles'] ?? []) as $p) {
            $stmt_sp->execute([$olt_id, $p['direction'], $p['name'], $p['speed_kbps'], $p['onu_count'] ?? 0]);
        }
    }
} catch (Exception $e) {
    error_log("[SmartOLT] background_provision_olt gagal untuk OLT id={$olt_id}: " . $e->getMessage());
}
