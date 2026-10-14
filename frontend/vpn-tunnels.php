<?php
require_once __DIR__ . '/../backend/db.php';

// Auto-detect tunnel IP from OpenVPN status log
function get_ovpn_tunnel_ips(): array {
    $map = [];
    $status = file_get_contents("/var/log/openvpn/openvpn-status.log");
    if (!$status) return $map;
    $in_routing = false;
    foreach (explode("\n", $status) as $line) {
        if (strpos($line, 'ROUTING TABLE') !== false) { $in_routing = true; continue; }
        if (strpos($line, 'GLOBAL STATS') !== false) break;
        if ($in_routing && strpos($line, ',') !== false) {
            $parts = explode(',', $line);
            if (count($parts) >= 2) $map[trim($parts[1])] = trim($parts[0]);
        }
    }
    return $map;
}



// Write iroute rules to CCD file for a given cert CN
function write_ccd_iroute($cn, $routes) {
    $ccd_dir = "/etc/openvpn/ccd";
    if (!is_dir($ccd_dir)) return;
    $lines = [];
    foreach ($routes as $rt) {
        $rt = trim($rt);
        if (!$rt || strpos($rt, "/") === false) continue;
        list($net, $cidr) = explode("/", $rt);
        $mask = long2ip(-1 << (32 - (int)$cidr));
        $lines[] = "iroute $net $mask";
    }
    @file_put_contents("$ccd_dir/$cn", implode("\n", $lines) . "\n");
}

