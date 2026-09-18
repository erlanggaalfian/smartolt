<?php
// ==============================================================================
# SmartOLT Configured ONUs Page
// ==============================================================================
require_once __DIR__ . '/header.php';

// Ambil OLT yang diizinkan untuk dropdown filter
$olts = get_allowed_olts();
$allowed_ids = array_map(function($o) { return (int)$o['id']; }, $olts);
$allowed_ids_str = implode(',', $allowed_ids) ?: '0';

$olt_id_raw = $_GET['olt_id'] ?? '';
$selected_olt_id = ($olt_id_raw !== '' && $olt_id_raw !== null) ? (int)$olt_id_raw : '';
if ($selected_olt_id !== '' && !has_olt_access($selected_olt_id)) {
    $selected_olt_id = '';
}
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status = isset($_GET['status']) ? $_GET['status'] : '';
$filter_zone = isset($_GET['zone']) ? trim($_GET['zone']) : '';
$filter_odb  = isset($_GET['odb'])  ? trim($_GET['odb'])  : '';
$filter_pon     = isset($_GET['pon_port']) ? trim($_GET['pon_port']) : '';
$filter_signal  = isset($_GET['signal']) ? $_GET['signal'] : '';
$filter_type    = isset($_GET['onu_type']) ? trim($_GET['onu_type']) : '';

// Catatan: Sinkronisasi otomatis OLT kini dijalankan di latar belakang oleh cron job (backend/python_engine/cron_sync.py).
// Halaman ini memuat data secara instan dari database lokal MySQL.

// Ambil daftar Zone, ODB, & PON unik dari database untuk dropdown
if ($selected_olt_id !== '') {
    $zone_query_base = "SELECT DISTINCT zone FROM onus WHERE olt_id = " . (int)$selected_olt_id . " AND zone IS NOT NULL AND zone != ''";
    $odb_query_base  = "SELECT DISTINCT splitter FROM onus WHERE olt_id = " . (int)$selected_olt_id . " AND splitter IS NOT NULL AND splitter != ''";
    $pon_query_base  = "SELECT DISTINCT pon_port FROM onus WHERE olt_id = " . (int)$selected_olt_id . " AND pon_port IS NOT NULL AND pon_port != ''";
} else {
    $zone_query_base = "SELECT DISTINCT zone FROM onus WHERE olt_id IN ($allowed_ids_str) AND zone IS NOT NULL AND zone != ''";
    $odb_query_base  = "SELECT DISTINCT splitter FROM onus WHERE olt_id IN ($allowed_ids_str) AND splitter IS NOT NULL AND splitter != ''";
    $pon_query_base  = "SELECT DISTINCT pon_port FROM onus WHERE olt_id IN ($allowed_ids_str) AND pon_port IS NOT NULL AND pon_port != ''";
}
$available_zones = $pdo->query($zone_query_base . " ORDER BY zone ASC")->fetchAll(PDO::FETCH_COLUMN);
$available_odbs  = $pdo->query($odb_query_base  . " ORDER BY splitter ASC")->fetchAll(PDO::FETCH_COLUMN);
$available_pons  = $pdo->query($pon_query_base  . " ORDER BY pon_port ASC")->fetchAll(PDO::FETCH_COLUMN);
$available_types = $pdo->query("SELECT DISTINCT onu_type FROM onus WHERE onu_type IS NOT NULL AND onu_type != ''" . ($selected_olt_id !== '' ? " AND olt_id = " . (int)$selected_olt_id : " AND olt_id IN ($allowed_ids_str)") . " ORDER BY onu_type ASC")->fetchAll(PDO::FETCH_COLUMN);

// Pagination limit logic (default to 100)
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
if (!in_array($limit, [20, 50, 100, 200])) $limit = 100;

// Bangun query filter untuk COUNT
$sql_count = "SELECT COUNT(*) 
              FROM onus 
              JOIN olts ON onus.olt_id = olts.id 
              WHERE 1=1";
$params = [];

