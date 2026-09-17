<?php
// ==============================================================================
# SmartOLT OLT Detail — Tab-based Sub-menu
// Lokasi: frontend/olt-detail.php
// ==============================================================================
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../backend/driver.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    echo "<div class='error-box'>ID OLT tidak valid.</div>";
    require_once __DIR__ . '/footer.php';
    exit;
}

// Ambil data OLT dari database
$stmt = $pdo->prepare("SELECT * FROM olts WHERE id = ?");
$stmt->execute([$id]);
$olt = $stmt->fetch();

if (!$olt || !has_olt_access($id)) {
    echo "<div class='error-box'>OLT tidak ditemukan atau Anda tidak memiliki akses ke OLT ini.</div>";
    require_once __DIR__ . '/footer.php';
    exit;
}

// Panel brand-spesifik yang benar-benar didukung driver OLT ini. Tab hanya
// digambar bila didukung, supaya user tidak menekan tab yang pasti menjawab
// "Panel ... tidak didukung".
$driver_panels = get_olt_supported_panels($olt);

// Hitung jumlah ONU
$onu_count = $pdo->prepare("SELECT COUNT(*) as total, SUM(status='online') as online FROM onus WHERE olt_id = ?");
$onu_count->execute([$id]);
$onu_stats = $onu_count->fetch();
?>

<style>
/* ---- OLT Tab System ---- */
/* scrollbar disembunyikan: Firefox via scrollbar-width, Chrome/Safari via ::-webkit-scrollbar */
.olt-tab-bar { display: flex; align-items: center; gap: 6px; background: var(--bg-secondary); border: 1px solid var(--border-color); border-bottom: none; border-radius: var(--radius-lg) var(--radius-lg) 0 0; padding: 10px 14px 0 14px; overflow-x: auto; scrollbar-width: none; }
.olt-tab-bar::-webkit-scrollbar { display: none; }
.olt-tab { display: inline-flex; align-items: center; gap: 8px; padding: 12px 20px; border-radius: var(--radius-sm) var(--radius-sm) 0 0; font-family: var(--font-family); font-size: 0.85rem; font-weight: 600; color: var(--text-muted); cursor: pointer; border: 1px solid transparent; border-bottom: none; background: transparent; white-space: nowrap; transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1); text-decoration: none; position: relative; bottom: -1px; }
.olt-tab:hover { color: var(--text-main); background: var(--surface-2); }
.olt-tab.active { color: var(--text-accent); background: var(--bg-card); border-color: var(--border-color); box-shadow: 0 -4px 12px var(--shadow-a05); }
/* Garis aksen tipis di atas tab aktif */
.olt-tab.active::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px; background: var(--text-accent); border-radius: 3px 3px 0 0; }
.olt-tab-panel { display: none; background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 0 0 var(--radius-lg) var(--radius-lg); box-shadow: var(--shadow-md); padding: 28px; animation: fadeIn 0.25s cubic-bezier(0.4, 0, 0.2, 1); }
.olt-tab-panel.active { display: block; }
@keyframes fadeIn {
  from { opacity: 0; transform: translateY(4px); }
  to { opacity: 1; transform: translateY(0); }
}
@media (max-width: 640px) {
  .olt-tab { padding: 10px 14px; font-size: 0.8rem; }
  .olt-tab-panel { padding: 20px; }
}
/* ---- OLT Header Card ---- */
.olt-header-card { display: flex; align-items: center; justify-content: space-between; gap: 20px; flex-wrap: wrap; background: var(--bg-card); border: 1px solid var(--border-color); border-left: 4px solid var(--text-accent); border-radius: var(--radius-lg); box-shadow: var(--shadow-md); padding: 24px 30px; margin-bottom: 24px; transition: var(--transition-normal); }
.olt-header-info { display: flex; align-items: center; gap: 18px; }
.olt-icon-box { width: 56px; height: 56px; background: var(--text-accent-glow); border: 1px solid var(--text-accent); border-radius: var(--radius-md); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.olt-header-meta h2 { margin: 0; font-size: 1.3rem; font-weight: 700; letter-spacing: -0.01em; }
.olt-header-meta p { margin: 6px 0 0; font-size: 0.82rem; color: var(--text-muted); font-family: monospace; }
.olt-stat-pill { display: flex; flex-direction: column; align-items: center; padding: 12px 22px; border-radius: var(--radius-md); background: var(--surface-1); border: 1px solid var(--border-color); min-width: 96px; transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1); }
.olt-stat-pill:hover { transform: translateY(-3px); border-color: var(--text-accent); box-shadow: 0 4px 15px var(--shadow-a10); }
.olt-stat-pill .val { font-size: 1.55rem; font-weight: 700; color: var(--text-main); line-height: 1.2; }
.olt-stat-pill .lbl { font-size: 0.68rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; margin-top: 3px; }
/* ---- Section header di dalam panel ---- */
.olt-section-head { display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; margin-bottom: 24px; padding-bottom: 16px; border-bottom: 1px solid var(--border-color); }
.olt-section-head h2 { margin: 0; font-size: 1.05rem; font-weight: 600; display: inline-flex; align-items: center; gap: 8px; }
.olt-section-head p { margin: 6px 0 0; font-size: 0.82rem; color: var(--text-muted); }
.olt-section-actions { display: flex; gap: 10px; flex-wrap: wrap; }
/* ---- Health metric cards ---- */
.health-card-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; margin-bottom: 26px; }
.health-metric { background: var(--surface-1); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 20px 16px; transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1); }
.health-metric:hover { border-color: var(--text-accent); transform: translateY(-2px); background: var(--surface-3); box-shadow: var(--shadow-sm); }
.health-metric .lbl { font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted); margin-bottom: 10px; }
.health-metric .val { font-size: 1.75rem; font-weight: 700; color: var(--text-main); }
/* ---- Panel inline form (tambah VLAN / edit port) ---- */
.olt-inline-form { background: var(--surface-1); border: 1px solid var(--border-color); border-left: 3px solid var(--text-accent); border-radius: var(--radius-md); padding: 22px; margin-bottom: 24px; }
.olt-inline-form h4 { margin: 0 0 6px; font-size: 0.9rem; font-weight: 700; color: var(--text-accent); display: inline-flex; align-items: center; gap: 8px; }
.olt-inline-form .hint { margin: 0 0 18px; font-size: 0.8rem; color: var(--text-muted); }
.olt-form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 16px; align-items: end; }
/* ---- Notifikasi inline panel ---- */
.olt-inline-msg { display: none; margin-bottom: 18px; padding: 12px 16px; border-radius: var(--radius-sm); font-size: 0.85rem; font-weight: 500; }
.olt-inline-msg.ok { display: block; background: var(--tint-success-soft); color: var(--color-success); border: 1px solid var(--border-success); }
.olt-inline-msg.err { display: block; background: var(--tint-danger-soft); color: var(--color-danger); border: 1px solid var(--border-danger); }
.olt-inline-msg.hidden { display: none; }
/* ---- Data Table (dipakai smartolt-data-table) ---- */
.smartolt-data-table, .pr-table { width: 100%; border-collapse: separate; border-spacing: 0; font-size: 0.85rem; }
.smartolt-data-table th, .pr-table th { padding: 12px 16px; font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted); border-bottom: 1px solid var(--border-color); text-align: left; background: var(--surface-1); }
.smartolt-data-table td, .pr-table td { padding: 12px 16px; color: var(--text-main); border-bottom: 1px solid var(--border-color); vertical-align: middle; transition: background-color 0.15s ease; }
.smartolt-data-table tbody tr:last-child td, .pr-table tbody tr:last-child td { border-bottom: none; }
.smartolt-data-table tbody tr:hover td, .pr-table tbody tr:hover td { background: var(--surface-2); }
/* ---- VLAN table ---- */
.vlan-row-empty { text-align:center; padding:36px; color:var(--text-muted); font-style:italic; }
/* ---- Panel render ---- */
.panel-render { min-height: 120px; }
.pr-section { margin-bottom: 28px; }
.pr-section:last-child { margin-bottom: 0; }
.pr-title { margin: 0 0 14px; font-size: 0.92rem; font-weight: 700; color: var(--text-accent); letter-spacing: 0.01em; }
.pr-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(210px, 1fr)); gap: 16px; }
.pr-lbl { font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted); margin-bottom: 8px; }
.pr-table-wrap { overflow-x: auto; border: 1px solid var(--border-color); border-radius: var(--radius-md); background: var(--bg-card); box-shadow: var(--shadow-sm); }
.pr-loading, .pr-empty { padding: 32px; text-align: center; color: var(--text-muted); font-size: 0.88rem; font-style: italic; }
.pr-error { padding: 14px 18px; border-radius: var(--radius-sm); font-size: 0.86rem; background: var(--tint-danger-soft); color: var(--color-danger); border: 1px solid var(--border-danger-strong); word-break: break-word; }
.pr-note { padding: 12px 16px; margin-bottom: 18px; border-radius: var(--radius-sm); font-size: 0.84rem; background: var(--tint-warning-soft); color: var(--color-warning-deep); border: 1px solid var(--border-warning); }
.pr-stamp { margin-top: 20px; font-size: 0.75rem; color: var(--text-muted); text-align: right; }
@media (max-width: 640px) {
  .pr-grid { grid-template-columns: 1fr; }
}
/* spinner */
.spinner-small { display: inline-block; width: 14px; height: 14px; border: 2px solid var(--spinner-track); border-radius: 50%; border-top-color: var(--text-accent); animation: spin-sm 0.8s linear infinite; vertical-align: middle; margin-right: 6px; }
@keyframes spin-sm {
  to { transform: rotate(360deg); }
}
.hidden { display: none !important; }
</style>

