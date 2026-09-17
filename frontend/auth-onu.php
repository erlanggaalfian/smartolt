<?php
// ==============================================================================
# SmartOLT Halaman Otorisasi Pelanggan Baru (Auth ONU)
# Diakses via link dari unconfigured.php?olt_id=&pon=&sn=&type=
// ==============================================================================
require_once __DIR__ . '/header.php';

$olt_id = isset($_GET['olt_id']) ? (int)$_GET['olt_id'] : 0;
$pon_port = trim($_GET['pon'] ?? '');
$serial_number = trim($_GET['sn'] ?? '');
$onu_type_detected = trim($_GET['type'] ?? '');

if (empty($olt_id) || empty($pon_port) || empty($serial_number)) {
    $_SESSION['error'] = 'Data ONU tidak lengkap. Silakan pilih ulang dari daftar Unconfigured.';
    header('Location: unconfigured.php');
    exit;
}
check_olt_access_or_redirect($olt_id, 'unconfigured.php');

$stmt = $pdo->prepare("SELECT * FROM olts WHERE id = ?");
$stmt->execute([$olt_id]);
$olt = $stmt->fetch();
if (!$olt) {
    $_SESSION['error'] = 'OLT tidak ditemukan!';
    header('Location: unconfigured.php');
    exit;
}

