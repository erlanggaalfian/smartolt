<?php
// Generate MikroTik .rsc script for VPN tunnel setup
// Access: /vpn/<id>/<token>.rsc (path-based, MikroTik friendly)
require_once __DIR__ . '/../backend/db.php';

// Auth: session (admin) OR token (MikroTik fetch)
$token = trim(file_get_contents('/opt/genieacs/certs/.api-token') ?: '');
$hasSession = isset($_SESSION["smartolt_role"]) && $_SESSION["smartolt_role"] === "superadmin";
$hasToken = ($_GET['token'] ?? '') === $token;

if (!$hasSession && !$hasToken) {
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
$certBase = "https://{$server}/vpn-cert";
$username = $t['username'];
$password = $t['password'];
$vpnName = 'VPN-Erlangga-SmartOLT';
$rscName = "setup-{$vpnName}-{$username}.rsc";

header('Content-Type: text/plain');
header("Content-Disposition: attachment; filename=\"{$rscName}\"");

// All commands must be single-line (MikroTik CLI does NOT support line continuation)
echo "# === {$vpnName} Setup - {$username} ===\n";
echo "\n";
echo "# 1. Download certificates\n";
echo "/tool fetch url=\"{$certBase}/ca-Erlangga-SmartOLT.crt/{$token}\" dst-path=ca-Erlangga-SmartOLT.crt\n";
echo ":delay 2s\n";
echo "/tool fetch url=\"{$certBase}/{$vpnName}.crt/{$token}\" dst-path={$vpnName}.crt\n";
echo ":delay 2s\n";
echo "/tool fetch url=\"{$certBase}/{$vpnName}.key/{$token}\" dst-path={$vpnName}.key\n";
echo ":delay 3s\n";
echo "\n";
echo "# 2. Import certificates\n";
echo "/certificate import file-name=ca-Erlangga-SmartOLT.crt passphrase=\"\"\n";
echo ":delay 1s\n";
echo "/certificate import file-name={$vpnName}.crt passphrase=\"\"\n";
echo ":delay 1s\n";
echo "/certificate import file-name={$vpnName}.key passphrase=\"\"\n";
echo ":delay 1s\n";
echo "\n";
echo "# 3. Add OpenVPN client\n";
echo "/interface ovpn-client add name=ovpn-{$vpnName} connect-to={$server} port=1194 mode=ip user={$username} password={$password} certificate={$vpnName}.crt cipher=aes128 auth=sha1 add-default-route=no disabled=no\n";
echo "\n";
echo "# 4. Bersihkan file dari MikroTik\n";
echo "/file remove ca-Erlangga-SmartOLT.crt\n";
echo "/file remove {$vpnName}.crt\n";
echo "/file remove {$vpnName}.key\n";
echo "/file remove {$rscName}\n";
echo ":delay 1s\n";
echo "\n";
echo "# 5. Selesai\n";
echo ":log info \"{$vpnName} setup selesai untuk {$username}\"\n";
echo ":delay 2s\n";
echo "/interface ovpn-client print where name=ovpn-{$vpnName}\n";
echo "\n# 6. Cleanup script\n";
echo ":delay 5s\n";
echo "/system script remove vpn-setup\n";
