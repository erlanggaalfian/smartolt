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
        header('Location: onu-types.php');
        exit;
    }
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $stmt = $pdo->prepare("INSERT INTO onu_types (name, pon_type, pon_capabilities, ethernet_ports, wifi_ssids, voip_ports, catv, allow_custom, capability) VALUES (?,?,?,?,?,?,?,?,?)");
        $stmt->execute([
            trim($_POST['name'] ?? ''),
            $_POST['pon_type'] ?? 'GPON',
            $_POST['pon_capabilities'] ?? 'GPON',
            intval($_POST['ethernet_ports'] ?? 4),
            intval($_POST['wifi_ssids'] ?? 0),
            intval($_POST['voip_ports'] ?? 0),
            isset($_POST['catv']) ? 1 : 0,
            isset($_POST['allow_custom']) ? 1 : 0,
            $_POST['capability'] ?? 'Bridging/Routing'
        ]);
    } elseif ($action === 'update') {
        $id = intval($_POST['id'] ?? 0);
        if ($id) {
            $stmt = $pdo->prepare("UPDATE onu_types SET name=?, pon_type=?, pon_capabilities=?, ethernet_ports=?, wifi_ssids=?, voip_ports=?, catv=?, allow_custom=?, capability=? WHERE id=?");
            $stmt->execute([
                trim($_POST['name'] ?? ''),
                $_POST['pon_type'] ?? 'GPON',
                $_POST['pon_capabilities'] ?? 'GPON',
                intval($_POST['ethernet_ports'] ?? 4),
                intval($_POST['wifi_ssids'] ?? 0),
                intval($_POST['voip_ports'] ?? 0),
                isset($_POST['catv']) ? 1 : 0,
                isset($_POST['allow_custom']) ? 1 : 0,
                $_POST['capability'] ?? 'Bridging/Routing',
                $id
            ]);
        }
    } elseif ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        if ($id) $pdo->prepare("DELETE FROM onu_types WHERE id=?")->execute([$id]);
    }
    header('Location: onu-types.php');
    exit;
}

// Handle edit view
$edit_id = intval($_GET['edit'] ?? 0);
$edit_type = null;
if ($edit_id) {
    $stmt = $pdo->prepare("SELECT * FROM onu_types WHERE id=?");
    $stmt->execute([$edit_id]);
    $edit_type = $stmt->fetch(PDO::FETCH_ASSOC);
}

$types = $pdo->query("SELECT t.*, COALESCE(cnt.c, 0) AS usage_count FROM onu_types t LEFT JOIN (SELECT onu_type, COUNT(*) AS c FROM onus GROUP BY onu_type) cnt ON cnt.onu_type = t.name ORDER BY t.name")->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/header.php';
?>

<div class="main-content">
<?php if ($edit_type): ?>
    <!-- EDIT MODE -->
    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:20px;">
        <div>
            <h2 style="margin:0;">Edit ONU type</h2>
            <p style="color:#64748b; margin:4px 0 0;">Update ONU type properties and port counts.</p>
        </div>
        <a href="onu-types.php" class="btn btn-secondary">Back to list</a>
    </div>

    <form method="post" class="card" style="max-width:700px;">
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="id" value="<?php echo (int)$edit_type['id']; ?>">

        <div class="form-grid">
            <div class="form-group">
                <label>PON type</label>
                <select name="pon_type">
                    <option value="GPON" <?php echo $edit_type['pon_type']==='GPON'?'selected':''; ?>>GPON</option>
                    <option value="EPON" <?php echo $edit_type['pon_type']==='EPON'?'selected':''; ?>>EPON</option>
                </select>
            </div>
            <div class="form-group">
                <label>GPON capabilities</label>
                <select name="pon_capabilities">
                    <?php foreach(['GPON','XG-PON','XGS-PON'] as $cap): ?>
                    <option value="<?php echo htmlspecialchars($cap); ?>" <?php echo $edit_type['pon_capabilities']===$cap?'selected':''; ?>><?php echo htmlspecialchars($cap); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="form-group">
            <label>ONU type</label>
            <input type="text" name="name" value="<?php echo htmlspecialchars($edit_type['name']); ?>" required>
        </div>

        <div class="form-grid">
            <div class="form-group">
                <label>Ethernet ports</label>
                <select name="ethernet_ports">
                    <?php foreach([0,1,2,4,5,8,16,24] as $p): ?>
                    <option value="<?php echo $p; ?>" <?php echo $edit_type['ethernet_ports']==$p?'selected':''; ?>><?php echo $p; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>WiFi SSIDs</label>
                <select name="wifi_ssids">
                    <?php foreach([0,1,2,4,8,10,14,16] as $w): ?>
                    <option value="<?php echo $w; ?>" <?php echo $edit_type['wifi_ssids']==$w?'selected':''; ?>><?php echo $w; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>VoIP ports</label>
                <select name="voip_ports">
                    <?php foreach([0,1,2,4,8] as $v): ?>
                    <option value="<?php echo $v; ?>" <?php echo $edit_type['voip_ports']==$v?'selected':''; ?>><?php echo $v; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>CATV</label>
                <label class="toggle-label">
                    <input type="checkbox" name="catv" value="1" <?php echo $edit_type['catv']?'checked':''; ?>>
                    <span>Enabled</span>
                </label>
            </div>
        </div>

        <div class="form-grid">
            <div class="form-group">
                <label>Allow custom profiles</label>
                <label class="toggle-label">
                    <input type="checkbox" name="allow_custom" value="1" <?php echo $edit_type['allow_custom']?'checked':''; ?>>
                    <span>Yes</span>
                </label>
            </div>
            <div class="form-group">
                <label>Capability</label>
                <select name="capability">
                    <option value="Bridging" <?php echo $edit_type['capability']==='Bridging'?'selected':''; ?>>Bridging</option>
                    <option value="Bridging/Routing" <?php echo $edit_type['capability']==='Bridging/Routing'?'selected':''; ?>>Bridging/Routing</option>
                </select>
            </div>
        </div>

        <div style="display:flex; gap:10px; margin-top:20px;">
            <button type="submit" class="btn btn-primary">Save</button>
            <a href="onu-types.php" class="btn btn-secondary">Cancel</a>
        </div>
    </form>

