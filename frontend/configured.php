<?php
// ==============================================================================
# SmartOLT Configured ONUs Page
// ==============================================================================
require_once __DIR__ . '/header.php';

// Ambil OLT yang diizinkan untuk dropdown filter
$olts = get_allowed_olts();
$allowed_ids = array_map(function($o) { return (int)$o['id']; }, $olts);
$allowed_ids_str = implode(',', $allowed_ids) ?: '0';

$olt_ids_raw = $_GET['olt_id'] ?? [];
if (!is_array($olt_ids_raw)) $olt_ids_raw = $olt_id_raw !== '' ? [(int)$olt_id_raw] : [];
$selected_olts = [];
foreach ($olt_ids_raw as $oid) {
    $oid = (int)$oid;
    if ($oid > 0 && has_olt_access($oid)) $selected_olts[] = $oid;
}
$selected_olts = array_values(array_unique($selected_olts));
$selected_olt_id = count($selected_olts) === 1 ? $selected_olts[0] : '';
// Semua filter non-search diperlakukan sebagai array (checkbox multi-select),
// menerima input legacy berupa string tunggal (dibungkus jadi array 1 elemen).
function ms_param($key) {
    $v = $_GET[$key] ?? '';
    if (!is_array($v)) $v = $v !== '' ? [$v] : [];
    return array_values(array_filter($v, fn($x) => $x !== ''));
}
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status = ms_param('status');
$filter_zone = ms_param('zone');
$filter_odb  = ms_param('odb');
$filter_board   = ms_param('board');
$filter_port    = ms_param('port');
$filter_signal  = ms_param('signal');
$filter_type    = ms_param('onu_type');
$filter_vlan    = ms_param('vlan');
$filter_profile = ms_param('profile');

// Catatan: Sinkronisasi otomatis OLT kini dijalankan di latar belakang oleh cron job (backend/python_engine/cron_sync.py).
// Halaman ini memuat data secara instan dari database lokal MySQL.

// Ambil daftar Zone, ODB, & PON unik dari database untuk dropdown
if (!empty($selected_olts)) {
    $olt_filter_in = implode(',', $selected_olts);
} else {
    $olt_filter_in = $allowed_ids_str;
}
// pon_port disimpan sebagai "rack/slot/port" (contoh "1/2/6", lihat
// backend/python_engine/snmp_module.py baris ~182). Board = "rack/slot" (2 segmen
// pertama), Port = segmen terakhir. Dipisah via SUBSTRING_INDEX di SQL.
$board_expr = "SUBSTRING_INDEX(pon_port, '/', 2)";
$port_expr  = "SUBSTRING_INDEX(pon_port, '/', -1)";

$available_zones    = $pdo->query("SELECT DISTINCT zone FROM onus WHERE olt_id IN ($olt_filter_in) AND zone IS NOT NULL AND zone != '' ORDER BY zone ASC")->fetchAll(PDO::FETCH_COLUMN);
$available_odbs     = $pdo->query("SELECT DISTINCT splitter FROM onus WHERE olt_id IN ($olt_filter_in) AND splitter IS NOT NULL AND splitter != '' ORDER BY splitter ASC")->fetchAll(PDO::FETCH_COLUMN);
$available_boards   = $pdo->query("SELECT DISTINCT $board_expr as b FROM onus WHERE olt_id IN ($olt_filter_in) AND pon_port IS NOT NULL AND pon_port != '' ORDER BY b ASC")->fetchAll(PDO::FETCH_COLUMN);
$available_ports    = $pdo->query("SELECT DISTINCT $port_expr as p FROM onus WHERE olt_id IN ($olt_filter_in) AND pon_port IS NOT NULL AND pon_port != '' ORDER BY CAST(p AS UNSIGNED) ASC")->fetchAll(PDO::FETCH_COLUMN);
$available_types    = $pdo->query("SELECT DISTINCT onu_type FROM onus WHERE onu_type IS NOT NULL AND onu_type != '' AND olt_id IN ($olt_filter_in) ORDER BY onu_type ASC")->fetchAll(PDO::FETCH_COLUMN);
$available_vlans    = $pdo->query("SELECT DISTINCT vlan FROM onus WHERE vlan IS NOT NULL AND vlan != '' AND olt_id IN ($olt_filter_in) ORDER BY vlan ASC")->fetchAll(PDO::FETCH_COLUMN);
$available_profiles = $pdo->query("SELECT DISTINCT download_profile FROM onus WHERE download_profile IS NOT NULL AND download_profile != '' AND olt_id IN ($olt_filter_in) ORDER BY download_profile ASC")->fetchAll(PDO::FETCH_COLUMN);