$filter_sql = "";
if ($search !== '') {
    $filter_sql .= " AND (onus.name LIKE ? OR onus.serial_number LIKE ? OR onus.onu_id LIKE ? OR onus.pppoe_username LIKE ? OR onus.address LIKE ? OR onus.description LIKE ? OR onus.external_id LIKE ? OR onus.contact LIKE ? OR olts.name LIKE ? OR olts.ip LIKE ? OR onus.vlan LIKE ? OR onus.pon_port LIKE ? OR onus.wan_mode LIKE ? OR onus.onu_type LIKE ? OR onus.last_down_cause LIKE ? OR onus.splitter LIKE ? OR onus.zone LIKE ? OR onus.download_profile LIKE ? OR onus.upload_profile LIKE ? OR onus.config_preset LIKE ? OR onus.mgmt_ip LIKE ? OR onus.odb_port LIKE ? OR onus.pppoe_ip LIKE ?)";
    for ($i = 0; $i < 23; $i++) $params[] = "%{$search}%";
}
if ($selected_olt_id !== '') {
    $filter_sql .= " AND onus.olt_id = ?";
    $params[] = $selected_olt_id;
} else {
    $filter_sql .= " AND onus.olt_id IN ($allowed_ids_str)";
}
if ($filter_zone !== '') {
    if (strtolower($filter_zone) === 'none') {
        $filter_sql .= " AND (onus.zone IS NULL OR onus.zone = '' OR onus.zone = 'None')";
    } else {
        $filter_sql .= " AND onus.zone = ?";
        $params[] = $filter_zone;
    }
}
if ($filter_odb !== '') {
    if (strtolower($filter_odb) === 'none') {
        $filter_sql .= " AND (onus.splitter IS NULL OR onus.splitter = '' OR onus.splitter = 'None')";
    } else {
        $filter_sql .= " AND onus.splitter = ?";
        $params[] = $filter_odb;
    }
}
if ($filter_pon !== '') {
    $filter_sql .= " AND onus.pon_port = ?";
    $params[] = $filter_pon;
}
if ($filter_signal !== '') {
    // Signal quality filter: critical (< -30), weak (-30 to -28), fair (-28 to -25), good (> -25)
    $signal_ranges = [
        'critical' => ['max' => -30],
        'weak'     => ['min' => -30, 'max' => -28],
        'fair'     => ['min' => -28, 'max' => -25],
        'good'     => ['min' => -25],
    ];
    if (isset($signal_ranges[$filter_signal])) {
        $range = $signal_ranges[$filter_signal];
        if (isset($range['min']) && isset($range['max'])) {
            $filter_sql .= " AND onus.last_rx_power IS NOT NULL AND onus.last_rx_power >= ? AND onus.last_rx_power < ?";
            $params[] = $range['min'];
            $params[] = $range['max'];
        } elseif (isset($range['max'])) {
            $filter_sql .= " AND onus.last_rx_power IS NOT NULL AND onus.last_rx_power < ?";
            $params[] = $range['max'];
        } else {
            $filter_sql .= " AND onus.last_rx_power IS NOT NULL AND onus.last_rx_power >= ?";
            $params[] = $range['min'];
        }
    }
}
if ($filter_type !== '') {
    $filter_sql .= " AND onus.onu_type = ?";
    $params[] = $filter_type;
}
// Simpan filter TANPA kondisi status untuk stats summary (Online/Offline/Disabled count).
// Harus diambil SETELAH zone/odb/pon/signal filter tapi SEBELUM status filter.
$stats_filter_sql = $filter_sql;
$stats_params = $params;

if ($status !== '') {
    $filter_sql .= " AND onus.status = ?";
    $params[] = $status;
}

// Eksekusi count query
$stmt_count = $pdo->prepare($sql_count . $filter_sql);
$stmt_count->execute($params);
$total_items = (int)$stmt_count->fetchColumn();

// Stats summary: count by status — $stats_filter_sql sudah disimpan SEBELUM status filter
// ditambahkan (di atas), jadi stats menunjukkan semua status meski user filter by status.
$stmt_stats = $pdo->prepare("SELECT onus.status, COUNT(*) as cnt FROM onus JOIN olts ON onus.olt_id = olts.id WHERE 1=1" . $stats_filter_sql . " GROUP BY onus.status");
$stmt_stats->execute($stats_params);
$stats_map = ['online' => 0, 'offline' => 0, 'disabled' => 0];
foreach ($stmt_stats->fetchAll() as $sr) { $stats_map[$sr['status']] = (int)$sr['cnt']; }
$stats_total = array_sum($stats_map);

$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($current_page < 1) $current_page = 1;
$total_pages = ceil($total_items / $limit);
if ($total_pages < 1) $total_pages = 1;
if ($current_page > $total_pages) $current_page = $total_pages;

$offset = ($current_page - 1) * $limit;

// Query data terpaginasi (hanya kolom yang diperlukan, tidak memuat last_status_cli / last_running_config)
$sql_data = "SELECT onus.id, onus.olt_id, onus.pon_port, onus.onu_id, onus.name, onus.serial_number, 
                    onus.vlan, onus.status, onus.last_rx_power, onus.last_rx_olt_power, onus.updated_at, onus.last_down_cause,
                    onus.wan_mode, onus.onu_type, onus.zone, onus.splitter,
                    onus.download_profile, onus.upload_profile,
                    olts.name as olt_name, olts.ip as olt_ip 
             FROM onus 
             JOIN olts ON onus.olt_id = olts.id 
             WHERE 1=1" . $filter_sql . " 
             ORDER BY onus.id DESC 
             LIMIT " . (int)$limit . " OFFSET " . (int)$offset;

$stmt_data = $pdo->prepare($sql_data);
$stmt_data->execute($params);
$onus = $stmt_data->fetchAll();
?>

