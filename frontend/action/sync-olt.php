<?php
// ==============================================================================
# Action Handler: Sinkronisasi Data Pelanggan OLT
// ==============================================================================
set_time_limit(300); // Sinkronisasi butuh waktu lama (SSH ke OLT + curl ke engine)
require_once __DIR__ . '/../../backend/db.php';
require_once __DIR__ . '/../../backend/driver.php';

$is_ajax = isset($_GET['ajax']) && $_GET['ajax'] === '1';

if (!isset($_SESSION['smartolt_role'])) {
    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Akses ditolak! Silakan login terlebih dahulu.']);
        exit;
    }
    $_SESSION['error'] = 'Akses ditolak!';
    header('Location: ../dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['olt_id'])) {
    $olt_id = isset($_POST['olt_id']) ? (int)$_POST['olt_id'] : (int)$_GET['olt_id'];

    if (empty($olt_id)) {
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'ID OLT tidak valid!']);
            exit;
        }
        $_SESSION['error'] = 'ID OLT tidak valid!';
        header('Location: ../configured.php');
        exit;
    }
    
    if ($is_ajax) {
        if (!has_olt_access($olt_id)) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Akses ditolak! Anda tidak memiliki izin untuk mengakses OLT ini.']);
            exit;
        }
    } else {
        check_olt_access_or_redirect($olt_id, '../configured.php');
    }

    // Ambil data OLT
    $stmt = $pdo->prepare("SELECT * FROM olts WHERE id = ?");
    $stmt->execute([$olt_id]);
    $olt = $stmt->fetch();

    if (!$olt) {
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'OLT tidak ditemukan!']);
            exit;
        }
        $_SESSION['error'] = 'OLT tidak ditemukan!';
        header('Location: ../configured.php');
        exit;
    }

    // Uji SNMP ping secara instan sebelum melakukan login SSH/Telnet yang mahal
    if (!check_snmp_ping($olt)) {
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => "❌ Gagal sinkronisasi: OLT offline atau tidak merespon SNMP pada port {$olt['snmp_port']}!"]);
            exit;
        }
        $_SESSION['error'] = "❌ Gagal sinkronisasi: OLT offline atau tidak merespon SNMP pada port {$olt['snmp_port']}!";
        header("Location: ../configured.php?olt_id={$olt_id}");
        exit;
    }

    // Tarik data ONU terkonfigurasi dari OLT
    try {
        session_write_close(); // Lepas lock sesi selama komunikasi dengan OLT
        $pulled = pull_configured_onus($olt);
        if (isset($pulled['success']) && !$pulled['success']) {
            throw new Exception($pulled['message'] ?? 'Gagal menarik data dari OLT.');
        }
        $onus = $pulled['onus'] ?? [];

        $ipconfig_map = [];
        $name_map     = [];
        $desc_map     = [];
        // Peta konfigurasi detail (VLAN, PPPoE, nama bersih) ditarik di background asinkron
        // melalui cron_sync.py --force-config untuk mencegah pemuatan halaman web tersumbat/lambat.
        session_start(); // Mulai kembali sesi untuk menulis sukses/error/debug log

        // Simpan ke database local (Insert / Update on Duplicate Key)
        $stmt_insert = $pdo->prepare("
            INSERT INTO onus (
                olt_id, pon_port, onu_id, name, serial_number, status, vlan, pppoe_username, pppoe_password, wan_mode,
                zone, splitter, address, contact, updated_at
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
            ON DUPLICATE KEY UPDATE 
                name = CASE WHEN VALUES(name) LIKE 'ONU_%' AND onus.name IS NOT NULL AND onus.name != '' THEN onus.name ELSE VALUES(name) END,
                status = VALUES(status),
                vlan = COALESCE(VALUES(vlan), onus.vlan),
                pppoe_username = COALESCE(VALUES(pppoe_username), onus.pppoe_username),
                pppoe_password = COALESCE(VALUES(pppoe_password), onus.pppoe_password),
                wan_mode = COALESCE(VALUES(wan_mode), onus.wan_mode),
                zone = COALESCE(VALUES(zone), onus.zone),
                splitter = COALESCE(VALUES(splitter), onus.splitter),
                address = COALESCE(VALUES(address), onus.address),
                contact = COALESCE(VALUES(contact), onus.contact),
                updated_at = CURRENT_TIMESTAMP
        ");

        $pdo->beginTransaction();
        try {
            $sync_count = 0;
            $active_onu_keys = [];
            foreach ($onus as $onu) {
                if (!is_array($onu) || empty($onu['pon_port']) || !isset($onu['onu_id'])) {
                    continue;
                }
                $key = "{$onu['pon_port']}_{$onu['onu_id']}";
                $active_onu_keys[] = $key;

                $vlan_val = isset($ipconfig_map[$key]['vlan']) ? $ipconfig_map[$key]['vlan'] : null;
                $user_val = isset($ipconfig_map[$key]['pppoe_username']) ? $ipconfig_map[$key]['pppoe_username'] : null;
                $pass_val = isset($ipconfig_map[$key]['pppoe_password']) ? $ipconfig_map[$key]['pppoe_password'] : null;
                $mode_val = isset($ipconfig_map[$key]['wan_mode']) ? $ipconfig_map[$key]['wan_mode'] : null;

                // Defaults
                $name_val = $onu['name'];
                $desc_val = $onu['name'];

                if (isset($name_map[$key])) {
                    $name_val = $name_map[$key];
                }
                if (isset($desc_map[$key])) {
                    $desc_val = $desc_map[$key];
                }

                // Fallback jika name_val berisi data zone terstruktur
                if (stripos($name_val, 'zone_') !== false) {
                    $desc_val = $name_val;
                    $name_val = extract_customer_name($name_val);
                }

                // Parse deskripsi terstruktur ke variabel database
                $parsed = parse_structured_description($desc_val);
                $zone_val     = ($parsed['zone'] !== 'None') ? $parsed['zone'] : null;
                $splitter_val = ($parsed['splitter'] !== 'None') ? $parsed['splitter'] : null;
                $address_val  = ($parsed['address'] !== 'None') ? $parsed['address'] : null;
                $contact_val  = ($parsed['contact'] !== 'None') ? $parsed['contact'] : null;

                $stmt_insert->execute([
                    $olt['id'],
                    $onu['pon_port'],
                    $onu['onu_id'],
                    $name_val,
                    $onu['serial_number'],
                    $onu['status'],
                    $vlan_val,
                    $user_val,
                    $pass_val,
                    $mode_val,
                    $zone_val,
                    $splitter_val,
                    $address_val,
                    $contact_val
                ]);
                $sync_count++;
            }

            // Hapus ONU yang tidak ada lagi di OLT dari database local cache
            // Grace period 5 menit: jangan hapus baris yang baru di-insert/update
            // (mis. dari authorize_onu) karena OLT belum publish ke daftar onu.
            if (!empty($active_onu_keys)) {
                $stmt_local = $pdo->prepare("SELECT id, pon_port, onu_id, updated_at FROM onus WHERE olt_id = ?");
                $stmt_local->execute([$olt['id']]);
                $local_db_onus = $stmt_local->fetchAll();
                $grace_cutoff = date('Y-m-d H:i:s', time() - 300);

                foreach ($local_db_onus as $local_onu) {
                    $local_key = "{$local_onu['pon_port']}_{$local_onu['onu_id']}";
                    if (!in_array($local_key, $active_onu_keys) && $local_onu['updated_at'] < $grace_cutoff) {
                        $stmt_delete_stale = $pdo->prepare("DELETE FROM onus WHERE id = ?");
                        $stmt_delete_stale->execute([$local_onu['id']]);
                    }
                }
            }
            $pdo->commit();
        } catch (Throwable $dbEx) {
            $pdo->rollBack();
            throw $dbEx;
        }

        // Jalankan cron_sync.py di background secara asinkron untuk memperbarui redaman/sinyal instan tanpa membebani pemuatan halaman
        $python_bin = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' 
            ? __DIR__ . '/../../backend/python_engine/venv/Scripts/python.exe'
            : __DIR__ . '/../../backend/python_engine/venv/bin/python3';
        $cron_script = __DIR__ . '/../../backend/python_engine/cron_sync.py';

        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            pclose(popen("start /B " . escapeshellarg($python_bin) . " " . escapeshellarg($cron_script) . " --force-config > NUL 2>&1", "r"));
        } else {
            exec(escapeshellarg($python_bin) . " " . escapeshellarg($cron_script) . " --force-config > /dev/null 2>&1 &");
        }

        // Audit Log
        write_audit_log($olt['id'], 'OLT_SYNC', "Sinkronisasi berhasil. {$sync_count} ONU terdaftar diimpor/diperbarui.");

        $_SESSION['success'] = "Berhasil menyinkronkan {$sync_count} pelanggan dari OLT {$olt['name']}!";
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => "Berhasil menyinkronkan {$sync_count} pelanggan dari OLT {$olt['name']}!"]);
            exit;
        }
    } catch (Throwable $e) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        error_log("[SmartOLT sync-olt] Error: " . $e->getMessage());
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Gagal sinkronisasi OLT karena kesalahan internal.']);
            exit;
        }
        $_SESSION['error'] = "Gagal sinkronisasi OLT karena kesalahan internal.";
    }

    // Redirect kembali ke configured.php dengan parameter filter OLT yang sesuai
    header("Location: ../configured.php?olt_id={$olt_id}");
    exit;
}
