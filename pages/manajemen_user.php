<?php
defined('host') or die('Akses langsung tidak diizinkan.');

// Handle Actions (POST requests)
$success_msg = '';
$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'create') {
        $nik = trim($_POST['nik'] ?? '');
        $password = $_POST['password'] ?? '';
        $copy_from = $_POST['copy_from'] ?? '';

        if (!empty($nik) && !empty($password)) {
            // Check if user already exists
            $stmt_check = $koneksi->prepare("SELECT id_user FROM user WHERE aes_decrypt(id_user, 'nur') = ?");
            $stmt_check->bind_param("s", $nik);
            $stmt_check->execute();
            $res_check = $stmt_check->get_result();
            if ($res_check && $res_check->num_rows > 0) {
                $error_msg = "User dengan NIK $nik sudah memiliki akun!";
            }
            $stmt_check->close();

            if (empty($error_msg)) {
                // Insert into user
                $stmt_insert = $koneksi->prepare("INSERT INTO user (id_user, password) VALUES (aes_encrypt(?, 'nur'), aes_encrypt(?, 'windi'))");
                $stmt_insert->bind_param("ss", $nik, $password);
                if ($stmt_insert->execute()) {
                    $success_msg = "User baru berhasil ditambahkan.";
                    
                    // Initialize or copy custom hak_akses permissions
                    if (!empty($copy_from)) {
                        $stmt_copy_hak = $koneksi->prepare("INSERT INTO hak_akses (nik, dashboard, manajemen, dokter, pegawai, kasir, keuangan) SELECT ?, dashboard, manajemen, dokter, pegawai, kasir, keuangan FROM hak_akses WHERE nik = ?");
                        if ($stmt_copy_hak) {
                            $stmt_copy_hak->bind_param("ss", $nik, $copy_from);
                            $stmt_copy_hak->execute();
                            // Fallback if source user has no record in hak_akses yet
                            if ($stmt_copy_hak->affected_rows === 0) {
                                $koneksi->query("INSERT INTO hak_akses (nik, dashboard, manajemen, dokter, pegawai, kasir, keuangan) VALUES ('$nik', '1', '0', '0', '0', '0', '0')");
                            }
                            $stmt_copy_hak->close();
                        }
                    } else {
                        // default permissions
                        $koneksi->query("INSERT INTO hak_akses (nik, dashboard, manajemen, dokter, pegawai, kasir, keuangan) VALUES ('$nik', '1', '0', '0', '0', '0', '0')");
                    }

                    // Copy SIMKES Khanza (1,200+) permissions if template user is selected
                    if (!empty($copy_from)) {
                        // Get all columns except id_user and password
                        $res_cols = $koneksi->query("DESCRIBE user");
                        $cols = [];
                        while ($col_row = $res_cols->fetch_assoc()) {
                            $c = $col_row['Field'];
                            if ($c !== 'id_user' && $c !== 'password') {
                                $cols[] = $c;
                            }
                        }
                        
                        // Select source permissions
                        $stmt_src = $koneksi->prepare("SELECT * FROM user WHERE aes_decrypt(id_user, 'nur') = ?");
                        $stmt_src->bind_param("s", $copy_from);
                        $stmt_src->execute();
                        $src_row = $stmt_src->get_result()->fetch_assoc();
                        $stmt_src->close();

                        if ($src_row && !empty($cols)) {
                            $updates = [];
                            $params = [];
                            $types = "";
                            foreach ($cols as $col) {
                                $updates[] = "`$col` = ?";
                                $params[] = $src_row[$col];
                                $types .= "s";
                            }
                            
                            $q_update = "UPDATE user SET " . implode(", ", $updates) . " WHERE aes_decrypt(id_user, 'nur') = ?";
                            $params[] = $nik;
                            $types .= "s";

                            $stmt_up = $koneksi->prepare($q_update);
                            if ($stmt_up) {
                                $stmt_up->bind_param($types, ...$params);
                                $stmt_up->execute();
                                $stmt_up->close();
                                $success_msg .= " Hak akses modul disalin dari $copy_from.";
                            }
                        }
                    }
                } else {
                    $error_msg = "Gagal menambahkan user baru: " . $koneksi->error;
                }
                $stmt_insert->close();
            }
        } else {
            $error_msg = "NIK dan Password harus diisi!";
        }
    } 
    
    elseif ($action === 'update_password') {
        $nik = trim($_POST['nik'] ?? '');
        $new_password = $_POST['new_password'] ?? '';

        if (!empty($nik) && !empty($new_password)) {
            $stmt_update = $koneksi->prepare("UPDATE user SET password = aes_encrypt(?, 'windi') WHERE aes_decrypt(id_user, 'nur') = ?");
            $stmt_update->bind_param("ss", $new_password, $nik);
            if ($stmt_update->execute()) {
                $success_msg = "Password user $nik berhasil diperbarui.";
            } else {
                $error_msg = "Gagal memperbarui password: " . $koneksi->error;
            }
            $stmt_update->close();
        } else {
            $error_msg = "Password baru tidak boleh kosong!";
        }
    } 
    
    elseif ($action === 'update_permissions') {
        $nik = trim($_POST['nik'] ?? '');
        $dashboard = isset($_POST['dashboard']) ? '1' : '0';
        $manajemen = isset($_POST['manajemen']) ? '1' : '0';
        $dokter    = isset($_POST['dokter'])    ? '1' : '0';
        $pegawai   = isset($_POST['pegawai'])   ? '1' : '0';
        $kasir     = isset($_POST['kasir'])     ? '1' : '0';
        $keuangan  = isset($_POST['keuangan'])  ? '1' : '0';

        if (!empty($nik)) {
            $stmt_up_hak = $koneksi->prepare("INSERT INTO hak_akses (nik, dashboard, manajemen, dokter, pegawai, kasir, keuangan) VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE dashboard = ?, manajemen = ?, dokter = ?, pegawai = ?, kasir = ?, keuangan = ?");
            if ($stmt_up_hak) {
                $stmt_up_hak->bind_param("sssssssssssss", $nik, $dashboard, $manajemen, $dokter, $pegawai, $kasir, $keuangan, $dashboard, $manajemen, $dokter, $pegawai, $kasir, $keuangan);
                if ($stmt_up_hak->execute()) {
                    $success_msg = "Hak akses menu untuk user $nik berhasil diperbarui.";
                } else {
                    $error_msg = "Gagal memperbarui hak akses menu: " . $koneksi->error;
                }
                $stmt_up_hak->close();
            } else {
                $error_msg = "Gagal menyiapkan query update hak akses: " . $koneksi->error;
            }
        } else {
            $error_msg = "NIK tidak valid!";
        }
    }
    
    elseif ($action === 'copy_permissions') {
        $nik = trim($_POST['nik'] ?? '');
        $copy_from = $_POST['copy_from'] ?? '';

        if (!empty($nik) && !empty($copy_from)) {
            // 1. Copy custom hak_akses menu permissions
            $stmt_copy_hak = $koneksi->prepare("INSERT INTO hak_akses (nik, dashboard, manajemen, dokter, pegawai, kasir, keuangan) SELECT ?, dashboard, manajemen, dokter, pegawai, kasir, keuangan FROM hak_akses WHERE nik = ? ON DUPLICATE KEY UPDATE dashboard=VALUES(dashboard), manajemen=VALUES(manajemen), dokter=VALUES(dokter), pegawai=VALUES(pegawai), kasir=VALUES(kasir), keuangan=VALUES(keuangan)");
            if ($stmt_copy_hak) {
                $stmt_copy_hak->bind_param("ss", $nik, $copy_from);
                $stmt_copy_hak->execute();
                $stmt_copy_hak->close();
            }

            // 2. Copy original SIMKES Khanza permissions
            $res_cols = $koneksi->query("DESCRIBE user");
            $cols = [];
            while ($col_row = $res_cols->fetch_assoc()) {
                $c = $col_row['Field'];
                if ($c !== 'id_user' && $c !== 'password') {
                    $cols[] = $c;
                }
            }

            $stmt_src = $koneksi->prepare("SELECT * FROM user WHERE aes_decrypt(id_user, 'nur') = ?");
            $stmt_src->bind_param("s", $copy_from);
            $stmt_src->execute();
            $src_row = $stmt_src->get_result()->fetch_assoc();
            $stmt_src->close();

            if ($src_row && !empty($cols)) {
                $updates = [];
                $params = [];
                $types = "";
                foreach ($cols as $col) {
                    $updates[] = "`$col` = ?";
                    $params[] = $src_row[$col];
                    $types .= "s";
                }
                
                $q_update = "UPDATE user SET " . implode(", ", $updates) . " WHERE aes_decrypt(id_user, 'nur') = ?";
                $params[] = $nik;
                $types .= "s";

                $stmt_up = $koneksi->prepare($q_update);
                if ($stmt_up && $stmt_up->bind_param($types, ...$params) && $stmt_up->execute()) {
                    $success_msg = "Seluruh hak akses user $nik berhasil disalin dari $copy_from.";
                } else {
                    $error_msg = "Gagal menyalin hak akses modul: " . $koneksi->error;
                }
                if ($stmt_up) $stmt_up->close();
            } else {
                $error_msg = "Data sumber hak akses tidak ditemukan.";
            }
        } else {
            $error_msg = "Pilih user asal dan tujuan!";
        }
    } 
    
    elseif ($action === 'delete') {
        $nik = trim($_POST['nik'] ?? '');
        if (!empty($nik)) {
            // Note: hak_akses record will automatically cascade delete due to foreign key
            $stmt_delete = $koneksi->prepare("DELETE FROM user WHERE aes_decrypt(id_user, 'nur') = ?");
            $stmt_delete->bind_param("s", $nik);
            if ($stmt_delete->execute()) {
                $success_msg = "User $nik berhasil dihapus.";
            } else {
                $error_msg = "Gagal menghapus user: " . $koneksi->error;
            }
            $stmt_delete->close();
        } else {
            $error_msg = "NIK tidak valid!";
        }
    }
}