<div class="filter-panel">
    <form method="GET" id="form-filter" action="configured.php" class="filter-row" style="margin:0;">
        <input type="hidden" name="limit" id="filter-limit" value="<?php echo $limit; ?>">
        <div class="filter-item search-item">
            <input type="text" name="search" class="form-control" placeholder="Cari nama, SN, alamat, PPPoE, OLT, VLAN, splitter, zone..." value="<?php echo htmlspecialchars($search); ?>">
        </div>
        <div class="filter-item">
            <select name="olt_id" class="form-control" onchange="this.closest('form').querySelectorAll('[name=zone],[name=odb],[name=pon_port]').forEach(i=>i.value='');this.closest('form').submit();">
                <option value="" <?php echo $selected_olt_id === '' ? 'selected' : ''; ?>>Semua OLT</option>
                <?php foreach ($olts as $olt): ?>
                    <option value="<?php echo (int)$olt['id']; ?>" <?php echo ($selected_olt_id !== '' && $selected_olt_id == $olt['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($olt['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-item">
            <select name="status" class="form-control" onchange="this.form.submit()">
                <option value="">Semua Status</option>
                <option value="online" <?php echo $status === 'online' ? 'selected' : ''; ?>>Online</option>
                <option value="offline" <?php echo $status === 'offline' ? 'selected' : ''; ?>>Offline</option>
                <option value="disabled" <?php echo $status === 'disabled' ? 'selected' : ''; ?>>Disabled</option>
            </select>
        </div>
        <div class="filter-item">
            <select name="zone" class="form-control" onchange="this.form.submit()">
                <option value="">Semua Zone</option>
                <option value="None" <?php echo $filter_zone === 'None' ? 'selected' : ''; ?>>None (belum diisi)</option>
                <?php foreach ($available_zones as $z): ?>
                    <option value="<?php echo htmlspecialchars($z); ?>" <?php echo $filter_zone === $z ? 'selected' : ''; ?>><?php echo htmlspecialchars($z); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-item">
            <select name="odb" class="form-control" onchange="this.form.submit()">
                <option value="">Semua ODB</option>
                <option value="None" <?php echo $filter_odb === 'None' ? 'selected' : ''; ?>>None (belum diisi)</option>
                <?php foreach ($available_odbs as $o): ?>
                    <option value="<?php echo htmlspecialchars($o); ?>" <?php echo $filter_odb === $o ? 'selected' : ''; ?>><?php echo htmlspecialchars($o); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-item">
            <select name="pon_port" class="form-control" onchange="this.form.submit()">
                <option value="">Semua PON</option>
                <?php foreach ($available_pons as $p): ?>
                    <option value="<?php echo htmlspecialchars($p); ?>" <?php echo $filter_pon === $p ? 'selected' : ''; ?>><?php echo htmlspecialchars($p); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="filter-item">
            <select name="signal" class="form-control" onchange="this.form.submit()">
                <option value="">Semua Sinyal</option>
                <option value="critical" <?php echo $filter_signal === 'critical' ? 'selected' : ''; ?>>Sinyal Kritis (&lt; -30 dBm)</option>
                <option value="weak" <?php echo $filter_signal === 'weak' ? 'selected' : ''; ?>>Sinyal Lemah (-30 s/d -28)</option>
                <option value="fair" <?php echo $filter_signal === 'fair' ? 'selected' : ''; ?>>Sinyal Sedang (-28 s/d -25)</option>
                <option value="good" <?php echo $filter_signal === 'good' ? 'selected' : ''; ?>>Sinyal Bagus (&gt; -25 dBm)</option>
            </select>
        </div>
        <div class="filter-item">
            <select name="onu_type" class="form-control" onchange="this.form.submit()">
                <option value="">Semua Tipe</option>
                <?php foreach ($available_types as $t): ?>
                    <option value="<?php echo htmlspecialchars($t); ?>" <?php echo $filter_type === $t ? 'selected' : ''; ?>><?php echo htmlspecialchars($t); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="filter-item">
            <button type="submit" class="btn btn-primary btn-block">
                <i data-lucide="filter"></i> Filter
            </button>
        </div>
        <?php if (!empty($filter_zone) || !empty($filter_odb) || !empty($filter_pon) || !empty($search) || !empty($status) || !empty($filter_signal) || !empty($filter_type) || $limit !== 100): ?>
        <div class="filter-item">
            <a href="configured.php?olt_id=<?php echo htmlspecialchars($selected_olt_id); ?>" class="btn btn-outline" style="text-decoration:none;">
                <i data-lucide="x"></i> Reset
            </a>
        </div>
        <?php endif; ?>
        <div class="filter-item">
            <a href="action/export-csv.php?<?php echo http_build_query(array_filter([
                'olt_id' => $selected_olt_id ?: null,
                'search' => $search ?: null,
                'status' => $status ?: null,
                'zone' => $filter_zone ?: null,
                'odb' => $filter_odb ?: null,
                'pon_port' => $filter_pon ?: null,
                'signal' => $filter_signal ?: null,
                'onu_type' => $filter_type ?: null,
            ], fn($v) => $v !== null && $v !== '')); ?>" class="btn btn-outline" style="text-decoration:none;" title="Download CSV dengan filter saat ini">
                <i data-lucide="download"></i> CSV
            </a>
        </div>
        <?php if (!empty($selected_olt_id)): ?>
        <div class="filter-item" style="display:flex; align-items:center; gap:6px;">
            <button type="button" class="btn btn-xs btn-warning" id="btn-sync-pelanggan" onclick="triggerSyncPelanggan(event)">
                <i data-lucide="refresh-cw" style="width:12px; height:12px; display:inline-block; vertical-align:middle;"></i> Sync Pelanggan
            </button>
            <label style="display:flex; align-items:center; gap:4px; font-size:0.75rem; color:var(--text-muted); cursor:pointer; user-select:none;" title="Tampilkan detail error saat sync gagal">
                <input type="checkbox" id="toggle-debug" style="width:13px; height:13px; cursor:pointer;">
                Debug
            </label>
        </div>
        <?php endif; ?>
    </form>
