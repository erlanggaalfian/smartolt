<?php
// ==============================================================================
# TR-069 Management — Profiles + Status (2 tabs)
// ==============================================================================
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/genieacs.php';

if ($_SESSION['smartolt_role'] !== 'superadmin') {
    $_SESSION['error'] = 'Akses ditolak!';
    header('Location: dashboard.php');
    exit;
}

include __DIR__ . '/header.php';

$profiles = tr069_get_profiles($pdo);
$health = genieacs_health_check();
$devices = genieacs_list_devices();
$faults = genieacs_get_faults(20);
$default = null;
$custom = [];
foreach ($profiles as $p) {
    if ($p['is_default']) $default = $p; else $custom[] = $p;
}
?>

<style>
.tr069-tab-bar { display:flex; align-items:center; gap:6px; background:var(--bg-secondary); border:1px solid var(--border-color); border-bottom:none; border-radius:var(--radius-lg) var(--radius-lg) 0 0; padding:10px 14px 0; }
.tr069-tab { display:inline-flex; align-items:center; gap:6px; padding:12px 20px; border-radius:var(--radius-sm) var(--radius-sm) 0 0; font-family:var(--font-family); font-size:0.85rem; font-weight:600; color:var(--text-muted); cursor:pointer; border:1px solid transparent; border-bottom:none; background:transparent; transition:all 0.2s; position:relative; bottom:-1px; }
.tr069-tab:hover { color:var(--text-main); background:var(--surface-2); }
.tr069-tab.active { color:var(--text-accent); background:var(--bg-card); border-color:var(--border-color); }
.tr069-tab.active::before { content:''; position:absolute; top:0; left:0; right:0; height:3px; background:var(--text-accent); border-radius:3px 3px 0 0; }
.tr069-panel { display:none; background:var(--bg-card); border:1px solid var(--border-color); border-radius:0 0 var(--radius-lg) var(--radius-lg); box-shadow:var(--shadow-md); padding:28px; }
.tr069-panel.active { display:block; }
.health-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:16px; margin-bottom:24px; }
.health-card { background:var(--bg-secondary); border:1px solid var(--border-color); border-radius:var(--radius-md); padding:18px; text-align:center; }
.health-card .label { font-size:0.75rem; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.5px; margin-bottom:6px; }
.health-card .value { font-size:1.6rem; font-weight:700; }
.health-card .status-dot { display:inline-block; width:10px; height:10px; border-radius:50%; margin-right:6px; }
.dot-green { background:var(--color-green); box-shadow:0 0 6px var(--color-green); }
.dot-red { background:var(--color-red); box-shadow:0 0 6px var(--color-red); }
.dot-yellow { background:var(--color-orange); box-shadow:0 0 6px var(--color-orange); }
.profile-default { background:var(--bg-secondary); border:1px solid var(--border-color); border-radius:var(--radius-md); padding:20px 24px; margin-bottom:24px; }
.profile-default .pd-header { display:flex; align-items:center; gap:10px; margin-bottom:12px; }
.profile-default .pd-badge { background:var(--text-accent-glow); color:var(--text-accent); font-size:0.7rem; font-weight:700; padding:3px 8px; border-radius:99px; text-transform:uppercase; letter-spacing:0.5px; }
.profile-default .pd-url { font-family:monospace; font-size:0.85rem; color:var(--text-accent); background:var(--bg-card); padding:8px 12px; border-radius:var(--radius-sm); border:1px solid var(--border-color); display:inline-block; margin-bottom:12px; }
.profile-default .pd-status { display:flex; gap:16px; flex-wrap:wrap; }
.profile-default .pd-svc { display:flex; align-items:center; gap:6px; font-size:0.85rem; }
.fault-item { display:flex; align-items:center; gap:12px; padding:10px 14px; border-bottom:1px solid var(--border-color); font-size:0.85rem; }
.fault-item:last-child { border-bottom:none; }
</style>

<!-- TAB BAR -->
<div class="tr069-tab-bar">
    <button class="tr069-tab active" data-tab="profiles"><i data-lucide="file-cog" style="width:14px;height:14px;"></i> TR-069 Profiles</button>
    <button class="tr069-tab" data-tab="status"><i data-lucide="activity" style="width:14px;height:14px;"></i> TR-069 Status</button>