$onu_type_opts = $pdo->query("SELECT name FROM onu_types ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
$zone_opts = $pdo->query("SELECT DISTINCT zone FROM splitters WHERE zone IS NOT NULL AND zone != '' ORDER BY zone")->fetchAll(PDO::FETCH_COLUMN);

$pon_parts = explode('/', $pon_port);
$board_port_display = count($pon_parts) === 3 ? "Shelf {$pon_parts[0]} / Slot {$pon_parts[1]} / Port {$pon_parts[2]}" : $pon_port;
?>
<style>
    .auth-form-row { display:flex; align-items:flex-start; gap:16px; margin-bottom:16px; }
    .auth-form-row label { flex:0 0 200px; font-weight:600; color:var(--text-main); padding-top:9px; }
    .auth-form-row .auth-field { flex:1; }
    .auth-form-row .auth-field small.hint { display:block; color:var(--text-muted); margin-top:4px; font-size:0.8rem; }
    .auth-static { background:var(--bg-tertiary); border:1px solid var(--border-color); border-radius:6px; padding:8px 12px; color:var(--text-main); }
    .auth-preset-row { display:flex; align-items:center; gap:8px; margin-bottom:20px; padding-bottom:16px; border-bottom:1px solid var(--border-color); }
    .auth-preset-row select { flex:1; }
    .btn-icon-square { width:38px; height:38px; flex:0 0 38px; display:inline-flex; align-items:center; justify-content:center; border:1px solid var(--border-color); border-radius:6px; background:var(--bg-tertiary); cursor:pointer; }
    .btn-link-plain { color:var(--color-accent); background:none; border:none; padding:0; cursor:pointer; font-weight:600; text-decoration:none; }
    #gps-fields { margin-top:10px; display:flex; gap:8px; }
    @media (max-width: 600px) {
        .auth-form-row { flex-direction:column; gap:4px; }
        .auth-form-row label { flex:0 0 auto; padding-top:0; font-size:0.85rem; }
        .auth-preset-row { flex-direction:column; align-items:stretch; }
        #gps-fields { flex-direction:column; }
    }
</style>

<div class="content-card">
    <div class="card-header border-accent">
        <h2>Otorisasi Pelanggan Baru <span style="font-weight:400;color:var(--text-muted);font-size:0.85em;">(<?php echo htmlspecialchars($olt['name']); ?>)</span></h2>
    </div>
    <div class="card-body">
        <div class="auth-preset-row">
            <label style="flex:0 0 auto;font-weight:600;">Gunakan Preset</label>
            <select id="auth-preset-select" class="form-control">
                <option value="">Tidak ada</option>
            </select>
            <button type="button" class="btn-icon-square" id="btn-save-preset" title="Simpan kombinasi saat ini sebagai preset baru"><i data-lucide="plus" style="width:16px;height:16px;"></i></button>
        </div>

        <form action="action/auth-onu.php" method="POST" id="form-auth-onu">
            <input type="hidden" name="olt_id" value="<?php echo (int)$olt_id; ?>">
            <input type="hidden" name="pon_port" value="<?php echo htmlspecialchars($pon_port); ?>">
            <input type="hidden" name="serial_number" value="<?php echo htmlspecialchars($serial_number); ?>">
            <input type="hidden" name="type" value="<?php echo htmlspecialchars($onu_type_detected); ?>">
            <input type="hidden" name="config_preset" id="auth-config-preset" value="">

            <div class="auth-form-row">
                <label>OLT</label>
                <div class="auth-field"><div class="auth-static"><?php echo htmlspecialchars($olt['name']); ?></div></div>
            </div>
            <div class="auth-form-row">
                <label>Board / Port</label>
                <div class="auth-field"><div class="auth-static"><?php echo htmlspecialchars($board_port_display); ?></div></div>
            </div>
            <div class="auth-form-row">
                <label>Nomor Seri / MAC</label>
                <div class="auth-field"><div class="auth-static"><?php echo htmlspecialchars($serial_number); ?></div></div>
            </div>
            <div class="auth-form-row">
                <label for="auth-onu-type">Tipe ONU</label>
                <div class="auth-field">
                    <select id="auth-onu-type" name="onu_type" class="form-control" required>
                        <?php foreach ($onu_type_opts as $t): ?>
                        <option value="<?php echo htmlspecialchars($t); ?>" <?php echo ($t === $onu_type_detected || ($onu_type_detected === '' && $t === 'ALL-ONT')) ? 'selected' : ''; ?>><?php echo htmlspecialchars($t); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="auth-form-row">
                <label for="auth-download-speed">Kecepatan Download</label>
                <div class="auth-field">
                    <select id="auth-download-speed" name="download_profile" class="form-control" required>
                        <option value="" disabled selected>Memuat...</option>
                    </select>
                </div>
            </div>
            <div class="auth-form-row">
                <label for="auth-upload-speed">Kecepatan Upload</label>
                <div class="auth-field">
                    <select id="auth-upload-speed" name="upload_profile" class="form-control" required>
                        <option value="" disabled selected>Memuat...</option>
                    </select>
                </div>
            </div>
            <div class="auth-form-row">
                <label for="auth-onu-mode">Mode ONU</label>
                <div class="auth-field">
                    <select id="auth-onu-mode" name="onu_mode" class="form-control">
                        <option value="Routing" selected>Routing</option>
                        <option value="Bridging">Bridging</option>
                    </select>
                </div>
            </div>
            <div class="auth-form-row">
                <label for="auth-wan-mode">Mode WAN</label>
                <div class="auth-field">
                    <select id="auth-wan-mode" name="wan_mode" class="form-control">
                        <option value="Setup via ONU webpage" selected>Setup via ONU webpage</option>
                        <option value="DHCP">DHCP</option>
                        <option value="Static">Static IP</option>
                        <option value="PPPoE">PPPoE</option>
                    </select>
                </div>
            </div>
            <div class="auth-form-row" id="auth-pppoe-fields" style="display:none;">
                <label>PPPoE</label>
                <div class="auth-field" style="display:flex; gap:8px;">
                    <input type="text" id="auth-pppoe-username" name="pppoe_username" class="form-control" placeholder="Username PPPoE">
                    <input type="password" id="auth-pppoe-password" name="pppoe_password" class="form-control" placeholder="Password PPPoE">
                </div>
            </div>
            <div class="auth-form-row">
                <label for="auth-vlan">VLAN ID</label>
                <div class="auth-field">
                    <select id="auth-vlan" name="vlan" class="form-control" required>
                        <option value="" disabled selected>Memuat VLAN...</option>
                    </select>
                </div>
            </div>
            <div class="auth-form-row">
                <label for="auth-zone">Zone</label>
                <div class="auth-field">
                    <select id="auth-zone" name="zone" class="form-control" required>
                        <option value="">- Pilih Zone -</option>
                        <?php foreach ($zone_opts as $z): ?>
                        <option value="<?php echo htmlspecialchars($z); ?>"><?php echo htmlspecialchars($z); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="auth-form-row">
                <label for="auth-splitter">Splitter</label>
                <div class="auth-field">
                    <select id="auth-splitter" name="splitter" class="form-control" required>
                        <option value="">- Pilih Zone dulu -</option>
                    </select>
                    <small class="hint" id="auth-splitter-usage"></small>
                </div>
            </div>
            <div class="auth-form-row">
                <label for="auth-splitter-port">Port Splitter</label>
                <div class="auth-field">
                    <select id="auth-splitter-port" name="splitter_port" class="form-control">
                        <option value="">Tidak ada</option>
                    </select>
                </div>
            </div>
            <div class="auth-form-row">
                <label for="auth-name">Nama</label>
                <div class="auth-field"><input type="text" id="auth-name" name="name" class="form-control" placeholder="Nama pelanggan" required></div>
            </div>
            <div class="auth-form-row">
                <label for="auth-address">Alamat atau keterangan</label>
                <div class="auth-field"><input type="text" id="auth-address" name="address" class="form-control" placeholder="Alamat pelanggan" required></div>
            </div>
            <div class="auth-form-row">
                <label for="auth-contact">Kontak</label>
                <div class="auth-field"><input type="text" id="auth-contact" name="contact" class="form-control" placeholder="Nomor HP/WA"></div>
            </div>
            <input type="hidden" id="auth-onu-id" name="onu_id" required>
            <div class="auth-form-row">
                <label for="auth-external-id">ONU External ID</label>
                <div class="auth-field"><input type="text" id="auth-external-id" name="external_id" class="form-control" placeholder="Opsional"></div>
            </div>
            <div class="auth-form-row">
                <label></label>
                <div class="auth-field">
                    <label style="font-weight:400; display:flex; align-items:center; gap:8px; cursor:pointer;">
                        <input type="checkbox" id="auth-use-gps"> Gunakan GPS
                    </label>
                    <div id="gps-fields" class="hidden">
                        <input type="text" id="auth-latitude" name="latitude" class="form-control" placeholder="Latitude">
                        <input type="text" id="auth-longitude" name="longitude" class="form-control" placeholder="Longitude">
                        <button type="button" class="btn-icon-square" id="btn-use-gps" title="Gunakan lokasi saya"><i data-lucide="map-pin" style="width:16px; height:16px;"></i></button>
                    </div>
                </div>
            </div>
            <div class="loading-spinner hidden" id="auth-onu-loading">
                <div class="spinner"></div>
                <p id="auth-progress-text">Mengirim konfigurasi ke OLT...</p>
            </div>
            <div class="modal-footer" style="padding:16px 0 0; border-top:1px solid var(--border-color, #e2e8f0); margin-top:16px; display:flex; gap:16px; align-items:center;">
                <button type="submit" class="btn btn-success" id="auth-submit-btn" disabled><i data-lucide="save" style="width:15px;height:15px;"></i> Simpan</button>
                <a href="unconfigured.php" class="btn-link-plain">Batal</a>
                <span id="auth-onu-id-error" style="display:none; color:var(--color-danger); font-size:0.85rem; font-weight:600;"></span>
            </div>
        </form>
    </div>
</div>

<!-- Modal simpan preset -->
<div class="modal" id="modal-save-preset">
    <div class="modal-content" style="max-width:420px;">
        <div class="modal-header">
            <h2>Simpan Preset</h2>
            <button type="button" class="modal-close" id="btn-close-preset-modal">&times;</button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label for="preset-name-input">Nama Preset</label>
                <input type="text" id="preset-name-input" class="form-control" placeholder="Nama preset">
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" id="btn-cancel-preset">Batal</button>
            <button type="button" class="btn btn-success" id="btn-confirm-save-preset">Simpan</button>
        </div>
    </div>
</div>

<script>
    function esc(v) {
        if (v === null || v === undefined) return '';
        return String(v)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }
    document.addEventListener('DOMContentLoaded', () => {
        const oltId = <?php echo (int)$olt_id; ?>;
        const ponPort = <?php echo json_encode($pon_port); ?>;
        const form = document.getElementById('form-auth-onu');
        const loading = document.getElementById('auth-onu-loading');

        const authZoneSelect = document.getElementById('auth-zone');
        const authSplitterSelect = document.getElementById('auth-splitter');
        const authSplitterPortSelect = document.getElementById('auth-splitter-port');
        const authSplitterUsage = document.getElementById('auth-splitter-usage');
        let splitterDataMap = {};
        let pendingPresetVlan = null;   // deferred VLAN from preset if list not loaded yet
        let pendingPresetSplitter = null; // deferred splitter from preset if zone load not done yet
        let pendingPresetDl = null;     // deferred download profile from preset
        let pendingPresetUl = null;     // deferred upload profile from preset

        function updateSplitterUsage() {
            const s = splitterDataMap[authSplitterSelect.value];
            authSplitterPortSelect.innerHTML = '<option value="">Tidak ada</option>';
            if (!s) { authSplitterUsage.textContent = ''; return; }
            const capacity = parseInt(s.capacity, 10) || 0;
            const used = parseInt(s.used, 10) || 0;
            const free = Math.max(capacity - used, 0);
            const pct = capacity > 0 ? Math.round((used / capacity) * 100) : 0;
            authSplitterUsage.textContent = `Penggunaan: ${free} tersisa / ${capacity} (${pct}%)`;
            for (let p = 1; p <= capacity; p++) {
                authSplitterPortSelect.innerHTML += `<option value="${p}">Port ${p}</option>`;
            }
        }

        authZoneSelect.addEventListener('change', () => {
            const zone = authZoneSelect.value;
            if (!zone) {
                authSplitterSelect.innerHTML = '<option value="">- Pilih Zone dulu -</option>';
                authSplitterUsage.textContent = '';
                return;
            }
            authSplitterSelect.innerHTML = '<option value="">Memuat...</option>';
            fetch('action/get-splitters.php?zone=' + encodeURIComponent(zone))
                .then(r => r.json())
                .then(res => {
                    const items = (res.data || []);
                    splitterDataMap = {};
                    let html = '<option value="">- Pilih Splitter -</option>';
                    items.forEach(s => { splitterDataMap[s.name] = s; html += `<option value="${esc(s.name)}">${esc(s.name)}</option>`; });
                    authSplitterSelect.innerHTML = items.length ? html : '<option value="">- Tidak ada ODB di zone ini -</option>';
                    authSplitterUsage.textContent = '';
                    // Apply deferred preset splitter if pending
                    if (pendingPresetSplitter) {
                        const spOpt = [...authSplitterSelect.options].find(o => o.value === pendingPresetSplitter);
                        if (spOpt) { authSplitterSelect.value = pendingPresetSplitter; updateSplitterUsage(); }
                        pendingPresetSplitter = null;
                    }
                })
                .catch(() => { authSplitterSelect.innerHTML = '<option value="">Gagal memuat splitter</option>'; });
        });
        authSplitterSelect.addEventListener('change', updateSplitterUsage);

        // GPS collapsible
        const gpsCheckbox = document.getElementById('auth-use-gps');
        const gpsFields = document.getElementById('gps-fields');
        gpsCheckbox.addEventListener('change', () => {
            gpsFields.classList.toggle('hidden', !gpsCheckbox.checked);
        });
        const gpsBtn = document.getElementById('btn-use-gps');
        gpsBtn.addEventListener('click', () => {
            if (!navigator.geolocation) return;
            gpsBtn.disabled = true;
            navigator.geolocation.getCurrentPosition((pos) => {
                document.getElementById('auth-latitude').value = pos.coords.latitude;
                document.getElementById('auth-longitude').value = pos.coords.longitude;
                gpsBtn.disabled = false;
            }, () => { gpsBtn.disabled = false; });
        });

        // WAN setup mode -> tampilkan field PPPoE hanya jika mode PPPoE dipilih
        const wanModeSelect = document.getElementById('auth-wan-mode');
        const pppoeFields = document.getElementById('auth-pppoe-fields');
        function togglePppoeFields() {
            pppoeFields.style.display = wanModeSelect.value === 'PPPoE' ? 'flex' : 'none';
        }
        wanModeSelect.addEventListener('change', togglePppoeFields);
        togglePppoeFields();

        form.addEventListener('submit', (e) => {
            loading.classList.remove('hidden');
            const btn = form.querySelector('button[type="submit"]');
            if (btn) { btn.disabled = true; btn.style.opacity = '0.6'; }
            // Staged progress feedback — authorize ~5s (write batched)
            const progressEl = document.getElementById('auth-progress-text');
            if (progressEl) {
                setTimeout(() => { progressEl.textContent = 'Mengkonfigurasi ONU di OLT...'; }, 2000);
                setTimeout(() => { progressEl.textContent = 'Menyimpan ke database...'; }, 5000);
                setTimeout(() => { progressEl.textContent = 'Masih memproses, harap tunggu...'; }, 12000);
            }
        });

        const onuIdInput = document.getElementById('auth-onu-id');
        const submitBtn = document.getElementById('auth-submit-btn');
        const authProgressEl = document.getElementById('auth-progress-text');
        function fetchNextOnuId() {
            onuIdInput.value = '';
            onuIdInput.disabled = true;
            if (submitBtn) submitBtn.disabled = true;
            // Show inline error area if it exists
            const errEl = document.getElementById('auth-onu-id-error');
            if (errEl) { errEl.style.display = 'none'; errEl.textContent = ''; }
            fetch(`action/get-next-onu-id.php?olt_id=${oltId}&pon_port=${ponPort}`)
                .then(r => r.json())
                .then(res => {
                    if (res.success) { onuIdInput.value = res.next_onu_id; onuIdInput.disabled = false; }
                    else { showOnuIdError(res.message || 'Gagal mengambil ONU ID'); }
                })
                .catch(() => { showOnuIdError('Kesalahan jaringan saat mengambil ONU ID.'); })
                .finally(() => { if (onuIdInput.value && submitBtn) submitBtn.disabled = false; });
        }
        function showOnuIdError(msg) {
            const errEl = document.getElementById('auth-onu-id-error');
            if (errEl) { errEl.textContent = msg + ' '; errEl.style.display = 'inline'; }
            if (submitBtn) submitBtn.disabled = true;
        }
        fetchNextOnuId();

        const vlanSelect = document.getElementById('auth-vlan');
        function fetchVlanList() {
            vlanSelect.innerHTML = `<option value="" disabled selected>Memuat VLAN...</option>`;
            fetch(`action/vlan.php?olt_id=${oltId}&action=list`)
                .then(r => r.json())
                .then(data => {
                    if (!data.success) { vlanSelect.innerHTML = `<option value="" disabled selected>Gagal memuat VLAN</option>`; return; }
                    const vlans = data.vlans || [];
                    if (vlans.length === 0) { vlanSelect.innerHTML = `<option value="" disabled selected>Tidak ada VLAN</option>`; return; }
                    let options = '';
                    vlans.forEach(v => {
                        const id = v.id ?? v.vlan_id ?? v;
                        options += `<option value="${esc(id)}">${v.description ? esc(id) + ' – ' + esc(v.description) : esc(id)}</option>`;
                    });
                    vlanSelect.innerHTML = options;
                    // Apply deferred preset VLAN if pending
                    if (pendingPresetVlan) {
                        const deferredOpt = [...vlanSelect.options].find(o => String(o.value) === pendingPresetVlan);
                        if (deferredOpt) vlanSelect.value = pendingPresetVlan;
                        pendingPresetVlan = null;
                    }
                })
                .catch(() => vlanSelect.innerHTML = `<option value="" disabled selected>Gagal memuat VLAN</option>`);
        }
        fetchVlanList();

        // Download/Upload speed profile, ambil dari cache DB speed_profiles milik OLT ini
        const downloadSelect = document.getElementById('auth-download-speed');
        const uploadSelect = document.getElementById('auth-upload-speed');
        function fetchSpeedProfiles() {
            fetch(`action/speed-profiles.php?olt_id=${oltId}&action=list`)
                .then(r => r.json())
                .then(data => {
                    if (!data.success) {
                        downloadSelect.innerHTML = '<option value="" disabled selected>Gagal memuat</option>';
                        uploadSelect.innerHTML = '<option value="" disabled selected>Gagal memuat</option>';
                        return;
                    }
                    const profiles = data.profiles || [];
                    const downs = profiles.filter(p => p.direction === 'download');
                    const ups = profiles.filter(p => p.direction === 'upload');
                    const mk = (list) => list.length
                        ? '<option value="" disabled selected>- Pilih -</option>' + list.map(p => `<option value="${esc(p.name)}">${esc(p.name)} (${esc(p.speed_kbps)} kbps)</option>`).join('')
                        : '<option value="" disabled selected>Belum ada profile di OLT ini</option>';
                    downloadSelect.innerHTML = mk(downs);
                    uploadSelect.innerHTML = mk(ups);
                    // Apply deferred preset speed profiles if pending
                    if (pendingPresetDl) {
                        if ([...downloadSelect.options].some(o => o.value === pendingPresetDl)) downloadSelect.value = pendingPresetDl;
                        pendingPresetDl = null;
                    }
                    if (pendingPresetUl) {
                        if ([...uploadSelect.options].some(o => o.value === pendingPresetUl)) uploadSelect.value = pendingPresetUl;
                        pendingPresetUl = null;
                    }
                })
                .catch(() => {
                    downloadSelect.innerHTML = '<option value="" disabled selected>Error</option>';
                    uploadSelect.innerHTML = '<option value="" disabled selected>Error</option>';
                });
        }
        fetchSpeedProfiles();

        // Preset
        const presetSelect = document.getElementById('auth-preset-select');
        let presetMap = {};
        function fetchPresets() {
            fetch('action/onu-presets.php?action=list')
                .then(r => r.json())
                .then(data => {
                    if (!data.success) return;
                    presetMap = {};
                    let html = '<option value="">Tidak ada</option>';
                    (data.presets || []).forEach(p => {
                        presetMap[p.id] = p;
                        html += `<option value="${esc(p.id)}">${esc(p.name)}</option>`;
                    });
                    presetSelect.innerHTML = html;
                });
        }
        fetchPresets();
        presetSelect.addEventListener('change', () => {
            const p = presetMap[presetSelect.value];
            document.getElementById('auth-config-preset').value = p ? p.name : '';
            if (!p) return;
            document.getElementById('auth-onu-type').value = p.onu_type || 'ALL-ONT';
            document.getElementById('auth-onu-mode').value = p.onu_mode || 'Routing';
            if (p.vlan) {
                const vlanOpt = [...vlanSelect.options].find(o => String(o.value) === String(p.vlan));
                if (vlanOpt) vlanSelect.value = p.vlan;
                else pendingPresetVlan = String(p.vlan); // VLAN list not loaded yet, defer
            }
            if (p.wan_mode) {
                wanModeSelect.value = p.wan_mode;
                togglePppoeFields();
            }
            if (p.download_profile && [...downloadSelect.options].some(o => o.value === p.download_profile)) {
                downloadSelect.value = p.download_profile;
            } else if (p.download_profile) {
                pendingPresetDl = p.download_profile; // profiles not loaded yet, defer
            }
            if (p.upload_profile && [...uploadSelect.options].some(o => o.value === p.upload_profile)) {
                uploadSelect.value = p.upload_profile;
            } else if (p.upload_profile) {
                pendingPresetUl = p.upload_profile; // profiles not loaded yet, defer
            }
            // Zone -> set + trigger splitter load
            if (p.zone) {
                authZoneSelect.value = p.zone;
                authZoneSelect.dispatchEvent(new Event('change'));
                // Defer splitter selection — zone change fetches splitter list async
                pendingPresetSplitter = p.splitter || '';
            }
        });

        const presetModal = document.getElementById('modal-save-preset');
        document.getElementById('btn-save-preset').addEventListener('click', () => {
            document.getElementById('preset-name-input').value = '';
            presetModal.classList.add('open');
        });
        document.getElementById('btn-close-preset-modal').addEventListener('click', () => presetModal.classList.remove('open'));
        document.getElementById('btn-cancel-preset').addEventListener('click', () => presetModal.classList.remove('open'));
        document.getElementById('btn-confirm-save-preset').addEventListener('click', () => {
            const name = document.getElementById('preset-name-input').value.trim();
            if (!name) return;
            const body = new URLSearchParams({
                action: 'save',
                name: name,
                onu_type: document.getElementById('auth-onu-type').value,
                vlan: vlanSelect.value || '',
                download_profile: downloadSelect.value || '',
                upload_profile: uploadSelect.value || '',
                onu_mode: document.getElementById('auth-onu-mode').value,
                wan_mode: wanModeSelect.value,
                zone: authZoneSelect.value || '',
                splitter: authSplitterSelect.value || ''
            });
            fetch('action/onu-presets.php', { method: 'POST', body })
                .then(r => r.json())
                .then(res => {
                    presetModal.classList.remove('open');
                    if (res.success) fetchPresets();
                });
        });
    });
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