</div>

<div style="display:flex; gap:16px; flex-wrap:wrap; margin-bottom:16px;">
    <div style="display:flex; align-items:center; gap:8px; background:var(--bg-secondary); border:1px solid var(--border-color); border-radius:8px; padding:10px 16px;">
        <span style="font-size:0.85rem; color:var(--text-muted);">Total</span>
        <span id="stat-total" style="font-size:1.25rem; font-weight:700; color:var(--text-main);"><?php echo number_format($stats_total); ?></span>
    </div>
    <div style="display:flex; align-items:center; gap:8px; background:var(--bg-secondary); border:1px solid var(--border-color); border-radius:8px; padding:10px 16px;">
        <span style="width:8px;height:8px;border-radius:50%;background:var(--color-success);display:inline-block;"></span>
        <span style="font-size:0.85rem; color:var(--text-muted);">Online</span>
        <span id="stat-online" style="font-size:1.25rem; font-weight:700; color:var(--color-success);"><?php echo number_format($stats_map['online']); ?></span>
    </div>
    <div style="display:flex; align-items:center; gap:8px; background:var(--bg-secondary); border:1px solid var(--border-color); border-radius:8px; padding:10px 16px;">
        <span style="width:8px;height:8px;border-radius:50%;background:var(--color-danger);display:inline-block;"></span>
        <span style="font-size:0.85rem; color:var(--text-muted);">Offline</span>
        <span id="stat-offline" style="font-size:1.25rem; font-weight:700; color:var(--color-danger);"><?php echo number_format($stats_map['offline']); ?></span>
    </div>
    <div style="display:flex; align-items:center; gap:8px; background:var(--bg-secondary); border:1px solid var(--border-color); border-radius:8px; padding:10px 16px;">
        <span style="width:8px;height:8px;border-radius:50%;background:var(--color-warning);display:inline-block;"></span>
        <span style="font-size:0.85rem; color:var(--text-muted);">Disabled</span>
        <span id="stat-disabled" style="font-size:1.25rem; font-weight:700; color:var(--color-warning);"><?php echo number_format($stats_map['disabled']); ?></span>
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


        // Asynchronously update optical power (redaman) from the local MySQL DB cache
        async function updateAllOnuSignals() {
            const oltId = "<?php echo htmlspecialchars($selected_olt_id); ?>";

            try {
                const response = await fetch(`action/get-signals-db.php?olt_id=${oltId}`);
                if (!response.ok) throw new Error('Failed to fetch cached signals');
                const data = await response.json();

                if (data.success && data.onus) {
                    const onuMap = new Map();
                    data.onus.forEach(onu => {
                        onuMap.set(onu.id, onu);
                    });

                    let domChanged = false;
                    const rows = document.querySelectorAll('.onu-row');
                    rows.forEach(row => {
                        const onuId = parseInt(row.getAttribute('data-onu-id'));
                        const onu = onuMap.get(onuId);
                        if (!onu) return;

                        // Skip update if nothing changed for this ONU
                        if (row.getAttribute('data-name') === (onu.name || '') &&
                            row.getAttribute('data-status') === onu.status &&
                            row.getAttribute('data-vlan') === String(onu.vlan || 'None') &&
                            row.getAttribute('data-rx-onu') === String(onu.rx_onu) &&
                            row.getAttribute('data-rx-olt') === String(onu.rx_olt) &&
                            row.getAttribute('data-down-cause') === String(onu.last_down_cause || '-') &&
                            row.getAttribute('data-wan') === String(onu.wan_mode || '') &&
                            row.getAttribute('data-dl-prof') === String(onu.download_profile || '') &&
                            row.getAttribute('data-ul-prof') === String(onu.upload_profile || '') &&
                            row.getAttribute('data-onu-type') === String(onu.onu_type || '')) {
                            return;
                        }
                        domChanged = true;

                        // Update status badge
                        const statusCell = row.querySelector('.status-cell');
                        if (statusCell) {
                            if (onu.status === 'online') {
                                statusCell.innerHTML = `<span class="badge bg-green"><i data-lucide="globe" style="width:12px;height:12px;display:inline-block;vertical-align:middle;margin-right:4px;"></i> Online</span>`;
                            } else if (onu.status === 'disabled') {
                                statusCell.innerHTML = `<span class="badge bg-yellow"><i data-lucide="shield-off" style="width:12px;height:12px;display:inline-block;vertical-align:middle;margin-right:4px;"></i> Disabled</span>`;
                            } else {
                                statusCell.innerHTML = `<span class="badge bg-red"><i data-lucide="plug" style="width:12px;height:12px;display:inline-block;vertical-align:middle;margin-right:4px;"></i> Offline</span>`;
                            }
                        }
                        row.setAttribute('data-status', onu.status);
                        row.setAttribute('data-name', onu.name || '');

                        // Update customer name
                        const nameCell = row.querySelector('.name-cell');
                        if (nameCell) {
                            nameCell.innerHTML = `<strong>${esc(onu.customer_name || '-')}</strong>`;
                        }

                        row.setAttribute('data-vlan', onu.vlan || 'None');
                        row.setAttribute('data-rx-onu', onu.rx_onu);
                        row.setAttribute('data-rx-olt', onu.rx_olt);
                        row.setAttribute('data-down-cause', onu.last_down_cause || '-');

                        // Update VLAN badge
                        const vlanCell = row.querySelector('.vlan-cell');
                        if (vlanCell) {
                            const vlanVal = onu.vlan || '-';
                            vlanCell.innerHTML = `<span class="badge bg-green">${esc(vlanVal)}</span>`;
                        }

                        // Update signal badge (Rx ONU)
                        const signalCell = row.querySelector('.signal-cell');
                        if (signalCell) {
                            if (onu.status === 'online') {
                                const rx = parseFloat(onu.rx_onu);
                                if (isNaN(rx)) {
                                    signalCell.innerHTML = `<span class="badge bg-red">N/A</span>`;
                                } else {
                                    let sigClass = 'bg-red';
                                    if (rx >= -25) sigClass = 'bg-green';
                                    else if (rx >= -28) sigClass = 'bg-yellow';
                                    else if (rx >= -30) sigClass = 'bg-orange';
                                    signalCell.innerHTML = `<span class="badge ${sigClass}">${esc(onu.rx_onu)} dBm</span>`;
                                }
                            } else {
                                signalCell.innerHTML = `<span class="badge bg-gray">Offline</span>`;
                            }
                        }

                        // Update signal badge (Rx OLT)
                        const signalOltCell = row.querySelector('.signal-olt-cell');
                        if (signalOltCell) {
                            if (onu.status === 'online') {
                                const rxOlt = parseFloat(onu.rx_olt);
                                if (isNaN(rxOlt)) {
                                    signalOltCell.innerHTML = `<span class="badge bg-red">N/A</span>`;
                                } else {
                                    let sigClassOlt = 'bg-red';
                                    if (rxOlt >= -25) sigClassOlt = 'bg-green';
                                    else if (rxOlt >= -28) sigClassOlt = 'bg-yellow';
                                    else if (rxOlt >= -30) sigClassOlt = 'bg-orange';
                                    signalOltCell.innerHTML = `<span class="badge ${sigClassOlt}">${esc(onu.rx_olt)} dBm</span>`;
                                }
                            } else {
                                signalOltCell.innerHTML = `<span class="badge bg-gray">Offline</span>`;
                            }
                        }

                        // Update Last Down
                        const updatedCell = row.querySelector('.updated-cell');
                        if (updatedCell) {
                            updatedCell.textContent = onu.last_down_cause || '-';
                        }

                        // Update WAN mode badge
                        const wanCell = row.querySelector('.wan-cell');
                        if (wanCell) {
                            const wanDisplay = onu.wan_mode === 'Static' ? 'Static IP' : (onu.wan_mode || '-');
                            wanCell.innerHTML = `<span class="badge bg-blue">${esc(wanDisplay)}</span>`;
                        }
                        // Update ONU Type
                        const typeCell = row.querySelector('.onu-type-cell');
                        if (typeCell) {
                            typeCell.textContent = onu.onu_type || '-';
                        }

                        // Update data-attributes for next comparison
                        row.setAttribute('data-wan', onu.wan_mode || '');
                        row.setAttribute('data-onu-type', onu.onu_type || '');
                        row.setAttribute('data-dl-prof', onu.download_profile || '');
                        row.setAttribute('data-ul-prof', onu.upload_profile || '');
                    });

                    // Re-initialize Lucide icons only if DOM was actually modified
                    if (domChanged && typeof lucide !== 'undefined') {
                        lucide.createIcons();
                    }

                    // Update stats cards from full ONU dataset (only when no extra filters active)
                    // get-signals-db.php returns full OLT data, not filtered subset
                    const hasExtraFilters = <?php echo ($search !== '' || !empty($filter_zone) || !empty($filter_odb) || !empty($filter_pon) || !empty($filter_signal) || !empty($filter_type) || $status !== '') ? 'true' : 'false'; ?>;
                    if (!hasExtraFilters) {
                        const statusCounts = { online: 0, offline: 0, disabled: 0 };
                        data.onus.forEach(o => {
                            if (statusCounts[o.status] !== undefined) statusCounts[o.status]++;
                        });
                        const statTotal = document.getElementById('stat-total');
                        const statOnline = document.getElementById('stat-online');
                        const statOffline = document.getElementById('stat-offline');
                        const statDisabled = document.getElementById('stat-disabled');
                        if (statTotal) statTotal.textContent = data.onus.length.toLocaleString('en-US');
                        if (statOnline) statOnline.textContent = statusCounts.online.toLocaleString('en-US');
                        if (statOffline) statOffline.textContent = statusCounts.offline.toLocaleString('en-US');
                        if (statDisabled) statDisabled.textContent = statusCounts.disabled.toLocaleString('en-US');
                    }
                }
            } catch (err) {
                console.error("[SmartOLT] Error updating signals from local DB:", err);
            }
        }

        // Run immediately on page load
        updateAllOnuSignals();

        // Run every 60 seconds (1 minute)
        setInterval(updateAllOnuSignals, 60000);

        // Bind limit selection dropdown in card-header to main filter form
        const headerLimitSelect = document.getElementById('header-limit-select');
        if (headerLimitSelect) {
            headerLimitSelect.addEventListener('change', (e) => {
                const filterLimitInput = document.getElementById('filter-limit');
                if (filterLimitInput) {
                    filterLimitInput.value = e.target.value;
                }
                const filterForm = document.getElementById('form-filter');
                if (filterForm) {
                    filterForm.submit();
                }
            });
        }
    });

    function showToast(message, type = 'info', duration = 5000) {
        const container = document.getElementById('toast-container');
        if (!container) {
            const c = document.createElement('div');
            c.id = 'toast-container';
            c.style.cssText = 'position: fixed; bottom: 20px; right: 20px; z-index: 9999; display: flex; flex-direction: column; gap: 10px; pointer-events: none;';
            document.body.appendChild(c);
        }
        
        const toast = document.createElement('div');
        toast.style.cssText = `
            padding: 12px 20px;
            border-radius: 8px;
            color: #fff;
            font-size: 0.875rem;
            font-weight: 500;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
            display: flex;
            align-items: center;
            gap: 10px;
            pointer-events: auto;
            animation: slideIn 0.3s ease forwards;
            max-width: 350px;
        `;
        
        if (!document.getElementById('toast-animation-style')) {
            const style = document.createElement('style');
            style.id = 'toast-animation-style';
            style.innerHTML = `
                @keyframes slideIn {
                    from { transform: translateX(120%); opacity: 0; }
                    to { transform: translateX(0); opacity: 1; }
                }
                @keyframes fadeOut {
                    from { opacity: 1; }
                    to { opacity: 0; }
                }
                @keyframes spin {
                    to { transform: rotate(360deg); }
                }
                .spinner-small {
                    width: 14px;
                    height: 14px;
                    border: 2px solid rgba(255, 255, 255, 0.3);
                    border-top-color: #fff;
                    border-radius: 50%;
                    animation: spin 0.8s linear infinite;
                    display: inline-block;
                }
            `;
            document.head.appendChild(style);
        }

        if (type === 'info') {
            toast.style.background = 'var(--bg-tertiary, #1e3a8a)';
            toast.style.border = '1px solid var(--text-accent, #3b82f6)';
            toast.style.color = 'var(--text-main, #fff)';
            toast.innerHTML = `<span class="spinner-small"></span> <span>${esc(message)}</span>`;
        } else if (type === 'success') {
            toast.style.background = 'var(--bg-tertiary, #064e3b)';
            toast.style.border = '1px solid var(--color-success, #10b981)';
            toast.style.color = 'var(--text-main, #fff)';
            toast.innerHTML = `<span>${esc(message)}</span>`;
        } else {
            toast.style.background = 'var(--bg-tertiary, #7f1d1d)';
            toast.style.border = '1px solid var(--color-danger, #ef4444)';
            toast.style.color = 'var(--text-main, #fff)';
            toast.innerHTML = `<span>${esc(message)}</span>`;
        }
        
        document.getElementById('toast-container').appendChild(toast);
        
        if (duration > 0) {
            setTimeout(() => {
                toast.style.animation = 'fadeOut 0.5s ease forwards';
                setTimeout(() => toast.remove(), 500);
            }, duration);
        }
        
        return toast;
    }

    async function triggerSyncPelanggan(event) {
        event.preventDefault();
        const oltId = "<?php echo htmlspecialchars($selected_olt_id); ?>";
        if (!oltId) return;
        
        const btn = document.getElementById('btn-sync-pelanggan');
        if (btn) btn.disabled = true;

        const debugMode = <?php echo !empty($_SESSION['debug_mode']) ? 'true' : 'false'; ?> || (document.getElementById('toggle-debug')?.checked || false);
        const toast = showToast('Sedang menyinkronkan data pelanggan dari OLT... Anda tetap bisa mengoperasikan aplikasi.', 'info', 0);

        try {
            const response = await fetch(`action/sync-olt.php?olt_id=${oltId}&ajax=1`, {
                method: 'POST'
            });

            const rawText = await response.text();
            toast.remove();

            if (!response.ok) {
                const detail = debugMode
                    ? `HTTP ${response.status} ${response.statusText}\n\n${rawText.substring(0, 500)}`
                    : `Server mengembalikan HTTP ${response.status}. Aktifkan Debug Mode untuk melihat detail.`;
                showToast(detail, 'error', debugMode ? 15000 : 6000);
                if (btn) btn.disabled = false;
                return;
            }

            let data;
            try {
                data = JSON.parse(rawText);
            } catch (parseErr) {
                const detail = debugMode
                    ? `Gagal parsing JSON response:\n${rawText.substring(0, 500)}`
                    : 'Response server tidak valid. Aktifkan Debug Mode untuk melihat detail.';
                showToast(detail, 'error', debugMode ? 15000 : 6000);
                if (btn) btn.disabled = false;
                return;
            }

            if (data.success) {
                showToast(data.message || 'Sinkronisasi berhasil!', 'success', 4000);
                setTimeout(() => { window.location.reload(); }, 1500);
            } else {
                showToast(data.message || 'Gagal menyinkronkan data.', 'error', 6000);
            }
        } catch (err) {
            toast.remove();
            const detail = debugMode
                ? `Network/Fetch error:\n${err.message}\n\nKemungkinan: PHP timeout, koneksi terputus, atau server crash.`
                : 'Terjadi kesalahan jaringan saat melakukan sinkronisasi.';
            showToast(detail, 'error', debugMode ? 15000 : 6000);
        } finally {
            if (btn) btn.disabled = false;
        }
    }
