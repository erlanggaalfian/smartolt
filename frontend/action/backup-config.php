<?php
/**
 * backup-config.php — Backup OLT running-config to DB via CLI.
 * POST: olt_id=<id> or olt_id=all
 */
require_once __DIR__ . '/../backend/db.php';

if (!isset($_SESSION['smartolt_role'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Akses ditolak! Silakan login terlebih dahulu.']);
    exit;
}

header('Content-Type: application/json');
set_time_limit(300);
session_write_close();

$olt_id = $_POST['olt_id'] ?? 'all';

// Check OLT access for specific OLT
if ($olt_id !== 'all') {
    $olt_id_int = (int)$olt_id;
    if ($olt_id_int <= 0 || !has_olt_access($olt_id_int)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Akses ditolak! Anda tidak memiliki izin untuk OLT ini.']);
        exit;
    }
}

$python_bin = dirname(__DIR__) . '/backend/python_engine/venv/bin/python3';
$script = dirname(__DIR__) . '/backend/python_engine/backup_configs.py';
$cmd = escapeshellarg($python_bin) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($olt_id) . ' 2>&1';
$output = shell_exec($cmd);

$success = strpos($output, 'Done.') !== false;
echo json_encode([
    'success' => $success,
    'output' => trim($output)
]);
