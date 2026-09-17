<?php
// ==============================================================================
# Action Handler: Get OLT Health Real-time Status (Asynchronous JSON response)
// Lokasi: frontend/action/get-olt-health.php
// ==============================================================================
header('Content-Type: application/json');
require_once __DIR__ . '/../../backend/db.php';
require_once __DIR__ . '/../../backend/driver.php';

if (!isset($_SESSION['smartolt_role']) || $_SESSION['smartolt_role'] !== 'superadmin') {
    echo json_encode(['success' => false, 'message' => 'Akses ditolak! Anda tidak memiliki izin.']);
    exit;
}

set_time_limit(0); // SNMP + CLI multi-call, bisa lewat 30s default
session_write_close(); // Lepas session lock agar request lain bisa berjalan paralel

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID OLT tidak valid.']);
    exit;
}
check_olt_access_json($id);

$stmt = $pdo->prepare("SELECT * FROM olts WHERE id = ?");
$stmt->execute([$id]);
$olt = $stmt->fetch();

if (!$olt) {
    echo json_encode(['success' => false, 'message' => 'OLT tidak ditemukan.']);
    exit;
}

$olt_device = [
    'ip'                => $olt['ip'],
    'username'          => $olt['username'],
    'password'          => $olt['password'],
    'ssh_port'          => $olt['ssh_port'],
    'type'              => $olt['type'],
    'protocol'          => $olt['protocol'] ?? 'SSH',
    'snmp_port'         => $olt['snmp_port'] ?? 161,
    'snmp_community'    => $olt['snmp_community'] ?? 'public',
    'snmp_community_rw' => $olt['snmp_community_rw'] ?? 'public'
];

$cpu = 'N/A';
$ram = 'N/A';
$temp = 'N/A';
$uptime = 'N/A';
$sys_name = '';
$sys_location = '';
$ssh_status = 'Disconnected';
$snmp_status = 'Disconnected';

// Fast SNMP check first (0.05s vs 10s CLI)
$snmp_ip = $olt['ip'] ?? '';
$snmp_comm = $olt['snmp_community'] ?? '';
$snmp_port = (int)($olt['snmp_port'] ?? 161);
$snmp_vendor = stripos($olt['type'] ?? '', 'CDATA') !== false ? 'cdata' : 'zte';
if ($snmp_ip && $snmp_comm) {
    $python_bin = dirname(dirname(__DIR__)) . '/backend/python_engine/venv/bin/python3';
    $snmp_py = dirname(dirname(__DIR__)) . '/backend/python_engine/snmp_module.py';
    $cmd = escapeshellarg($python_bin) . ' -c '
         . escapeshellarg("import sys,json; sys.path.insert(0," . escapeshellarg(dirname($snmp_py)) . "); import snmp_module as s; r=s.get_system_info(" . escapeshellarg($snmp_ip) . "," . escapeshellarg($snmp_comm) . "," . $snmp_port . "," . escapeshellarg($snmp_vendor) . "); print(json.dumps(r))")
         . ' 2>&1';
    $snmp_out = trim(shell_exec($cmd));
    if ($snmp_out && ($snmp_json = json_decode($snmp_out, true))) {
        $snmp_status = 'Connected';
        if (!empty($snmp_json['uptime'])) {
            $uptime = $snmp_json['uptime'];
        }
        if (isset($snmp_json['temperature_c']) && $snmp_json['temperature_c'] !== null && $snmp_json['temperature_c'] !== '') {
            $temp = $snmp_json['temperature_c'] . ' °C';
        }
        $sys_name = $snmp_json['sys_name'] ?? '';
        $sys_location = $snmp_json['sys_location'] ?? '';
    }
}

try {
    $test_res = check_olt_connection($olt_device);
    if ($test_res['success']) {
        $ssh_status = 'Connected';
        $msg = $test_res['message'];
        
        // Parse CPU Load
        if (preg_match('/CPU Load\s*:\s*([^\n\r]+)/i', $msg, $m)) {
            $cpu = trim($m[1]);
        }
        // Parse RAM Usage
        if (preg_match('/RAM Usage\s*:\s*([^\n\r]+)/i', $msg, $m)) {
            $ram = trim($m[1]);
        }
        // Temperature: sumber SNMP (temperature_c) lebih akurat & cepat, prioritas.
        // Fallback CLI cuma dipakai kalau SNMP gagal dapat nilai.
        if ($temp === 'N/A' && preg_match('/Temperature\s*:\s*([^\n\r]+)/i', $msg, $m)) {
            $temp = trim($m[1]);
        }
        // Parse Uptime (CLI uptime overrides SNMP if available)
        if (preg_match('/Uptime\s*:\s*([^\n\r]+)/i', $msg, $m)) {
            $uptime = trim($m[1]);
        }
        
        // Update SNMP status from CLI result if not already Connected
        if ($snmp_status !== 'Connected') {
            if (stripos($msg, 'SNMP Timeout') === false && stripos($msg, 'SNMP Status: Koneksi Berhasil') !== false) {
                $snmp_status = 'Connected';
            } else {
                $snmp_status = 'Timeout / Error';
            }
        }
        
        // Simpan status kesehatan ke database
        try {
            $stmt_upd = $pdo->prepare("
                UPDATE olts 
                SET last_cpu = ?, 
                    last_ram = ?, 
                    last_temp = ?, 
                    last_uptime = ?, 
                    last_snmp_status = ?, 
                    last_health_check = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $stmt_upd->execute([$cpu, $ram, $temp, $uptime, $snmp_status, $id]);
        } catch (PDOException $db_err) {
            error_log("[SmartOLT] Gagal menyimpan status kesehatan OLT ke database: " . $db_err->getMessage());
        }

        echo json_encode([
            'success' => true,
            'cpu' => $cpu,
            'ram' => $ram,
            'temp' => $temp,
            'uptime' => $uptime,
            'sys_name' => $sys_name,
            'sys_location' => $sys_location,
            'ssh_status' => $ssh_status,
            'snmp_status' => $snmp_status
        ]);
    } else {
        // Simpan status kesehatan gagal ke database
        try {
            $stmt_upd = $pdo->prepare("
                UPDATE olts 
                SET last_cpu = 'N/A', 
                    last_ram = 'N/A', 
                    last_temp = 'N/A', 
                    last_uptime = 'N/A', 
                    last_snmp_status = 'Disconnected', 
                    last_health_check = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $stmt_upd->execute([$id]);
        } catch (PDOException $db_err) {}

        echo json_encode([
            'success' => false,
            'message' => $test_res['message'],
            'ssh_status' => 'Disconnected',
            'snmp_status' => 'Disconnected'
        ]);
    }
} catch (Exception $e) {
    // Simpan status kesehatan gagal ke database
    try {
        $stmt_upd = $pdo->prepare("
            UPDATE olts 
            SET last_cpu = 'N/A', 
                last_ram = 'N/A', 
                last_temp = 'N/A', 
                last_uptime = 'N/A', 
                last_snmp_status = 'Disconnected', 
                last_health_check = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $stmt_upd->execute([$id]);
    } catch (PDOException $db_err) {}

    error_log("[SmartOLT get-olt-health] Exception: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Terjadi kesalahan internal pada server.',
        'ssh_status' => 'Disconnected',
        'snmp_status' => 'Disconnected'
    ]);
}
exit;
