<?php
// ==============================================================================
# SmartOLT ONU Detail & Realtime Monitoring Page
// ==============================================================================
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/driver.php';
require_once __DIR__ . '/../backend/genieacs.php';
require_once __DIR__ . '/header.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (empty($id)) {
    header('Location: configured.php');
    exit;
}

// Ambil data detail ONU dari DB beserta kredensial OLT untuk sinkronisasi
$stmt = $pdo->prepare("SELECT onus.*, olts.name as olt_name, olts.ip as olt_ip, olts.type as olt_type,
                              olts.username as olt_username, olts.password as olt_password,
                              olts.ssh_port, olts.protocol,
                              olts.snmp_port, olts.snmp_community, olts.snmp_community_rw
                       FROM onus 
                       JOIN olts ON onus.olt_id = olts.id 
                       WHERE onus.id = ?");
$stmt->execute([$id]);
$onu = $stmt->fetch();

if (!$onu) {
    echo "<div class='error-box'>ONU tidak ditemukan di database.</div>";
    require_once __DIR__ . '/footer.php';
    exit;
}

// Background sync REMOVED — JS already fires onu-data.php?full=1 on page load,
// which runs sync_onu_config_from_olt() and returns data directly to the browser.
// Running sync_one_onu.py in parallel wasted 1 OLT VTY session slot (scarce resource,
// max ~5 concurrent) for identical, redundant work. Resync button (#btn-resync-config)
// triggers JS full sync by setting needFullSync=true + calling fetchRealtimeData()
// without a full page reload.



// Ambil riwayat sinyal dan traffic dari database
$stmt_hist = $pdo->prepare("
    SELECT rx_power, rx_olt_power, rx_bytes, tx_bytes, timestamp 
    FROM onu_history 
    WHERE onu_id = ? 
    ORDER BY timestamp DESC 
    LIMIT 20
");
$stmt_hist->execute([$onu['id']]);
$history_rows = array_reverse($stmt_hist->fetchAll());

$hist_labels = [];
$hist_signal = [];
$hist_signal_olt = [];
$hist_upload = [];
$hist_download = [];

$prev_row = null;
foreach ($history_rows as $row) {
    $time_label = date('H:i', strtotime($row['timestamp']));
    $hist_labels[] = $time_label;
    $hist_signal[] = $row['rx_power'] !== null ? (float)$row['rx_power'] : null;
    $hist_signal_olt[] = $row['rx_olt_power'] !== null ? (float)$row['rx_olt_power'] : null;
    
    $up_speed = 0.0;
    $down_speed = 0.0;
    
    if ($prev_row !== null && $row['rx_bytes'] !== null && $row['tx_bytes'] !== null && $prev_row['rx_bytes'] !== null && $prev_row['tx_bytes'] !== null) {
        $time_diff = strtotime($row['timestamp']) - strtotime($prev_row['timestamp']);
        if ($time_diff > 0) {
            $rx_diff = (float)$row['rx_bytes'] - (float)$prev_row['rx_bytes'];
            $tx_diff = (float)$row['tx_bytes'] - (float)$prev_row['tx_bytes'];
            
            if ($rx_diff >= 0 && $tx_diff >= 0) {
                // bytes/sec * 8 bits/byte = bits/sec. / 1000000 = Mbps.
                // rx_bytes = upload pelanggan (kecil), tx_bytes = download pelanggan (besar) —
                // lihat catatan verifikasi di JS trafficChart di bawah.
                $up_speed = round(($rx_diff * 8) / ($time_diff * 1000000), 2);
                $down_speed = round(($tx_diff * 8) / ($time_diff * 1000000), 2);
            }
        }
    }
    
    $hist_upload[] = $up_speed;
    $hist_download[] = $down_speed;
    $prev_row = $row;
}

// Page load render dari DB saja (instan, tanpa SSH).
// Data terbaru diambil JS lewat action/onu-data.php?full=1 setelah halaman tampil,
// lalu di-refresh tiap 15 detik dengan mode ringan (sinyal + IP).

// Daftar speed profile milik OLT ONU ini, untuk dropdown Download/Upload Speed
// di modal Update ONU Mode (sama sumber data dengan form Otorisasi).
$stmt_sp = $pdo->prepare('SELECT direction, name, speed_kbps FROM speed_profiles WHERE olt_id = ? ORDER BY direction, speed_kbps');
$stmt_sp->execute([$onu['olt_id']]);
$speed_profiles_list = $stmt_sp->fetchAll(PDO::FETCH_ASSOC);
$sp_download_options = array_filter($speed_profiles_list, fn($p) => $p['direction'] === 'download');
$sp_upload_options = array_filter($speed_profiles_list, fn($p) => $p['direction'] === 'upload');

?>





<?php
$pon_parts = explode('/', $onu['pon_port']);
$board_num = isset($pon_parts[0]) ? $pon_parts[0] : '0';
$port_num = end($pon_parts);
$driver_info = get_olt_driver_info(['type' => $onu['olt_type']]);
$gpon_channel = (isset($driver_info['pon_type']) && strtolower($driver_info['pon_type']) === 'epon') ? 'EPON' : 'GPON';

// Sumber utama: kolom DB (diisi oleh sync_snmp_onus.py / cron_sync.py / auth-onu.php
// yang SUDAH parse description sekali saat sync). Halaman ini TIDAK parse ulang
// description supaya hanya ada SATU tempat yang membaca format zone_..._odb_..._authd_...
$desc = $onu['description'] ?? $onu['name'] ?? '';
$parsed_desc = [
    'zone' => $onu['zone'] ?: 'None',
    'address' => $onu['address'] ?: 'None',
    'splitter' => $onu['splitter'] ?: 'None',
    'contact' => $onu['contact'] ?: 'None',
    'external_id' => $onu['external_id'] ?: null,
    'auth_date' => null,
    'clean_name' => $onu['name']
];

// Fallback: HANYA jika kolom DB kosong (data lama belum pernah di-sync ulang),
// parse langsung dari description sebagai jaring pengaman.
if (($parsed_desc['zone'] === 'None' || $parsed_desc['splitter'] === 'None' || $parsed_desc['address'] === 'None')
    && (stripos($desc, 'zone_') !== false || stripos($desc, 'name_') !== false)) {
    $parsed = parse_structured_description($desc);
    if ($parsed_desc['zone'] === 'None') $parsed_desc['zone'] = $parsed['zone'];
    if ($parsed_desc['address'] === 'None') $parsed_desc['address'] = $parsed['address'];
    if ($parsed_desc['splitter'] === 'None') $parsed_desc['splitter'] = $parsed['splitter'];
    if ($parsed_desc['contact'] === 'None') $parsed_desc['contact'] = $parsed['contact'];
    if (empty($parsed_desc['external_id'])) $parsed_desc['external_id'] = $parsed['external_id'];
}
// Nama bersih: pakai kolom DB langsung, description hanya fallback jika name kosong/generik
if (empty($parsed_desc['clean_name']) || preg_match('/^onu_\d+$/i', $parsed_desc['clean_name'])) {
    $extracted = extract_customer_name($desc);
    if ($extracted !== $desc) {
        $parsed_desc['clean_name'] = $extracted;
    }
}

// Parse PPPoE username/password dari running config jika kosong
if (!empty($onu['last_running_config'])) {
    if (preg_match('/pppoe\s+\d+\s+.*?user\s+(\S+)\s+password\s+(\S+)/i', $onu['last_running_config'], $pppoe_m)) {
        if (empty($onu['pppoe_username'])) {
            $onu['pppoe_username'] = $pppoe_m[1];
            $onu['pppoe_password'] = $pppoe_m[2];
        }
        // Auto-detect PPPoE mode if DB says DHCP but config has PPPoE
        if (strtolower($onu['wan_mode'] ?? '') !== 'pppoe') {
            $onu['wan_mode'] = 'PPPoE';
        }
    }
}

// Speed profile: DB kolom (diisi auth-onu / update-onu-mode) sebagai sumber utama.
// Fallback: parse dari last_running_config (hanya terisi setelah "Show running-config" diklik).
$speed_upload_profile = !empty($onu['upload_profile']) ? $onu['upload_profile'] : null;
$speed_download_profile = !empty($onu['download_profile']) ? $onu['download_profile'] : null;
if ((!$speed_upload_profile || !$speed_download_profile) && !empty($onu['last_running_config'])) {
    $cfg = $onu['last_running_config'];
    if (!$speed_upload_profile) {
        if (preg_match('/tcont\s+\d+\s+profile\s+(\S+)/i', $cfg, $m_up)) {
            $speed_upload_profile = $m_up[1];
        } elseif (preg_match('/ont\s+tcont\s+\d+\s+\d+\s+1\s+dba-profile-id\s+(\d+)/i', $cfg, $m_up)) {
            $speed_upload_profile = 'DBA Profile ID ' . $m_up[1];
        }
    }
    if (!$speed_download_profile) {
        if (preg_match('/gemport\s+\d+\s+traffic-limit\s+downstream\s+(\S+)/i', $cfg, $m_down)) {
            $speed_download_profile = $m_down[1];
        } elseif (preg_match('/ont\s+tcont\s+\d+\s+\d+\s+0\s+dba-profile-id\s+(\d+)/i', $cfg, $m_down)) {
            $speed_download_profile = 'DBA Profile ID ' . $m_down[1];
        }
    }
}



// Cari tanggal otorisasi
if (preg_match('/authd_(\d{8})/i', $desc, $m)) {
    $date_str = $m[1];
    $timestamp = strtotime(substr($date_str, 0, 4) . '-' . substr($date_str, 4, 2) . '-' . substr($date_str, 6, 2));
    if ($timestamp) {
        $parsed_desc['auth_date'] = date('d-M-Y H:i:s', $timestamp);
    }
}

// Fetch ONU type specs (ethernet ports, WiFi, VoIP, CATV)
$onu_type_specs = ['ethernet_ports' => 0, 'wifi_ssids' => 0, 'voip_ports' => 0, 'catv' => 0];
if (!empty($onu['onu_type'])) {
    $typeStmt = $pdo->prepare("SELECT ethernet_ports, wifi_ssids, voip_ports, catv FROM onu_types WHERE name = ?");
    $typeStmt->execute([$onu['onu_type']]);
    $typeRow = $typeStmt->fetch(PDO::FETCH_ASSOC);
    if ($typeRow) $onu_type_specs = $typeRow;
}
        ?>

<style>
.sync-banner {
    display: flex;
    align-items: center;
    gap: 12px;
    background: rgba(59, 130, 246, 0.1);
    border: 1px solid rgba(59, 130, 246, 0.2);
    border-radius: 8px;
    padding: 12px 16px;
    margin-bottom: 20px;
    color: #93c5fd;
    font-size: 0.875rem;
    font-weight: 500;
    transition: opacity 0.3s ease, margin 0.3s ease, padding 0.3s ease, height 0.3s ease;
}
.sync-banner.fade-out {
    opacity: 0;
    padding-top: 0;
    padding-bottom: 0;
    margin-bottom: 0;
    height: 0;
    overflow: hidden;
    border: none;
}
.spinner-small {
    width: 14px;
    height: 14px;
    border: 2px solid rgba(147, 197, 253, 0.3);
    border-top-color: #60a5fa;
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
    display: inline-block;
    vertical-align: middle;
}
@keyframes spin {
    to { transform: rotate(360deg); }
}
.animate-spin { animation: spin 1.2s linear infinite; display: inline-block; }
</style>

<div class="smartolt-layout">
    <!-- Sync Loading Banner (Bypassed in foreground) -->

    <!-- Section 1: Identity & Status (clean text layout, no card/table) -->
    <div class="detail-plain-grid">

        <!-- Kolom Kiri: Identitas -->
        <div class="detail-plain-col">

            <div class="detail-plain-row">
                <span class="detail-plain-label">OLT</span>
                <span class="detail-plain-value"><a href="configured.php?olt_id=<?php echo (int)$onu['olt_id']; ?>"><?php echo htmlspecialchars($onu['olt_name']); ?></a></span>
            </div>

            <div class="detail-plain-row">
                <span class="detail-plain-label">Shelf</span>
                <span class="detail-plain-value"><?php echo htmlspecialchars($board_num); ?></span>
            </div>

            <div class="detail-plain-row">
                <span class="detail-plain-label">Slot</span>
                <span class="detail-plain-value"><?php echo htmlspecialchars(isset($pon_parts[1]) ? $pon_parts[1] : '0'); ?></span>
            </div>

            <div class="detail-plain-row">
                <span class="detail-plain-label">Port</span>
                <span class="detail-plain-value"><?php echo htmlspecialchars($port_num); ?></span>
            </div>

            <div class="detail-plain-row">
                <span class="detail-plain-label">ONU</span>
                <span class="detail-plain-value mono"><a href="configured.php?olt_id=<?php echo (int)$onu['olt_id']; ?>">gpon-onu_<?php echo htmlspecialchars($onu['pon_port']); ?>:<?php echo htmlspecialchars($onu['onu_id']); ?></a></span>
            </div>

            <div class="detail-plain-row">
                <span class="detail-plain-label">Kanal GPON</span>
                <span class="detail-plain-value"><?php echo htmlspecialchars($gpon_channel); ?></span>
            </div>

            <div class="detail-plain-row" data-clickable onclick="document.getElementById('replace-onu-modal').classList.add('open')">
                <span class="detail-plain-label">SN</span>
                <span class="detail-plain-value mono" id="detail-sn"><i data-lucide="pencil" style="width:11px;height:11px;"></i> <?php echo htmlspecialchars($onu['serial_number']); ?></span>
            </div>

            <div class="detail-plain-row" data-clickable onclick="document.getElementById('edit-onu-type-modal').classList.add('open')">
                <span class="detail-plain-label">Tipe ONU</span>
                <span class="detail-plain-value"><i data-lucide="pencil" style="width:11px;height:11px;"></i> <?php echo htmlspecialchars($onu['onu_type'] ?: 'ALL-ONT'); ?></span>
            </div>

            <div class="detail-plain-row" data-clickable onclick="document.getElementById('edit-config-preset-modal').classList.add('open')">
                <span class="detail-plain-label">Preset Konfigurasi</span>
                <span class="detail-plain-value"><i data-lucide="pencil" style="width:11px;height:11px;"></i> <?php echo htmlspecialchars($onu['config_preset'] ?: 'Tidak ada'); ?></span>
            </div>

            <div class="detail-plain-row" data-clickable onclick="document.getElementById('edit-identity-modal').classList.add('open')">
                <span class="detail-plain-label">Zone</span>
                <span class="detail-plain-value" id="detail-zone"><i data-lucide="pencil" style="width:11px;height:11px;"></i> <?php echo htmlspecialchars(($parsed_desc['zone'] && strtolower($parsed_desc['zone']) !== 'none') ? $parsed_desc['zone'] : 'Belum diisi'); ?></span>
            </div>

            <div class="detail-plain-row" data-clickable onclick="document.getElementById('edit-identity-modal').classList.add('open')">
                <span class="detail-plain-label">Splitter</span>
                <span class="detail-plain-value" id="detail-splitter" style="flex-wrap:nowrap; overflow:hidden; min-width:0; white-space:nowrap; text-overflow:ellipsis;" title="<?php echo htmlspecialchars(($parsed_desc['splitter'] && strtolower($parsed_desc['splitter']) !== 'none') ? $parsed_desc['splitter'] : 'Belum diisi'); ?>"><i data-lucide="pencil" style="width:11px;height:11px;flex-shrink:0;"></i> <?php echo htmlspecialchars(($parsed_desc['splitter'] && strtolower($parsed_desc['splitter']) !== 'none') ? $parsed_desc['splitter'] : 'Belum diisi'); ?></span>
            </div>

            <div class="detail-plain-row" data-clickable onclick="document.getElementById('edit-identity-modal').classList.add('open')">
                <span class="detail-plain-label">Nama</span>
                <span class="detail-plain-value" id="detail-name"><i data-lucide="pencil" style="width:11px;height:11px;"></i> <?php echo htmlspecialchars(!empty($parsed_desc['clean_name']) && strtolower($parsed_desc['clean_name']) !== 'none' ? $parsed_desc['clean_name'] : 'Belum diisi'); ?></span>
            </div>

            <div class="detail-plain-row" data-clickable onclick="document.getElementById('edit-identity-modal').classList.add('open')">
                <span class="detail-plain-label">Alamat atau keterangan</span>
                <span class="detail-plain-value" id="detail-address"><i data-lucide="pencil" style="width:11px;height:11px;"></i> <?php echo htmlspecialchars(($parsed_desc['address'] && strtolower($parsed_desc['address']) !== 'none') ? $parsed_desc['address'] : 'Belum diisi'); ?></span>
            </div>

            <div class="detail-plain-row" data-clickable onclick="document.getElementById('edit-identity-modal').classList.add('open')">
                <span class="detail-plain-label">Kontak</span>
                <span class="detail-plain-value" id="detail-contact"><i data-lucide="pencil" style="width:11px;height:11px;"></i> <?php echo htmlspecialchars(($parsed_desc['contact'] && strtolower($parsed_desc['contact']) !== 'none') ? $parsed_desc['contact'] : 'Belum diisi'); ?></span>
            </div>

            <div class="detail-plain-row">
                <span class="detail-plain-label">Tanggal otorisasi</span>
                <span class="detail-plain-value" style="color:var(--text-muted);"><?php echo htmlspecialchars($parsed_desc['auth_date'] ?: date('d-M-Y H:i:s', strtotime($onu['created_at']))); ?></span>
            </div>

            <div class="detail-plain-row" data-clickable onclick="document.getElementById('edit-identity-modal').classList.add('open')">
                <span class="detail-plain-label">Port ODB</span>
                <span class="detail-plain-value" id="detail-odb-port"><i data-lucide="pencil" style="width:11px;height:11px;"></i> <?php echo htmlspecialchars(!empty($onu['odb_port']) ? $onu['odb_port'] : 'Belum diisi'); ?></span>
            </div>

            <div class="detail-plain-row" data-clickable onclick="document.getElementById('edit-identity-modal').classList.add('open')">
                <span class="detail-plain-label">ONU external ID</span>
                <span class="detail-plain-value" id="detail-external-id"><i data-lucide="pencil" style="width:11px;height:11px;"></i> <?php echo htmlspecialchars(!empty($parsed_desc['external_id']) ? $parsed_desc['external_id'] : 'Belum diisi'); ?></span>
            </div>

            <?php
            $has_coords = ($onu['latitude'] ?? null) && ($onu['longitude'] ?? null);
            ?>
            <div class="detail-plain-row" <?php if ($has_coords) echo 'data-clickable onclick="window.open(\'https://www.google.com/maps?q=' . htmlspecialchars($onu['latitude']) . ',' . htmlspecialchars($onu['longitude']) . '\', \'_blank\')"'; ?>>
                <span class="detail-plain-label">Lokasi</span>
                <span class="detail-plain-value">
                    <?php if ($has_coords): ?>
                        <a href="https://www.google.com/maps?q=<?php echo htmlspecialchars($onu['latitude']); ?>,<?php echo htmlspecialchars($onu['longitude']); ?>" target="_blank" onclick="event.stopPropagation();" style="color:var(--text-accent);">
                            <i data-lucide="map-pin" style="width:14px;height:14px;"></i> <?php echo htmlspecialchars($onu['latitude'] . ', ' . $onu['longitude']); ?>
                        </a>
                    <?php else: ?>
                        <span style="color:var(--text-muted);">N/A</span>
                    <?php endif; ?>
                </span>
            </div>

        </div>

        <!-- Kolom Kanan: Status & Koneksi -->
        <div class="detail-plain-col">

            <div class="detail-plain-row">
                <span class="detail-plain-label">Status</span>
                <span class="detail-plain-value">
                    <span class="detail-status-dot <?php echo htmlspecialchars($onu['status']); ?>" id="detail-status-dot"></span>
                    <span id="detail-status"><?php echo htmlspecialchars(ucfirst($onu['status'])); ?></span>
                    <span style="font-size:0.75rem; color:var(--text-muted); margin-left:6px;">auto-refresh <span id="auto-refresh-countdown" style="font-weight:600; color:var(--text-accent);">15</span>s</span>
                </span>
            </div>

            <div class="detail-plain-row">
                <span class="detail-plain-label">Last Down</span>
                <span class="detail-plain-value" id="detail-last-down" style="color:var(--text-muted);">
                    <?php echo htmlspecialchars($onu['last_down_cause'] ?? '-'); ?>
                </span>
            </div>

            <div class="detail-plain-row">
                <span class="detail-plain-label">Sinyal Rx</span>
                <span class="detail-plain-value">
                    <?php
                    $onu_val = $onu['last_rx_power'] !== null ? (float)$onu['last_rx_power'] : null;
                    $onu_color = 'var(--text-muted)';
                    if ($onu_val !== null) {
                        $onu_color = ($onu_val < -30) ? 'var(--color-danger)' : (($onu_val < -28) ? 'var(--color-orange)' : (($onu_val < -25) ? 'var(--color-amber)' : 'var(--color-success)'));
                    }
                    $olt_val = $onu['last_rx_olt_power'] !== null ? (float)$onu['last_rx_olt_power'] : null;
                    $olt_color = 'var(--text-muted)';
                    if ($olt_val !== null) {
                        $olt_color = ($olt_val < -30) ? 'var(--color-danger)' : (($olt_val < -28) ? 'var(--color-orange)' : (($olt_val < -25) ? 'var(--color-amber)' : 'var(--color-success)'));
                    }
                    ?>
                    <strong id="detail-rx-onu" style="color:<?php echo $onu_color; ?>;"><?php echo $onu['last_rx_power'] !== null ? htmlspecialchars($onu['last_rx_power']) . ' dBm' : 'N/A'; ?></strong>
                    <span style="color:var(--text-muted);">/</span>
                    <strong id="detail-rx-olt" style="color:<?php echo $olt_color; ?>;"><?php echo $onu['last_rx_olt_power'] !== null ? htmlspecialchars($onu['last_rx_olt_power']) . ' dBm' : 'N/A'; ?></strong>
                    <span id="detail-distance" style="color:var(--text-muted);">(N/A)</span>
                    <i data-lucide="signal" style="width:14px;height:14px;color:var(--color-success);"></i>
                </span>
            </div>

            <div class="detail-plain-row" data-clickable onclick="document.getElementById('mgmt-ip-modal').classList.add('open')">
                <span class="detail-plain-label">IP Manajemen</span>
                <span class="detail-plain-value" id="detail-mgmt-ip">
                    <i data-lucide="pencil" style="width:11px;height:11px;"></i>
                    <?php
                    $mgmt_mode = $onu['mgmt_ip_mode'] ?? 'Inactive';
                    // IP yang dipakai untuk chip: Static/DHCP dari OLT, atau fallback TR-069
                    // (satu jalur VLAN management, sering DHCP-nya belum dilaporkan OLT).
                    $mgmt_ip_display = $onu['mgmt_ip'] ?: null;
                    if (!$mgmt_ip_display && ($onu['config_method'] ?? null) === 'TR069') {
                        $tr069_dev_id = genieacs_find_device_id($onu['serial_number'] ?? '');
                        $mgmt_ip_display = $tr069_dev_id ? genieacs_get_tr069_ip($tr069_dev_id) : null;
                    }
                    if ($mgmt_mode === 'Static' || $mgmt_mode === 'DHCP'): ?>
                        <strong><?php echo htmlspecialchars($mgmt_mode); ?></strong>
                        <?php if ($mgmt_ip_display): ?>
                            <a href="http://<?php echo htmlspecialchars($mgmt_ip_display); ?>" target="_blank" class="chip chip-green" style="text-decoration:none;" onclick="event.stopPropagation();">
                                <i data-lucide="external-link" style="width:11px;height:11px;display:inline-block;vertical-align:middle;margin-right:4px;"></i><?php echo htmlspecialchars($mgmt_ip_display); ?>
                            </a>
                        <?php else: ?>
                            <span class="chip chip-red">Menunggu IP</span>
                        <?php endif; ?>
                    <?php else: ?>
                        <span style="color:var(--text-muted);">Nonaktif</span>
                    <?php endif; ?>
                </span>
            </div>

            <div class="detail-plain-row" data-clickable onclick="document.getElementById('tr609-profile-modal').classList.add('open')">
                <span class="detail-plain-label">TR609 Profile</span>
                <span class="detail-plain-value" id="detail-tr609">
                    <i data-lucide="pencil" style="width:11px;height:11px;"></i>
                    <?php
                    $tr609 = $onu['tr069_profile'] ?? 'ACS-Smartolt';
                    echo htmlspecialchars($tr609);
                    ?>
                </span>
            </div>

            <div class="detail-plain-row">
                <span class="detail-plain-label">VLAN Terpasang</span>
                <span class="detail-plain-value" id="detail-vlan"><?php echo htmlspecialchars($onu['vlan'] ?: 'Belum diisi'); ?></span>
            </div>

            <div class="detail-plain-row">
                <span class="detail-plain-label">Akses Remote WAN</span>
                <span class="detail-plain-value" id="detail-wan-remote">
                    <?php
                    $wra = $onu['wan_remote_access'] ?? '';
                    if ($wra === 'yes') {
                        echo '<span style="color:var(--color-success); font-weight:600;">Aktif</span>';
                    } else {
                        echo '<span style="color:var(--text-muted);">Nonaktif</span>';
                    }
                    ?>
                </span>
            </div>

            <div class="detail-plain-row" data-clickable onclick="document.getElementById('update-onu-mode-modal').classList.add('open')">
                <span class="detail-plain-label">Mode ONU</span>
                <span class="detail-plain-value" id="detail-onu-mode"><i data-lucide="pencil" style="width:11px;height:11px;"></i> <?php echo htmlspecialchars($onu['onu_mode'] ?: 'Routing'); ?> (<?php echo htmlspecialchars($onu['config_method'] ?: 'OMCI'); ?>) &mdash; WAN <?php echo htmlspecialchars($onu['vlan'] ?: 'N/A'); ?></span>
            </div>

            <div class="detail-plain-row" data-clickable onclick="document.getElementById('update-onu-mode-modal').classList.add('open')">
                <span class="detail-plain-label">Mode setup WAN</span>
                <span class="detail-plain-value" id="detail-wan-setup">
                    <i data-lucide="pencil" style="width:11px;height:11px;"></i> <span id="detail-wan-label"><?php echo htmlspecialchars($onu['wan_mode'] === 'Static' ? 'Static IP' : ($onu['wan_mode'] ?: 'Setup via ONU webpage')); ?></span>

                    <span id="detail-pppoe-ip-wrapper">
                        <?php
                        // TR-069 = sumber kebenaran IP PPPoE saat config_method=TR069 (bukan cache CLI OLT).
                        $pppoe_ip_display = $onu['pppoe_ip'] ?: null;
                        if (($onu['wan_mode'] ?? '') === 'PPPoE' && ($onu['config_method'] ?? null) === 'TR069') {
                            $tr069_dev_id_ppp = genieacs_find_device_id($onu['serial_number'] ?? '');
                            $tr069_ppp_ip_display = $tr069_dev_id_ppp ? genieacs_get_ppp_wan_ip($tr069_dev_id_ppp) : null;
                            if ($tr069_ppp_ip_display) $pppoe_ip_display = $tr069_ppp_ip_display;
                        }
                        ?>
                        <?php if (!empty($pppoe_ip_display)): ?>
                            <a href="http://<?php echo htmlspecialchars($pppoe_ip_display); ?>" target="_blank" style="opacity:0.65;" onclick="event.stopPropagation();">
                                <i data-lucide="external-link" style="width:11px;height:11px;vertical-align:middle;margin-right:4px;"></i><?php echo htmlspecialchars($pppoe_ip_display); ?>
                            </a>
                        <?php elseif (($onu['wan_mode'] ?? '') === 'PPPoE'): ?>
                            <span style="color:var(--text-muted);">Memuat...</span>
                        <?php else: ?>
                            <span style="color:var(--text-muted);">-</span>
                        <?php endif; ?>
                    </span>
                </span>
            </div>

            <div class="detail-plain-row" data-clickable onclick="document.getElementById('update-onu-mode-modal').classList.add('open')">
                <span class="detail-plain-label">Nama pengguna PPPoE</span>
                <span class="detail-plain-value mono" id="pppoe-user-text" data-username="<?php echo htmlspecialchars($onu['pppoe_username'] ?? ''); ?>" data-visible="false">
                    <span id="pppoe-user-value"><?php
                        if (!empty($onu['pppoe_username'])) echo '**********';
                        elseif (($onu['wan_mode'] ?? '') === 'PPPoE') echo 'Memuat…';
                        else echo '-';
                    ?></span>
                    <button type="button" id="btn-toggle-pppoe-user" class="detail-plain-edit" onclick="event.stopPropagation();"><i data-lucide="eye" style="width:14px;height:14px;"></i></button>
                </span>
            </div>

            <div class="detail-plain-row" data-clickable onclick="document.getElementById('update-onu-mode-modal').classList.add('open')">
                <span class="detail-plain-label">Kata sandi PPPoE</span>
                <span class="detail-plain-value mono" id="pppoe-pass-text" data-password="<?php echo htmlspecialchars($onu['pppoe_password'] ?? ''); ?>" data-visible="false">
                    <span id="pppoe-pass-value"><?php
                        if (!empty($onu['pppoe_password'])) echo '**********';
                        elseif (($onu['wan_mode'] ?? '') === 'PPPoE') echo 'Memuat…';
                        else echo '-';
                    ?></span>
                    <button type="button" id="btn-toggle-pppoe-pass" class="detail-plain-edit" onclick="event.stopPropagation();"><i data-lucide="eye" style="width:14px;height:14px;"></i></button>
                </span>
            </div>

        </div>

    </div>

    <!-- Section 2: Status row (Buttons) -->
    <div class="smartolt-row">
        <div class="smartolt-label">Status</div>
        <div class="smartolt-content" style="display:flex; flex-direction:column; gap:10px;">
            <div style="display:flex; gap:10px; flex-wrap:wrap;">
                <button type="button" class="btn-solt btn-solt-blue" id="btn-onu-refresh-signal"><i data-lucide="refresh-cw" style="width:14px; height:14px;"></i> Status</button>
                <button type="button" class="btn-solt btn-solt-blue" id="btn-show-running-config"><i data-lucide="file-text" style="width:14px; height:14px;"></i> Show running-config</button>
                <button type="button" class="btn-solt btn-solt-blue" id="btn-sw-info"><i data-lucide="info" style="width:14px; height:14px;"></i> Info SW</button>
                <?php if (($onu['tr069_profile'] ?? '') === 'ACS-Smartolt'): ?>
                <button type="button" class="btn-solt btn-solt-orange" id="btn-tr069-status"><i data-lucide="activity" style="width:14px; height:14px;"></i> TR069 Status</button>
                <?php endif; ?>
                <button type="button" class="btn-solt btn-solt-green" id="btn-live"><i data-lucide="refresh-cw" style="width:14px; height:14px;"></i> LIVE!</button>
            </div>
            
            <!-- Panel Output CLI Langsung di Bawah Tombol -->
            <div id="status-cli-output-container" style="display: none; margin-top:10px; padding:16px; font-size:0.85rem; line-height:1.6; color:var(--text-main); width:100%;"></div>
        </div>
    </div>

    <!-- Section 3: Traffic/Signal charts -->
    <div class="smartolt-row">
        <div class="smartolt-label">Trafik/Sinyal</div>
        <div class="smartolt-content flex-row-gap">
            <div class="chart-wrapper">
                <div class="chart-title">gpon-onu_<?php echo htmlspecialchars($onu['pon_port']); ?>:<?php echo htmlspecialchars($onu['onu_id']); ?> trafik</div>
                <div class="chart-container" style="position: relative; height: 220px; width: 100%;">
                    <canvas id="trafficChart"></canvas>
                </div>
                <div class="chart-legend-stats">
                    <div class="legend-row">
                        <span class="legend-label"><span class="legend-dot upload-dot"></span>Upload</span>
                        <span class="legend-current">Saat ini: <span id="stat-ul-curr">0.00 Mbps</span></span>
                        <span class="legend-max">Maks: <span id="stat-ul-max">0.00 Mbps</span></span>
                    </div>
                    <div class="legend-row">
                        <span class="legend-label"><span class="legend-dot download-dot"></span>Download</span>
                        <span class="legend-current">Saat ini: <span id="stat-dl-curr">0.00 Mbps</span></span>
                        <span class="legend-max">Maks: <span id="stat-dl-max">0.00 Mbps</span></span>
                    </div>
                </div>
                <div class="chart-legend-stats" style="margin-top:4px; color:var(--text-muted); font-size:0.8rem;">
                    Total Paket — RX: <span id="stat-rx-packets">-</span> TX: <span id="stat-tx-packets">-</span>
                </div>
            </div>
            <div class="chart-wrapper">
                <div class="chart-title">gpon-onu_<?php echo htmlspecialchars($onu['pon_port']); ?>:<?php echo htmlspecialchars($onu['onu_id']); ?> sinyal</div>
                <div class="chart-container" style="position: relative; height: 220px; width: 100%;">
                    <canvas id="signalChart"></canvas>
                </div>
                <div class="chart-legend-stats">
                    <div class="legend-row">
                        <span class="legend-label"><span class="legend-dot" style="background:#22d3ee;"></span>Rx ONU</span>
                        <span class="legend-current"><span id="stat-sig-curr"><?php echo $onu['last_rx_power'] !== null ? htmlspecialchars($onu['last_rx_power']) . ' dBm' : 'N/A'; ?></span></span>
                        <span></span>
                    </div>
                    <div class="legend-row">
                        <span class="legend-label"><span class="legend-dot" style="background:#a855f7;"></span>Rx OLT</span>
                        <span class="legend-current"><span id="stat-sig-olt-curr"><?php echo $onu['last_rx_olt_power'] !== null ? htmlspecialchars($onu['last_rx_olt_power']) . ' dBm' : 'N/A'; ?></span></span>
                        <span></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Section 4: Speed profiles table -->
    <div class="smartolt-row">
        <div class="smartolt-label">Profil Kecepatan</div>
        <div class="smartolt-content">
            <table class="smartolt-data-table">
                <thead>
                    <tr>
                        <th style="width: 45%;">Download</th>
                        <th style="width: 45%;">Upload</th>
                        <th style="width: 10%;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td id="speed-download-cell"><?php echo htmlspecialchars($speed_download_profile ?? 'N/A (klik "Show running-config")'); ?></td>
                        <td id="speed-upload-cell"><?php echo htmlspecialchars($speed_upload_profile ?? 'N/A (klik "Show running-config")'); ?></td>
                        <td>
                            <a href="#" class="action-link" onclick="document.getElementById('update-onu-mode-modal').classList.add('open'); return false;">
                                <i data-lucide="plus-circle" style="width:14px; height:14px;"></i> Konfigurasi
                            </a>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Section 5: Ethernet ports -->
    <div class="smartolt-row">
        <div class="smartolt-label">Port Ethernet</div>
        <div class="smartolt-content">
            <?php if ($onu_type_specs['ethernet_ports'] > 0): ?>
                <span style="font-weight:600; color:var(--text-main);"><?php echo (int)$onu_type_specs['ethernet_ports']; ?> port</span>
                <span style="color:var(--text-muted); font-size:0.85rem;">(<?php echo htmlspecialchars($onu['onu_type']); ?>)</span>
            <?php else: ?>
                <span style="color:var(--text-muted);">—</span>
            <?php endif; ?>
        </div>
    </div>

    <!-- Section 6: WiFi -->
    <div class="smartolt-row">
        <div class="smartolt-label">WiFi</div>
        <div class="smartolt-content">
            <?php if ($onu_type_specs['wifi_ssids'] > 0): ?>
                <span style="font-weight:600; color:var(--text-main);"><?php echo (int)$onu_type_specs['wifi_ssids']; ?> SSIDs</span>
                <span style="color:var(--text-muted); font-size:0.85rem;">(<?php echo htmlspecialchars($onu['onu_type']); ?>)</span>
            <?php else: ?>
                <span style="color:var(--text-muted);">—</span>
            <?php endif; ?>
        </div>
    </div>

    <!-- Section 7: VoIP, IPTV, CATV status -->
    <div class="smartolt-row" style="margin-bottom: 12px; padding-bottom: 12px; border-bottom:none;">
        <div class="smartolt-label">Layanan VoIP</div>
        <div class="smartolt-content">
            <?php if ($onu_type_specs['voip_ports'] > 0): ?>
                <span style="font-weight:600; color:var(--text-main);"><?php echo (int)$onu_type_specs['voip_ports']; ?> port</span>
            <?php else: ?>
                <span style="color:var(--text-muted);">Nonaktif</span>
            <?php endif; ?>
        </div>
    </div>
    <div class="smartolt-row" style="margin-bottom: 12px; padding-bottom: 12px; border-bottom:none;">
        <div class="smartolt-label">CATV</div>
        <div class="smartolt-content">
            <?php if ($onu_type_specs['catv']): ?>
                <span style="font-weight:600; color:var(--color-green);">Didukung</span>
            <?php else: ?>
                <span style="color:var(--text-muted);">Tidak didukung</span>
            <?php endif; ?>
        </div>
    </div>

    <!-- Section 8: Action buttons (Reboot, Resync, Restore, Disable, Delete) -->
    <div class="smartolt-row" style="border-top:1px solid var(--border-color); padding-top:24px;">
        <div class="smartolt-label"></div>
        <div class="smartolt-content" style="display:flex; gap:10px; flex-wrap:wrap;">
            <form action="action/reboot-onu.php" method="POST" onsubmit="return confirm('Apakah Anda yakin ingin me-reboot ONT ini?');" style="margin:0;">
                <input type="hidden" name="id" value="<?php echo (int)$onu['id']; ?>">
                <button type="submit" class="btn-solt btn-solt-orange"><i data-lucide="refresh-cw" style="width:14px; height:14px;"></i> Reboot</button>
            </form>
            <form action="action/restore-factory.php" method="POST" onsubmit="return confirm('Restore factory akan MENGHAPUS semua konfigurasi ONT dan mengembalikan ke pengaturan pabrik. ONT perlu diotorisasi ulang. Lanjutkan?');" style="margin:0;">
                <input type="hidden" name="id" value="<?php echo (int)$onu['id']; ?>">
                <button type="submit" class="btn-solt btn-solt-orange"><i data-lucide="rotate-ccw" style="width:14px; height:14px;"></i> Restore Factory</button>
            </form>
            <button type="button" class="btn-solt btn-solt-orange" id="btn-resync-config"><i data-lucide="refresh-ccw" style="width:14px; height:14px;"></i> Resync config</button>
            <?php if ($onu['status'] === 'disabled'): ?>
                <form action="action/onu-state.php" method="POST" onsubmit="return confirm('Apakah Anda yakin ingin mengaktifkan kembali ONT ini?');" style="margin:0;">
                    <input type="hidden" name="id" value="<?php echo (int)$onu['id']; ?>">
                    <input type="hidden" name="action" value="enable">
                    <button type="submit" class="btn-solt btn-solt-yellow"><i data-lucide="shield" style="width:14px; height:14px;"></i> Aktifkan ONU</button>
                </form>
            <?php else: ?>
                <form action="action/onu-state.php" method="POST" onsubmit="return confirm('Apakah Anda yakin ingin menonaktifkan ONT ini?');" style="margin:0;">
                    <input type="hidden" name="id" value="<?php echo (int)$onu['id']; ?>">
                    <input type="hidden" name="action" value="disable">
                    <button type="submit" class="btn-solt btn-solt-yellow"><i data-lucide="shield-alert" style="width:14px; height:14px;"></i> Nonaktifkan ONU</button>
                </form>
            <?php endif; ?>
            <form action="action/delete-onu.php" method="POST" onsubmit="return confirm('Apakah Anda yakin ingin menghapus pelanggan ini dari OLT?');" style="margin:0;">
                <input type="hidden" name="id" value="<?php echo (int)$onu['id']; ?>">
                <button type="submit" class="btn-solt btn-solt-red"><i data-lucide="trash-2" style="width:14px; height:14px;"></i> Hapus</button>
            </form>
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
    function signalColor(dBm) {
        if (dBm >= -25) return 'var(--color-success)';
        if (dBm >= -28) return 'var(--color-amber)';
        if (dBm >= -30) return 'var(--color-orange)';
        return 'var(--color-danger)';
    }

    document.addEventListener('DOMContentLoaded', () => {
        const onuId = "<?php echo (int)$onu['id']; ?>";
        window.currentWanMode = "<?php echo htmlspecialchars($onu['wan_mode'] ?: 'Setup via ONU webpage'); ?>";

        // Theme-aware chart colors
        const _cs = getComputedStyle(document.documentElement);
        const _chartText = _cs.getPropertyValue('--text-muted').trim() || '#9ca3af';
        const _chartGrid = document.documentElement.classList.contains('light-mode')
            ? 'rgba(0, 0, 0, 0.08)' : 'rgba(255, 255, 255, 0.05)';

        // Setup Charts
        const ctxSignal = document.getElementById('signalChart').getContext('2d');
        const ctxTraffic = document.getElementById('trafficChart').getContext('2d');

        const signalChart = new Chart(ctxSignal, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($hist_labels); ?>,
                datasets: [{
                    label: 'Rx Signal ONU (dBm)',
                    data: <?php echo json_encode($hist_signal); ?>,
                    borderColor: '#06b6d4',
                    backgroundColor: 'rgba(6, 182, 212, 0.1)',
                    fill: true,
                    tension: 0.4
                }, {
                    label: 'Rx Signal OLT (dBm)',
                    data: <?php echo json_encode($hist_signal_olt); ?>,
                    borderColor: '#a855f7',
                    backgroundColor: 'rgba(168, 85, 247, 0.1)',
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { labels: { color: _chartText } } },
                scales: {
                    x: { grid: { color: _chartGrid }, ticks: { color: _chartText } },
                    y: { grid: { color: _chartGrid }, ticks: { color: _chartText }, suggestedMin: -35, suggestedMax: -15 }
                }
            }
        });

        const trafficChart = new Chart(ctxTraffic, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($hist_labels); ?>,
                datasets: [
                    {
                        label: 'Upload (Mbps)',
                        data: <?php echo json_encode($hist_upload); ?>,
                        borderColor: '#f59e0b',
                        backgroundColor: 'rgba(245, 158, 11, 0.1)',
                        fill: true,
                        tension: 0.4
                    },
                    {
                        label: 'Download (Mbps)',
                        data: <?php echo json_encode($hist_download); ?>,
                        borderColor: '#3081d1',
                        backgroundColor: 'rgba(59, 130, 246, 0.1)',
                        fill: true,
                        tension: 0.4
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { labels: { color: _chartText } } },
                scales: {
                    x: { grid: { color: _chartGrid }, ticks: { color: _chartText } },
                    y: { grid: { color: _chartGrid }, ticks: { color: _chartText } }
                }
            }
        });

        let countdownSec = 15;
        const countdownEl = document.getElementById('auto-refresh-countdown');
        function isLiveMode() {
            const btn = document.getElementById('btn-live');
            return btn && btn.dataset.live === 'on';
        }

        // Panggilan pertama pakai full=1: backend tarik config lengkap (VLAN, PPPoE,
        // mode) dari driver lalu simpan ke DB. Polling berikutnya mode ringan,
        // hanya sinyal + IP, supaya OLT tidak dibebani tiap 15 detik.
        let needFullSync = true;
        let lastTraffic = null; // { t, rx, tx } — sample sebelumnya utk hitung delta Mbps
        let maxUpSpeed = 0, maxDownSpeed = 0;

        function fetchRealtimeData() {
            countdownSec = isLiveMode() ? 3 : 15;
            if (countdownEl) {
                countdownEl.textContent = countdownSec;
            }

            const url = `action/onu-data.php?id=${onuId}` + (needFullSync ? '&full=1' : '&mode=snmp');
            needFullSync = false;

            fetch(url)
                .then(res => res.json())
                .then(data => {
                    const banner = document.getElementById('sync-loading-banner');
                    if (data.success) {
                        if (banner) {
                            banner.classList.add('fade-out');
                        }

                        const statusBadge = document.getElementById('detail-status');
                        const statusDot = document.getElementById('detail-status-dot');
                        const rxOnuText = document.getElementById('detail-rx-onu');
                        const rxOltText = document.getElementById('detail-rx-olt');
                        const pppoeContainer = document.getElementById('detail-pppoe-ip-wrapper');

                        statusBadge.textContent = data.status.charAt(0).toUpperCase() + data.status.slice(1);
                        if (statusDot) statusDot.className = `detail-status-dot ${data.status}`;

                        const lastDownEl = document.getElementById('detail-last-down');
                        if (lastDownEl) lastDownEl.textContent = data.last_down_cause || '-';

                        // Update Identitas Pelanggan secara dinamis
                        const setChipVal = (id, val, fallback = 'Belum diisi') => {
                            const el = document.getElementById(id);
                            if (el) {
                                const cleanVal = (val && val !== 'None' && val !== 'N/A' && val !== '') ? val : fallback;
                                el.innerHTML = `<i data-lucide="pencil" style="width:11px;height:11px;"></i> ${esc(cleanVal)}`;
                            }
                        };

                        setChipVal('detail-name', data.name, 'Belum diisi');
                        setChipVal('detail-zone', data.zone, 'Belum diisi');
                        // splitter: PHP parses from description field, don't overwrite with raw DB value
                        setChipVal('detail-splitter', data.splitter, 'Belum diisi');
                        const splitterEl = document.getElementById('detail-splitter');
                        if (splitterEl) splitterEl.title = (data.splitter && data.splitter !== 'None') ? data.splitter : 'Belum diisi';
                        setChipVal('detail-address', data.address, 'Belum diisi');
                        setChipVal('detail-contact', data.contact, 'Belum diisi');

                        const extIdEl = document.getElementById('detail-external-id');
                        if (extIdEl) setChipVal('detail-external-id', data.external_id, 'Belum diisi');
                        const odbPortEl = document.getElementById('detail-odb-port');
                        if (odbPortEl) setChipVal('detail-odb-port', data.odb_port, 'Belum diisi');

                        const mgmtIpEl = document.getElementById('detail-mgmt-ip');
                        if (mgmtIpEl && data.mgmt_ip !== undefined) {
                            const mgmtMode = data.mgmt_ip_mode || 'Inactive';
                            const mgmtIpDisplay = data.mgmt_ip || data.tr069_ip || null;
                            let mgmtHtml = '<i data-lucide="pencil" style="width:11px;height:11px;"></i> ';
                            if (mgmtMode === 'Static' || mgmtMode === 'DHCP') {
                                mgmtHtml += `<strong>${esc(mgmtMode)}</strong> `;
                                if (mgmtIpDisplay) {
                                    mgmtHtml += `<a href="http://${esc(mgmtIpDisplay)}" target="_blank" class="chip chip-green" style="text-decoration:none;" onclick="event.stopPropagation();"><i data-lucide="external-link" style="width:11px;height:11px;display:inline-block;vertical-align:middle;margin-right:4px;"></i>${esc(mgmtIpDisplay)}</a>`;
                                } else {
                                    mgmtHtml += `<span class="chip chip-red">Menunggu IP</span>`;
                                }
                            } else {
                                mgmtHtml += `<span style="color:var(--text-muted);">Nonaktif</span>`;
                            }
                            mgmtIpEl.innerHTML = mgmtHtml;
                        }

                        const distEl = document.getElementById('detail-distance');
                        if (distEl && data.distance_m !== undefined && data.distance_m !== null) distEl.textContent = `(${data.distance_m}m)`;

                        // Update speed profile cells if available from sync
                        const dlCell = document.getElementById('speed-download-cell');
                        const ulCell = document.getElementById('speed-upload-cell');
                        if (dlCell && data.download_profile) dlCell.textContent = data.download_profile;
                        if (ulCell && data.upload_profile) ulCell.textContent = data.upload_profile;

                        const onuModeEl = document.getElementById('detail-onu-mode');
                        if (onuModeEl) {
                            onuModeEl.innerHTML = `<i data-lucide="pencil" style="width:11px;height:11px;"></i> ${esc(data.onu_mode || 'Routing')} (${esc(data.config_method || 'OMCI')}) &mdash; WAN ${esc(data.vlan || 'N/A')}`;
                        }
                        // Update WAN setup label (span wraps label text only, pppoe wrapper is separate)
                        const wanLabelEl = document.getElementById('detail-wan-label');
                        if (wanLabelEl && data.wan_mode) {
                            wanLabelEl.textContent = data.wan_mode === 'Static' ? 'Static IP' : data.wan_mode;
                            window.currentWanMode = data.wan_mode;
                        }
                        // WAN Setup: PHP detects PPPoE from running-config, don't overwrite with raw DB value

                        // VLAN + kredensial PPPoE (terisi setelah sync full pertama)
                        const vlanChip = document.getElementById('detail-vlan');
                        if (vlanChip && data.vlan) vlanChip.textContent = data.vlan;
                        else if (vlanChip) vlanChip.textContent = 'Belum diisi';

                        // Update Akses Remote WAN
                        const wraEl = document.getElementById('detail-wan-remote');
                        if (wraEl && data.wan_remote_access !== undefined) {
                            if (data.wan_remote_access === 'yes') {
                                wraEl.innerHTML = '<span style="color:var(--color-success); font-weight:600;">Aktif</span>';
                            } else {
                                wraEl.innerHTML = '<span style="color:var(--text-muted);">Nonaktif</span>';
                            }
                        }

                        const userChip = document.getElementById('pppoe-user-text');
                        if (userChip && data.pppoe_username) {
                            userChip.dataset.username = data.pppoe_username;
                            if (userChip.dataset.visible !== 'true') {
                                const v = document.getElementById('pppoe-user-value');
                                if (v) v.textContent = '**********';
                            }
                        }
                        const passChip = document.getElementById('pppoe-pass-text');
                        if (passChip && data.pppoe_password) {
                            passChip.dataset.password = data.pppoe_password;
                            if (passChip.dataset.visible !== 'true') {
                                const v = document.getElementById('pppoe-pass-value');
                                if (v) v.textContent = '**********';
                            }
                        }

                        // Update PPPoE IP (only meaningful for PPPoE mode)
                        if (pppoeContainer) {
                            if (data.wan_mode !== 'PPPoE') {
                                pppoeContainer.innerHTML = '<span style="color:var(--text-muted);">-</span>';
                            } else if (data.pppoe_ip && data.pppoe_ip !== 'N/A' && data.pppoe_ip !== '0.0.0.0' && data.pppoe_ip !== '') {
                                pppoeContainer.innerHTML = `
                                    <a href="http://${esc(data.pppoe_ip)}" target="_blank" class="chip chip-green" style="text-decoration: none;" onclick="event.stopPropagation();">
                                        <i data-lucide="external-link" style="width:11px; height:11px; display:inline-block; vertical-align:middle; margin-right:4px;"></i>
                                        ${esc(data.pppoe_ip)}
                                    </a>`;
                            } else {
                                pppoeContainer.innerHTML = `
                                    <span class="chip chip-red">No IP</span>`;
                            }
                            lucide.createIcons();
                        }

                        if (data.status === 'offline') {
                            rxOnuText.textContent = 'N/A';
                            rxOltText.textContent = 'N/A';
                            rxOnuText.style.color = 'var(--color-danger)';
                            rxOltText.style.color = 'var(--color-danger)';
                            // Push null to charts so they keep advancing instead of freezing
                            const timeStrOff = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
                            if (signalChart.data.labels.length > 30) {
                                signalChart.data.labels.shift();
                                signalChart.data.datasets[0].data.shift();
                                signalChart.data.datasets[1].data.shift();
                            }
                            signalChart.data.labels.push(timeStrOff);
                            signalChart.data.datasets[0].data.push(null);
                            signalChart.data.datasets[1].data.push(null);
                            signalChart.update();
                            if (trafficChart.data.labels.length > 30) {
                                trafficChart.data.labels.shift();
                                trafficChart.data.datasets[0].data.shift();
                                trafficChart.data.datasets[1].data.shift();
                            }
                            trafficChart.data.labels.push(timeStrOff);
                            trafficChart.data.datasets[0].data.push(null);
                            trafficChart.data.datasets[1].data.push(null);
                            trafficChart.update();
                            // Reset live stats
                            const ulCurrOff = document.getElementById('stat-ul-curr');
                            const dlCurrOff = document.getElementById('stat-dl-curr');
                            if (ulCurrOff) ulCurrOff.textContent = '0.00 Mbps';
                            if (dlCurrOff) dlCurrOff.textContent = '0.00 Mbps';
                            const sigCurrOff = document.getElementById('stat-sig-curr');
                            const sigOltCurrOff = document.getElementById('stat-sig-olt-curr');
                            if (sigCurrOff) sigCurrOff.textContent = 'N/A';
                            if (sigOltCurrOff) sigOltCurrOff.textContent = 'N/A';
                            lastTraffic = null; // reset traffic delta so next online reading starts clean
                        } else {
                            rxOnuText.textContent = data.rx_onu !== 'N/A' ? `${data.rx_onu} dBm` : 'N/A';
                            // Only update rx_olt if we have a real value (SNMP mode doesn't provide it)
                            if (data.rx_olt !== 'N/A' && data.rx_olt !== null) {
                                rxOltText.textContent = `${data.rx_olt} dBm`;
                            }

                            const sigCurrEl = document.getElementById('stat-sig-curr');
                            const sigOltCurrEl = document.getElementById('stat-sig-olt-curr');
                            if (sigCurrEl && data.rx_onu !== 'N/A' && data.rx_onu !== null) sigCurrEl.textContent = `${data.rx_onu} dBm`;
                            if (sigOltCurrEl && data.rx_olt !== 'N/A' && data.rx_olt !== null) sigOltCurrEl.textContent = `${data.rx_olt} dBm`;

                            if (data.rx_onu !== 'N/A' && data.rx_onu !== null) {
                                const rxVal = parseFloat(data.rx_onu);
                                rxOnuText.style.color = signalColor(rxVal);
                            }
                            
                            const rxOltNum = parseFloat(data.rx_olt);
                            if (!isNaN(rxOltNum)) {
                                rxOltText.style.color = signalColor(rxOltNum);
                            }

                            // Update signal chart
                            const timeStr = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
                            const rxValForChart = (data.rx_onu !== 'N/A' && data.rx_onu !== null) ? parseFloat(data.rx_onu) : null;
                            
                            if (signalChart.data.labels.length > 30) {
                                signalChart.data.labels.shift();
                                signalChart.data.datasets[0].data.shift();
                                signalChart.data.datasets[1].data.shift();
                            }
                            signalChart.data.labels.push(timeStr);
                            signalChart.data.datasets[0].data.push(rxValForChart);
                            const rxOltVal = data.rx_olt !== undefined && data.rx_olt !== 'N/A' ? parseFloat(data.rx_olt) : null;
                            signalChart.data.datasets[1].data.push(rxOltVal);
                            signalChart.update();

                            // Update traffic chart dari SNMP counter real (delta bytes / delta detik)
                            // rx_octets = traffic diterima OLT DARI ONU = UPLOAD pelanggan (kecil)
                            // tx_octets = traffic dikirim OLT KE ONU = DOWNLOAD pelanggan (besar)
                            // Verifikasi silang dgn OID standar ifInOctets/ifOutOctets per PON-port:
                            // ifIn (upload aggregate) selalu jauh lebih kecil dari ifOut (download aggregate),
                            // rasio sama persis dgn rx_octets/tx_octets per-ONU di sini.
                            const nowMs = Date.now();
                            const rxOctets = data.traffic_rx_octets;
                            const txOctets = data.traffic_tx_octets;
                            let downSpeed = 0, upSpeed = 0;
                            if (rxOctets !== null && txOctets !== null && lastTraffic) {
                                const dtSec = (nowMs - lastTraffic.t) / 1000;
                                if (dtSec > 0) {
                                    const rxDiff = rxOctets - lastTraffic.rx;
                                    const txDiff = txOctets - lastTraffic.tx;
                                    if (rxDiff >= 0 && txDiff >= 0) {
                                        upSpeed = (rxDiff * 8 / dtSec / 1e6).toFixed(2);
                                        downSpeed = (txDiff * 8 / dtSec / 1e6).toFixed(2);
                                    }
                                    // ponytail: counter wrap/reset -> speed stays 0 until next clean delta
                                }
                            }
                            if (rxOctets !== null && txOctets !== null) {
                                lastTraffic = { t: nowMs, rx: rxOctets, tx: txOctets };
                            }

                            const rxPktEl = document.getElementById('stat-rx-packets');
                            const txPktEl = document.getElementById('stat-tx-packets');
                            if (rxPktEl) rxPktEl.textContent = data.traffic_rx_packets !== null && data.traffic_rx_packets !== undefined ? Number(data.traffic_rx_packets).toLocaleString('en-US') : '-';
                            if (txPktEl) txPktEl.textContent = data.traffic_tx_packets !== null && data.traffic_tx_packets !== undefined ? Number(data.traffic_tx_packets).toLocaleString('en-US') : '-';

                            const upNum = parseFloat(upSpeed) || 0;
                            const downNum = parseFloat(downSpeed) || 0;
                            if (upNum > maxUpSpeed) maxUpSpeed = upNum;
                            if (downNum > maxDownSpeed) maxDownSpeed = downNum;
                            const ulCurrEl = document.getElementById('stat-ul-curr');
                            const ulMaxEl = document.getElementById('stat-ul-max');
                            const dlCurrEl = document.getElementById('stat-dl-curr');
                            const dlMaxEl = document.getElementById('stat-dl-max');
                            if (ulCurrEl) ulCurrEl.textContent = upNum.toFixed(2) + ' Mbps';
                            if (ulMaxEl) ulMaxEl.textContent = maxUpSpeed.toFixed(2) + ' Mbps';
                            if (dlCurrEl) dlCurrEl.textContent = downNum.toFixed(2) + ' Mbps';
                            if (dlMaxEl) dlMaxEl.textContent = maxDownSpeed.toFixed(2) + ' Mbps';

                            if (trafficChart.data.labels.length > 30) {
                                trafficChart.data.labels.shift();
                                trafficChart.data.datasets[0].data.shift();
                                trafficChart.data.datasets[1].data.shift();
                            }
                            trafficChart.data.labels.push(timeStr);
                            trafficChart.data.datasets[0].data.push(upSpeed);
                            trafficChart.data.datasets[1].data.push(downSpeed);
                            trafficChart.update();
                        }

                        // Re-render lucide icons after DOM updates (setChipVal/innerHTML replaces <i> with new unprocessed elements)
                        if (typeof lucide !== 'undefined') lucide.createIcons();
                    } else {
                        needFullSync = true; // retry full sync on next poll so VLAN/PPPoE/distance aren't stuck
                        if (banner) {
                            banner.style.background = 'rgba(239, 68, 68, 0.1)';
                            banner.style.borderColor = 'rgba(239, 68, 68, 0.2)';
                            banner.style.color = '#fca5a5';
                            banner.innerHTML = `<span>⚠️ Gagal sinkronisasi data dari OLT: ${esc(data.message || 'Error tidak diketahui')}</span>`;
                        }
                    }
                })
                .catch(err => {
                    console.error('Error fetching realtime signal:', err);
                    needFullSync = true; // retry full sync on next poll so VLAN/PPPoE/distance aren't stuck
                    const banner = document.getElementById('sync-loading-banner');
                    if (banner) {
                        banner.style.background = 'rgba(239, 68, 68, 0.1)';
                        banner.style.borderColor = 'rgba(239, 68, 68, 0.2)';
                        banner.style.color = '#fca5a5';
                        banner.innerHTML = '<span>⚠️ Terjadi kesalahan jaringan saat sinkronisasi data dari OLT.</span>';
                    }
                });
        }

        function updateCountdown() {
            countdownSec--;
            if (countdownSec <= 0) {
                fetchRealtimeData();
            } else {
                if (countdownEl) {
                    countdownEl.textContent = countdownSec;
                }
            }
        }

        // Jalankan polling asinkron pertama kali dan jalankan hitung mundur 1 detik sekali
        fetchRealtimeData();
        const countdownInterval = setInterval(updateCountdown, 1000);

        // Bersihkan interval jika berpindah halaman
        window.addEventListener('beforeunload', () => {
            clearInterval(countdownInterval);
        });

        document.getElementById('btn-onu-refresh-signal').addEventListener('click', fetchRealtimeData);

        // LIVE toggle — must be here (first DOMContentLoaded scope) because it
        // directly accesses countdownSec and fetchRealtimeData which are
        // block-scoped to this callback. Placing this handler in a separate
        // DOMContentLoaded caused ReferenceError on every click.
        const btnLive = document.getElementById('btn-live');
        if (btnLive) {
            btnLive.addEventListener('click', () => {
                const isOn = btnLive.dataset.live === 'on';
                btnLive.dataset.live = isOn ? 'off' : 'on';
                btnLive.innerHTML = `<i data-lucide="refresh-cw" style="width:14px; height:14px;"></i> ${isOn ? 'LIVE!' : 'LIVE! (ON)'}`;
                if (typeof lucide !== 'undefined') lucide.createIcons();
                btnLive.style.opacity = isOn ? '0.7' : '1';
                countdownSec = isOn ? 15 : 3;
                if (!isOn) fetchRealtimeData();
            });
        }

        // Resync config button — trigger full sync without page reload
        const btnResync = document.getElementById('btn-resync-config');
        if (btnResync) {
            btnResync.addEventListener('click', () => {
                needFullSync = true;
                fetchRealtimeData();
            });
        }

        // Dynamic VLAN loader for Update ONU Mode Modal
        const modal = document.getElementById('update-onu-mode-modal');
        let vlanLoaded = false;
        
        if (modal) {
            const observer = new MutationObserver((mutations) => {
                mutations.forEach((mutation) => {
                    if (mutation.attributeName === 'class' && modal.classList.contains('open')) {
                        if (!vlanLoaded) {
                            loadLiveVlans();
                        }
                    }
                });
            });
            observer.observe(modal, { attributes: true });
        }

        async function loadLiveVlans() {
            const selectEl = document.getElementById('modal-vlan-select');
            if (!selectEl) return;
            
            const oltId = "<?php echo (int)$onu['olt_id']; ?>";
            const currentVlan = "<?php echo htmlspecialchars($onu['vlan'] ?: ''); ?>";
            const selectedVal = selectEl.value || currentVlan;
            
            // Tampilkan status loading ringan tanpa merusak pilihan sebelumnya
            const loadingOpt = document.createElement('option');
            loadingOpt.value = "";
            loadingOpt.textContent = "⏳ Memuat VLAN terbaru dari OLT...";
            loadingOpt.disabled = true;
            selectEl.insertBefore(loadingOpt, selectEl.firstChild);
            
            try {
                const response = await fetch(`action/vlan.php?action=list&olt_id=${oltId}`);
                if (!response.ok) throw new Error('Network response not ok');
                const res = await response.json();
                
                if (res.success && res.vlans && res.vlans.length > 0) {
                    vlanLoaded = true;
                    selectEl.innerHTML = '';
                    
                    res.vlans.forEach((v) => {
                        const vid = (typeof v === 'object' && v !== null) ? v.id : v;
                        const opt = document.createElement('option');
                        opt.value = vid;
                        const nameStr = (typeof v === 'object' && v !== null && v.description) ? ` - ${v.description}` : '';
                        opt.textContent = `${vid}${nameStr}`;
                        
                        if (String(vid) === String(selectedVal)) {
                            opt.selected = true;
                        }
                        selectEl.appendChild(opt);
                    });
                    
                    // Pastikan opsi yang terpilih sebelumnya tetap ada di list
                    let hasSelected = Array.from(selectEl.options).some(opt => opt.value === String(selectedVal));
                    if (!hasSelected && selectedVal) {
                        const opt = document.createElement('option');
                        opt.value = selectedVal;
                        opt.textContent = selectedVal;
                        opt.selected = true;
                        selectEl.appendChild(opt);
                    }
                } else {
                    loadingOpt.textContent = "⚠️ Gagal memuat VLAN langsung dari OLT.";
                }
            } catch (err) {
                console.error("[SmartOLT] Failed to fetch live VLANs from OLT:", err);
                loadingOpt.textContent = "⚠️ Gagal memuat VLAN (kesalahan jaringan).";
            }
        }
    });
