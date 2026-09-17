<?php
// ==============================================================================
# SmartOLT Activity Logs Page
// ==============================================================================
require_once __DIR__ . '/header.php';

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$action_filter = isset($_GET['action_type']) ? trim($_GET['action_type']) : '';

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
if (!in_array($limit, [20, 50, 100, 200])) $limit = 100;
$page = max(1, (int)($_GET['page'] ?? 1));

// Build filter
$params = [];
$filter_sql = '';
if ($search !== '') {
    $filter_sql .= ' AND (l.message LIKE ? OR l.action LIKE ?)';
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}
if ($action_filter !== '') {
    $filter_sql .= ' AND l.action = ?';
    $params[] = $action_filter;
}

// Count
$stmt = $pdo->prepare("SELECT COUNT(*) FROM logs l WHERE 1=1{$filter_sql}");
$stmt->execute($params);
$total_items = (int)$stmt->fetchColumn();
$total_pages = max(1, (int)ceil($total_items / $limit));
if ($page > $total_pages) $page = $total_pages;
$offset = ($page - 1) * $limit;

// Fetch page
$stmt = $pdo->prepare("SELECT l.*, o.name AS olt_name
                        FROM logs l
                        LEFT JOIN olts o ON l.olt_id = o.id
                        WHERE 1=1{$filter_sql}
                        ORDER BY l.timestamp DESC
                        LIMIT {$limit} OFFSET {$offset}");
$stmt->execute($params);
$logs = $stmt->fetchAll();

// Available action types for filter dropdown
$action_types = $pdo->query("SELECT DISTINCT action FROM logs ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);

// Build base query string for pagination links
$base_qs = ['limit' => $limit];
if ($search !== '') $base_qs['search'] = $search;
if ($action_filter !== '') $base_qs['action_type'] = $action_filter;
function log_page_url($p, $base_qs) {
    $qs = $base_qs;
    $qs['page'] = $p;
    return 'logs.php?' . http_build_query($qs);
}

$success_msg = isset($_SESSION['success']) ? $_SESSION['success'] : '';
unset($_SESSION['success']);
?>

<?php if ($success_msg): ?>
    <div style="background:rgba(16,185,129,0.15); border:1px solid var(--color-success); color:var(--color-success); padding:12px; border-radius:var(--radius-sm); margin-bottom:20px; font-weight:600;">
        ✓ <?php echo htmlspecialchars($success_msg); ?>
    </div>
<?php endif; ?>

<div class="section-actions" style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
    <form action="action/clear-logs.php" method="POST" onsubmit="return confirm('Apakah Anda yakin ingin menghapus semua log aktivitas? Tindakan ini tidak dapat dibatalkan.');" style="margin:0;">
        <button type="submit" class="btn btn-danger">
            <i data-lucide="trash-2"></i> Bersihkan Semua Log
        </button>
    </form>

    <form method="GET" action="logs.php" style="display:flex; gap:8px; align-items:center; margin:0;">
        <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>"
               placeholder="Cari log..." class="form-input" style="width:200px;">
        <select name="action_type" class="form-input" style="width:160px;">
            <option value="">Semua Aksi</option>
            <?php foreach ($action_types as $at): ?>
                <option value="<?php echo htmlspecialchars($at); ?>" <?php echo $action_filter === $at ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($at); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <select name="limit" class="form-input" style="width:80px;" onchange="this.form.submit()">
            <?php foreach ([20, 50, 100, 200] as $l): ?>
                <option value="<?php echo $l; ?>" <?php echo $limit === $l ? 'selected' : ''; ?>><?php echo $l; ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-primary btn-sm"><i data-lucide="search"></i></button>
    </form>
</div>

<div class="content-card">
    <div class="card-header border-accent" style="display:flex; justify-content:space-between; align-items:center;">
        <h2>Audit Logs Aktivitas Sistem</h2>
        <span style="color:var(--text-muted); font-size:13px;"><?php echo number_format($total_items); ?> total</span>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Waktu & Tanggal</th>
                        <th>Target OLT</th>
                        <th>Kategori Aksi</th>
                        <th>Keterangan Aktivitas</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="4" style="text-align:center;color:var(--text-muted);padding:16px;">Log aktivitas kosong.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($logs as $log): ?>
                            <tr>
                                <td><code><?php echo htmlspecialchars(date('d/m/Y H:i:s', strtotime($log['timestamp']))); ?></code></td>
                                <td><?php echo htmlspecialchars($log['olt_name'] ?: 'System / General'); ?></td>
                                <td>
                                    <?php
                                    $action = $log['action'];
                                    $badge_class = 'bg-green';
                                    if (strpos($action, 'DELETE') !== false || strpos($action, 'CLEAR') !== false) {
                                        $badge_class = 'bg-red';
                                    } elseif (strpos($action, 'REBOOT') !== false || strpos($action, 'ADD') !== false) {
                                        $badge_class = 'bg-orange';
                                    }
                                    ?>
                                    <span class="badge <?php echo $badge_class; ?>"><?php echo htmlspecialchars($action); ?></span>
                                </td>
                                <td><?php echo htmlspecialchars($log['message']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if ($total_pages > 1): ?>
<nav style="display:flex; justify-content:center; gap:4px; margin-top:16px;">
    <?php if ($page > 1): ?>
        <a href="<?php echo log_page_url(1, $base_qs); ?>" class="btn btn-sm btn-outline">«</a>
        <a href="<?php echo log_page_url($page - 1, $base_qs); ?>" class="btn btn-sm btn-outline">‹</a>
    <?php endif; ?>

    <?php
    $start = max(1, $page - 2);
    $end = min($total_pages, $page + 2);
    for ($i = $start; $i <= $end; $i++):
    ?>
        <?php if ($i === $page): ?>
            <span class="btn btn-sm btn-primary"><?php echo $i; ?></span>
        <?php else: ?>
            <a href="<?php echo log_page_url($i, $base_qs); ?>" class="btn btn-sm btn-outline"><?php echo $i; ?></a>
        <?php endif; ?>
    <?php endfor; ?>

    <?php if ($page < $total_pages): ?>
        <a href="<?php echo log_page_url($page + 1, $base_qs); ?>" class="btn btn-sm btn-outline">›</a>
        <a href="<?php echo log_page_url($total_pages, $base_qs); ?>" class="btn btn-sm btn-outline">»</a>
    <?php endif; ?>
</nav>
<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
