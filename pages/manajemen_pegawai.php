<?php
defined('host') or die('Akses langsung tidak diizinkan.');

// Handle AJAX Actions for Pegawai Documents (Kontrak/SIP)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    ob_clean();
    header('Content-Type: application/json');
    $ajax_action = $_POST['ajax_action'];
    
    if ($ajax_action === 'fetch_docs') {
        $nik = $_POST['nik'] ?? '';
        $docs = [];
        $stmt_docs = $koneksi->prepare("SELECT *, DATEDIFF(tanggal_habis, CURDATE()) AS sisa_hari FROM kontrak_pegawai WHERE nik = ? ORDER BY tanggal_habis ASC");
        if ($stmt_docs) {
            $stmt_docs->bind_param("s", $nik);
            $stmt_docs->execute();
            $docs = $stmt_docs->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt_docs->close();
        }
        echo json_encode(['success' => true, 'docs' => $docs]);
        exit;
    }
    
    if ($ajax_action === 'save_doc') {
        $nik = $_POST['nik'] ?? '';
        $tipe = $_POST['tipe'] ?? '';
        $nomor_dokumen = $_POST['nomor_dokumen'] ?? '';
        $tanggal_mulai = !empty($_POST['tanggal_mulai']) ? $_POST['tanggal_mulai'] : null;
        $tanggal_habis = !empty($_POST['tanggal_habis']) ? $_POST['tanggal_habis'] : null;
        $keterangan = $_POST['keterangan'] ?? '';
        
        if (empty($nik) || empty($tipe)) {
            echo json_encode(['success' => false, 'message' => 'NIK dan Tipe Dokumen wajib diisi!']);
            exit;
        }
        
        // Use composite PK (nik, tipe) insert with ON DUPLICATE KEY UPDATE
        $stmt_save = $koneksi->prepare("
            INSERT INTO kontrak_pegawai (nik, tipe, nomor_dokumen, tanggal_mulai, tanggal_habis, keterangan) 
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
                nomor_dokumen = VALUES(nomor_dokumen), 
                tanggal_mulai = VALUES(tanggal_mulai), 
                tanggal_habis = VALUES(tanggal_habis), 
                keterangan = VALUES(keterangan)
        ");
        
        if ($stmt_save) {
            $stmt_save->bind_param("ssssss", $nik, $tipe, $nomor_dokumen, $tanggal_mulai, $tanggal_habis, $keterangan);
            if ($stmt_save->execute()) {
                echo json_encode(['success' => true, 'message' => 'Dokumen berhasil disimpan!']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Gagal menyimpan dokumen: ' . $koneksi->error]);
            }
            $stmt_save->close();
        } else {
            echo json_encode(['success' => false, 'message' => 'Gagal mempersiapkan query penyimpanan.']);
        }
        exit;
    }
    
    if ($ajax_action === 'delete_doc') {
        $nik = $_POST['nik'] ?? '';
        $tipe = $_POST['tipe'] ?? '';
        
        if (empty($nik) || empty($tipe)) {
            echo json_encode(['success' => false, 'message' => 'NIK dan Tipe wajib ditentukan!']);
            exit;
        }

        $stmt_del = $koneksi->prepare("DELETE FROM kontrak_pegawai WHERE nik = ? AND tipe = ?");
        if ($stmt_del) {
            $stmt_del->bind_param("ss", $nik, $tipe);
            if ($stmt_del->execute()) {
                echo json_encode(['success' => true, 'message' => 'Dokumen berhasil dihapus!']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Gagal menghapus dokumen: ' . $koneksi->error]);
            }
            $stmt_del->close();
        } else {
            echo json_encode(['success' => false, 'message' => 'Gagal mempersiapkan query hapus.']);
        }
        exit;
    }
    
    if ($ajax_action === 'reset_face') {
        $nik = $_POST['nik'] ?? '';
        if (empty($nik)) {
            echo json_encode(['success' => false, 'message' => 'NIK wajib ditentukan!']);
            exit;
        }

        $stmt_reset = $koneksi->prepare("DELETE FROM face_vector WHERE nik = ?");
        if ($stmt_reset) {
            $stmt_reset->bind_param("s", $nik);
            if ($stmt_reset->execute()) {
                echo json_encode(['success' => true, 'message' => 'Data verifikasi wajah berhasil di-reset!']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Gagal me-reset data wajah: ' . $koneksi->error]);
            }
            $stmt_reset->close();
        } else {
            echo json_encode(['success' => false, 'message' => 'Gagal mempersiapkan query reset.']);
        }
        exit;
    }
}

// Handle Actions (POST requests)
$success_msg = '';
$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'create' || $action === 'update') {
        $nik = trim($_POST['nik'] ?? '');
        $nama = trim($_POST['nama'] ?? '');
        $jk = $_POST['jk'] ?? 'Pria';
        $jbtn = trim($_POST['jbtn'] ?? '');
        $departemen = $_POST['departemen'] ?? '-';
        $stts_aktif = $_POST['stts_aktif'] ?? 'AKTIF';
        $pendidikan = trim($_POST['pendidikan'] ?? '');
        $tmp_lahir = trim($_POST['tmp_lahir'] ?? '');
        $tgl_lahir = !empty($_POST['tgl_lahir']) ? $_POST['tgl_lahir'] : null;
        $alamat = trim($_POST['alamat'] ?? '');
        $no_ktp = trim($_POST['no_ktp'] ?? '');
        $mulai_kerja = !empty($_POST['mulai_kerja']) ? $_POST['mulai_kerja'] : '1900-01-01';

        if (empty($nik) || empty($nama)) {
            $error_msg = "NIK dan Nama Pegawai tidak boleh kosong!";
        } else {
            if ($action === 'create') {
                // Check if NIK already exists
                $stmt_check = $koneksi->prepare("SELECT nik FROM pegawai WHERE nik = ?");
                $stmt_check->bind_param("s", $nik);
                $stmt_check->execute();
                if ($stmt_check->get_result()->num_rows > 0) {
                    $error_msg = "Pegawai dengan NIK $nik sudah terdaftar!";
                }
                $stmt_check->close();

                if (empty($error_msg)) {
                    // Set sensible default values for Khanza schema columns
                    $jnj_jabatan = '-';
                    $kode_kelompok = '-';
                    $kode_resiko = '-';
                    $kode_emergency = '-';
                    $bidang = '-';
                    $stts_wp = '-';
                    $stts_kerja = '-';
                    $npwp = '-';
                    $gapok = 0.0;
                    $kota = '-';
                    $ms_kerja = '<1';
                    $indexins = '-';
                    $bpd = 'T';
                    $rekening = '-';
                    $wajibmasuk = 0;
                    $pengurang = 0.0;
                    $indek = 0;
                    $mulai_kontrak = '1900-01-01';
                    $cuti_diambil = 0;
                    $dankes = 0.0;
                    $photo = 'pages/pegawai/photo/';

                    $q = "INSERT INTO pegawai (
                            nik, nama, jk, jbtn, jnj_jabatan, kode_kelompok, kode_resiko, kode_emergency, 
                            departemen, bidang, stts_wp, stts_kerja, npwp, pendidikan, gapok, tmp_lahir, 
                            tgl_lahir, alamat, kota, mulai_kerja, ms_kerja, indexins, bpd, rekening, 
                            stts_aktif, wajibmasuk, pengurang, indek, mulai_kontrak, cuti_diambil, dankes, 
                            photo, no_ktp
                          ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                    
                    $stmt_ins = $koneksi->prepare($q);
                    if ($stmt_ins) {
                        $stmt_ins->bind_param(
                            "ssssssssssssssdsssssssssiiddiiiss",
                            $nik, $nama, $jk, $jbtn, $jnj_jabatan, $kode_kelompok, $kode_resiko, $kode_emergency,
                            $departemen, $bidang, $stts_wp, $stts_kerja, $npwp, $pendidikan, $gapok, $tmp_lahir,
                            $tgl_lahir, $alamat, $kota, $mulai_kerja, $ms_kerja, $indexins, $bpd, $rekening,
                            $stts_aktif, $wajibmasuk, $pengurang, $indek, $mulai_kontrak, $cuti_diambil, $dankes,
                            $photo, $no_ktp
                        );
                        
                        if ($stmt_ins->execute()) {
                            $success_msg = "Pegawai baru berhasil ditambahkan.";
                        } else {
                            $error_msg = "Gagal menambahkan pegawai: " . $koneksi->error;
                        }
                        $stmt_ins->close();
                    } else {
                        $error_msg = "Gagal mempersiapkan query simpan: " . $koneksi->error;
                    }
                }
            } else { // update
                $stmt_up = $koneksi->prepare("UPDATE pegawai SET nama = ?, jk = ?, jbtn = ?, departemen = ?, stts_aktif = ?, pendidikan = ?, tmp_lahir = ?, tgl_lahir = ?, alamat = ?, no_ktp = ?, mulai_kerja = ? WHERE nik = ?");
                $stmt_up->bind_param("ssssssssssss", $nama, $jk, $jbtn, $departemen, $stts_aktif, $pendidikan, $tmp_lahir, $tgl_lahir, $alamat, $no_ktp, $mulai_kerja, $nik);
                if ($stmt_up->execute()) {
                    $success_msg = "Data pegawai NIK $nik berhasil diperbarui.";
                } else {
                    $error_msg = "Gagal memperbarui data pegawai: " . $koneksi->error;
                }
                $stmt_up->close();
            }
        }
    } 
    
    elseif ($action === 'delete') {
        $nik = trim($_POST['nik'] ?? '');
        if (!empty($nik)) {
            $stmt_del = $koneksi->prepare("DELETE FROM pegawai WHERE nik = ?");
            $stmt_del->bind_param("s", $nik);
            if ($stmt_del->execute()) {
                $success_msg = "Data pegawai NIK $nik berhasil dihapus.";
            } else {
                $error_msg = "Gagal menghapus pegawai: " . $koneksi->error;
            }
            $stmt_del->close();
        } else {
            $error_msg = "NIK tidak valid!";
        }
    }
}

// Fetch filter values
$search = trim($_GET['search'] ?? '');
$filter_dept = trim($_GET['dept'] ?? '');
$filter_status = trim($_GET['status'] ?? '');
$sort = trim($_GET['sort'] ?? 'nama');

$page_num = isset($_GET['p']) ? (int)$_GET['p'] : 1;
if ($page_num < 1) $page_num = 1;
$limit = 10;
$offset = ($page_num - 1) * $limit;

// Construct Query Conditions
$conditions = [];
$params = [];
$types = "";

if (!empty($search)) {
    $conditions[] = "(pegawai.nik LIKE ? OR pegawai.nama LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "ss";
}
if (!empty($filter_dept)) {
    $conditions[] = "pegawai.departemen = ?";
    $params[] = $filter_dept;
    $types .= "s";
}
if (!empty($filter_status)) {
    $conditions[] = "pegawai.stts_aktif = ?";
    $params[] = $filter_status;
    $types .= "s";
}

$where_clause = "";
if (!empty($conditions)) {
    $where_clause = " WHERE " . implode(" AND ", $conditions);
}

// Count Total
$count_query = "SELECT COUNT(*) as total FROM pegawai" . $where_clause;
$stmt_count = $koneksi->prepare($count_query);
if (!empty($params)) {
    $stmt_count->bind_param($types, ...$params);
}
$stmt_count->execute();
$total_rows = $stmt_count->get_result()->fetch_assoc()['total'];
$stmt_count->close();

$total_pages = ceil($total_rows / $limit);
if ($total_pages < 1) $total_pages = 1;

// Fetch pegawai data
if ($sort === 'kontrak') {
    $query = "SELECT pegawai.*, departemen.nama as nama_dept,
                     (CASE WHEN fv.nik IS NOT NULL THEN 1 ELSE 0 END) as has_face
              FROM pegawai 
              LEFT JOIN departemen ON departemen.dep_id = pegawai.departemen
              LEFT JOIN kontrak_pegawai kp ON kp.nik = pegawai.nik AND kp.tipe = 'Kontrak Pegawai'
              LEFT JOIN face_vector fv ON fv.nik = pegawai.nik" . $where_clause;
    $query .= " ORDER BY (kp.tanggal_habis IS NULL) ASC, kp.tanggal_habis ASC, pegawai.nama ASC LIMIT ? OFFSET ?";
} else if ($sort === 'sip') {
    $query = "SELECT pegawai.*, departemen.nama as nama_dept,
                     (CASE WHEN fv.nik IS NOT NULL THEN 1 ELSE 0 END) as has_face
              FROM pegawai 
              LEFT JOIN departemen ON departemen.dep_id = pegawai.departemen
              LEFT JOIN kontrak_pegawai kp ON kp.nik = pegawai.nik AND kp.tipe = 'SIP Dokter'
              LEFT JOIN face_vector fv ON fv.nik = pegawai.nik" . $where_clause;
    $query .= " ORDER BY (kp.tanggal_habis IS NULL) ASC, kp.tanggal_habis ASC, pegawai.nama ASC LIMIT ? OFFSET ?";
} else {
    $query = "SELECT pegawai.*, departemen.nama as nama_dept,
                     (CASE WHEN fv.nik IS NOT NULL THEN 1 ELSE 0 END) as has_face
              FROM pegawai 
              LEFT JOIN departemen ON departemen.dep_id = pegawai.departemen
              LEFT JOIN face_vector fv ON fv.nik = pegawai.nik" . $where_clause;
    $query .= " ORDER BY pegawai.nama ASC LIMIT ? OFFSET ?";
}

$params[] = $limit;
$params[] = $offset;
$types .= "ii";

$stmt_list = $koneksi->prepare($query);
if ($stmt_list) {
    $stmt_list->bind_param($types, ...$params);
    $stmt_list->execute();
    $pegawai_list = $stmt_list->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_list->close();
} else {
    $pegawai_list = [];
}

// Fetch document expiration warnings for the displayed paginated employees
if (!empty($pegawai_list)) {
    $niks = array_map(function($p) { return $p['nik']; }, $pegawai_list);
    $in_clause = implode(',', array_fill(0, count($niks), '?'));
    
    $query_warnings = "SELECT nik, tipe, nomor_dokumen, tanggal_habis, DATEDIFF(tanggal_habis, CURDATE()) as sisa_hari 
                       FROM kontrak_pegawai 
                       WHERE nik IN ($in_clause)";
    
    $stmt_warn = $koneksi->prepare($query_warnings);
    if ($stmt_warn) {
        $stmt_warn->bind_param(str_repeat("s", count($niks)), ...$niks);
        $stmt_warn->execute();
        $res_warn = $stmt_warn->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt_warn->close();
        
        $warnings_by_nik = [];
        foreach ($res_warn as $w) {
            $warnings_by_nik[$w['nik']][] = $w;
        }
        
        foreach ($pegawai_list as &$p) {
            $p['dokumen_warnings'] = $warnings_by_nik[$p['nik']] ?? [];
        }
        unset($p);
    }
}

// Fetch departemen list for dropdowns
$departments = [];
$res_dept = $koneksi->query("SELECT dep_id, nama FROM departemen ORDER BY nama ASC");
if ($res_dept) {
    while ($row = $res_dept->fetch_assoc()) {
        $departments[] = $row;
    }
}
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Data Pegawai</h1>
        <p class="text-secondary" style="font-size: 14px;">Kelola informasi master pegawai rumah sakit.</p>
    </div>
    <button class="btn btn-primary" onclick="openAddModal()">
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
        Tambah Pegawai
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
        <h3 class="card-title">Master Pegawai</h3>
        
        <!-- Filter Form -->
        <form method="GET" style="display: flex; gap: 10px; width: 100%; max-width: 680px; flex-wrap: wrap;">
            <input type="hidden" name="page" value="manajemen">
            <input type="hidden" name="sub" value="pegawai">
            <input type="text" name="search" class="form-control" placeholder="Cari NIK atau Nama..." value="<?= htmlspecialchars($search) ?>" style="flex: 1; min-width: 150px;">
            
            <select name="dept" class="form-control" style="width: 160px;">
                <option value="">-- Semua Dept --</option>
                <?php foreach ($departments as $d): ?>
                    <option value="<?= htmlspecialchars($d['dep_id']) ?>" <?= $filter_dept === $d['dep_id'] ? 'selected' : '' ?>><?= htmlspecialchars($d['nama']) ?></option>
                <?php endforeach; ?>
            </select>

            <select name="status" class="form-control" style="width: 130px;">
                <option value="">-- Semua Status --</option>
                <option value="AKTIF" <?= $filter_status === 'AKTIF' ? 'selected' : '' ?>>AKTIF</option>
                <option value="CUTI" <?= $filter_status === 'CUTI' ? 'selected' : '' ?>>CUTI</option>
                <option value="KELUAR" <?= $filter_status === 'KELUAR' ? 'selected' : '' ?>>KELUAR</option>
                <option value="TENAGA LUAR" <?= $filter_status === 'TENAGA LUAR' ? 'selected' : '' ?>>TENAGA LUAR</option>
                <option value="NON AKTIF" <?= $filter_status === 'NON AKTIF' ? 'selected' : '' ?>>NON AKTIF</option>
            </select>

            <select name="sort" class="form-control" style="width: 180px;">
                <option value="nama" <?= $sort === 'nama' ? 'selected' : '' ?>>Urut: Nama (A-Z)</option>
                <option value="kontrak" <?= $sort === 'kontrak' ? 'selected' : '' ?>>Urut: Kontrak Terdekat</option>
                <option value="sip" <?= $sort === 'sip' ? 'selected' : '' ?>>Urut: SIP Terdekat</option>
            </select>

            <button type="submit" class="btn btn-secondary btn-sm" style="padding: 10px 14px;">Filter</button>
        </form>
    </div>

    <div class="table-responsive">
        <table class="table-custom">
            <thead>
                <tr>
                    <th>NIK</th>
                    <th>Nama Pegawai</th>
                    <th>Gender</th>
                    <th>Jabatan</th>
                    <th>Departemen</th>
                    <th>Status</th>
                    <th style="text-align: right;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($pegawai_list)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; color: var(--text-secondary); padding: 30px;">
                            Tidak ada data pegawai ditemukan.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($pegawai_list as $p): ?>
                        <tr>
                            <td data-label="NIK"><strong><?= htmlspecialchars($p['nik']) ?></strong></td>
                             <td data-label="Nama Pegawai">
                                 <div style="font-weight: 600; color: var(--text-primary);"><?= htmlspecialchars($p['nama']) ?></div>
                                 <?php if (!empty($p['dokumen_warnings'])): ?>
                                     <div style="display: flex; flex-wrap: wrap; gap: 6px; margin-top: 6px;">
                                         <?php foreach ($p['dokumen_warnings'] as $w): ?>
                                             <?php 
                                             $sisa = (int)$w['sisa_hari'];
                                             $tipe_label = htmlspecialchars($w['tipe'] === 'Kontrak Pegawai' ? 'Kontrak' : 'SIP');
                                             if ($sisa < 0) {
                                                 $badge_class = 'badge-danger';
                                                 $badge_text = "$tipe_label Expired (" . abs($sisa) . " hr)";
                                                 $show_badge = true;
                                             } else if ($sisa <= 30) {
                                                 $badge_class = 'badge-danger';
                                                 $badge_text = "$tipe_label Habis $sisa hr";
                                                 $show_badge = true;
                                             } else if ($sisa <= 90) {
                                                 $badge_class = 'badge-warning';
                                                 $badge_text = "$tipe_label Habis $sisa hr";
                                                 $show_badge = true;
                                             } else {
                                                 $show_badge = false;
                                             }
                                             ?>
                                             <?php if ($show_badge): ?>
                                                 <span class="badge <?= $badge_class ?>" style="font-size: 10px; padding: 2px 6px; text-transform: none; display: inline-flex; align-items: center; gap: 4px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                                                     ⚠️ <?= $badge_text ?>
                                                 </span>
                                             <?php endif; ?>
                                         <?php endforeach; ?>
                                     </div>
                                 <?php endif; ?>
                             </td>
                            <td data-label="Gender"><?= htmlspecialchars($p['jk']) ?></td>
                            <td data-label="Jabatan"><?= htmlspecialchars($p['jbtn'] ?: '-') ?></td>
                            <td data-label="Departemen"><?= htmlspecialchars($p['nama_dept'] ?? 'Tanpa Departemen') ?></td>
                            <td data-label="Status">
                                <span class="badge <?= $p['stts_aktif'] === 'AKTIF' ? 'badge-success' : ($p['stts_aktif'] === 'CUTI' ? 'badge-warning' : 'badge-danger') ?>">
                                    <?= htmlspecialchars($p['stts_aktif']) ?>
                                </span>
                            </td>
                            <td style="text-align: right;">
                                <div style="display: inline-flex; gap: 6px;">
                                    <?php if (isset($p['has_face']) && $p['has_face'] == 1): ?>
                                        <button class="btn btn-danger btn-sm" style="background:#dc2626; border-color:#dc2626;" onclick="resetFace('<?= htmlspecialchars($p['nik']) ?>', '<?= htmlspecialchars(addslashes($p['nama'])) ?>')">
                                            Reset Wajah
                                        </button>
                                    <?php endif; ?>
                                    <button class="btn btn-secondary btn-sm" onclick='openEditModal(<?= json_encode($p) ?>)'>
                                        Edit
                                    </button>
                                    <button class="btn btn-primary btn-sm" onclick='openDokumenModal(<?= json_encode($p) ?>)'>
                                        Kontrak/SIP
                                    </button>
                                    <button class="btn btn-danger btn-sm" onclick="confirmDeletePegawai('<?= htmlspecialchars($p['nik']) ?>')">
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
                <a href="index.php?page=manajemen&sub=pegawai&search=<?= urlencode($search) ?>&dept=<?= urlencode($filter_dept) ?>&status=<?= urlencode($filter_status) ?>&sort=<?= urlencode($sort) ?>&p=<?= $i ?>" class="btn <?= $i === $page_num ? 'btn-primary' : 'btn-secondary' ?> btn-sm" style="min-width: 32px; justify-content: center;">
                    <?= $i ?>
                </a>
            <?php endfor; ?>
        </div>
    <?php endif; ?>
</div>

<!-- ================= ADD / EDIT MODAL ================= -->
<div id="pegawaiModal" class="modal-overlay" onclick="closeModal('pegawaiModal')">
    <div class="modal-content modal-lg" onclick="event.stopPropagation()">
        <form method="POST">
            <input type="hidden" name="action" id="modal_action" value="create">
            <div class="modal-header">
                <h3 id="modal_title">Tambah Pegawai</h3>
                <button type="button" class="btn-close" onclick="closeModal('pegawaiModal')">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                </button>
            </div>
            <div class="modal-body" style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                <div class="form-group" style="grid-column: span 2; display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div>
                        <label class="form-label" for="nik">NIK / No. Identitas *</label>
                        <input type="text" id="nik" name="nik" class="form-control" placeholder="NIK Pegawai (contoh: 120000001)" required>
                    </div>
                    <div>
                        <label class="form-label" for="nama">Nama Pegawai *</label>
                        <input type="text" id="nama" name="nama" class="form-control" placeholder="Nama Lengkap Tanpa Gelar" required>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="jk">Jenis Kelamin</label>
                    <select id="jk" name="jk" class="form-control">
                        <option value="Pria">Pria</option>
                        <option value="Wanita">Wanita</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label" for="departemen">Departemen</label>
                    <select id="departemen" name="departemen" class="form-control" required>
                        <option value="-">-- Pilih Departemen --</option>
                        <?php foreach ($departments as $d): ?>
                            <option value="<?= htmlspecialchars($d['dep_id']) ?>"><?= htmlspecialchars($d['nama']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label" for="jbtn">Jabatan</label>
                    <input type="text" id="jbtn" name="jbtn" class="form-control" placeholder="contoh: Perawat / Staf Administrasi">
                </div>

                <div class="form-group">
                    <label class="form-label" for="pendidikan">Pendidikan Terakhir</label>
                    <input type="text" id="pendidikan" name="pendidikan" class="form-control" placeholder="contoh: D3 Keperawatan / S1 Akuntansi">
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
                        <label class="form-label" for="no_ktp">No. KTP</label>
                        <input type="text" id="no_ktp" name="no_ktp" class="form-control" placeholder="16 Digit No. KTP">
                    </div>
                    <div>
                        <label class="form-label" for="mulai_kerja">Mulai Kerja</label>
                        <input type="date" id="mulai_kerja" name="mulai_kerja" class="form-control">
                    </div>
                </div>

                <div class="form-group" style="grid-column: span 2;">
                    <label class="form-label" for="alamat">Alamat Lengkap</label>
                    <input type="text" id="alamat" name="alamat" class="form-control" placeholder="Jalan, RT/RW, Kelurahan, Kecamatan, Kota">
                </div>

                <div class="form-group" style="grid-column: span 2;">
                    <label class="form-label" for="stts_aktif">Status Aktif Pegawai</label>
                    <select id="stts_aktif" name="stts_aktif" class="form-control">
                        <option value="AKTIF">AKTIF</option>
                        <option value="CUTI">CUTI</option>
                        <option value="KELUAR">KELUAR</option>
                        <option value="TENAGA LUAR">TENAGA LUAR</option>
                        <option value="NON AKTIF">NON AKTIF</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('pegawaiModal')">Batal</button>
                <button type="submit" class="btn btn-primary" id="modal_submit_btn">Simpan</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Pegawai Modal Form (Hidden) -->
<form id="deletePegawaiForm" method="POST" style="display: none;">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" id="delete_nik" name="nik">
</form>

<!-- ================= DOKUMEN (KONTRAK & SIP) MODAL ================= -->
<div id="dokumenModal" class="modal-overlay" onclick="closeModal('dokumenModal')">
    <div class="modal-content modal-lg" onclick="event.stopPropagation()" style="max-width: 820px; width: 95%;">
        <div class="modal-header">
            <div>
                <h3 id="dokumen_modal_title" style="font-weight: 800; font-size: 18px;">Manajemen Dokumen Pegawai</h3>
                <span class="text-secondary" style="font-size: 12px; font-weight: 500;" id="dokumen_modal_subtitle">NIK: -</span>
            </div>
            <button type="button" class="btn-close" onclick="closeModal('dokumenModal')">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
            </button>
        </div>
        <div class="modal-body" style="display: grid; grid-template-columns: 1fr; gap: 24px; padding: 20px;">
            <!-- Responsiveness grid -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 24px;">
                <!-- Left Side: Form Input/Edit Dokumen -->
                <div style="border-right: 1px solid var(--border-color); padding-right: 12px;">
                    <h4 style="font-size: 14px; font-weight: 700; margin-bottom: 16px; color: var(--text-primary);" id="doc_form_title">Tambah Dokumen Baru</h4>
                    <form id="docForm" onsubmit="saveDokumen(event)">
                        <input type="hidden" id="doc_id" value="0">
                        <input type="hidden" id="doc_nik" value="">
                        
                        <div class="form-group">
                            <label class="form-label">Tipe Dokumen *</label>
                            <select id="doc_tipe" class="form-control" required onchange="onDocTypeChanged()">
                                <option value="Kontrak Pegawai">Kontrak Pegawai</option>
                                <option value="SIP Dokter">SIP Dokter</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label" id="label_nomor_dokumen">Nomor Dokumen</label>
                            <input type="text" id="doc_nomor" class="form-control" placeholder="Masukkan nomor dokumen..." maxlength="100">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Tanggal Mulai Berlaku</label>
                            <input type="date" id="doc_tgl_mulai" class="form-control">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Tanggal Habis Berlaku *</label>
                            <input type="date" id="doc_tgl_habis" class="form-control" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Keterangan / Catatan</label>
                            <input type="text" id="doc_keterangan" class="form-control" placeholder="Catatan tambahan (maks 200 karakter)..." maxlength="200">
                        </div>

                        <div style="display: flex; gap: 8px; margin-top: 16px;">
                            <button type="submit" class="btn btn-primary" style="flex: 1; justify-content: center;" id="btn_save_doc">Simpan Dokumen</button>
                            <button type="button" class="btn btn-secondary" style="display: none; justify-content: center;" id="btn_cancel_edit_doc" onclick="resetDocForm()">Batal Edit</button>
                        </div>
                    </form>
                </div>

                <!-- Right Side: Daftar Dokumen Pegawai -->
                <div>
                    <h4 style="font-size: 14px; font-weight: 700; margin-bottom: 16px; color: var(--text-primary);">Daftar Dokumen Aktif</h4>
                    <div class="table-responsive" style="max-height: 380px; overflow-y: auto;">
                        <table class="table-custom" style="font-size: 12.5px;">
                            <thead>
                                <tr>
                                    <th>Tipe / No</th>
                                    <th>Masa Berlaku</th>
                                    <th>Sisa Hari</th>
                                    <th style="text-align: right;">Aksi</th>
                                </tr>
                            </thead>
                            <tbody id="docListTableBody">
                                <tr>
                                    <td colspan="4" style="text-align: center; color: var(--text-secondary); padding: 20px;">
                                        Memuat data dokumen...
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function openModal(id) {
    document.getElementById(id).classList.add('active');
}

function closeModal(id) {
    document.getElementById(id).classList.remove('active');
}

function openAddModal() {
    document.getElementById('modal_action').value = 'create';
    document.getElementById('modal_title').innerText = 'Tambah Pegawai Baru';
    document.getElementById('modal_submit_btn').innerText = 'Simpan';
    
    // Clear and enable form fields
    document.getElementById('nik').value = '';
    document.getElementById('nik').disabled = false;
    document.getElementById('nama').value = '';
    document.getElementById('jk').value = 'Pria';
    document.getElementById('departemen').value = '-';
    document.getElementById('jbtn').value = '';
    document.getElementById('pendidikan').value = '';
    document.getElementById('tmp_lahir').value = '';
    document.getElementById('tgl_lahir').value = '';
    document.getElementById('no_ktp').value = '';
    document.getElementById('mulai_kerja').value = '';
    document.getElementById('alamat').value = '';
    document.getElementById('stts_aktif').value = 'AKTIF';

    openModal('pegawaiModal');
}

function openEditModal(p) {
    document.getElementById('modal_action').value = 'update';
    document.getElementById('modal_title').innerText = 'Edit Data Pegawai';
    document.getElementById('modal_submit_btn').innerText = 'Simpan Perubahan';
    
    // Populate form fields
    const nik_input = document.getElementById('nik');
    nik_input.value = p.nik;
    
    // Create a hidden input for nik if we submit the form so it is still sent back despite disabled field
    let hidden_nik = document.getElementById('hidden_nik');
    if (!hidden_nik) {
        hidden_nik = document.createElement('input');
        hidden_nik.type = 'hidden';
        hidden_nik.id = 'hidden_nik';
        hidden_nik.name = 'nik';
        nik_input.parentNode.appendChild(hidden_nik);
    }
    hidden_nik.value = p.nik;
    nik_input.disabled = true;

    document.getElementById('nama').value = p.nama;
    document.getElementById('jk').value = p.jk || 'Pria';
    document.getElementById('departemen').value = p.departemen || '-';
    document.getElementById('jbtn').value = p.jbtn || '';
    document.getElementById('pendidikan').value = p.pendidikan || '';
    document.getElementById('tmp_lahir').value = p.tmp_lahir || '';
    document.getElementById('tgl_lahir').value = p.tgl_lahir || '';
    document.getElementById('no_ktp').value = p.no_ktp || '';
    document.getElementById('mulai_kerja').value = p.mulai_kerja || '';
    document.getElementById('alamat').value = p.alamat || '';
    document.getElementById('stts_aktif').value = p.stts_aktif || 'AKTIF';

    openModal('pegawaiModal');
}

function confirmDeletePegawai(nik) {
    if (confirm("Apakah Anda yakin ingin menghapus data pegawai NIK " + nik + "? Tindakan ini tidak dapat dibatalkan.")) {
        document.getElementById('delete_nik').value = nik;
        document.getElementById('deletePegawaiForm').submit();
    }
}

// ================= DOKUMEN MODAL JAVASCRIPT =================
let currentDocPegawai = null;

function openDokumenModal(p) {
    currentDocPegawai = p;
    document.getElementById('dokumen_modal_title').innerText = "Manajemen Dokumen: " + p.nama;
    document.getElementById('dokumen_modal_subtitle').innerText = "NIK: " + p.nik;
    document.getElementById('doc_nik').value = p.nik;
    
    resetDocForm();
    fetchDokumen(p.nik);
    openModal('dokumenModal');
}

function onDocTypeChanged() {
    const tipe = document.getElementById('doc_tipe').value;
    const labelNomor = document.getElementById('label_nomor_dokumen');
    if (tipe === 'SIP Dokter') {
        labelNomor.innerText = 'Nomor SIP';
    } else {
        labelNomor.innerText = 'Nomor Kontrak';
    }
}

function resetDocForm() {
    document.getElementById('doc_id').value = "0";
    document.getElementById('doc_tipe').value = "Kontrak Pegawai";
    document.getElementById('doc_nomor').value = "";
    document.getElementById('doc_tgl_mulai').value = "";
    document.getElementById('doc_tgl_habis').value = "";
    document.getElementById('doc_keterangan').value = "";
    
    document.getElementById('doc_tipe').disabled = false;
    document.getElementById('doc_form_title').innerText = "Tambah Dokumen Baru";
    document.getElementById('btn_save_doc').innerText = "Simpan Dokumen";
    document.getElementById('btn_cancel_edit_doc').style.display = "none";
    
    onDocTypeChanged();
}

function fetchDokumen(nik) {
    const tbody = document.getElementById('docListTableBody');
    tbody.innerHTML = `<tr><td colspan="4" style="text-align: center; color: var(--text-secondary); padding: 20px;">Memuat data...</td></tr>`;
    
    const formData = new FormData();
    formData.append('ajax_action', 'fetch_docs');
    formData.append('nik', nik);
    
    fetch('index.php?page=manajemen&sub=pegawai', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            renderDocList(data.docs);
        } else {
            tbody.innerHTML = `<tr><td colspan="4" style="text-align: center; color: var(--danger); padding: 20px;">Gagal memuat: ${data.message}</td></tr>`;
        }
    })
    .catch(err => {
        tbody.innerHTML = `<tr><td colspan="4" style="text-align: center; color: var(--danger); padding: 20px;">Kesalahan jaringan!</td></tr>`;
    });
}