</script>

<?php
// Ambil daftar VLAN unik yang tercatat pada database cache lokal untuk OLT ini
$stmt_vlans = $pdo->prepare("SELECT DISTINCT vlan FROM onus WHERE olt_id = ? AND vlan IS NOT NULL AND vlan > 0 ORDER BY vlan ASC");
$stmt_vlans->execute([$onu['olt_id']]);
$cached_vlans = $stmt_vlans->fetchAll(PDO::FETCH_COLUMN);

// Pastikan VLAN milik ONU ini ada di array list
if ($onu['vlan'] && !in_array($onu['vlan'], $cached_vlans)) {
    $cached_vlans[] = $onu['vlan'];
}
sort($cached_vlans);
// ponytail: no hardcoded VLAN fallback — show only real cached VLANs + ONU's own

// VLAN management (type='management') — untuk IP Manajemen popup
$stmt_mgmt_vlans = $pdo->prepare("SELECT vlan_id, description FROM olt_vlans WHERE olt_id = ? AND type = 'management' ORDER BY vlan_id ASC");
$stmt_mgmt_vlans->execute([$onu['olt_id']]);
$management_vlans = $stmt_mgmt_vlans->fetchAll(PDO::FETCH_ASSOC);

// TR609 profiles
$tr069_profiles = tr069_get_profiles($pdo);
?>

