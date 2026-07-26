<?php
if (!defined('host')) {
    exit('No direct script access allowed');
}

$nik_user = $_SESSION['username'];
$is_admin = isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true;

$success_msg = '';
$error_msg   = '';

// ── 1. AMBIL DAFTAR PEGAWAI UNTUK DROPDOWN LEVEL DISPOSISI ─────────────────
$pegawai_list = [];
$res_peg = $koneksi->query("SELECT nik, nama, jbtn FROM pegawai WHERE stts_aktif = 'AKTIF' ORDER BY nama ASC");
if ($res_peg) {
    while ($row_p = $res_peg->fetch_assoc()) {
        $pegawai_list[] = $row_p;
    }
}

// ── 2. PROCESS POST ACTIONS ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── A. SIMPAN SURAT MASUK BARU + ALOKASI 3 LEVEL DISPOSISI ───────────────
    if ($action === 'simpan_surat') {
        $no_surat   = trim($_POST['no_surat'] ?? '');
        $asal       = trim($_POST['asal'] ?? '');
        $tujuan     = trim($_POST['tujuan'] ?? '');
        $tgl_surat  = $_POST['tgl_surat'] ?? date('Y-m-d');
        $tgl_terima = $_POST['tgl_terima'] ?? date('Y-m-d');
        $perihal    = trim($_POST['perihal'] ?? '');
        $keterangan = trim($_POST['keterangan'] ?? '');
        
        $level1_nik = $_POST['level1_nik'] ?? '';
        $level2_nik = $_POST['level2_nik'] ?? '';
        $level3_nik = $_POST['level3_nik'] ?? '';

        if (empty($no_surat) || empty($asal) || empty($perihal)) {
            $error_msg = "Nomor Surat, Asal, dan Perihal wajib diisi!";
        } elseif (empty($level1_nik) || empty($level2_nik) || empty($level3_nik)) {
            $error_msg = "Harap tentukan penerima disposisi untuk ketiga level (Level 1, Level 2, dan Level 3)!";
        } else {
            // Generate nomor urut transaksi otomatis (SM + Ymd + 3 digit counter)
            $prefix = 'SM' . date('Ymd');
            $res_no = $koneksi->query("SELECT MAX(no_urut) as max_no FROM surat_masuk WHERE no_urut LIKE '$prefix%'");
            $next_num = 1;
            if ($res_no && $row_no = $res_no->fetch_assoc()) {
                if (!empty($row_no['max_no'])) {
                    $last_counter = (int) substr($row_no['max_no'], -3);
                    $next_num = $last_counter + 1;
                }
            }
            $no_urut = $prefix . str_pad($next_num, 3, '0', STR_PAD_LEFT);

            // Handle Upload File PDF / Gambar
            $file_url = '';
            if (isset($_FILES['file_surat']) && $_FILES['file_surat']['error'] === UPLOAD_ERR_OK) {
                $file_tmp  = $_FILES['file_surat']['tmp_name'];
                $file_name = $_FILES['file_surat']['name'];
                $ext       = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                $allowed   = ['pdf', 'jpg', 'jpeg', 'png'];

                if (in_array($ext, $allowed)) {
                    $new_filename = 'Surat_' . $no_urut . '_' . time() . '.' . $ext;
                    $upload_dir   = __DIR__ . '/upload/';
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }
                    $target_path = $upload_dir . $new_filename;
                    if (move_uploaded_file($file_tmp, $target_path)) {
                        $file_url = 'pages/upload/' . $new_filename;
                    }
                } else {
                    $error_msg = "Format file tidak didukung! Format yang diperbolehkan: PDF, JPG, PNG.";
                }
            }

            if (empty($error_msg)) {
                // Simpan ke surat_masuk (Tabel standar Khanza 100% murni tanpa diubah)
                $kd_lemari = 'SA001'; $kd_rak = 'SR001'; $kd_map = 'SM001'; $kd_ruang = 'SG001';
                $kd_sifat = 'SF001'; $kd_balas = 'SB002'; $kd_status = 'SS003'; $kd_klasifikasi = 'SK002';
                $lampiran = '-'; $tembusan = '-'; $tgl_deadline = $tgl_terima;

                $stmt_ins = $koneksi->prepare("INSERT INTO surat_masuk 
                    (no_urut, no_surat, asal, tujuan, tgl_surat, perihal, tgl_terima, kd_lemari, kd_rak, kd_map, kd_ruang, kd_sifat, lampiran, tembusan, tgl_deadline_balas, kd_balas, keterangan, kd_status, kd_klasifikasi, file_url)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                
                if ($stmt_ins) {
                    $stmt_ins->bind_param("ssssssssssssssssssss", 
                        $no_urut, $no_surat, $asal, $tujuan, $tgl_surat, $perihal, $tgl_terima,
                        $kd_lemari, $kd_rak, $kd_map, $kd_ruang, $kd_sifat, $lampiran, $tembusan,
                        $tgl_deadline, $kd_balas, $keterangan, $kd_status, $kd_klasifikasi, $file_url
                    );

                    if ($stmt_ins->execute()) {
                        // Simpan 3 Level Disposisi + User Input ke surat_masuk_disposisi_level
                        $levels = [
                            1 => ['nik' => $level1_nik, 'label' => 'Level 1 (Kepala Bagian)'],
                            2 => ['nik' => $level2_nik, 'label' => 'Level 2 (Wakil Direktur)'],
                            3 => ['nik' => $level3_nik, 'label' => 'Level 3 (Direktur)']
                        ];

                        $stmt_lvl = $koneksi->prepare("INSERT INTO surat_masuk_disposisi_level (no_urut, level, nik, jabatan_label, status_disposisi, user_input) VALUES (?, ?, ?, ?, 'Menunggu', ?)");
                        foreach ($levels as $lvl_num => $lvl_info) {
                            $stmt_lvl->bind_param("sisss", $no_urut, $lvl_num, $lvl_info['nik'], $lvl_info['label'], $nik_user);
                            $stmt_lvl->execute();
                        }
                        $stmt_lvl->close();

                        $success_msg = "Surat Masuk <strong>" . htmlspecialchars($no_surat) . "</strong> (" . $no_urut . ") berhasil disimpan dan disposisi 3 level telah dialokasikan.";
                    } else {
                        $error_msg = "Gagal menyimpan surat masuk: " . $koneksi->error;
                    }
                    $stmt_ins->close();
                } else {
                    $error_msg = "Gagal menyiapkan query insert surat masuk: " . $koneksi->error;
                }
            }
        }
    }

    // ── B. INPUT / UPDATE DISPOSISI PER LEVEL ─────────────────────────────────
    elseif ($action === 'simpan_disposisi') {
        $no_urut       = trim($_POST['no_urut'] ?? '');
        $level         = (int)($_POST['level'] ?? 0);
        $isi_disposisi = trim($_POST['isi_disposisi'] ?? '');
        $harap         = trim($_POST['harap'] ?? '');
        $catatan       = trim($_POST['catatan'] ?? '');
        $pengesahan    = ($_POST['pengesahan'] ?? '') === 'true' ? 'true' : 'false';

        if (empty($no_urut) || $level < 1 || $level > 3 || empty($isi_disposisi)) {
            $error_msg = "Isi disposisi wajib diisi!";
        } else {
            // VERIFIKASI HAK AKSES: Pastikan user terdaftar di level tersebut atau admin
            $can_submit = $is_admin;
            if (!$can_submit) {
                $stmt_chk = $koneksi->prepare("SELECT id FROM surat_masuk_disposisi_level WHERE no_urut = ? AND level = ? AND nik = ?");
                $stmt_chk->bind_param("sis", $no_urut, $level, $nik_user);
                $stmt_chk->execute();
                $res_chk = $stmt_chk->get_result();
                if ($res_chk && $res_chk->num_rows > 0) {
                    $can_submit = true;
                }
                $stmt_chk->close();
            }

            if ($can_submit) {
                $now = date('Y-m-d H:i:s');
                $stmt_up_disp = $koneksi->prepare("UPDATE surat_masuk_disposisi_level 
                    SET status_disposisi = 'Sudah Disposisi', tgl_disposisi = ?, isi_disposisi = ?, harap = ?, catatan = ?, pengesahan = ?
                    WHERE no_urut = ? AND level = ?");
                if ($stmt_up_disp) {
                    $stmt_up_disp->bind_param("ssssssi", $now, $isi_disposisi, $harap, $catatan, $pengesahan, $no_urut, $level);
                    if ($stmt_up_disp->execute()) {
                        $success_msg = "Disposisi Level $level untuk surat $no_urut berhasil disimpan.";
                    } else {
                        $error_msg = "Gagal memperbarui disposisi: " . $koneksi->error;
                    }
                    $stmt_up_disp->close();
                }
            } else {
                $error_msg = "Akses Ditolak: Anda tidak terdaftar sebagai pemberi disposisi Level $level untuk surat ini!";
            }
        }
    }
}