// Sync VPN tunnel status from OpenVPN status log (OpenVPN 2.6 format)
function sync_vpn_status($pdo) {
    $status = @file_get_contents("/var/log/openvpn/openvpn-status.log");
    if (!$status) return;
    $connected = [];
    foreach (explode("\n", $status) as $line) {
        if (strpos($line, "CLIENT_LIST,") === 0) {
            $parts = explode(",", $line);
            if (count($parts) >= 5 && $parts[1] !== 'Common Name') {
                $connected[] = ['cn' => $parts[1], 'ip' => explode(":", $parts[2])[0], 'tunnel_ip' => $parts[3]];
            }
        }
    }
    $tunnels = $pdo->query("SELECT id, cert_cn, status FROM vpn_tunnels ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    $connected_by_cn = [];
    foreach ($connected as $c) { $connected_by_cn[$c['cn']] = $c; }
    foreach ($tunnels as $t) {
        $c = $connected_by_cn[$t['cert_cn']] ?? null;
        if ($c) {
            $tip = $c['tunnel_ip'] ?? null;
            $pdo->prepare("UPDATE vpn_tunnels SET status='connected', client_ip=?, tunnel_ip=? WHERE id=?")
                 ->execute([$c['ip'], $tip, $t['id']]);
            // Apply routes from DB to server routing table + CCD iroute
            if ($tip) {
                $row = $pdo->query("SELECT routes_json FROM vpn_tunnels WHERE id=" . (int)$t['id'])->fetch();
                $routes = $row['routes_json'] ? json_decode($row['routes_json'], true) : [];
                foreach ((array)$routes as $rt) {
                    $rt = trim($rt);
                    if ($rt) exec("sudo ip route replace " . escapeshellarg($rt) . " via " . escapeshellarg($tip) . " dev tun0 2>/dev/null");
                }
                write_ccd_iroute($c['cn'], (array)$routes);
            }
        } else {
            $pdo->prepare("UPDATE vpn_tunnels SET status='disconnected', client_ip=NULL, tunnel_ip=NULL WHERE id=? AND status != 'disconnected'")
                 ->execute([$t['id']]);
        }
    }
}

if (!can_access_settings()) {
    $_SESSION["error"] = "Akses ditolak!";
    header("Location: ../dashboard.php");
    exit;
}

// Generate a unique OpenVPN client certificate for a tunnel (CN = username)
function generate_tunnel_cert($username) {
    if (file_exists("/opt/genieacs/certs/$username.crt")) return true; // already exists
    exec("sudo /opt/openvpn-ca/gen-client-cert.sh " . escapeshellarg($username) . " 2>&1", $out, $ret);
    return $ret === 0;
}

sync_vpn_status($pdo);

// --- POST handlers ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tok = $_POST['csrf_token'] ?? '';
    if (!$tok || !hash_equals($_SESSION['csrf_token'] ?? '', $tok)) {
        header('Location: vpn-tunnels.php'); exit;
    }
    $act = $_POST['action'] ?? '';

    if ($act === 'add') {
        $u = trim($_POST['username'] ?? '');
        $p = trim($_POST['password'] ?? '');
                $routes_raw = $_POST['routes'] ?? [];
        $routes = [];
        foreach ((array)$routes_raw as $r) {
            $r = trim($r);
            if ($r !== '' && preg_match('/^\d{1,3}(\.\d{1,3}){3}\/\d{1,2}$/', $r)) $routes[] = $r;
        }
        if ($u && $p && preg_match('/^[A-Za-z0-9_-]+$/', $u)) {
            $stmt = $pdo->prepare("INSERT INTO vpn_tunnels (username, password, cert_cn, routes_json) VALUES (?,?,?,?)");
            $stmt->execute([$u, $p, $u, $routes ? json_encode($routes) : null]);
            generate_tunnel_cert($u);
            sync_chap_secrets($pdo);
        }
    } elseif ($act === 'update' && ($id = (int)($_POST['id'] ?? 0))) {
        $p = trim($_POST['password'] ?? '');
                $routes_raw = $_POST['routes'] ?? [];
        $routes = [];
        foreach ((array)$routes_raw as $r) {
            $r = trim($r);
            if ($r !== '' && preg_match('/^\d{1,3}(\.\d{1,3}){3}\/\d{1,2}$/', $r)) $routes[] = $r;
        }
        if ($p) {
            // Get old routes to remove from server routing table
            $old = $pdo->query("SELECT routes_json, tunnel_ip FROM vpn_tunnels WHERE id=" . $id)->fetch();
            $old_routes = $old['routes_json'] ? json_decode($old['routes_json'], true) : [];
            $old_tip = $old['tunnel_ip'] ?? null;
            // Remove old routes from server
            if ($old_tip) {
                foreach ((array)$old_routes as $rt) {
                    $rt = trim($rt);
                    if ($rt) exec("sudo ip route del " . escapeshellarg($rt) . " via " . escapeshellarg($old_tip) . " dev tun0 2>/dev/null");
                }
            }
            $stmt = $pdo->prepare("UPDATE vpn_tunnels SET password=?, routes_json=? WHERE id=?");
            $stmt->execute([$p, $routes ? json_encode($routes) : null, $id]);
            // Apply new routes to server (if connected)
            if ($old_tip) {
                foreach ($routes as $rt) {
                    $rt = trim($rt);
                    if ($rt) exec("sudo ip route replace " . escapeshellarg($rt) . " via " . escapeshellarg($old_tip) . " dev tun0 2>/dev/null");
                }
            }
            // Always update CCD (takes effect on next connect)
            $cn_row = $pdo->query("SELECT cert_cn FROM vpn_tunnels WHERE id=$id")->fetch();
            if ($cn_row && $cn_row['cert_cn']) write_ccd_iroute($cn_row['cert_cn'], $routes);
            sync_chap_secrets($pdo);
        }
    } elseif ($act === 'delete' && ($id = (int)($_POST['id'] ?? 0))) {
        $row = $pdo->query("SELECT cert_cn, tunnel_ip, routes_json FROM vpn_tunnels WHERE id=$id")->fetch();
        if ($row) {
            // Remove routes from server routing table
            $routes = $row['routes_json'] ? json_decode($row['routes_json'], true) : [];
            if ($row['tunnel_ip']) {
                foreach ((array)$routes as $rt) {
                    $rt = trim($rt);
                    if ($rt) exec("sudo ip route del " . escapeshellarg($rt) . " via " . escapeshellarg($row['tunnel_ip']) . " dev tun0 2>/dev/null");
                }
            }
            // Remove CCD file
            if ($row['cert_cn']) @unlink("/etc/openvpn/ccd/{$row['cert_cn']}");
        }
        $pdo->prepare("DELETE FROM vpn_tunnels WHERE id=?")->execute([$id]);
        sync_chap_secrets($pdo);
    }
    header('Location: vpn-tunnels.php'); exit;
}