$olt_counts     = $pdo->query("SELECT olt_id, COUNT(*) as cnt FROM onus WHERE olt_id IN ($allowed_ids_str) GROUP BY olt_id")->fetchAll(PDO::FETCH_KEY_PAIR);
$odb_counts     = $pdo->query("SELECT COALESCE(NULLIF(splitter,''),'None') as s, COUNT(*) as cnt FROM onus WHERE olt_id IN ($olt_filter_in) GROUP BY s ORDER BY s ASC")->fetchAll(PDO::FETCH_KEY_PAIR);
$zone_counts    = $pdo->query("SELECT COALESCE(NULLIF(zone,''),'None') as z, COUNT(*) as cnt FROM onus WHERE olt_id IN ($olt_filter_in) GROUP BY z ORDER BY z ASC")->fetchAll(PDO::FETCH_KEY_PAIR);
$board_counts   = $pdo->query("SELECT $board_expr as b, COUNT(*) as cnt FROM onus WHERE olt_id IN ($olt_filter_in) AND pon_port IS NOT NULL AND pon_port != '' GROUP BY b")->fetchAll(PDO::FETCH_KEY_PAIR);
$port_counts    = $pdo->query("SELECT $port_expr as p, COUNT(*) as cnt FROM onus WHERE olt_id IN ($olt_filter_in) AND pon_port IS NOT NULL AND pon_port != '' GROUP BY p")->fetchAll(PDO::FETCH_KEY_PAIR);
$type_counts    = $pdo->query("SELECT onu_type as t, COUNT(*) as cnt FROM onus WHERE olt_id IN ($olt_filter_in) AND onu_type IS NOT NULL AND onu_type != '' GROUP BY t")->fetchAll(PDO::FETCH_KEY_PAIR);
$vlan_counts    = $pdo->query("SELECT vlan as v, COUNT(*) as cnt FROM onus WHERE olt_id IN ($olt_filter_in) AND vlan IS NOT NULL AND vlan != '' GROUP BY v")->fetchAll(PDO::FETCH_KEY_PAIR);
$profile_counts = $pdo->query("SELECT download_profile as p, COUNT(*) as cnt FROM onus WHERE olt_id IN ($olt_filter_in) AND download_profile IS NOT NULL AND download_profile != '' GROUP BY p")->fetchAll(PDO::FETCH_KEY_PAIR);

// Status: buckets "offline_power/fiber/other" derivatif dari status+last_down_cause,
// jadi dihitung manual di PHP (bukan GROUP BY langsung).
$status_raw_counts = $pdo->query("SELECT status, last_down_cause, COUNT(*) as cnt FROM onus WHERE olt_id IN ($olt_filter_in) GROUP BY status, last_down_cause")->fetchAll();
$status_counts = ['online' => 0, 'offline_power' => 0, 'offline_fiber' => 0, 'offline_other' => 0, 'offline' => 0, 'disabled' => 0];
$power_causes = ['Power Down', 'Manual'];
$fiber_causes = ['LOS', 'LOSi', 'LOFi', 'SFi', 'LOAi', 'LOAMi'];
foreach ($status_raw_counts as $sr) {
    $cnt = (int)$sr['cnt'];
    if ($sr['status'] === 'online') { $status_counts['online'] += $cnt; }
    elseif ($sr['status'] === 'disabled') { $status_counts['disabled'] += $cnt; }
    elseif ($sr['status'] === 'offline') {
        $status_counts['offline'] += $cnt;
        if (in_array($sr['last_down_cause'], $power_causes, true)) $status_counts['offline_power'] += $cnt;
        elseif (in_array($sr['last_down_cause'], $fiber_causes, true)) $status_counts['offline_fiber'] += $cnt;
        else $status_counts['offline_other'] += $cnt;
    }
}