// ── 3. QUERY FILTER DATA SURAT MASUK KETAT PER USER ─────────────────────────
$search = trim($_GET['search'] ?? '');

if ($is_admin) {
    // Admin utama melihat semua surat
    $sql_surat = "SELECT s.*, 
                    (SELECT COUNT(*) FROM surat_masuk_disposisi_level d1 WHERE d1.no_urut = s.no_urut AND d1.status_disposisi = 'Sudah Disposisi') as total_disposisi
                  FROM surat_masuk s";
    if (!empty($search)) {
        $search_esc = $koneksi->real_escape_string($search);
        $sql_surat .= " WHERE s.no_surat LIKE '%$search_esc%' OR s.asal LIKE '%$search_esc%' OR s.perihal LIKE '%$search_esc%' OR s.no_urut LIKE '%$search_esc%'";
    }
    $sql_surat .= " ORDER BY s.tgl_terima DESC, s.no_urut DESC";
} else {
    // User biasa HANYA BISA MEMBUKA surat jika NIK-nya terdaftar di Level 1, Level 2, Level 3, ATAU penginput surat di tabel disposisi_level
    $nik_esc = $koneksi->real_escape_string($nik_user);
    $sql_surat = "SELECT DISTINCT s.*,
                    (SELECT COUNT(*) FROM surat_masuk_disposisi_level d1 WHERE d1.no_urut = s.no_urut AND d1.status_disposisi = 'Sudah Disposisi') as total_disposisi
                  FROM surat_masuk s
                  INNER JOIN surat_masuk_disposisi_level d ON s.no_urut = d.no_urut
                  WHERE (d.nik = '$nik_esc' OR d.user_input = '$nik_esc')";
    
    if (!empty($search)) {
        $search_esc = $koneksi->real_escape_string($search);
        $sql_surat .= " AND (s.no_surat LIKE '%$search_esc%' OR s.asal LIKE '%$search_esc%' OR s.perihal LIKE '%$search_esc%' OR s.no_urut LIKE '%$search_esc%')";
    }
    $sql_surat .= " ORDER BY s.tgl_terima DESC, s.no_urut DESC";
}

