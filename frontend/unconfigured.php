<?php
// ==============================================================================
# SmartOLT Unconfigured ONUs Page (Scanning & Authorization)
# Scan Ulang memindai SEMUA OLT terdaftar sekaligus (paralel), hasil
# dikelompokkan per OLT dalam section masing-masing.
# Otorisasi ONU dilakukan di halaman terpisah: auth-onu.php
// ==============================================================================
require_once __DIR__ . '/header.php';

$olts = get_allowed_olts();

// Limit per-OLT (default 100)
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
if (!in_array($limit, [20, 50, 100, 200])) $limit = 100;
?>

<div class="section-actions">
    <form method="GET" action="unconfigured.php" id="form-select-olt" class="form-group row-inline" style="margin:0; display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
        <div style="display:flex; align-items:center; gap:8px;">
            <label for="autofind-limit-select" style="margin:0;font-weight:600;white-space:nowrap;">Tampilkan per OLT:</label>
            <select id="autofind-limit-select" name="limit" class="form-control inline-select" style="width:90px;" onchange="this.form.submit()">
                <option value="20" <?php echo $limit === 20 ? 'selected' : ''; ?>>20</option>
                <option value="50" <?php echo $limit === 50 ? 'selected' : ''; ?>>50</option>
                <option value="100" <?php echo $limit === 100 ? 'selected' : ''; ?>>100</option>
                <option value="200" <?php echo $limit === 200 ? 'selected' : ''; ?>>200</option>
            </select>
        </div>
        <button type="button" id="btn-rescan-autofind" class="btn btn-secondary">
            <i data-lucide="refresh-cw"></i> Scan Ulang
        </button>
    </form>
</div>

<?php if (empty($olts)): ?>
    <div class="content-card">
        <div class="card-body">
            <p style="text-align:center;color:var(--text-muted);padding:20px;">Belum ada OLT terdaftar. Harap tambahkan OLT terlebih dahulu di menu Setting / OLT.</p>
        </div>
    </div>
