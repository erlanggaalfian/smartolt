<?php
// ==============================================================================
# SmartOLT Speed Profile Page — daftar profile Upload/Download hasil sync dari OLT
// ==============================================================================
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../backend/driver.php';

$olts = $pdo->query("SELECT id, name FROM olts ORDER BY name")->fetchAll();
?>

<div class="section-actions" style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
    <select id="sp-olt-select" class="form-control" style="width:220px;">
        <option value="">- Pilih OLT -</option>
        <?php foreach ($olts as $o): ?>
            <option value="<?php echo (int)$o['id']; ?>"><?php echo htmlspecialchars($o['name']); ?></option>
        <?php endforeach; ?>
    </select>
</div>

<div class="content-card">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap;">
        <div class="sp-subnav">
            <button class="sp-subnav-tab active" data-direction="download">Download Profile</button>
            <button class="sp-subnav-tab" data-direction="upload">Upload Profile</button>
        </div>
        <button class="btn btn-primary btn-sm" id="btn-add-speed-profile">
            <i data-lucide="plus"></i> Add speed profile
        </button>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="data-table" id="sp-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Speed</th>
                        <th>Type</th>
                        <th>ONUs</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody id="sp-table-body">
                    <tr><td colspan="5" style="text-align:center;color:var(--text-muted);">Pilih OLT, atau tambahkan speed profile baru.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal-overlay" id="sp-modal-overlay" style="display:none;">
    <div class="modal-box" style="max-width:420px;">
        <div class="modal-header">
            <h3 id="sp-modal-title">Add Speed Profile</h3>
            <button type="button" class="modal-close" id="sp-modal-close">&times;</button>
        </div>
        <form id="sp-form">
            <div class="modal-body" style="display:flex; flex-direction:column; gap:12px;">
                <div>
                    <label>Direction</label>
                    <select id="sp-f-direction" class="form-control" required>
                        <option value="download">Download</option>
                        <option value="upload">Upload</option>
                    </select>
                </div>
                <div>
                    <label>Name</label>
                    <input type="text" id="sp-f-name" class="form-control" maxlength="32"
                           pattern="[A-Za-z0-9._-]+" title="Hanya huruf, angka, titik, garis bawah, strip." required>
                </div>
                <div>
                    <label>Speed (kbps)</label>
                    <input type="number" id="sp-f-speed" class="form-control" min="1" max="2000000" required>
                </div>
                <div id="sp-form-error" style="color:var(--text-danger,#dc2626); font-size:0.85rem;"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" id="sp-form-cancel">Batal</button>
                <button type="submit" class="btn btn-primary" id="sp-form-submit">Simpan ke OLT</button>
            </div>
        </form>
    </div>
</div>