<!-- Modal Update ONU Mode (WAN / PPPoE Config) -->
<div class="modal" id="update-onu-mode-modal">
    <div class="modal-content" style="max-width: 650px; width: 90%; border-radius: 6px; box-shadow: 0 4px 20px rgba(0,0,0,0.15);">
        <div class="modal-header" style="padding: 15px 24px; display: flex; justify-content: space-between; align-items: center;">
            <h3 style="margin:0; font-size:1.4rem; font-weight:700; color:var(--text-main);">Update Mode ONU</h3>
            <button type="button" class="close-btn" onclick="document.getElementById('update-onu-mode-modal').classList.remove('open')" style="background:none; border:none; font-size:1.5rem; cursor:pointer; color:var(--text-muted); line-height: 1;">&times;</button>
        </div>
        <form action="action/update-onu-mode.php" method="POST">
            <input type="hidden" name="onu_id" value="<?php echo (int)$onu['id']; ?>">
            <input type="hidden" name="change_scope" value="wan_config">
            <div class="modal-body" style="padding: 24px; font-size:0.95rem; color:var(--text-main);">
                
                <table style="width:100%; border-collapse:collapse;">
                    <!-- WAN VLAN-ID -->
                    <tr style="height: 55px;">
                        <td style="width: 160px; font-weight: 600; color:var(--text-main); vertical-align: middle; padding: 8px 0;">WAN VLAN-ID</td>
                        <td style="vertical-align: middle; padding: 8px 0;">
                            <select name="vlan_service" id="modal-vlan-select" style="width:100%; max-width: 320px; padding:8px 12px; border:1px solid var(--border-color); border-radius:4px; background:var(--bg-tertiary); font-size:0.9rem; color:var(--text-main);">
                                <?php foreach ($cached_vlans as $cv): ?>
                                    <option value="<?php echo (int)$cv; ?>" <?php echo (int)$onu['vlan'] == (int)$cv ? 'selected' : ''; ?>><?php echo (int)$cv; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <td></td>
                        <td style="padding: 0 0 15px 0;">
                            <small style="color: var(--text-accent); display: block; line-height: 1.4; font-size: 0.85rem;">
                                Setelah mengubah WAN VLAN-ID, periksa pengaturan Ethernet ports dan sesuaikan VLAN sesuai kebutuhan.
                            </small>
                        </td>
                    </tr>

                    <!-- ONU mode -->
                    <tr style="height: 45px;">
                        <td style="font-weight: 600; color:var(--text-main); vertical-align: middle; padding: 8px 0;">Mode ONU</td>
                        <td style="vertical-align: middle; padding: 8px 0; display: flex; gap: 15px; align-items: center;">
                            <label style="display:inline-flex; align-items:center; gap:6px; cursor:pointer;">
                                <input type="radio" name="onu_mode" value="Routing" <?php echo ($onu['onu_mode'] === 'Routing' || empty($onu['onu_mode'])) ? 'checked' : ''; ?>> Routing
                            </label>
                            <label style="display:inline-flex; align-items:center; gap:6px; cursor:pointer;">
                                <input type="radio" name="onu_mode" value="Bridging" <?php echo $onu['onu_mode'] === 'Bridging' ? 'checked' : ''; ?>> Bridging
                            </label>
                        </td>
                    </tr>

                    <!-- WAN mode -->
                    <tr>
                        <td style="font-weight: 600; color:var(--text-main); vertical-align: top; padding: 12px 0;">Mode WAN</td>
                        <td style="padding: 8px 0;">
                            <div style="margin-bottom: 8px;">
                                <label style="display:inline-flex; align-items:center; gap:6px; cursor:pointer;">
                                    <input type="radio" name="wan_mode" class="wan-mode-radio" value="Setup via ONU webpage" <?php echo ($onu['wan_mode'] === 'Setup via ONU webpage' || empty($onu['wan_mode'])) ? 'checked' : ''; ?>> Setup via ONU webpage
                                </label>
                            </div>
                            <div style="font-size: 0.85rem; color: var(--text-muted); font-weight: 500; margin-bottom: 8px; margin-left: 2px;">
                                Pengaturan untuk ONT yang kompatibel:
                            </div>
                            <div style="display:flex; gap:15px; align-items:center; margin-left: 2px;">
                                <label style="display:inline-flex; align-items:center; gap:6px; cursor:pointer;">
                                    <input type="radio" name="wan_mode" class="wan-mode-radio" value="DHCP" <?php echo $onu['wan_mode'] === 'DHCP' ? 'checked' : ''; ?>> DHCP
                                </label>
                                <label style="display:inline-flex; align-items:center; gap:6px; cursor:pointer;">
                                    <input type="radio" name="wan_mode" class="wan-mode-radio" value="Static" <?php echo $onu['wan_mode'] === 'Static' ? 'checked' : ''; ?>> Static IP
                                </label>
                                <label style="display:inline-flex; align-items:center; gap:6px; cursor:pointer;">
                                    <input type="radio" name="wan_mode" class="wan-mode-radio" value="PPPoE" <?php echo $onu['wan_mode'] === 'PPPoE' ? 'checked' : ''; ?>> PPPoE
                                </label>
                            </div>
                        </td>
                    </tr>

                    <!-- Config method -->
                    <tr style="height: 45px;">
                        <td style="font-weight: 600; color:var(--text-main); vertical-align: middle; padding: 8px 0;">Metode Konfigurasi</td>
                        <td style="vertical-align: middle; padding: 8px 0; display: flex; gap: 15px; align-items: center;">
                            <?php $tr069_active = (($onu['tr069_profile'] ?? '') === 'ACS-Smartolt'); ?>
                            <label style="display:inline-flex; align-items:center; gap:6px; cursor:pointer;">
                                <input type="radio" name="config_method" value="OMCI" <?php echo ($onu['config_method'] === 'OMCI' || empty($onu['config_method'])) ? 'checked' : ''; ?>> OMCI
                            </label>
                            <label style="display:inline-flex; align-items:center; gap:6px; cursor:<?php echo $tr069_active ? 'pointer' : 'not-allowed'; ?>; <?php echo $tr069_active ? '' : 'opacity:0.5;'; ?>" title="<?php echo $tr069_active ? '' : 'Set TR609 Profile ke ACS-Smartolt dulu untuk mengaktifkan opsi ini'; ?>">
                                <input type="radio" name="config_method" value="TR069" <?php echo $tr069_active ? '' : 'disabled'; ?> <?php echo ($onu['config_method'] === 'TR069' && $tr069_active) ? 'checked' : ''; ?>> TR069<?php echo $tr069_active ? '' : ' - Tidak Aktif'; ?>
                            </label>
                        </td>
                    </tr>

                    <!-- Username (PPPoE only) -->
                    <tr id="row-pppoe-user" style="height: 50px;">
                        <td style="font-weight: 600; color:var(--text-main); vertical-align: middle; padding: 6px 0;">Nama Pengguna</td>
                        <td style="vertical-align: middle; padding: 6px 0;">
                            <input type="text" name="pppoe_username" value="<?php echo htmlspecialchars($onu['pppoe_username'] ?: ''); ?>" style="width:100%; max-width: 320px; padding:8px 12px; border:1px solid var(--border-color); border-radius:4px; font-size:0.9rem; box-sizing:border-box; color:var(--text-main); background:var(--bg-tertiary);">
                        </td>
                    </tr>

                    <!-- Password (PPPoE only) -->
                    <tr id="row-pppoe-pass" style="height: 50px;">
                        <td style="font-weight: 600; color:var(--text-main); vertical-align: middle; padding: 6px 0;">Kata Sandi</td>
                        <td style="vertical-align: middle; padding: 6px 0;">
                            <input type="password" name="pppoe_password" value="<?php echo htmlspecialchars($onu['pppoe_password'] ?: ''); ?>" style="width:100%; max-width: 320px; padding:8px 12px; border:1px solid var(--border-color); border-radius:4px; font-size:0.9rem; box-sizing:border-box; color:var(--text-main); background:var(--bg-tertiary);">
                        </td>
                    </tr>

                    <!-- IP Address (Static only) -->
                    <tr id="row-static-ip" style="height: 50px;">
                        <td style="font-weight: 600; color:var(--text-main); vertical-align: middle; padding: 6px 0;">Alamat IP</td>
                        <td style="vertical-align: middle; padding: 6px 0;">
                            <input type="text" name="static_ip" placeholder="IP Address" value="<?php echo htmlspecialchars($onu['static_ip'] ?: ''); ?>" style="width:100%; max-width: 320px; padding:8px 12px; border:1px solid var(--border-color); border-radius:4px; font-size:0.9rem; box-sizing:border-box; color:var(--text-main); background:var(--bg-tertiary);">
                        </td>
                    </tr>

                    <!-- Netmask (Static only) -->
                    <tr id="row-static-netmask" style="height: 50px;">
                        <td style="font-weight: 600; color:var(--text-main); vertical-align: middle; padding: 6px 0;">Netmask</td>
                        <td style="vertical-align: middle; padding: 6px 0;">
                            <input type="text" name="static_netmask" placeholder="Netmask" value="<?php echo htmlspecialchars($onu['static_netmask'] ?: ''); ?>" style="width:100%; max-width: 320px; padding:8px 12px; border:1px solid var(--border-color); border-radius:4px; font-size:0.9rem; box-sizing:border-box; color:var(--text-main); background:var(--bg-tertiary);">
                        </td>
                    </tr>

                    <!-- Gateway (Static only) -->
                    <tr id="row-static-gateway" style="height: 50px;">
                        <td style="font-weight: 600; color:var(--text-main); vertical-align: middle; padding: 6px 0;">Gateway</td>
                        <td style="vertical-align: middle; padding: 6px 0;">
                            <input type="text" name="static_gateway" placeholder="Gateway" value="<?php echo htmlspecialchars($onu['static_gateway'] ?: ''); ?>" style="width:100%; max-width: 320px; padding:8px 12px; border:1px solid var(--border-color); border-radius:4px; font-size:0.9rem; box-sizing:border-box; color:var(--text-main); background:var(--bg-tertiary);">
                        </td>
                    </tr>

                    <!-- DNS Primary (Static only) -->
                    <tr id="row-static-dns-primary" style="height: 50px;">
                        <td style="font-weight: 600; color:var(--text-main); vertical-align: middle; padding: 6px 0;">DNS Primer</td>
                        <td style="vertical-align: middle; padding: 6px 0;">
                            <input type="text" name="static_dns_primary" placeholder="DNS Primer" value="<?php echo htmlspecialchars($onu['static_dns_primary'] ?: ''); ?>" style="width:100%; max-width: 320px; padding:8px 12px; border:1px solid var(--border-color); border-radius:4px; font-size:0.9rem; box-sizing:border-box; color:var(--text-main); background:var(--bg-tertiary);">
                        </td>
                    </tr>

                    <!-- DNS Secondary (Static only) -->
                    <tr id="row-static-dns-secondary" style="height: 50px;">
                        <td style="font-weight: 600; color:var(--text-main); vertical-align: middle; padding: 6px 0;">DNS Sekunder</td>
                        <td style="vertical-align: middle; padding: 6px 0;">
                            <input type="text" name="static_dns_secondary" placeholder="DNS Sekunder" value="<?php echo htmlspecialchars($onu['static_dns_secondary'] ?: ''); ?>" style="width:100%; max-width: 320px; padding:8px 12px; border:1px solid var(--border-color); border-radius:4px; font-size:0.9rem; box-sizing:border-box; color:var(--text-main); background:var(--bg-tertiary);">
                        </td>
                    </tr>


                    <!-- Speed Profiles Configuration (sumber: tabel speed_profiles, sama dengan form Otorisasi) -->
                    <tr style="height: 50px;">
                        <td style="font-weight: 600; color:var(--text-main); vertical-align: middle; padding: 6px 0;">Kecepatan Download</td>
                        <td style="vertical-align: middle; padding: 6px 0;">
                            <select name="download_profile" style="width:100%; max-width: 320px; padding:8px 12px; border:1px solid var(--border-color); border-radius:4px; background:var(--bg-tertiary); font-size:0.9rem; color:var(--text-main);">
                                <?php if (empty($sp_download_options)): ?>
                                    <option value="" disabled selected>Belum ada profile di OLT ini</option>
                                <?php else: foreach ($sp_download_options as $p): ?>
                                    <option value="<?php echo htmlspecialchars($p['name']); ?>" <?php echo ($onu['download_profile'] ?? '') === $p['name'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($p['name']); ?> (<?php echo (int)$p['speed_kbps']; ?> kbps)</option>
                                <?php endforeach; endif; ?>
                            </select>
                        </td>
                    </tr>
                    <tr style="height: 50px;">
                        <td style="font-weight: 600; color:var(--text-main); vertical-align: middle; padding: 6px 0;">Kecepatan Upload</td>
                        <td style="vertical-align: middle; padding: 6px 0;">
                            <select name="upload_profile" style="width:100%; max-width: 320px; padding:8px 12px; border:1px solid var(--border-color); border-radius:4px; background:var(--bg-tertiary); font-size:0.9rem; color:var(--text-main);">
                                <?php if (empty($sp_upload_options)): ?>
                                    <option value="" disabled selected>Belum ada profile di OLT ini</option>
                                <?php else: foreach ($sp_upload_options as $p): ?>
                                    <option value="<?php echo htmlspecialchars($p['name']); ?>" <?php echo ($onu['upload_profile'] ?? '') === $p['name'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($p['name']); ?> (<?php echo (int)$p['speed_kbps']; ?> kbps)</option>
                                <?php endforeach; endif; ?>
                            </select>
                        </td>
                    </tr>

                    <!-- WAN remote access -->
                    <tr style="height: 50px;">
                        <td style="font-weight: 600; color:var(--text-main); vertical-align: middle; padding: 6px 0;">Akses Remote WAN</td>
                        <td style="vertical-align: middle; padding: 6px 0;">
                            <select name="wan_remote_access" style="width:100%; max-width: 320px; padding:8px 12px; border:1px solid var(--border-color); border-radius:4px; background:var(--bg-tertiary); font-size:0.9rem; color:var(--text-main);">
                                <option value="yes" <?php echo ($onu['wan_remote_access'] === 'yes') ? 'selected' : ''; ?>>Aktif dari semua jaringan internet</option>
                                <option value="no" <?php echo ($onu['wan_remote_access'] !== 'yes') ? 'selected' : ''; ?>>Nonaktif</option>
                            </select>
                        </td>
                    </tr>
                </table>

            </div>
            <div class="modal-footer" style="padding: 15px 24px; display:flex; justify-content:flex-end; align-items:center; gap:12px;">
                <button type="button" onclick="document.getElementById('update-onu-mode-modal').classList.remove('open')" style="background:none; border:none; color:var(--text-accent); font-weight: 500; cursor:pointer; font-size:0.95rem; padding: 8px 12px;">Tutup</button>
                <button type="submit" class="btn btn-success" style="padding:8px 24px;">Simpan</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Edit ONU Type -->
<div class="modal" id="edit-onu-type-modal">
    <div class="modal-content" style="max-width: 450px; width: 90%; border-radius: 6px; box-shadow: 0 4px 20px rgba(0,0,0,0.15);">
        <div class="modal-header" style="padding: 15px 24px; display: flex; justify-content: space-between; align-items: center;">
            <h3 style="margin:0; font-size:1.2rem; font-weight:700; color:var(--text-main);">Edit Tipe ONU</h3>
            <button type="button" class="close-btn" onclick="document.getElementById('edit-onu-type-modal').classList.remove('open')" style="background:none; border:none; font-size:1.5rem; cursor:pointer; color:var(--text-muted); line-height: 1;">&times;</button>
        </div>
        <form action="action/update-onu-mode.php" method="POST">
            <input type="hidden" name="onu_id" value="<?php echo (int)$onu['id']; ?>">
            <input type="hidden" name="change_scope" value="onu_type">
            <div class="modal-body" style="padding: 24px; font-size:0.95rem; color:var(--text-main);">
                <div style="margin-bottom: 15px;">
                    <label style="font-weight: 600; display: block; margin-bottom: 8px;">Tipe ONU</label>
                    <select name="onu_type" style="width:100%; padding:8px 12px; border:1px solid var(--border-color); border-radius:4px; font-size:0.9rem; box-sizing:border-box; color:var(--text-main); background:var(--bg-tertiary);">
                        <?php
                        $curType = $onu['onu_type'] ?: 'ALL-ONT';
                        $typeOpts = $pdo->query("SELECT name FROM onu_types ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
                        if (!in_array($curType, $typeOpts)) $typeOpts[] = $curType;
                        foreach ($typeOpts as $t): ?>
                        <option value="<?php echo htmlspecialchars($t); ?>" <?php echo $t === $curType ? 'selected' : ''; ?>><?php echo htmlspecialchars($t); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer" style="padding: 15px 24px; display:flex; justify-content:flex-end; align-items:center; gap:12px;">
                <button type="button" onclick="document.getElementById('edit-onu-type-modal').classList.remove('open')" style="background:none; border:none; color:var(--text-accent); font-weight: 500; cursor:pointer; font-size:0.95rem; padding: 8px 12px;">Tutup</button>
                <button type="submit" class="btn btn-success" style="padding:8px 24px;">Simpan</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Edit Config Preset -->
<div class="modal" id="edit-config-preset-modal">
    <div class="modal-content" style="max-width: 450px; width: 90%; border-radius: 6px; box-shadow: 0 4px 20px rgba(0,0,0,0.15);">
        <div class="modal-header" style="padding: 15px 24px; display: flex; justify-content: space-between; align-items: center;">
            <h3 style="margin:0; font-size:1.2rem; font-weight:700; color:var(--text-main);">Edit Preset Konfigurasi</h3>
            <button type="button" class="close-btn" onclick="document.getElementById('edit-config-preset-modal').classList.remove('open')" style="background:none; border:none; font-size:1.5rem; cursor:pointer; color:var(--text-muted); line-height: 1;">&times;</button>
        </div>
        <form action="action/update-onu-mode.php" method="POST">
            <input type="hidden" name="onu_id" value="<?php echo (int)$onu['id']; ?>">
            <input type="hidden" name="change_scope" value="preset">
            <div class="modal-body" style="padding: 24px; font-size:0.95rem; color:var(--text-main);">
                <div style="margin-bottom: 15px;">
                    <label style="font-weight: 600; display: block; margin-bottom: 8px;">Preset Konfigurasi</label>
                    <select name="config_preset" style="width:100%; padding:8px 12px; border:1px solid var(--border-color); border-radius:4px; font-size:0.9rem; box-sizing:border-box; color:var(--text-main); background:var(--bg-tertiary);">
                        <option value="">Tidak ada</option>
                        <?php
                        $curPreset = $onu['config_preset'] ?? '';
                        $presetOpts = $pdo->query("SELECT name FROM onu_presets ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
                        if ($curPreset && !in_array($curPreset, $presetOpts)) $presetOpts[] = $curPreset;
                        foreach ($presetOpts as $p): ?>
                        <option value="<?php echo htmlspecialchars($p); ?>" <?php echo $p === $curPreset ? 'selected' : ''; ?>><?php echo htmlspecialchars($p); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer" style="padding: 15px 24px; display:flex; justify-content:flex-end; align-items:center; gap:12px;">
                <button type="button" onclick="document.getElementById('edit-config-preset-modal').classList.remove('open')" style="background:none; border:none; color:var(--text-accent); font-weight: 500; cursor:pointer; font-size:0.95rem; padding: 8px 12px;">Tutup</button>
                <button type="submit" class="btn btn-success" style="padding:8px 24px;">Simpan</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Terpadu: Edit Identitas & Lokasi ONU -->
<div class="modal" id="edit-identity-modal">
    <div class="modal-content" style="max-width: 560px; width: 95%;">
        <div class="modal-header">
            <div style="display:flex; align-items:center; gap:10px;">
                <i data-lucide="user-cog" style="width:18px;height:18px; color:var(--text-accent);"></i>
                <h3 style="margin:0; font-size:1.1rem; font-weight:600;">Edit Identitas & Lokasi ONU</h3>
            </div>
            <button type="button" class="close-btn" onclick="document.getElementById('edit-identity-modal').classList.remove('open')">&times;</button>
        </div>
        <form action="action/update-onu-mode.php" method="POST">
            <input type="hidden" name="onu_id" value="<?php echo (int)$onu['id']; ?>">
            <input type="hidden" name="change_scope" value="identity">
            <div class="modal-body modal-body-grid" style="padding: 20px 24px; display:grid; grid-template-columns:1fr 1fr; gap:14px;">

                <div>
                    <label class="modal-label">Nama Pelanggan <span style="color:var(--color-danger);">*</span></label>
                    <input type="text" name="name" class="modal-input" value="<?php echo htmlspecialchars(($parsed_desc['clean_name'] && strtolower($parsed_desc['clean_name']) !== 'none') ? $parsed_desc['clean_name'] : ''); ?>" required placeholder="Nama pelanggan">
                </div>

                <div>
                    <label class="modal-label">Kontak (HP/WA)</label>
                    <input type="text" name="contact" class="modal-input" value="<?php echo htmlspecialchars(($parsed_desc['contact'] && strtolower($parsed_desc['contact']) !== 'none') ? $parsed_desc['contact'] : ''); ?>" placeholder="Nomor HP/WA">
                </div>

                <div>
                    <label class="modal-label">Zone</label>
                    <select name="zone" id="identity-zone-select" class="modal-input">
                        <?php
                        $curZone = ($parsed_desc['zone'] && strtolower($parsed_desc['zone']) !== 'none') ? $parsed_desc['zone'] : '';
                        $zoneOpts = $pdo->query("SELECT DISTINCT zone FROM splitters WHERE zone IS NOT NULL AND zone != '' ORDER BY zone")->fetchAll(PDO::FETCH_COLUMN);
                        if ($curZone && !in_array($curZone, $zoneOpts)) $zoneOpts[] = $curZone;
                        foreach ($zoneOpts as $z): ?>
                        <option value="<?php echo htmlspecialchars($z); ?>" <?php echo $z === $curZone ? 'selected' : ''; ?>><?php echo htmlspecialchars($z); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="modal-label">ODB (Splitter)</label>
                    <select name="splitter" id="identity-splitter-select" class="modal-input">
                        <option value="<?php echo htmlspecialchars(($parsed_desc['splitter'] && strtolower($parsed_desc['splitter']) !== 'none') ? $parsed_desc['splitter'] : ''); ?>" selected><?php echo htmlspecialchars(($parsed_desc['splitter'] && strtolower($parsed_desc['splitter']) !== 'none') ? $parsed_desc['splitter'] : '- Pilih Zone dulu -'); ?></option>
                    </select>
                </div>

                <div>
                    <label class="modal-label">ODB Port</label>
                    <input type="text" name="odb_port" class="modal-input" value="<?php echo htmlspecialchars($onu['odb_port'] ?? ''); ?>" placeholder="Port ODB">
                </div>

                <div>
                    <label class="modal-label">Alamat / Keterangan</label>
                    <input type="text" name="address" class="modal-input" value="<?php echo htmlspecialchars(($parsed_desc['address'] && strtolower($parsed_desc['address']) !== 'none') ? $parsed_desc['address'] : ''); ?>" placeholder="Alamat pelanggan">
                </div>

                <div>
                    <label class="modal-label">ONU External ID</label>
                    <input type="text" name="external_id" class="modal-input" value="<?php echo htmlspecialchars($parsed_desc['external_id'] ?? ''); ?>" placeholder="Opsional">
                </div>

                <div style="display:flex; gap:6px; align-items:flex-end;">
                    <div style="flex:1;">
                        <label class="modal-label">Latitude</label>
                        <input type="text" name="latitude" id="identity-latitude" class="modal-input" value="<?php echo htmlspecialchars($onu['latitude'] ?? ''); ?>" placeholder="-x.xxxxxx" pattern="^-?[0-9]{1,3}\.[0-9]{1,10}$">
                    </div>
                    <button type="button" class="btn-icon-square" id="btn-identity-gps" title="Gunakan lokasi saya" style="margin-bottom:0;"><i data-lucide="map-pin" style="width:16px;height:16px;"></i></button>
                </div>

                <div>
                    <label class="modal-label">Longitude</label>
                    <input type="text" name="longitude" id="identity-longitude" class="modal-input" value="<?php echo htmlspecialchars($onu['longitude'] ?? ''); ?>" placeholder="xxx.xxxxxx" pattern="^-?[0-9]{1,3}\.[0-9]{1,10}$">
                </div>

            </div>
            <div class="modal-footer">
                <button type="button" onclick="document.getElementById('edit-identity-modal').classList.remove('open')" class="btn btn-secondary">Batal</button>
                <button type="submit" class="btn btn-primary"><i data-lucide="save" style="width:14px;height:14px;"></i> Simpan</button>
            </div>
        </form>
    </div>
</div>


<script>
    document.addEventListener('DOMContentLoaded', () => {
        const onuId = "<?php echo (int)$onu['id']; ?>";

        // Mode WAN: tampilkan field yang relevan sesuai radio dipilih
        function toggleWanModeFields() {
            const checked = document.querySelector('input[name="wan_mode"]:checked');
            const mode = checked ? checked.value : '';
            const rowPppoeUser = document.getElementById('row-pppoe-user');
            const rowPppoePass = document.getElementById('row-pppoe-pass');
            const rowStaticIp = document.getElementById('row-static-ip');
            const rowStaticNetmask = document.getElementById('row-static-netmask');
            const rowStaticGateway = document.getElementById('row-static-gateway');
            const rowStaticDnsPrimary = document.getElementById('row-static-dns-primary');
            const rowStaticDnsSecondary = document.getElementById('row-static-dns-secondary');
            if (rowPppoeUser) rowPppoeUser.style.display = (mode === 'PPPoE') ? '' : 'none';
            if (rowPppoePass) rowPppoePass.style.display = (mode === 'PPPoE') ? '' : 'none';
            if (rowStaticIp) rowStaticIp.style.display = (mode === 'Static') ? '' : 'none';
            if (rowStaticNetmask) rowStaticNetmask.style.display = (mode === 'Static') ? '' : 'none';
            if (rowStaticGateway) rowStaticGateway.style.display = (mode === 'Static') ? '' : 'none';
            if (rowStaticDnsPrimary) rowStaticDnsPrimary.style.display = (mode === 'Static') ? '' : 'none';
            if (rowStaticDnsSecondary) rowStaticDnsSecondary.style.display = (mode === 'Static') ? '' : 'none';
        }
        document.querySelectorAll('.wan-mode-radio').forEach(r => r.addEventListener('change', toggleWanModeFields));
        toggleWanModeFields();

        // Zone -> Splitter dropdown chaining
        const zoneSelect = document.getElementById('identity-zone-select');
        const splitterSelect = document.getElementById('identity-splitter-select');
        const currentSplitter = <?php echo json_encode(($parsed_desc['splitter'] && strtolower($parsed_desc['splitter']) !== 'none') ? $parsed_desc['splitter'] : ''); ?>;

        function loadSplitters(zone, keepCurrent) {
            if (!zone) {
                splitterSelect.innerHTML = '<option value="">- Pilih Zone dulu -</option>';
                return;
            }
            fetch('action/get-splitters.php?zone=' + encodeURIComponent(zone))
                .then(r => r.json())
                .then(res => {
                    const items = (res.data || []);
                    let html = '';
                    let found = false;
                    if (!keepCurrent) html = '<option value="">- Pilih ODB -</option>';
                    items.forEach(s => {
                        const sel = keepCurrent && s.name === currentSplitter ? 'selected' : '';
                        if (sel) found = true;
                        html += `<option value="${esc(s.name)}" ${sel}>${esc(s.name)}</option>`;
                    });
                    if (keepCurrent && currentSplitter && !found) {
                        html = `<option value="${esc(currentSplitter)}" selected>${esc(currentSplitter)}</option>` + html;
                    }
                    splitterSelect.innerHTML = html || '<option value="">- Tidak ada ODB di zone ini -</option>';
                })
                .catch(() => { splitterSelect.innerHTML = '<option value="">Gagal memuat splitter</option>'; });
        }

        if (zoneSelect && splitterSelect) {
            loadSplitters(zoneSelect.value, true);
            zoneSelect.addEventListener('change', () => loadSplitters(zoneSelect.value, false));
        }

        // GPS: use current location for lat/long in edit-identity-modal
        const gpsBtn = document.getElementById('btn-identity-gps');
        if (gpsBtn) {
            gpsBtn.addEventListener('click', () => {
                if (!navigator.geolocation) return;
                gpsBtn.disabled = true;
                navigator.geolocation.getCurrentPosition((pos) => {
                    const latEl = document.getElementById('identity-latitude');
                    const lngEl = document.getElementById('identity-longitude');
                    if (latEl) latEl.value = pos.coords.latitude;
                    if (lngEl) lngEl.value = pos.coords.longitude;
                    gpsBtn.disabled = false;
                }, () => { gpsBtn.disabled = false; });
            });
        }

        // Toggle username show/hide on detail table
        const userText = document.getElementById('pppoe-user-text');
        const userValueSpan = document.getElementById('pppoe-user-value');
        const toggleUserBtn = document.getElementById('btn-toggle-pppoe-user');
        if (toggleUserBtn && userText && userValueSpan) {
            const btnIcon = toggleUserBtn.querySelector('i, svg');
            toggleUserBtn.addEventListener('click', () => {
                const isVisible = userText.getAttribute('data-visible') === 'true';
                if (isVisible) {
                    userValueSpan.textContent = '**********';
                    userText.setAttribute('data-visible', 'false');
                    btnIcon.setAttribute('data-lucide', 'eye');
                } else {
                    userValueSpan.textContent = userText.getAttribute('data-username') || 'Tidak Dikonfigurasi';
                    userText.setAttribute('data-visible', 'true');
                    btnIcon.setAttribute('data-lucide', 'eye-off');
                }
                if (typeof lucide !== 'undefined') {
                    lucide.createIcons();
                }
            });
        }

        // Toggle password show/hide on detail table
        const passText = document.getElementById('pppoe-pass-text');
        const passValueSpan = document.getElementById('pppoe-pass-value');
        const toggleBtn = document.getElementById('btn-toggle-pppoe-pass');
        if (toggleBtn && passText && passValueSpan) {
            const btnIcon = toggleBtn.querySelector('i, svg');
            toggleBtn.addEventListener('click', () => {
                const isVisible = passText.getAttribute('data-visible') === 'true';
                if (isVisible) {
                    passValueSpan.textContent = '**********';
                    passText.setAttribute('data-visible', 'false');
                    btnIcon.setAttribute('data-lucide', 'eye');
                } else {
                    passValueSpan.textContent = passText.getAttribute('data-password') || 'Tidak Dikonfigurasi';
                    passText.setAttribute('data-visible', 'true');
                    btnIcon.setAttribute('data-lucide', 'eye-off');
                }
                if (typeof lucide !== 'undefined') {
                    lucide.createIcons();
                }
            });
        }

        // Output Container & Buttons logic
        const cliOutputBox = document.getElementById('status-cli-output-container');
        const btnGetStatus = document.getElementById('btn-onu-refresh-signal');
        const btnShowRunning = document.getElementById('btn-show-running-config');

        // Cache dari database hasil kueri page load backend
        let cachedStatus = <?php echo json_encode($onu['last_status_cli'] ?? ''); ?>;
        let cachedConfig = <?php echo json_encode($onu['last_running_config'] ?? ''); ?>;

        // Handle Get Status
        if (btnGetStatus && cliOutputBox) {
            btnGetStatus.addEventListener('click', () => {
                cliOutputBox.style.display = 'block';
                cliOutputBox.style.fontFamily = 'monospace';
                cliOutputBox.style.whiteSpace = 'pre-wrap';
                cliOutputBox.style.background = 'var(--bg-tertiary)';
                cliOutputBox.style.border = '1px solid var(--border-color)';
                cliOutputBox.style.borderRadius = '4px';
                cliOutputBox.textContent = 'Mengambil data...';

                btnGetStatus.disabled = true;
                fetch(`action/onu-query.php?action=status&id=${onuId}`)
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            let text = '';
                            text += 'Optical status\n' + (data.optical_status || '') + '\n\n';
                            text += 'ONU CATV port\n' + (data.onu_catv_port || 'No CATV data or command unsupported.') + '\n\n';
                            text += 'ONU details\n' + (data.onu_details || '') + '\n\n';
                            text += 'History\n' + (data.history || '') + '\n\n';
                            text += 'ONU WAN Interfaces\n' + (data.wan_interfaces || '') + '\n\n';
                            text += 'ONU LAN Interfaces status\n' + (data.lan_interfaces || '') + '\n\n';
                            text += 'Realtime VLAN info\n' + (data.vlan_info || '') + '\n\n';
                            text += 'VoIP status\n' + (data.voip_status || '') + '\n\n';
                            text += 'MACs on OLT from this ONU\n' + (data.macs || '');
                            cliOutputBox.textContent = text;
                            cachedStatus = text;
                            
                            // Update live fields on page cards
                            const pppoeContainer = document.getElementById('detail-pppoe-ip-wrapper');
                            if (pppoeContainer) {
                                if (window.currentWanMode !== 'PPPoE') {
                                    pppoeContainer.innerHTML = '<span style="color:var(--text-muted);">-</span>';
                                } else if (data.pppoe_ip && data.pppoe_ip !== 'N/A' && data.pppoe_ip !== '0.0.0.0' && data.pppoe_ip !== '') {
                                    pppoeContainer.innerHTML = `
                                        <a href="http://${esc(data.pppoe_ip)}" target="_blank" class="chip chip-green" style="text-decoration: none;" onclick="event.stopPropagation();">
                                            <i data-lucide="external-link" style="width:11px; height:11px; display:inline-block; vertical-align:middle; margin-right:4px;"></i>
                                            ${esc(data.pppoe_ip)}
                                        </a>`;
                                } else {
                                    pppoeContainer.innerHTML = `<span class="chip chip-red">No IP</span>`;
                                }
                            }

                            const rxOnuText = document.getElementById('detail-rx-onu');
                            if (rxOnuText && data.rx_onu && data.rx_onu !== 'N/A') {
                                rxOnuText.textContent = `${data.rx_onu} dBm`;
                                const rxVal = parseFloat(data.rx_onu);
                                rxOnuText.style.color = signalColor(rxVal);
                            }

                            const rxOltText = document.getElementById('detail-rx-olt');
                            if (rxOltText && data.rx_olt && data.rx_olt !== 'N/A') {
                                rxOltText.textContent = `${data.rx_olt} dBm`;
                                const rxOltVal = parseFloat(data.rx_olt);
                                rxOltText.style.color = signalColor(rxOltVal);
                            }

                            if (typeof lucide !== 'undefined') {
                                lucide.createIcons();
                            }
                        } else {
                            cliOutputBox.textContent = 'Gagal: ' + (data.message || 'Tidak dapat memuat status.');
                        }
                    })
                    .catch(err => {
                        console.error(err);
                        cliOutputBox.textContent = 'Terjadi kesalahan jaringan saat mengambil status dari OLT.';
                    })
                    .finally(() => {
                        btnGetStatus.disabled = false;
                    });
            });
        }

        // Handle Show Running Config
        if (btnShowRunning && cliOutputBox) {
            btnShowRunning.addEventListener('click', () => {
                cliOutputBox.style.display = 'block';
                cliOutputBox.style.fontFamily = 'monospace';
                cliOutputBox.style.whiteSpace = 'pre-wrap';
                cliOutputBox.style.background = 'var(--bg-tertiary)';
                cliOutputBox.style.border = '1px solid var(--border-color)';
                cliOutputBox.style.borderRadius = '4px';
                cliOutputBox.textContent = 'Mengambil data...';

                btnShowRunning.disabled = true;
                fetch(`action/onu-query.php?action=config&id=${onuId}`)
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            cliOutputBox.textContent = data.config;
                            cachedConfig = data.config;
                            // update tabel Speed profiles dari config asli (ZTE: nama profile; CDATA: dba-profile-id numerik)
                            const mUp = data.config.match(/tcont\s+\d+\s+profile\s+(\S+)/i)
                                || data.config.match(/ont\s+tcont\s+\d+\s+\d+\s+1\s+dba-profile-id\s+(\d+)/i);
                            const mDown = data.config.match(/gemport\s+\d+\s+traffic-limit\s+downstream\s+(\S+)/i)
                                || data.config.match(/ont\s+tcont\s+\d+\s+\d+\s+0\s+dba-profile-id\s+(\d+)/i);
                            const upCell = document.getElementById('speed-upload-cell');
                            const downCell = document.getElementById('speed-download-cell');
                            if (upCell) upCell.textContent = mUp ? (isNaN(mUp[1]) ? mUp[1] : 'DBA Profile ID ' + mUp[1]) : 'Tidak ditemukan di config';
                            if (downCell) downCell.textContent = mDown ? (isNaN(mDown[1]) ? mDown[1] : 'DBA Profile ID ' + mDown[1]) : 'Tidak ditemukan di config';
                        } else {
                            cliOutputBox.textContent = 'Gagal: ' + (data.message || 'Tidak dapat memuat running-config.');
                        }
                    })
                    .catch(err => {
                        console.error(err);
                        cliOutputBox.textContent = 'Terjadi kesalahan jaringan saat mengambil running-config.';
                    })
                    .finally(() => {
                        btnShowRunning.disabled = false;
                    });
            });
        }

        // Handle SW Info
        const btnSwInfo = document.getElementById('btn-sw-info');
        if (btnSwInfo && cliOutputBox) {
            btnSwInfo.addEventListener('click', () => {
                cliOutputBox.style.display = 'block';
                cliOutputBox.style.fontFamily = 'monospace';
                cliOutputBox.style.whiteSpace = 'pre-wrap';
                cliOutputBox.style.background = 'var(--bg-tertiary)';
                cliOutputBox.style.border = '1px solid var(--border-color)';
                cliOutputBox.style.borderRadius = '4px';
                cliOutputBox.textContent = 'Mengambil data...';

                btnSwInfo.disabled = true;
                fetch(`action/onu-query.php?action=hw_sw&id=${onuId}`)
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            cliOutputBox.textContent = data.hw_sw;
                        } else {
                            cliOutputBox.textContent = 'Gagal: ' + (data.message || 'Tidak dapat memuat info hardware/software.');
                        }
                    })
                    .catch(err => {
                        console.error(err);
                        cliOutputBox.textContent = 'Terjadi kesalahan jaringan saat mengambil SW info.';
                    })
                    .finally(() => {
                        btnSwInfo.disabled = false;
                    });
            });
        }

        // Handle TR069 Status (GenieACS inline accordion)
        const btnTr069 = document.getElementById('btn-tr069-status');
        const serial = '<?= addslashes($onu['serial_number'] ?? '') ?>';
        if (btnTr069 && cliOutputBox) {
            function loadTr069Status() {
                cliOutputBox.style.display = 'block';
                cliOutputBox.style.fontFamily = 'inherit';
                cliOutputBox.style.whiteSpace = 'normal';
                cliOutputBox.style.background = 'none';
                cliOutputBox.style.border = 'none';
                cliOutputBox.style.borderRadius = '0';
                cliOutputBox.innerHTML = '<em>Memuat data GenieACS...</em>';
                btnTr069.disabled = true;
                fetch(`action/genieacs-proxy.php?serial=${encodeURIComponent(serial)}`)
                    .then(r => r.json())
                    .then(d => {
                        if (d.error) { cliOutputBox.textContent = 'Error: ' + d.error; return; }
                        const root = d.InternetGatewayDevice || d.Device || {};
                        // Flatten tree into sections
                        const sections = [];
                        function walk(obj, path) {
                            if (!obj || typeof obj !== 'object') return;
                            // Collect leaf values
                            const params = {};
                            const children = {};
                            for (const [k, v] of Object.entries(obj)) {
                                if (k.startsWith('_')) continue;
                                if (v && typeof v === 'object' && v._object) children[k] = v;
                                else if (v && typeof v === 'object' && '_value' in v) params[k] = v._value;
                            }
                            if (Object.keys(params).length) sections.push({ title: path, params });
                            for (const [k, v] of Object.entries(children)) {
                                // Numeric array index (e.g. WANConnectionDevice.1) → merge with parent segment
                                // instead of becoming its own bare-number path segment.
                                const childPath = /^\d+$/.test(k) ? (path + ' ' + k) : (path ? path + ' > ' + k : k);
                                walk(v, childPath);
                            }
                        }
                        // Build General section from ALL DeviceInfo fields
                        const generalParams = {};
                        const devInfoFieldLabels = {
                            'Manufacturer': 'Manufacturer',
                            'ModelName': 'Model name',
                            'ProductClass': 'Product Class',
                            'Description': 'Description',
                            'DeviceType': 'Device Type',
                            'SerialNumber': 'TR069 Serial',
                            'HardwareVersion': 'Hardware version',
                            'SoftwareVersion': 'Software version',
                            'AdditionalHardwareVersion': 'Additional Hardware',
                            'AdditionalSoftwareVersion': 'Additional Software',
                            'ModemFirmwareVersion': 'Modem Firmware',
                            'ProvisioningCode': 'Provisioning code',
                            'SpecVersion': 'Spec Version',
                            'DeviceSummary': 'Device Summary',
                            'EnabledOptions': 'Enabled Options',
                            'FirstUseDate': 'First Use Date',
                            'ManufacturerOUI': 'Manufacturer OUI',
                            'ModelNumber': 'Model Number',
                            'UpTime': 'Uptime',
                        };
                        // Walk all DeviceInfo sub-objects for params
                        function collectParams(obj, prefix) {
                            for (const [k, v] of Object.entries(obj || {})) {
                                if (k.startsWith('_') || !v || typeof v !== 'object') continue;
                                if (v._object) { collectParams(v, prefix ? prefix+'.'+k : k); continue; }
                                if ('_value' in v) {
                                    const label = devInfoFieldLabels[k] || k;
                                    generalParams[prefix ? prefix+'.'+k : label] = v._value;
                                }
                            }
                        }
                        const dev = root.DeviceInfo || {};
                        const devid = d._deviceId || {};
                        collectParams(dev, '');

                        // Build ordered General params
                        const ordered = {};
                        const topFields = [
                            ['Manufacturer', dev.Manufacturer?._value ? dev.Manufacturer._value + ' (OUI: ' + (devid._OUI || '-') + ')' : '-'],
                            ['Model name', dev.ModelName?._value || dev.ProductClass?._value || '-'],
                            ['Software version', dev.SoftwareVersion?._value || '-'],
                            ['Hardware version', dev.HardwareVersion?._value || '-'],
                            ['Provisioning code', dev.ProvisioningCode?._value || '-'],
                            ['Data model', 'TR-069 (Root: ' + (d.InternetGatewayDevice ? 'InternetGatewayDevice' : 'Device') + ')'],
                            ['GPON Serial number', devid._SerialNumber || '-'],
                            ['TR069 Serial number', dev.SerialNumber?._value || '-'],
                        ];
                        for (const [k, v] of topFields) ordered[k] = v;

                        // CPU Usage with color
                        const cpu = dev.X_HW_CpuUsed?._value ?? dev.ProcessStatus?.CPUUsage?._value;
                        if (cpu != null) ordered['CPU Usage'] = {v: cpu+'%', color: cpu > 80 ? '#dc3545' : cpu > 50 ? '#ffc107' : '#28a745'};

                        // RAM (TR-069 MemoryStatus values are in KB → convert to MB)
                        const totalRAM = dev.MemoryStatus?.Total?._value;
                        const freeRAM = dev.MemoryStatus?.Free?._value;
                        if (totalRAM) ordered['Total RAM'] = Math.round(totalRAM/1024) + ' MB';
                        if (freeRAM) ordered['Free RAM'] = Math.round(freeRAM/1024) + ' MB';

                        // Uptime — pakai boot time + durasi format lengkap
                        const uptimeSec = dev.UpTime?._value;
                        if (uptimeSec) {
                            const bootTime = new Date(Date.now() - uptimeSec*1000);
                            const pad = n => String(n).padStart(2,'0');
                            const bootStr = bootTime.getFullYear()+'-'+pad(bootTime.getMonth()+1)+'-'+pad(bootTime.getDate())+' '+pad(bootTime.getHours())+':'+pad(bootTime.getMinutes())+':'+pad(bootTime.getSeconds());
                            const d_ = Math.floor(uptimeSec/86400), h = Math.floor((uptimeSec%86400)/3600), m = Math.floor((uptimeSec%3600)/60), s = Math.floor(uptimeSec%60);
                            const parts = [];
                            if (d_) parts.push(d_+' day'+(d_>1?'s':''));
                            if (d_ || h) parts.push(h+' hour'+(h!=1?'s':''));
                            parts.push(m+' minute'+(m!=1?'s':''));
                            parts.push(s+' second'+(s!=1?'s':''));
                            ordered['Uptime'] = bootStr + ' (' + parts.join(', ') + ' ago)';
                        }

                        sections.unshift({ title: 'General', params: ordered });
                        // Walk WANDevice
                        if (root.WANDevice) {
                            for (const [wdk, wdv] of Object.entries(root.WANDevice)) {
                                if (!wdv || typeof wdv !== 'object') continue;
                                walk(wdv, 'WANDevice ' + wdk);
                            }
                        }
                        // Walk LANDevice
                        if (root.LANDevice) {
                            for (const [ldk, ldv] of Object.entries(root.LANDevice)) {
                                if (!ldv || typeof ldv !== 'object') continue;
                                walk(ldv, 'LANDevice ' + ldk);
                            }
                        }
                        // Map TR-069 path to friendly section name
                        const sectionLabels = {
                            'WANDevice': 'WAN',
                            'WANConnectionDevice': 'WAN Conn',
                            'WANPPPConnection': 'PPP Interface',
                            'WANIPConnection': 'IP Interface',
                            'WANEthInterfaceConfig': 'WAN Eth',
                            'PortMapping': 'Port Forward',
                            'X_GponInterafceConfig': 'GPON Optical',
                            'X_GponInterfaceConfig': 'GPON Optical',
                            'X_HW_PonInterface': 'PON Interface',
                            'X_HW_ShowInterface': 'Show Interface',
                            'WANCommonInterfaceConfig': 'WAN Common',
                        };
                        const lanLabels = {
                            'LANHostConfigManagement': 'LAN DHCP Server',
                            'LANEthernetInterfaceConfig': 'LAN Ports',
                            'Stats': 'LAN Counters',
                            'WLANConfiguration': 'Wireless LAN',
                            'Hosts': 'Hosts',
                            'X_HW_LanService': 'LAN Service',
                        };
                        function niceLabel(path) {
                            // WANDevice 1 > WANConnectionDevice 1 > WANPPPConnection → "PPP Interface 1.1"
                            const parts = path.split(' > ');
                            const nums = [];
                            const names = [];
                            for (const p of parts) {
                                const m = p.match(/^(.+?)\s+(\d+)$/);
                                if (m) {
                                    names.push(sectionLabels[m[1]] || lanLabels[m[1]] || m[1]);
                                    nums.push(m[2]);
                                } else {
                                    names.push(sectionLabels[p] || lanLabels[p] || p);
                                }
                            }
                            // Special: WLANConfiguration → "Wireless LAN {n}" HANYA utk path yang PERSIS
                            // berhenti di WLANConfiguration N (root konfigurasi WLAN itu sendiri).
                            // Child object di dalamnya (Stats, WPS, PreSharedKey, dst) TIDAK boleh
                            // ikut dilabeli "Wireless LAN N" juga — itu bikin section duplikat.
                            const wlanMatch = path.trim().match(/WLANConfiguration\s+(\d+)$/);
                            if (wlanMatch) {
                                return 'Wireless LAN ' + wlanMatch[1];
                            }
                            if (path.includes('WLANConfiguration')) {
                                const wlanNum = path.match(/WLANConfiguration\s+(\d+)/);
                                const isStats = /\bStats\b/.test(path);
                                if (isStats) return 'WLAN Counters' + (wlanNum ? ' ' + wlanNum[1] : '');
                                // Child lain (WPS, PreSharedKey, AssociatedDevice, X_HW_*, dst): pakai nama child asli.
                                const lastChild = names[names.length - 1] || path;
                                return lastChild + (wlanNum ? ' (WLAN ' + wlanNum[1] + ')' : '');
                            }
                            // For WAN paths: use last meaningful name + numeric suffix
                            const last = names[names.length - 1] || path;
                            const numStr = nums.join('.');
                            return numStr ? last + ' ' + numStr : last;
                        }
                        // Rewrite section titles
                        sections.forEach(sec => { sec.title = niceLabel(sec.title); });

                        // Walk top-level non-device children (Services, Diagnostics, etc.)
                        for (const [k, v] of Object.entries(root)) {
                            if (['_object','_timestamp','_writable','_deviceId'].includes(k)) continue;
                            if (['DeviceInfo','WANDevice','LANDevice'].includes(k)) continue;
                            if (v && typeof v === 'object' && v._object) {
                                // Friendly top-level names
                                const topLabels = {
                                    'Layer3Forwarding': 'Routing',
                                    'Services': 'Services',
                                    'VoiceService': 'Voice lines',
                                    'ManagementServer': 'Management Server',
                                    'Time': 'Time',
                                    'DeviceInfo': 'Device Info',
                                    'DeviceConfig': 'Device Config',
                                    'Diagnostics': 'Diagnostics',
                                    'IPPingDiagnostics': 'IP Ping Diagnostics',
                                    'DownloadDiagnostics': 'Download Diagnostics',
                                    'UploadDiagnostics': 'Upload Diagnostics',
                                    'TraceRouteDiagnostics': 'Traceroute Diagnostics',
                                    'ServerSelectionDiagnostics': 'Server Selection',
                                    'UDPEchoConfig': 'UDP Echo Config',
                                    'UserInterface': 'User Interface',
                                    'BulkData': 'Bulk Data',
                                    'QueueManagement': 'Queue Management',
                                    'Optical': 'Optical',
                                    'LANInterfaces': 'LAN Interfaces',
                                    'LANConfigSecurity': 'LAN Config Security',
                                    'WiFi': 'WiFi',
                                    'Security': 'Security',
                                    'Hosts': 'Hosts',
                                    'X_HW_ALG': 'ALG',
                                    'X_HW_IPTV': 'IPTV',
                                    'X_HW_IPv6': 'IPv6',
                                    'X_HW_DNS': 'DNS',
                                    'X_HW_Security': 'Security',
                                    'X_HW_ServiceManage': 'Service Management',
                                    'X_HW_APDevice': 'AP Device',
                                    'X_HW_APMPolicy': 'APM Policy',
                                    'X_HW_APService': 'AP Service',
                                    'X_HW_WiFiDiagnostic': 'WiFi Diagnostic',
                                    'X_HW_WifiCoverService': 'WiFi Cover',
                                    'X_HW_eMDI': 'eMDI',
                                    'X_HW_PonQualityMonitor': 'PON Quality',
                                    'X_HW_SmartCAT': 'Smart CAT',
                                    'X_HW_SmartTopo': 'Smart Topo',
                                    'X_HW_MainUPnP': 'UPnP',
                                    'X_HW_SlvUPnP': 'Slave UPnP',
                                    'X_HW_NetInfo_Acquisition': 'Network Info',
                                    'X_HW_ARPPingDiagnostics': 'ARP Ping',
                                    'X_HW_DHCP_PING_EMULATOR': 'DHCP Ping',
                                    'X_HW_DHCPSLVSERVER': 'DHCP Slave',
                                    'X_HW_Arp': 'ARP Table',
                                    'X_HW_GRETunnel': 'GRE Tunnel',
                                    'X_HW_NeighborDiscovery': 'Neighbor Discovery',
                                    'X_HW_SFTP': 'SFTP',
                                    'X_HW_RouterAdvertisement': 'Router Advertisement',
                                    'X_HW_DHCPv6': 'DHCPv6',
                                    'X_HW_IPv6Config': 'IPv6 Config',
                                    'X_HW_IPv6Layer3Forwarding': 'IPv6 Routing',
                                    'X_HW_WLANForGuest': 'Guest WLAN',
                                    'X_HW_WLANForISP': 'ISP WLAN',
                                    'X_HW_LanService': 'LAN Service',
                                    'X_HW_IperfSpeedTest': 'Speed Test',
                                    'X_HW_AppRemoteManage': 'Remote Manage',
                                    'X_HW_PPPoE_BridgeWAN_AutoEmulator': 'PPPoE Emulator',
                                    'X_HW_PPPOE_EMLUATOR': 'PPPoE Emulator',
                                    'X_HW_AutoBackupRestore': 'Auto Backup',
                                    'X_HW_Alarm': 'Alarm',
                                    'X_HW_CertPassword': 'Cert Password',
                                    'X_HW_CheckUrlRandom': 'URL Random',
                                    'X_HW_DSCP': 'DSCP',
                                    'X_HW_EnableCertificate': 'Certificate',
                                    'X_HW_RandomInformEnable': 'Random Inform',
                                    'X_HW_Syntax': 'Syntax',
                                    'X_HW_UserContractInfo': 'User Contract',
                                    'X_HW_ServiceAccessInfo': 'Service Access',
                                    'X_HW_Syslog': 'Syslog',
                                    'X_HW_Monitor': 'Monitor',
                                    'X_HW_FeatureList': 'Feature List',
                                    'X_HW_LanService': 'LAN Service',
                                    'X_HW_CHL_SCAN': 'Channel Scan',
                                    'X_HW_AmpInfo': 'Amplifier Info',
                                    'X_HW_Dot1agCfm': 'CFM (802.1ag)',
                                    'X_HW_POTSDeviceNumber': 'POTS Devices',
                                    'ManageableDevice': 'Manageable Devices',
                                    'InformParameter': 'Inform Parameters',
                                    'VirtualDevice': 'Virtual Device',
                                };
                                const label = topLabels[k] || k;
                                walk(v, label);
                            }
                        }

                        // Render accordion
                        let html = '<div style="font-size:0.85rem;background:var(--bg-main);">';
                        html += '<div style="font-weight:700;margin-bottom:12px;font-size:0.95rem;display:flex;align-items:center;gap:8px;"><span style="width:10px;height:10px;border-radius:50%;background:#28a745;display:inline-block;"></span> ' + (d._id || serial) + '</div>';
                        // Render one PPP Interface card matching the ONU-webpage layout (readonly + editable rows).
                        function pv(sec, key) {
                            const v = sec.params[key];
                            if (v && typeof v === 'object' && 'v' in v) return v.v;
                            return (v === undefined || v === null) ? '' : v;
                        }
                        function pppRow(label, valueHtml) {
                            return '<div style="display:grid;grid-template-columns:180px 1fr;align-items:center;gap:8px;padding:7px 0;border-bottom:1px solid var(--border-color);">'
                                + '<span style="color:var(--text-muted);font-size:0.82rem;">' + label + '</span>'
                                + '<span style="word-break:break-all;">' + valueHtml + '</span></div>';
                        }
                        function pppInput(key, i, value, opts) {
                            opts = opts || {};
                            const w = opts.narrow ? '110px' : '320px';
                            return '<input type="text" class="ppp-field" data-key="'+key+'" data-idx="'+i+'" value="'+String(value).replace(/"/g,'&quot;')+'"'
                                + (opts.placeholder ? ' placeholder="'+opts.placeholder+'"' : '')
                                + ' style="width:100%;max-width:'+w+';box-sizing:border-box;padding:5px 8px;font-size:0.85rem;border:1px solid var(--border-color);border-radius:4px;background:var(--bg-secondary);color:var(--text-main);">';
                        }
                        function renderPppCard(sec, i) {
                            const status = pv(sec, 'ConnectionStatus') || 'N/A';
                            const statusColor = status === 'Connected' ? '#28a745' : (status === 'Connecting' ? '#fd7e14' : '#6c757d');
                            const connName = pv(sec, 'Name') || 'Internet_PPPoE';
                            const uptime = pv(sec, 'Uptime');
                            const username = pv(sec, 'Username');
                            const dns = pv(sec, 'DNSServers');
                            const gw = pv(sec, 'DefaultGateway');
                            const ip = pv(sec, 'ExternalIPAddress');
                            const mru = pv(sec, 'MaxMRUSize');
                            const acsName = root?.ManagementServer?.URL?._value || ''; // ACS Name = URL server ACS, bukan field WANPPPConnection
                            const trigger = pv(sec, 'ConnectionTrigger') || 'AlwaysOn';
                            const macClone = pv(sec, 'MACAddressOverride');
                            const mac = pv(sec, 'MACAddress');
                            const svcList = pv(sec, 'X_HW_SERVICELIST') || 'INTERNET';
                            const nat = pv(sec, 'NATEnabled');
                            const lcp = pv(sec, 'PPPLCPEcho');
                            const dmz = pv(sec, 'X_HW_DMZ_Enable');
                            const dmzIp = pv(sec, 'X_HW_DMZ_HostIP');
                            const vlan = pv(sec, 'X_HW_VLAN');
                            let h = '<div style="margin-bottom:8px;border:1px solid var(--border-color);border-radius:6px;overflow:hidden;">';
                            h += '<div class="tr069-toggle" data-idx="'+i+'" style="padding:8px 12px;cursor:pointer;background:var(--bg-secondary);color:var(--text-main);font-weight:600;display:flex;justify-content:space-between;align-items:center;">';
                            h += '<span>' + sec.title + '</span></div>';
                            h += '<div class="tr069-panel" style="display:' + (i === 0 ? 'block' : 'none') + ';padding:4px 12px;background:var(--bg-main);">';
                            h += pppRow('Connection name', connName);
                            h += pppRow('Connection status', '<span style="color:'+statusColor+';font-weight:600;">'+status+'</span>');
                            h += pppRow('Uptime', uptime || 'N/A');
                            h += pppRow('IP Address', ip || 'N/A');
                            h += pppRow('PPP Gateway', gw || 'N/A');
                            h += pppRow('Username', pppInput('username', i, username, {narrow:false}));
                            h += pppRow('Password', pppInput('password', i, pv(sec, 'Password'), {narrow:false}));
                            h += pppRow('DNS Servers', dns || 'N/A');
                            h += pppRow('ACS Name', acsName || 'N/A');
                            h += pppRow('Connection trigger', '<select class="ppp-field" data-key="trigger" data-idx="'+i+'" style="width:100%;max-width:320px;box-sizing:border-box;padding:5px 8px;font-size:0.85rem;border:1px solid var(--border-color);border-radius:4px;background:var(--bg-secondary);color:var(--text-main);"><option'+(trigger==='AlwaysOn'?' selected':'')+'>AlwaysOn</option><option'+(trigger==='OnDemand'?' selected':'')+'>OnDemand</option><option'+(trigger==='Manual'?' selected':'')+'>Manual</option></select>');
                            h += pppRow('Max MRU Size', pppInput('max_mru', i, mru, {narrow:true}));
                            h += pppRow('MAC Clone', ppRadio('mac_clone', i, macClone));
                            h += pppRow('MAC Address', mac || 'N/A');
                            h += pppRow('Service list', svcList);
                            h += pppRow('Default route', '<span style="background:#212529;color:#fff;padding:1px 8px;border-radius:4px;font-size:0.78rem;">Yes</span>');
                            h += pppRow('NAT Enabled', ppRadioYN('nat', i, nat));
                            h += pppRow('LCP Detection', ppRadio('lcp', i, lcp));
                            h += pppRow('DMZ Enable', ppRadio('dmz', i, dmz));
                            h += pppRow('DMZ Host IP Address', pppInput('dmz_ip', i, dmzIp, {narrow:false}));
                            h += pppRow('VLAN ID', pppInput('vlan', i, vlan, {narrow:true}));
                            h += pppRow('Reset connection', '<button type="button" class="btn-solt ppp-reset" data-idx="'+i+'" style="padding:5px 14px;font-size:0.8rem;background:#6c757d;color:#fff;border:none;border-radius:4px;cursor:pointer;">Reset Connection</button>');
                            h += '<div style="padding:12px 0 8px;display:flex;gap:8px;">';
                            h += '<button type="button" class="btn-solt btn-solt-green ppp-save" data-idx="'+i+'" style="padding:6px 16px;font-size:0.82rem;">Simpan Perubahan</button>';
                            h += '</div>';
                            h += '<div style="padding:8px 0 4px;border-top:1px solid var(--border-color);">';
                            h += '<button type="button" class="btn-solt ppp-remove" data-idx="'+i+'" style="padding:6px 16px;font-size:0.82rem;background:#dc3545;color:#fff;border:none;border-radius:4px;cursor:pointer;">Remove PPP WAN</button>';
                            h += '</div>';
                            h += '</div></div>';
                            return h;
                        }
                        function ppRadio(key, i, current) {
                            const yes = current === true || current === 'true' || current === 'Enabled' || current === 1 || current === '1';
                            return '<label style="margin-right:18px;display:inline-flex;align-items:center;gap:5px;"><input type="radio" name="ppp-'+key+'-'+i+'" class="ppp-radio" data-key="'+key+'" data-idx="'+i+'" value="1"'+(yes?' checked':'')+'> Enabled</label>'
                                + '<label style="display:inline-flex;align-items:center;gap:5px;"><input type="radio" name="ppp-'+key+'-'+i+'" class="ppp-radio" data-key="'+key+'" data-idx="'+i+'" value="0"'+(!yes?' checked':'')+'> Disabled</label>';
                        }
                        function ppRadioYN(key, i, current) {
                            const yes = current === true || current === 'true' || current === 'Yes' || current === 1 || current === '1';
                            return '<label style="margin-right:18px;display:inline-flex;align-items:center;gap:5px;"><input type="radio" name="ppp-'+key+'-'+i+'" class="ppp-radio" data-key="'+key+'" data-idx="'+i+'" value="1"'+(yes?' checked':'')+'> Yes</label>'
                                + '<label style="display:inline-flex;align-items:center;gap:5px;"><input type="radio" name="ppp-'+key+'-'+i+'" class="ppp-radio" data-key="'+key+'" data-idx="'+i+'" value="0"'+(!yes?' checked':'')+'> No</label>';
                        }
                        // Render card Wireless LAN mirip layout webUI router — semua field bisa diedit.
                        function renderWlanCard(sec, i) {
                            const enabled = pv(sec, 'Enable');
                            const status = pv(sec, 'Status') || (enabled ? 'Up' : 'Down');
                            const statusColor = status === 'Up' ? '#28a745' : '#6c757d';
                            const ssid = pv(sec, 'SSID') || '';
                            const standard = pv(sec, 'Standard') || pv(sec, 'X_HW_Standard') || 'N/A';
                            const band = pv(sec, 'X_HW_RFBand') || (/^(a|ac|an)/i.test(String(standard)) ? '5GHz' : '2.4GHz');
                            const wpaEnc = pv(sec, 'WPAEncryptionModes') || '';
                            const channel = pv(sec, 'Channel') || '';
                            const autoChannel = pv(sec, 'AutoChannelEnable');
                            const bandwidth = (() => {
                                const ht20 = pv(sec, 'X_HW_HT20');
                                if (ht20 !== '' && ht20 != null) return (ht20 === true || ht20 === '1' || ht20 === 1) ? '20 MHz' : '40 MHz';
                                return pv(sec, 'X_HW_Bandwidth') || 'N/A';
                            })();
                            const regDomain = pv(sec, 'RegulatoryDomain') || 'ID';
                            const ssidAdv = pv(sec, 'SSIDAdvertisementEnabled');
                            const txPower = pv(sec, 'TransmitPower');
                            const maxDev = pv(sec, 'X_HW_AssociateNum') || pv(sec, 'MaxAssociatedDevices');
                            const totalAssoc = pv(sec, 'TotalAssociations');
                            const password = pv(sec, 'KeyPassphrase') || '';
                            let h = '<div style="margin-bottom:8px;border:1px solid var(--border-color);border-radius:6px;overflow:hidden;">';
                            h += '<div class="tr069-toggle" data-idx="'+i+'" style="padding:8px 12px;cursor:pointer;background:var(--bg-secondary);color:var(--text-main);font-weight:600;display:flex;justify-content:space-between;align-items:center;">';
                            h += '<span>' + sec.title + '</span></div>';
                            h += '<div class="tr069-panel" style="display:' + (i === 0 ? 'block' : 'none') + ';padding:8px 12px;background:var(--bg-main);font-size:0.85rem;">';
                            h += pppRow('SSID', wlanInput('ssid', i, ssid, {narrow:false}));
                            h += pppRow('Password', wlanInput('password', i, password, {narrow:false}));
                            h += pppRow('Connection status', '<span style="color:'+statusColor+';font-weight:600;">'+status+'</span>');
                            h += pppRow('Radio', wlanRadio('enable', i, enabled));
                            h += pppRow('Band', band + ' (' + standard + ')');
                            h += pppRow('Security', wlanSecuritySelect(i, wpaEnc));
                            h += pppRow('Channel', wlanChannelRow(i, channel, autoChannel));
                            h += pppRow('Bandwidth', bandwidth);
                            h += pppRow('Regulatory Domain', wlanRegDomainSelect(i, regDomain));
                            h += pppRow('SSID Advertisement', wlanRadio('ssid_broadcast', i, ssidAdv));
                            h += pppRow('Transmit Power', wlanInput('tx_power', i, txPower, {narrow:true}));
                            h += pppRow('Max Devices', (maxDev !== '' && maxDev != null) ? maxDev : 'N/A');
                            h += pppRow('Total Associations', (totalAssoc !== '' && totalAssoc != null) ? totalAssoc : '0');
                            h += '<div style="padding:12px 0 4px;">';
                            h += '<button type="button" class="btn-solt btn-solt-green wlan-save" data-idx="'+i+'" style="padding:6px 16px;font-size:0.82rem;">Simpan Perubahan</button>';
                            h += '</div>';
                            h += '</div></div>';
                            return h;
                        }
                        function wlanInput(key, i, value, opts) {
                            opts = opts || {};
                            const w = opts.narrow ? '110px' : '320px';
                            return '<input type="text" class="wlan-field" data-key="'+key+'" data-idx="'+i+'" value="'+String(value).replace(/"/g,'&quot;')+'"'
                                + ' style="width:100%;max-width:'+w+';box-sizing:border-box;padding:5px 8px;font-size:0.85rem;border:1px solid var(--border-color);border-radius:4px;background:var(--bg-secondary);color:var(--text-main);">';
                        }
                        function wlanRadio(key, i, current) {
                            const yes = current === true || current === 'true' || current === 1 || current === '1';
                            return '<label style="margin-right:18px;display:inline-flex;align-items:center;gap:5px;"><input type="radio" name="wlan-'+key+'-'+i+'" class="wlan-radio" data-key="'+key+'" data-idx="'+i+'" value="1"'+(yes?' checked':'')+'> Enabled</label>'
                                + '<label style="display:inline-flex;align-items:center;gap:5px;"><input type="radio" name="wlan-'+key+'-'+i+'" class="wlan-radio" data-key="'+key+'" data-idx="'+i+'" value="0"'+(!yes?' checked':'')+'> Disabled</label>';
                        }
                        function wlanSecuritySelect(i, current) {
                            const opts = [['TKIPEncryption','WPA2 (TKIP)'],['AESEncryption','WPA2 (AES)'],['TKIPAndAESEncryption','WPA2 (TKIP+AES)'],['None','None']];
                            let s = '<select class="wlan-field" data-key="security" data-idx="'+i+'" style="width:100%;max-width:220px;box-sizing:border-box;padding:5px 8px;font-size:0.85rem;border:1px solid var(--border-color);border-radius:4px;background:var(--bg-secondary);color:var(--text-main);">';
                            opts.forEach(([val, label]) => { s += '<option value="'+val+'"'+(current===val?' selected':'')+'>'+label+'</option>'; });
                            s += '</select>';
                            return s;
                        }
                        function wlanRegDomainSelect(i, current) {
                            const opts = ['ID','US','GB','SG','MY','CN'];
                            let s = '<select class="wlan-field" data-key="regulatory_domain" data-idx="'+i+'" style="width:100%;max-width:120px;box-sizing:border-box;padding:5px 8px;font-size:0.85rem;border:1px solid var(--border-color);border-radius:4px;background:var(--bg-secondary);color:var(--text-main);">';
                            opts.forEach(val => { s += '<option value="'+val+'"'+(current===val?' selected':'')+'>'+val+'</option>'; });
                            s += '</select>';
                            return s;
                        }
                        function wlanChannelRow(i, channel, autoChannel) {
                            const isAuto = autoChannel === true || autoChannel === 'true' || autoChannel === 1 || autoChannel === '1';
                            let s = '<label style="margin-right:14px;display:inline-flex;align-items:center;gap:5px;"><input type="radio" name="wlan-chmode-'+i+'" class="wlan-chmode" data-idx="'+i+'" value="auto"'+(isAuto?' checked':'')+'> Auto</label>';
                            s += '<label style="display:inline-flex;align-items:center;gap:5px;margin-right:10px;"><input type="radio" name="wlan-chmode-'+i+'" class="wlan-chmode" data-idx="'+i+'" value="fix"'+(!isAuto?' checked':'')+'> Fix</label>';
                            s += '<input type="text" class="wlan-field" data-key="channel" data-idx="'+i+'" value="'+String(channel).replace(/"/g,'&quot;')+'" style="width:70px;box-sizing:border-box;padding:5px 8px;font-size:0.85rem;border:1px solid var(--border-color);border-radius:4px;background:var(--bg-secondary);color:var(--text-main);">';
                            return s;
                        }
                        function ppRadioReadonly(current) {
                            const yes = current === true || current === 'true' || current === 1 || current === '1';
                            return '<span style="color:'+(yes?'#28a745':'#6c757d')+';font-weight:600;">' + (yes ? 'Enabled' : 'Disabled') + '</span>';
                        }
                        sections.forEach((sec, i) => {
                            const count = Object.keys(sec.params).length;
                            if (!count) return;
                            const isGeneral = sec.title === 'General';
                            const isPPP = /^PPP Interface/.test(sec.title);
                            const isWLAN = /^Wireless LAN/.test(sec.title);
                            // Child WLAN (PreSharedKey, WPS, dst) — field-nya sudah tercakup di card
                            // "Wireless LAN N" (mis. Password = KeyPassphrase dari PreSharedKey), jadi
                            // tidak perlu section accordion terpisah.
                            const isWlanChild = /\(WLAN \d+\)$/.test(sec.title);
                            if (isWlanChild) return;
                            if (isPPP) {
                                html += renderPppCard(sec, i);
                                return;
                            }
                            if (isWLAN) {
                                html += renderWlanCard(sec, i);
                                return;
                            }
                            // Ikon refresh muncul HANYA saat section sedang terbuka (di-toggle via JS di bawah),
                            // tidak ada lagi badge "N params" permanen di semua section.
                            const rightHtml = '<span class="tr069-refresh" data-idx="'+i+'" title="Refresh" style="cursor:pointer;color:var(--text-muted);font-size:1rem;line-height:1;display:' + (i === 0 ? 'inline' : 'none') + ';">&#8635;</span>';
                            html += '<div style="margin-bottom:8px;border:1px solid var(--border-color);border-radius:6px;overflow:hidden;">';
                            html += '<div class="tr069-toggle" data-idx="'+i+'" style="padding:8px 12px;cursor:pointer;background:var(--bg-secondary);color:var(--text-main);font-weight:600;display:flex;justify-content:space-between;align-items:center;">';
                            html += '<span>' + sec.title + '</span>' + rightHtml + '</div>';
                            html += '<div class="tr069-panel" style="display:' + (i === 0 ? 'block' : 'none') + ';padding:8px 12px;background:var(--bg-main);">';
                            for (const [k, v] of Object.entries(sec.params)) {
                                let val;
                                if (v && typeof v === 'object' && v.v) {
                                    val = '<span style="color:' + (v.color || 'inherit') + ';font-weight:600;">' + v.v + '</span>';
                                } else {
                                    val = (v === '' || v == null) ? '<span style="color:var(--text-muted);">(empty)</span>' : String(v);
                                }
                                html += '<div style="padding:3px 0;border-bottom:1px solid var(--border-color);display:flex;gap:8px;">';
                                html += '<span style="min-width:200px;color:var(--text-muted);flex-shrink:0;">' + k + '</span>';
                                html += '<span style="word-break:break-all;">' + val + '</span></div>';
                            }
                            if (isGeneral) {
                                html += '<div style="padding:3px 0;border-bottom:1px solid var(--border-color);display:flex;gap:8px;align-items:center;">';
                                html += '<span style="min-width:200px;color:var(--text-muted);flex-shrink:0;">Pending provisions</span>';
                                html += '<span style="display:flex;gap:8px;flex:1;"><input type="text" id="tr069-prov-input" placeholder="Provision name" style="flex:1;padding:4px 8px;border:1px solid var(--border-color);border-radius:4px;background:var(--bg-secondary);color:var(--text-main);font-size:0.85rem;">';
                                html += '<button type="button" class="btn-solt btn-solt-green" id="tr069-prov-add" style="padding:4px 12px;font-size:0.8rem;">+ Add</button></span></div>';
                            }
                            html += '</div></div>';
                        });
                        html += '</div>';
                        cliOutputBox.innerHTML = html;
                        // Accordion: single-open — buka panel yang diklik, sembunyikan sisanya;
                        // ikon refresh (.tr069-refresh) ikut muncul HANYA di header yang sedang terbuka.
                        cliOutputBox.querySelectorAll('.tr069-toggle').forEach(t => t.addEventListener('click', function() {
                            cliOutputBox.querySelectorAll('.tr069-panel').forEach(p => p.style.display = 'none');
                            cliOutputBox.querySelectorAll('.tr069-refresh').forEach(r => r.style.display = 'none');
                            this.nextElementSibling.style.display = 'block';
                            const refreshIcon = this.querySelector('.tr069-refresh');
                            if (refreshIcon) refreshIcon.style.display = 'inline';
                        }));
                        // Add provision handler
                        const provBtn = document.getElementById('tr069-prov-add');
                        const provInput = document.getElementById('tr069-prov-input');
                        if (provBtn && provInput) {
                            provBtn.addEventListener('click', () => {
                                const name = provInput.value.trim();
                                if (!name) return;
                                provBtn.disabled = true;
                                fetch('action/genieacs-proxy.php', {
                                    method: 'POST',
                                    headers: {'Content-Type': 'application/json'},
                                    body: JSON.stringify({serial, provision: name})
                                }).then(r => r.json()).then(d => {
                                    if (d.success) { provInput.value = ''; alert('Provision "' + name + '" ditambahkan.'); }
                                    else alert('Error: ' + (d.error || 'Gagal'));
                                }).catch(() => alert('Gagal menambahkan provision.')).finally(() => { provBtn.disabled = false; });
                            });
                        }
                        // Refresh button — sekarang ada di tiap section header (muncul saat section terbuka)
                        cliOutputBox.querySelectorAll('.tr069-refresh').forEach(refreshBtn => {
                            refreshBtn.addEventListener('click', (e) => {
                                e.stopPropagation();
                                refreshBtn.style.opacity = '0.4';
                                fetch('action/genieacs-proxy.php', {
                                    method: 'POST',
                                    headers: {'Content-Type': 'application/json'},
                                    body: JSON.stringify({serial, refresh: true})
                                }).then(r => r.json()).then(() => loadTr069Status())
                                  .catch(() => { refreshBtn.style.opacity = '1'; alert('Gagal refresh.'); });
                            });
                        });
                        // Edit button (PPP Interface section header) — edit VLAN ID & Max MRU Size, push via TR-069
                        cliOutputBox.querySelectorAll('.ppp-save').forEach(btn => {
                            btn.addEventListener('click', () => {
                                const idx = btn.dataset.idx;
                                const fields = {};
                                cliOutputBox.querySelectorAll('.ppp-field[data-idx="'+idx+'"]').forEach(el => { fields[el.dataset.key] = el.value; });
                                cliOutputBox.querySelectorAll('.ppp-radio[data-idx="'+idx+'"]:checked').forEach(el => { fields[el.dataset.key] = el.value; });
                                if (!fields.password) delete fields.password; // kosong = tidak diubah
                                btn.disabled = true; btn.textContent = 'Menyimpan...';
                                fetch('action/genieacs-proxy.php', {
                                    method: 'POST',
                                    headers: {'Content-Type': 'application/json'},
                                    body: JSON.stringify({serial, edit_ppp: true, ...fields})
                                }).then(r => r.json()).then(d => {
                                    if (d.success) { alert(d.message || 'Berhasil dikirim.'); loadTr069Status(); }
                                    else { alert('Error: ' + (d.message || 'Gagal')); btn.disabled = false; btn.textContent = 'Simpan Perubahan'; }
                                }).catch(() => { alert('Gagal mengirim perubahan.'); btn.disabled = false; btn.textContent = 'Simpan Perubahan'; });
                            });
                        });
                        // Simpan Perubahan (card Wireless LAN) — kirim SSID/password/security/channel/radio dst via TR-069.
                        cliOutputBox.querySelectorAll('.wlan-save').forEach(btn => {
                            btn.addEventListener('click', () => {
                                const idx = btn.dataset.idx;
                                const fields = {};
                                cliOutputBox.querySelectorAll('.wlan-field[data-idx="'+idx+'"]').forEach(el => { fields[el.dataset.key] = el.value; });
                                cliOutputBox.querySelectorAll('.wlan-radio[data-idx="'+idx+'"]:checked').forEach(el => { fields[el.dataset.key] = el.value; });
                                const chMode = cliOutputBox.querySelector('.wlan-chmode[data-idx="'+idx+'"]:checked');
                                fields.auto_channel = chMode && chMode.value === 'auto' ? '1' : '0';
                                if (!fields.password) delete fields.password; // kosong = tidak diubah
                                btn.disabled = true; btn.textContent = 'Menyimpan...';
                                fetch('action/genieacs-proxy.php', {
                                    method: 'POST',
                                    headers: {'Content-Type': 'application/json'},
                                    body: JSON.stringify({serial, edit_wlan: true, wlan_index: idx, ...fields})
                                }).then(r => r.json()).then(d => {
                                    if (d.success) { alert(d.message || 'Berhasil dikirim.'); loadTr069Status(); }
                                    else { alert('Error: ' + (d.message || 'Gagal')); btn.disabled = false; btn.textContent = 'Simpan Perubahan'; }
                                }).catch(() => { alert('Gagal mengirim perubahan.'); btn.disabled = false; btn.textContent = 'Simpan Perubahan'; });
                            });
                        });
                        cliOutputBox.querySelectorAll('.ppp-reset').forEach(btn => {
                            btn.addEventListener('click', () => {
                                if (!confirm('Reset koneksi PPP sekarang? ONU akan reconnect.')) return;
                                btn.disabled = true; btn.textContent = 'Resetting...';
                                fetch('action/genieacs-proxy.php', {
                                    method: 'POST',
                                    headers: {'Content-Type': 'application/json'},
                                    body: JSON.stringify({serial, ppp_reset: true})
                                }).then(r => r.json()).then(d => {
                                    alert(d.success ? (d.message || 'Reset dikirim.') : 'Error: ' + (d.message || 'Gagal'));
                                    btn.disabled = false; btn.textContent = 'Reset Connection';
                                }).catch(() => { alert('Gagal reset.'); btn.disabled = false; btn.textContent = 'Reset Connection'; });
                            });
                        });
                        cliOutputBox.querySelectorAll('.ppp-remove').forEach(btn => {
                            btn.addEventListener('click', () => {
                                if (!confirm('HAPUS interface PPP WAN ini dari device? Device akan kehilangan koneksi PPPoE (kembali ke WAN kosong/DHCP default). Aksi ini TIDAK BISA dibatalkan.')) return;
                                btn.disabled = true; btn.textContent = 'Menghapus...';
                                fetch('action/genieacs-proxy.php', {
                                    method: 'POST',
                                    headers: {'Content-Type': 'application/json'},
                                    body: JSON.stringify({serial, ppp_remove: true})
                                }).then(r => r.json()).then(d => {
                                    alert(d.success ? (d.message || 'PPP WAN dihapus.') : 'Error: ' + (d.message || 'Gagal'));
                                    if (d.success) loadTr069Status();
                                    else { btn.disabled = false; btn.textContent = 'Remove PPP WAN'; }
                                }).catch(() => { alert('Gagal menghapus.'); btn.disabled = false; btn.textContent = 'Remove PPP WAN'; });
                            });
                        });
                        cliOutputBox.style.maxHeight = 'none';
                    })
                    .catch(() => { cliOutputBox.textContent = 'Gagal mengambil data GenieACS.'; })
                    .finally(() => { btnTr069.disabled = false; });
            }
            btnTr069.addEventListener('click', loadTr069Status);
        }

        // Global Escape Key to close all modals
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal.open').forEach(modal => {
                    modal.classList.remove('open');
                });
            }
        });

        // Global click-outside modal closer
        document.querySelectorAll('.modal').forEach(modal => {
            modal.addEventListener('click', (e) => {
                if (e.target === modal) {
                    modal.classList.remove('open');
                }
            });
        });

        });
