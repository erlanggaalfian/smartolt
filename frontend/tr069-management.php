<?php
// ==============================================================================
# TR-069 Management — Profiles + Status (2 tabs)
// ==============================================================================
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/genieacs.php';

if (!can_access_settings()) {
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
    unset($p['acs_password']); // never embed credential in HTML/JS; edit form leaves password blank to keep existing
    if ($p['is_default']) $default = $p; else $custom[] = $p;
}
?>


<!-- TAB BAR -->
<div class="tr069-tab-bar">
    <button class="tr069-tab active" data-tab="profiles"><i data-lucide="file-cog" class="icon-sm"></i> TR-069 Profiles</button>
    <button class="tr069-tab" data-tab="status"><i data-lucide="activity" class="icon-sm"></i> TR-069 Status</button>
</div>

<!-- TAB 1: PROFILES -->
<div class="tr069mgmt-panel active" id="panel-profiles">

    <?php if ($default): ?>
    <div class="profile-default">
        <div class="pd-header">
            <i data-lucide="shield-check" class="icon-lg text-accent"></i>
            <strong class="fs-lg"><?= htmlspecialchars($default['name']) ?></strong>
            <span class="pd-badge">Default</span>
            <button class="btn btn-secondary btn-sm" onclick='editProfile(<?= json_encode($default) ?>)' class="ml-auto" title="Edit Username/Password"><i data-lucide="pencil" class="icon-sm"></i> Edit Auth</button>
        </div>
        <div class="pd-url"><?= htmlspecialchars($default['acs_url']) ?></div>
        <div class="text-muted fs-sm mb-3">
            Username: <strong><?= $default['acs_username'] ? htmlspecialchars($default['acs_username']) : '<span class="text-warning">belum diisi</span>' ?></strong>
        </div>
        <?php if ($default['description']): ?>
            <p class="text-muted fs-sm mb-3 mt-0"><?= htmlspecialchars($default['description']) ?></p>
        <?php endif; ?>
        <div class="pd-status">
            <?php foreach ($health['services'] as $svc): ?>
            <div class="pd-svc">
                <span class="status-dot <?= $svc['status']==='running' ? 'dot-green' : 'dot-red' ?>"></span>
                <?= htmlspecialchars($svc['name']) ?>:<?= $svc['port'] ?>
                <span class="text-muted fs-xs"><?= $svc['status'] ?></span>
            </div>
            <?php endforeach; ?>
            <div class="pd-svc">
                <span class="status-dot <?= $health['mongodb'] ? 'dot-green' : 'dot-red' ?>"></span>
                MongoDB
                <span class="text-muted fs-xs"><?= $health['mongodb'] ? 'connected' : 'down' ?></span>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="content-card">
        <div class="card-header">
            <h1 id="page-title"><i data-lucide="list" class="icon-md"></i> Custom Profiles</h1>
            <button class="btn btn-primary btn-sm" onclick="openProfileModal()"><i data-lucide="plus" class="icon-sm"></i> Add Profile</button>
        </div>
        <div class="table-responsive">
            <table class="data-table">
                <thead><tr><th>Nama</th><th>ACS URL</th><th>Username</th><th>Status</th><th>Aksi</th></tr></thead>
                <tbody>
                <?php if (empty($custom)): ?>
                    <tr><td colspan="5" class="empty-state">Belum ada profil custom.</td></tr>
                <?php endif; ?>
                <?php foreach ($custom as $p): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($p['name']) ?></strong></td>
                        <td><code class="fs-xs"><?= htmlspecialchars($p['acs_url']) ?></code></td>
                        <td class="fs-sm"><?= htmlspecialchars($p['acs_username'] ?? '-') ?></td>
                        <td>
                            <?php if ($p['is_active']): ?>
                                <span class="text-success fw-600">Active</span>
                            <?php else: ?>
                                <span class="text-muted">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <button class="btn btn-secondary btn-sm" onclick='editProfile(<?= json_encode($p) ?>)'><i data-lucide="pencil" class="icon-sm"></i></button>
                            <button class="btn btn-secondary btn-sm text-danger" onclick="deleteProfile(<?= $p['id'] ?>, '<?= htmlspecialchars($p['name']) ?>')"><i data-lucide="trash-2" class="icon-sm"></i></button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- TAB 2: STATUS -->
