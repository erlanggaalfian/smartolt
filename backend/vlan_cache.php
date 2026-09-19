<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/driver.php';

/**
 * Refreshes VLAN list from OLT and stores it in the database cache.
 *
 * @param array $olt Device array (must include 'id').
 * @return array List of VLAN IDs.
 */
function refresh_olt_vlans(array $olt): array {
    $result = get_olt_vlans($olt);
    $vlans = $result['vlans'] ?? [];

    // Baca 'type' (common/management) yang SUDAH tersimpan di DB, karena ini
    // metadata software murni -- OLT tidak pernah mengirim info ini, jadi
    // refresh dari OLT TIDAK BOLEH menimpanya balik ke 'common'.
    global $pdo;
    $existingTypes = [];
    $stmtExisting = $pdo->prepare('SELECT vlan_id, type FROM olt_vlans WHERE olt_id = ?');
    $stmtExisting->execute([$olt['id']]);
    foreach ($stmtExisting->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $existingTypes[(int)$row['vlan_id']] = $row['type'];
    }

    // Normalisasi: tiap item bisa berupa objek {id,description,...} atau angka polos.
    $normalized = [];
    foreach ($vlans as $v) {
        if (is_array($v)) {
            $vid = (int)($v['id'] ?? 0);
            if ($vid <= 0) continue;
            $normalized[$vid] = [
                'id' => $vid,
                'description' => (string)($v['description'] ?? ''),
                'type' => $existingTypes[$vid] ?? 'common',
                'tagged' => (string)($v['tagged'] ?? ''),
                'untagged' => (string)($v['untagged'] ?? ''),
                'ip' => (string)($v['ip'] ?? ''),
                'protected' => !empty($v['protected']) ? 1 : 0,
            ];
        } else {
            $vid = (int)$v;
            if ($vid <= 0) continue;
            $normalized[$vid] = [
                'id' => $vid, 'description' => '', 'type' => $existingTypes[$vid] ?? 'common',
                'tagged' => '', 'untagged' => '', 'ip' => '', 'protected' => 0,
            ];
        }
    }
    $vlan_ids = array_keys($normalized);

    $pdo->beginTransaction();
    // Upsert (race-proof): request paralel tidak akan bentrok di unique key olt_id+vlan_id.
    // PENTING: 'type' TIDAK ikut di-overwrite oleh ON DUPLICATE KEY -- nilainya
    // sudah dibaca dari DB existing di atas dan dipertahankan lewat INSERT biasa.
    $stmtUp = $pdo->prepare('INSERT INTO olt_vlans (olt_id, vlan_id, description, type, tagged, untagged, ip, protected, fetched_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE description = VALUES(description), type = VALUES(type),
            tagged = VALUES(tagged), untagged = VALUES(untagged), ip = VALUES(ip),
            protected = VALUES(protected), fetched_at = NOW()');
    foreach ($normalized as $vid => $v) {
        $stmtUp->execute([$olt['id'], $vid, $v['description'], $v['type'], $v['tagged'], $v['untagged'], $v['ip'], $v['protected']]);
    }
    // Buang VLAN lama yang sudah tidak ada di perangkat.
    $placeholders = $vlan_ids ? implode(',', array_fill(0, count($vlan_ids), '?')) : '';
    $stmtDel = $vlan_ids
        ? $pdo->prepare("DELETE FROM olt_vlans WHERE olt_id = ? AND vlan_id NOT IN ($placeholders)")
        : $pdo->prepare('DELETE FROM olt_vlans WHERE olt_id = ?');
    $stmtDel->execute($vlan_ids ? array_merge([$olt['id']], $vlan_ids) : [$olt['id']]);
    $pdo->commit();
    return array_values($normalized);
}

/**
 * Set/unset penanda 'management' untuk satu VLAN di satu OLT.
 * Murni metadata software (tidak ada command CLI OLT untuk ini -- dikonfirmasi
 * ZTE C300/C320 tidak punya atribut VLAN type di device).
 */
function set_olt_vlan_management(int $olt_id, int $vlan_id, bool $is_management): bool {
    global $pdo;
    $type = $is_management ? 'management' : 'common';
    $stmt = $pdo->prepare('UPDATE olt_vlans SET type = ? WHERE olt_id = ? AND vlan_id = ?');
    return $stmt->execute([$type, $olt_id, $vlan_id]);
}
?>
