<?php
require_once __DIR__ . '/../backend/db.php';

if (!isset($_SESSION["smartolt_role"]) || $_SESSION["smartolt_role"] !== "superadmin") {
    $_SESSION["error"] = "Akses ditolak!";
    header("Location: ../dashboard.php");
    exit;
}

// POST handler
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tok = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $tok)) {
        $_SESSION['error'] = 'CSRF kedaluwarsa.';
        header('Location: splitters.php'); exit;
    }
    $act = $_POST['action'] ?? '';
    if ($act === 'add') {
        $pdo->prepare("INSERT INTO splitters (name,external_id,nr_of_ports,comment,note,zone,latitude,longitude) VALUES (?,?,?,?,?,?,?,?)")
            ->execute([trim($_POST['name']??''), trim($_POST['external_id']??'')?:null, (int)($_POST['nr_of_ports']??0)?:null, trim($_POST['comment']??'')?:null, trim($_POST['note']??'')?:null, $_POST['zone']?:null, (float)($_POST['latitude']??0)?:null, (float)($_POST['longitude']??0)?:null]);
    } elseif ($act === 'update' && ($id=(int)($_POST['id']??0))) {
        $pdo->prepare("UPDATE splitters SET name=?,external_id=?,nr_of_ports=?,comment=?,note=?,zone=?,latitude=?,longitude=? WHERE id=?")
            ->execute([trim($_POST['name']??''), trim($_POST['external_id']??'')?:null, (int)($_POST['nr_of_ports']??0)?:null, trim($_POST['comment']??'')?:null, trim($_POST['note']??'')?:null, $_POST['zone']?:null, (float)($_POST['latitude']??0)?:null, (float)($_POST['longitude']??0)?:null, $id]);
    } elseif ($act === 'delete' && ($id=(int)($_POST['id']??0))) {
        $pdo->prepare("DELETE FROM splitters WHERE id=?")->execute([$id]);
    }
    header('Location: splitters.php'); exit;
}