// Sync /etc/ppp/chap-secrets from DB
function sync_chap_secrets($pdo) {
    $rows = $pdo->query("SELECT username, password FROM vpn_tunnels")->fetchAll(PDO::FETCH_ASSOC);
    $lines = ["# Auto-generated by SmartOLT — do not edit manually", "# client    server    secret    IP addresses"];
    foreach ($rows as $r) {
        $lines[] = $r['username'] . "    l2tp-server    " . $r['password'] . "    *";
    }
    file_put_contents('/etc/ppp/chap-secrets', implode("\n", $lines) . "\n");
}

// Check live L2TP sessions
function get_l2tp_sessions() {
    $sessions = [];
    $output = [];
    exec('cat /var/run/xl2tpd/l2tp-control 2>/dev/null && echo "show sessions" | socat - UNIX-CONNECT:/var/run/xl2tpd/l2tp-control 2>/dev/null', $output);
    // Fallback: parse /proc or status file
    $output2 = [];
    exec('ip l2tp show session 2>/dev/null', $output2);
    foreach ($output2 as $line) {
        if (preg_match('/Session\s+(\d+)\s+in\s+tunnel\s+(\d+)/i', $line, $m)) {
            $sessions[$m[1]] = ['tunnel_id' => $m[2]];
        }
    }
    // Get tunnel info for peer IPs
    $output3 = [];
    exec('ip l2tp show tunnel 2>/dev/null', $output3);
    $tunnels = [];
    foreach ($output3 as $line) {
        if (preg_match('/Tunnel\s+(\d+).*peer_address=(\S+)/i', $line, $m)) {
            $tunnels[$m[1]] = $m[2];
        }
    }
    // Get PPP interfaces for IP assignment
    $output4 = [];
    exec('ip -o link show type ppp 2>/dev/null', $output4);
    $ppp_ips = [];
    foreach ($output4 as $line) {
        if (preg_match('/(ppp\d+).*state\s+(UP|DOWN)/i', $line, $m)) {
            $ppp_ips[$m[1]] = ['state' => $m[2]];
        }
    }
    return ['sessions' => $sessions, 'tunnels' => $tunnels, 'ppp' => $ppp_ips];
}

