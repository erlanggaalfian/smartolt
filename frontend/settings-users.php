<?php
// ==============================================================================
// SmartOLT Settings - Users Management Page
// Lokasi: /frontend/settings-users.php
// ==============================================================================
require_once __DIR__ . '/header.php';

// Ambil daftar user yang boleh dilihat/dikelola (superadmin: semua; admin: grupnya saja)
$users = get_manageable_users();
// Ambil OLT untuk pendelegasian akses (superadmin: semua; admin: OLT miliknya saja)
$all_olts = get_allowed_olts();
?>

<div class="section-actions">
    <button class="btn btn-primary" id="btn-open-add-user-modal">
        <i data-lucide="user-plus"></i> Tambah User Baru
    </button>
</div>

<div class="content-card">
    <div class="card-header border-accent">
        <h1 id="page-title">Manajemen User &amp; Hak Akses</h1>
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
                <tbody class="motion-fade-in">
                    <?php foreach ($users as $user): ?>
                        <tr>
                            <td><?php echo (int)$user['id']; ?></td>
                            <td>
                                <div style="display:flex; align-items:center; gap:8px;">
                                    <i data-lucide="user" style="width:16px; height:16px; color:var(--text-muted);"></i>
                                    <strong style="color:var(--text-muted);"><?php echo htmlspecialchars($user['username']); ?></strong>
                                </div>
                            </td>
                            <td>
                                <?php if ($user['role'] === 'superadmin'): ?>
                                    <span class="badge badge-online">Superadmin</span>
                                <?php elseif ($user['role'] === 'admin'): ?>
                                    <span class="badge badge-warning">Admin</span>
                                <?php else: ?>
                                    <span class="badge badge-offline">User</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo date('d-m-Y H:i', strtotime($user['created_at'])); ?> WIB</td>
                            <td style="text-align: center;">
                                <?php if ($user['id'] == $_SESSION['smartolt_user_id']): ?>
                                    <span class="text-muted" style="font-size:0.85rem; font-style:italic;">Akun Aktif</span>
                                <?php elseif (!can_manage_target_user((int)$user['id'], $user['role'])): ?>
                                    <span class="text-muted" style="font-size:0.85rem; font-style:italic;">Di luar grup Anda</span>
                                <?php else: ?>
                                    <?php if ($user['role'] !== 'superadmin'): ?>
                                        <button type="button" class="btn-action btn-edit-user" data-user-id="<?php echo (int)$user['id']; ?>" data-username="<?php echo htmlspecialchars($user['username']); ?>" title="Edit User" style="display:inline-flex; align-items:center; justify-content:center; padding:6px; background:#fef9c3; border:none; border-radius:4px; color:#a16207; margin-right:4px; cursor:pointer;">
                                            <i data-lucide="pencil" style="width:16px; height:16px;"></i>
                                        </button>
                                        <button type="button" class="btn-action btn-manage-access" data-user-id="<?php echo (int)$user['id']; ?>" data-username="<?php echo htmlspecialchars($user['username']); ?>" title="Kelola Akses OLT" style="display:inline-flex; align-items:center; justify-content:center; padding:6px; background:#e0f2fe; border:none; border-radius:4px; color:#0369a1; margin-right:4px; cursor:pointer;">
                                            <i data-lucide="key-round" style="width:16px; height:16px;"></i>
                                        </button>
                                    <?php else: ?>
                                        <button type="button" class="btn-action btn-edit-user" data-user-id="<?php echo (int)$user['id']; ?>" data-username="<?php echo htmlspecialchars($user['username']); ?>" title="Edit User" style="display:inline-flex; align-items:center; justify-content:center; padding:6px; background:#fef9c3; border:none; border-radius:4px; color:#a16207; margin-right:4px; cursor:pointer;">
                                            <i data-lucide="pencil" style="width:16px; height:16px;"></i>
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
                        <option value="user">User (Kelola ONU di grupnya, tanpa Settings)</option>
                        <option value="admin">Admin (Kelola penuh di grupnya, termasuk Settings)</option>
                        <?php if ($_SESSION['smartolt_role'] === 'superadmin'): ?>
                            <option value="superadmin">Superadmin (Akses Penuh Tanpa Batas)</option>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="form-group" id="group-add-user-olts" style="margin-top: 15px;">
                    <label style="font-weight: 500; margin-bottom: 5px; display: block;">Akses OLT (Grup)</label>
                    <div style="max-height: 150px; overflow-y: auto; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px; background: var(--bg-primary); display: flex; flex-direction: column; gap: 8px;">
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
                <p style="margin-bottom: 15px;">Pilih OLT yang boleh diakses oleh user: <strong id="access-username" style="color:var(--text-accent);"></strong></p>
                <div style="max-height: 250px; overflow-y: auto; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px; background: var(--bg-primary); display: flex; flex-direction: column; gap: 10px;">
                    <?php if (count($all_olts) === 0): ?>
                        <p style="font-style: italic; color: var(--text-muted);">Belum ada OLT terdaftar.</p>
                    <?php else: ?>
                        <?php foreach ($all_olts as $olt): ?>
                            <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                                <input type="checkbox" name="olt_ids[]" value="<?php echo (int)$olt['id']; ?>" class="olt-access-checkbox">
                                <div>
                                    <strong style="display: block; font-size: 0.9rem; color: var(--text-muted);"><?php echo htmlspecialchars($olt['name']); ?></strong>
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