<!-- OLT Header Info -->
<div style="margin-bottom: 16px;">
    <div class="section-actions" style="margin-bottom:16px;">
        <a href="settings-olt.php" class="btn btn-back" style="text-decoration:none;">
            <i data-lucide="arrow-left" style="width:16px; height:16px;"></i> Kembali ke Daftar OLT
        </a>
    </div>

    <div class="olt-header-card">
        <div class="olt-header-info">
            <div class="olt-icon-box">
                <i data-lucide="server" style="width:22px; height:22px; color:var(--text-accent);"></i>
            </div>
            <div class="olt-header-meta">
                <h2><?php echo htmlspecialchars($olt['name']); ?></h2>
                <p><?php echo htmlspecialchars($olt['ip']); ?> · <?php echo htmlspecialchars($olt['protocol'] ?? 'SSH'); ?>:<?php echo htmlspecialchars($olt['ssh_port']); ?> · <?php echo htmlspecialchars($olt['type']); ?></p>
            </div>
        </div>
        <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
            <div class="olt-stat-pill">
                <span class="val"><?php echo (int)$onu_stats['total']; ?></span>
                <span class="lbl">Total ONU</span>
            </div>
            <div class="olt-stat-pill" style="border-color:rgba(16,185,129,0.4);">
                <span class="val" style="color:var(--color-success);"><?php echo (int)$onu_stats['online']; ?></span>
                <span class="lbl">Online</span>
            </div>
            <div class="olt-stat-pill" style="border-color:rgba(239,68,68,0.4);">
                <span class="val" style="color:var(--color-danger);"><?php echo (int)$onu_stats['total'] - (int)$onu_stats['online']; ?></span>
                <span class="lbl">Offline</span>
            </div>
            <span id="olt-snmp-badge" class="badge <?php echo ($olt['last_snmp_status'] === 'Connected') ? 'bg-green' : 'bg-red'; ?>">
                ● <?php echo htmlspecialchars($olt['last_snmp_status'] ?: 'Unknown'); ?>
            </span>
        </div>
    </div>
</div>

<!-- Flash message sudah dirender oleh header.php (dengan escaping) — jangan duplikasi di sini. -->

<!-- TAB BAR -->
<div class="olt-tab-bar" role="tablist">
    <button class="olt-tab active" data-tab="monitoring" role="tab">
        <i data-lucide="activity" style="width:14px;height:14px;"></i> Monitoring
    </button>
<?php foreach ($driver_panels as $pkey => $pmeta): ?>
    <button class="olt-tab" data-tab="<?php echo htmlspecialchars($pkey); ?>" role="tab">
        <i data-lucide="<?php echo htmlspecialchars($pmeta['icon'] ?? 'square'); ?>" style="width:14px;height:14px;"></i> <?php echo htmlspecialchars($pmeta['label'] ?? $pkey); ?>
    </button>
<?php endforeach; ?>
    <button class="olt-tab" data-tab="vlan" role="tab">
        <i data-lucide="layers" style="width:14px;height:14px;"></i> VLANs
    </button>
    <button class="olt-tab" data-tab="konfigurasi" role="tab">
        <i data-lucide="settings" style="width:14px;height:14px;"></i> Konfigurasi
    </button>
    <button class="olt-tab" data-tab="onu-list" role="tab">
        <i data-lucide="list" style="width:14px;height:14px;"></i> Daftar ONU
    </button>
</div>

<!-- =====================================================================
     TAB 1: MONITORING
     ===================================================================== -->
<div class="olt-tab-panel active" id="panel-monitoring">

    <div class="olt-section-head">
        <div>
            <h2><i data-lucide="activity" style="width:17px;height:17px;color:var(--text-accent);"></i> Status Perangkat</h2>
            <p>Ringkasan kesehatan OLT beserta informasi koneksi.</p>
        </div>
        <div class="olt-section-actions">
            <button id="btn-refresh-health" class="btn btn-primary">
                <i data-lucide="refresh-cw" style="width:14px;height:14px;"></i> Refresh Status
            </button>
        </div>
    </div>

    <div class="olt-inline-msg err hidden" id="health-error-container"></div>

    <!-- Metric Cards -->
    <div class="health-card-grid">
        <div class="health-metric">
            <div class="lbl">CPU Load</div>
            <div class="val" id="health-cpu"><?php echo htmlspecialchars($olt['last_cpu'] ?: 'N/A'); ?></div>
        </div>
        <div class="health-metric">
            <div class="lbl">RAM Usage</div>
            <div class="val" id="health-ram"><?php echo htmlspecialchars($olt['last_ram'] ?: 'N/A'); ?></div>
        </div>
        <div class="health-metric">
            <div class="lbl">Suhu Board</div>
            <div class="val" id="health-temp" style="color:<?php
                $t = (float)$olt['last_temp'];
                echo $t > 55 ? 'var(--color-danger)' : ($t > 48 ? 'var(--color-warning)' : 'var(--text-main)');
            ?>;"><?php echo htmlspecialchars($olt['last_temp'] ?: 'N/A'); ?></div>
        </div>
        <div class="health-metric">
            <div class="lbl">Status SNMP</div>
            <div class="val" id="health-snmp" style="font-size:1.1rem; color:<?php echo $olt['last_snmp_status'] === 'Connected' ? 'var(--color-success)' : 'var(--color-danger)'; ?>;">
                ● <?php echo htmlspecialchars($olt['last_snmp_status'] ?: 'Unknown'); ?>
            </div>
        </div>
    </div>

    <!-- Info Table -->
    <div class="pr-table-wrap">
    <table class="smartolt-data-table" style="width:100%;">
        <tbody>
            <tr><td style="width:30%; font-weight:600; color:var(--text-muted);">Tipe Hardware</td><td><?php echo htmlspecialchars($olt['type']); ?></td></tr>
            <tr><td style="font-weight:600; color:var(--text-muted);">Alamat IP &amp; Protokol</td><td style="font-family:monospace;"><?php echo htmlspecialchars($olt['ip']); ?> (<?php echo htmlspecialchars($olt['protocol'] ?? 'SSH'); ?>:<?php echo htmlspecialchars($olt['ssh_port']); ?>)</td></tr>
            <tr><td style="font-weight:600; color:var(--text-muted);">SNMP Community RO</td><td><code><?php echo htmlspecialchars($olt['snmp_community']); ?></code> (Port: <?php echo htmlspecialchars($olt['snmp_port']); ?>)</td></tr>
            <tr><td style="font-weight:600; color:var(--text-muted);">Uptime OLT</td><td id="health-uptime"><?php echo htmlspecialchars($olt['last_uptime'] ?: 'N/A'); ?></td></tr>
            <tr><td style="font-weight:600; color:var(--text-muted);">Nama Sistem (sysName)</td><td id="health-sysname">-</td></tr>
            <tr><td style="font-weight:600; color:var(--text-muted);">Lokasi (sysLocation)</td><td id="health-syslocation">-</td></tr>
            <tr><td style="font-weight:600; color:var(--text-muted);">Terakhir Diperiksa</td><td id="health-last-check" style="color:var(--text-muted); font-size:0.85rem;"><?php echo $olt['last_health_check'] ? date('d/m/Y H:i:s', strtotime($olt['last_health_check'])) : '-'; ?></td></tr>
        </tbody>
    </table>
    </div>