$tunnels = $pdo->query("SELECT * FROM vpn_tunnels ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);
$live = get_l2tp_sessions();

$edit_tunnel = null;
if (isset($_GET['edit'])) {
    $eid = (int)$_GET['edit'];
    foreach ($tunnels as $t) { if ((int)$t['id'] === $eid) { $edit_tunnel = $t; break; } }
}

// Stats
$total = count($tunnels);
$connected = 0;
foreach ($tunnels as $t) { if ($t['status'] === 'connected') $connected++; }
$disconnected = $total - $connected;

include __DIR__ . '/header.php';
?>

<div class="stats-grid motion-stagger">
    <div class="stat-card motion-fade-up"><div class="stat-icon bg-blue"><i data-lucide="network"></i></div><div class="stat-info"><div class="stat-title">Total Tunnels</div><p><?= $total ?></p></div></div>
    <div class="stat-card motion-fade-up"><div class="stat-icon bg-green"><i data-lucide="wifi"></i></div><div class="stat-info"><div class="stat-title">Connected</div><p><?= $connected ?></p></div></div>
    <div class="stat-card motion-fade-up"><div class="stat-icon bg-red"><i data-lucide="wifi-off"></i></div><div class="stat-info"><div class="stat-title">Disconnected</div><p><?= $disconnected ?></p></div></div>
    <div class="stat-card motion-fade-up"><div class="stat-icon bg-purple"><i data-lucide="route"></i></div><div class="stat-info"><div class="stat-title">Subnet</div><p>10.198.198.0/24</p></div></div>
</div>

<div class="content-card">
    <div class="card-header">
        <h1 id="page-title"><i data-lucide="shield" class="icon-md"></i> VPN Tunnels</h1>
        <div class="inline-flex-center">
            <button class="btn btn-primary btn-sm" onclick="document.getElementById('add-modal').classList.add('open')">
                <i data-lucide="plus" class="icon-sm"></i> Add VPN
            </button>
        </div>
    </div>

<?php if (empty($tunnels)): ?>
    <div class="empty-state empty-state--lg">
        <i data-lucide="shield-off" class="icon-empty"></i>
        <p class="mt-3">Belum ada VPN tunnel</p>
    </div>
<?php else: ?>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Status</th>
                    <th>Username</th>
                    <th>Client IP</th>
                    <th>Tunnel IP</th>
                    <th>Routes</th>
                    <th>Subnet</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody class="motion-fade-in">
            <?php foreach ($tunnels as $t):
                $routes = $t['routes_json'] ? json_decode($t['routes_json'], true) : [];
                $routes_count = is_array($routes) ? count($routes) : 0;
                $is_connected = $t['status'] === 'connected';
            ?>
                <tr>
                    <td>
                        <span class="badge <?= $is_connected ? 'badge-green' : 'badge-red' ?> fs-xs">
                            <i data-lucide="<?= $is_connected ? 'wifi' : 'wifi-off' ?>" class="icon-xs"></i>
                            <?= $is_connected ? 'Connected' : 'Disconnected' ?>
                        </span>
                    </td>
                    <td class="mono"><?= htmlspecialchars($t['username']) ?></td>
                    <td><?= $t['client_ip'] ? htmlspecialchars($t['client_ip']) : '<span class="text-muted">—</span>' ?></td>
                    <td class="mono"><?= htmlspecialchars($t['tunnel_ip'] ?? '-') ?></td>
                    <td>
                        <?php if ($routes_count > 0): ?>
                            <span class="badge badge-blue"><?= $routes_count ?> route<?= $routes_count > 1 ? 's' : '' ?></span>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="mono fs-xs"><?= htmlspecialchars($t['subnet'] ?? '10.198.198.0/24') ?></td>
                    <td>
                        <div class="flex-row gap-2">
                            <button class="btn btn-xs btn-outline" onclick="showMikrotik(<?= $t['id'] ?>)" title="Setup MikroTik">
                                <i data-lucide="terminal" class="icon-sm"></i>
                            </button>
                            <a href="vpn-tunnels.php?edit=<?= $t['id'] ?>" class="btn btn-xs btn-outline" title="Edit">
                                <i data-lucide="pencil" class="icon-sm"></i>
                            </a>
                            <form method="post" class="form-inline-block" onsubmit="return confirm('Hapus tunnel <?= htmlspecialchars($t['username']) ?>?')">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $t['id'] ?>">
                                <button type="submit" class="btn btn-xs btn-outline text-danger" title="Hapus">
                                    <i data-lucide="trash-2" class="icon-sm"></i>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
</div>

<!-- ADD MODAL -->
<div class="modal" id="add-modal" role="dialog" aria-modal="true">
    <div class="modal-content modal-w-520">
        <div class="modal-header"><h3>Add VPN Tunnel</h3></div>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="add">
            <div class="modal-body">
                <div class="form-grid">
                    <div class="form-group"><label>Username</label><input type="text" name="username" class="form-control" required placeholder="mikrotik-01"></div>
                    <div class="form-group"><label>Password</label><input type="text" name="password" class="form-control" required></div>
                </div>
                <div class="form-group">
                    <label>Tunnel Routes <small class="text-muted">(subnet per baris, contoh: 192.168.1.0/24)</small></label>
                    <div id="add-routes-container">
                        <div class="inline-flex-center mb-1">
                            <input type="text" name="routes[]" class="form-control flex-1" placeholder="192.168.1.0/24">
                            <button type="button" class="btn btn-xs btn-outline text-danger btn-x" onclick="this.parentElement.remove()">×</button>
                        </div>
                    </div>
                    <button type="button" class="btn btn-xs btn-outline" onclick="addRouteRow('add-routes-container')">+ Tambah Route</button>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('add-modal').classList.remove('open')">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan</button>
            </div>
        </form>
    </div>
</div>

<!-- EDIT MODAL -->
<?php if ($edit_tunnel):
    $edit_routes = $edit_tunnel['routes_json'] ? json_decode($edit_tunnel['routes_json'], true) : [];
    if (!is_array($edit_routes)) $edit_routes = [];
    if (empty($edit_routes)) $edit_routes = [''];
?>
<div class="modal open" role="dialog" aria-modal="true">
    <div class="modal-content modal-w-520">
        <div class="modal-header"><h3>Edit VPN Tunnel — <?= htmlspecialchars($edit_tunnel['username']) ?></h3></div>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" value="<?= $edit_tunnel['id'] ?>">
            <div class="modal-body">
                <div class="form-group"><label>Username</label><input type="text" class="form-control" value="<?= htmlspecialchars($edit_tunnel['username']) ?>" disabled></div>
                <div class="form-grid">
                    <div class="form-group"><label>Password</label><input type="password" name="password" class="form-control" required value="<?= htmlspecialchars($edit_tunnel['password']) ?>" autocomplete="new-password"></div>
                </div>
                <div class="form-group">
                    <label>Tunnel Routes</label>
                    <div id="edit-routes-container">
                        <?php foreach ($edit_routes as $r): ?>
                            <div class="inline-flex-center mb-1">
                                <input type="text" name="routes[]" class="form-control flex-1" value="<?= htmlspecialchars($r) ?>" placeholder="192.168.1.0/24">
                                <button type="button" class="btn btn-xs btn-outline text-danger btn-x" onclick="this.parentElement.remove()">×</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="btn btn-xs btn-outline" onclick="addRouteRow('edit-routes-container')">+ Tambah Route</button>
                </div>
            </div>
            <div class="modal-footer">
                <a href="vpn-tunnels.php" class="btn btn-secondary">Batal</a>
                <button type="submit" class="btn btn-primary">Simpan</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- SETUP MIKROTIK MODAL -->
<div class="modal" id="mt-modal" role="dialog" aria-modal="true">
    <div class="modal-content modal-w-620">
        <div class="modal-header"><h3>Setup MikroTik — <span id="mt-username"></span></h3></div>
        <div class="modal-body">
            <div id="mt-enable-area" class="cert-enable-area">
                <p class="text-muted">Aktifkan download cert untuk setup VPN</p>
                <button type="button" id="cert-enable-btn" class="btn btn-primary" onclick="toggleCertAccess()">Enable Cert Download (5m)</button>
            </div>
            <div id="mt-script-area" style="display:none;">
                <div class="flex-between mb-2">
                    <p class="text-muted fs-sm m-0">Copy-paste script ini ke terminal MikroTik:</p>
                    <span id="cert-countdown" class="text-danger fs-sm fw-600"></span>
                </div>
                <textarea id="mt-script" class="form-control code-area" rows="14" readonly></textarea>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="document.getElementById('mt-modal').classList.remove('open')">Tutup</button>
            <button type="button" id="mt-copy-btn" class="btn btn-primary" style="display:none;" onclick="copyMtScript()">Copy Script</button>
        </div>
    </div>
</div>

<script>
// Tunnel data for MikroTik script generator
const tunnels = <?= json_encode($tunnels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const apiToken = "<?= trim(file_get_contents('/opt/genieacs/certs/.api-token')) ?>";
const serverIP = "smartolt.netbackup.my.id";

function addRouteRow(containerId) {
    const c = document.getElementById(containerId);
    const div = document.createElement('div');
    div.style.cssText = 'display:flex;gap:4px;margin-bottom:4px;align-items:center;';
    const input = document.createElement('input');
    input.type = 'text'; input.name = 'routes[]'; input.className = 'form-control';
    input.placeholder = '192.168.1.0/24'; input.style.flex = '1';
    const btn = document.createElement('button');
    btn.type = 'button'; btn.className = 'btn btn-xs btn-outline';
    btn.style.cssText = 'color:var(--color-danger);min-width:28px;';
    btn.textContent = '\u00d7';
    btn.onclick = function() { div.remove(); };
    div.appendChild(input);
    div.appendChild(btn);
    c.appendChild(div);
}

function showMikrotik(id) {
    const t = tunnels.find(x => x.id == id);
    if (!t) return;
    document.getElementById('mt-username').textContent = t.username;
    const url = location.origin + '/vpn/' + id + '/' + apiToken + '.rsc';
    const rscName = 'setup-VPN-Erlangga-SmartOLT-' + t.username + '.rsc';
    const dq = String.fromCharCode(34);
    const bs = String.fromCharCode(92);
    let src = '/tool fetch url=' + bs + dq + url + bs + dq + ' dst-path=' + rscName;
    src += bs + 'n:delay 3s' + bs + 'n/import ' + rscName;
    let script = '/system script add name=vpn-setup source=' + dq + src + dq + '\n';
    script += '/system script run vpn-setup';
    document.getElementById('mt-script').value = script;
    document.getElementById('mt-modal').classList.add('open');
}

function copyMtScript() {
    const ta = document.getElementById('mt-script');
    ta.select(); document.execCommand('copy');
}

function toggleCertAccess() {
    fetch('/api-vpn-cert-toggle.php', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'action=enable'})
    .then(r => r.json()).then(d => {
        if (d.ok) {
            document.getElementById('mt-enable-area').style.display = 'none';
            document.getElementById('mt-script-area').style.display = 'block';
            document.getElementById('mt-copy-btn').style.display = '';
            startCertCountdown(d.expires);
        }
    });
}

let certTimer = null;
function startCertCountdown(expires) {
    if (certTimer) clearInterval(certTimer);
    certTimer = setInterval(() => {
        const left = Math.max(0, Math.floor(expires - Date.now()/1000));
        const m = Math.floor(left/60);
        const s = left % 60;
        document.getElementById('cert-countdown').textContent = m + ':' + String(s).padStart(2,'0');
        if (left <= 0) {
            clearInterval(certTimer);
            document.getElementById('mt-enable-area').style.display = '';
            document.getElementById('mt-script-area').style.display = 'none';
            document.getElementById('mt-copy-btn').style.display = 'none';
            document.getElementById('cert-countdown').textContent = '';
            fetch('/api-vpn-cert-toggle.php', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'action=disable'});
        }
    }, 1000);
}

// Check if already active on page load
fetch('/api-vpn-cert-toggle.php').then(r=>r.json()).then(d => {
    if (d.active) {
        document.getElementById('mt-enable-area').style.display = 'none';
        document.getElementById('mt-script-area').style.display = 'block';
        document.getElementById('mt-copy-btn').style.display = '';
        startCertCountdown(d.expires);
    }
});
</script>

<?php include __DIR__ . '/footer.php'; ?>
