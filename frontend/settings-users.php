<?php
// ==============================================================================
// SmartOLT Settings - Users Management Page
// Lokasi: /frontend/settings-users.php
// ==============================================================================
require_once __DIR__ . '/header.php';

// Ambil daftar user
$users = $pdo->query("SELECT id, username, role, created_at FROM users ORDER BY created_at DESC")->fetchAll();
// Ambil semua OLT untuk pendelegasian akses
$all_olts = $pdo->query("SELECT id, name, ip FROM olts ORDER BY name ASC")->fetchAll();
?>

<div class="section-actions">
    <button class="btn btn-primary" id="btn-open-add-user-modal">
        <i data-lucide="user-plus"></i> Tambah User Baru
    </button>
</div>

<div class="content-card">
    <div class="card-header border-accent">
        <h2>Manajemen User & Hak Akses</h2>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th style="width: 80px;">ID</th>
                        <th>Username</th>
                        <th>Peran (Role)</th>
                        <th>Tanggal Dibuat</th>
                        <th style="width: 120px; text-align: center;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                        <tr>
                            <td><?php echo (int)$user['id']; ?></td>
                            <td>
                                <div style="display:flex; align-items:center; gap:8px;">
                                    <i data-lucide="user" style="width:16px; height:16px; color:var(--text-muted);"></i>
                                    <strong style="color:var(--text-color);"><?php echo htmlspecialchars($user['username']); ?></strong>
                                </div>
                            </td>
                            <td>
                                <?php if ($user['role'] === 'superadmin'): ?>
                                    <span class="badge badge-online">Superadmin</span>
                                <?php else: ?>
                                    <span class="badge badge-offline">User Biasa</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo date('d-m-Y H:i', strtotime($user['created_at'])); ?> WIB</td>
                            <td style="text-align: center;">
                                <?php if ($user['id'] == $_SESSION['smartolt_user_id']): ?>
                                    <span class="text-muted" style="font-size:0.85rem; font-style:italic;">Akun Aktif</span>
                                <?php else: ?>
                                    <?php if ($user['role'] === 'biasa'): ?>
                                        <button type="button" class="btn-action btn-manage-access" data-user-id="<?php echo (int)$user['id']; ?>" data-username="<?php echo htmlspecialchars($user['username']); ?>" title="Kelola Akses OLT" style="display:inline-flex; align-items:center; justify-content:center; padding:6px; background:#e0f2fe; border:none; border-radius:4px; color:#0369a1; margin-right:4px; cursor:pointer;">
                                            <i data-lucide="key-round" style="width:16px; height:16px;"></i>
                                        </button>
                                    <?php endif; ?>
                                    <form action="action/user.php" method="POST" onsubmit="return confirm('Apakah Anda yakin ingin menghapus user ini?');" style="display:inline;">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo (int)$user['id']; ?>">
                                        <button type="submit" class="btn-action btn-delete" title="Hapus User">
                                            <i data-lucide="trash-2"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- MODAL TAMBAH USER -->
<div id="add-user-modal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2><i data-lucide="user-plus"></i> Tambah User Baru</h2>
            <button class="modal-close" id="btn-close-add-user-modal">&times;</button>
        </div>
        <form id="form-add-user" action="action/user.php" method="POST">
            <input type="hidden" name="action" value="add">
            <div class="modal-body">
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" placeholder="Masukkan username" required class="form-control">
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" placeholder="Masukkan password" required class="form-control">
                </div>
                <div class="form-group">
                    <label for="role">Hak Akses / Peran</label>
                    <select id="role" name="role" required class="form-control">
                        <option value="biasa">User Biasa (Hanya Monitoring & Kelola ONU)</option>
                        <option value="superadmin">Superadmin (Akses Penuh & Set Config OLT)</option>
                    </select>
                </div>
                <div class="form-group" id="group-add-user-olts" style="display:none; margin-top: 15px;">
                    <label style="font-weight: 500; margin-bottom: 5px; display: block;">Akses OLT (Hanya untuk User Biasa)</label>
                    <div style="max-height: 150px; overflow-y: auto; padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px; background: #f8fafc; display: flex; flex-direction: column; gap: 8px;">
                        <?php foreach ($all_olts as $olt): ?>
                            <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 0.9rem;">
                                <input type="checkbox" name="olt_ids[]" value="<?php echo (int)$olt['id']; ?>">
                                <span><?php echo htmlspecialchars($olt['name']); ?> (<?php echo htmlspecialchars($olt['ip']); ?>)</span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" id="btn-cancel-add-user-modal">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan User</button>
            </div>
        </form>
    </div>