// Signal: 4 bucket rentang dBm, dihitung via query terpisah per rentang (scoped olt_filter_in).
$signal_ranges = [
    'critical' => ['max' => -30],
    'weak'     => ['min' => -30, 'max' => -28],
    'fair'     => ['min' => -28, 'max' => -25],
    'good'     => ['min' => -25],
];
$signal_counts = [];
foreach ($signal_ranges as $key => $range) {
    $cond = "last_rx_power IS NOT NULL";
    $sparams = [];
    if (isset($range['min']) && isset($range['max'])) { $cond .= " AND last_rx_power >= ? AND last_rx_power < ?"; $sparams = [$range['min'], $range['max']]; }
    elseif (isset($range['max'])) { $cond .= " AND last_rx_power < ?"; $sparams = [$range['max']]; }
    else { $cond .= " AND last_rx_power >= ?"; $sparams = [$range['min']]; }
    $st = $pdo->prepare("SELECT COUNT(*) FROM onus WHERE olt_id IN ($olt_filter_in) AND $cond");
    $st->execute($sparams);
    $signal_counts[$key] = (int)$st->fetchColumn();
}

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
if (!empty($selected_olts)) {
    $ph = implode(',', array_fill(0, count($selected_olts), '?'));
    $filter_sql .= " AND onus.olt_id IN ($ph)";
    foreach ($selected_olts as $oid) $params[] = $oid;
} else {
    $filter_sql .= " AND onus.olt_id IN ($allowed_ids_str)";
}
// Helper generik: filter multi-select IN (...) dengan dukungan opsi "None" (NULL/kosong).
function apply_ms_filter(&$filter_sql, &$params, $col, $values) {
    if (empty($values)) return;
    $has_none = false;
    $vals = [];
    foreach ($values as $v) {
        if (strtolower($v) === 'none') $has_none = true;
        elseif ($v !== '') $vals[] = $v;
    }
    $parts = [];
    if (!empty($vals)) {
        $ph = implode(',', array_fill(0, count($vals), '?'));
        $parts[] = "$col IN ($ph)";
        foreach ($vals as $v) $params[] = $v;
    }
    if ($has_none) $parts[] = "($col IS NULL OR $col = '' OR $col = 'None')";
    if (!empty($parts)) $filter_sql .= " AND (" . implode(' OR ', $parts) . ")";
}

apply_ms_filter($filter_sql, $params, 'onus.zone', $filter_zone);
apply_ms_filter($filter_sql, $params, 'onus.splitter', $filter_odb);
apply_ms_filter($filter_sql, $params, $board_expr, $filter_board);
apply_ms_filter($filter_sql, $params, $port_expr, $filter_port);
apply_ms_filter($filter_sql, $params, 'onus.onu_type', $filter_type);
apply_ms_filter($filter_sql, $params, 'onus.vlan', $filter_vlan);
apply_ms_filter($filter_sql, $params, 'onus.download_profile', $filter_profile);

if (!empty($filter_signal)) {
    $sig_parts = [];
    foreach ($filter_signal as $key) {
        if (!isset($signal_ranges[$key])) continue;
        $range = $signal_ranges[$key];
        if (isset($range['min']) && isset($range['max'])) {
            $sig_parts[] = "(onus.last_rx_power >= ? AND onus.last_rx_power < ?)";
            $params[] = $range['min']; $params[] = $range['max'];
        } elseif (isset($range['max'])) {
            $sig_parts[] = "onus.last_rx_power < ?";
            $params[] = $range['max'];
        } else {
            $sig_parts[] = "onus.last_rx_power >= ?";
            $params[] = $range['min'];
        }
    }
    if (!empty($sig_parts)) $filter_sql .= " AND onus.last_rx_power IS NOT NULL AND (" . implode(' OR ', $sig_parts) . ")";
}

// Simpan filter TANPA kondisi status untuk stats summary (Online/Offline/Disabled count).
// Harus diambil SETELAH zone/odb/board/port/signal filter tapi SEBELUM status filter.
$stats_filter_sql = $filter_sql;
$stats_params = $params;