// Fetch lists for rendering
$search = trim($_GET['search'] ?? '');
$page_num = isset($_GET['p']) ? (int)$_GET['p'] : 1;
if ($page_num < 1) $page_num = 1;
$limit = 10;
$offset = ($page_num - 1) * $limit;

// Total Count
$count_query = "SELECT COUNT(*) as total FROM user";
if (!empty($search)) {
    $count_query = "SELECT COUNT(*) as total FROM user WHERE aes_decrypt(id_user, 'nur') LIKE ? OR aes_decrypt(id_user, 'nur') IN (SELECT nik FROM pegawai WHERE nama LIKE ?)";
}
$stmt_count = $koneksi->prepare($count_query);
if (!empty($search)) {
    $search_param = "%$search%";
    $stmt_count->bind_param("ss", $search_param, $search_param);
}
$stmt_count->execute();
$total_rows = $stmt_count->get_result()->fetch_assoc()['total'];
$stmt_count->close();

$total_pages = ceil($total_rows / $limit);
if ($total_pages < 1) $total_pages = 1;

// Fetch users with decryption, pegawai details & hak_akses
$query = "SELECT 
            aes_decrypt(u.id_user, 'nur') as uid, 
            aes_decrypt(u.password, 'windi') as pwd,
            p.nama as nama_pegawai,
            p.jbtn as jabatan,
            h.dashboard,
            h.manajemen,
            h.dokter,
            h.pegawai,
            h.kasir,
            h.keuangan
          FROM user u
          LEFT JOIN pegawai p ON p.nik = aes_decrypt(u.id_user, 'nur')
          LEFT JOIN hak_akses h ON h.nik = aes_decrypt(u.id_user, 'nur')";
          