</div>


<!-- MODAL KELOLA AKSES OLT -->
<div id="manage-access-modal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2><i data-lucide="key-round"></i> Kelola Akses OLT</h2>
            <button class="modal-close" id="btn-close-access-modal">&times;</button>
        </div>
        <form id="form-manage-access" action="action/user.php" method="POST">
            <input type="hidden" name="action" value="update_access">
            <input type="hidden" name="user_id" id="access-user-id">
            <div class="modal-body">
                <p style="margin-bottom: 15px;">Pilih OLT yang boleh diakses oleh user: <strong id="access-username" style="color:var(--primary-color);"></strong></p>
                <div style="max-height: 250px; overflow-y: auto; padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px; background: #f8fafc; display: flex; flex-direction: column; gap: 10px;">
                    <?php if (count($all_olts) === 0): ?>
                        <p style="font-style: italic; color: var(--text-muted);">Belum ada OLT terdaftar.</p>
                    <?php else: ?>
                        <?php foreach ($all_olts as $olt): ?>
                            <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                                <input type="checkbox" name="olt_ids[]" value="<?php echo (int)$olt['id']; ?>" class="olt-access-checkbox">
                                <div>
                                    <strong style="display: block; font-size: 0.9rem; color: var(--text-color);"><?php echo htmlspecialchars($olt['name']); ?></strong>
                                    <span style="font-size: 0.8rem; color: var(--text-muted);"><?php echo htmlspecialchars($olt['ip']); ?></span>
                                </div>
                            </label>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" id="btn-cancel-access-modal">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan Akses</button>
            </div>
        </form>
    </div>
</div>

<script>
    // Modal toggle logic
    const modal = document.getElementById('add-user-modal');
    const btnOpen = document.getElementById('btn-open-add-user-modal');
    const btnClose = document.getElementById('btn-close-add-user-modal');
    const btnCancel = document.getElementById('btn-cancel-add-user-modal');
    const form = document.getElementById('form-add-user');
    const selectRole = document.getElementById('role');
    const groupOlts = document.getElementById('group-add-user-olts');

    btnOpen.addEventListener('click', () => {
        modal.classList.add('open');
    });

    selectRole.addEventListener('change', () => {
        if (selectRole.value === 'biasa') {
            groupOlts.style.display = 'block';
        } else {
            groupOlts.style.display = 'none';
        }
    });

    const closeModal = () => {
        modal.classList.remove('open');
        form.reset();
        groupOlts.style.display = 'none';
    };

    btnClose.addEventListener('click', closeModal);
    btnCancel.addEventListener('click', closeModal);

    // Manage Access modal logic
    const accessModal = document.getElementById('manage-access-modal');
    const accessUserId = document.getElementById('access-user-id');
    const accessUsername = document.getElementById('access-username');
    const closeAccessModalBtn = document.getElementById('btn-close-access-modal');
    const cancelAccessModalBtn = document.getElementById('btn-cancel-access-modal');

    document.querySelectorAll('.btn-manage-access').forEach(btn => {
        btn.addEventListener('click', async () => {
            const userId = btn.getAttribute('data-user-id');
            const username = btn.getAttribute('data-username');
            
            accessUserId.value = userId;
            accessUsername.innerText = username;
            
            // Reset checkboxes
            document.querySelectorAll('.olt-access-checkbox').forEach(cb => cb.checked = false);
            
            // Fetch current access
            try {
                const res = await fetch(`action/user.php?action=get_access&user_id=${userId}`);
                const data = await res.json();
                if (data.success && Array.isArray(data.olt_ids)) {
                    data.olt_ids.forEach(id => {
                        const cb = document.querySelector(`.olt-access-checkbox[value="${id}"]`);
                        if (cb) cb.checked = true;
                    });
                }
            } catch (e) {
                console.error(e);
            }
            
            accessModal.classList.add('open');
        });
    });

    const closeAccessModal = () => {
        accessModal.classList.remove('open');
    };

    closeAccessModalBtn.addEventListener('click', closeAccessModal);
    cancelAccessModalBtn.addEventListener('click', closeAccessModal);

    // Close on outside click
    window.addEventListener('click', (e) => {
        if (e.target === modal) {
            closeModal();
        }
        if (e.target === accessModal) {
            closeAccessModal();
        }
    });

    // Close on Escape key
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            if (modal.classList.contains('open')) closeModal();
            if (accessModal.classList.contains('open')) closeAccessModal();
        }
    });
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