<!-- MODAL EDIT USER -->
<div id="edit-user-modal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2><i data-lucide="pencil"></i> Edit User</h2>
            <button class="modal-close" id="btn-close-edit-user-modal">&times;</button>
        </div>
        <form id="form-edit-user">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" id="edit-user-id">
            <div class="modal-body">
                <div class="form-group">
                    <label for="edit-username">Username</label>
                    <input type="text" id="edit-username" name="username" required class="form-control">
                </div>
                <div class="form-group">
                    <label for="edit-password">Password Baru <span style="font-weight:400;font-size:0.8rem;color:var(--text-muted);">(kosongkan jika tidak diganti)</span></label>
                    <input type="password" id="edit-password" name="password" placeholder="Biarkan kosong jika tidak diganti" class="form-control">
                </div>
                <div class="form-group">
                    <label for="edit-role">Hak Akses / Peran</label>
                    <select id="edit-role" name="role" required class="form-control">
                        <option value="user">User</option>
                        <option value="admin">Admin</option>
                        <?php if ($_SESSION['smartolt_role'] === 'superadmin'): ?>
                            <option value="superadmin">Superadmin</option>
                        <?php endif; ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" id="btn-cancel-edit-user-modal">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
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
        if (selectRole.value === 'superadmin') {
            groupOlts.style.display = 'none';
        } else {
            groupOlts.style.display = 'block';
        }
    });

    const closeModal = () => {
        modal.classList.remove('open');
        form.reset();
        groupOlts.style.display = 'block';
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
            if (editModal.classList.contains('open')) closeEditModal();
        }
    });

    // Edit User modal logic
    const editModal = document.getElementById('edit-user-modal');
    const editUserId = document.getElementById('edit-user-id');
    const editUsername = document.getElementById('edit-username');
    const editPassword = document.getElementById('edit-password');
    const editRole = document.getElementById('edit-role');
    const formEdit = document.getElementById('form-edit-user');

    document.querySelectorAll('.btn-edit-user').forEach(btn => {
        btn.addEventListener('click', async () => {
            const userId = btn.getAttribute('data-user-id');
            editUserId.value = userId;
            editPassword.value = '';
            try {
                const res = await fetch(`action/user.php?action=get_user&user_id=${userId}`);
                const data = await res.json();
                if (data.success) {
                    editUsername.value = data.user.username;
                    editRole.value = data.user.role;
                    editModal.classList.add('open');
                } else {
                    alert(data.message || 'Gagal memuat data user.');
                }
            } catch (e) {
                alert('Gagal memuat data user.');
            }
        });
    });

    const closeEditModal = () => {
        editModal.classList.remove('open');
        formEdit.reset();
    };
    document.getElementById('btn-close-edit-user-modal').addEventListener('click', closeEditModal);
    document.getElementById('btn-cancel-edit-user-modal').addEventListener('click', closeEditModal);

    formEdit.addEventListener('submit', async (e) => {
        e.preventDefault();
        const fd = new FormData(formEdit);
        try {
            const res = await fetch('action/user.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.success) {
                alert(data.message);
                location.reload();
            } else {
                alert(data.message || 'Gagal update.');
            }
        } catch (e) {
            alert('Terjadi kesalahan koneksi.');
        }
    });
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