<?php else: ?>
    <div id="autofind-olt-sections">
        <?php foreach ($olts as $olt): ?>
            <div class="content-card autofind-olt-section" data-olt-id="<?php echo (int)$olt['id']; ?>" style="margin-bottom:16px;">
                <div class="card-header border-accent">
                    <h2><?php echo htmlspecialchars($olt['name']); ?></h2>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Shelf PON</th>
                                    <th>Slot PON</th>
                                    <th>Port PON</th>
                                    <th>Nomor Seri / MAC</th>
                                    <th>Tipe Deteksi</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody class="autofind-tbody">
                                <tr>
                                    <td colspan="6" style="text-align:center;padding:30px;">
                                        <div class="loading-spinner" style="padding:0;">
                                            <div class="spinner"></div>
                                            <p style="margin-top:8px;font-weight:600;">Memindai <?php echo htmlspecialchars($olt['name']); ?>...</p>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="autofind-pagination-container"></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        const limit = <?php echo $limit; ?>;
        const pageBySection = new Map(); // oltId -> current page

        function esc(str) {
            if (!str) return '';
            return str.toString()
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function renderPagination(container, totalItems, totalPages, page, oltId, onPageChange) {
            const offset = (page - 1) * limit;
            const startNum = offset + 1;
            const endNum = Math.min(offset + limit, totalItems);

            let html = `
                <div class="pagination-bar" style="margin-top:20px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
                    <div style="font-size:0.85rem; color:var(--text-muted);">
                        Menampilkan ${startNum} - ${endNum} dari ${totalItems} ONU baru
                    </div>
                    <div class="pagination-buttons" style="display:flex; gap:6px; align-items:center;">
            `;

            const mkBtn = (label, targetPage, disabled) => {
                if (disabled) return `<span class="btn btn-xs btn-outline disabled" style="opacity:0.5; cursor:not-allowed;">${label}</span>`;
                return `<button type="button" class="btn btn-xs btn-outline autofind-page-btn" data-page="${targetPage}" style="text-decoration:none;">${label}</button>`;
            };

            html += mkBtn('<<', 1, page <= 1);
            html += mkBtn('<', page - 1, page <= 1);

            const windowSize = 5;
            let startPage = page - 2;
            let endPage = page + 2;
            if (startPage < 1) { startPage = 1; endPage = Math.min(totalPages, windowSize); }
            if (endPage > totalPages) { endPage = totalPages; startPage = Math.max(1, totalPages - windowSize + 1); }

            for (let i = startPage; i <= endPage; i++) {
                if (i === page) {
                    html += `<span class="btn btn-xs btn-primary" style="text-decoration:none;">${i}</span>`;
                } else {
                    html += `<button type="button" class="btn btn-xs btn-outline autofind-page-btn" data-page="${i}" style="text-decoration:none;">${i}</button>`;
                }
            }

            html += mkBtn('>', page + 1, page >= totalPages);
            html += mkBtn('>>', totalPages, page >= totalPages);

            html += `</div></div>`;
            container.innerHTML = html;
            container.querySelectorAll('.autofind-page-btn').forEach(btn => {
                btn.addEventListener('click', () => onPageChange(parseInt(btn.dataset.page, 10)));
            });
        }

        function renderSectionRows(section, onus, page) {
            const oltId = section.dataset.oltId;
            const tbody = section.querySelector('.autofind-tbody');
            const paginationContainer = section.querySelector('.autofind-pagination-container');
            const totalItems = onus.length;

            if (totalItems === 0) {
                tbody.innerHTML = `<tr><td colspan="6" style="text-align:center;color:var(--text-muted);padding:16px;">Tidak ada ONU baru yang terdeteksi.</td></tr>`;
                paginationContainer.innerHTML = '';
                return;
            }

            const totalPages = Math.ceil(totalItems / limit) || 1;
            if (page > totalPages) page = totalPages;
            pageBySection.set(oltId, page);

            const paginatedList = onus.slice((page - 1) * limit, (page - 1) * limit + limit);
            let rowsHtml = '';
            paginatedList.forEach(onu => {
                const parts = onu.pon_port.split('/') || [];
                const authUrl = `auth-onu.php?olt_id=${encodeURIComponent(oltId)}&pon=${encodeURIComponent(onu.pon_port)}&sn=${encodeURIComponent(onu.serial_number)}&type=${encodeURIComponent(onu.type)}`;
                rowsHtml += `<tr><td><strong>${esc(parts[0])}</strong></td><td><strong>${esc(parts[1])}</strong></td><td><strong>${esc(parts[2])}</strong></td><td><code>${esc(onu.serial_number)}</code></td><td><span class="badge bg-orange">${esc(onu.type)}</span></td><td><a href="${authUrl}" class="btn btn-xs btn-success"><i data-lucide="key-round"></i> Otorisasi</a></td></tr>`;
            });
            tbody.innerHTML = rowsHtml;
            if (totalPages > 1) {
                renderPagination(paginationContainer, totalItems, totalPages, page, oltId, (newPage) => {
                    renderSectionRows(section, onus, newPage);
                });
            } else {
                paginationContainer.innerHTML = '';
            }
            if (typeof lucide !== 'undefined') lucide.createIcons();
        }

        function fetchSection(section) {
            const oltId = section.dataset.oltId;
            const oltName = section.querySelector('.card-header h2').textContent.trim();
            const tbody = section.querySelector('.autofind-tbody');
            tbody.innerHTML = `<tr><td colspan="6" style="text-align:center;padding:30px;"><div class="loading-spinner" style="padding:0;"><div class="spinner"></div><p style="margin-top:8px;font-weight:600;">Memindai ${esc(oltName)}...</p></div></td></tr>`;
            section.querySelector('.autofind-pagination-container').innerHTML = '';

            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), 60000); // 60s timeout

            return fetch(`action/get-autofind.php?olt_id=${oltId}`, { signal: controller.signal })
                .then(r => { clearTimeout(timeoutId); return r.json(); })
                .then(data => {
                    if (!data.success) {
                        tbody.innerHTML = `<tr><td colspan="6" style="text-align:center;color:var(--color-danger);padding:16px;font-weight:600;">Gagal memindai OLT: ${esc(data.message)}</td></tr>`;
                        return;
                    }
                    renderSectionRows(section, data.onus || [], pageBySection.get(oltId) || 1);
                })
                .catch(err => {
                    clearTimeout(timeoutId);
                    const msg = err.name === 'AbortError' ? 'Waktu pemindai habis (>60 detik). OLT mungkin tidak merespons.' : 'Gagal memindai OLT: ' + esc(err.message);
                    tbody.innerHTML = `<tr><td colspan="6" style="text-align:center;color:var(--color-danger);padding:16px;font-weight:600;">${msg}</td></tr>`;
                });
        }

        function fetchAllOlts() {
            const sections = document.querySelectorAll('.autofind-olt-section');
            const rescanBtn = document.getElementById('btn-rescan-autofind');
            if (rescanBtn) rescanBtn.disabled = true;
            let pending = sections.length;
            const done = () => { if (--pending <= 0 && rescanBtn) rescanBtn.disabled = false; };
            sections.forEach(section => { fetchSection(section).finally(done); });
        }

        if (document.querySelector('.autofind-olt-section')) fetchAllOlts();
        const rescanBtn = document.getElementById('btn-rescan-autofind');
        if (rescanBtn) rescanBtn.addEventListener('click', (e) => { e.preventDefault(); fetchAllOlts(); });
    });
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