function renderDocList(docs) {
    const tbody = document.getElementById('docListTableBody');
    if (docs.length === 0) {
        tbody.innerHTML = `<tr><td colspan="4" style="text-align: center; color: var(--text-secondary); padding: 20px;">Belum ada dokumen Kontrak atau SIP terdaftar.</td></tr>`;
        return;
    }
    
    tbody.innerHTML = "";
    docs.forEach(doc => {
        const sisa = parseInt(doc.sisa_hari);
        let sisaHtml = '';
        if (isNaN(sisa)) {
            sisaHtml = '<span class="text-secondary">-</span>';
        } else if (sisa < 0) {
            sisaHtml = `<span class="badge badge-danger" style="font-size: 11px;">Kedaluwarsa (${Math.abs(sisa)} hari)</span>`;
        } else if (sisa <= 30) {
            sisaHtml = `<span class="badge badge-danger" style="font-size: 11px;">Hampir Habis (${sisa} hari)</span>`;
        } else if (sisa <= 90) {
            sisaHtml = `<span class="badge badge-warning" style="font-size: 11px; color:#a16207; background:#fef9c3;">${sisa} Hari</span>`;
        } else {
            sisaHtml = `<span class="badge badge-success" style="font-size: 11px;">${sisa} Hari</span>`;
        }
        
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td>
                <strong>${escapeHtml(doc.tipe)}</strong>
                <div style="font-size: 11px; color: var(--text-secondary); margin-top: 2px;">No: ${escapeHtml(doc.nomor_dokumen || '-')}</div>
            </td>
            <td>
                <div style="font-size: 11px;">Mulai: ${doc.tanggal_mulai ? formatDate(doc.tanggal_mulai) : '-'}</div>
                <div style="font-size: 11px; font-weight: 700; margin-top: 1px;">Habis: ${formatDate(doc.tanggal_habis)}</div>
            </td>
            <td>${sisaHtml}</td>
            <td style="text-align: right;">
                <div style="display: inline-flex; gap: 4px;">
                    <button type="button" class="btn btn-secondary btn-sm" style="padding: 4px 8px; font-size: 11px;" onclick='startEditDoc(${JSON.stringify(doc)})'>Edit</button>
                    <button type="button" class="btn btn-danger btn-sm" style="padding: 4px 8px; font-size: 11px;" onclick="deleteDoc('${escapeHtml(doc.tipe)}')">Hapus</button>
                </div>
            </td>
        `;
        tbody.appendChild(tr);
    });
}

function startEditDoc(doc) {
    document.getElementById('doc_tipe').value = doc.tipe;
    document.getElementById('doc_nomor').value = doc.nomor_dokumen || '';
    document.getElementById('doc_tgl_mulai').value = doc.tanggal_mulai || '';
    document.getElementById('doc_tgl_habis').value = doc.tanggal_habis || '';
    document.getElementById('doc_keterangan').value = doc.keterangan || '';
    
    document.getElementById('doc_tipe').disabled = true;
    document.getElementById('doc_form_title').innerText = "Edit Dokumen";
    document.getElementById('btn_save_doc').innerText = "Simpan Perubahan";
    document.getElementById('btn_cancel_edit_doc').style.display = "inline-flex";
    
    onDocTypeChanged();
}

function saveDokumen(e) {
    e.preventDefault();
    const btn = document.getElementById('btn_save_doc');
    btn.disabled = true;
    btn.innerText = 'Menyimpan...';
    
    const nik = document.getElementById('doc_nik').value;
    const tipe = document.getElementById('doc_tipe').value;
    const nomor = document.getElementById('doc_nomor').value;
    const mulai = document.getElementById('doc_tgl_mulai').value;
    const habis = document.getElementById('doc_tgl_habis').value;
    const keterangan = document.getElementById('doc_keterangan').value;
    
    const formData = new FormData();
    formData.append('ajax_action', 'save_doc');
    formData.append('nik', nik);
    formData.append('tipe', tipe);
    formData.append('nomor_dokumen', nomor);
    formData.append('tanggal_mulai', mulai);
    formData.append('tanggal_habis', habis);
    formData.append('keterangan', keterangan);
    
    fetch('index.php?page=manajemen&sub=pegawai', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        btn.disabled = false;
        btn.innerText = 'Simpan Dokumen';
        
        if (data.success) {
            alert(data.message);
            resetDocForm();
            fetchDokumen(nik);
        } else {
            alert("Gagal: " + data.message);
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerText = 'Simpan Dokumen';
        alert("Kesalahan jaringan saat menyimpan dokumen!");
    });
}

function deleteDoc(tipe) {
    if (!confirm("Apakah Anda yakin ingin menghapus dokumen " + tipe + " ini?")) return;
    
    const nik = document.getElementById('doc_nik').value;
    const formData = new FormData();
    formData.append('ajax_action', 'delete_doc');
    formData.append('nik', nik);
    formData.append('tipe', tipe);
    
    fetch('index.php?page=manajemen&sub=pegawai', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            resetDocForm();
            fetchDokumen(nik);
        } else {
            alert("Gagal menghapus: " + data.message);
        }
    })
    .catch(err => {
        alert("Kesalahan jaringan saat menghapus dokumen!");
    });
}

function escapeHtml(text) {
    if (!text) return '';
    const map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    };
    return text.replace(/[&<>"']/g, function(m) { return map[m]; });
}

function formatDate(dateStr) {
    if (!dateStr) return '';
    const parts = dateStr.split('-');
    if (parts.length === 3) {
        return `${parts[2]}-${parts[1]}-${parts[0]}`;
    }
    return dateStr;
}

function resetFace(nik, nama) {
    if (!confirm("Apakah Anda yakin ingin me-reset data verifikasi wajah untuk pegawai " + nama + " (" + nik + ")?\nPegawai harus merekam kembali wajahnya lewat halaman Profil sebelum bisa melakukan absensi.")) return;
    
    const formData = new FormData();
    formData.append('ajax_action', 'reset_face');
    formData.append('nik', nik);
    
    fetch('index.php?page=manajemen&sub=pegawai', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            window.location.reload();
        } else {
            alert("Gagal me-reset: " + data.message);
        }
    })
    .catch(err => {
        alert("Kesalahan jaringan saat melakukan reset wajah!");
    });
}
</script>