if (!empty($search)) {
    $query .= " WHERE aes_decrypt(u.id_user, 'nur') LIKE ? OR p.nama LIKE ?";
}
$query .= " ORDER BY p.nama ASC LIMIT ? OFFSET ?";

$stmt_list = $koneksi->prepare($query);
if (!empty($search)) {
    $stmt_list->bind_param("ssii", $search_param, $search_param, $limit, $offset);
} else {
    $stmt_list->bind_param("ii", $limit, $offset);
}
$stmt_list->execute();
$user_list = $stmt_list->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_list->close();

// Fetch all users for template select list
$template_users = [];
$res_tpl = $koneksi->query("SELECT aes_decrypt(id_user, 'nur') as uid, (SELECT nama FROM pegawai WHERE nik = aes_decrypt(id_user, 'nur') LIMIT 1) as nama FROM user ORDER BY nama ASC");
if ($res_tpl) {
    while ($row = $res_tpl->fetch_assoc()) {
        if (!empty($row['uid'])) {
            $template_users[] = $row;
        }
    }
}
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Manajemen User</h1>
        <p class="text-secondary" style="font-size: 14px;">Kelola akun akses aplikasi SIMKES Khanza Anda di sini.</p>
    </div>
    <button class="btn btn-primary" onclick="openModal('createModal')">
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
        Tambah User
    </button>
