<?php
// ==============================================================================
# Cron: cek status semua OLT setiap 1 menit.
# Jalankan via crontab: * * * * * php /path/to/backend/cron_check_olt_status.php
# Atau via Windows Task Scheduler: setiap 1 menit.
//
# Script ini ringan — hanya SSH connect + SNMP ping, TIDAK menjalankan
# show running-config atau pull data berat lainnya.
// ==============================================================================
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/driver.php';

// Cegah overlap jika eksekusi sebelumnya belum selesai
$lock_file = sys_get_temp_dir() . '/smartolt_cron_olt_check.lock';
$lock_fp = fopen($lock_file, 'c');
if (!flock($lock_fp, LOCK_EX | LOCK_NB)) {
    // Masih ada proses sebelumnya jalan
    fclose($lock_fp);
    exit(0);
}

try {
    $stmt = $pdo->query("SELECT id, ip, username, password, ssh_port, type, protocol,
                                snmp_port, snmp_community, snmp_community_rw
                         FROM olts");
    $olts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $update = $pdo->prepare("UPDATE olts SET last_snmp_status = ?, last_health_check = NOW() WHERE id = ?");

    foreach ($olts as $olt) {
        try {
            // Cek SNMP ping (lebih cepat, ~1 detik)
            $snmp_ok = check_snmp_ping($olt);

            // Cek SSH connection via driver (timeout 10 detik)
            $ssh_ok = false;
            try {
                $res = call_driver($olt, 'checkConnection');
                $ssh_ok = !empty($res['success']);
            } catch (Exception $e) {
                $ssh_ok = false;
            }

            if ($ssh_ok && $snmp_ok) {
                $status = 'Connected';
            } elseif ($ssh_ok) {
                $status = 'SSH Only';
            } elseif ($snmp_ok) {
                $status = 'SNMP Only';
            } else {
                $status = 'Disconnected';
            }

            $update->execute([$status, $olt['id']]);
        } catch (Exception $e) {
            $update->execute(['Error', $olt['id']]);
            error_log("[SmartOLT cron_olt_check] OLT {$olt['ip']}: " . $e->getMessage());
        }
    }
} finally {
    flock($lock_fp, LOCK_UN);
    fclose($lock_fp);
    @unlink($lock_file);
}