</script>

<div class="content-card">
    <div class="card-header border-accent" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; padding: 12px 20px;">
        <h2>Daftar Pelanggan Terdaftar (Configured)</h2>
        <div style="display:flex; align-items:center; gap:8px;">
            <label for="header-limit-select" style="margin:0;font-weight:600;font-size:0.85rem;color:var(--text-muted);">Tampilkan:</label>
            <select id="header-limit-select" class="form-control" style="width:105px; padding:4px 8px; font-size:0.85rem; height:32px; border-radius:4px; background-color:var(--bg-tertiary); border:1px solid var(--border-color); color:var(--text-main); font-weight:600; cursor:pointer;">
                <option value="20" <?php echo $limit === 20 ? 'selected' : ''; ?>>20 / hal</option>
                <option value="50" <?php echo $limit === 50 ? 'selected' : ''; ?>>50 / hal</option>
                <option value="100" <?php echo $limit === 100 ? 'selected' : ''; ?>>100 / hal</option>
                <option value="200" <?php echo $limit === 200 ? 'selected' : ''; ?>>200 / hal</option>
            </select>
        </div>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Status</th>
                        <th>Nama Pelanggan</th>
                        <th>Serial Number</th>
                        <th>Koneksi OLT & PON</th>
                        <th>VLAN</th>
                        <th>WAN</th>
                        <th>Sinyal Rx ONU</th>
                        <th>Sinyal Rx OLT</th>
                        <th>Last Down</th>
                        <th>Tipe ONU</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($onus)): ?>
                        <tr>
                            <td colspan="11" style="text-align:center;color:var(--text-muted);padding:16px;">Tidak ada ONU terdaftar yang sesuai dengan kriteria filter.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($onus as $onu): ?>
                            <tr class="onu-row" data-onu-id="<?php echo (int)$onu['id']; ?>" data-name="<?php echo htmlspecialchars($onu['name'] ?? ''); ?>" data-status="<?php echo htmlspecialchars($onu['status']); ?>" data-vlan="<?php echo htmlspecialchars($onu['vlan'] ?: 'None'); ?>" data-rx-onu="<?php echo $onu['last_rx_power'] !== null ? number_format((float)$onu['last_rx_power'], 2) : 'N/A'; ?>" data-rx-olt="<?php echo $onu['last_rx_olt_power'] !== null ? number_format((float)$onu['last_rx_olt_power'], 2) : 'N/A'; ?>" data-down-cause="<?php echo htmlspecialchars($onu['last_down_cause'] ?? '-'); ?>" data-wan="<?php echo htmlspecialchars($onu['wan_mode'] ?? ''); ?>" data-onu-type="<?php echo htmlspecialchars($onu['onu_type'] ?? ''); ?>" data-dl-prof="<?php echo htmlspecialchars($onu['download_profile'] ?? ''); ?>" data-ul-prof="<?php echo htmlspecialchars($onu['upload_profile'] ?? ''); ?>">
                                <td class="status-cell">
                                    <?php if ($onu['status'] === 'online'): ?>
                                        <span class="badge bg-green"><i data-lucide="globe" style="width:12px;height:12px;display:inline-block;vertical-align:middle;margin-right:4px;"></i> Online</span>
                                    <?php elseif ($onu['status'] === 'disabled'): ?>
                                        <span class="badge bg-yellow"><i data-lucide="shield-off" style="width:12px;height:12px;display:inline-block;vertical-align:middle;margin-right:4px;"></i> Disabled</span>
                                    <?php else: ?>
                                        <span class="badge bg-red"><i data-lucide="plug" style="width:12px;height:12px;display:inline-block;vertical-align:middle;margin-right:4px;"></i> Offline</span>
                                    <?php endif; ?>
                                </td>
                                <td class="name-cell"><strong><?php echo htmlspecialchars(extract_customer_name($onu['name'])); ?></strong></td>
                                <td><code><?php echo htmlspecialchars($onu['serial_number']); ?></code></td>
                                <td>
                                    <?php echo htmlspecialchars($onu['olt_name']); ?><br>
                                    PON <?php echo htmlspecialchars($onu['pon_port'] . ':' . $onu['onu_id']); ?>
                                </td>
                                <td class="vlan-cell"><span class="badge bg-green"><?php echo htmlspecialchars($onu['vlan'] ?: '-'); ?></span></td>
                                <td class="wan-cell"><span class="badge bg-blue"><?php echo htmlspecialchars($onu['wan_mode'] === 'Static' ? 'Static IP' : ($onu['wan_mode'] ?: '-')); ?></span></td>
                                <td class="signal-cell">
                                    <?php if ($onu['status'] === 'online' && $onu['last_rx_power'] !== null): ?>
                                        <?php 
                                        $rx = (float)$onu['last_rx_power'];
                                        $sig_class = 'bg-red';
                                        if ($rx >= -25) $sig_class = 'bg-green';
                                        else if ($rx >= -28) $sig_class = 'bg-yellow';
                                        else if ($rx >= -30) $sig_class = 'bg-orange';
                                        ?>
                                        <span class="badge <?php echo $sig_class; ?>"><?php echo htmlspecialchars($onu['last_rx_power']); ?> dBm</span>
                                    <?php elseif ($onu['status'] === 'online'): ?>
                                        <span class="badge bg-red">N/A</span>
                                    <?php else: ?>
                                        <span class="badge bg-gray">Offline</span>
                                    <?php endif; ?>
                                </td>
                                <td class="signal-olt-cell">
                                    <?php if ($onu['status'] === 'online' && $onu['last_rx_olt_power'] !== null): ?>
                                        <?php 
                                        $rx_olt = (float)$onu['last_rx_olt_power'];
                                        $sig_class_olt = 'bg-red';
                                        if ($rx_olt >= -25) $sig_class_olt = 'bg-green';
                                        else if ($rx_olt >= -28) $sig_class_olt = 'bg-yellow';
                                        else if ($rx_olt >= -30) $sig_class_olt = 'bg-orange';
                                        ?>
                                        <span class="badge <?php echo $sig_class_olt; ?>"><?php echo htmlspecialchars($onu['last_rx_olt_power']); ?> dBm</span>
                                    <?php elseif ($onu['status'] === 'online'): ?>
                                        <span class="badge bg-red">N/A</span>
                                    <?php else: ?>
                                        <span class="badge bg-gray">Offline</span>
                                    <?php endif; ?>
                                </td>
                                <td class="updated-cell"><?php echo $onu['last_down_cause'] ? htmlspecialchars($onu['last_down_cause']) : '-'; ?></td>
                                <td class="onu-type-cell" style="font-size:0.8rem;color:var(--text-muted);"><?php echo htmlspecialchars($onu['onu_type'] ?: '-'); ?></td>
                                <td>
                                    <a href="onu-detail.php?id=<?php echo (int)$onu['id']; ?>" class="btn btn-xs btn-primary">
                                        <i data-lucide="eye"></i> Detail
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($total_pages > 1): ?>
            <?php
            $link_params = [
                'olt_id' => $selected_olt_id,
                'search' => $search,
                'status' => $status,
                'zone' => $filter_zone,
                'odb' => $filter_odb,
                'pon_port' => $filter_pon,
                'signal' => $filter_signal,
                'onu_type' => $filter_type,
                'limit' => $limit
            ];
            $base_query = http_build_query($link_params);
            ?>
            <div class="pagination-bar" style="margin-top:20px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
                <div style="font-size:0.85rem; color:var(--text-muted);">
                    Menampilkan <?php echo ($offset + 1); ?> - <?php echo min($offset + $limit, $total_items); ?> dari <?php echo $total_items; ?> pelanggan
                </div>
                <div class="pagination-buttons" style="display:flex; gap:6px; align-items:center;">
                    <!-- First Page Link (<<) -->
                    <?php if ($current_page > 1): ?>
                        <a href="configured.php?<?php echo $base_query; ?>&page=1" class="btn btn-xs btn-outline" style="text-decoration:none;" title="Halaman Pertama"><<</a>
                    <?php else: ?>
                        <span class="btn btn-xs btn-outline disabled" style="opacity:0.5; cursor:not-allowed;"><<</span>
                    <?php endif; ?>

                    <!-- Prev Page Link (<) -->
                    <?php if ($current_page > 1): ?>
                        <a href="configured.php?<?php echo $base_query; ?>&page=<?php echo ($current_page - 1); ?>" class="btn btn-xs btn-outline" style="text-decoration:none;" title="Halaman Sebelumnya"><</a>
                    <?php else: ?>
                        <span class="btn btn-xs btn-outline disabled" style="opacity:0.5; cursor:not-allowed;"><</span>
                    <?php endif; ?>

                    <!-- Page Numbers (Sliding Window of 5) -->
                    <?php
                    $window_size = 5;
                    $start = $current_page - 2;
                    $end = $current_page + 2;

                    if ($start < 1) {
                        $start = 1;
                        $end = min($total_pages, $window_size);
                    }
                    if ($end > $total_pages) {
                        $end = $total_pages;
                        $start = max(1, $total_pages - $window_size + 1);
                    }

                    for ($i = $start; $i <= $end; $i++) {
                        $active_class = ($i === $current_page) ? 'btn-primary' : 'btn-outline';
                        echo '<a href="configured.php?' . $base_query . '&page=' . $i . '" class="btn btn-xs ' . $active_class . '" style="text-decoration:none;">' . $i . '</a>';
                    }
                    ?>

                    <!-- Next Page Link (>) -->
                    <?php if ($current_page < $total_pages): ?>
                        <a href="configured.php?<?php echo $base_query; ?>&page=<?php echo ($current_page + 1); ?>" class="btn btn-xs btn-outline" style="text-decoration:none;" title="Halaman Selanjutnya">></a>
                    <?php else: ?>
                        <span class="btn btn-xs btn-outline disabled" style="opacity:0.5; cursor:not-allowed;">></span>
                    <?php endif; ?>

                    <!-- Last Page Link (>>) -->
                    <?php if ($current_page < $total_pages): ?>
                        <a href="configured.php?<?php echo $base_query; ?>&page=<?php echo $total_pages; ?>" class="btn btn-xs btn-outline" style="text-decoration:none;" title="Halaman Terakhir">>></a>
                    <?php else: ?>
                        <span class="btn btn-xs btn-outline disabled" style="opacity:0.5; cursor:not-allowed;">>></span>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