<style>
.sp-subnav { display:flex; gap:4px; border-bottom:1px solid var(--border-color); }
.sp-subnav-tab {
    padding:10px 16px; background:none; border:none; border-bottom:2px solid transparent;
    font-size:0.9rem; font-weight:500; color:var(--text-muted); cursor:pointer; transition:var(--transition-fast);
}
.sp-subnav-tab:hover { color:var(--text-main); }
.sp-subnav-tab.active { color:var(--text-accent); border-bottom-color:var(--text-accent); }
.modal-overlay { position:fixed; inset:0; background:rgba(0,0,0,0.5); display:flex; align-items:center; justify-content:center; z-index:1000; }
.modal-box { background:var(--bg-secondary); border-radius:var(--radius-sm); width:100%; }
.modal-header { display:flex; justify-content:space-between; align-items:center; padding:14px 18px; border-bottom:1px solid var(--border-color); }
.modal-close { background:none; border:none; font-size:1.4rem; cursor:pointer; color:var(--text-muted); }
.modal-body { padding:18px; }
.modal-body label { display:block; font-size:0.8rem; color:var(--text-muted); margin-bottom:4px; }
.modal-footer { display:flex; justify-content:flex-end; gap:8px; padding:14px 18px; border-top:1px solid var(--border-color); }
</style>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const oltSelect = document.getElementById('sp-olt-select');
    const tbody = document.getElementById('sp-table-body');
    const tabs = document.querySelectorAll('.sp-subnav-tab');
    let currentDirection = 'download';
    let cachedProfiles = [];

    const modal = document.getElementById('sp-modal-overlay');
    const form = document.getElementById('sp-form');
    const fDirection = document.getElementById('sp-f-direction');
    const fName = document.getElementById('sp-f-name');
    const fSpeed = document.getElementById('sp-f-speed');
    const formError = document.getElementById('sp-form-error');
    const modalTitle = document.getElementById('sp-modal-title');
    let editMode = false;

    function esc(str) {
        if (!str) return '';
        return str.toString().replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }

    function render() {
        const rows = cachedProfiles.filter(p => p.direction === currentDirection);
        if (!rows.length) {
            tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:var(--text-muted);">Belum ada data. Klik "Ambil dari OLT" atau "Add speed profile".</td></tr>';
            return;
        }
        tbody.innerHTML = rows.map(p => `
            <tr>
                <td>${esc(p.name)}</td>
                <td>${esc(p.speed_kbps)} kbps</td>
                <td><span class="badge bg-orange">Internet</span></td>
                <td>${esc(p.onu_count)}</td>
                <td style="display:flex; gap:6px;">
                    <button class="btn btn-xs btn-secondary btn-edit-sp" data-direction="${esc(p.direction)}" data-name="${esc(p.name)}" data-speed="${esc(p.speed_kbps)}">Edit</button>
                    <button class="btn btn-xs btn-danger btn-delete-sp" data-direction="${esc(p.direction)}" data-name="${esc(p.name)}" data-onus="${esc(p.onu_count)}">Delete</button>
                </td>
            </tr>
        `).join('');

        tbody.querySelectorAll('.btn-edit-sp').forEach(btn => {
            btn.addEventListener('click', () => openModal(true, btn.dataset));
        });
        tbody.querySelectorAll('.btn-delete-sp').forEach(btn => {
            btn.addEventListener('click', () => doDelete(btn.dataset));
        });
    }

    async function loadList() {
        const oltId = oltSelect.value;
        if (!oltId) { cachedProfiles = []; render(); return; }
        const res = await fetch(`action/speed-profiles.php?action=list&olt_id=${oltId}`).then(r => r.json());
        cachedProfiles = res.success ? (res.profiles || []) : [];
        render();
    }

    tabs.forEach(tab => {
        tab.addEventListener('click', () => {
            tabs.forEach(t => t.classList.remove('active'));
            tab.classList.add('active');
            currentDirection = tab.dataset.direction;
            render();
        });
    });

    oltSelect.addEventListener('change', loadList);

    function openModal(isEdit, data) {
        if (!oltSelect.value) { alert('Pilih OLT dulu.'); return; }
        editMode = isEdit;
        formError.textContent = '';
        modalTitle.textContent = isEdit ? 'Edit Speed Profile' : 'Add Speed Profile';
        fDirection.value = data?.direction || currentDirection;
        fDirection.disabled = isEdit;
        fName.value = data?.name || '';
        fName.readOnly = isEdit;
        fSpeed.value = data?.speed || '';
        modal.style.display = 'flex';
    }
    function closeModal() { modal.style.display = 'none'; }

    document.getElementById('btn-add-speed-profile').addEventListener('click', () => openModal(false, null));
    document.getElementById('sp-modal-close').addEventListener('click', closeModal);
    document.getElementById('sp-form-cancel').addEventListener('click', closeModal);

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const oltId = oltSelect.value;
        formError.textContent = '';
        const submitBtn = document.getElementById('sp-form-submit');
        submitBtn.disabled = true;
        submitBtn.textContent = 'Mengirim ke OLT...';
        try {
            const fd = new FormData();
            fd.append('action', 'save');
            fd.append('olt_id', oltId);
            fd.append('direction', fDirection.value);
            fd.append('name', fName.value.trim());
            fd.append('speed_kbps', fSpeed.value);
            const res = await fetch('action/speed-profiles.php', { method: 'POST', body: fd }).then(r => r.json());
            if (!res.success) { formError.textContent = res.message || 'Gagal menyimpan.'; return; }
            closeModal();
            loadList();
        } catch (e) {
            formError.textContent = 'Gagal terhubung ke server.';
        } finally {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Simpan ke OLT';
        }
    });

    async function doDelete(data) {
        const oltId = oltSelect.value;
        const onuCount = parseInt(data.onus || '0', 10);
        let warn = `Hapus speed profile "${data.name}" dari OLT?`;
        if (onuCount > 0) {
            warn = `PERINGATAN: profile "${data.name}" masih dipakai ${onuCount} ONU. Menghapusnya berisiko mengganggu layanan mereka. Tetap hapus dari OLT?`;
        }
        if (!confirm(warn)) return;
        const fd = new FormData();
        fd.append('action', 'delete');
        fd.append('olt_id', oltId);
        fd.append('direction', data.direction);
        fd.append('name', data.name);
        if (onuCount > 0) fd.append('force', '1');
        const res = await fetch('action/speed-profiles.php', { method: 'POST', body: fd }).then(r => r.json());
        if (!res.success) { alert(res.message || 'Gagal menghapus.'); return; }
        loadList();
    }
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
