<?php
// ==============================================================================
# Action Handler: Get Debug CLI OLT Terminal Logs (JSON response)
# Lokasi: frontend/action/get-debug-cli.php
// ==============================================================================
header('Content-Type: application/json');
require_once __DIR__ . '/../../backend/db.php';

if (!isset($_SESSION['smartolt_role']) || $_SESSION['smartolt_role'] !== 'superadmin') {
    echo json_encode(['success' => false, 'message' => 'Akses ditolak! Anda tidak memiliki izin.']);
    exit;
}

session_write_close(); // Lepas lock sesi agar request lain bisa berjalan paralel

$log_path = __DIR__ . '/../../backend/python_engine/debug_cli.log';
if (!file_exists($log_path)) {
    echo json_encode(['success' => true, 'log' => 'Belum ada data interaksi CLI terminal OLT yang tercatat.']);
    exit;
}

$log_data = file_get_contents($log_path);
if (strlen($log_data) > 50000) {
    $log_data = substr($log_data, -50000);
    $log_data = "--- (Output dipotong karena terlalu panjang) ---\n" . $log_data;
}

echo json_encode(['success' => true, 'log' => $log_data]);
exit;