if (!empty($status)) {
    $status_parts = [];
    foreach ($status as $s) {
        if ($s === 'offline_power') {
            $status_parts[] = "(onus.status = 'offline' AND onus.last_down_cause IN ('Power Down','Manual'))";
        } elseif ($s === 'offline_fiber') {
            $status_parts[] = "(onus.status = 'offline' AND onus.last_down_cause IN ('LOS','LOSi','LOFi','SFi','LOAi','LOAMi'))";
        } elseif ($s === 'offline_other') {
            $status_parts[] = "(onus.status = 'offline' AND (onus.last_down_cause IS NULL OR onus.last_down_cause NOT IN ('Power Down','Manual','LOS','LOSi','LOFi','SFi','LOAi','LOAMi')))";
        } else {
            $status_parts[] = "onus.status = ?";
            $params[] = $s;
        }
    }
    if (!empty($status_parts)) $filter_sql .= " AND (" . implode(' OR ', $status_parts) . ")";
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
                    olts.name as olt_name, olts.ip as olt_ip, olts.last_snmp_status
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
    <form method="GET" id="form-filter" action="configured.php" class="filter-row filter-row--grid form-inline">
        <input type="hidden" name="limit" id="filter-limit" value="<?php echo $limit; ?>">
        <script>
        (function(){
            document.addEventListener('click',e=>{document.querySelectorAll('.ms-dropdown.open').forEach(d=>{if(!d.contains(e.target))d.classList.remove('open')})});
            document.querySelectorAll('.ms-dropdown').forEach(dd=>{
                const btn=dd.querySelector('.ms-btn .ms-label');
                const cbs=dd.querySelectorAll('input[type=checkbox]');
                function upd(){const n=Array.from(cbs).filter(c=>c.checked).length;btn.textContent=n?n+' dipilih':'🔍 Semua';}
                cbs.forEach(c=>c.addEventListener('change',upd));upd();
            });
        })();
        </script>
        <div class="filter-line">
        <div class="filter-item search-item">
            <label class="filter-label-outside" for="f-search">Cari</label>
            <input type="text" id="f-search" name="search" class="form-control" aria-label="Cari pelanggan" placeholder="Nama, SN, alamat, PPPoE..." value="<?php echo htmlspecialchars($search); ?>">
        </div>
        <?php
        // Render generik satu field .ms-dropdown: $id = id unik, $label = label kolom,
        // $name = nama GET param (array), $options = [[value, display_label, count], ...],
        // $selected = array nilai terpilih, $narrow = pakai lebar sempit.
        function render_ms_dropdown($id, $label, $name, $options, $selected, $narrow = false) {
            $n = count($selected);
            $btn_label = $n ? "$n dipilih" : 'Semua';
            ?>
            <div class="filter-item ms-dropdown<?php echo $narrow ? ' filter-item--narrow' : ''; ?>" id="ms-<?php echo $id; ?>">
                <label class="filter-label-outside"><?php echo htmlspecialchars($label); ?></label>
                <button type="button" class="form-control ms-btn" onclick="this.closest('.ms-dropdown').classList.toggle('open')">
                    <span class="ms-label"><?php echo htmlspecialchars($btn_label); ?></span>
                    <span class="ms-caret">▾</span>
                </button>
                <div class="ms-panel">
                    <input type="text" class="form-control ms-search" placeholder="Cari <?php echo htmlspecialchars($label); ?>..." oninput="const q=this.value.toLowerCase();this.closest('.ms-panel').querySelectorAll('.ms-opt').forEach(o=>{o.style.display=o.textContent.toLowerCase().includes(q)?'':'none'})">
                    <div class="ms-list">
                        <?php foreach ($options as $opt): [$value, $opt_label, $cnt] = $opt; ?>
                        <label class="ms-opt checkbox-row">
                            <input type="checkbox" name="<?php echo $name; ?>[]" value="<?php echo htmlspecialchars($value); ?>" <?php echo in_array($value, $selected) ? 'checked' : ''; ?>>
                            <span><?php echo htmlspecialchars($opt_label); ?> <span class="text-muted">(<?php echo (int)$cnt; ?>)</span></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <button type="submit" class="btn btn-primary btn-xs ms-apply">Terapkan</button>
                </div>
            </div>
            <?php
        }
        $status_labels = ['online'=>'Online','offline_power'=>'Offline - Power Down','offline_fiber'=>'Offline - Fiber Terputus','offline_other'=>'Offline - Lainnya','offline'=>'Offline - Semua','disabled'=>'Disabled'];
        $signal_labels = ['critical'=>'Sinyal Kritis (< -30 dBm)','weak'=>'Sinyal Lemah (-30 s/d -28)','fair'=>'Sinyal Sedang (-28 s/d -25)','good'=>'Sinyal Bagus (> -25 dBm)'];
        render_ms_dropdown('olt', 'OLT', 'olt_id', array_map(fn($o) => [(int)$o['id'], $o['name'], $olt_counts[$o['id']] ?? 0], $olts), $selected_olts);
        render_ms_dropdown('board', 'Board', 'board', array_map(fn($b) => [$b, $b, $board_counts[$b] ?? 0], $available_boards), $filter_board, true);
        render_ms_dropdown('port', 'Port', 'port', array_map(fn($p) => [$p, $p, $port_counts[$p] ?? 0], $available_ports), $filter_port, true);
        render_ms_dropdown('zone', 'Zone', 'zone', array_merge([['None', 'None (belum diisi)', $zone_counts['None'] ?? 0]], array_map(fn($z) => [$z, $z, $zone_counts[$z] ?? 0], $available_zones)), $filter_zone);
        render_ms_dropdown('odb', 'Splitter', 'odb', array_merge([['None', 'None (belum diisi)', $odb_counts['None'] ?? 0]], array_map(fn($o) => [$o, $o, $odb_counts[$o] ?? 0], $available_odbs)), $filter_odb);
        render_ms_dropdown('vlan', 'VLAN', 'vlan', array_map(fn($v) => [$v, $v, $vlan_counts[$v] ?? 0], $available_vlans), $filter_vlan, true);
        ?>
        </div>
        <div class="filter-line">
        <?php
        render_ms_dropdown('type', 'Tipe', 'onu_type', array_map(fn($t) => [$t, $t, $type_counts[$t] ?? 0], $available_types), $filter_type, true);
        render_ms_dropdown('profile', 'Profile', 'profile', array_map(fn($p) => [$p, $p, $profile_counts[$p] ?? 0], $available_profiles), $filter_profile);
        render_ms_dropdown('signal', 'Sinyal', 'signal', array_map(fn($k) => [$k, $signal_labels[$k], $signal_counts[$k] ?? 0], array_keys($signal_labels)), $filter_signal);
        render_ms_dropdown('status', 'Status', 'status', array_map(fn($k) => [$k, $status_labels[$k], $status_counts[$k] ?? 0], array_keys($status_labels)), $status);
        ?>
        </div>

        <div class="filter-actions">
            <div class="filter-item">
                <button type="submit" class="btn btn-primary btn-block">
                    <i data-lucide="filter"></i> Filter
                </button>
            </div>
            <?php if (!empty($filter_zone) || !empty($filter_odb) || !empty($filter_board) || !empty($filter_port) || !empty($search) || !empty($status) || !empty($filter_signal) || !empty($filter_type) || !empty($filter_vlan) || !empty($filter_profile) || $limit !== 100): ?>
            <div class="filter-item">
                <a href="configured.php" class="btn btn-outline">
                    <i data-lucide="x"></i> Reset
                </a>
            </div>
            <?php endif; ?>
            <div class="filter-item">
                <a href="action/export-csv.php?<?php echo http_build_query(array_filter([
                    'olt_id' => !empty($selected_olts) ? $selected_olts : null,
                    'search' => $search ?: null,
                    'status' => $status ?: null,
                    'zone' => $filter_zone ?: null,
                    'odb' => $filter_odb ?: null,
                    'board' => $filter_board ?: null,
                    'port' => $filter_port ?: null,
                    'signal' => $filter_signal ?: null,
                    'onu_type' => $filter_type ?: null,
                    'vlan' => $filter_vlan ?: null,
                    'profile' => $filter_profile ?: null,
                ], fn($v) => $v !== null && $v !== '')); ?>" class="btn btn-outline" title="Download CSV dengan filter saat ini">
                    <i data-lucide="download"></i> CSV
                </a>
            </div>
            <?php if (!empty($selected_olts)): ?>
            <div class="filter-item inline-flex-center">
                <button type="button" class="btn btn-xs btn-warning" id="btn-sync-pelanggan" onclick="triggerSyncPelanggan(event)">
                    <i data-lucide="refresh-cw" class="icon-xs"></i> Sync Pelanggan
                </button>
                <label class="toggle-inline" title="Tampilkan detail error saat sync gagal">
                    <input type="checkbox" id="toggle-debug" class="checkbox-xs">
                    Debug
                </label>
            </div>
            <?php endif; ?>
        </div>
    </form>
