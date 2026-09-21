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

header('Content-Type: text/plain');
header("Content-Disposition: attachment; filename=\"setup-vpn-{$username}.rsc\"");

echo "# === SmartOLT VPN Setup — {$username} ===\n";
echo "# Jalankan: /import setup-vpn-{$username}.rsc\n\n";

echo "# 1. Download certificates\n";
echo "/tool fetch url=\"{$certUrl}/ca.crt\" dst-path=ca.crt\n";
echo "/tool fetch url=\"{$certUrl}/mikrotik.crt\" dst-path=mikrotik.crt\n";
echo "/tool fetch url=\"{$certUrl}/mikrotik.key\" dst-path=mikrotik.key\n";
echo "/tool fetch url=\"{$certUrl}/ta.key\" dst-path=ta.key\n";
echo ":delay 3s\n\n";

echo "# 2. Import certificates\n";
echo "/certificate import file-name=ca.crt passphrase=\"\"\n";
echo "/certificate import file-name=mikrotik.crt passphrase=\"\"\n";
echo "/certificate import file-name=mikrotik.key passphrase=\"\"\n\n";

echo "# 3. Add OpenVPN client\n";
echo "/interface ovpn-client add \\\n";
echo "  name=ovpn-{$username} \\\n";
echo "  connect-to={$server} \\\n";
echo "  port=1194 \\\n";
echo "  mode=ip \\\n";
echo "  protocol=tcp \\\n";
echo "  user={$username} \\\n";
echo "  password=\"{$password}\" \\\n";
echo "  certificate=mikrotik.crt_0 \\\n";
echo "  cipher=aes128 \\\n";
echo "  auth=sha1 \\\n";
echo "  add-default-route=no \\\n";
echo "  disabled=no\n\n";

echo "# 4. Cek koneksi\n";
echo ":log info \"VPN setup selesai untuk {$username}\"\n";
echo "/interface ovpn-client print where name=ovpn-{$username}\n";
