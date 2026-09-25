<?php
// ==============================================================================
# Action Handler: Perintah TR-069 (Refresh interfaces / Reboot / Reset to factory)
// Setara tombol di GenieACS UI, dipanggil via NBI -- HANYA untuk ONU config_method=TR069.
// Dipanggil via fetch() AJAX dari panel TR069 Status (respons JSON), bukan form submit.
// ==============================================================================
require_once __DIR__ . '/../../backend/db.php';
require_once __DIR__ . '/../../backend/genieacs.php';

header('Content-Type: application/json');

if (!isset($_SESSION['smartolt_role'])) {
    echo json_encode(['success' => false, 'message' => 'Akses ditolak! Silakan login terlebih dahulu.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token()) {
        echo json_encode(['success' => false, 'message' => 'Token keamanan tidak valid.']);
        exit;
    }
    $id = (int)($_POST['id'] ?? 0);
    $action = $_POST['tr069_action'] ?? '';

    if ($id > 0 && in_array($action, ['refresh', 'reboot', 'factory_reset'], true)) {
        $stmt = $pdo->prepare("SELECT onus.*, olts.id as olt_id_raw FROM onus JOIN olts ON onus.olt_id = olts.id WHERE onus.id = ?");
        $stmt->execute([$id]);
        $onu = $stmt->fetch();

        if ($onu) {
            if (!has_olt_access($onu['olt_id_raw'])) {
                echo json_encode(['success' => false, 'message' => 'Akses OLT ditolak.']);
                exit;
            }

            if (($onu['config_method'] ?? '') !== 'TR069') {
                echo json_encode(['success' => false, 'message' => 'ONU ini bukan config_method TR-069.']);
                exit;
            }

            switch ($action) {
                case 'refresh':
                    $res = genieacs_refresh_interfaces($onu['serial_number']);
                    $label = 'Refresh interfaces';
                    break;
                case 'reboot':
                    $res = genieacs_reboot_device($onu['serial_number']);
                    $label = 'Reboot';
                    // Sama seperti reboot CLI: tandai offline sementara, cron/polling akan update ulang.
                    if ($res['success']) {
                        $stmt_upd = $pdo->prepare("UPDATE onus SET status = 'offline', last_rx_power = NULL WHERE id = ?");
                        $stmt_upd->execute([$id]);
                    }
                    break;
                case 'factory_reset':
                    $res = genieacs_factory_reset_device($onu['serial_number']);
                    $label = 'Reset to factory';
                    if ($res['success']) {
                        $stmt_upd = $pdo->prepare("UPDATE onus SET status = 'offline', last_rx_power = NULL WHERE id = ?");
                        $stmt_upd->execute([$id]);
                    }
                    break;
            }

            if ($res['success']) {
                write_audit_log($onu['olt_id_raw'], 'ONU_TR069_' . strtoupper($action), "ONT {$onu['serial_number']} ({$onu['name']}) -- {$label} via TR-069 oleh administrator.");
            }
            echo json_encode(['success' => $res['success'], 'message' => "{$label}: " . $res['message']]);
            exit;
        }
        echo json_encode(['success' => false, 'message' => 'ONU tidak ditemukan.']);
        exit;
    }
    echo json_encode(['success' => false, 'message' => 'Parameter tidak valid.']);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Metode tidak diizinkan.']);