</script>

<!-- Modal Replace ONU (Ganti Serial Number) -->
<div class="modal" id="replace-onu-modal">
    <div class="modal-content" style="max-width: 500px; width: 90%; border-radius: 6px; box-shadow: 0 4px 20px rgba(0,0,0,0.15);">
        <div class="modal-header" style="padding: 15px 24px; display: flex; justify-content: space-between; align-items: center;">
            <div style="display:flex; align-items:center; gap:10px;">
                <i data-lucide="refresh-cw" style="width:18px;height:18px; color:var(--text-accent);"></i>
                <h3 style="margin:0; font-size:1.2rem; font-weight:700; color:var(--text-main);">Replace ONU / Ganti SN</h3>
            </div>
            <button type="button" class="close-btn" onclick="document.getElementById('replace-onu-modal').classList.remove('open')" style="background:none; border:none; font-size:1.5rem; cursor:pointer; color:var(--text-muted); line-height: 1;">&times;</button>
        </div>
        <form action="action/replace-onu.php" method="POST" onsubmit="return confirmReplaceOnu(event)">
            <input type="hidden" name="onu_id" value="<?php echo (int)$onu['id']; ?>">
            <div class="modal-body" style="padding: 24px; font-size:0.95rem; color:var(--text-main);">
                <div style="background-color: var(--tint-warning); border: 1px solid var(--color-amber); border-radius: 6px; padding: 12px 16px; margin-bottom: 18px; color: var(--color-amber); line-height: 1.5; font-size: 0.85rem;">
                    <strong>⚠️ PERINGATAN REPLACE ONU:</strong><br>
                    Proses ini akan menghapus ONU lama dari OLT fisik, mengubah Serial Number ke SN baru di database, lalu mendaftarkan ulang dan mengirimkan konfigurasi WAN lama (VLAN, PPPoE username/password, speed profile, dsb.) ke perangkat ONU baru secara otomatis.
                </div>
                
                <div style="margin-bottom: 15px;">
                    <label style="font-weight: 600; display: block; margin-bottom: 6px; color:var(--text-main);">Serial Number Lama</label>
                    <input type="text" class="modal-input" value="<?php echo htmlspecialchars($onu['serial_number']); ?>" readonly style="background-color:var(--bg-tertiary); cursor:not-allowed; border: 1px solid var(--border-color); border-radius: 4px; padding: 8px 12px; width: 100%; box-sizing: border-box; font-family: monospace; color:var(--text-main);">
                </div>
                
                <div style="margin-bottom: 15px;">
                    <label style="font-weight: 600; display: block; margin-bottom: 6px; color:var(--text-main);">Serial Number Baru <span style="color:var(--color-danger);">*</span></label>
                    <input type="text" name="new_serial_number" required placeholder="Contoh: ZTEGC1234567" style="border: 1px solid var(--border-color); border-radius: 4px; padding: 8px 12px; width: 100%; box-sizing: border-box; font-family: monospace; font-size: 0.95rem; text-transform: uppercase; color:var(--text-main); background:var(--bg-tertiary);">
                </div>
            </div>
            <div class="modal-footer" style="padding: 15px 24px; display:flex; justify-content:flex-end; align-items:center; gap:12px;">
                <button type="button" onclick="document.getElementById('replace-onu-modal').classList.remove('open')" style="background:none; border:none; color:var(--text-accent); font-weight: 500; cursor:pointer; font-size:0.95rem; padding: 8px 12px;">Batal</button>
                <button type="submit" class="btn btn-danger" style="padding:8px 24px;">Proses Replace</button>
            </div>
        </form>
    </div>