<div class="tr069mgmt-panel" id="panel-status">

    <div class="health-grid">
        <div class="health-card">
            <div class="label">CWMP</div>
            <div class="value"><span class="status-dot <?= $health['services']['cwmp']['status']==='running' ? 'dot-green' : 'dot-red' ?>"></span><?= $health['services']['cwmp']['status']==='running' ? 'Online' : 'Down' ?></div>
            <div class="text-muted fs-xs">:<?= $health['services']['cwmp']['port'] ?></div>
        </div>
        <div class="health-card">
            <div class="label">NBI API</div>
            <div class="value"><span class="status-dot <?= $health['services']['nbi']['status']==='running' ? 'dot-green' : 'dot-red' ?>"></span><?= $health['services']['nbi']['status']==='running' ? 'Online' : 'Down' ?></div>
            <div class="text-muted fs-xs">:<?= $health['services']['nbi']['port'] ?></div>
        </div>
        <div class="health-card">
            <div class="label">File Server</div>
            <div class="value"><span class="status-dot <?= $health['services']['fs']['status']==='running' ? 'dot-green' : 'dot-red' ?>"></span><?= $health['services']['fs']['status']==='running' ? 'Online' : 'Down' ?></div>
            <div class="text-muted fs-xs">:<?= $health['services']['fs']['port'] ?></div>
        </div>
        <div class="health-card">
            <div class="label">MongoDB</div>
            <div class="value"><span class="status-dot <?= $health['mongodb'] ? 'dot-green' : 'dot-red' ?>"></span><?= $health['mongodb'] ? 'Connected' : 'Down' ?></div>
        </div>
    </div>

    <div class="health-grid health-grid--auto">
        <div class="health-card">
            <div class="label">Total Devices</div>
            <div class="value text-accent"><?= $health['device_count'] ?></div>
        </div>
        <div class="health-card">
            <div class="label">Online (24h)</div>
            <div class="value text-success"><?= $health['online_24h'] ?></div>
        </div>
        <div class="health-card">
            <div class="label">Pending Tasks</div>
            <div class="value text-warning"><?= count($faults) ?></div>
        </div>
    </div>

    <!-- Active faults / provisioning tasks -->
    <div class="content-card mt-6">
        <div class="card-header">
            <h2><i data-lucide="alert-triangle" class="icon-md"></i> Active Tasks & Provisioning</h2>
        </div>
        <?php if (empty($faults)): ?>
            <div class="empty-state">Tidak ada task pending.</div>
        <?php else: ?>
            <div class="scroll-300">
            <?php foreach ($faults as $t): ?>
                <div class="fault-item">
                    <i data-lucide="<?= $t['name']==='setParameterValues' ? 'settings' : 'refresh-cw' ?>" class="icon-sm text-accent"></i>
                    <div class="flex-1">
                        <strong><?= htmlspecialchars($t['name'] ?? 'unknown') ?></strong>
                        <span class="text-muted fs-xs ml-2"><?= htmlspecialchars($t['device'] ?? '') ?></span>
                    </div>
                    <span class="text-muted fs-xs"><?= isset($t['timestamp']) ? date('d/m H:i', strtotime($t['timestamp'])) : '-' ?></span>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- ONU Inventory -->
    <div class="content-card mt-6">
        <div class="card-header">
            <h2><i data-lucide="router" class="icon-md"></i> ONU Inventory (<?= count($devices) ?>)</h2>
        </div>
        <div class="table-responsive">
            <table class="data-table">
                <thead><tr><th>Manufacturer</th><th>Model</th><th>Serial</th><th>SW Version</th><th>RX Power</th><th>Last Inform</th></tr></thead>
                <tbody>
                <?php if (empty($devices)): ?>
                    <tr><td colspan="6" class="empty-state">Belum ada ONU terdaftar di ACS.</td></tr>
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
<div class="modal" id="profile-modal" role="dialog" aria-modal="true" aria-labelledby="profile-modal-title">
    <div class="modal-content modal-sm-inner">
        <div class="modal-header">
            <h3 id="profile-modal-title">Add TR-069 Profile</h3>
            <button class="modal-close" onclick="closeProfileModal()">&times;</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="profile-id" value="">
            <div class="form-grid form-grid--1col">
                <div class="form-group">
                    <label>Nama Profil</label>
                    <input type="text" id="profile-name" placeholder="Contoh: ZTE OLT Jakarta">
                </div>
                <div class="form-group">
                    <label>ACS URL</label>
                    <input type="text" id="profile-url" placeholder="http://192.168.1.100:7559">
                </div>
                <div class="grid-2">
                    <div class="form-group">
                        <label>Username</label>
                        <input type="text" id="profile-username" placeholder="ACS username">
                    </div>
                    <div class="form-group">
                        <label>Password</label>
                        <input type="password" id="profile-password" placeholder="Kosongkan untuk tetap pakai password lama" autocomplete="new-password">
                    </div>
                </div>
                <div class="form-group">
                    <label>Deskripsi</label>
                    <textarea id="profile-desc" rows="2" class="form-control"></textarea>
                </div>
            </div>
        </div>
        <div class="modal-footer flex-end">
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
        document.querySelectorAll('.tr069mgmt-panel').forEach(p => p.classList.remove('active'));
        btn.classList.add('active');
        document.getElementById('panel-' + btn.dataset.tab).classList.add('active');
        lucide.createIcons();
    });
});

function openProfileModal(id, name, url, desc, username, password) {
    document.getElementById('profile-modal-title').textContent = id ? 'Edit TR-069 Profile' : 'Add TR-069 Profile';
    document.getElementById('profile-id').value = id || '';
    document.getElementById('profile-name').value = name || '';
    document.getElementById('profile-url').value = url || '';
    document.getElementById('profile-desc').value = desc || '';
    document.getElementById('profile-username').value = username || '';
    document.getElementById('profile-password').value = password || '';
    document.getElementById('profile-modal').classList.add('open');
}
function closeProfileModal() { document.getElementById('profile-modal').classList.remove('open'); }

function editProfile(p) {
    openProfileModal(p.id, p.name, p.acs_url, p.description, p.acs_username, p.acs_password);
    // Default profile: name/URL/desc read-only
    const isDefault = p.is_default == 1;
    document.getElementById('profile-name').readOnly = isDefault;
    document.getElementById('profile-url').readOnly = isDefault;
    document.getElementById('profile-desc').readOnly = isDefault;
    document.getElementById('profile-name').style.opacity = isDefault ? 0.5 : 1;
    document.getElementById('profile-url').style.opacity = isDefault ? 0.5 : 1;
    document.getElementById('profile-desc').style.opacity = isDefault ? 0.5 : 1;
}

function saveProfile() {
    const id = document.getElementById('profile-id').value;
    const body = {
        id: id || null,
        name: document.getElementById('profile-name').value.trim(),
        acs_url: document.getElementById('profile-url').value.trim(),
        description: document.getElementById('profile-desc').value.trim(),
        acs_username: document.getElementById('profile-username').value.trim(),
        acs_password: document.getElementById('profile-password').value,
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