</div>

<!-- Alert Notifications -->
<?php if (!empty($success_msg)): ?>
    <div class="content-card" style="border-left: 5px solid var(--success); background: rgba(16, 185, 129, 0.08); padding: 16px; margin-bottom: 20px; display: flex; align-items: center; gap: 12px; border-radius: var(--radius-md);">
        <span style="font-size: 20px;">✅</span>
        <span style="font-size: 14px; font-weight: 600; color: #065f46;"><?= htmlspecialchars($success_msg) ?></span>
    </div>
<?php endif; ?>

<?php if (!empty($error_msg)): ?>
    <div class="content-card" style="border-left: 5px solid var(--danger); background: rgba(239, 68, 68, 0.08); padding: 16px; margin-bottom: 20px; display: flex; align-items: center; gap: 12px; border-radius: var(--radius-md);">
        <span style="font-size: 20px;">⚠️</span>
        <span style="font-size: 14px; font-weight: 600; color: #991b1b;"><?= htmlspecialchars($error_msg) ?></span>
    </div>
<?php endif; ?>

<div class="content-card">
    <div class="card-header">
        <h3 class="card-title">Daftar Akun Pengguna</h3>
        <form method="GET" style="display: flex; gap: 10px; width: 100%; max-width: 320px;">
            <input type="hidden" name="page" value="manajemen">
            <input type="text" name="search" class="form-control" placeholder="Cari NIK atau Nama..." value="<?= htmlspecialchars($search) ?>">
            <button type="submit" class="btn btn-secondary btn-sm" style="padding: 10px 14px;">Cari</button>
        </form>
    </div>

    <div class="table-responsive">
        <table class="table-custom">
            <thead>
                <tr>
                    <th>NIK / ID User</th>
                    <th>Nama Pegawai</th>
                    <th>Jabatan</th>
                    <th>Password</th>
                    <th>Hak Akses Menu</th>
                    <th style="text-align: right;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($user_list)): ?>
                    <tr>
                        <td colspan="6" style="text-align: center; color: var(--text-secondary); padding: 30px;">
                            Tidak ada data pengguna ditemukan.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($user_list as $u): ?>
                        <tr>
                            <td data-label="NIK / ID User"><strong><?= htmlspecialchars($u['uid'] ?? '') ?></strong></td>
                            <td data-label="Nama Pegawai"><?= htmlspecialchars($u['nama_pegawai'] ?? 'Bukan Pegawai / Tidak Terhubung') ?></td>
                            <td data-label="Jabatan"><?= htmlspecialchars($u['jabatan'] ?? '-') ?></td>
                            <td data-label="Password">
                                <div style="display: flex; align-items: center; gap: 8px; font-family: monospace;">
                                    <span id="pwd-<?= htmlspecialchars($u['uid'] ?? '') ?>" style="letter-spacing: 0.5px;">••••••••</span>
                                    <button class="btn-close" style="padding: 2px;" onclick="togglePwdText('<?= htmlspecialchars($u['uid'] ?? '') ?>', '<?= htmlspecialchars($u['pwd'] ?? '') ?>')">
                                        <svg id="eye-icon-<?= htmlspecialchars($u['uid'] ?? '') ?>" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                    </button>
                                </div>
                            </td>
                            <td data-label="Hak Akses Menu">
                                <div style="display: flex; flex-wrap: wrap; gap: 4px;">
                                    <span class="badge badge-primary">Dashboard</span>
                                    <?php if (($u['manajemen'] ?? '0') === '1'): ?>
                                        <span class="badge badge-success">Manajemen</span>
                                    <?php endif; ?>
                                    <?php if (($u['dokter'] ?? '0') === '1'): ?>
                                        <span class="badge badge-success">Dokter</span>
                                    <?php endif; ?>
                                    <?php if (($u['pegawai'] ?? '0') === '1'): ?>
                                        <span class="badge badge-success">Pegawai</span>
                                    <?php endif; ?>
                                    <?php if (($u['kasir'] ?? '0') === '1'): ?>
                                        <span class="badge badge-success">Kasir</span>
                                    <?php endif; ?>
                                    <?php if (($u['keuangan'] ?? '0') === '1'): ?>
                                        <span class="badge badge-success">Keuangan</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td style="text-align: right;">
                                <div style="display: inline-flex; gap: 6px;">
                                    <button class="btn btn-secondary btn-sm" onclick="openEditPwdModal('<?= htmlspecialchars($u['uid'] ?? '') ?>')">
                                        Password
                                    </button>
                                    <button class="btn btn-primary btn-sm" onclick='openEditAccessModal(<?= json_encode($u) ?>)'>
                                        Atur Akses
                                    </button>
                                    <button class="btn btn-warning btn-sm" onclick="openCopyPermModal('<?= htmlspecialchars($u['uid'] ?? '') ?>')">
                                        Copy
                                    </button>
                                    <button class="btn btn-danger btn-sm" onclick="confirmDeleteUser('<?= htmlspecialchars($u['uid'] ?? '') ?>')">
                                        Hapus
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
        <div style="display: flex; justify-content: center; gap: 8px; margin-top: 24px;">
            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                <a href="index.php?page=manajemen&search=<?= urlencode($search) ?>&p=<?= $i ?>" class="btn <?= $i === $page_num ? 'btn-primary' : 'btn-secondary' ?> btn-sm" style="min-width: 32px; justify-content: center;">
                    <?= $i ?>
                </a>
            <?php endfor; ?>
        </div>
    <?php endif; ?>