</div>

<!-- =====================================================================
     TAB 2: KONFIGURASI OLT
     ===================================================================== -->
<div class="olt-tab-panel" id="panel-konfigurasi">
    <div class="olt-section-head">
        <div>
            <h2><i data-lucide="settings" style="width:17px;height:17px;color:var(--text-accent);"></i> Konfigurasi OLT</h2>
            <p>Ubah parameter koneksi CLI dan SNMP perangkat ini.</p>
        </div>
    </div>

    <form action="action/edit-olt.php" method="POST" id="form-edit-olt-page">
        <input type="hidden" name="id"       value="<?php echo htmlspecialchars($olt['id']); ?>">
        <input type="hidden" name="redirect" value="../olt-detail.php?id=<?php echo htmlspecialchars($olt['id']); ?>#konfigurasi">

        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:18px;">

            <div style="grid-column:1/-1;">
                <label class="modal-label">Nama OLT / Lokasi</label>
                <input type="text" name="name" class="modal-input" value="<?php echo htmlspecialchars($olt['name']); ?>" required>
            </div>

            <div>
                <label class="modal-label">Tipe OLT</label>
                <select name="type" class="modal-input">
                    <?php
                    // Cocokkan tipe tersimpan (bisa berupa alias lama, mis. 'GPON')
                    // dengan entri registry agar pilihan yang tampil tetap benar.
                    $saved_meta = find_olt_driver_meta($olt['type']);
                    foreach (get_supported_olt_types() as $type_value => $type_label):
                        $is_selected = ($saved_meta !== null && find_olt_driver_meta($type_value) === $saved_meta);
                    ?>
                    <option value="<?php echo htmlspecialchars($type_value); ?>" <?php echo $is_selected ? 'selected' : ''; ?>><?php echo htmlspecialchars($type_label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="modal-label">Alamat IP OLT</label>
                <input type="text" name="ip" class="modal-input" value="<?php echo htmlspecialchars($olt['ip']); ?>" required>
            </div>

            <div>
                <label class="modal-label">Protokol CLI</label>
                <select name="protocol" id="detail-olt-protocol" class="modal-input">
                    <option value="SSH"    <?php echo ($olt['protocol'] ?? 'SSH') === 'SSH'    ? 'selected' : ''; ?>>SSH</option>
                    <option value="TELNET" <?php echo ($olt['protocol'] ?? 'SSH') === 'TELNET' ? 'selected' : ''; ?>>TELNET</option>
                </select>
            </div>

            <div>
                <label class="modal-label" id="detail-lbl-ssh-port">Port CLI</label>
                <input type="number" name="ssh_port" id="detail-olt-ssh-port" class="modal-input" value="<?php echo htmlspecialchars($olt['ssh_port']); ?>" required>
            </div>

            <div>
                <label class="modal-label">Username CLI</label>
                <input type="text" name="username" class="modal-input" value="<?php echo htmlspecialchars($olt['username']); ?>" required>
            </div>

            <div>
                <label class="modal-label">Password CLI</label>
                <input type="password" name="password" class="modal-input" placeholder="Kosongkan jika tidak diubah">
            </div>

            <div>
                <label class="modal-label">Community SNMP RO</label>
                <input type="text" name="snmp_community" class="modal-input" value="<?php echo htmlspecialchars($olt['snmp_community']); ?>" required>
            </div>

            <div>
                <label class="modal-label">Community SNMP RW</label>
                <input type="text" name="snmp_community_rw" class="modal-input" value="<?php echo htmlspecialchars($olt['snmp_community_rw']); ?>" required>
            </div>

            <div>
                <label class="modal-label">Port SNMP</label>
                <input type="number" name="snmp_port" class="modal-input" value="<?php echo htmlspecialchars($olt['snmp_port'] ?: 161); ?>" required>
            </div>

        </div>

        <div style="display:flex; justify-content:flex-end; margin-top:24px;">
            <button type="submit" class="btn btn-primary" style="display:inline-flex; align-items:center; gap:8px;">
                <i data-lucide="save" style="width:16px; height:16px;"></i> Simpan &amp; Verifikasi Koneksi
            </button>
        </div>
    </form>
</div>

<!-- =====================================================================
     TAB: INTERFACES (data realtime dari driver, brand-agnostic)
     ===================================================================== -->
<?php if (isset($driver_panels['interfaces'])): ?>
<div class="olt-tab-panel" id="panel-interfaces">
    <div class="olt-section-head">
        <div>
            <h2><i data-lucide="network" style="width:17px;height:17px;color:var(--text-accent);"></i> Management Interface</h2>
            <p>Management interface OLT, dibaca langsung dari perangkat.</p>
        </div>
        <div class="olt-section-actions">
            <button class="btn btn-primary lazy-refresh" data-panel="interfaces">
                <i data-lucide="refresh-cw" style="width:14px;height:14px;"></i> Refresh
            </button>
        </div>
    </div>

    <div id="render-interfaces"></div>
</div>
<?php endif; ?>

<!-- =====================================================================
     TAB 3: VLAN MANAGEMENT (baca/tulis langsung ke perangkat)
     ===================================================================== -->
<div class="olt-tab-panel" id="panel-vlan">

    <div class="olt-section-head">
        <div>
            <h2><i data-lucide="layers" style="width:17px;height:17px;color:var(--text-accent);"></i> Manajemen VLAN OLT</h2>
            <p>VLAN dibaca dan ditulis langsung ke perangkat — tabel di bawah adalah kondisi nyata OLT.</p>
        </div>
        <div class="olt-section-actions">
            <button class="btn btn-outline" id="btn-refresh-vlan">
                <i data-lucide="refresh-cw" style="width:14px;height:14px;"></i> Refresh
            </button>
            <button class="btn btn-primary" id="btn-add-vlan">
                <i data-lucide="plus" style="width:14px;height:14px;"></i> Tambah VLAN
            </button>
        </div>
    </div>

    <div class="pr-table-wrap">
        <table class="smartolt-data-table" style="width:100%;">
            <thead>
                <tr>
                    <th>VLAN ID</th>
                    <th>Deskripsi</th>
                    <th>Tipe</th>
                    <th>Tagged Ports</th>
                    <th>Untagged Ports</th>
                    <th>IP (vlanif)</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody id="vlan-tbody">
                <tr><td colspan="7" class="vlan-row-empty">Klik <strong>Refresh</strong> untuk membaca VLAN dari perangkat.</td></tr>
            </tbody>
        </table>
    </div>
</div>

<!-- =====================================================================
     TAB 5: DAFTAR ONU
     ===================================================================== -->
<div class="olt-tab-panel" id="panel-onu-list">
    <div class="olt-section-head">
        <div>
            <h2><i data-lucide="list" style="width:17px;height:17px;color:var(--text-accent);"></i> Daftar ONU Terdaftar</h2>
            <p>Total: <?php echo (int)$onu_stats['total']; ?> ONU · <?php echo (int)$onu_stats['online']; ?> Online</p>
        </div>
        <div class="olt-section-actions">
            <a href="configured.php?olt_id=<?php echo (int)$id; ?>" class="btn btn-outline" style="text-decoration:none;">
                <i data-lucide="external-link" style="width:14px;height:14px;"></i> Buka di Configured
            </a>
        </div>
    </div>
    <?php
    $onu_list = $pdo->prepare("SELECT id, name, serial_number, pon_port, onu_id, status, last_rx_power, zone, splitter FROM onus WHERE olt_id = ? ORDER BY pon_port, onu_id ASC LIMIT 100");
    $onu_list->execute([$id]);
    $onus = $onu_list->fetchAll();
    ?>
    <div class="pr-table-wrap">
    <table class="smartolt-data-table" style="width:100%;">
        <thead>
            <tr>
                <th>Nama</th>
                <th>Serial Number</th>
                <th>PON Port</th>
                <th>Zone</th>
                <th>ODB</th>
                <th>Rx Signal</th>
                <th>Status</th>
                <th>Aksi</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($onus)): ?>
                <tr><td colspan="8" class="vlan-row-empty">Belum ada ONU terdaftar.</td></tr>
            <?php else: ?>
                <?php foreach ($onus as $o): ?>
                <tr>
                    <td><?php echo htmlspecialchars($o['name']); ?></td>
                    <td><code style="font-size:0.8rem;"><?php echo htmlspecialchars($o['serial_number']); ?></code></td>
                    <td style="font-family:monospace; font-size:0.82rem;"><?php echo htmlspecialchars($o['pon_port']); ?>:<?php echo htmlspecialchars($o['onu_id']); ?></td>
                    <td><?php echo $o['zone'] ? '<span class="chip chip-purple">'.htmlspecialchars($o['zone']).'</span>' : '<span style="color:var(--text-muted);">-</span>'; ?></td>
                    <td><?php echo $o['splitter'] ? '<span class="chip chip-indigo">'.htmlspecialchars($o['splitter']).'</span>' : '<span style="color:var(--text-muted);">-</span>'; ?></td>
                    <td style="font-family:monospace; font-size:0.85rem; color:<?php
                        $rx = (float)$o['last_rx_power'];
                        echo $rx < -27 ? 'var(--color-danger)' : ($rx < -25 ? 'var(--color-warning)' : 'var(--color-success)');
                    ?>;"><?php echo $o['last_rx_power'] ? htmlspecialchars($o['last_rx_power']).' dBm' : 'N/A'; ?></td>
                    <td><span class="chip <?php echo $o['status'] === 'online' ? 'chip-green' : ($o['status'] === 'disabled' ? 'chip-slate' : 'chip-red'); ?>">● <?php echo htmlspecialchars(ucfirst($o['status'])); ?></span></td>
                    <td>
                        <a href="onu-detail.php?id=<?php echo (int)$o['id']; ?>" class="btn btn-xs btn-outline" style="text-decoration:none;">
                            <i data-lucide="eye" style="width:12px;height:12px;"></i> Detail
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<!-- =====================================================================
     TAB 6: OLT DETAILS
     ===================================================================== -->
