<?php
// ==============================================================================
# SmartOLT Dashboard Page
// ==============================================================================
require_once __DIR__ . '/header.php';

// Ambil data statistik dari database
$allowed_ids = get_allowed_olt_ids();
$allowed_ids_str = implode(',', array_map('intval', $allowed_ids)) ?: '0';

$olt_count = count($allowed_ids);
$onu_counts = $pdo->query("SELECT SUM(status='online') AS online, SUM(status='offline') AS offline FROM onus WHERE olt_id IN ($allowed_ids_str)")->fetch(PDO::FETCH_ASSOC);
$onu_online = (int)($onu_counts['online'] ?? 0);
$onu_offline = (int)($onu_counts['offline'] ?? 0);

// Signal quality distribution (single query, 4 buckets)
$sig_sth = $pdo->prepare("
    SELECT
        SUM(last_rx_power IS NOT NULL AND last_rx_power < -30) AS critical,
        SUM(last_rx_power >= -30 AND last_rx_power < -28) AS weak,
        SUM(last_rx_power >= -28 AND last_rx_power < -25) AS fair,
        SUM(last_rx_power >= -25) AS good,
        SUM(last_rx_power IS NULL) AS unknown
    FROM onus WHERE olt_id IN ($allowed_ids_str)
");
$sig_sth->execute();
$signal = $sig_sth->fetch(PDO::FETCH_ASSOC);

// Ambil daftar OLT untuk tabel ringkasan
$olts = get_allowed_olts();

// Ambil log aktivitas terakhir
$logs = $pdo->query("SELECT l.*, o.name as olt_name FROM logs l LEFT JOIN olts o ON l.olt_id = o.id WHERE l.olt_id IS NULL OR l.olt_id IN ($allowed_ids_str) ORDER BY l.timestamp DESC LIMIT 5")->fetchAll();
?>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon bg-blue"><i data-lucide="server"></i></div>
        <div class="stat-info">
            <h3>Total OLT</h3>
            <p><?php echo htmlspecialchars($olt_count); ?></p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon bg-green"><i data-lucide="smile"></i></div>
        <div class="stat-info">
            <h3>ONU Online</h3>
            <p><?php echo htmlspecialchars($onu_online); ?></p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon bg-red"><i data-lucide="frown"></i></div>
        <div class="stat-info">
            <h3>ONU Offline</h3>
            <p><?php echo htmlspecialchars($onu_offline); ?></p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon bg-orange"><i data-lucide="scan-eye"></i></div>
        <div class="stat-info">
            <h3>Unconfigured</h3>
            <p><a href="unconfigured.php" style="color:inherit;text-decoration:none;">Scan Baru</a></p>
        </div>
    </div>
</div>

<!-- Signal Quality Health -->
<div style="display:flex;align-items:center;gap:8px;margin-bottom:16px;">
    <i data-lucide="radio" style="width:16px;height:16px;color:var(--text-muted);"></i>
    <span style="font-weight:600;font-size:0.85rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.5px;">Signal Quality</span>
</div>
<div class="stats-grid">
    <a href="configured.php?signal=critical" class="stat-card" style="text-decoration:none;color:inherit;">
        <div class="stat-icon" style="background:rgba(239,68,68,0.15);color:#ef4444;"><i data-lucide="alert-triangle"></i></div>
        <div class="stat-info">
            <h3>Critical</h3>
            <p style="color:#ef4444;font-weight:700;"><?php echo (int)$signal['critical']; ?></p>
        </div>
    </a>
    <a href="configured.php?signal=weak" class="stat-card" style="text-decoration:none;color:inherit;">
        <div class="stat-icon" style="background:rgba(245,158,11,0.15);color:#f59e0b;"><i data-lucide="signal-medium"></i></div>
        <div class="stat-info">
            <h3>Weak</h3>
            <p style="color:#f59e0b;font-weight:700;"><?php echo (int)$signal['weak']; ?></p>
        </div>
    </a>
    <a href="configured.php?signal=fair" class="stat-card" style="text-decoration:none;color:inherit;">
        <div class="stat-icon" style="background:rgba(59,130,246,0.15);color:#3b82f6;"><i data-lucide="signal"></i></div>
        <div class="stat-info">
            <h3>Fair</h3>
            <p style="font-weight:700;"><?php echo (int)$signal['fair']; ?></p>
        </div>
    </a>
    <a href="configured.php?signal=good" class="stat-card" style="text-decoration:none;color:inherit;">
        <div class="stat-icon bg-green"><i data-lucide="signal-high"></i></div>
        <div class="stat-info">
            <h3>Good</h3>
            <p style="color:#22c55e;font-weight:700;"><?php echo (int)$signal['good']; ?></p>
        </div>
    </a>
</div>

<div class="content-row">
    <!-- Tabel Ringkasan OLT -->
    <div class="content-card col-8">
        <div class="card-header border-accent">
            <h2>Ringkasan OLT Terdaftar</h2>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Nama OLT</th>
                            <th>Alamat IP</th>
                            <th>Status SNMP</th>
                            <th>CPU Load</th>
                            <th>RAM Usage</th>
                            <th>Suhu Board</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($olts)): ?>
                            <tr>
                                <td colspan="7" style="text-align:center;color:var(--text-muted);">Belum ada OLT terdaftar. Silakan tambahkan OLT di menu Setting.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($olts as $olt): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($olt['name']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($olt['ip']); ?></td>
                                    <td>
                                        <span class="badge <?php echo ($olt['last_snmp_status'] === 'Connected') ? 'bg-green' : 'bg-red'; ?>">
                                            ● <?php echo htmlspecialchars($olt['last_snmp_status'] ?: 'Belum Diuji'); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($olt['last_cpu'] ?: 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($olt['last_ram'] ?: 'N/A'); ?></td>
                                    <td>
                                        <span style="font-weight:600; color:<?php 
                                            $t_val = (float)$olt['last_temp'];
                                            if ($t_val > 55) echo 'var(--color-danger)';
                                            elseif ($t_val > 48) echo 'var(--color-warning)';
                                            else echo 'inherit';
                                        ?>;">
                                            <?php echo htmlspecialchars($olt['last_temp'] ?: 'N/A'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="olt-detail.php?id=<?php echo (int)$olt['id']; ?>" class="btn btn-xs btn-outline" style="text-decoration:none; display:inline-flex; align-items:center;">
                                            <i data-lucide="activity" style="margin-right:2px; width:12px; height:12px;"></i> Monitor
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Aktivitas Log Terakhir -->
    <div class="content-card col-4">
        <div class="card-header">
            <h2>Log Aktivitas Terakhir</h2>
        </div>
        <div class="card-body">
            <ul class="activity-list">
                <?php if (empty($logs)): ?>
                    <li class="activity-item" style="color:var(--text-muted);">Belum ada log aktivitas.</li>
                <?php else: ?>
                    <?php foreach ($logs as $log): ?>
                        <li class="activity-item">
                            <span class="activity-time"><?php echo htmlspecialchars(date('H:i:s d/m/Y', strtotime($log['timestamp']))); ?> - <?php echo htmlspecialchars($log['action']); ?></span>
                            <div class="activity-text"><?php echo htmlspecialchars($log['message']); ?></div>
                        </li>
                    <?php endforeach; ?>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