</div>

<div class="statstrip">
    <div class="statstrip-item">
        <span class="text-muted fs-sm">Total</span>
        <span id="stat-total" class="statstrip-value"><?php echo number_format($stats_total); ?></span>
    </div>
    <div class="statstrip-item">
        <span class="dot-sm dot-success"></span>
        <span class="text-muted fs-sm">Online</span>
        <span id="stat-online" class="statstrip-value text-success"><?php echo number_format($stats_map['online']); ?></span>
    </div>
    <div class="statstrip-item">
        <span class="dot-sm dot-danger"></span>
        <span class="text-muted fs-sm">Offline</span>
        <span id="stat-offline" class="statstrip-value text-danger"><?php echo number_format($stats_map['offline']); ?></span>
    </div>
    <div class="statstrip-item">
        <span class="dot-sm dot-warning"></span>
        <span class="text-muted fs-sm">Disabled</span>
        <span id="stat-disabled" class="statstrip-value text-warning"><?php echo number_format($stats_map['disabled']); ?></span>
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
                            row.getAttribute('data-odp') === String(onu.splitter || '') &&
                            row.getAttribute('data-zone') === String(onu.zone || '') &&
                            row.getAttribute('data-dl-prof') === String(onu.download_profile || '') &&
                            row.getAttribute('data-ul-prof') === String(onu.upload_profile || '')) {
                            return;
                        }
                        domChanged = true;

                        // Update status badge
                        const statusCell = row.querySelector('.status-cell');
                        if (statusCell) {
                            if (onu.status === 'online') {
                                statusCell.innerHTML = `<span class="status-icon-badge icon-green" title="Online"><i data-lucide="globe"></i></span>`;
                            } else if (onu.status === 'disabled') {
                                statusCell.innerHTML = `<span class="status-icon-badge icon-yellow" title="Disabled"><i data-lucide="shield-off"></i></span>`;
                            } else {
                                const cause = onu.last_down_cause || '';
                                const isPower = cause === 'Power Down' || cause === 'Manual';
                                const isFiber = ['LOS','LOSi','LOFi','SFi','LOAi','LOAMi'].includes(cause);
                                let icon, cls, title;
                                if (isPower) { icon = 'plug'; cls = 'icon-gray'; title = 'Offline - Power Down'; }
                                else if (isFiber) { icon = 'link-2-off'; cls = 'icon-red'; title = `Offline - Fiber Terputus (${cause})`; }
                                else { icon = 'globe'; cls = 'icon-red'; title = 'Offline'; }
                                statusCell.innerHTML = `<span class="status-icon-badge ${cls}" title="${title}"><i data-lucide="${icon}"></i></span>`;
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

                        // Update VLAN
                        const vlanCell = row.querySelector('.vlan-cell');
                        if (vlanCell) {
                            const vlanVal = onu.vlan || '-';
                            vlanCell.textContent = vlanVal;
                        }

                        // Update signal badges — skip jika OLT offline
                        const oltOffline = row.getAttribute('data-olt-offline') === '1';
                        const signalCell = row.querySelector('.signal-cell');
                        if (signalCell && !oltOffline) {
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
                                signalCell.innerHTML = '';
                            }
                        }

                        const signalOltCell = row.querySelector('.signal-olt-cell');
                        if (signalOltCell && !oltOffline) {
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
                                signalOltCell.innerHTML = '';
                            }
                        }

                        // Update Nama ODP
                        const odpCell = row.querySelector('.odp-cell');
                        if (odpCell) {
                            odpCell.textContent = onu.splitter || '-';
                        }
                        // Update Zone
                        const zoneCell = row.querySelector('.zone-cell');
                        if (zoneCell) {
                            zoneCell.textContent = onu.zone || '-';
                        }

                        // Update data-attributes for next comparison
                        row.setAttribute('data-dl-prof', onu.download_profile || '');
                        row.setAttribute('data-ul-prof', onu.upload_profile || '');
                    });

                    // Re-initialize Lucide icons only if DOM was actually modified
                    if (domChanged && typeof lucide !== 'undefined') {
                        lucide.createIcons();
                    }

                    // Update stats cards from full ONU dataset (only when no extra filters active)
                    // get-signals-db.php returns full OLT data, not filtered subset
                    const hasExtraFilters = <?php echo ($search !== '' || !empty($filter_zone) || !empty($filter_odb) || !empty($filter_board) || !empty($filter_port) || !empty($filter_signal) || !empty($filter_type) || !empty($filter_vlan) || !empty($filter_profile) || !empty($selected_olts) || !empty($status)) ? 'true' : 'false'; ?>;
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
            c.setAttribute('role', 'status');
            c.setAttribute('aria-live', 'polite');
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
                    from { transform: translateY(-20px) scale(0.95); opacity: 0; }
                    to { transform: translateY(0) scale(1); opacity: 1; }
                }
                @keyframes fadeOut {
                    from { transform: translateY(0) scale(1); opacity: 1; }
                    to { transform: translateY(-10px) scale(0.95); opacity: 0; }
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
            toast.style.border = '1px solid var(--text-accent, #3081d1)';
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
    <div class="card-header card-header--wrap card-header--seamless">
        <h1 id="page-title">Daftar Pelanggan Terdaftar (Configured)</h1>
        <div class="inline-flex-center">
            <label for="header-limit-select" class="m-0 fw-600 fs-sm text-muted">Tampilkan:</label>
            <select id="header-limit-select" class="form-control form-control--compact" aria-label="Jumlah baris">
                <option value="20" <?php echo $limit === 20 ? 'selected' : ''; ?>>20 / hal</option>
                <option value="50" <?php echo $limit === 50 ? 'selected' : ''; ?>>50 / hal</option>
                <option value="100" <?php echo $limit === 100 ? 'selected' : ''; ?>>100 / hal</option>
                <option value="200" <?php echo $limit === 200 ? 'selected' : ''; ?>>200 / hal</option>
            </select>
        </div>
    </div>
    <div class="card-body card-body--flush">
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th class="w-60 text-center" style="width:40px; white-space:nowrap">Status</th>
                        <th style="width:96px">Aksi</th>
                        <th style="width:15%">Nama Pelanggan</th>
                        <th style="width:10%">Serial Number</th>
                        <th style="width:12%">Koneksi OLT & PON</th>
                        <th class="text-center" style="width:5%">VLAN</th>
                        <th style="width:16%">Nama ODP</th>
                        <th style="width:12%">Zone</th>
                        <th class="text-center" style="width:9%">Sinyal Rx ONU</th>
                        <th class="text-center" style="width:9%">Sinyal Rx OLT</th>
                    </tr>
                </thead>
                <tbody class="motion-fade-in">
                    <?php if (empty($onus)): ?>
                        <tr>
                            <td colspan="10" class="empty-state">Tidak ada ONU terdaftar yang sesuai dengan kriteria filter.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($onus as $onu): ?>
                            <?php $olt_offline = ($onu['last_snmp_status'] ?? '') !== 'Connected'; ?>
                            <tr class="onu-row" data-onu-id="<?php echo (int)$onu['id']; ?>" data-name="<?php echo htmlspecialchars($onu['name'] ?? ''); ?>" data-status="<?php echo htmlspecialchars($onu['status']); ?>" data-vlan="<?php echo htmlspecialchars($onu['vlan'] ?: 'None'); ?>" data-rx-onu="<?php echo $onu['last_rx_power'] !== null ? number_format((float)$onu['last_rx_power'], 2) : 'N/A'; ?>" data-rx-olt="<?php echo $onu['last_rx_olt_power'] !== null ? number_format((float)$onu['last_rx_olt_power'], 2) : 'N/A'; ?>" data-down-cause="<?php echo htmlspecialchars($onu['last_down_cause'] ?? '-'); ?>" data-odp="<?php echo htmlspecialchars($onu['splitter'] ?? ''); ?>" data-zone="<?php echo htmlspecialchars($onu['zone'] ?? ''); ?>" data-dl-prof="<?php echo htmlspecialchars($onu['download_profile'] ?? ''); ?>" data-ul-prof="<?php echo htmlspecialchars($onu['upload_profile'] ?? ''); ?>" data-olt-offline="<?php echo $olt_offline ? '1' : '0'; ?>">
                                <td class="status-cell text-center">
                                    <?php if ($olt_offline): ?>
                                        <span class="status-icon-badge icon-red" title="OLT Offline — <?php echo htmlspecialchars($onu['olt_name']); ?>"><i data-lucide="server-crash"></i></span>
                                    <?php elseif ($onu['status'] === 'online'): ?>
                                        <span class="status-icon-badge icon-green" title="Online"><i data-lucide="globe"></i></span>
                                    <?php elseif ($onu['status'] === 'disabled'): ?>
                                        <span class="status-icon-badge icon-yellow" title="Disabled"><i data-lucide="shield-off"></i></span>
                                    <?php else: ?>
                                        <?php
                                        // Icon+warna beda tergantung penyebab down: fiber terputus (LOS) = merah,
                                        // listrik ONU mati (power down/manual) = abu, penyebab tak diketahui = globe merah.
                                        $cause = $onu['last_down_cause'] ?? '';
                                        $is_power = in_array($cause, ['Power Down', 'Manual'], true);
                                        $is_fiber = in_array($cause, ['LOS', 'LOSi', 'LOFi', 'SFi', 'LOAi', 'LOAMi'], true);
                                        if ($is_power) {
                                            $offline_icon = 'plug'; $offline_class = 'icon-gray'; $offline_title = 'Offline - Power Down';
                                        } elseif ($is_fiber) {
                                            $offline_icon = 'link-2-off'; $offline_class = 'icon-red'; $offline_title = 'Offline - Fiber Terputus (' . htmlspecialchars($cause) . ')';
                                        } else {
                                            $offline_icon = 'globe'; $offline_class = 'icon-red'; $offline_title = 'Offline';
                                        }
                                        ?>
                                        <span class="status-icon-badge <?php echo $offline_class; ?>" title="<?php echo $offline_title; ?>"><i data-lucide="<?php echo $offline_icon; ?>"></i></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="onu-detail.php?id=<?php echo (int)$onu['id']; ?>" class="btn btn-xs btn-primary">
                                        <i data-lucide="eye"></i> Detail
                                    </a>
                                </td>
                                <td class="name-cell"><strong><?php echo htmlspecialchars(extract_customer_name($onu['name'])); ?></strong></td>
                                <td><code><?php echo htmlspecialchars($onu['serial_number']); ?></code></td>
                                <td class="koneksi-cell">
                                    <?php echo htmlspecialchars($onu['olt_name']); ?><br>
                                    <span class="text-muted">PON <?php echo htmlspecialchars($onu['pon_port'] . ':' . $onu['onu_id']); ?></span>
                                </td>
                                <td class="vlan-cell text-center"><?php echo htmlspecialchars($onu['vlan'] ?: '-'); ?></td>
                                <td class="odp-cell"><?php echo htmlspecialchars($onu['splitter'] ?: '-'); ?></td>
                                <td class="zone-cell"><?php echo htmlspecialchars($onu['zone'] ?: '-'); ?></td>
                                <td class="signal-cell text-center">
                                    <?php if ($olt_offline): ?>
                                        <span class="text-muted">—</span>
                                    <?php elseif ($onu['status'] === 'online' && $onu['last_rx_power'] !== null): ?>
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
                                    <?php endif; ?>
                                </td>
                                <td class="signal-olt-cell text-center">
                                    <?php if ($olt_offline): ?>
                                        <span class="text-muted">—</span>
                                    <?php elseif ($onu['status'] === 'online' && $onu['last_rx_olt_power'] !== null): ?>
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
                                    <?php endif; ?>
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
                'olt_id' => $selected_olts ?: null,
                'search' => $search,
                'status' => $status,
                'zone' => $filter_zone,
                'odb' => $filter_odb,
                'board' => $filter_board,
                'port' => $filter_port,
                'signal' => $filter_signal,
                'onu_type' => $filter_type,
                'vlan' => $filter_vlan,
                'profile' => $filter_profile,
                'limit' => $limit
            ];
            $base_query = http_build_query($link_params);
            ?>
            <div class="pagination-bar mt-5">
                <div class="text-muted fs-sm">
                    Menampilkan <?php echo ($offset + 1); ?> - <?php echo min($offset + $limit, $total_items); ?> dari <?php echo $total_items; ?> pelanggan
                </div>
                <div class="pagination-buttons">
                    <!-- First Page Link (<<) -->
                    <?php if ($current_page > 1): ?>
                        <a href="configured.php?<?php echo $base_query; ?>&page=1" class="btn btn-xs btn-outline" title="Halaman Pertama"><<</a>
                    <?php else: ?>
                        <span class="btn btn-xs btn-outline disabled"><<</span>
                    <?php endif; ?>

                    <!-- Prev Page Link (<) -->
                    <?php if ($current_page > 1): ?>
                        <a href="configured.php?<?php echo $base_query; ?>&page=<?php echo ($current_page - 1); ?>" class="btn btn-xs btn-outline" title="Halaman Sebelumnya"><</a>
                    <?php else: ?>
                        <span class="btn btn-xs btn-outline disabled"><</span>
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
                        echo '<a href="configured.php?' . $base_query . '&page=' . $i . '" class="btn btn-xs ' . $active_class . '">' . $i . '</a>';
                    }
                    ?>

                    <!-- Next Page Link (>) -->
                    <?php if ($current_page < $total_pages): ?>
                        <a href="configured.php?<?php echo $base_query; ?>&page=<?php echo ($current_page + 1); ?>" class="btn btn-xs btn-outline" title="Halaman Selanjutnya">></a>
                    <?php else: ?>
                        <span class="btn btn-xs btn-outline disabled">></span>
                    <?php endif; ?>

                    <!-- Last Page Link (>>) -->
                    <?php if ($current_page < $total_pages): ?>
                        <a href="configured.php?<?php echo $base_query; ?>&page=<?php echo $total_pages; ?>" class="btn btn-xs btn-outline" title="Halaman Terakhir">>></a>
                    <?php else: ?>
                        <span class="btn btn-xs btn-outline disabled">>></span>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