<?php if (isset($driver_panels['olt-details'])): ?>
<div class="olt-tab-panel" id="panel-olt-details">
    <div class="olt-section-head">
        <div>
            <h2><i data-lucide="cpu" style="width:17px;height:17px;color:var(--text-accent);"></i> OLT Details</h2>
            <p>Informasi sistem, firmware, dan hardware lengkap dari OLT.</p>
        </div>
        <div class="olt-section-actions">
            <button class="btn btn-outline lazy-refresh" data-panel="olt-details">
                <i data-lucide="refresh-cw" style="width:14px;height:14px;"></i> Refresh
            </button>
        </div>
    </div>
    <div class="panel-render" id="render-olt-details"></div>
</div>
<?php endif; ?>

<!-- =====================================================================
     TAB 7: OLT CARDS
     ===================================================================== -->
<?php if (isset($driver_panels['olt-cards'])): ?>
<div class="olt-tab-panel" id="panel-olt-cards">
    <div class="olt-section-head">
        <div>
            <h2><i data-lucide="layout-grid" style="width:17px;height:17px;color:var(--text-accent);"></i> OLT Cards</h2>
            <p>Status board/card yang terpasang pada OLT.</p>
        </div>
        <div class="olt-section-actions">
            <button class="btn btn-outline lazy-refresh" data-panel="olt-cards">
                <i data-lucide="refresh-cw" style="width:14px;height:14px;"></i> Refresh
            </button>
        </div>
    </div>
    <div class="panel-render" id="render-olt-cards"></div>
</div>
<?php endif; ?>

<!-- =====================================================================
     TAB 8: PON PORTS
     ===================================================================== -->
