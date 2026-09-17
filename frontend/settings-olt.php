<?php
// ==============================================================================
# SmartOLT Settings & OLT Management Page
// ==============================================================================
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../backend/driver.php';

// Ambil daftar OLT
$olts = $pdo->query("SELECT * FROM olts ORDER BY created_at DESC")->fetchAll();

// Tipe OLT yang tersedia diambil dari registry driver — menambah vendor baru
// cukup mendaftarkannya di olt_driver_registry() pada backend/driver.php.
$olt_types = get_supported_olt_types();
?>

<div class="section-actions">
    <button class="btn btn-primary" id="btn-open-add-olt-modal">
        <i data-lucide="plus"></i> Tambah OLT Baru
    </button>
</div>

<div class="content-card">
    <div class="card-header border-accent">
        <h2>Daftar Management OLT</h2>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="data-table" id="olt-table">
                <thead>
                    <tr>
                        <th>Nama OLT</th>
                        <th>Alamat IP</th>
                        <th>Tipe</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($olts)): ?>
                        <tr>
                            <td colspan="4" style="text-align:center;color:var(--text-muted);">Belum ada OLT terdaftar. Silakan klik tombol di atas untuk menambahkan.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($olts as $olt): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($olt['name']); ?></strong></td>
                                <td><?php echo htmlspecialchars($olt['ip']); ?></td>
                                <td><span class="badge bg-green"><?php echo htmlspecialchars($olt['type']); ?></span></td>
                                 <td>
                                     <div style="display:flex;gap:6px;">
                                         <a href="olt-detail.php?id=<?php echo (int)$olt['id']; ?>" class="btn btn-xs btn-outline" style="text-decoration:none; display:inline-flex; align-items:center;">
                                             <i data-lucide="eye" style="margin-right:2px;"></i> View
                                         </a>
                                         <button type="button" class="btn btn-xs btn-warning btn-edit-olt" 
                                                 data-id="<?php echo (int)$olt['id']; ?>"
                                                 data-name="<?php echo htmlspecialchars($olt['name']); ?>"
                                                 data-type="<?php echo htmlspecialchars($olt['type']); ?>"
                                                 data-ip="<?php echo htmlspecialchars($olt['ip']); ?>"
                                                 data-protocol="<?php echo htmlspecialchars($olt['protocol'] ?? 'SSH'); ?>"
                                                 data-port="<?php echo htmlspecialchars($olt['ssh_port']); ?>"
                                                 data-username="<?php echo htmlspecialchars($olt['username']); ?>"
                                                 data-snmp-port="<?php echo htmlspecialchars($olt['snmp_port'] ?? '8161'); ?>"
                                                 data-snmp-community="<?php echo htmlspecialchars($olt['snmp_community'] ?? ''); ?>"
                                                 data-snmp-community-rw="<?php echo htmlspecialchars($olt['snmp_community_rw'] ?? ''); ?>"
                                                 style="display:inline-flex; align-items:center;">
                                             <i data-lucide="edit" style="margin-right:2px;"></i> Edit
                                         </button>
                                         <form action="action/delete-olt.php" method="POST" onsubmit="return confirm('Apakah Anda yakin ingin menghapus OLT ini? Seluruh ONU yang terikat akan dihapus dari cache lokal.');" style="margin:0;">
                                             <input type="hidden" name="id" value="<?php echo (int)$olt['id']; ?>">
                                             <button type="submit" class="btn btn-xs btn-danger" style="display:inline-flex; align-items:center;">
                                                 <i data-lucide="trash-2" style="margin-right:2px;"></i> Hapus
                                             </button>
                                         </form>
                                     </div>
                                 </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- MODAL: TAMBAH OLT -->