</div>

<script>
    function confirmReplaceOnu(event) {
        if (!confirm("Apakah Anda yakin ingin melakukan REPLACE ONU?\nONT lama akan dihapus dari OLT fisik dan ONT baru akan dikonfigurasi ulang secara otomatis.")) {
            event.preventDefault();
            return false;
        }
        return true;
    }
</script>

<!-- Modal IP Manajemen -->
<div class="modal" id="mgmt-ip-modal">
    <div class="modal-content" style="max-width:520px; width:90%;">
        <div class="modal-header" style="padding:15px 24px; display:flex; justify-content:space-between; align-items:center;">
            <h3 style="margin:0; font-size:1.3rem; font-weight:700;">IP Manajemen</h3>
            <button type="button" onclick="document.getElementById('mgmt-ip-modal').classList.remove('open')" style="background:none;border:none;font-size:1.5rem;cursor:pointer;color:var(--text-muted);">&times;</button>
        </div>
        <form id="mgmt-ip-form" onsubmit="return saveMgmtIp(event)">
            <input type="hidden" name="onu_id" value="<?php echo (int)$onu['id']; ?>">
            <div class="modal-body" style="padding:24px;">
                <table style="width:100%;border-collapse:collapse;">
                    <tr style="height:50px;">
                        <td style="width:140px;font-weight:600;color:var(--text-main);vertical-align:middle;">Mode</td>
                        <td>
                            <select name="mgmt_ip_mode" id="mgmt-mode-select" style="width:100%;max-width:280px;padding:8px 12px;border:1px solid var(--border-color);border-radius:4px;background:var(--bg-tertiary);font-size:0.9rem;color:var(--text-main);" onchange="toggleMgmtFields()">
                                <option value="Inactive" <?php echo ($onu['mgmt_ip_mode'] ?? 'Inactive') === 'Inactive' ? 'selected' : ''; ?>>Nonaktif</option>
                                <option value="DHCP" <?php echo ($onu['mgmt_ip_mode'] ?? '') === 'DHCP' ? 'selected' : ''; ?>>DHCP</option>
                                <option value="Static" <?php echo ($onu['mgmt_ip_mode'] ?? '') === 'Static' ? 'selected' : ''; ?>>Static</option>
                            </select>
                        </td>
                    </tr>
                    <tr style="height:50px;" class="mgmt-detail-row">
                        <td style="font-weight:600;color:var(--text-main);vertical-align:middle;">VLAN</td>
                        <td>
                            <select name="mgmt_vlan" style="width:100%;max-width:280px;padding:8px 12px;border:1px solid var(--border-color);border-radius:4px;background:var(--bg-tertiary);font-size:0.9rem;color:var(--text-main);">
                                <option value="">Pilih VLAN...</option>
                                <?php foreach ($management_vlans as $mv): ?>
                                    <option value="<?php echo (int)$mv['vlan_id']; ?>" <?php echo (int)($onu['mgmt_vlan'] ?? 0) === (int)$mv['vlan_id'] ? 'selected' : ''; ?>><?php echo (int)$mv['vlan_id']; ?> — <?php echo htmlspecialchars($mv['description'] ?? ''); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (empty($management_vlans)): ?>
                                <small style="color:var(--color-orange);">Belum ada VLAN yang ditandai sebagai management. Tambahkan VLAN bertipe management di OLT Settings terlebih dahulu.</small>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr style="height:50px;" class="mgmt-static-row" id="mgmt-ip-row">
                        <td style="font-weight:600;color:var(--text-main);vertical-align:middle;">IP Address</td>
                        <td><input type="text" name="mgmt_ip" value="<?php echo htmlspecialchars($onu['mgmt_ip'] ?? ''); ?>" placeholder="192.168.1.100" style="width:100%;max-width:280px;padding:8px 12px;border:1px solid var(--border-color);border-radius:4px;background:var(--bg-tertiary);font-size:0.9rem;color:var(--text-main);"></td>
                    </tr>
                    <tr style="height:50px;" class="mgmt-detail-row">
                        <td style="font-weight:600;color:var(--text-main);vertical-align:middle;">Akses Remote WAN</td>
                        <td>
                            <select name="wan_remote_access" style="width:100%;max-width:280px;padding:8px 12px;border:1px solid var(--border-color);border-radius:4px;background:var(--bg-tertiary);font-size:0.9rem;color:var(--text-main);">
                                <option value="no" <?php echo ($onu['wan_remote_access'] ?? 'no') !== 'yes' ? 'selected' : ''; ?>>Nonaktif</option>
                                <option value="yes" <?php echo ($onu['wan_remote_access'] ?? '') === 'yes' ? 'selected' : ''; ?>>Aktif dari semua jaringan internet</option>
                            </select>
                        </td>
                    </tr>
                </table>
                <small style="display:block;margin-top:12px;color:var(--text-muted);line-height:1.4;">IP Manajemen digunakan untuk remote akses ONU via web. VLAN harus ditandai sebagai management di halaman OLT VLAN. Akses Remote WAN membuka akses ONU dari internet luar.</small>
            </div>
            <div class="modal-footer" style="display:flex;justify-content:flex-end;gap:8px;padding:16px 24px;">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('mgmt-ip-modal').classList.remove('open')">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal TR609 Profile -->
