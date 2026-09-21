<?php
// ==============================================================================
# GenieACS Device Detail — tab WAN/LAN/WLAN/Security, baca + edit
// ==============================================================================
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../backend/genieacs.php';

$id = $_GET['id'] ?? '';
if (!$id) { header('Location: genieacs-devices.php'); exit; }

$device = genieacs_get_device($id);
if (!$device) {
    echo "<div class='error-box'>Device tidak ditemukan di ACS.</div>";
    require_once __DIR__ . '/footer.php';
    exit;
}

$map = genieacs_param_map();
$info = $device['InternetGatewayDevice']['DeviceInfo'] ?? [];
$manu = $info['Manufacturer']['_value'] ?? '-';
$model = $info['ModelName']['_value'] ?? ($info['ProductClass']['_value'] ?? '-');

function val($device, $path) { return genieacs_tree_get($device, $path); }
?>
<style>
.olt-tab-bar { display: flex; align-items: center; gap: 6px; background: var(--bg-secondary); border: 1px solid var(--border-color); border-bottom: none; border-radius: var(--radius-lg) var(--radius-lg) 0 0; padding: 10px 14px 0 14px; overflow-x: auto; scrollbar-width: none; }
.olt-tab-bar::-webkit-scrollbar { display: none; }
.olt-tab { display: inline-flex; align-items: center; gap: 8px; padding: 12px 20px; border-radius: var(--radius-sm) var(--radius-sm) 0 0; font-family: var(--font-family); font-size: 0.85rem; font-weight: 600; color: var(--text-muted); cursor: pointer; border: 1px solid transparent; border-bottom: none; background: transparent; white-space: nowrap; transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1); position: relative; bottom: -1px; }
.olt-tab:hover { color: var(--text-main); background: var(--surface-2); }
.olt-tab.active { color: var(--text-accent); background: var(--bg-card); border-color: var(--border-color); box-shadow: 0 -4px 12px var(--shadow-a05); }
.olt-tab.active::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px; background: var(--text-accent); border-radius: 3px 3px 0 0; }
.olt-tab-panel { display: none; background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 0 0 var(--radius-lg) var(--radius-lg); box-shadow: var(--shadow-md); padding: 28px; }
.olt-tab-panel.active { display: block; }
</style>

<div class="content-card" style="margin-bottom:16px;">
    <div class="card-header">
        <h2><i data-lucide="router" style="width:18px;height:18px;"></i> <?php echo htmlspecialchars($manu . ' ' . $model); ?></h2>
        <a href="genieacs-devices.php" class="btn btn-secondary btn-sm">Kembali</a>
    </div>
    <div style="padding:0 20px 16px; color:var(--text-muted); font-size:0.85rem;"><code><?php echo htmlspecialchars($id); ?></code></div>
</div>

<form id="genieacs-form">
<input type="hidden" name="device_id" value="<?php echo htmlspecialchars($id); ?>">

<div class="olt-tab-bar" role="tablist">
    <button type="button" class="olt-tab active" data-tab="wan"><i data-lucide="globe" style="width:14px;height:14px;"></i> WAN</button>
    <button type="button" class="olt-tab" data-tab="lan"><i data-lucide="network" style="width:14px;height:14px;"></i> LAN</button>
    <button type="button" class="olt-tab" data-tab="wlan"><i data-lucide="wifi" style="width:14px;height:14px;"></i> WLAN</button>
    <button type="button" class="olt-tab" data-tab="security"><i data-lucide="lock" style="width:14px;height:14px;"></i> Security</button>
</div>

<div class="olt-tab-panel active" data-panel="wan">
    <div class="form-grid">
        <div class="form-group"><label>WAN IP (DHCP/Static)</label><input type="text" name="wan.ip" value="<?php echo htmlspecialchars(val($device, $map['wan']['ip']) ?? ''); ?>"></div>
        <div class="form-group"><label>WAN IP (PPPoE)</label><input type="text" value="<?php echo htmlspecialchars(val($device, $map['wan']['ip_ppp']) ?? ''); ?>" disabled></div>
        <div class="form-group"><label>Connection Type</label><input type="text" value="<?php echo htmlspecialchars(val($device, $map['wan']['connection_type']) ?? ''); ?>" disabled></div>
        <div class="form-group"><label>PPPoE Username</label><input type="text" name="wan.ppp_username" value="<?php echo htmlspecialchars(val($device, $map['wan']['ppp_username']) ?? ''); ?>"></div>
        <div class="form-group"><label>PPPoE Password</label><input type="password" name="wan.ppp_password" value="<?php echo htmlspecialchars(val($device, $map['wan']['ppp_password']) ?? ''); ?>"></div>
        <div class="form-group"><label>MAC Address</label><input type="text" value="<?php echo htmlspecialchars(val($device, $map['wan']['mac']) ?? ''); ?>" disabled></div>
    </div>
</div>