</div>

<!-- TAB 1: PROFILES -->
<div class="tr069-panel active" id="panel-profiles">

    <?php if ($default): ?>
    <div class="profile-default">
        <div class="pd-header">
            <i data-lucide="shield-check" style="width:20px;height:20px;color:var(--text-accent);"></i>
            <strong style="font-size:1.05rem;"><?= htmlspecialchars($default['name']) ?></strong>
            <span class="pd-badge">Default</span>
            <span style="margin-left:auto;font-size:0.75rem;color:var(--text-muted);">Tidak bisa diedit / dihapus</span>
        </div>
        <div class="pd-url"><?= htmlspecialchars($default['acs_url']) ?></div>
        <?php if ($default['description']): ?>
            <p style="color:var(--text-muted);font-size:0.85rem;margin:0 0 12px;"><?= htmlspecialchars($default['description']) ?></p>
        <?php endif; ?>
        <div class="pd-status">
            <?php foreach ($health['services'] as $svc): ?>
            <div class="pd-svc">
                <span class="status-dot <?= $svc['status']==='running' ? 'dot-green' : 'dot-red' ?>"></span>
                <?= htmlspecialchars($svc['name']) ?>:<?= $svc['port'] ?>
                <span style="color:var(--text-muted);font-size:0.75rem;"><?= $svc['status'] ?></span>
            </div>
            <?php endforeach; ?>
            <div class="pd-svc">
                <span class="status-dot <?= $health['mongodb'] ? 'dot-green' : 'dot-red' ?>"></span>
                MongoDB
                <span style="color:var(--text-muted);font-size:0.75rem;"><?= $health['mongodb'] ? 'connected' : 'down' ?></span>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="content-card">
        <div class="card-header">
            <h2><i data-lucide="list" style="width:18px;height:18px;"></i> Custom Profiles</h2>
            <button class="btn btn-primary btn-sm" onclick="openProfileModal()"><i data-lucide="plus" style="width:14px;height:14px;"></i> Add Profile</button>
        </div>
        <div class="table-responsive">
            <table class="data-table">
                <thead><tr><th>Nama</th><th>ACS URL</th><th>Deskripsi</th><th>Status</th><th>Aksi</th></tr></thead>
                <tbody>
                <?php if (empty($custom)): ?>
                    <tr><td colspan="5" style="text-align:center;padding:30px;color:var(--text-muted);">Belum ada profil custom.</td></tr>
                <?php endif; ?>
                <?php foreach ($custom as $p): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($p['name']) ?></strong></td>
                        <td><code style="font-size:0.8rem;"><?= htmlspecialchars($p['acs_url']) ?></code></td>
                        <td style="color:var(--text-muted);font-size:0.85rem;"><?= htmlspecialchars($p['description'] ?? '-') ?></td>
                        <td>
                            <?php if ($p['is_active']): ?>
                                <span style="color:var(--color-green);font-weight:600;">Active</span>
                            <?php else: ?>
                                <span style="color:var(--text-muted);">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <button class="btn btn-secondary btn-sm" onclick='editProfile(<?= json_encode($p) ?>)'><i data-lucide="pencil" style="width:13px;height:13px;"></i></button>
                            <button class="btn btn-secondary btn-sm" style="color:var(--color-red);" onclick="deleteProfile(<?= $p['id'] ?>, '<?= htmlspecialchars($p['name']) ?>')"><i data-lucide="trash-2" style="width:13px;height:13px;"></i></button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- TAB 2: STATUS -->