</div>

<!-- ================= MODALS ================= -->

<!-- Create User Modal -->
<div id="createModal" class="modal-overlay" onclick="closeModal('createModal')">
    <div class="modal-content" onclick="event.stopPropagation()">
        <form method="POST">
            <input type="hidden" name="action" value="create">
            <div class="modal-header">
                <h3>Tambah User Baru</h3>
                <button type="button" class="btn-close" onclick="closeModal('createModal')">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                </button>
            </div>
            <div class="modal-body">
                <div class="form-group autocomplete-container">
                    <label class="form-label" for="search_pegawai">Cari Pegawai (Nama / NIK)</label>
                    <input type="text" id="search_pegawai" class="form-control" placeholder="Ketik nama atau NIK pegawai..." autocomplete="off" oninput="suggestPegawai(this.value)">
                    <input type="hidden" id="selected_nik" name="nik" required>
                    <div id="pegawai-suggestions" class="autocomplete-suggestions" style="display: none;"></div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="password">Password</label>
                    <input type="password" id="password" name="password" class="form-control" placeholder="Masukkan password untuk login..." required autocomplete="off">
                </div>

                <div class="form-group">
                    <label class="form-label" for="copy_from">Salin Hak Akses dari User (Opsional)</label>
                    <select id="copy_from" name="copy_from" class="form-control">
                        <option value="">-- Gunakan Default (Tanpa Akses) --</option>
                        <?php foreach ($template_users as $tu): ?>
                            <option value="<?= htmlspecialchars($tu['uid'] ?? '') ?>"><?= htmlspecialchars($tu['nama'] ?? '') ?> (<?= htmlspecialchars($tu['uid'] ?? '') ?>)</option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-secondary" style="font-size: 11px; margin-top: 4px;">Sangat disarankan menyalin hak akses dari user yang sudah ada agar tidak perlu mengkonfigurasi hak akses satu per satu.</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('createModal')">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan User</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Password Modal -->
<div id="editPwdModal" class="modal-overlay" onclick="closeModal('editPwdModal')">
    <div class="modal-content" onclick="event.stopPropagation()">
        <form method="POST">
            <input type="hidden" name="action" value="update_password">
            <input type="hidden" id="edit_nik" name="nik">
            <div class="modal-header">
                <h3>Ubah Password User</h3>
                <button type="button" class="btn-close" onclick="closeModal('editPwdModal')">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                </button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">ID User / NIK</label>
                    <input type="text" id="display_nik" class="form-control" disabled>
                </div>

                <div class="form-group">
                    <label class="form-label" for="new_password">Password Baru</label>
                    <input type="password" id="new_password" name="new_password" class="form-control" placeholder="Masukkan password baru..." required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('editPwdModal')">Batal</button>
                <button type="submit" class="btn btn-primary">Ubah Password</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Custom Menu Access Modal -->