$res_surat = $koneksi->query($sql_surat);
$surat_data = [];
if ($res_surat) {
    while ($row_s = $res_surat->fetch_assoc()) {
        $no_u = $row_s['no_urut'];
        // Ambil data rincian 3 level disposisi untuk surat ini
        $res_lvl = $koneksi->query("SELECT d.*, p.nama as nama_pegawai, p.jbtn 
                                    FROM surat_masuk_disposisi_level d
                                    LEFT JOIN pegawai p ON p.nik = d.nik
                                    WHERE d.no_urut = '$no_u'
                                    ORDER BY d.level ASC");
        $levels_data = [];
        if ($res_lvl) {
            while ($r_l = $res_lvl->fetch_assoc()) {
                $levels_data[$r_l['level']] = $r_l;
            }
        }
        $row_s['levels'] = $levels_data;
        $surat_data[] = $row_s;
    }
}
?>

<div class="content-card">
    <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 16px; margin-bottom: 24px;">
        <div>
            <h1 class="page-title" style="margin-bottom: 4px; display: flex; align-items: center; gap: 10px;">
                <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--primary-color);"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
                Surat Masuk & Disposisi Bertingkat
            </h1>
            <p class="text-secondary" style="margin: 0; font-size: 14px;">
                Pengelolaan surat masuk dengan alur disposisi 3 level independen (paralel) & pembatasan hak akses ketat.
            </p>
        </div>
        
        <button type="button" class="btn btn-primary" onclick="openModal('addSuratModal')" style="display: flex; align-items: center; gap: 8px;">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
            Input Surat Masuk Baru
        </button>
    </div>

    <!-- NOTIFIKASI PESAN -->
    <?php if (!empty($success_msg)): ?>
        <div style="background: #dcfce7; border: 1px solid #86efac; color: #166534; padding: 14px 18px; border-radius: 12px; margin-bottom: 20px; font-size: 14px; display: flex; align-items: center; gap: 10px;">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"></polyline></svg>
            <div><?= $success_msg ?></div>
        </div>
    <?php endif; ?>

    <?php if (!empty($error_msg)): ?>
        <div style="background: #fee2e2; border: 1px solid #fca5a5; color: #991b1b; padding: 14px 18px; border-radius: 12px; margin-bottom: 20px; font-size: 14px; display: flex; align-items: center; gap: 10px;">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
            <div><?= $error_msg ?></div>
        </div>
    <?php endif; ?>

    <!-- CARI SURAT -->
    <div style="margin-bottom: 20px;">
        <form method="GET" style="display: flex; gap: 12px; max-width: 480px;">
            <input type="hidden" name="page" value="surat_menyurat">
            <input type="hidden" name="sub" value="surat_masuk">
            <input type="text" name="search" class="form-control" placeholder="Cari No. Surat, Asal, Perihal..." value="<?= htmlspecialchars($search) ?>">
            <button type="submit" class="btn btn-secondary">Cari</button>
            <?php if (!empty($search)): ?>
                <a href="index.php?page=surat_menyurat&sub=surat_masuk" class="btn btn-secondary" style="background: #e2e8f0; color: #475569;">Reset</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- LIST DATA SURAT -->
    <?php if (empty($surat_data)): ?>
        <div style="text-align: center; padding: 48px 20px; background: #f8fafc; border-radius: 16px; border: 1px dashed #cbd5e1;">
            <div style="font-size: 40px; margin-bottom: 12px;">📭</div>
            <h3 style="margin-bottom: 6px; color: #334155;">Belum Ada Surat Masuk</h3>
            <p style="color: #64748b; font-size: 14px; max-width: 400px; margin: 0 auto;">
                <?= !empty($search) ? 'Tidak ditemukan surat yang cocok dengan kata kunci pencarian.' : 'Anda belum memiliki surat masuk yang ditugaskan kepada Anda.' ?>
            </p>
        </div>
    <?php else: ?>
        <div style="display: grid; grid-template-columns: 1fr; gap: 20px;">
            <?php foreach ($surat_data as $surat): ?>
                <?php
                $user_level = 0;
                foreach ($surat['levels'] as $lvl => $ldat) {
                    if ($ldat['nik'] === $nik_user) {
                        $user_level = $lvl;
                        break;
                    }
                }
                ?>
                <div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 20px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.04); transition: all 0.2s ease;">
                    
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 12px; margin-bottom: 14px; border-bottom: 1px solid #f1f5f9; padding-bottom: 14px;">
                        <div>
                            <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 4px;">
                                <span style="background: #e0f2fe; color: #0369a1; font-weight: 700; font-size: 12px; padding: 3px 10px; border-radius: 6px;">
                                    <?= htmlspecialchars($surat['no_urut']) ?>
                                </span>
                                <h3 style="margin: 0; font-size: 16px; color: #0f172a; font-weight: 700;">
                                    No. Surat: <?= htmlspecialchars($surat['no_surat']) ?>
                                </h3>
                            </div>
                            <div style="color: #64748b; font-size: 13px;">
                                📍 <strong>Asal:</strong> <?= htmlspecialchars($surat['asal']) ?> &bull; 🗓️ <strong>Tgl Terima:</strong> <?= date('d M Y', strtotime($surat['tgl_terima'])) ?>
                            </div>
                        </div>

                        <div style="display: flex; align-items: center; gap: 8px;">
                            <?php if (!empty($surat['file_url']) && file_exists(__DIR__ . '/../' . $surat['file_url'])): ?>
                                <a href="<?= htmlspecialchars($surat['file_url']) ?>" target="_blank" class="btn btn-sm btn-secondary" style="display: inline-flex; align-items: center; gap: 6px; font-size: 12px;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg>
                                    Berkas Surat
                                </a>
                            <?php endif; ?>

                            <button type="button" class="btn btn-sm btn-primary" onclick='openDetailDisposisiModal(<?= json_encode($surat) ?>)' style="display: inline-flex; align-items: center; gap: 6px; font-size: 12px;">
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                Lihat / Input Disposisi
                            </button>
                        </div>
                    </div>

                    <!-- PERIHAL & KETERANGAN -->
                    <div style="margin-bottom: 16px;">
                        <div style="font-weight: 600; color: #1e293b; font-size: 15px; margin-bottom: 4px;">
                            <?= htmlspecialchars($surat['perihal']) ?>
                        </div>
                        <?php if (!empty($surat['keterangan'])): ?>
                            <div style="font-size: 13px; color: #64748b;">
                                💬 <?= htmlspecialchars($surat['keterangan']) ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- PROGRESS DISPOSISI 3 LEVEL -->
                    <div style="background: #f8fafc; border-radius: 12px; padding: 14px; border: 1px solid #f1f5f9;">
                        <div style="font-size: 12px; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 10px; display: flex; justify-content: space-between; align-items: center;">
                            <span>Status Disposisi Bertingkat (Paralel)</span>
                            <span style="color: var(--primary-color); font-weight: 800; text-transform: none;">
                                <?= $surat['total_disposisi'] ?> / 3 Selesai
                            </span>
                        </div>

                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px;">
                            <?php for ($l = 1; $l <= 3; $l++): ?>
                                <?php 
                                $ldat = $surat['levels'][$l] ?? null; 
                                $is_my_level = ($ldat && $ldat['nik'] === $nik_user);
                                $is_done = ($ldat && $ldat['status_disposisi'] === 'Sudah Disposisi');
                                ?>
                                <div style="background: #fff; border: 1px solid <?= $is_my_level ? 'var(--primary-color)' : '#e2e8f0' ?>; border-radius: 10px; padding: 10px 12px; position: relative;">
                                    
                                    <?php if ($is_my_level): ?>
                                        <div style="position: absolute; top: -8px; right: 10px; background: var(--primary-color); color: #fff; font-size: 9px; font-weight: 800; padding: 2px 6px; border-radius: 4px; text-transform: uppercase;">
                                            Tugas Anda
                                        </div>
                                    <?php endif; ?>

                                    <div style="font-weight: 700; font-size: 12px; color: #334155; margin-bottom: 2px;">
                                        Level <?= $l ?>
                                    </div>
                                    <div style="font-size: 13px; font-weight: 600; color: #0f172a; margin-bottom: 4px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?= htmlspecialchars($ldat['nama_pegawai'] ?? '-') ?>">
                                        <?= htmlspecialchars($ldat['nama_pegawai'] ?? 'Belum Ditunjuk') ?>
                                    </div>

                                    <div style="display: flex; align-items: center; justify-content: space-between;">
                                        <span style="display: inline-block; font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 6px; background: <?= $is_done ? '#dcfce7' : '#fef3c7' ?>; color: <?= $is_done ? '#15803d' : '#b45309' ?>;">
                                            <?= $is_done ? '✓ Sudah Disposisi' : '⏳ Menunggu' ?>
                                        </span>

                                        <?php if ($is_done && !empty($ldat['tgl_disposisi'])): ?>
                                            <span style="font-size: 10px; color: #94a3b8;">
                                                <?= date('d/m/y H:i', strtotime($ldat['tgl_disposisi'])) ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endfor; ?>
                        </div>
                    </div>

                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- ========================================================================= -->