<div class="olt-tab-panel" data-panel="lan">
    <div class="form-grid">
        <div class="form-group"><label>LAN IP</label><input type="text" name="lan.ip" value="<?php echo htmlspecialchars(val($device, $map['lan']['ip']) ?? ''); ?>"></div>
        <div class="form-group"><label>Subnet Mask</label><input type="text" name="lan.subnet" value="<?php echo htmlspecialchars(val($device, $map['lan']['subnet']) ?? ''); ?>"></div>
        <div class="form-group"><label>DHCP Server</label>
            <select name="lan.dhcp_enable">
                <option value="true" <?php echo val($device, $map['lan']['dhcp_enable']) ? 'selected' : ''; ?>>Enabled</option>
                <option value="false" <?php echo !val($device, $map['lan']['dhcp_enable']) ? 'selected' : ''; ?>>Disabled</option>
            </select>
        </div>
        <div class="form-group"><label>DHCP Start</label><input type="text" name="lan.dhcp_start" value="<?php echo htmlspecialchars(val($device, $map['lan']['dhcp_start']) ?? ''); ?>"></div>
        <div class="form-group"><label>DHCP End</label><input type="text" name="lan.dhcp_end" value="<?php echo htmlspecialchars(val($device, $map['lan']['dhcp_end']) ?? ''); ?>"></div>
    </div>
</div>

<div class="olt-tab-panel" data-panel="wlan">
    <div class="form-grid">
        <div class="form-group"><label>SSID</label><input type="text" name="wlan.ssid" value="<?php echo htmlspecialchars(val($device, $map['wlan']['ssid']) ?? ''); ?>"></div>
        <div class="form-group"><label>WiFi Enable</label>
            <select name="wlan.enable">
                <option value="true" <?php echo val($device, $map['wlan']['enable']) ? 'selected' : ''; ?>>Enabled</option>
                <option value="false" <?php echo !val($device, $map['wlan']['enable']) ? 'selected' : ''; ?>>Disabled</option>
            </select>
        </div>
        <div class="form-group"><label>Channel</label><input type="text" name="wlan.channel" value="<?php echo htmlspecialchars(val($device, $map['wlan']['channel']) ?? ''); ?>"></div>
        <div class="form-group"><label>Standard (802.11)</label><input type="text" value="<?php echo htmlspecialchars(val($device, $map['wlan']['standard']) ?? ''); ?>" disabled></div>
        <div class="form-group"><label>Broadcast SSID</label>
            <select name="wlan.ssid_broadcast">
                <option value="true" <?php echo val($device, $map['wlan']['ssid_broadcast']) ? 'selected' : ''; ?>>Yes</option>
                <option value="false" <?php echo !val($device, $map['wlan']['ssid_broadcast']) ? 'selected' : ''; ?>>No</option>
            </select>
        </div>
    </div>
</div>

<div class="olt-tab-panel" data-panel="security">
    <div class="form-grid">
        <div class="form-group"><label>Auth Mode</label><input type="text" name="security.auth_mode" value="<?php echo htmlspecialchars(val($device, $map['security']['auth_mode']) ?? ''); ?>"></div>
        <div class="form-group"><label>Encryption</label><input type="text" name="security.encryption" value="<?php echo htmlspecialchars(val($device, $map['security']['encryption']) ?? ''); ?>"></div>
        <div class="form-group"><label>WiFi Password (PSK)</label><input type="password" name="security.passphrase" placeholder="(kosongkan bila tidak diubah)"></div>
    </div>
</div>

<div style="margin-top:20px; display:flex; gap:10px;">
    <button type="submit" class="btn btn-primary"><i data-lucide="save" style="width:16px; height:16px;"></i> Simpan & Push ke Device</button>
    <button type="button" id="btn-refresh-device" class="btn btn-secondary"><i data-lucide="refresh-cw" style="width:16px; height:16px;"></i> Refresh dari Device</button>
</div>
</form>

<script>
document.querySelectorAll('.olt-tab').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.olt-tab').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.olt-tab-panel').forEach(p => p.classList.remove('active'));
        btn.classList.add('active');
        document.querySelector(`.olt-tab-panel[data-panel="${btn.dataset.tab}"]`).classList.add('active');
    });
});

document.getElementById('genieacs-form').addEventListener('submit', async function(e) {
    e.preventDefault();
    const fd = new FormData(this);
    const payload = { device_id: fd.get('device_id'), params: {} };
    for (const [k, v] of fd.entries()) {
        if (k === 'device_id') continue;
        if ((k.endsWith('.passphrase') || k.endsWith('.ppp_password')) && v === '') continue;
        payload.params[k] = v;
    }
    const r = await fetch('action/genieacs-set-params.php', {
        method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(payload)
    });
    const data = await r.json();
    alert(data.success ? 'Perubahan dikirim, menunggu device connect berikutnya.' : ('Gagal: ' + data.message));
});

document.getElementById('btn-refresh-device').addEventListener('click', async function() {
    const id = document.querySelector('[name=device_id]').value;
    const r = await fetch('action/genieacs-refresh.php', {
        method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({device_id: id})
    });
    const data = await r.json();
    alert(data.success ? 'Refresh dijadwalkan, muat ulang halaman beberapa saat lagi.' : ('Gagal: ' + data.message));
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
