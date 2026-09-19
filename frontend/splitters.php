<?php
require_once __DIR__ . '/../backend/db.php';

// Auth check sebelum POST handler — prevent unauthenticated mutation
if (!isset($_SESSION["smartolt_role"]) || $_SESSION["smartolt_role"] !== "superadmin") {
    $_SESSION["error"] = "Akses ditolak!";
    header("Location: ../dashboard.php");
    exit;
}


// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF validation (page is outside /action/, so central middleware skips it)
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf_token)) {
        $_SESSION['error'] = 'Sesi keamanan (CSRF) kedaluwarsa atau tidak valid.';
        header('Location: splitters.php');
        exit;
    }
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $stmt = $pdo->prepare("INSERT INTO splitters (name, external_id, nr_of_ports, comment, note, zone, latitude, longitude) VALUES (?,?,?,?,?,?,?,?)");
        $stmt->execute([
            trim($_POST['name'] ?? ''),
            trim($_POST['external_id'] ?? '') ?: null,
            intval($_POST['nr_of_ports'] ?? 0) ?: null,
            trim($_POST['comment'] ?? '') ?: null,
            trim($_POST['note'] ?? '') ?: null,
            $_POST['zone'] ?: null,
            floatval($_POST['latitude'] ?? 0) ?: null,
            floatval($_POST['longitude'] ?? 0) ?: null
        ]);
    } elseif ($action === 'update') {
        $id = intval($_POST['id'] ?? 0);
        if ($id) {
            $stmt = $pdo->prepare("UPDATE splitters SET name=?, external_id=?, nr_of_ports=?, comment=?, note=?, zone=?, latitude=?, longitude=? WHERE id=?");
            $stmt->execute([
                trim($_POST['name'] ?? ''),
                trim($_POST['external_id'] ?? '') ?: null,
                intval($_POST['nr_of_ports'] ?? 0) ?: null,
                trim($_POST['comment'] ?? '') ?: null,
                trim($_POST['note'] ?? '') ?: null,
                $_POST['zone'] ?: null,
                floatval($_POST['latitude'] ?? 0) ?: null,
                floatval($_POST['longitude'] ?? 0) ?: null,
                $id
            ]);
        }
    } elseif ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        if ($id) $pdo->prepare("DELETE FROM splitters WHERE id=?")->execute([$id]);
    }
    header('Location: splitters.php');
    exit;
}

// Edit mode
$edit_id = intval($_GET['edit'] ?? 0);
$edit_splitter = null;
$edit_onus = [];
if ($edit_id) {
    $stmt = $pdo->prepare("SELECT * FROM splitters WHERE id=?");
    $stmt->execute([$edit_id]);
    $edit_splitter = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($edit_splitter) {
        $stmt2 = $pdo->prepare("SELECT id, name, serial_number, onu_id, pon_port FROM onus WHERE splitter=? LIMIT 50");
        $stmt2->execute([$edit_splitter['name']]);
        $edit_onus = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    }
}

// List mode data
$filter_zone = $_GET['zone'] ?? '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$where = 'WHERE 1=1';
$params = [];
if ($filter_zone) { $where .= ' AND s.zone = ?'; $params[] = $filter_zone; }
if ($search !== '') { $where .= ' AND (s.name LIKE ? OR s.zone LIKE ? OR s.external_id LIKE ?)'; $params[] = "%{$search}%"; $params[] = "%{$search}%"; $params[] = "%{$search}%"; }

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
if (!in_array($limit, [20, 50, 100, 200])) $limit = 50;

$count_stmt = $pdo->prepare("SELECT COUNT(*) FROM splitters s $where");
$count_stmt->execute($params);
$total_items = (int)$count_stmt->fetchColumn();

$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($current_page < 1) $current_page = 1;
$total_pages = max(1, ceil($total_items / $limit));
if ($current_page > $total_pages) $current_page = $total_pages;
$offset = ($current_page - 1) * $limit;