<?php if (isset($driver_panels['pon-ports'])): ?>
<div class="olt-tab-panel" id="panel-pon-ports">
    <div class="olt-section-head">
        <div>
            <h2><i data-lucide="plug-zap" style="width:17px;height:17px;color:var(--text-accent);"></i> PON Ports</h2>
            <p>Status masing-masing port GPON/PON beserta jumlah ONU aktif.</p>
        </div>
        <div class="olt-section-actions">
            <button class="btn btn-outline lazy-refresh" data-panel="pon-ports">
                <i data-lucide="refresh-cw" style="width:14px;height:14px;"></i> Refresh
            </button>
        </div>
    </div>
    <!-- PON port cards populated by JS from DB -->
    <div id="pon-port-grid" style="display:grid; grid-template-columns:repeat(auto-fill,minmax(180px,1fr)); gap:12px; margin-bottom:20px;">
        <?php
        // Per-port ONU count from DB
        $port_stats = $pdo->prepare(
            "SELECT pon_port, COUNT(*) as total, SUM(status='online') as online
             FROM onus WHERE olt_id = ? GROUP BY pon_port ORDER BY pon_port"
        );
        $port_stats->execute([$id]);
        $ports = $port_stats->fetchAll();
        if (empty($ports)):
        ?>
            <p style="color:var(--text-muted); grid-column:1/-1;">Belum ada data port dari database.</p>
        <?php else: ?>
            <?php foreach ($ports as $pt): ?>
            <div class="health-metric" style="cursor:default; text-align:left; padding:14px 16px;">
                <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:8px;">
                    <span style="font-size:0.72rem; font-weight:700; text-transform:uppercase; color:var(--text-muted);">Port</span>
                    <span class="chip chip-teal" style="font-size:0.7rem; padding:2px 8px;"><?php echo htmlspecialchars($pt['pon_port']); ?></span>
                </div>
                <div style="display:flex; gap:12px; align-items:center;">
                    <div>
                        <div style="font-size:1.5rem; font-weight:700; color:var(--text-main);"><?php echo (int)$pt['total']; ?></div>
                        <div style="font-size:0.7rem; color:var(--text-muted);">Total ONU</div>
                    </div>
                    <div style="width:1px; height:36px; background:var(--border-color);"></div>
                    <div>
                        <div style="font-size:1.5rem; font-weight:700; color:var(--color-success);"><?php echo (int)$pt['online']; ?></div>
                        <div style="font-size:0.7rem; color:var(--text-muted);">Online</div>
                    </div>
                    <div>
                        <div style="font-size:1.5rem; font-weight:700; color:var(--color-danger);"><?php echo (int)$pt['total'] - (int)$pt['online']; ?></div>
                        <div style="font-size:0.7rem; color:var(--text-muted);">Offline</div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <div class="panel-render" id="render-pon-ports"></div>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const oltId = "<?php echo htmlspecialchars($olt['id']); ?>";

    function showDebugModal(logText) {
        let modal = document.getElementById('debug-log-modal');
        if (!modal) {
            modal = document.createElement('div');
            modal.id = 'debug-log-modal';
            modal.className = 'modal open';
            modal.style.zIndex = '9999';
            modal.style.display = 'flex';
            modal.style.alignItems = 'center';
            modal.style.justifyContent = 'center';
            modal.style.position = 'fixed';
            modal.style.top = '0';
            modal.style.left = '0';
            modal.style.width = '100%';
            modal.style.height = '100%';
            modal.style.background = 'rgba(0,0,0,0.5)';
            
            modal.innerHTML = `
                <div class="modal-content" style="max-width: 800px; width: 90%; border-radius: 6px; box-shadow: 0 4px 20px rgba(0,0,0,0.25); border: none; background: #1e1e1e; color: #f8f8f2; font-family: 'Courier New', Courier, monospace; display: flex; flex-direction: column;">
                    <div class="modal-header" style="border-bottom: 1px solid #333; padding: 15px 24px; display: flex; justify-content: space-between; align-items: center; background: #2d2d2d; border-top-left-radius: 6px; border-top-right-radius: 6px;">
                        <h3 style="margin:0; font-size:1.1rem; font-weight:500; color:#fff; display: flex; align-items: center; gap: 8px;">
                            <i data-lucide="terminal" style="width:18px; height:18px; color: #a6e22e;"></i> OLT CLI Debug Log Tracing
                        </h3>
                        <button type="button" class="close-btn" onclick="document.getElementById('debug-log-modal').style.display='none'" style="background:none; border:none; font-size:1.5rem; cursor:pointer; color:#94a3b8; line-height: 1;">&times;</button>
                    </div>
                    <div class="modal-body" style="padding: 24px; max-height: 450px; overflow-y: auto;">
                        <div style="font-weight: bold; color: #66d9ef; margin-bottom: 10px;">[Sent Commands & OLT CLI Trace Output]</div>
                        <pre id="debug-log-content" style="white-space: pre-wrap; font-size: 0.9rem; margin: 0; line-height: 1.4; color: #a6e22e; font-family: 'Courier New', Courier, monospace;"></pre>
                    </div>
                    <div class="modal-footer" style="padding: 15px 24px; border-top: 1px solid #333; display:flex; justify-content:flex-end; align-items:center; background: #2d2d2d; border-bottom-left-radius: 6px; border-bottom-right-radius: 6px;">
                        <button type="button" onclick="document.getElementById('debug-log-modal').style.display='none'" style="padding:8px 24px; border-radius:4px; background:#a6e22e; color:#000; border:none; cursor:pointer; font-weight:600; font-size:0.95rem; font-family: 'Montserrat', sans-serif;">Close</button>
                    </div>
                </div>
            `;
            document.body.appendChild(modal);
            lucide.createIcons();

            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    modal.style.display = 'none';
                }
            });
            modal.addEventListener('click', function(e) {
                if (e.target === modal) {
                    modal.style.display = 'none';
                }
            });
        }
        document.getElementById('debug-log-content').textContent = logText;
        modal.style.display = 'flex';
    }

    // ===== TAB SWITCHING =====
    const tabs   = document.querySelectorAll('.olt-tab');
    const panels = document.querySelectorAll('.olt-tab-panel');

    // Track which lazy tabs have already loaded
    const lazyLoaded = {};

    // Panel yang datanya diambil dari backend driver brand-spesifik.
    // TIDAK ada perintah CLI di sisi frontend — backend/python_engine/drivers/<driver>.py
    // yang menentukan perintah sekaligus mem-parsing hasilnya menjadi JSON.
    // Daftarnya berasal dari registry driver, bukan hardcode, supaya OLT yang
    // tidak mendukung sebuah panel tidak pernah memanggilnya.
    const driverPanels = <?php echo json_encode(array_keys($driver_panels)); ?>;

    function switchTab(name) {
        tabs.forEach(t => t.classList.toggle('active', t.dataset.tab === name));
        panels.forEach(p => p.classList.toggle('active', p.id === 'panel-' + name));
        location.hash = name;
        // Lazy load on first visit
        if (driverPanels.includes(name) && !lazyLoaded[name]) {
            lazyLoaded[name] = true;
            loadPanel(name);
        }
        if (name === 'vlan' && !lazyLoaded.vlan) {
            lazyLoaded.vlan = true;
            loadVlans();
            if (!lazyLoaded.interfaces) {
                lazyLoaded.interfaces = true;
                loadPanel('interfaces');
            }
        }
    }

    // ===== RENDERER PANEL DRIVER =====
    // Semua data datang dalam bentuk JSON terstruktur dari backend driver.
    // Frontend hanya bertugas menggambar — tidak tahu-menahu soal perintah CLI.

    function esc(v) {
        if (v === null || v === undefined) return '';
        return String(v)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    const STATE_COLOR = {
        good:    'var(--color-success)',
        warn:    'var(--color-warning)',
        bad:     'var(--color-danger)',
        neutral: 'var(--text-main)'
    };
    const STATE_CHIP = {
        good: 'chip-green', warn: 'chip-amber', bad: 'chip-red', neutral: 'chip-slate'
    };

    function renderMetrics(sec) {
        const items = (sec.items || []).map(it => {
            const color = STATE_COLOR[it.state] || STATE_COLOR.neutral;
            const size  = it.small ? '0.95rem' : '1.6rem';
            const unit  = it.unit ? `<span style="font-size:0.9rem;font-weight:600;margin-left:2px;">${esc(it.unit)}</span>` : '';
            return `
            <div class="health-metric" style="text-align:left;">
                <div class="pr-lbl">${esc(it.label)}</div>
                <div style="font-weight:700;font-size:${size};color:${color};line-height:1.3;word-break:break-word;">${esc(it.value)}${unit}</div>
            </div>`;
        }).join('');
        return `<div class="pr-section">${secTitle(sec)}<div class="pr-grid">${items}</div></div>`;
    }

    function renderCards(sec) {
        const items = (sec.items || []).map(it => {
            const color = STATE_COLOR[it.state] || STATE_COLOR.neutral;
            const chip  = STATE_CHIP[it.state] || 'chip-slate';
            const badge = it.badge ? `<span class="chip ${chip}" style="font-size:0.72rem;">● ${esc(it.badge)}</span>` : '';
            const sub   = it.subtitle ? `<div style="font-size:0.75rem;color:var(--text-muted);margin-top:8px;">${esc(it.subtitle)}</div>` : '';
            return `
            <div class="health-metric" style="text-align:left;">
                <div class="pr-lbl">${esc(it.title)}</div>
                <div style="font-weight:700;font-size:1rem;color:${color};margin-bottom:8px;word-break:break-word;">${esc(it.value)}</div>
                ${badge}${sub}
            </div>`;
        }).join('');
        return `<div class="pr-section">${secTitle(sec)}<div class="pr-grid">${items}</div></div>`;
    }

    function renderTable(sec) {
        // Kolom aksi opsional. Driver mengirim sec.action = { label, event, args: [ {...}, ... ] }
        // dengan satu entri args per baris. Frontend hanya menggambar tombol dan menyimpan
        // datanya di data-args — tidak tahu-menahu soal perintah CLI.
        const act  = sec.action;
        const cols = (sec.columns || []).map(c => `<th>${esc(c)}</th>`).join('')
                   + (act ? `<th>Aksi</th>` : '');
        const rows = (sec.rows || []).map((r, i) => {
            let cells = (r || []).map(c => `<td>${esc(c)}</td>`).join('');
            if (act) {
                const arg = (act.args || [])[i];
                cells += arg
                    ? `<td><button class="btn btn-xs btn-outline pr-action" data-event="${esc(act.event)}"
                         data-args="${esc(JSON.stringify(arg))}">${esc(act.label)}</button></td>`
                    : '<td>-</td>';
            }
            return `<tr>${cells}</tr>`;
        }).join('');
        if (!rows) return '';
        return `<div class="pr-section">${secTitle(sec)}
            <div class="pr-table-wrap"><table class="pr-table"><thead><tr>${cols}</tr></thead><tbody>${rows}</tbody></table></div>
        </div>`;
    }

    function secTitle(sec) {
        return sec.title ? `<h4 class="pr-title">${esc(sec.title)}</h4>` : '';
    }

    function renderSections(container, sections) {
        const html = (sections || []).map(sec => {
            if (sec.type === 'metrics') return renderMetrics(sec);
            if (sec.type === 'cards')   return renderCards(sec);
            if (sec.type === 'table')   return renderTable(sec);
            return '';
        }).join('');
        container.innerHTML = html || `<div class="pr-empty">Tidak ada data yang bisa ditampilkan.</div>`;
    }

    async function loadPanel(panelName) {
        // Driver OLT ini tidak mendukung panel tsb -> jangan panggil backend.
        if (!driverPanels.includes(panelName)) return;
        const el = document.getElementById('render-' + panelName);
        if (!el) return;

        el.innerHTML = `<div class="pr-loading"><span class="spinner-small"></span> Mengambil data dari OLT...</div>`;

        try {
            const res  = await fetch(`action/get-olt-panel.php?id=${encodeURIComponent(oltId)}&panel=${encodeURIComponent(panelName)}`, {
                headers: { 'Accept': 'application/json' }
            });
            const text = await res.text();

            let data;
            try {
                data = JSON.parse(text);
            } catch (_) {
                el.innerHTML = `<div class="pr-error">Respons server tidak valid.</div>`;
                return;
            }

            if (!data.success) {
                el.innerHTML = `<div class="pr-error">${esc(data.message || 'Gagal mengambil data dari OLT.')}</div>`;
                return;
            }

            renderSections(el, data.sections);

            if (panelName === 'interfaces' && data.sections) {
                const tableSec = data.sections.find(sec => sec.type === 'table');
                if (tableSec && tableSec.action && tableSec.action.args) {
                    window.oltPorts = tableSec.action.args.map(a => a.port);
                }
            }

            if (data.message) {
                el.insertAdjacentHTML('afterbegin', `<div class="pr-note">${esc(data.message)}</div>`);
            }
            if (data.fetched_at) {
                el.insertAdjacentHTML('beforeend', `<div class="pr-stamp">Diperbarui: ${esc(data.fetched_at)}</div>`);
            }
        } catch (e) {
            el.innerHTML = `<div class="pr-error">Gagal terhubung ke server: ${esc(e.message)}</div>`;
        }
    }

    tabs.forEach(t => t.addEventListener('click', () => switchTab(t.dataset.tab)));

    // Restore tab from URL hash
    const hash = location.hash.replace('#', '');
    if (hash && document.getElementById('panel-' + hash)) switchTab(hash);

    // Wire up Refresh buttons on lazy tabs
    document.querySelectorAll('.lazy-refresh').forEach(btn => {
        btn.addEventListener('click', () => {
            const panelName = btn.dataset.panel;
            if (driverPanels.includes(panelName)) {
                lazyLoaded[panelName] = true; // tandai agar switchTab tidak memuat ulang
                loadPanel(panelName);
            }
        });
    });

    // ===== PROTOCOL PORT SYNC =====
    const protocolSelect = document.getElementById('detail-olt-protocol');
    const portLabel      = document.getElementById('detail-lbl-ssh-port');
    const portInput      = document.getElementById('detail-olt-ssh-port');

    if (protocolSelect) {
        function updatePortDefaults() {
            const proto = protocolSelect.value;
            if (proto === 'TELNET') {
                portLabel.textContent = 'Port Telnet';
                if (portInput.value == '22' || portInput.value == '') portInput.value = '23';
            } else {
                portLabel.textContent = 'Port SSH';
                if (portInput.value == '23' || portInput.value == '') portInput.value = '22';
            }
        }
        protocolSelect.addEventListener('change', updatePortDefaults);
        updatePortDefaults();
    }

    // ===== HEALTH MONITORING =====
    function fetchOltHealth() {
        const cpuEl      = document.getElementById('health-cpu');
        const ramEl      = document.getElementById('health-ram');
        const tempEl     = document.getElementById('health-temp');
        const snmpEl     = document.getElementById('health-snmp');
        const uptimeEl   = document.getElementById('health-uptime');
        const sysNameEl  = document.getElementById('health-sysname');
        const sysLocEl   = document.getElementById('health-syslocation');
        const snmpBadge  = document.getElementById('olt-snmp-badge');
        const errBox     = document.getElementById('health-error-container');
        const lastCheck  = document.getElementById('health-last-check');

        cpuEl.innerHTML = ramEl.innerHTML = '<span class="spinner-small"></span>';
        tempEl.innerHTML = '<span class="spinner-small"></span>';
        snmpEl.innerHTML = '● Loading...'; snmpEl.style.color = 'var(--text-muted)';
        uptimeEl.innerHTML = '<span class="spinner-small"></span> Memuat...';
        snmpBadge.textContent = '● Connecting...'; snmpBadge.className = 'badge bg-orange';
        errBox.classList.add('hidden');

        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 45000); // 45 detik max

        fetch(`action/get-olt-health.php?id=${oltId}`, { signal: controller.signal })
            .then(r => { clearTimeout(timeoutId); return r.json(); })
            .then(data => {
                if (data.success) {
                    cpuEl.textContent    = data.cpu;
                    ramEl.textContent    = data.ram;
                    tempEl.textContent   = data.temp;
                    uptimeEl.textContent = data.uptime;
                    sysNameEl.textContent = data.sys_name || '-';
                    sysLocEl.textContent  = data.sys_location || '-';
                    snmpEl.textContent   = `● ${data.snmp_status}`;
                    snmpEl.style.color   = data.snmp_status === 'Connected' ? 'var(--color-success)' : 'var(--color-danger)';
                    snmpBadge.textContent = `● ${data.snmp_status}`;
                    snmpBadge.className   = 'badge ' + (data.snmp_status === 'Connected' ? 'bg-green' : 'bg-red');
                    const tempNum = parseFloat(data.temp);
                    if (!isNaN(tempNum)) {
                        tempEl.style.color = tempNum > 55 ? 'var(--color-danger)' : tempNum > 48 ? 'var(--color-warning)' : 'var(--text-main)';
                    }
                    lastCheck.textContent = new Date().toLocaleString();
                } else {
                    ['cpu','ram','temp'].forEach(k => document.getElementById('health-'+k).textContent = 'N/A');
                    snmpEl.textContent = '● Disconnected'; snmpEl.style.color = 'var(--color-danger)';
                    snmpBadge.textContent = '● Disconnected'; snmpBadge.className = 'badge bg-red';
                    uptimeEl.textContent = 'N/A';
                    errBox.innerHTML = `<strong>⚠️ Gagal Terhubung ke OLT Fisik:</strong><br><span style="font-family:monospace;">${esc(data.message || '')}</span>`;
                    errBox.classList.remove('hidden');
                }
            })
            .catch(err => {
                ['cpu','ram','temp'].forEach(k => document.getElementById('health-'+k).textContent = 'N/A');
                snmpEl.textContent = '● Error'; snmpEl.style.color = 'var(--color-danger)';
                snmpBadge.textContent = '● Error'; snmpBadge.className = 'badge bg-red';
                uptimeEl.textContent = 'N/A';
                const msg = err.name === 'AbortError' ? 'Timeout: OLT tidak merespon dalam 45 detik. Periksa koneksi jaringan.' : err.message;
                errBox.innerHTML = `<strong>⚠️ Error:</strong> ${esc(msg)}`;
                errBox.classList.remove('hidden');
            });
    }

    fetchOltHealth();
    document.getElementById('btn-refresh-health').addEventListener('click', fetchOltHealth);

    // ===== MANAJEMEN VLAN =====
    const btnAddVlan  = document.getElementById('btn-add-vlan');
    const vlanTbody   = document.getElementById('vlan-tbody');

    function vlanModalNotify(text, ok) {
        const msgEl = document.getElementById('vlan-modal-msg');
        if (!msgEl) return;
        msgEl.className = 'olt-inline-msg ' + (ok ? 'ok' : 'err');
        msgEl.textContent = text;
        msgEl.style.display = 'block';
    }

    async function vlanApi(payload) {
        const body = new URLSearchParams(Object.assign({ olt_id: oltId }, payload));
        const res  = await fetch('action/vlan.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body:    body.toString()
        });
        return res.json();
    }

    function renderVlanRows(vlans) {
        if (!vlans || !vlans.length) {
            vlanTbody.innerHTML = '<tr><td colspan="7" class="vlan-row-empty">Tidak ada VLAN pada perangkat ini.</td></tr>';
            return;
        }

        const formatPortsClean = (portsStr) => {
            if (!portsStr || portsStr.trim() === '' || portsStr === '-') {
                return '-';
            }
            // Pemisah nama port berbeda antar vendor: CData "ge 0/0/1" (spasi),
            // ZTE "gei_1/3/2" / "xgei_1/3/2" (garis bawah). Alternasi ditulis dari
            // yang TERPANJANG dulu supaya "xgei" tidak tercocok sebagian jadi "ge".
            const ports = portsStr.match(/(?:xgei|gei|xge|ge|gpon)[\s_]\d+\/\d+\/\d+/gi) || [];

            // Vendor lain bisa memakai penamaan yang belum dikenal — jangan
            // tampilkan '-' seolah kosong; tampilkan apa adanya.
            if (!ports.length) return esc(portsStr.trim());

            // Group by interface prefix (e.g. 'gpon', 'ge', 'xge')
            const groups = {};
            ports.forEach(port => {
                const p = port.toLowerCase();
                let prefix = 'other';
                if (p.startsWith('gpon')) {
                    prefix = 'gpon';
                } else if (p.startsWith('xge')) {
                    prefix = 'xge';
                } else if (p.startsWith('ge')) {
                    prefix = 'ge';
                }
                if (!groups[prefix]) {
                    groups[prefix] = [];
                }
                groups[prefix].push(port);
            });

            // Join each group with commas, then join groups with ' - '
            // Define specific order of groups for consistency: gpon, ge, xge, other
            const order = ['gpon', 'ge', 'xge', 'other'];
            const parts = [];
            order.forEach(key => {
                if (groups[key] && groups[key].length) {
                    parts.push(groups[key].join(', '));
                }
            });
            return esc(parts.join(' - '));
        };

        vlanTbody.innerHTML = vlans.map(v => {
            const locked = v.protected;
            const btnEdit = '<button class="btn btn-xs btn-outline vlan-edit" style="margin-right: 6px;" data-vlan=\'' + esc(JSON.stringify(v)) + '\'><i data-lucide="pencil" style="width:10px;height:10px;display:inline-block;vertical-align:middle;margin-right:2px;"></i> Edit</button>';
            const aksi = btnEdit + (locked
                ? '<span class="chip chip-slate" title="VLAN sistem / layer 3 tidak dapat dihapus">Terproteksi</span>'
                : '<button class="btn btn-xs btn-danger vlan-del" data-vlan="' + esc(v.id) + '">Hapus</button>');
            return '<tr>' +
                '<td><span class="chip chip-indigo">' + esc(v.id) + '</span></td>' +
                '<td>' + (v.description ? esc(v.description) : '-') + '</td>' +
                '<td><span class="chip chip-slate">' + esc(v.type) + '</span></td>' +
                '<td>' + formatPortsClean(v.tagged) + '</td>' +
                '<td>' + formatPortsClean(v.untagged) + '</td>' +
                '<td>' + (v.ip       ? esc(v.ip)       : '-') + '</td>' +
                '<td>' + aksi + '</td>' +
            '</tr>';
        }).join('');
        lucide.createIcons();
    }

    async function loadVlans() {
        if (!vlanTbody) return;
        vlanTbody.innerHTML = '<tr><td colspan="7" class="vlan-row-empty">Membaca VLAN dari perangkat...</td></tr>';
        try {
            const data = await vlanApi({ action: 'list' });
            if (data.success) {
                renderVlanRows(data.vlans);
            } else {
                vlanTbody.innerHTML = '<tr><td colspan="7" class="vlan-row-empty">' + esc(data.message) + '</td></tr>';
            }
        } catch (e) {
            vlanTbody.innerHTML = '<tr><td colspan="7" class="vlan-row-empty">Gagal menghubungi server.</td></tr>';
        }
    }

    const btnRefreshVlan = document.getElementById('btn-refresh-vlan');
    if (btnRefreshVlan) btnRefreshVlan.addEventListener('click', loadVlans);

    function openVlanModal(vlanData = null) {
        const titleEl = document.getElementById('vlan-modal-title');
        const idInput = document.getElementById('vlan-modal-id');
        const descInput = document.getElementById('vlan-modal-desc');
        const tbody = document.getElementById('vlan-modal-ports-tbody');

        tbody.innerHTML = '';
        document.getElementById('vlan-modal-msg').style.display = 'none';

        if (vlanData) {
            titleEl.innerHTML = `<i data-lucide="pencil" style="width:18px;height:18px;display:inline-block;vertical-align:middle;margin-right:4px;"></i> Edit VLAN ${esc(vlanData.id)}`;
            idInput.value = vlanData.id;
            idInput.disabled = true;
            descInput.value = vlanData.description || '';
        } else {
            titleEl.innerHTML = `<i data-lucide="plus-circle" style="width:18px;height:18px;display:inline-block;vertical-align:middle;margin-right:4px;"></i> Tambah VLAN Baru`;
            idInput.value = '';
            idInput.disabled = false;
            descInput.value = '';
        }

        // Alternatif terpanjang lebih dulu supaya 'xgei_1/3/2' tidak tertangkap
        // sebagian sebagai 'ge'. Pemisah bisa spasi (CData 'ge 0/0/1') atau
        // underscore (ZTE 'xgei_1/3/2').
        const normPort = (p) => p.trim().toLowerCase().replace(/\s+/g, ' ');
        const parsePorts = (str) => {
            if (!str) return [];
            const matches = str.match(/(?:xgei|gei|xge|ge|gpon)[\s_]\d+\/\d+\/\d+/gi);
            return matches ? matches.map(normPort) : [];
        };

        const ports = window.oltPorts || [];
        if (ports.length === 0) {
            tbody.innerHTML = '<tr><td colspan="4" style="text-align:center; padding:12px; color:var(--text-muted);">Memuat daftar port... Mohon tunggu sebentar.</td></tr>';
        } else {
            ports.forEach(port => {
                let currentAssignment = 'none';
                if (vlanData) {
                    const tagList = parsePorts(vlanData.tagged);
                    const untagList = parsePorts(vlanData.untagged);
                    if (tagList.includes(normPort(port))) {
                        currentAssignment = 'tagged';
                    } else if (untagList.includes(normPort(port))) {
                        currentAssignment = 'untagged';
                    }
                }

                tbody.innerHTML += `
                    <tr>
                        <td style="font-weight: 600; vertical-align: middle;">${esc(port)}</td>
                        <td style="text-align: center; vertical-align: middle;">
                            <input type="radio" name="vlan_port_${esc(port)}" value="tagged" ${currentAssignment === 'tagged' ? 'checked' : ''}>
                        </td>
                        <td style="text-align: center; vertical-align: middle;">
                            <input type="radio" name="vlan_port_${esc(port)}" value="untagged" ${currentAssignment === 'untagged' ? 'checked' : ''}>
                        </td>
                        <td style="text-align: center; vertical-align: middle;">
                            <input type="radio" name="vlan_port_${esc(port)}" value="none" ${currentAssignment === 'none' ? 'checked' : ''}>
                        </td>
                    </tr>
                `;
            });
        }

        lucide.createIcons();
        document.getElementById('vlan-modal').classList.add('open');
    }

    if (btnAddVlan) {
        btnAddVlan.addEventListener('click', () => {
            openVlanModal(null);
        });
    }

    const btnVlanModalSubmit = document.getElementById('btn-vlan-modal-submit');
    if (btnVlanModalSubmit) {
        btnVlanModalSubmit.addEventListener('click', async () => {
            const vid = document.getElementById('vlan-modal-id').value.trim();
            const desc = document.getElementById('vlan-modal-desc').value.trim();
            if (!vid || +vid < 1 || +vid > 4094) {
                vlanModalNotify('VLAN ID harus berada pada rentang 1-4094.', false);
                return;
            }

            const portsConfig = {};
            const ports = window.oltPorts || [];
            ports.forEach(port => {
                const radios = document.getElementsByName(`vlan_port_${port}`);
                let val = 'none';
                for (const r of radios) {
                    if (r.checked) {
                        val = r.value;
                        break;
                    }
                }
                portsConfig[port] = val;
            });

            btnVlanModalSubmit.disabled = true;
            btnVlanModalSubmit.textContent = 'Menyimpan...';

            try {
                const body = new URLSearchParams({
                    olt_id: oltId,
                    action: 'save-vlan-ports',
                    vlan_id: vid,
                    description: desc,
                    ports_config: JSON.stringify(portsConfig)
                });
                const res = await fetch('action/vlan.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString()
                });
                const data = await res.json();
                
                vlanModalNotify(data.message, !!data.success);
                if (data.debug_log) {
                    showDebugModal(data.debug_log);
                }
                if (data.success) {
                    setTimeout(() => {
                        closeVlanModal();
                        loadVlans();
                        loadPanel('interfaces');
                    }, 1000);
                }
            } catch (e) {
                vlanModalNotify('Gagal menghubungi server.', false);
            }

            btnVlanModalSubmit.disabled = false;
            btnVlanModalSubmit.textContent = 'Simpan ke OLT';
        });
    }

    if (vlanTbody) {
        vlanTbody.addEventListener('click', async ev => {
            const editBtn = ev.target.closest('.vlan-edit');
            if (editBtn) {
                const vlanData = JSON.parse(editBtn.dataset.vlan);
                openVlanModal(vlanData);
                return;
            }

            const delBtn = ev.target.closest('.vlan-del');
            if (delBtn) {
                const vid = delBtn.dataset.vlan;
                if (!confirm('Hapus VLAN ' + vid + ' dari OLT?')) return;

                delBtn.disabled = true;
                try {
                    let data = await vlanApi({ action: 'delete', vlan_id: vid });

                    if (!data.success && data.needs_confirm) {
                        if (confirm(data.message + '\n\nLanjutkan menghapus VLAN ' + vid + '?')) {
                            data = await vlanApi({ action: 'delete', vlan_id: vid, confirm: '1' });
                        } else {
                            delBtn.disabled = false;
                            return;
                        }
                    }
                    alert(data.message);
                    if (data.debug_log) {
                        showDebugModal(data.debug_log);
                    }
                    loadVlans();
                    loadPanel('interfaces');
                } catch (e) {
                    alert('Gagal menghubungi server.');
                    delBtn.disabled = false;
                }
            }
        });
    }

    if (location.hash.replace('#', '') === 'vlan') {
        lazyLoaded.vlan = true;
        loadVlans();
        if (!lazyLoaded.interfaces) {
            lazyLoaded.interfaces = true;
            loadPanel('interfaces');
        }
    }

    // ===== EDIT PORT CONFIG (Auto-nego, Speed, Duplex) =====
    const portConfigModal = document.getElementById('port-config-modal');
    const pcAutoNego = document.getElementById('pc-auto-nego');
    const pcSpeedWrap = document.getElementById('pc-speed-wrap');
    const pcDuplexWrap = document.getElementById('pc-duplex-wrap');
    const btnPortConfigSubmit = document.getElementById('btn-port-config-submit');

    let portConfigCurrent = null;

    function portConfigNotify(text, ok) {
        const msgEl = document.getElementById('port-config-msg');
        if (!msgEl) return;
        msgEl.className = 'olt-inline-msg ' + (ok ? 'ok' : 'err');
        msgEl.textContent = text;
        msgEl.style.display = 'block';
    }

    function updatePortConfigForm() {
        if (!pcAutoNego) return;
        const auto = pcAutoNego.value === 'enable';
        pcSpeedWrap.style.display = auto ? 'none' : 'block';
        pcDuplexWrap.style.display = auto ? 'none' : 'block';
    }

    if (pcAutoNego) {
        pcAutoNego.addEventListener('change', updatePortConfigForm);
    }

    document.addEventListener('click', ev => {
        const btn = ev.target.closest('.pr-action[data-event="port-config"]');
        if (!btn) return;

        const arg = JSON.parse(btn.dataset.args);
        portConfigCurrent = arg;

        document.getElementById('pc-port-label').textContent = esc(arg.port);
        document.getElementById('pc-auto-nego').value = arg.auto_nego === 'enable' ? 'enable' : 'disable';

        let speedVal = '1000';
        if (arg.speed) {
            const num = String(arg.speed).replace(/[^0-9]/g, '');
            if (num) speedVal = num;
        }
        // Port gigabit tidak bisa dipaksa 10 Gbps — perangkat menolaknya.
        // Sembunyikan opsinya daripada membiarkan pengguna mengirim nilai gagal.
        const isTenGig = /^xge/i.test(arg.port);
        const opt10g = document.querySelector('#pc-speed option[value="10000"]');
        if (opt10g) opt10g.hidden = !isTenGig;
        if (!isTenGig && speedVal === '10000') speedVal = '1000';
        document.getElementById('pc-speed').value = speedVal;
        document.getElementById('pc-duplex').value = (arg.duplex && arg.duplex.toLowerCase() === 'half') ? 'half' : 'full';

        updatePortConfigForm();
        document.getElementById('port-config-msg').style.display = 'none';
        portConfigModal.classList.add('open');
    });

    if (btnPortConfigSubmit) {
        btnPortConfigSubmit.addEventListener('click', async () => {
            if (!portConfigCurrent) return;

            const autoNego = pcAutoNego.value;
            const speed = document.getElementById('pc-speed').value;
            const duplex = document.getElementById('pc-duplex').value;

            btnPortConfigSubmit.disabled = true;
            btnPortConfigSubmit.textContent = 'Menyimpan...';

            try {
                const body = new URLSearchParams({
                    olt_id: oltId,
                    port: portConfigCurrent.port,
                    auto_nego: autoNego,
                    speed: speed,
                    duplex: duplex
                });
                const res = await fetch('action/port-config.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString()
                });
                const data = await res.json();

                portConfigNotify(data.message, !!data.success);

                if (data.success) {
                    setTimeout(() => {
                        closePortConfigModal();
                        loadPanel('interfaces');
                    }, 1000);
                }
            } catch (e) {
                portConfigNotify('Gagal menghubungi server.', false);
            }

            btnPortConfigSubmit.disabled = false;
            btnPortConfigSubmit.textContent = 'Simpan';
        });
    }

    // Modal close helpers
    const closeVlanModal = () => {
        document.getElementById('vlan-modal').classList.remove('open');
    };
    const closePortConfigModal = () => {
        portConfigModal.classList.remove('open');
    };

    document.getElementById('btn-close-vlan-modal').addEventListener('click', closeVlanModal);
    document.getElementById('btn-cancel-vlan-modal').addEventListener('click', closeVlanModal);
    document.getElementById('btn-close-port-config-modal').addEventListener('click', closePortConfigModal);
    document.getElementById('btn-cancel-port-config-modal').addEventListener('click', closePortConfigModal);

    window.addEventListener('click', (e) => {
        if (e.target === document.getElementById('vlan-modal')) closeVlanModal();
        if (e.target === portConfigModal) closePortConfigModal();
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            closeVlanModal();
            closePortConfigModal();
        }
    });

});
</script>