<div class="tr069-panel" id="panel-status">

    <div class="health-grid">
        <div class="health-card">
            <div class="label">CWMP</div>
            <div class="value"><span class="status-dot <?= $health['services']['cwmp']['status']==='running' ? 'dot-green' : 'dot-red' ?>"></span><?= $health['services']['cwmp']['status']==='running' ? 'Online' : 'Down' ?></div>
            <div style="font-size:0.75rem;color:var(--text-muted);">:<?= $health['services']['cwmp']['port'] ?></div>
        </div>
        <div class="health-card">
            <div class="label">NBI API</div>
            <div class="value"><span class="status-dot <?= $health['services']['nbi']['status']==='running' ? 'dot-green' : 'dot-red' ?>"></span><?= $health['services']['nbi']['status']==='running' ? 'Online' : 'Down' ?></div>
            <div style="font-size:0.75rem;color:var(--text-muted);">:<?= $health['services']['nbi']['port'] ?></div>
        </div>
        <div class="health-card">
            <div class="label">File Server</div>
            <div class="value"><span class="status-dot <?= $health['services']['fs']['status']==='running' ? 'dot-green' : 'dot-red' ?>"></span><?= $health['services']['fs']['status']==='running' ? 'Online' : 'Down' ?></div>
            <div style="font-size:0.75rem;color:var(--text-muted);">:<?= $health['services']['fs']['port'] ?></div>
        </div>
        <div class="health-card">
            <div class="label">MongoDB</div>
            <div class="value"><span class="status-dot <?= $health['mongodb'] ? 'dot-green' : 'dot-red' ?>"></span><?= $health['mongodb'] ? 'Connected' : 'Down' ?></div>
        </div>
    </div>

    <div class="health-grid" style="grid-template-columns:repeat(auto-fit,minmax(140px,1fr));">
        <div class="health-card">
            <div class="label">Total Devices</div>
            <div class="value" style="color:var(--text-accent);"><?= $health['device_count'] ?></div>
        </div>
        <div class="health-card">
            <div class="label">Online (24h)</div>
            <div class="value" style="color:var(--color-green);"><?= $health['online_24h'] ?></div>
        </div>
        <div class="health-card">
            <div class="label">Pending Tasks</div>
            <div class="value" style="color:var(--color-orange);"><?= count($faults) ?></div>
        </div>
    </div>

    <!-- Active faults / provisioning tasks -->
    <div class="content-card" style="margin-top:24px;">
        <div class="card-header">
            <h2><i data-lucide="alert-triangle" style="width:18px;height:18px;"></i> Active Tasks & Provisioning</h2>
        </div>
        <?php if (empty($faults)): ?>
            <div style="text-align:center;padding:30px;color:var(--text-muted);">Tidak ada task pending.</div>
        <?php else: ?>
            <div style="max-height:300px;overflow-y:auto;">
            <?php foreach ($faults as $t): ?>
                <div class="fault-item">
                    <i data-lucide="<?= $t['name']==='setParameterValues' ? 'settings' : 'refresh-cw' ?>" style="width:16px;height:16px;color:var(--text-accent);flex-shrink:0;"></i>
                    <div style="flex:1;">
                        <strong><?= htmlspecialchars($t['name'] ?? 'unknown') ?></strong>
                        <span style="color:var(--text-muted);font-size:0.8rem;margin-left:8px;"><?= htmlspecialchars($t['device'] ?? '') ?></span>
                    </div>
                    <span style="color:var(--text-muted);font-size:0.75rem;"><?= isset($t['timestamp']) ? date('d/m H:i', strtotime($t['timestamp'])) : '-' ?></span>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- ONU Inventory -->
    <div class="content-card" style="margin-top:24px;">
        <div class="card-header">
            <h2><i data-lucide="router" style="width:18px;height:18px;"></i> ONU Inventory (<?= count($devices) ?>)</h2>
        </div>
        <div class="table-responsive">
            <table class="data-table">
                <thead><tr><th>Manufacturer</th><th>Model</th><th>Serial</th><th>SW Version</th><th>RX Power</th><th>Last Inform</th></tr></thead>
                <tbody>
                <?php if (empty($devices)): ?>
                    <tr><td colspan="6" style="text-align:center;padding:30px;color:var(--text-muted);">Belum ada ONU terdaftar di ACS.</td></tr>
                <?php endif; ?>
                <?php foreach ($devices as $d):
                    $info = $d['InternetGatewayDevice']['DeviceInfo'] ?? [];
                    $mfr = $info['Manufacturer']['_value'] ?? '-';
                    $model = $info['ProductClass']['_value'] ?? $info['ModelName']['_value'] ?? '-';
                    $sn = $info['SerialNumber']['_value'] ?? '-';
                    $sw = $info['SoftwareVersion']['_value'] ?? '-';
                    $rx = $d['VirtualParameters']['OpticalPower']['_value'] ?? null;
                    $li = $d['_lastInform'] ?? null;
                ?>
                    <tr>
                        <td><?= htmlspecialchars($mfr) ?></td>
                        <td><strong><?= htmlspecialchars($model) ?></strong></td>
                        <td><code><?= htmlspecialchars($sn) ?></code></td>
                        <td><?= htmlspecialchars($sw) ?></td>
                        <td><?= $rx !== null ? htmlspecialchars($rx) . ' dBm' : '-' ?></td>
                        <td><?= $li ? date('d/m/Y H:i', strtotime($li)) : '-' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ADD/EDIT PROFILE MODAL -->
