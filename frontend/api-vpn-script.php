<?php
// Generate MikroTik .rsc script for OpenVPN tunnel setup
// Usage: /api-vpn-script.php?id=<tunnel_id>
require_once __DIR__ . '/../backend/db.php';

if (!isset($_SESSION["smartolt_role"]) || $_SESSION["smartolt_role"] !== "superadmin") {
    http_response_code(403);
    die('Access denied');
}

$id = (int)($_GET['id'] ?? 0);
if (!$id) { http_response_code(400); die('Missing id'); }

$stmt = $pdo->prepare("SELECT * FROM vpn_tunnels WHERE id=?");
$stmt->execute([$id]);
$t = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$t) { http_response_code(404); die('Not found'); }

$server = 'smartolt.netbackup.my.id';
$certUrl = "https://{$server}/_tmp";
$username = $t['username'];
$password = $t['password'];
$vpnName = 'VPN-Erlangga-SmartOLT';

header('Content-Type: text/plain');
header("Content-Disposition: attachment; filename=\"setup-{$vpnName}-{$username}.rsc\"");

echo "# === {$vpnName} Setup — {$username} ===\n";
echo "# Jalankan: /import setup-{$vpnName}-{$username}.rsc\n\n";

echo "# 1. Download certificates\n";
echo "/tool fetch url=\"{$certUrl}/ca-Erlangga-SmartOLT.crt\" dst-path=ca-Erlangga-SmartOLT.crt\n";
echo ":delay 2s\n";
echo "/tool fetch url=\"{$certUrl}/{$vpnName}.crt\" dst-path={$vpnName}.crt\n";
echo ":delay 2s\n";
echo "/tool fetch url=\"{$certUrl}/{$vpnName}.key\" dst-path={$vpnName}.key\n";
echo ":delay 3s\n\n";

echo "# 2. Import certificates\n";
echo "/certificate import file-name=ca-Erlangga-SmartOLT.crt passphrase=\"\"\n";
echo ":delay 1s\n";
echo "/certificate import file-name={$vpnName}.crt passphrase=\"\"\n";
echo ":delay 1s\n";
echo "/certificate import file-name={$vpnName}.key passphrase=\"\"\n";
echo ":delay 1s\n\n";

echo "# 3. Add OpenVPN client\n";
echo "/interface ovpn-client add \\\n";
echo "  name=ovpn-{$vpnName} \\\n";
echo "  connect-to={$server} \\\n";
echo "  port=1194 \\\n";
echo "  mode=ip \\\n";
echo "  protocol=tcp \\\n";
echo "  user={$username} \\\n";
echo "  password=\"{$password}\" \\\n";
echo "  certificate={$vpnName}.crt_0 \\\n";
echo "  cipher=aes128 \\\n";
echo "  auth=sha1 \\\n";
echo "  add-default-route=no \\\n";
echo "  disabled=no\n\n";

echo "# 4. Selesai\n";
echo ":log info \"{$vpnName} setup selesai untuk {$username}\"\n";
echo ":delay 2s\n";
echo "/interface ovpn-client print where name=ovpn-{$vpnName}\n";