<div class="modal" id="tr609-profile-modal">
    <div class="modal-content" style="max-width:480px; width:90%;">
        <div class="modal-header" style="padding:15px 24px; display:flex; justify-content:space-between; align-items:center;">
            <h3 style="margin:0; font-size:1.3rem; font-weight:700;">TR609 Profile</h3>
            <button type="button" onclick="document.getElementById('tr609-profile-modal').classList.remove('open')" style="background:none;border:none;font-size:1.5rem;cursor:pointer;color:var(--text-muted);">&times;</button>
        </div>
        <form id="tr609-form" onsubmit="return saveTr609(event)">
            <input type="hidden" name="onu_id" value="<?php echo (int)$onu['id']; ?>">
            <div class="modal-body" style="padding:24px;">
                <table style="width:100%;border-collapse:collapse;">
                    <tr style="height:50px;">
                        <td style="width:120px;font-weight:600;color:var(--text-main);vertical-align:middle;">Profil</td>
                        <td>
                            <select name="tr609_profile" style="width:100%;max-width:300px;padding:8px 12px;border:1px solid var(--border-color);border-radius:4px;background:var(--bg-tertiary);font-size:0.9rem;color:var(--text-main);">
                                <option value="Nonaktif" <?php echo ($onu['tr069_profile'] ?? '') === 'Nonaktif' ? 'selected' : ''; ?>>Nonaktifkan TR069</option>
                                <option value="ACS-Smartolt" <?php echo ($onu['tr069_profile'] ?? 'ACS-Smartolt') === 'ACS-Smartolt' ? 'selected' : ''; ?>>ACS-Smartolt</option>
                                <?php foreach ($tr069_profiles as $tp): ?>
                                    <?php if (!empty($tp['is_default'])) continue; ?>
                                    <option value="<?php echo htmlspecialchars($tp['name']); ?>" <?php echo ($onu['tr069_profile'] ?? 'ACS-Smartolt') === $tp['name'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($tp['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                </table>
                <div id="tr609-profile-info" style="margin-top:16px;padding:14px;background:var(--bg-secondary);border:1px solid var(--border-color);border-radius:var(--radius-sm);font-size:0.85rem;line-height:1.8;">
                    <div><strong>ACS URL:</strong> <span id="tr609-info-url">-</span></div>
                    <div><strong>Username:</strong> <span id="tr609-info-user">-</span></div>
                </div>
                <small style="display:block;margin-top:12px;color:var(--text-muted);line-height:1.4;">Pilih profil, lalu Simpan untuk push TR609 config ke ONU via OLT CLI. Ubah profil di Settings &gt; TR-069 Management.</small>
            </div>
            <div class="modal-footer" style="display:flex;justify-content:flex-end;gap:8px;padding:16px 24px;">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('tr609-profile-modal').classList.remove('open')">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan</button>
            </div>
        </form>
    </div>
</div>

<script>
function toggleMgmtFields() {
    const mode = document.getElementById('mgmt-mode-select').value;
    document.querySelectorAll('.mgmt-detail-row').forEach(r => r.style.display = (mode === 'Inactive') ? 'none' : '');
    document.querySelectorAll('.mgmt-static-row').forEach(r => r.style.display = (mode === 'Static') ? '' : 'none');
}
toggleMgmtFields();

function saveMgmtIp(e) {
    e.preventDefault();
    const form = document.getElementById('mgmt-ip-form');
    const fd = new FormData(form);
    const mode = fd.get('mgmt_ip_mode');
    const vlan = fd.get('mgmt_vlan');
    if (mode !== 'Inactive' && !vlan) {
        alert('Pilih VLAN management terlebih dahulu.\nJika tidak ada VLAN, tandai VLAN bertipe management di OLT Settings.');
        return false;
    }
    fetch('action/onu-set-mgmt-ip.php', {method:'POST', body:fd})
    .then(r => r.json()).then(d => {
        if (d.success) {
            document.getElementById('mgmt-ip-modal').classList.remove('open');
            location.reload();
        } else { alert(d.message || 'Gagal simpan'); }
    }).catch(() => alert('Error koneksi'));
    return false;
}

function saveTr609(e) {
    e.preventDefault();
    const form = document.getElementById('tr609-form');
    const fd = new FormData(form);
    fetch('action/onu-set-tr609.php', {method:'POST', body:fd})
    .then(r => r.json()).then(d => {
        if (d.success) {
            document.getElementById('tr609-profile-modal').classList.remove('open');
            location.reload();
        } else { alert(d.message || 'Gagal simpan'); }
    }).catch(() => alert('Error koneksi'));
    return false;
}

// Profile info panel — update saat dropdown berubah
const tr609Profiles = <?php
$profilesMap = ['ACS-Smartolt' => ['acs_url' => 'http://10.198.198.1:7547', 'acs_username' => '', 'acs_password' => '']];
foreach ($tr069_profiles as $tp) {
    if (!empty($tp['is_default'])) continue;
    $profilesMap[$tp['name']] = ['acs_url' => $tp['acs_url'], 'acs_username' => $tp['acs_username'] ?? '', 'acs_password' => '***'];
}
echo json_encode($profilesMap);
 ?>;
function updateTr609Info() {
    const name = document.querySelector('[name="tr609_profile"]').value;
    const p = tr609Profiles[name] || {};
    document.getElementById('tr609-info-url').textContent = p.acs_url || '-';
    document.getElementById('tr609-info-user').textContent = p.acs_username || '-';
}
document.querySelector('[name="tr609_profile"]').addEventListener('change', updateTr609Info);
updateTr609Info();
</script>

<?php require_once __DIR__ . '/onu-detail-css.php'; ?>
<?php require_once __DIR__ . '/footer.php'; ?>
