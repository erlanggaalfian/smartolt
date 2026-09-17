<?php
// ==============================================================================
# Background worker: tarik current-config OLT & simpan ONU ke DB.
# Dipanggil async (exec ... &) dari add-olt.php / edit-olt.php supaya form
# tidak menunggu SSH fetch config yang bisa berjarak detik-menit untuk OLT besar.
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
    $pulled = pull_onus_from_current_config($olt);
    $pulled_onus = $pulled['onus'] ?? [];

    $stmt_insert = $pdo->prepare("
        INSERT INTO onus (olt_id, pon_port, onu_id, name, serial_number, status, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
        ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            status = VALUES(status),
            updated_at = CURRENT_TIMESTAMP
    ");
    foreach ($pulled_onus as $onu) {
        $stmt_insert->execute([
            $olt_id,
            $onu['pon_port'],
            $onu['onu_id'],
            $onu['name'],
            $onu['serial_number'],
            $onu['status'],
        ]);
    }
    error_log("[SmartOLT] background_pull_onus: OLT id={$olt_id} selesai, " . count($pulled_onus) . " ONU diproses.");
} catch (Exception $e) {
    error_log("[SmartOLT] background_pull_onus gagal untuk OLT id={$olt_id}: " . $e->getMessage());
}
