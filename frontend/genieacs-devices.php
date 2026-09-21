<?php
// ==============================================================================
# GenieACS Device List — ONU ZTE/Huawei terdaftar di TR-069 ACS
// ==============================================================================
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../backend/genieacs.php';

$devices = genieacs_list_devices();
?>
<div class="content-card">
    <div class="card-header">
        <h2><i data-lucide="router" style="width:18px;height:18px;"></i> GenieACS Devices</h2>
        <div style="color:var(--text-muted); font-size:0.85rem;"><?php echo count($devices); ?> device terdaftar</div>
    </div>

<div class="table-responsive">
    <table class="data-table">
        <thead>
            <tr>
                <th>Manufacturer</th>
                <th>Model</th>
                <th>Serial Number</th>
                <th>Software</th>
                <th>RX Power (dBm)</th>
                <th>Last Inform</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($devices)): ?>
                <tr><td colspan="7" style="text-align:center; padding:30px; color:var(--text-muted);">Belum ada device yang terhubung ke ACS.</td></tr>
            <?php endif; ?>
            <?php foreach ($devices as $d):
                $info = $d['InternetGatewayDevice']['DeviceInfo'] ?? [];
                $manu = $info['Manufacturer']['_value'] ?? '-';
                $model = $info['ModelName']['_value'] ?? ($info['ProductClass']['_value'] ?? '-');
                $sw = $info['SoftwareVersion']['_value'] ?? '-';
                $rx = $d['VirtualParameters']['OpticalPower']['_value'] ?? null;
                $lastInform = $d['_lastInform'] ?? null;
            ?>
            <tr>
                <td><?php echo htmlspecialchars($manu); ?></td>
                <td><?php echo htmlspecialchars($model); ?></td>
                <td><code><?php echo htmlspecialchars($d['_id']); ?></code></td>
                <td><?php echo htmlspecialchars($sw); ?></td>
                <td><?php echo $rx !== null ? htmlspecialchars($rx) : '-'; ?></td>
                <td><?php echo $lastInform ? htmlspecialchars(date('d/m/Y H:i', strtotime($lastInform))) : '-'; ?></td>
                <td>
                    <a href="genieacs-device-detail.php?id=<?php echo urlencode($d['_id']); ?>" class="btn btn-primary btn-sm" title="Detail & Edit">
                        <i data-lucide="settings-2" style="width:14px; height:14px;"></i>
                    </a>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
