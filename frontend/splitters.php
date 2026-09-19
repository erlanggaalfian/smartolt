<?php
require_once __DIR__ . '/../backend/db.php';

if (!isset($_SESSION["smartolt_role"]) || $_SESSION["smartolt_role"] !== "superadmin") {
    $_SESSION["error"] = "Akses ditolak!";
    header("Location: ../dashboard.php");
    exit;
}

// POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf_token)) {
        $_SESSION['error'] = 'Sesi keamanan kedaluwarsa.';
        header('Location: splitters.php');
        exit;
    }
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $stmt = $pdo->prepare("INSERT INTO splitters (name, external_id, nr_of_ports, comment, note, zone, latitude, longitude) VALUES (?,?,?,?,?,?,?,?)");
        $stmt->execute([
            trim($_POST['name'] ?? ''), trim($_POST['external_id'] ?? '') ?: null,
            intval($_POST['nr_of_ports'] ?? 0) ?: null, trim($_POST['comment'] ?? '') ?: null,
            trim($_POST['note'] ?? '') ?: null, $_POST['zone'] ?: null,
            floatval($_POST['latitude'] ?? 0) ?: null, floatval($_POST['longitude'] ?? 0) ?: null
        ]);
    } elseif ($action === 'update') {
        $id = intval($_POST['id'] ?? 0);
        if ($id) {
            $stmt = $pdo->prepare("UPDATE splitters SET name=?, external_id=?, nr_of_ports=?, comment=?, note=?, zone=?, latitude=?, longitude=? WHERE id=?");
            $stmt->execute([
                trim($_POST['name'] ?? ''), trim($_POST['external_id'] ?? '') ?: null,
                intval($_POST['nr_of_ports'] ?? 0) ?: null, trim($_POST['comment'] ?? '') ?: null,
                trim($_POST['note'] ?? '') ?: null, $_POST['zone'] ?: null,
                floatval($_POST['latitude'] ?? 0) ?: null, floatval($_POST['longitude'] ?? 0) ?: null, $id
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

// Filters
$filter_zone = $_GET['zone'] ?? '';
$search = trim($_GET['search'] ?? '');
$where = 'WHERE 1=1';
$params = [];
if ($filter_zone) { $where .= ' AND s.zone = ?'; $params[] = $filter_zone; }
if ($search !== '') { $where .= ' AND (s.name LIKE ? OR s.zone LIKE ? OR s.external_id LIKE ?)'; $params[] = "%{$search}%"; $params[] = "%{$search}%"; $params[] = "%{$search}%"; }

$limit = in_array((int)($_GET['limit'] ?? 0), [20,50,100,200]) ? (int)$_GET['limit'] : 50;

$count_stmt = $pdo->prepare("SELECT COUNT(*) FROM splitters s $where");
$count_stmt->execute($params);
$total_items = (int)$count_stmt->fetchColumn();

$current_page = max(1, (int)($_GET['page'] ?? 1));
$total_pages = max(1, (int)ceil($total_items / $limit));
if ($current_page > $total_pages) $current_page = $total_pages;
$offset = ($current_page - 1) * $limit;

$q = $pdo->prepare("SELECT s.*, COALESCE(cnt.c, 0) as usage_count FROM splitters s LEFT JOIN (SELECT splitter, COUNT(*) AS c FROM onus GROUP BY splitter) cnt ON cnt.splitter = s.name $where ORDER BY s.zone, s.name LIMIT $limit OFFSET $offset");
$q->execute($params);
$splitters = $q->fetchAll(PDO::FETCH_ASSOC);

$sr = $pdo->prepare("SELECT COUNT(*) as t, COALESCE(SUM(nr_of_ports),0) as p FROM splitters s $where");
$sr->execute($params);
$row = $sr->fetch(PDO::FETCH_ASSOC);
$total_splitters = (int)$row['t'];
$total_ports = (int)$row['p'];

$ur = $pdo->prepare("SELECT COALESCE(SUM(cnt.c),0) FROM splitters s LEFT JOIN (SELECT splitter, COUNT(*) AS c FROM onus GROUP BY splitter) cnt ON cnt.splitter = s.name $where");
$ur->execute($params);
$total_used = (int)$ur->fetchColumn();

$zones = $pdo->query("SELECT DISTINCT zone FROM splitters WHERE zone IS NOT NULL AND zone != '' ORDER BY zone")->fetchAll(PDO::FETCH_COLUMN);

include __DIR__ . '/header.php';
?>

<?php if ($edit_splitter): ?>

<div class="content-card" style="margin-bottom:20px;">
    <div class="card-header">
        <h2>Edit Splitter &mdash; <?php echo htmlspecialchars($edit_splitter['name']); ?></h2>
        <a href="splitters.php" class="btn btn-secondary">&larr; Kembali</a>
    </div>
    <div class="card-body">
        <div class="stats-grid" style="margin-bottom:20px;">
            <div class="stat-card"><div class="stat-icon bg-blue"><i data-lucide="hash"></i></div><div class="stat-info"><h3>ID</h3><p><?php echo (int)$edit_splitter['id']; ?></p></div></div>
            <div class="stat-card"><div class="stat-icon bg-green"><i data-lucide="network"></i></div><div class="stat-info"><h3>ONU Terpasang</h3><p><?php echo count($edit_onus); ?></p></div></div>
            <div class="stat-card"><div class="stat-icon bg-orange"><i data-lucide="map-pin"></i></div><div class="stat-info"><h3>Zone</h3><p><?php echo htmlspecialchars($edit_splitter['zone'] ?? '—'); ?></p></div></div>
        </div>
        <form method="post" style="max-width:700px;">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" value="<?php echo (int)$edit_splitter['id']; ?>">
            <div class="form-group"><label>Nama Splitter</label><input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($edit_splitter['name']); ?>" required></div>
            <div class="form-grid">
                <div class="form-group"><label>External ID</label><input type="text" name="external_id" class="form-control" value="<?php echo htmlspecialchars($edit_splitter['external_id'] ?? ''); ?>"></div>
                <div class="form-group"><label>Jumlah Port</label><input type="number" name="nr_of_ports" class="form-control" value="<?php echo htmlspecialchars($edit_splitter['nr_of_ports'] ?? ''); ?>" min="1" max="128"></div>
            </div>
            <div class="form-group"><label>Comment</label><input type="text" name="comment" class="form-control" value="<?php echo htmlspecialchars($edit_splitter['comment'] ?? ''); ?>"></div>
            <div class="form-group"><label>Note</label><textarea name="note" class="form-control" rows="2"><?php echo htmlspecialchars($edit_splitter['note'] ?? ''); ?></textarea></div>
            <div class="form-group"><label>Zone</label><select name="zone" class="form-control"><option value="">— Tanpa Zone —</option><?php foreach ($zones as $z): ?><option value="<?php echo htmlspecialchars($z); ?>" <?php echo ($edit_splitter['zone'] ?? '') === $z ? 'selected' : ''; ?>><?php echo htmlspecialchars($z); ?></option><?php endforeach; ?></select></div>
            <div class="form-grid">
                <div class="form-group"><label>Latitude</label><input type="text" name="latitude" class="form-control" value="<?php echo htmlspecialchars($edit_splitter['latitude'] ?? ''); ?>"></div>
                <div class="form-group"><label>Longitude</label><input type="text" name="longitude" class="form-control" value="<?php echo htmlspecialchars($edit_splitter['longitude'] ?? ''); ?>"></div>
            </div>
            <div style="display:flex;gap:10px;margin-top:20px;">
                <button type="submit" class="btn btn-primary">Simpan</button>
                <a href="splitters.php" class="btn btn-secondary">Batal</a>
            </div>
        </form>
    </div>
</div>

<?php if ($edit_onus): ?>
<div class="content-card">
    <div class="card-header"><h2>ONU Terpasang (<?php echo count($edit_onus); ?>)</h2></div>
    <div class="table-responsive">
        <table class="data-table">
            <thead><tr><th>Port</th><th>Serial Number</th><th>Nama</th></tr></thead>
            <tbody>
            <?php foreach ($edit_onus as $o): ?>
                <tr>
                    <td><a href="onu-detail.php?id=<?php echo (int)$o['id']; ?>" style="color:var(--text-accent);text-decoration:none;"><?php echo htmlspecialchars($o['pon_port'].':'.$o['onu_id']); ?></a></td>
                    <td class="mono"><?php echo htmlspecialchars($o['serial_number']); ?></td>
                    <td><?php echo htmlspecialchars($o['name']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php else: ?>

<div class="stats-grid">
    <div class="stat-card"><div class="stat-icon bg-blue"><i data-lucide="git-branch"></i></div><div class="stat-info"><h3>Total Splitters</h3><p><?php echo $total_splitters; ?></p></div></div>
    <div class="stat-card"><div class="stat-icon bg-purple"><i data-lucide="plug"></i></div><div class="stat-info"><h3>Total Ports</h3><p><?php echo $total_ports; ?></p></div></div>
    <div class="stat-card"><div class="stat-icon bg-green"><i data-lucide="check-circle"></i></div><div class="stat-info"><h3>Used Ports</h3><p><?php echo $total_used; ?></p></div></div>
    <div class="stat-card"><div class="stat-icon bg-orange"><i data-lucide="circle"></i></div><div class="stat-info"><h3>Free Ports</h3><p><?php echo max(0, $total_ports - $total_used); ?></p></div></div>
</div>

<div class="content-card">
    <div class="card-header">
        <h2>Daftar Splitter</h2>
        <div style="display:flex;gap:8px;align-items:center;">
            <form method="GET" style="display:flex;gap:8px;align-items:center;">
                <?php if ($filter_zone): ?><input type="hidden" name="zone" value="<?php echo htmlspecialchars($filter_zone); ?>"><?php endif; ?>
                <input type="text" name="search" class="form-control" value="<?php echo htmlspecialchars($search); ?>" placeholder="Cari nama, zone, ID..." style="width:200px;">
                <button type="submit" class="btn btn-secondary">Cari</button>
                <?php if ($search): ?><a href="splitters.php<?php echo $filter_zone ? '?zone='.urlencode($filter_zone) : ''; ?>" class="btn btn-secondary">Reset</a><?php endif; ?>
            </form>
            <select class="form-control" style="width:150px;" onchange="if(this.value)location='splitters.php?zone='+this.value;else location='splitters.php';">
                <option value="">Semua Zone</option>
                <?php foreach ($zones as $z): ?><option value="<?php echo htmlspecialchars($z); ?>" <?php echo $filter_zone === $z ? 'selected' : ''; ?>><?php echo htmlspecialchars($z); ?></option><?php endforeach; ?>
            </select>
            <button class="btn btn-primary" onclick="document.getElementById('add-modal').classList.toggle('open')">+ Tambah</button>
        </div>
    </div>
    <div class="table-responsive">
        <table class="data-table">
            <thead><tr><th>Nama</th><th>External ID</th><th>Koordinat</th><th>ONU</th><th>Port</th><th>Penggunaan</th><th>Zone</th><th>Aksi</th></tr></thead>
            <tbody>
            <?php if (empty($splitters)): ?>
                <tr><td colspan="8" style="text-align:center;padding:24px;color:var(--text-muted);">Tidak ada splitter ditemukan.</td></tr>
            <?php else: foreach ($splitters as $s): ?>
                <tr>
                    <td><a href="splitters.php?edit=<?php echo (int)$s['id']; ?>" style="color:var(--text-accent);text-decoration:none;font-weight:600;"><?php echo htmlspecialchars($s['name']); ?></a></td>
                    <td><?php echo htmlspecialchars($s['external_id'] ?? '—'); ?></td>
                    <td><?php echo ($s['latitude'] && $s['longitude']) ? $s['latitude'].', '.$s['longitude'] : '—'; ?></td>
                    <td><?php echo (int)$s['usage_count']; ?></td>
                    <td><?php echo $s['nr_of_ports'] ?? '—'; ?></td>
                    <td><?php
                        $ports = (int)($s['nr_of_ports'] ?: 0);
                        $used = (int)$s['usage_count'];
                        if ($ports > 0) {
                            $pct = (int)round($used / $ports * 100);
                            $cls = $pct >= 80 ? 'bg-red' : ($pct >= 50 ? 'bg-orange' : 'bg-green');
                            echo '<span class="badge '.$cls.'">'.$pct.'%</span>';
                        } else {
                            echo '<span style="color:var(--text-muted);">'.$used.' / —</span>';
                        }
                    ?></td>
                    <td><?php echo htmlspecialchars($s['zone'] ?? '—'); ?></td>
                    <td>
                        <a href="splitters.php?edit=<?php echo (int)$s['id']; ?>" class="btn btn-detail">Edit</a>
                        <form method="post" style="display:inline;" onsubmit="return confirm('Hapus splitter ini?')">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?php echo (int)$s['id']; ?>">
                            <button type="submit" class="btn btn-detail" style="color:var(--color-danger);border-color:var(--color-danger);">Hapus</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($total_pages > 1): ?>
    <?php $bq = http_build_query(['zone'=>$filter_zone,'search'=>$search,'limit'=>$limit]); ?>
    <div style="display:flex;justify-content:space-between;align-items:center;padding:12px 16px;border-top:1px solid var(--border-color);">
        <span style="font-size:13px;color:var(--text-muted);"><?php echo ($offset+1); ?>&ndash;<?php echo min($offset+$limit,$total_items); ?> dari <?php echo $total_items; ?></span>
        <div style="display:flex;gap:4px;">
            <?php if ($current_page > 1): ?>
                <a href="splitters.php?<?php echo $bq; ?>&page=1" class="btn btn-xs btn-outline" style="text-decoration:none;">&laquo;</a>
                <a href="splitters.php?<?php echo $bq; ?>&page=<?php echo $current_page-1; ?>" class="btn btn-xs btn-outline" style="text-decoration:none;">&lsaquo;</a>
            <?php endif; ?>
            <?php
            $ws = 5; $st = max(1,$current_page-2); $en = min($total_pages,$st+$ws-1);
            if ($en-$st+1 < $ws) $st = max(1,$en-$ws+1);
            for ($i=$st;$i<=$en;$i++): ?>
                <a href="splitters.php?<?php echo $bq; ?>&page=<?php echo $i; ?>" class="btn btn-xs <?php echo $i===$current_page?'btn-primary':'btn-outline'; ?>" style="text-decoration:none;"><?php echo $i; ?></a>
            <?php endfor; ?>
            <?php if ($current_page < $total_pages): ?>
                <a href="splitters.php?<?php echo $bq; ?>&page=<?php echo $current_page+1; ?>" class="btn btn-xs btn-outline" style="text-decoration:none;">&rsaquo;</a>
                <a href="splitters.php?<?php echo $bq; ?>&page=<?php echo $total_pages; ?>" class="btn btn-xs btn-outline" style="text-decoration:none;">&raquo;</a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<div class="modal" id="add-modal">
    <div class="modal-content" style="max-width:600px;">
        <div class="modal-header">
            <h2>Tambah Splitter</h2>
            <button class="close-btn" onclick="document.getElementById('add-modal').classList.remove('open')">&times;</button>
        </div>
        <form method="post">
            <input type="hidden" name="action" value="add">
            <div class="modal-body">
                <div class="form-group"><label>Nama Splitter</label><input type="text" name="name" class="form-control" required placeholder="e.g. TGR-01D1209-Cingklok"></div>
                <div class="form-grid">
                    <div class="form-group"><label>External ID</label><input type="text" name="external_id" class="form-control"></div>
                    <div class="form-group"><label>Jumlah Port</label><input type="number" name="nr_of_ports" class="form-control" min="1" max="128" value="8"></div>
                </div>
                <div class="form-group"><label>Zone</label><select name="zone" class="form-control"><option value="">— Tanpa Zone —</option><?php foreach ($zones as $z) echo '<option value="'.htmlspecialchars($z).'">'.htmlspecialchars($z).'</option>'; ?></select></div>
                <div class="form-group"><label>Comment</label><input type="text" name="comment" class="form-control"></div>
                <div class="form-grid">
                    <div class="form-group"><label>Latitude</label><input type="text" name="latitude" class="form-control" placeholder="-7.250"></div>
                    <div class="form-group"><label>Longitude</label><input type="text" name="longitude" class="form-control" placeholder="110.752"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('add-modal').classList.remove('open')">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan</button>
            </div>
        </form>
    </div>
</div>

<?php endif; ?>

<?php include __DIR__ . '/footer.php'; ?>
