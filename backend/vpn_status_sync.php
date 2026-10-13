<?php
require_once __DIR__ . '/../backend/db.php';
$status = @file_get_contents('/var/log/openvpn/openvpn-status.log');
if (!$status) exit(0);
$connected = [];
foreach (explode("\n", $status) as $line) {
    if (strpos($line, "CLIENT_LIST,") === 0) {
        $parts = explode(",", $line);
        if (count($parts) >= 5 && $parts[1] !== 'Common Name') {
            $connected[] = ['cn' => $parts[1], 'ip' => explode(":", $parts[2])[0], 'tunnel_ip' => $parts[3]];
        }
    }
}
$by_cn = [];
foreach ($connected as $c) { $by_cn[$c['cn']] = $c; }
$tunnels = $pdo->query("SELECT id, cert_cn FROM vpn_tunnels")->fetchAll(PDO::FETCH_ASSOC);
foreach ($tunnels as $t) {
    $c = $by_cn[$t['cert_cn']] ?? null;
    if ($c) {
        $pdo->prepare("UPDATE vpn_tunnels SET status='connected', client_ip=?, tunnel_ip=? WHERE id=?")
             ->execute([$c['ip'], $c['tunnel_ip'], $t['id']]);
    } else {
        $pdo->prepare("UPDATE vpn_tunnels SET status='disconnected', client_ip=NULL, tunnel_ip=NULL WHERE id=? AND status != 'disconnected'")
             ->execute([$t['id']]);
    }
}