// Edit mode
$edit_id = (int)($_GET['edit'] ?? 0);
$edit_splitter = null;
$edit_onus = [];
if ($edit_id) {
    $st = $pdo->prepare("SELECT * FROM splitters WHERE id=?");
    $st->execute([$edit_id]);
    $edit_splitter = $st->fetch(PDO::FETCH_ASSOC);
    if ($edit_splitter) {
        $st2 = $pdo->prepare("SELECT id,name,serial_number,onu_id,pon_port FROM onus WHERE splitter=? LIMIT 50");
        $st2->execute([$edit_splitter['name']]);
        $edit_onus = $st2->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Filters
$fzone = $_GET['zone'] ?? '';
$fsearch = trim($_GET['search'] ?? '');
$fwhere = 'WHERE 1=1';
$fparams = [];
if ($fzone !== '') { $fwhere .= ' AND s.zone=?'; $fparams[] = $fzone; }
if ($fsearch !== '') { $fwhere .= ' AND (s.name LIKE ? OR s.zone LIKE ? OR s.external_id LIKE ?)'; $fparams[] = "%$fsearch%"; $fparams[] = "%$fsearch%"; $fparams[] = "%$fsearch%"; }

$limit_raw = (int)($_GET['limit'] ?? 0);
$limit = in_array($limit_raw, [20, 50, 100, 200], true) ? $limit_raw : 50;

$cnt = $pdo->prepare("SELECT COUNT(*) FROM splitters s $fwhere");
$cnt->execute($fparams);
$total_items = (int)$cnt->fetchColumn();

$total_pages = (int)max(1, ceil($total_items / $limit));
$current_page = (int)max(1, min((int)($_GET['page'] ?? 1), $total_pages));
$offset = ($current_page - 1) * $limit;

$q = $pdo->prepare("SELECT s.*, COALESCE(c.c,0) AS usage_count FROM splitters s LEFT JOIN (SELECT splitter,COUNT(*) AS c FROM onus GROUP BY splitter) c ON c.splitter=s.name $fwhere ORDER BY s.zone,s.name LIMIT $limit OFFSET $offset");
$q->execute($fparams);
$splitters = $q->fetchAll(PDO::FETCH_ASSOC);

$sr = $pdo->prepare("SELECT COUNT(*) AS t, COALESCE(SUM(nr_of_ports),0) AS p FROM splitters s $fwhere");
$sr->execute($fparams);
$sr_row = $sr->fetch(PDO::FETCH_ASSOC);
$total_splitters = (int)$sr_row['t'];
$total_ports = (int)$sr_row['p'];

$ur = $pdo->prepare("SELECT COALESCE(SUM(c.c),0) FROM splitters s LEFT JOIN (SELECT splitter,COUNT(*) AS c FROM onus GROUP BY splitter) c ON c.splitter=s.name $fwhere");
$ur->execute($fparams);
$total_used = (int)$ur->fetchColumn();

$zones = $pdo->query("SELECT DISTINCT zone FROM splitters WHERE zone IS NOT NULL AND zone != '' ORDER BY zone")->fetchAll(PDO::FETCH_COLUMN);

include __DIR__ . '/header.php';
?>

<?php if ($edit_splitter): ?>
<!-- EDIT MODE -->
<div class="content-card" style="margin-bottom:20px">
    <div class="card-header">
        <h2>Edit Splitter &mdash; <?php echo htmlspecialchars($edit_splitter['name']); ?></h2>
        <a href="splitters.php" class="btn btn-secondary">&larr; Kembali</a>
    </div>
    <div class="card-body">
        <div class="stats-grid" style="margin-bottom:20px">
            <div class="stat-card"><div class="stat-icon bg-blue"><i data-lucide="hash"></i></div><div class="stat-info"><h3>ID</h3><p><?php echo (int)$edit_splitter['id']; ?></p></div></div>
            <div class="stat-card"><div class="stat-icon bg-green"><i data-lucide="network"></i></div><div class="stat-info"><h3>ONU Terpasang</h3><p><?php echo count($edit_onus); ?></p></div></div>
            <div class="stat-card"><div class="stat-icon bg-orange"><i data-lucide="map-pin"></i></div><div class="stat-info"><h3>Zone</h3><p><?php echo htmlspecialchars($edit_splitter['zone'] ?? '—'); ?></p></div></div>
        </div>
        <form method="post" style="max-width:700px">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" value="<?php echo (int)$edit_splitter['id']; ?>">
            <div class="form-group"><label>Nama</label><input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($edit_splitter['name']); ?>" required></div>
            <div class="form-grid">
                <div class="form-group"><label>External ID</label><input type="text" name="external_id" class="form-control" value="<?php echo htmlspecialchars($edit_splitter['external_id'] ?? ''); ?>"></div>
                <div class="form-group"><label>Jumlah Port</label><input type="number" name="nr_of_ports" class="form-control" value="<?php echo (int)($edit_splitter['nr_of_ports'] ?? 0); ?>" min="1" max="128"></div>
            </div>
            <div class="form-group"><label>Comment</label><input type="text" name="comment" class="form-control" value="<?php echo htmlspecialchars($edit_splitter['comment'] ?? ''); ?>"></div>
            <div class="form-group"><label>Note</label><textarea name="note" class="form-control" rows="2"><?php echo htmlspecialchars($edit_splitter['note'] ?? ''); ?></textarea></div>
            <div class="form-group"><label>Zone</label><select name="zone" class="form-control"><option value="">— Tanpa Zone —</option><?php foreach ($zones as $z): ?><option value="<?php echo htmlspecialchars($z); ?>" <?php echo ($edit_splitter['zone'] ?? '') === $z ? 'selected' : ''; ?>><?php echo htmlspecialchars($z); ?></option><?php endforeach; ?></select></div>
            <div class="form-grid">
                <div class="form-group"><label>Latitude</label><input type="text" name="latitude" class="form-control" value="<?php echo htmlspecialchars($edit_splitter['latitude'] ?? ''); ?>"></div>
                <div class="form-group"><label>Longitude</label><input type="text" name="longitude" class="form-control" value="<?php echo htmlspecialchars($edit_splitter['longitude'] ?? ''); ?>"></div>
            </div>
            <div style="display:flex;gap:10px;margin-top:20px">
                <button type="submit" class="btn btn-primary">Simpan</button>
                <a href="splitters.php" class="btn btn-secondary">Batal</a>
            </div>
        </form>
    </div>
</div>
<?php if ($edit_onus): ?>
<div class="content-card">
    <div class="card-header"><h2>ONU Terpasang (<?php echo count($edit_onus); ?>)</h2></div>
    <div class="table-responsive"><table class="data-table"><thead><tr><th>Port</th><th>Serial Number</th><th>Nama</th></tr></thead><tbody>
    <?php foreach ($edit_onus as $o): ?><tr><td><a href="onu-detail.php?id=<?php echo (int)$o['id']; ?>" style="color:var(--text-accent);text-decoration:none"><?php echo htmlspecialchars($o['pon_port'].':'.$o['onu_id']); ?></a></td><td class="mono"><?php echo htmlspecialchars($o['serial_number']); ?></td><td><?php echo htmlspecialchars($o['name']); ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
</div>
<?php endif; ?>

<?php else: ?>
<!-- LIST MODE -->
<div class="stats-grid">
    <div class="stat-card"><div class="stat-icon bg-blue"><i data-lucide="git-branch"></i></div><div class="stat-info"><h3>Total Splitters</h3><p><?php echo $total_splitters; ?></p></div></div>
    <div class="stat-card"><div class="stat-icon bg-purple"><i data-lucide="plug"></i></div><div class="stat-info"><h3>Total Ports</h3><p><?php echo $total_ports; ?></p></div></div>
    <div class="stat-card"><div class="stat-icon bg-green"><i data-lucide="check-circle"></i></div><div class="stat-info"><h3>Used Ports</h3><p><?php echo $total_used; ?></p></div></div>
    <div class="stat-card"><div class="stat-icon bg-orange"><i data-lucide="circle"></i></div><div class="stat-info"><h3>Free Ports</h3><p><?php echo max(0, $total_ports - $total_used); ?></p></div></div>
</div>

<div class="content-card">
    <div class="card-header">
        <h2>Daftar Splitter</h2>
        <div style="display:flex;gap:8px;align-items:center">
            <form method="GET" style="display:flex;gap:8px;align-items:center">
                <?php if ($fzone !== ''): ?><input type="hidden" name="zone" value="<?php echo htmlspecialchars($fzone); ?>"><?php endif; ?>
                <input type="text" name="search" class="form-control" value="<?php echo htmlspecialchars($fsearch); ?>" placeholder="Cari..." style="width:200px">
                <button type="submit" class="btn btn-secondary">Cari</button>
                <?php if ($fsearch !== ''): ?><a href="splitters.php<?php echo $fzone !== '' ? '?zone='.urlencode($fzone) : ''; ?>" class="btn btn-secondary">Reset</a><?php endif; ?>
            </form>
            <select class="form-control" style="width:150px" onchange="location='splitters.php'+(this.value?'?zone='+this.value:'')">
                <option value="">Semua Zone</option>
                <?php foreach ($zones as $z): ?><option value="<?php echo htmlspecialchars($z); ?>" <?php echo $fzone === $z ? 'selected' : ''; ?>><?php echo htmlspecialchars($z); ?></option><?php endforeach; ?>
            </select>
            <button class="btn btn-primary" onclick="document.getElementById('add-modal').classList.toggle('open')">+ Tambah</button>
        </div>
    </div>
    <div class="table-responsive"><table class="data-table">
        <thead><tr><th>Nama</th><th>External ID</th><th>Koordinat</th><th>ONU</th><th>Port</th><th>Penggunaan</th><th>Zone</th><th>Aksi</th></tr></thead>
        <tbody>
        <?php if (empty($splitters)): ?>
            <tr><td colspan="8" style="text-align:center;padding:24px;color:var(--text-muted)">Tidak ada splitter ditemukan.</td></tr>
        <?php else: foreach ($splitters as $s):
            $ports = max(1, (int)($s['nr_of_ports'] ?: 0));
            $used = (int)$s['usage_count'];
            $pct = (int)round($used / $ports * 100);
            $pct_cls = $pct >= 80 ? 'bg-red' : ($pct >= 50 ? 'bg-orange' : 'bg-green');
        ?>
            <tr>
                <td><a href="splitters.php?edit=<?php echo (int)$s['id']; ?>" style="color:var(--text-accent);text-decoration:none;font-weight:600"><?php echo htmlspecialchars($s['name']); ?></a></td>
                <td><?php echo htmlspecialchars($s['external_id'] ?? '—'); ?></td>
                <td><?php echo ($s['latitude'] && $s['longitude']) ? $s['latitude'].', '.$s['longitude'] : '—'; ?></td>
                <td><?php echo $used; ?></td>
                <td><?php echo $s['nr_of_ports'] ?? '—'; ?></td>
                <td><span class="badge <?php echo $pct_cls; ?>"><?php echo $pct; ?>%</span></td>
                <td><?php echo htmlspecialchars($s['zone'] ?? '—'); ?></td>
                <td>
                    <a href="splitters.php?edit=<?php echo (int)$s['id']; ?>" class="btn btn-detail">Edit</a>
                    <form method="post" style="display:inline" onsubmit="return confirm('Hapus splitter ini?')">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?php echo (int)$s['id']; ?>">
                        <button type="submit" class="btn btn-detail" style="color:var(--color-danger);border-color:var(--color-danger)">Hapus</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table></div>

    <?php if ($total_pages > 1): $bq = http_build_query(['zone'=>$fzone,'search'=>$fsearch,'limit'=>$limit]); ?>
    <div style="display:flex;justify-content:space-between;align-items:center;padding:12px 16px;border-top:1px solid var(--border-color)">
        <span style="font-size:13px;color:var(--text-muted)"><?php echo ($offset+1); ?>&ndash;<?php echo min($offset+$limit,$total_items); ?> dari <?php echo $total_items; ?></span>
        <div style="display:flex;gap:4px">
            <?php if ($current_page > 1): ?>
                <a href="splitters.php?<?php echo $bq; ?>&page=1" class="btn btn-xs btn-outline" style="text-decoration:none">&laquo;</a>
                <a href="splitters.php?<?php echo $bq; ?>&page=<?php echo $current_page-1; ?>" class="btn btn-xs btn-outline" style="text-decoration:none">&lsaquo;</a>
            <?php endif; ?>
            <?php $ws=5; $st=(int)max(1,$current_page-2); $en=(int)min($total_pages,$st+$ws-1); if($en-$st+1<$ws) $st=(int)max(1,$en-$ws+1); for($i=$st;$i<=$en;$i++): ?>
                <a href="splitters.php?<?php echo $bq; ?>&page=<?php echo $i; ?>" class="btn btn-xs <?php echo $i===$current_page?'btn-primary':'btn-outline'; ?>" style="text-decoration:none"><?php echo $i; ?></a>
            <?php endfor; ?>
            <?php if ($current_page < $total_pages): ?>
                <a href="splitters.php?<?php echo $bq; ?>&page=<?php echo $current_page+1; ?>" class="btn btn-xs btn-outline" style="text-decoration:none">&rsaquo;</a>
                <a href="splitters.php?<?php echo $bq; ?>&page=<?php echo $total_pages; ?>" class="btn btn-xs btn-outline" style="text-decoration:none">&raquo;</a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- ADD MODAL -->
<div class="modal" id="add-modal">
    <div class="modal-content" style="max-width:600px">
        <div class="modal-header"><h2>Tambah Splitter</h2><button class="close-btn" onclick="document.getElementById('add-modal').classList.remove('open')">&times;</button></div>
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