<?php else: ?>
    <!-- LIST MODE -->
    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:20px;">
        <h2 style="margin:0;">ONU Types</h2>
        <button class="btn btn-primary" onclick="document.getElementById('add-modal').classList.toggle('open')">+ Add ONU type</button>
    </div>

    <table class="data-table">
        <thead>
            <tr>
                <th>PON type</th>
                <th>ONU type</th>
                <th>ONUs</th>
                <th>Ethernet</th>
                <th>WiFi</th>
                <th>VoIP</th>
                <th>CATV</th>
                <th>Capability</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($types as $t): ?>
            <tr>
                <td><?php echo htmlspecialchars($t['pon_type']); ?></td>
                <td><strong><?php echo htmlspecialchars($t['name']); ?></strong></td>
                <td><?php echo (int)$t['usage_count']; ?></td>
                <td><?php echo (int)$t['ethernet_ports']; ?></td>
                <td><?php echo (int)$t['wifi_ssids']; ?></td>
                <td><?php echo (int)$t['voip_ports']; ?></td>
                <td><?php echo $t['catv'] ? 'Yes' : '—'; ?></td>
                <td><?php echo htmlspecialchars($t['capability']); ?></td>
                <td>
                    <a href="onu-types.php?edit=<?php echo (int)$t['id']; ?>" class="btn btn-sm btn-secondary">Edit</a>
                    <form method="post" style="display:inline" onsubmit="return confirm('Delete <?php echo htmlspecialchars($t['name']); ?>?')">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?php echo (int)$t['id']; ?>">
                        <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <!-- ADD MODAL -->
    <div class="modal" id="add-modal">
        <div class="modal-content" style="max-width:600px;">
            <div class="modal-header">
                <h3 style="margin:0;">Add ONU type</h3>
                <button class="close-btn" onclick="document.getElementById('add-modal').classList.remove('open')">&times;</button>
            </div>
            <form method="post">
                <input type="hidden" name="action" value="add">
                <div class="modal-body">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>PON type</label>
                            <select name="pon_type"><option>GPON</option><option>EPON</option></select>
                        </div>
                        <div class="form-group">
                            <label>GPON capabilities</label>
                            <select name="pon_capabilities"><option>GPON</option><option>XG-PON</option><option>XGS-PON</option></select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>ONU type name</label>
                        <input type="text" name="name" required placeholder="e.g. ZTE-F609">
                    </div>
                    <div class="form-grid">
                        <div class="form-group"><label>Ethernet ports</label><select name="ethernet_ports"><?php foreach([0,1,2,4,5,8,16,24] as $p) echo "<option>$p</option>"; ?></select></div>
                        <div class="form-group"><label>WiFi SSIDs</label><select name="wifi_ssids"><?php foreach([0,1,2,4,8,10,14,16] as $w) echo "<option>$w</option>"; ?></select></div>
                        <div class="form-group"><label>VoIP ports</label><select name="voip_ports"><?php foreach([0,1,2,4,8] as $v) echo "<option>$v</option>"; ?></select></div>
                        <div class="form-group"><label>CATV</label><label class="toggle-label"><input type="checkbox" name="catv" value="1"><span>Enabled</span></label></div>
                    </div>
                    <div class="form-grid">
                        <div class="form-group"><label>Allow custom profiles</label><label class="toggle-label"><input type="checkbox" name="allow_custom" value="1" checked><span>Yes</span></label></div>
                        <div class="form-group"><label>Capability</label><select name="capability"><option>Bridging</option><option selected>Bridging/Routing</option></select></div>
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
</div>

<?php include __DIR__ . '/footer.php'; ?>