<div id="editAccessModal" class="modal-overlay" onclick="closeModal('editAccessModal')">
    <div class="modal-content" onclick="event.stopPropagation()">
        <form method="POST">
            <input type="hidden" name="action" value="update_permissions">
            <input type="hidden" id="access_nik" name="nik">
            <div class="modal-header">
                <h3>Atur Hak Akses Menu</h3>
                <button type="button" class="btn-close" onclick="closeModal('editAccessModal')">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                </button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Nama Pegawai</label>
                    <input type="text" id="access_display_nama" class="form-control" disabled>
                </div>

                <div class="form-group">
                    <label class="form-label" style="margin-bottom: 12px;">Daftar Menu Yang Dapat Dibuka:</label>
                    
                    <div style="display: flex; flex-direction: column; gap: 12px; padding: 4px;">
                        <label style="display: flex; align-items: center; gap: 10px; font-size: 14px; cursor: not-allowed; color: var(--text-secondary);">
                            <input type="checkbox" name="dashboard" value="1" checked disabled style="width:18px; height:18px;">
                            <strong>Dashboard</strong> (Selalu aktif untuk semua user)
                        </label>
                        
                        <label style="display: flex; align-items: center; gap: 10px; font-size: 14px; cursor: pointer;">
                            <input type="checkbox" id="access_chk_manajemen" name="manajemen" value="1" style="width:18px; height:18px;">
                            <strong>Manajemen</strong> (Mengelola akun user & hak akses)
                        </label>
                        
                        <label style="display: flex; align-items: center; gap: 10px; font-size: 14px; cursor: pointer;">
                            <input type="checkbox" id="access_chk_dokter" name="dokter" value="1" style="width:18px; height:18px;">
                            <strong>Dokter</strong> (Mengelola data dokter rumah sakit)
                        </label>
                        
                        <label style="display: flex; align-items: center; gap: 10px; font-size: 14px; cursor: pointer;">
                            <input type="checkbox" id="access_chk_pegawai" name="pegawai" value="1" style="width:18px; height:18px;">
                            <strong>Pegawai</strong> (Mengelola data pegawai rumah sakit)
                        </label>

                        <label style="display: flex; align-items: center; gap: 10px; font-size: 14px; cursor: pointer;">
                            <input type="checkbox" id="access_chk_kasir" name="kasir" value="1" style="width:18px; height:18px;">
                            <strong>Kasir</strong> (Akses halaman payment point kasir)
                        </label>

                        <label style="display: flex; align-items: center; gap: 10px; font-size: 14px; cursor: pointer;">
                            <input type="checkbox" id="access_chk_keuangan" name="keuangan" value="1" style="width:18px; height:18px;">
                            <strong>Keuangan</strong> (Akses laporan PPN obat & penjualan bebas)
                        </label>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('editAccessModal')">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan Hak Akses</button>
            </div>
        </form>
    </div>
</div>

