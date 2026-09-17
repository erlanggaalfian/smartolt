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
    // Normalisasi: tiap item bisa berupa objek {id,description,...} atau angka polos.
    $normalized = [];
    foreach ($vlans as $v) {
        if (is_array($v)) {
            $vid = (int)($v['id'] ?? 0);
            if ($vid <= 0) continue;
            $normalized[$vid] = [
                'id' => $vid,
                'description' => (string)($v['description'] ?? ''),
                'type' => (string)($v['type'] ?? 'common'),
                'tagged' => (string)($v['tagged'] ?? ''),
                'untagged' => (string)($v['untagged'] ?? ''),
                'ip' => (string)($v['ip'] ?? ''),
                'protected' => !empty($v['protected']) ? 1 : 0,
            ];
        } else {
            $vid = (int)$v;
            if ($vid <= 0) continue;
            $normalized[$vid] = [
                'id' => $vid, 'description' => '', 'type' => 'common',
                'tagged' => '', 'untagged' => '', 'ip' => '', 'protected' => 0,
            ];
        }
    }
    $vlan_ids = array_keys($normalized);

    global $pdo;
    $pdo->beginTransaction();
    // Upsert (race-proof): request paralel tidak akan bentrok di unique key olt_id+vlan_id.
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
?>
