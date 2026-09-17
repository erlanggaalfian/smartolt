<?php
// ==============================================================================
# Action Handler: Hapus / Unbind ONU
// ==============================================================================
set_time_limit(0); // SSH delete command can exceed 30s default
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
            check_olt_access_or_redirect($onu['olt_id_raw'], '../configured.php');
            $olt = [
                'id' => (int)$onu['olt_id_raw'],
                'ip' => $onu['olt_ip'],
                'username' => $onu['username'],
                'password' => $onu['password'],
                'ssh_port' => $onu['ssh_port'],
                'type' => $onu['olt_type'],
                'protocol' => $onu['olt_protocol'] ?? 'SSH'
            ];

            session_write_close(); // Lepas lock sesi selama komunikasi dengan OLT
            // Kirim perintah delete ke OLT
            $res = delete_onu_from_olt($olt, $onu);
            session_start(); // Mulai kembali sesi untuk menulis sukses/error/debug log

            if (!empty($_SESSION['debug_mode']) && isset($res['log'])) {
                $cmds_display = isset($res['commands']) ? implode("\n", $res['commands']) : '(tidak tersedia)';
                $_SESSION['debug_log'] = "Commands Sent to OLT:\n{$cmds_display}\n\nOLT CLI Response Log:\n" . $res['log'];
            }

            if ($res['success']) {
                // Hapus dari database local cache
                $stmt_del = $pdo->prepare("DELETE FROM onus WHERE id = ?");
                $stmt_del->execute([$id]);

                // Audit Log
                write_audit_log($onu['olt_id_raw'], 'ONU_DELETE', "ONT {$onu['serial_number']} ({$onu['name']}) berhasil dihapus / unbind dari PON {$onu['pon_port']} ID {$onu['onu_id']}.");

                $_SESSION['success'] = "ONT {$onu['serial_number']} berhasil dihapus dari OLT dan database lokal.";
            } else {
                $_SESSION['error'] = "Gagal menghapus ONT dari OLT: " . $res['message'];
                $redirect_url = '../onu-detail.php?id=' . $id;
                header('Location: ' . $redirect_url);
                exit;
            }
        }
    }

    header('Location: ../configured.php');
    exit;
}