$splitters = $pdo->prepare("
    SELECT s.*, COALESCE(cnt.c, 0) as usage_count
    FROM splitters s
    LEFT JOIN (SELECT splitter, COUNT(*) AS c FROM onus GROUP BY splitter) cnt ON cnt.splitter = s.name
    $where ORDER BY s.zone, s.name
    LIMIT $limit OFFSET $offset
");
$splitters->execute($params);
$splitters = $splitters->fetchAll(PDO::FETCH_ASSOC);

// Stats dari keseluruhan hasil filter (bukan cuma halaman ini)
$stats_stmt = $pdo->prepare("
    SELECT COUNT(*) as total_splitters, COALESCE(SUM(nr_of_ports),0) as total_ports
    FROM splitters s $where
");
$stats_stmt->execute($params);
$stats_row = $stats_stmt->fetch(PDO::FETCH_ASSOC);
$total_splitters = (int)$stats_row['total_splitters'];
$total_ports = (int)$stats_row['total_ports'];

$used_stmt = $pdo->prepare("
    SELECT COALESCE(SUM(cnt.c),0) FROM splitters s
    LEFT JOIN (SELECT splitter, COUNT(*) AS c FROM onus GROUP BY splitter) cnt ON cnt.splitter = s.name
    $where
");
$used_stmt->execute($params);
$total_used = (int)$used_stmt->fetchColumn();

$zones = $pdo->query("SELECT DISTINCT zone FROM splitters WHERE zone IS NOT NULL AND zone != '' ORDER BY zone")->fetchAll(PDO::FETCH_COLUMN);

include __DIR__ . '/header.php';
?>

<?php if ($edit_splitter): ?>
    <!-- EDIT MODE -->
    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:20px;">
        <div>
            <h2 style="margin:0;">Edit Splitter</h2>
            <p style="color:#64748b; margin:4px 0 0;"><?php echo htmlspecialchars($edit_splitter['name']); ?></p>
        </div>
        <a href="splitters.php" class="btn btn-secondary">Back to Splitter list</a>
    </div>

    <div style="display:flex; gap:20px; margin-bottom:20px; flex-wrap:wrap;">
        <div class="stat-card" style="min-width:120px;">
            <div class="stat-value"><?php echo (int)$edit_splitter['id']; ?></div>
            <div class="stat-label">ID</div>
        </div>
        <div class="stat-card" style="min-width:120px;">
            <div class="stat-value"><?php echo count($edit_onus); ?></div>
            <div class="stat-label">Attached ONUs</div>
        </div>
        <div class="stat-card" style="min-width:120px;">
            <div class="stat-value"><?php echo htmlspecialchars($edit_splitter['zone'] ?? '—'); ?></div>
            <div class="stat-label">Zone</div>
        </div>
    </div>

    <form method="post" class="card" style="max-width:700px;">
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="id" value="<?php echo (int)$edit_splitter['id']; ?>">

        <div class="form-group">
            <label>Splitter name</label>
            <input type="text" name="name" value="<?php echo htmlspecialchars($edit_splitter['name']); ?>" required>
        </div>

        <div class="form-grid">
            <div class="form-group">
                <label>External ID</label>
                <input type="text" name="external_id" value="<?php echo htmlspecialchars($edit_splitter['external_id'] ?? ''); ?>" placeholder="—">
            </div>
            <div class="form-group">
                <label>Nr of ports</label>
                <input type="number" name="nr_of_ports" value="<?php echo htmlspecialchars($edit_splitter['nr_of_ports'] ?? ''); ?>" min="1" max="128" placeholder="Recommended to set">
            </div>
        </div>

        <div class="form-group">
            <label>Comment</label>
            <input type="text" name="comment" value="<?php echo htmlspecialchars($edit_splitter['comment'] ?? ''); ?>">
        </div>

        <div class="form-group">
            <label>Note</label>
            <textarea name="note" rows="2"><?php echo htmlspecialchars($edit_splitter['note'] ?? ''); ?></textarea>
        </div>

        <div class="form-group">
            <label>Zone</label>
            <select name="zone">
                <option value="">No zone</option>
                <?php foreach ($zones as $z): ?>
                <option value="<?php echo htmlspecialchars($z); ?>" <?php echo ($edit_splitter['zone'] ?? '')===$z?'selected':''; ?>><?php echo htmlspecialchars($z); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-grid">
            <div class="form-group">
                <label>Latitude</label>
                <input type="text" name="latitude" value="<?php echo htmlspecialchars($edit_splitter['latitude'] ?? ''); ?>" placeholder="-7.250445">
            </div>
            <div class="form-group">
                <label>Longitude</label>
                <input type="text" name="longitude" value="<?php echo htmlspecialchars($edit_splitter['longitude'] ?? ''); ?>" placeholder="110.752645">
            </div>
        </div>

        <div style="display:flex; gap:10px; margin-top:20px;">
            <button type="submit" class="btn btn-primary">Save</button>
            <a href="splitters.php" class="btn btn-secondary">Cancel</a>
        </div>
    </form>

    <?php if ($edit_onus): ?>
    <div class="card" style="margin-top:20px;">
        <h3 style="margin:0 0 15px;">Attached ONUs (<?php echo count($edit_onus); ?>)</h3>
        <table class="data-table">
            <thead><tr><th>ONU</th><th>SN</th><th>Name</th></tr></thead>
            <tbody>
                <?php foreach ($edit_onus as $o): ?>
                <tr>
                    <td><a href="onu-detail.php?id=<?php echo (int)$o['id']; ?>">gpon-onu_<?php echo htmlspecialchars($o['pon_port'].':'.$o['onu_id']); ?></a></td>
                    <td class="mono"><?php echo htmlspecialchars($o['serial_number']); ?></td>
                    <td><?php echo htmlspecialchars($o['name']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

<?php else: ?>
    <!-- LIST MODE -->
    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:20px; flex-wrap:wrap; gap:10px;">
        <h2 style="margin:0;">Splitters</h2>
        <div style="display:flex; gap:10px; flex-wrap:wrap;">
            <form method="GET" style="display:flex; gap:10px;">
                <?php if ($filter_zone): ?><input type="hidden" name="zone" value="<?php echo htmlspecialchars($filter_zone); ?>"><?php endif; ?>
                <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Cari nama splitter, zone..." style="padding:6px 10px; border:1px solid #cbd5e1; border-radius:4px; min-width:220px;">
                <button type="submit" class="btn btn-secondary">Cari</button>
                <?php if ($search): ?><a href="splitters.php<?php echo $filter_zone ? '?zone='.urlencode($filter_zone) : ''; ?>" class="btn btn-secondary">Reset</a><?php endif; ?>
            </form>
            <select onchange="if(this.value)location='splitters.php?zone='+this.value; else location='splitters.php';" style="padding:6px 10px; border:1px solid #cbd5e1; border-radius:4px;">
                <option value="">All Zones</option>
                <?php foreach ($zones as $z): ?>
                <option value="<?php echo htmlspecialchars($z); ?>" <?php echo $filter_zone===$z?'selected':''; ?>><?php echo htmlspecialchars($z); ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-primary" onclick="document.getElementById('add-modal').classList.toggle('open')">+ Add Splitter</button>
        </div>
    </div>

    <div style="display:flex; gap:15px; margin-bottom:20px; flex-wrap:wrap;">
        <div class="stat-card"><div class="stat-value"><?php echo $total_splitters; ?></div><div class="stat-label">Total Splitters</div></div>
        <div class="stat-card"><div class="stat-value"><?php echo $total_ports; ?></div><div class="stat-label">Total Ports</div></div>
        <div class="stat-card"><div class="stat-value"><?php echo $total_used; ?></div><div class="stat-label">Used Ports</div></div>
        <div class="stat-card"><div class="stat-value"><?php echo max(0, $total_ports - $total_used); ?></div><div class="stat-label">Free Ports</div></div>
    </div>

    <table class="data-table">
        <thead>
            <tr><th>Splitter name</th><th>External ID</th><th>Coordinates</th><th>ONUs</th><th>Ports</th><th>Usage</th><th>Zone</th><th>Action</th></tr>
        </thead>
        <tbody>
            <?php foreach ($splitters as $s): ?>
            <tr>
                <td><a href="splitters.php?edit=<?php echo (int)$s['id']; ?>"><strong><?php echo htmlspecialchars($s['name']); ?></strong></a></td>
                <td><?php echo htmlspecialchars($s['external_id'] ?? '—'); ?></td>
                <td><?php echo ($s['latitude'] && $s['longitude']) ? $s['latitude'].','.$s['longitude'] : '—'; ?></td>
                <td><?php echo $s['usage_count']; ?></td>
                <td><?php echo $s['nr_of_ports'] ?? '—'; ?></td>
                <td><?php
                    $ports = $s['nr_of_ports'] ?: 0;
                    $used = $s['usage_count'];
                    echo $ports ? round($used/$ports*100).'%' : ($used.' / — No ports defined');
                ?></td>
                <td><?php echo htmlspecialchars($s['zone'] ?? '—'); ?></td>
                <td>
                    <a href="splitters.php?edit=<?php echo (int)$s['id']; ?>" class="btn btn-sm btn-secondary">Edit</a>
                    <form method="post" style="display:inline" onsubmit="return confirm('Delete <?php echo htmlspecialchars($s['name']); ?>?')">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?php echo (int)$s['id']; ?>">
                        <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($total_pages > 1): ?>
        <?php
        $link_params = ['zone' => $filter_zone, 'search' => $search, 'limit' => $limit];
        $base_query = http_build_query($link_params);
        ?>
        <div class="pagination-bar" style="margin-top:20px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
            <div style="font-size:0.85rem; color:#64748b;">
                Menampilkan <?php echo ($offset + 1); ?> - <?php echo min($offset + $limit, $total_items); ?> dari <?php echo $total_items; ?> splitter
            </div>
            <div class="pagination-buttons" style="display:flex; gap:6px; align-items:center;">
                <?php if ($current_page > 1): ?>
                    <a href="splitters.php?<?php echo $base_query; ?>&page=1" class="btn btn-xs btn-outline" style="text-decoration:none;" title="Halaman Pertama"><<</a>
                    <a href="splitters.php?<?php echo $base_query; ?>&page=<?php echo ($current_page - 1); ?>" class="btn btn-xs btn-outline" style="text-decoration:none;" title="Sebelumnya"><</a>
                <?php else: ?>
                    <span class="btn btn-xs btn-outline disabled" style="opacity:0.5; cursor:not-allowed;"><<</span>
                    <span class="btn btn-xs btn-outline disabled" style="opacity:0.5; cursor:not-allowed;"><</span>
                <?php endif; ?>

                <?php
                $window_size = 5;
                $start = $current_page - 2;
                $end = $current_page + 2;
                if ($start < 1) { $start = 1; $end = min($total_pages, $window_size); }
                if ($end > $total_pages) { $end = $total_pages; $start = max(1, $total_pages - $window_size + 1); }
                for ($i = $start; $i <= $end; $i++) {
                    $active_class = ($i === $current_page) ? 'btn-primary' : 'btn-outline';
                    echo '<a href="splitters.php?' . $base_query . '&page=' . $i . '" class="btn btn-xs ' . $active_class . '" style="text-decoration:none;">' . $i . '</a>';
                }
                ?>

                <?php if ($current_page < $total_pages): ?>
                    <a href="splitters.php?<?php echo $base_query; ?>&page=<?php echo ($current_page + 1); ?>" class="btn btn-xs btn-outline" style="text-decoration:none;" title="Selanjutnya">></a>
                    <a href="splitters.php?<?php echo $base_query; ?>&page=<?php echo $total_pages; ?>" class="btn btn-xs btn-outline" style="text-decoration:none;" title="Halaman Terakhir">>></a>
                <?php else: ?>
                    <span class="btn btn-xs btn-outline disabled" style="opacity:0.5; cursor:not-allowed;">></span>
                    <span class="btn btn-xs btn-outline disabled" style="opacity:0.5; cursor:not-allowed;">>></span>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- ADD MODAL -->
    <div class="modal" id="add-modal">
        <div class="modal-content" style="max-width:600px;">
            <div class="modal-header">
                <h3 style="margin:0;">Add Splitter</h3>
                <button class="close-btn" onclick="document.getElementById('add-modal').classList.remove('open')">&times;</button>
            </div>
            <form method="post">
                <input type="hidden" name="action" value="add">
                <div class="modal-body">
                    <div class="form-group"><label>Splitter name</label><input type="text" name="name" required placeholder="e.g. TGR-01D1209-Cingklok"></div>
                    <div class="form-grid">
                        <div class="form-group"><label>External ID</label><input type="text" name="external_id" placeholder="—"></div>
                        <div class="form-group"><label>Nr of ports</label><input type="number" name="nr_of_ports" min="1" max="128" value="8"></div>
                    </div>
                    <div class="form-group"><label>Zone</label>
                        <select name="zone"><option value="">No zone</option><?php foreach ($zones as $z) echo '<option>'.htmlspecialchars($z).'</option>'; ?></select>
                    </div>
                    <div class="form-group"><label>Comment</label><input type="text" name="comment"></div>
                    <div class="form-grid">
                        <div class="form-group"><label>Latitude</label><input type="text" name="latitude" placeholder="-7.250"></div>
                        <div class="form-group"><label>Longitude</label><input type="text" name="longitude" placeholder="110.752"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="document.getElementById('add-modal').classList.remove('open')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/footer.php'; ?>