<!-- Copy Permissions Modal -->
<div id="copyPermModal" class="modal-overlay" onclick="closeModal('copyPermModal')">
    <div class="modal-content" onclick="event.stopPropagation()">
        <form method="POST">
            <input type="hidden" name="action" value="copy_permissions">
            <input type="hidden" id="copy_target_nik" name="nik">
            <div class="modal-header">
                <h3>Salin Hak Akses Pengguna</h3>
                <button type="button" class="btn-close" onclick="closeModal('copyPermModal')">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                </button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">User Penerima (Target)</label>
                    <input type="text" id="display_target_nik" class="form-control" disabled>
                </div>

                <div class="form-group">
                    <label class="form-label" for="copy_source">Salin Seluruh Hak Akses Dari</label>
                    <select id="copy_source" name="copy_from" class="form-control" required>
                        <option value="">-- Pilih User Sumber --</option>
                        <?php foreach ($template_users as $tu): ?>
                            <option value="<?= htmlspecialchars($tu['uid'] ?? '') ?>"><?= htmlspecialchars($tu['nama'] ?? '') ?> (<?= htmlspecialchars($tu['uid'] ?? '') ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('copyPermModal')">Batal</button>
                <button type="submit" class="btn btn-primary">Salin Hak Akses</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete User Modal Form (Hidden) -->
<form id="deleteForm" method="POST" style="display: none;">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" id="delete_nik" name="nik">
</form>

<!-- AJAX Pegawai Autocomplete endpoint & frontend scripts -->
<script>
function openModal(id) {
    document.getElementById(id).classList.add('active');
}

function closeModal(id) {
    document.getElementById(id).classList.remove('active');
}

function openEditPwdModal(nik) {
    document.getElementById('edit_nik').value = nik;
    document.getElementById('display_nik').value = nik;
    document.getElementById('new_password').value = '';
    openModal('editPwdModal');
}

function openEditAccessModal(u) {
    document.getElementById('access_nik').value = u.uid;
    document.getElementById('access_display_nama').value = (u.nama_pegawai || 'Bukan Pegawai') + ' (' + u.uid + ')';
    
    // Set checkbox states based on user hak_akses values
    document.getElementById('access_chk_manajemen').checked = (u.manajemen === '1');
    document.getElementById('access_chk_dokter').checked    = (u.dokter    === '1');
    document.getElementById('access_chk_pegawai').checked   = (u.pegawai   === '1');
    document.getElementById('access_chk_kasir').checked     = (u.kasir     === '1');
    document.getElementById('access_chk_keuangan').checked  = (u.keuangan  === '1');
    
    openModal('editAccessModal');
}

function openCopyPermModal(nik) {
    document.getElementById('copy_target_nik').value = nik;
    document.getElementById('display_target_nik').value = nik;
    document.getElementById('copy_source').value = '';
    openModal('copyPermModal');
}

function confirmDeleteUser(nik) {
    if (confirm("Apakah Anda yakin ingin menghapus user dengan NIK " + nik + "? Akun tidak akan bisa digunakan lagi untuk masuk aplikasi.")) {
        document.getElementById('delete_nik').value = nik;
        document.getElementById('deleteForm').submit();
    }
}

function togglePwdText(nik, actualPwd) {
    const span = document.getElementById('pwd-' + nik);
    const icon = document.getElementById('eye-icon-' + nik);
    
    if (span.innerText === '••••••••') {
        span.innerText = actualPwd;
        icon.innerHTML = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line>';
    } else {
        span.innerText = '••••••••';
        icon.innerHTML = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';
    }
}

// Simple dynamic search for pegawai in modal
let suggestTimeout;
function suggestPegawai(val) {
    clearTimeout(suggestTimeout);
    const box = document.getElementById('pegawai-suggestions');
    if (val.length < 2) {
        box.style.display = 'none';
        return;
    }
    
    suggestTimeout = setTimeout(() => {
        fetch('pages/manajemen.php?ajax_search_pegawai=' + encodeURIComponent(val))
            .then(res => res.json())
            .then(data => {
                box.innerHTML = '';
                if (data.length > 0) {
                    data.forEach(p => {
                        const item = document.createElement('div');
                        item.className = 'suggestion-item';
                        item.innerHTML = '<span>' + p.nama + '</span><span style="color:var(--text-secondary);">' + p.nik + '</span>';
                        item.onclick = function() {
                            document.getElementById('search_pegawai').value = p.nama + ' (' + p.nik + ')';
                            document.getElementById('selected_nik').value = p.nik;
                            box.style.display = 'none';
                        };
                        box.appendChild(item);
                    });
                    box.style.display = 'block';
                } else {
                    box.innerHTML = '<div style="padding: 10px 14px; color: var(--text-secondary); font-size:13px;">Tidak ada pegawai ditemukan</div>';
                    box.style.display = 'block';
                }
            });
    }, 250);
}
</script>

<?php
// Simple AJAX endpoint inside the same page
if (isset($_GET['ajax_search_pegawai'])) {
    ob_clean();
    header('Content-Type: application/json');
    $val = trim($_GET['ajax_search_pegawai']);
    $list_res = [];
    if (strlen($val) >= 2) {
        // Query pegawai who don't already have an entry in user
        $stmt_s = $koneksi->prepare("SELECT nik, nama FROM pegawai WHERE (nama LIKE ? OR nik LIKE ?) AND nik NOT IN (SELECT aes_decrypt(id_user, 'nur') FROM user WHERE id_user IS NOT NULL) ORDER BY nama ASC LIMIT 10");
        $param_s = "%$val%";
        $stmt_s->bind_param("ss", $param_s, $param_s);
        $stmt_s->execute();
        $res_s = $stmt_s->get_result();
        while ($r = $res_s->fetch_assoc()) {
            $list_res[] = $r;
        }
        $stmt_s->close();
    }
    echo json_encode($list_res);
    exit;
}
?>