<!-- MODAL 1: INPUT SURAT MASUK BARU + PENENTUAN 3 LEVEL                      -->
<!-- ========================================================================= -->
<div id="addSuratModal" class="modal-overlay" onclick="closeModal('addSuratModal')">
    <div class="modal-content" onclick="event.stopPropagation()" style="max-width: 680px;">
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="simpan_surat">
            <div class="modal-header">
                <h3>Input Surat Masuk Baru</h3>
                <button type="button" class="btn-close" onclick="closeModal('addSuratModal')">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                </button>
            </div>

            <div class="modal-body" style="display: flex; flex-direction: column; gap: 14px;">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                    <div>
                        <label class="form-label">Nomor Surat resmi *</label>
                        <input type="text" name="no_surat" class="form-control" placeholder="Contoh: 005/RS/VII/2026" required>
                    </div>
                    <div>
                        <label class="form-label">Asal Pengirim *</label>
                        <input type="text" name="asal" class="form-control" placeholder="Contoh: Dinas Kesehatan" required>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px;">
                    <div>
                        <label class="form-label">Tujuan</label>
                        <input type="text" name="tujuan" class="form-control" value="RSUD PRINGSEWU">
                    </div>
                    <div>
                        <label class="form-label">Tanggal Surat</label>
                        <input type="date" name="tgl_surat" class="form-control" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div>
                        <label class="form-label">Tanggal Terima</label>
                        <input type="date" name="tgl_terima" class="form-control" value="<?= date('Y-m-d') ?>" required>
                    </div>
                </div>

                <div>
                    <label class="form-label">Perihal / Subjek Surat *</label>
                    <input type="text" name="perihal" class="form-control" placeholder="Ringkasan perihal surat..." required>
                </div>

                <div>
                    <label class="form-label">Keterangan Tambahan</label>
                    <textarea name="keterangan" class="form-control" rows="2" placeholder="Catatan khusus jika ada..."></textarea>
                </div>

                <div>
                    <label class="form-label">Upload Berkas Surat (PDF / Foto)</label>
                    <input type="file" name="file_surat" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
                </div>

                <!-- ALOKASI 3 LEVEL DISPOSISI -->
                <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 12px; padding: 16px; margin-top: 6px;">
                    <div style="font-weight: 700; color: #166534; font-size: 14px; margin-bottom: 12px; display: flex; align-items: center; gap: 8px;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><polyline points="16 11 18 13 22 9"></polyline></svg>
                        Penunjukan Penerima Disposisi 3 Level
                    </div>

                    <div style="display: flex; flex-direction: column; gap: 12px;">
                        <div>
                            <label class="form-label" style="color: #15803d; font-weight: 700;">Level 1 (misal: Kepala Bagian) *</label>
                            <select name="level1_nik" class="form-control" required style="background: #fff;">
                                <option value="">-- Pilih Pegawai Level 1 --</option>
                                <?php foreach ($pegawai_list as $p): ?>
                                    <option value="<?= $p['nik'] ?>"><?= htmlspecialchars($p['nama']) ?> (<?= htmlspecialchars($p['jbtn'] ?: 'Pegawai') ?> - NIK: <?= $p['nik'] ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="form-label" style="color: #15803d; font-weight: 700;">Level 2 (misal: Wakil Direktur) *</label>
                            <select name="level2_nik" class="form-control" required style="background: #fff;">
                                <option value="">-- Pilih Pegawai Level 2 --</option>
                                <?php foreach ($pegawai_list as $p): ?>
                                    <option value="<?= $p['nik'] ?>"><?= htmlspecialchars($p['nama']) ?> (<?= htmlspecialchars($p['jbtn'] ?: 'Pegawai') ?> - NIK: <?= $p['nik'] ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="form-label" style="color: #15803d; font-weight: 700;">Level 3 (misal: Direktur) *</label>
                            <select name="level3_nik" class="form-control" required style="background: #fff;">
                                <option value="">-- Pilih Pegawai Level 3 --</option>
                                <?php foreach ($pegawai_list as $p): ?>
                                    <option value="<?= $p['nik'] ?>"><?= htmlspecialchars($p['nama']) ?> (<?= htmlspecialchars($p['jbtn'] ?: 'Pegawai') ?> - NIK: <?= $p['nik'] ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('addSuratModal')">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan & Dialokasikan</button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL 2: DETAIL SURAT & INPUT DISPOSISI PER USER LEVEL                    -->
<!-- ========================================================================= -->
<div id="detailDisposisiModal" class="modal-overlay" onclick="closeModal('detailDisposisiModal')">
    <div class="modal-content" onclick="event.stopPropagation()" style="max-width: 760px; max-height: 90vh; overflow-y: auto;">
        <div class="modal-header">
            <h3 id="det_no_surat_title">Detail & Disposisi Surat</h3>
            <button type="button" class="btn-close" onclick="closeModal('detailDisposisiModal')">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
            </button>
        </div>

        <div class="modal-body">
            <!-- HEAD INFO SURAT -->
            <div style="background: #f8fafc; border-radius: 12px; padding: 16px; border: 1px solid #e2e8f0; margin-bottom: 20px;">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; font-size: 13px; margin-bottom: 10px;">
                    <div><strong>No. Urut:</strong> <span id="det_no_urut"></span></div>
                    <div><strong>No. Surat:</strong> <span id="det_no_surat"></span></div>
                    <div><strong>Asal Surat:</strong> <span id="det_asal"></span></div>
                    <div><strong>Tgl Terima:</strong> <span id="det_tgl_terima"></span></div>
                </div>
                <div style="font-size: 14px; color: #1e293b;">
                    <strong>Perihal:</strong> <span id="det_perihal"></span>
                </div>
                <div id="det_file_container" style="margin-top: 10px;"></div>
            </div>

            <!-- TABEL HISTORI 3 LEVEL -->
            <h4 style="margin-bottom: 12px; font-size: 15px; color: #334155; display: flex; align-items: center; gap: 8px;">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"></line><line x1="8" y1="12" x2="21" y2="12"></line><line x1="8" y1="18" x2="21" y2="18"></line><line x1="3" y1="6" x2="3.01" y2="6"></line><line x1="3" y1="12" x2="3.01" y2="12"></line><line x1="3" y1="18" x2="3.01" y2="18"></line></svg>
                Status Disposisi 3 Level
            </h4>
            
            <div id="det_levels_container" style="display: flex; flex-direction: column; gap: 12px; margin-bottom: 24px;">
                <!-- Filled via JS -->
            </div>

            <!-- FORM INPUT DISPOSISI SAYA (JIKA USER MERUPAKAN POPULATOR LEVEL INI) -->
            <div id="det_form_input_container" style="display: none; background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 14px; padding: 18px;">
                <h4 style="margin-bottom: 12px; font-size: 15px; color: #0369a1; display: flex; align-items: center; gap: 8px;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                    Input / Ubah Disposisi Anda (<span id="det_my_level_title"></span>)
                </h4>

                <form method="POST">
                    <input type="hidden" name="action" value="simpan_disposisi">
                    <input type="hidden" id="form_no_urut" name="no_urut">
                    <input type="hidden" id="form_level" name="level">

                    <div style="display: flex; flex-direction: column; gap: 12px;">
                        <div>
                            <label class="form-label" style="color: #0369a1; font-weight: 700;">Isi Disposisi / Instruksi *</label>
                            <textarea id="form_isi_disposisi" name="isi_disposisi" class="form-control" rows="3" placeholder="Masukkan instruksi atau tanggapan disposisi..." required style="background: #fff;"></textarea>
                        </div>

                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                            <div>
                                <label class="form-label" style="color: #0369a1;">Harap / Tindakan</label>
                                <input type="text" id="form_harap" name="harap" class="form-control" placeholder="Contoh: Proses Segera / Laporkan" style="background: #fff;">
                            </div>
                            <div>
                                <label class="form-label" style="color: #0369a1;">Pengesahan / Setujui</label>
                                <select id="form_pengesahan" name="pengesahan" class="form-control" style="background: #fff;">
                                    <option value="true">Disetujui (True)</option>
                                    <option value="false">Tidak (False)</option>
                                </select>
                            </div>
                        </div>

                        <div>
                            <label class="form-label" style="color: #0369a1;">Catatan Tambahan</label>
                            <input type="text" id="form_catatan" name="catatan" class="form-control" placeholder="Catatan internal..." style="background: #fff;">
                        </div>

                        <button type="submit" class="btn btn-primary" style="align-self: flex-end; margin-top: 4px;">
                            Simpan Disposisi
                        </button>
                    </div>
                </form>
            </div>

        </div>
    </div>
</div>

<script>
const LOGGED_NIK = <?= json_encode($nik_user) ?>;
const IS_ADMIN   = <?= json_encode($is_admin) ?>;

function openDetailDisposisiModal(s) {
    document.getElementById('det_no_surat_title').innerText = "Detail Surat: " + s.no_surat;
    document.getElementById('det_no_urut').innerText       = s.no_urut;
    document.getElementById('det_no_surat').innerText      = s.no_surat;
    document.getElementById('det_asal').innerText          = s.asal;
    document.getElementById('det_tgl_terima').innerText    = s.tgl_terima;
    document.getElementById('det_perihal').innerText       = s.perihal;

    // File Preview Button
    const fileContainer = document.getElementById('det_file_container');
    if (s.file_url) {
        fileContainer.innerHTML = `<a href="${s.file_url}" target="_blank" class="btn btn-sm btn-secondary" style="display:inline-flex; align-items:center; gap:6px;">📄 Lihat Berkas Asli (PDF/Gambar)</a>`;
    } else {
        fileContainer.innerHTML = `<span style="font-size:12px; color:#94a3b8;">(Tidak ada file terlampir)</span>`;
    }

    // Render 3 Level Disposisi Info
    const lvlContainer = document.getElementById('det_levels_container');
    lvlContainer.innerHTML = '';

    let myAssignedLevel = 0;
    let myExistingData = null;

    for (let l = 1; l <= 3; l++) {
        const ldat = (s.levels && s.levels[l]) ? s.levels[l] : null;
        const isDone = (ldat && ldat.status_disposisi === 'Sudah Disposisi');
        const isUserMe = (ldat && ldat.nik === LOGGED_NIK);

        if (isUserMe) {
            myAssignedLevel = l;
            myExistingData = ldat;
        }

        let cardHtml = `
            <div style="background: ${isUserMe ? '#f0fdf4' : '#fff'}; border: 1px solid ${isUserMe ? '#86efac' : '#e2e8f0'}; border-radius: 12px; padding: 14px;">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 6px;">
                    <div>
                        <span style="font-size: 11px; font-weight: 800; text-transform: uppercase; color: #64748b;">Level ${l}</span> &bull; 
                        <strong style="color: #0f172a;">${ldat ? ldat.nama_pegawai : 'Belum Ditunjuk'}</strong> 
                        <span style="font-size: 12px; color: #64748b;">(${ldat ? (ldat.jbtn || 'Pegawai') : ''})</span>
                    </div>
                    <span style="font-size: 11px; font-weight: 700; padding: 3px 10px; border-radius: 20px; background: ${isDone ? '#dcfce7' : '#fef3c7'}; color: ${isDone ? '#15803d' : '#b45309'};">
                        ${isDone ? '✓ Sudah Disposisi' : '⏳ Menunggu'}
                    </span>
                </div>
        `;

        if (isDone) {
            cardHtml += `
                <div style="font-size: 13px; color: #1e293b; margin-top: 6px; padding: 8px 12px; background: rgba(255,255,255,0.7); border-radius: 8px; border-left: 3px solid #22c55e;">
                    <div><strong>Isi:</strong> ${ldat.isi_disposisi || '-'}</div>
                    ${ldat.harap ? `<div style="font-size:12px; color:#475569; margin-top:2px;"><strong>Harap:</strong> ${ldat.harap}</div>` : ''}
                    ${ldat.catatan ? `<div style="font-size:12px; color:#475569; margin-top:2px;"><strong>Catatan:</strong> ${ldat.catatan}</div>` : ''}
                    <div style="font-size: 10px; color: #94a3b8; margin-top: 4px;">📅 Disetujui pada ${ldat.tgl_disposisi || '-'}</div>
                </div>
            `;
        } else {
            cardHtml += `<div style="font-size: 12px; color: #94a3b8; font-style: italic;">Belum ada isi disposisi.</div>`;
        }

        cardHtml += `</div>`;
        lvlContainer.innerHTML += cardHtml;
    }

    // FORM INPUT UNTUK USER JIKA APPLICABLE (OR ADMIN CAN CHOOSE LEVEL)
    const formContainer = document.getElementById('det_form_input_container');
    if (myAssignedLevel > 0 || IS_ADMIN) {
        const activeLevel = myAssignedLevel > 0 ? myAssignedLevel : 1;
        document.getElementById('det_my_level_title').innerText = "Level " + activeLevel;
        document.getElementById('form_no_urut').value           = s.no_urut;
        document.getElementById('form_level').value             = activeLevel;
        
        if (myExistingData) {
            document.getElementById('form_isi_disposisi').value = myExistingData.isi_disposisi || '';
            document.getElementById('form_harap').value         = myExistingData.harap || '';
            document.getElementById('form_catatan').value       = myExistingData.catatan || '';
            document.getElementById('form_pengesahan').value     = myExistingData.pengesahan || 'true';
        } else {
            document.getElementById('form_isi_disposisi').value = '';
            document.getElementById('form_harap').value         = '';
            document.getElementById('form_catatan').value       = '';
            document.getElementById('form_pengesahan').value     = 'true';
        }
        formContainer.style.display = 'block';
    } else {
        formContainer.style.display = 'none';
    }

    openModal('detailDisposisiModal');
}
</script>