<div class="modal" id="modal-add-olt">
    <div class="modal-content">
        <form action="action/add-olt.php" method="POST" id="form-add-olt">
            <div class="modal-header">
                <h2>Tambah OLT Baru</h2>
                <button type="button" class="modal-close" id="btn-close-olt-modal">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label for="olt-name">Nama OLT / Lokasi</label>
                    <input type="text" id="olt-name" name="name" class="form-control" placeholder="Masukkan nama OLT atau lokasi" required>
                </div>
                <div class="form-row">
                    <div class="form-group" style="flex:1;">
                        <label for="olt-type">Tipe OLT</label>
                        <select id="olt-type" name="type" class="form-control">
                            <?php foreach ($olt_types as $type_value => $type_label): ?>
                            <option value="<?php echo htmlspecialchars($type_value); ?>"><?php echo htmlspecialchars($type_label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="flex:2;">
                        <label for="olt-ip">Alamat IP OLT</label>
                        <input type="text" id="olt-ip" name="ip" class="form-control" placeholder="Masukkan alamat IP OLT" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group" style="flex:1;">
                        <label for="olt-protocol">Protokol CLI</label>
                        <select id="olt-protocol" name="protocol" class="form-control">
                            <option value="SSH">SSH</option>
                            <option value="TELNET">TELNET</option>
                        </select>
                    </div>
                    <div class="form-group" style="flex:1;">
                        <label for="olt-ssh-port" id="lbl-ssh-port">Port SSH</label>
                        <input type="number" id="olt-ssh-port" name="ssh_port" class="form-control" value="22" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group" style="flex:1;">
                        <label for="olt-user">Username</label>
                        <input type="text" id="olt-user" name="username" class="form-control" value="admin" required>
                    </div>
                    <div class="form-group" style="flex:1.5;">
                        <label for="olt-pass">Password</label>
                        <input type="password" id="olt-pass" name="password" class="form-control" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group" style="flex:1;">
                        <label for="olt-snmp-port">Port SNMP</label>
                        <input type="number" id="olt-snmp-port" name="snmp_port" class="form-control" value="8161" required>
                    </div>
                </div>
                 <div class="error-box hidden" id="add-olt-error"></div>
                 <div class="alert alert-success hidden" id="add-olt-success" style="background:rgba(34,197,94,0.15); border:1px solid #22c55e; color:#22c55e; padding:12px; border-radius:6px; margin-bottom:15px; font-weight:600;"></div>
                 <div class="loading-spinner hidden" id="add-olt-loading">
                     <div class="spinner"></div>
                     <p id="loading-text">Sedang memverifikasi koneksi OLT...</p>
                 </div>
             </div>
             <div class="modal-footer">
                 <button type="button" class="btn btn-secondary" id="btn-cancel-olt-modal">Batal</button>
                 <button type="button" class="btn btn-warning" id="btn-verify-ssh">Verifikasi Koneksi SSH</button>
                 <button type="submit" class="btn btn-success hidden" id="btn-submit-olt">Set SNMP & Simpan OLT</button>
             </div>
        </form>
    </div>
</div>

<!-- MODAL: EDIT OLT -->
<div class="modal" id="modal-edit-olt">
    <div class="modal-content">
        <form action="action/edit-olt.php" method="POST" id="form-edit-olt">
            <input type="hidden" name="id" id="edit-olt-id">
            <div class="modal-header">
                <h2>Edit OLT</h2>
                <button type="button" class="modal-close" id="btn-close-edit-olt-modal">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label for="edit-olt-name">Nama OLT / Lokasi</label>
                    <input type="text" id="edit-olt-name" name="name" class="form-control" placeholder="Masukkan nama OLT atau lokasi" required>
                </div>
                <div class="form-row">
                    <div class="form-group" style="flex:1;">
                        <label for="edit-olt-type">Tipe OLT</label>
                        <select id="edit-olt-type" name="type" class="form-control">
                            <?php foreach ($olt_types as $type_value => $type_label): ?>
                            <option value="<?php echo htmlspecialchars($type_value); ?>"><?php echo htmlspecialchars($type_label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="flex:2;">
                        <label for="edit-olt-ip">Alamat IP OLT</label>
                        <input type="text" id="edit-olt-ip" name="ip" class="form-control" placeholder="Masukkan alamat IP OLT" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group" style="flex:1;">
                        <label for="edit-olt-protocol">Protokol CLI</label>
                        <select id="edit-olt-protocol" name="protocol" class="form-control">
                            <option value="SSH">SSH</option>
                            <option value="TELNET">TELNET</option>
                        </select>
                    </div>
                    <div class="form-group" style="flex:1;">
                        <label for="edit-olt-ssh-port" id="lbl-edit-ssh-port">Port SSH</label>
                        <input type="number" id="edit-olt-ssh-port" name="ssh_port" class="form-control" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group" style="flex:1;">
                        <label for="edit-olt-user">Username</label>
                        <input type="text" id="edit-olt-user" name="username" class="form-control" required>
                    </div>
                    <div class="form-group" style="flex:1.5;">
                        <label for="edit-olt-pass">Password</label>
                        <input type="password" id="edit-olt-pass" name="password" class="form-control" placeholder="Kosongkan jika tidak diubah">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group" style="flex:1;">
                        <label for="edit-olt-snmp-comm">Community SNMP RO</label>
                        <input type="text" id="edit-olt-snmp-comm" name="snmp_community" class="form-control" required>
                    </div>
                    <div class="form-group" style="flex:1;">
                        <label for="edit-olt-snmp-comm-rw">Community SNMP RW</label>
                        <input type="text" id="edit-olt-snmp-comm-rw" name="snmp_community_rw" class="form-control" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group" style="flex:1;">
                        <label for="edit-olt-snmp-port">Port SNMP</label>
                        <input type="number" id="edit-olt-snmp-port" name="snmp_port" class="form-control" required>
                    </div>
                </div>
                 <div class="error-box hidden" id="edit-olt-error"></div>
                 <div class="alert alert-success hidden" id="edit-olt-success" style="background:rgba(34,197,94,0.15); border:1px solid #22c55e; color:#22c55e; padding:12px; border-radius:6px; margin-bottom:15px; font-weight:600;"></div>
                 <div class="loading-spinner hidden" id="edit-olt-loading">
                     <div class="spinner"></div>
                     <p id="edit-loading-text">Sedang memverifikasi koneksi OLT...</p>
                 </div>
             </div>
             <div class="modal-footer">
                 <button type="button" class="btn btn-secondary" id="btn-cancel-edit-olt-modal">Batal</button>
                 <button type="button" class="btn btn-warning" id="btn-edit-verify-ssh">Verifikasi Koneksi SSH</button>
                 <button type="submit" class="btn btn-success hidden" id="btn-edit-submit-olt">Set SNMP & Simpan Perubahan</button>
             </div>
         </form>
     </div>
</div>

<script>
    function esc(v) {
        if (v === null || v === undefined) return '';
        return String(v)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }
    document.addEventListener('DOMContentLoaded', () => {
        const modal = document.getElementById('modal-add-olt');
        const openBtn = document.getElementById('btn-open-add-olt-modal');
        const closeBtn = document.getElementById('btn-close-olt-modal');
        const cancelBtn = document.getElementById('btn-cancel-olt-modal');

        const form = document.getElementById('form-add-olt');
        const verifyBtn = document.getElementById('btn-verify-ssh');
        const submitBtn = document.getElementById('btn-submit-olt');
        const errorBox = document.getElementById('add-olt-error');
        const successBox = document.getElementById('add-olt-success');
        const loadingSpinner = document.getElementById('add-olt-loading');
        const loadingText = document.getElementById('loading-text');

        const resetModal = () => {
            form.reset();
            errorBox.classList.add('hidden');
            successBox.classList.add('hidden');
            loadingSpinner.classList.add('hidden');
            verifyBtn.classList.remove('hidden');
            verifyBtn.textContent = 'Verifikasi Koneksi SSH';
            submitBtn.classList.add('hidden');
            cancelBtn.classList.remove('hidden');
            portLabel.textContent = 'Port SSH';
        };

        openBtn.addEventListener('click', () => {
            resetModal();
            modal.classList.add('open');
        });
        
        const closeModal = () => {
            modal.classList.remove('open');
            resetModal();
        };

        closeBtn.addEventListener('click', closeModal);
        cancelBtn.addEventListener('click', closeModal);

        const protocolSelect = document.getElementById('olt-protocol');
        const portInput = document.getElementById('olt-ssh-port');
        const portLabel = document.getElementById('lbl-ssh-port');

        protocolSelect.addEventListener('change', () => {
            if (protocolSelect.value === 'TELNET') {
                portInput.value = '23';
                portLabel.textContent = 'Port Telnet';
                verifyBtn.textContent = 'Verifikasi Koneksi Telnet';
            } else {
                portInput.value = '22';
                portLabel.textContent = 'Port SSH';
                verifyBtn.textContent = 'Verifikasi Koneksi SSH';
            }
        });

        // CLI verification logic
        verifyBtn.addEventListener('click', () => {
            const protocol = protocolSelect.value;
            const ip = document.getElementById('olt-ip').value.trim();
            const sshPort = portInput.value.trim();
            const username = document.getElementById('olt-user').value.trim();
            const password = document.getElementById('olt-pass').value.trim();

            if (!ip || !username || !password) {
                errorBox.textContent = 'IP, Username, dan Password wajib diisi!';
                errorBox.classList.remove('hidden');
                return;
            }

            errorBox.classList.add('hidden');
            successBox.classList.add('hidden');
            loadingText.textContent = `Sedang memverifikasi koneksi ${protocol}...`;
            loadingSpinner.classList.remove('hidden');

            const formData = new FormData();
            formData.append('type', document.getElementById('olt-type').value);
            formData.append('protocol', protocol);
            formData.append('ip', ip);
            formData.append('ssh_port', sshPort);
            formData.append('username', username);
            formData.append('password', password);

            fetch('action/verify-ssh-only.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                loadingSpinner.classList.add('hidden');
                if (data.success) {
                    successBox.textContent = data.message;
                    successBox.classList.remove('hidden');
                    verifyBtn.classList.add('hidden');
                    submitBtn.classList.remove('hidden');
                } else {
                    errorBox.textContent = data.message;
                    errorBox.classList.remove('hidden');
                }
            })
            .catch(err => {
                loadingSpinner.classList.add('hidden');
                errorBox.textContent = 'Gagal menghubungi server untuk verifikasi SSH!';
                errorBox.classList.remove('hidden');
            });
        });

        // Submit form (Set SNMP & Save) logic
        form.addEventListener('submit', () => {
            errorBox.classList.add('hidden');
            successBox.classList.add('hidden');
            loadingText.textContent = 'Sedang memproses provisi SNMP & menyimpan OLT...';
            loadingSpinner.classList.remove('hidden');
            submitBtn.classList.add('hidden');
            cancelBtn.classList.add('hidden');
        });

        // Edit OLT modal fields binding
        const editModal = document.getElementById('modal-edit-olt');
        const closeEditBtn = document.getElementById('btn-close-edit-olt-modal');
        const cancelEditBtn = document.getElementById('btn-cancel-edit-olt-modal');
        const editForm = document.getElementById('form-edit-olt');

        const editVerifyBtn = document.getElementById('btn-edit-verify-ssh');
        const editSubmitBtn = document.getElementById('btn-edit-submit-olt');
        const editErrorBox = document.getElementById('edit-olt-error');
        const editSuccessBox = document.getElementById('edit-olt-success');
        const editLoadingSpinner = document.getElementById('edit-olt-loading');
        const editLoadingText = document.getElementById('edit-loading-text');

        const editProtocolSelect = document.getElementById('edit-olt-protocol');
        const editPortInput = document.getElementById('edit-olt-ssh-port');
        const editPortLabel = document.getElementById('lbl-edit-ssh-port');

        editProtocolSelect.addEventListener('change', () => {
            if (editProtocolSelect.value === 'TELNET') {
                editPortInput.value = '23';
                editPortLabel.textContent = 'Port Telnet';
                editVerifyBtn.textContent = 'Verifikasi Koneksi Telnet';
            } else {
                editPortInput.value = '22';
                editPortLabel.textContent = 'Port SSH';
                editVerifyBtn.textContent = 'Verifikasi Koneksi SSH';
            }
        });

        const resetEditModal = () => {
            editForm.reset();
            editErrorBox.classList.add('hidden');
            editSuccessBox.classList.add('hidden');
            editLoadingSpinner.classList.add('hidden');
            editVerifyBtn.classList.remove('hidden');
            editVerifyBtn.textContent = 'Verifikasi Koneksi SSH';
            editSubmitBtn.classList.add('hidden');
            cancelEditBtn.classList.remove('hidden');
            editPortLabel.textContent = 'Port SSH';
        };

        const closeEditModal = () => {
            editModal.classList.remove('open');
            resetEditModal();
        };

        closeEditBtn.addEventListener('click', closeEditModal);
        cancelEditBtn.addEventListener('click', closeEditModal);

        document.querySelectorAll('.btn-edit-olt').forEach(btn => {
            btn.addEventListener('click', () => {
                resetEditModal();
                
                const id = btn.getAttribute('data-id');
                const name = btn.getAttribute('data-name');
                const type = btn.getAttribute('data-type');
                const ip = btn.getAttribute('data-ip');
                const protocol = btn.getAttribute('data-protocol') || 'SSH';
                const port = btn.getAttribute('data-port');
                const username = btn.getAttribute('data-username');
                const snmpPort = btn.getAttribute('data-snmp-port');
                const snmpCommunity = btn.getAttribute('data-snmp-community');
                const snmpCommunityRw = btn.getAttribute('data-snmp-community-rw');

                document.getElementById('edit-olt-id').value = id;
                document.getElementById('edit-olt-name').value = name;
                document.getElementById('edit-olt-type').value = type;
                document.getElementById('edit-olt-ip').value = ip;
                editProtocolSelect.value = protocol;
                editPortInput.value = port;
                document.getElementById('edit-olt-user').value = username;
                document.getElementById('edit-olt-pass').value = ''; 
                document.getElementById('edit-olt-snmp-comm').value = snmpCommunity;
                document.getElementById('edit-olt-snmp-comm-rw').value = snmpCommunityRw;
                document.getElementById('edit-olt-snmp-port').value = snmpPort;

                if (protocol === 'TELNET') {
                    editPortLabel.textContent = 'Port Telnet';
                    editVerifyBtn.textContent = 'Verifikasi Koneksi Telnet';
                } else {
                    editPortLabel.textContent = 'Port SSH';
                    editVerifyBtn.textContent = 'Verifikasi Koneksi SSH';
                }

                editModal.classList.add('open');
            });
        });

        // Edit Modal verification logic
        editVerifyBtn.addEventListener('click', () => {
            const id = document.getElementById('edit-olt-id').value;
            const protocol = editProtocolSelect.value;
            const ip = document.getElementById('edit-olt-ip').value.trim();
            const sshPort = editPortInput.value.trim();
            const username = document.getElementById('edit-olt-user').value.trim();
            const password = document.getElementById('edit-olt-pass').value.trim();
            const snmpCommunity = document.getElementById('edit-olt-snmp-comm').value.trim();
            const snmpCommunityRw = document.getElementById('edit-olt-snmp-comm-rw').value.trim();

            if (!ip || !username || !snmpCommunity || !snmpCommunityRw) {
                editErrorBox.textContent = 'IP, Username, dan SNMP Community RO/RW wajib diisi!';
                editErrorBox.classList.remove('hidden');
                return;
            }

            editErrorBox.classList.add('hidden');
            editSuccessBox.classList.add('hidden');
            editLoadingText.textContent = `Sedang memverifikasi koneksi ${protocol}...`;
            editLoadingSpinner.classList.remove('hidden');

            const formData = new FormData();
            formData.append('id', id);
            formData.append('type', document.getElementById('edit-olt-type').value);
            formData.append('protocol', protocol);
            formData.append('ip', ip);
            formData.append('ssh_port', sshPort);
            formData.append('username', username);
            formData.append('password', password); 
            formData.append('snmp_community', snmpCommunity);
            formData.append('snmp_community_rw', snmpCommunityRw);

            fetch('action/verify-ssh-only.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                editLoadingSpinner.classList.add('hidden');
                if (data.success) {
                    editSuccessBox.textContent = data.message;
                    editSuccessBox.classList.remove('hidden');
                    editVerifyBtn.classList.add('hidden');
                    editSubmitBtn.classList.remove('hidden');
                } else {
                    editErrorBox.textContent = data.message;
                    editErrorBox.classList.remove('hidden');
                }
            })
            .catch(err => {
                editLoadingSpinner.classList.add('hidden');
                editErrorBox.textContent = 'Gagal menghubungi server untuk verifikasi koneksi!';
                editErrorBox.classList.remove('hidden');
            });
        });

        editForm.addEventListener('submit', () => {
            editErrorBox.classList.add('hidden');
            editSuccessBox.classList.add('hidden');
            editLoadingText.textContent = 'Sedang memproses provisi SNMP & menyimpan OLT...';
            editLoadingSpinner.classList.remove('hidden');
            editSubmitBtn.classList.add('hidden');
            cancelEditBtn.classList.add('hidden');
        });

        // Verifikasi Status Koneksi OLT secara Asinkron
        const oltRows = document.querySelectorAll('.btn-test-conn');
        
        function verifyConnection(oltId) {
            const statusCell = document.getElementById(`status-olt-${oltId}`);
            statusCell.innerHTML = '<span class="badge bg-orange">Menguji...</span>';
            
            fetch(`action/test-olt.php?id=${oltId}`)
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        statusCell.innerHTML = `<span class="badge bg-green" title="${esc(data.message)}">Online</span>`;
                    } else {
                        statusCell.innerHTML = `<span class="badge bg-red" title="${esc(data.message)}">Offline</span>`;
                    }
                })
                .catch(err => {
                    statusCell.innerHTML = '<span class="badge bg-red" title="Gagal Fetching">Error</span>';
                });
        }

        // Setup tombol manual test dan klik status badge
        oltRows.forEach(btn => {
            const id = btn.getAttribute('data-id');

            // Tambahkan event click untuk manual test
            btn.addEventListener('click', () => {
                verifyConnection(id);
            });
        });

        // Tambahkan event click untuk badge status
        const statusBadges = document.querySelectorAll('.btn-status-test');
        statusBadges.forEach(badge => {
            badge.addEventListener('click', () => {
                const id = badge.getAttribute('data-id');
                verifyConnection(id);
            });
        });
    });
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
