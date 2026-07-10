<?php
defined('host') or die('Akses langsung tidak diizinkan.');

// Handle Actions (POST requests)
$success_msg = '';
$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'create' || $action === 'update') {
        $kd_dokter = trim($_POST['kd_dokter'] ?? '');
        $nm_dokter = trim($_POST['nm_dokter'] ?? '');
        $jk = $_POST['jk'] ?? 'L';
        $tmp_lahir = trim($_POST['tmp_lahir'] ?? '');
        $tgl_lahir = !empty($_POST['tgl_lahir']) ? $_POST['tgl_lahir'] : null;
        $gol_drh = $_POST['gol_drh'] ?? '-';
        $agama = trim($_POST['agama'] ?? '');
        $almt_tgl = trim($_POST['almt_tgl'] ?? '');
        $no_telp = trim($_POST['no_telp'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $stts_nikah = $_POST['stts_nikah'] ?? 'BELUM MENIKAH';
        $kd_sps = $_POST['kd_sps'] ?? '';
        $alumni = trim($_POST['alumni'] ?? '');
        $no_ijn_praktek = trim($_POST['no_ijn_praktek'] ?? '');
        $status = $_POST['status'] ?? '1';

        if (empty($kd_dokter) || empty($nm_dokter)) {
            $error_msg = "Kode Dokter dan Nama Dokter tidak boleh kosong!";
        } else {
            if ($action === 'create') {
                // Check duplication
                $stmt_check = $koneksi->prepare("SELECT kd_dokter FROM dokter WHERE kd_dokter = ?");
                $stmt_check->bind_param("s", $kd_dokter);
                $stmt_check->execute();
                if ($stmt_check->get_result()->num_rows > 0) {
                    $error_msg = "Kode Dokter $kd_dokter sudah terdaftar!";
                }
                $stmt_check->close();

                if (empty($error_msg)) {
                    $stmt_ins = $koneksi->prepare("INSERT INTO dokter (kd_dokter, nm_dokter, jk, tmp_lahir, tgl_lahir, gol_drh, agama, almt_tgl, no_telp, email, stts_nikah, kd_sps, alumni, no_ijn_praktek, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt_ins->bind_param("sssssssssssssss", $kd_dokter, $nm_dokter, $jk, $tmp_lahir, $tgl_lahir, $gol_drh, $agama, $almt_tgl, $no_telp, $email, $stts_nikah, $kd_sps, $alumni, $no_ijn_praktek, $status);
                    if ($stmt_ins->execute()) {
                        $success_msg = "Dokter baru berhasil ditambahkan.";
                    } else {
                        $error_msg = "Gagal menambahkan dokter: " . $koneksi->error;
                    }
                    $stmt_ins->close();
                }
            } else { // update
                $stmt_up = $koneksi->prepare("UPDATE dokter SET nm_dokter = ?, jk = ?, tmp_lahir = ?, tgl_lahir = ?, gol_drh = ?, agama = ?, almt_tgl = ?, no_telp = ?, email = ?, stts_nikah = ?, kd_sps = ?, alumni = ?, no_ijn_praktek = ?, status = ? WHERE kd_dokter = ?");
                $stmt_up->bind_param("sssssssssssssss", $nm_dokter, $jk, $tmp_lahir, $tgl_lahir, $gol_drh, $agama, $almt_tgl, $no_telp, $email, $stts_nikah, $kd_sps, $alumni, $no_ijn_praktek, $status, $kd_dokter);
                if ($stmt_up->execute()) {
                    $success_msg = "Data dokter $kd_dokter berhasil diperbarui.";
                } else {
                    $error_msg = "Gagal memperbarui data dokter: " . $koneksi->error;
                }
                $stmt_up->close();
            }
        }
    } 
    
    elseif ($action === 'delete') {
        $kd_dokter = trim($_POST['kd_dokter'] ?? '');
        if (!empty($kd_dokter)) {
            $stmt_del = $koneksi->prepare("DELETE FROM dokter WHERE kd_dokter = ?");
            $stmt_del->bind_param("s", $kd_dokter);
            if ($stmt_del->execute()) {
                $success_msg = "Data dokter $kd_dokter berhasil dihapus.";
            } else {
                $error_msg = "Gagal menghapus dokter: " . $koneksi->error;
            }
            $stmt_del->close();
        } else {
            $error_msg = "Kode Dokter tidak valid!";
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
$count_query = "SELECT COUNT(*) as total FROM dokter";
if (!empty($search)) {
    $count_query = "SELECT COUNT(*) as total FROM dokter WHERE kd_dokter LIKE ? OR nm_dokter LIKE ?";
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

// Fetch doctors
$query = "SELECT d.*, s.nm_sps FROM dokter d LEFT JOIN spesialis s ON s.kd_sps = d.kd_sps";
if (!empty($search)) {
    $query .= " WHERE d.kd_dokter LIKE ? OR d.nm_dokter LIKE ?";
}
$query .= " ORDER BY d.nm_dokter ASC LIMIT ? OFFSET ?";

$stmt_list = $koneksi->prepare($query);
if (!empty($search)) {
    $stmt_list->bind_param("ssii", $search_param, $search_param, $limit, $offset);
} else {
    $stmt_list->bind_param("ii", $limit, $offset);
}
$stmt_list->execute();
$dokter_list = $stmt_list->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_list->close();

// Fetch specializations for dropdown select
$specializations = [];
$res_sps = $koneksi->query("SELECT kd_sps, nm_sps FROM spesialis ORDER BY nm_sps ASC");
if ($res_sps) {
    while ($row = $res_sps->fetch_assoc()) {
        $specializations[] = $row;
    }
}
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Data Dokter</h1>
        <p class="text-secondary" style="font-size: 14px;">Kelola informasi master dokter rumah sakit.</p>
    </div>
    <button class="btn btn-primary" onclick="openAddModal()">
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
        Tambah Dokter
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
        <h3 class="card-title">Master Dokter</h3>
        <form method="GET" style="display: flex; gap: 10px; width: 100%; max-width: 320px;">
            <input type="hidden" name="page" value="manajemen">
            <input type="hidden" name="sub" value="dokter">
            <input type="text" name="search" class="form-control" placeholder="Cari Kode atau Nama..." value="<?= htmlspecialchars($search) ?>">
            <button type="submit" class="btn btn-secondary btn-sm" style="padding: 10px 14px;">Cari</button>
        </form>
    </div>

    <div class="table-responsive">
        <table class="table-custom">
            <thead>
                <tr>
                    <th>Kode</th>
                    <th>Nama Dokter</th>
                    <th>Spesialisasi</th>
                    <th>L/P</th>
                    <th>No. Telp</th>
                    <th>No. Ijin Praktek</th>
                    <th>Status</th>
                    <th style="text-align: right;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($dokter_list)): ?>
                    <tr>
                        <td colspan="8" style="text-align: center; color: var(--text-secondary); padding: 30px;">
                            Tidak ada data dokter ditemukan.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($dokter_list as $d): ?>
                        <tr>
                            <td data-label="Kode"><strong><?= htmlspecialchars($d['kd_dokter']) ?></strong></td>
                            <td data-label="Nama Dokter"><?= htmlspecialchars($d['nm_dokter']) ?></td>
                            <td data-label="Spesialisasi"><?= htmlspecialchars($d['nm_sps'] ?? 'Tanpa Spesialisasi') ?></td>
                            <td data-label="L/P"><?= htmlspecialchars($d['jk']) ?></td>
                            <td data-label="No. Telp"><?= htmlspecialchars($d['no_telp'] ?: '-') ?></td>
                            <td data-label="No. Ijin Praktek" style="font-size:12px; max-width:200px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"><?= htmlspecialchars($d['no_ijn_praktek'] ?: '-') ?></td>
                            <td data-label="Status">
                                <span class="badge <?= $d['status'] === '1' ? 'badge-success' : 'badge-danger' ?>">
                                    <?= $d['status'] === '1' ? 'Aktif' : 'Tidak Aktif' ?>
                                </span>
                            </td>
                            <td style="text-align: right;">
                                <div style="display: inline-flex; gap: 6px;">
                                    <button class="btn btn-secondary btn-sm" onclick='openEditModal(<?= json_encode($d) ?>)'>
                                        Edit
                                    </button>
                                    <button class="btn btn-danger btn-sm" onclick="confirmDeleteDokter('<?= htmlspecialchars($d['kd_dokter']) ?>')">
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
                <a href="index.php?page=manajemen&sub=dokter&search=<?= urlencode($search) ?>&p=<?= $i ?>" class="btn <?= $i === $page_num ? 'btn-primary' : 'btn-secondary' ?> btn-sm" style="min-width: 32px; justify-content: center;">
                    <?= $i ?>
                </a>
            <?php endfor; ?>
        </div>
    <?php endif; ?>
</div>

<!-- ================= ADD / EDIT MODAL ================= -->
<div id="dokterModal" class="modal-overlay" onclick="closeModal('dokterModal')">
    <div class="modal-content modal-lg" onclick="event.stopPropagation()">
        <form method="POST">
            <input type="hidden" name="action" id="modal_action" value="create">
            <div class="modal-header">
                <h3 id="modal_title">Tambah Dokter</h3>
                <button type="button" class="btn-close" onclick="closeModal('dokterModal')">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                </button>
            </div>
            <div class="modal-body" style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                <div class="form-group" style="grid-column: span 2; display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div>
                        <label class="form-label" for="kd_dokter">Kode Dokter *</label>
                        <input type="text" id="kd_dokter" name="kd_dokter" class="form-control" placeholder="D000000X" required>
                    </div>
                    <div>
                        <label class="form-label" for="nm_dokter">Nama Dokter *</label>
                        <input type="text" id="nm_dokter" name="nm_dokter" class="form-control" placeholder="Nama Lengkap dengan Gelar" required>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="jk">Jenis Kelamin</label>
                    <select id="jk" name="jk" class="form-control">
                        <option value="L">Laki-Laki (L)</option>
                        <option value="P">Perempuan (P)</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label" for="kd_sps">Spesialisasi</label>
                    <select id="kd_sps" name="kd_sps" class="form-control" required>
                        <option value="">-- Pilih Spesialisasi --</option>
                        <?php foreach ($specializations as $s): ?>
                            <option value="<?= htmlspecialchars($s['kd_sps']) ?>"><?= htmlspecialchars($s['nm_sps']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                    <div>
                        <label class="form-label" for="tmp_lahir">Tempat Lahir</label>
                        <input type="text" id="tmp_lahir" name="tmp_lahir" class="form-control" placeholder="Kota Lahir">
                    </div>
                    <div>
                        <label class="form-label" for="tgl_lahir">Tanggal Lahir</label>
                        <input type="date" id="tgl_lahir" name="tgl_lahir" class="form-control">
                    </div>
                </div>

                <div class="form-group" style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                    <div>
                        <label class="form-label" for="gol_drh">Gol. Darah</label>
                        <select id="gol_drh" name="gol_drh" class="form-control">
                            <option value="-">-</option>
                            <option value="A">A</option>
                            <option value="B">B</option>
                            <option value="O">O</option>
                            <option value="AB">AB</option>
                        </select>
                    </div>
                    <div>
                        <label class="form-label" for="agama">Agama</label>
                        <input type="text" id="agama" name="agama" class="form-control" placeholder="Islam / Kristen / dsb.">
                    </div>
                </div>

                <div class="form-group" style="grid-column: span 2;">
                    <label class="form-label" for="almt_tgl">Alamat Tinggal</label>
                    <input type="text" id="almt_tgl" name="almt_tgl" class="form-control" placeholder="Alamat jalan, nomor, RT/RW, kota">
                </div>

                <div class="form-group">
                    <label class="form-label" for="no_telp">No. Telepon / HP</label>
                    <input type="text" id="no_telp" name="no_telp" class="form-control" placeholder="08XXXXXXXXXX">
                </div>

                <div class="form-group">
                    <label class="form-label" for="email">Email</label>
                    <input type="email" id="email" name="email" class="form-control" placeholder="dokter@rsud.go.id">
                </div>

                <div class="form-group">
                    <label class="form-label" for="stts_nikah">Status Pernikahan</label>
                    <select id="stts_nikah" name="stts_nikah" class="form-control">
                        <option value="BELUM MENIKAH">BELUM MENIKAH</option>
                        <option value="MENIKAH">MENIKAH</option>
                        <option value="JANDA">JANDA</option>
                        <option value="DUDHA">DUDHA</option>
                        <option value="JOMBLO">JOMBLO</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label" for="status">Status Aktif</label>
                    <select id="status" name="status" class="form-control">
                        <option value="1">Aktif</option>
                        <option value="0">Tidak Aktif</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label" for="alumni">Alumni Universitas</label>
                    <input type="text" id="alumni" name="alumni" class="form-control" placeholder="Nama Universitas lulusan">
                </div>

                <div class="form-group">
                    <label class="form-label" for="no_ijn_praktek">No. Ijin Praktek</label>
                    <input type="text" id="no_ijn_praktek" name="no_ijn_praktek" class="form-control" placeholder="Nomor Ijin Praktek Resmi">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('dokterModal')">Batal</button>
                <button type="submit" class="btn btn-primary" id="modal_submit_btn">Simpan</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete User Modal Form (Hidden) -->
<form id="deleteDokterForm" method="POST" style="display: none;">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" id="delete_kd_dokter" name="kd_dokter">
</form>

<script>
function openModal(id) {
    document.getElementById(id).classList.add('active');
}

function closeModal(id) {
    document.getElementById(id).classList.remove('active');
}

function openAddModal() {
    document.getElementById('modal_action').value = 'create';
    document.getElementById('modal_title').innerText = 'Tambah Dokter Baru';
    document.getElementById('modal_submit_btn').innerText = 'Simpan';
    
    // Clear and enable form fields
    document.getElementById('kd_dokter').value = '';
    document.getElementById('kd_dokter').disabled = false;
    document.getElementById('nm_dokter').value = '';
    document.getElementById('jk').value = 'L';
    document.getElementById('kd_sps').value = '';
    document.getElementById('tmp_lahir').value = '';
    document.getElementById('tgl_lahir').value = '';
    document.getElementById('gol_drh').value = '-';
    document.getElementById('agama').value = '';
    document.getElementById('almt_tgl').value = '';
    document.getElementById('no_telp').value = '';
    document.getElementById('email').value = '';
    document.getElementById('stts_nikah').value = 'BELUM MENIKAH';
    document.getElementById('status').value = '1';
    document.getElementById('alumni').value = '';
    document.getElementById('no_ijn_praktek').value = '';

    openModal('dokterModal');
}

function openEditModal(d) {
    document.getElementById('modal_action').value = 'update';
    document.getElementById('modal_title').innerText = 'Edit Data Dokter';
    document.getElementById('modal_submit_btn').innerText = 'Simpan Perubahan';
    
    // Populate form fields
    const kd_input = document.getElementById('kd_dokter');
    kd_input.value = d.kd_dokter;
    
    // Create a hidden input for kd_dokter if we submit the form so it is still sent back despite disabled field
    let hidden_kd = document.getElementById('hidden_kd_dokter');
    if (!hidden_kd) {
        hidden_kd = document.createElement('input');
        hidden_kd.type = 'hidden';
        hidden_kd.id = 'hidden_kd_dokter';
        hidden_kd.name = 'kd_dokter';
        kd_input.parentNode.appendChild(hidden_kd);
    }
    hidden_kd.value = d.kd_dokter;
    kd_input.disabled = true;

    document.getElementById('nm_dokter').value = d.nm_dokter;
    document.getElementById('jk').value = d.jk || 'L';
    document.getElementById('kd_sps').value = d.kd_sps || '';
    document.getElementById('tmp_lahir').value = d.tmp_lahir || '';
    document.getElementById('tgl_lahir').value = d.tgl_lahir || '';
    document.getElementById('gol_drh').value = d.gol_drh || '-';
    document.getElementById('agama').value = d.agama || '';
    document.getElementById('almt_tgl').value = d.almt_tgl || '';
    document.getElementById('no_telp').value = d.no_telp || '';
    document.getElementById('email').value = d.email || '';
    document.getElementById('stts_nikah').value = d.stts_nikah || 'BELUM MENIKAH';
    document.getElementById('status').value = d.status || '1';
    document.getElementById('alumni').value = d.alumni || '';
    document.getElementById('no_ijn_praktek').value = d.no_ijn_praktek || '';

    openModal('dokterModal');
}

function confirmDeleteDokter(kd) {
    if (confirm("Apakah Anda yakin ingin menghapus data dokter dengan kode " + kd + "? Tindakan ini tidak dapat dibatalkan.")) {
        document.getElementById('delete_kd_dokter').value = kd;
        document.getElementById('deleteDokterForm').submit();
    }
}
</script>