<div class="modal" id="profile-modal">
    <div class="modal-content" style="max-width:500px;">
        <div class="modal-header">
            <h3 id="profile-modal-title">Add TR-069 Profile</h3>
            <button class="modal-close" onclick="closeProfileModal()">&times;</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="profile-id" value="">
            <div class="form-grid" style="grid-template-columns:1fr;">
                <div class="form-group">
                    <label>Nama Profil</label>
                    <input type="text" id="profile-name" placeholder="Contoh: ZTE OLT Jakarta">
                </div>
                <div class="form-group">
                    <label>ACS URL</label>
                    <input type="text" id="profile-url" placeholder="http://192.168.1.100:7559">
                </div>
                <div class="form-group">
                    <label>Deskripsi</label>
                    <textarea id="profile-desc" rows="2" style="width:100%;background:var(--bg-secondary);border:1px solid var(--border-color);border-radius:var(--radius-sm);color:var(--text-main);padding:10px;resize:vertical;"></textarea>
                </div>
            </div>
        </div>
        <div class="modal-footer" style="display:flex;justify-content:flex-end;gap:8px;">
            <button class="btn btn-secondary" onclick="closeProfileModal()">Batal</button>
            <button class="btn btn-primary" onclick="saveProfile()">Simpan</button>
        </div>
    </div>
</div>

<script>
// Tab switching
document.querySelectorAll('.tr069-tab').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.tr069-tab').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.tr069-panel').forEach(p => p.classList.remove('active'));
        btn.classList.add('active');
        document.getElementById('panel-' + btn.dataset.tab).classList.add('active');
        lucide.createIcons();
    });
});

function openProfileModal(id, name, url, desc) {
    document.getElementById('profile-modal-title').textContent = id ? 'Edit TR-069 Profile' : 'Add TR-069 Profile';
    document.getElementById('profile-id').value = id || '';
    document.getElementById('profile-name').value = name || '';
    document.getElementById('profile-url').value = url || '';
    document.getElementById('profile-desc').value = desc || '';
    document.getElementById('profile-modal').classList.add('open');
}
function closeProfileModal() { document.getElementById('profile-modal').classList.remove('open'); }

function editProfile(p) {
    openProfileModal(p.id, p.name, p.acs_url, p.description);
}

function saveProfile() {
    const id = document.getElementById('profile-id').value;
    const body = {
        id: id || null,
        name: document.getElementById('profile-name').value.trim(),
        acs_url: document.getElementById('profile-url').value.trim(),
        description: document.getElementById('profile-desc').value.trim(),
    };
    if (!body.name || !body.acs_url) { alert('Nama dan URL wajib diisi.'); return; }
    fetch('action/tr069-profile-save.php', {
        method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify(body)
    }).then(r => r.json()).then(d => {
        if (d.success) location.reload(); else alert(d.message || 'Gagal simpan.');
    }).catch(() => alert('Error koneksi.'));
}

function deleteProfile(id, name) {
    if (!confirm('Hapus profil "' + name + '"?')) return;
    fetch('action/tr069-profile-delete.php', {
        method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({id})
    }).then(r => r.json()).then(d => {
        if (d.success) location.reload(); else alert(d.message || 'Gagal hapus.');
    }).catch(() => alert('Error koneksi.'));
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