<!-- MODAL ADD/EDIT VLAN -->
<div id="vlan-modal" class="modal">
    <div class="modal-content" style="max-width: 650px;">
        <div class="modal-header">
            <h2 id="vlan-modal-title"><i data-lucide="layers"></i> Tambah VLAN</h2>
            <button class="modal-close" id="btn-close-vlan-modal">&times;</button>
        </div>
        <div class="modal-body">
            <div class="olt-form-grid" style="grid-template-columns: 1fr 2fr; gap: 16px; margin-bottom: 20px;">
                <div>
                    <label class="modal-label">VLAN ID</label>
                    <input type="number" id="vlan-modal-id" class="modal-input" placeholder="e.g. 100" min="1" max="4094" required>
                </div>
                <div>
                    <label class="modal-label">Deskripsi (opsional, maks 32 karakter)</label>
                    <input type="text" id="vlan-modal-desc" class="modal-input" placeholder="e.g. PPPOE" maxlength="32" pattern="[A-Za-z0-9 ._-]*">
                </div>
            </div>

            <div style="margin-top: 20px;">
                <h3 style="font-size: 0.9rem; font-weight: 700; text-transform: uppercase; color: var(--text-muted); margin-bottom: 12px; border-bottom: 1px solid var(--border-color); padding-bottom: 6px;">
                    Konfigurasi Port Uplink
                </h3>
                <div class="pr-table-wrap">
                    <table class="data-table" style="width: 100%;">
                        <thead>
                            <tr>
                                <th>Port</th>
                                <th style="text-align: center; width: 120px;">Tagged</th>
                                <th style="text-align: center; width: 120px;">Untagged</th>
                                <th style="text-align: center; width: 120px;">None</th>
                            </tr>
                        </thead>
                        <tbody id="vlan-modal-ports-tbody">
                            <!-- Populated dynamically via JS -->
                        </tbody>
                    </table>
                </div>
            </div>
            
            <p style="margin: 16px 0 0; font-size: 0.78rem; color: var(--color-warning);">
                ⚠️ Mengubah port VLAN dapat berdampak pada kelancaran trafik pelanggan. Pastikan setelan Anda sudah benar.
            </p>
            <div id="vlan-modal-msg" class="olt-inline-msg" style="margin-top: 15px;"></div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" id="btn-cancel-vlan-modal">Batal</button>
            <button type="button" class="btn btn-primary" id="btn-vlan-modal-submit">Simpan ke OLT</button>
        </div>
    </div>
