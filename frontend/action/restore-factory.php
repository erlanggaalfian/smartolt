<?php
// ==============================================================================
# Action Handler: Restore ONU Factory Defaults
// ==============================================================================
set_time_limit(0); // SSH command can exceed 30s default
require_once __DIR__ . '/../../backend/db.php';
require_once __DIR__ . '/../../backend/driver.php';

if (!isset($_SESSION['smartolt_role'])) {
    $_SESSION['error'] = 'Akses ditolak! Silakan login terlebih dahulu.';
    header('Location: ../dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)$_POST['id'];

    if ($id > 0) {
        // Ambil data detail ONU & OLT
        $stmt = $pdo->prepare("SELECT onus.*, olts.id as olt_id_raw, olts.name as olt_name, olts.ip as olt_ip, olts.username, olts.password, olts.ssh_port, olts.type as olt_type, olts.protocol as olt_protocol 
                               FROM onus 
                               JOIN olts ON onus.olt_id = olts.id 
                               WHERE onus.id = ?");
        $stmt->execute([$id]);
        $onu = $stmt->fetch();

        if ($onu) {
            check_olt_access_or_redirect($onu['olt_id_raw'], '../onu-detail.php?id=' . $id);
            $olt = [
                'ip' => $onu['olt_ip'],
                'username' => $onu['username'],
                'password' => $onu['password'],
                'ssh_port' => $onu['ssh_port'],
                'type' => $onu['olt_type'],
                'protocol' => $onu['olt_protocol'] ?? 'SSH'
            ];

            session_write_close(); // Lepas lock sesi selama komunikasi dengan OLT
            $res = restore_factory_onu($olt, $onu);
            session_start(); // Mulai kembali sesi untuk menulis sukses/error/debug log

            if (!empty($_SESSION['debug_mode']) && isset($res['log'])) {
                $cmds_display = isset($res['commands']) ? implode("\n", $res['commands']) : '(tidak tersedia)';
                $_SESSION['debug_log'] = "Commands Sent to OLT:\n{$cmds_display}\n\nOLT CLI Response Log:\n" . $res['log'];
            }

            if ($res['success']) {
                // Update status di DB — ONU akan restart dan perlu re-configure
                $stmt_upd = $pdo->prepare("UPDATE onus SET status = 'offline', last_rx_power = NULL WHERE id = ?");
                $stmt_upd->execute([$id]);

                // Audit Log
                write_audit_log($onu['olt_id_raw'], 'ONU_RESTORE_FACTORY', "ONT {$onu['serial_number']} ({$onu['name']}) di-restore factory oleh administrator.");

                $_SESSION['success'] = "ONT berhasil dikirimi perintah restore factory! ONT akan restart dan perlu diotorisasi ulang.";
            } else {
                $_SESSION['error'] = "Gagal restore factory ONT: " . $res['message'];
            }
            
            header('Location: ../onu-detail.php?id=' . $id);
            exit;
        }
    }

    header('Location: ../configured.php');
    exit;
}
