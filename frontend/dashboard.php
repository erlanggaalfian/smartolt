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

<div class="stats-grid motion-stagger">
    <div class="stat-card motion-fade-up">
        <div class="stat-icon bg-blue"><i data-lucide="server"></i></div>
        <div class="stat-info"><div class="stat-title">Total OLT</div>
            <p><?php echo htmlspecialchars($olt_count); ?></p>
        </div>
    </div>
    <div class="stat-card motion-fade-up">
        <div class="stat-icon bg-green"><i data-lucide="smile"></i></div>
        <div class="stat-info"><div class="stat-title">ONU Online</div>
            <p><?php echo htmlspecialchars($onu_online); ?></p>
        </div>
    </div>
    <div class="stat-card motion-fade-up">
        <div class="stat-icon bg-red"><i data-lucide="frown"></i></div>
        <div class="stat-info"><div class="stat-title">ONU Offline</div>
            <p><?php echo htmlspecialchars($onu_offline); ?></p>
        </div>
    </div>
    <div class="stat-card motion-fade-up">
        <div class="stat-icon bg-orange"><i data-lucide="scan-eye"></i></div>
        <div class="stat-info"><div class="stat-title">Unconfigured</div>
            <p><a href="unconfigured.php" class="link-plain">Scan Baru</a></p>
        </div>
    </div>
</div>

<!-- Signal Quality Health -->
<div class="inline-flex-center mb-4">
    <i data-lucide="radio" class="icon-sm text-muted"></i>
    <span class="section-eyebrow">Signal Quality</span>
</div>
<div class="stats-grid motion-stagger scroll-reveal">
    <a href="configured.php?signal=critical" class="stat-card motion-fade-up link-plain">
        <div class="stat-icon stat-icon--tint-red"><i data-lucide="alert-triangle"></i></div>
        <div class="stat-info"><div class="stat-title">Critical</div>
            <p class="stat-value stat-value--red"><?php echo (int)$signal['critical']; ?></p>
        </div>
    </a>
    <a href="configured.php?signal=weak" class="stat-card motion-fade-up link-plain">
        <div class="stat-icon stat-icon--tint-amber"><i data-lucide="signal-medium"></i></div>
        <div class="stat-info"><div class="stat-title">Weak</div>
            <p class="stat-value stat-value--amber"><?php echo (int)$signal['weak']; ?></p>
        </div>
    </a>
    <a href="configured.php?signal=fair" class="stat-card motion-fade-up link-plain">
        <div class="stat-icon stat-icon--tint-blue"><i data-lucide="signal"></i></div>
        <div class="stat-info"><div class="stat-title">Fair</div>
            <p class="stat-value"><?php echo (int)$signal['fair']; ?></p>
        </div>
    </a>
    <a href="configured.php?signal=good" class="stat-card motion-fade-up link-plain">
        <div class="stat-icon bg-green"><i data-lucide="signal-high"></i></div>
        <div class="stat-info"><div class="stat-title">Good</div>
            <p class="stat-value stat-value--green"><?php echo (int)$signal['good']; ?></p>
        </div>
    </a>
</div>

<div class="content-row">
    <!-- Tabel Ringkasan OLT -->
    <div class="content-card col-8">
        <div class="card-header border-accent">
            <h1 id="page-title">Ringkasan OLT Terdaftar</h1>
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
                                <td colspan="7" class="empty-state">Belum ada OLT terdaftar. Silakan tambahkan OLT di menu Setting.</td>
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
                                        <span class="fw-600" style="color:<?php 
                                            $t_val = (float)$olt['last_temp'];
                                            if ($t_val > 55) echo 'var(--color-danger)';
                                            elseif ($t_val > 48) echo 'var(--color-warning)';
                                            else echo 'inherit';
                                        ?>;">
                                            <?php echo htmlspecialchars($olt['last_temp'] ?: 'N/A'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="olt-detail.php?id=<?php echo (int)$olt['id']; ?>" class="btn btn-xs btn-outline">
                                            <i data-lucide="activity" class="icon-xs"></i> Monitor
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
                    <li class="activity-item text-muted">Belum ada log aktivitas.</li>
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

<script>
// Scroll reveal observer
(function() {
    const obs = new IntersectionObserver((entries) => {
        entries.forEach(e => { if (e.isIntersecting) { e.target.classList.add('visible'); obs.unobserve(e.target); } });
    }, { threshold: 0.1 });
    document.querySelectorAll('.scroll-reveal').forEach(el => obs.observe(el));
})();
</script>