</div>

<!-- MODAL EDIT PORT NEGOTIATION -->
<div id="port-config-modal" class="modal">
    <div class="modal-content" style="max-width: 450px;">
        <div class="modal-header">
            <h2><i data-lucide="settings"></i> Edit Port <span id="pc-port-label"></span></h2>
            <button class="modal-close" id="btn-close-port-config-modal">&times;</button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label class="modal-label">Auto Negotiation</label>
                <select id="pc-auto-nego" class="modal-input">
                    <option value="enable">Enable (Auto speed & duplex)</option>
                    <option value="disable">Disable (Force speed & duplex)</option>
                </select>
            </div>
            <div class="form-group" id="pc-speed-wrap">
                <label class="modal-label">Speed (Mbps)</label>
                <select id="pc-speed" class="modal-input">
                    <option value="10">10 Mbps</option>
                    <option value="100">100 Mbps</option>
                    <option value="1000">1000 Mbps (1 Gbps)</option>
                    <option value="10000">10000 Mbps (10 Gbps)</option>
                </select>
            </div>
            <div class="form-group" id="pc-duplex-wrap">
                <label class="modal-label">Duplex</label>
                <select id="pc-duplex" class="modal-input">
                    <option value="full">Full Duplex</option>
                    <option value="half">Half Duplex</option>
                </select>
            </div>
            <div id="port-config-msg" class="olt-inline-msg" style="margin-top: 15px;"></div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" id="btn-cancel-port-config-modal">Batal</button>
            <button type="button" class="btn btn-primary" id="btn-port-config-submit">Simpan</button>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
