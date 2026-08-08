<?php
defined('host') or die('Akses langsung tidak diizinkan.');

// Sesi dan Hak Akses
$user_nik   = $_SESSION['username'] ?? '';
$is_admin   = isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true;

$success_msg = '';
$error_msg   = '';

// Filter Tahun & Bulan (Default: Bulan & Tahun saat ini)
$tahun = isset($_GET['tahun']) ? (int)$_GET['tahun'] : (int)date('Y');
$bulan = isset($_GET['bulan']) ? (int)$_GET['bulan'] : (int)date('n');

if ($tahun < 2000 || $tahun > 2099) $tahun = (int)date('Y');
if ($bulan < 1 || $bulan > 12) $bulan = (int)date('n');

// Hitung jumlah hari pada bulan yang dipilih
$jumlah_hari = cal_days_in_month(CAL_GREGORIAN, $bulan, $tahun);

// Hitung Status Kunci Jadwal (Draft vs Final/Locked)
$now_year       = (int)date('Y');
$now_month      = (int)date('n');
$current_period  = ($now_year * 12) + $now_month;
$selected_period = ($tahun * 12) + $bulan;

// Jadwal berstatus terkunci jika periode jadwal <= periode bulan berjalan, KECUALI jika pengguna adalah Admin Utama
$is_period_locked = ($selected_period <= $current_period);
$is_locked        = $is_period_locked && !$is_admin;

// Penanganan Pilihan Atasan untuk Admin
$selected_atasan_nik = $user_nik;
$list_atasan = [];

if ($is_admin) {
    // Ambil daftar semua atasan yang memiliki bawahan
    $q_atasan = "SELECT DISTINCT ap.nik_atasan, p.nama AS nama_atasan 
                 FROM atasan_pegawai ap
                 INNER JOIN pegawai p ON ap.nik_atasan = p.nik
                 ORDER BY p.nama ASC";
    $res_atasan = $koneksi->query($q_atasan);
    if ($res_atasan) {
        while ($row_a = $res_atasan->fetch_assoc()) {
            $list_atasan[] = $row_a;
        }
    }
    
    if (isset($_GET['nik_atasan']) && !empty($_GET['nik_atasan'])) {
        $selected_atasan_nik = trim($_GET['nik_atasan']);
    } elseif (!empty($list_atasan)) {
        // Jika admin belum memilih, default ke atasan pertama di list (atau dirinya sendiri jika ada)
        $selected_atasan_nik = $list_atasan[0]['nik_atasan'];
        foreach ($list_atasan as $at) {
            if ($at['nik_atasan'] === $user_nik) {
                $selected_atasan_nik = $user_nik;
                break;
            }
        }
    }
}

// Ambil Nama Atasan yang dipilih
$nama_atasan_aktif = '-';
$q_name = $koneksi->prepare("SELECT nama FROM pegawai WHERE nik = ? LIMIT 1");
if ($q_name) {
    $q_name->bind_param("s", $selected_atasan_nik);
    $q_name->execute();
    $res_n = $q_name->get_result();
    if ($row_n = $res_n->fetch_assoc()) {
        $nama_atasan_aktif = $row_n['nama'];
    }
    $q_name->close();
}

// Ambil Master Shift dari tabel jam_masuk
$valid_shifts = [];
$master_shifts = [];
$q_shift = $koneksi->query("SELECT shift, jam_masuk, jam_pulang FROM jam_masuk WHERE shift IS NOT NULL AND shift != '' ORDER BY shift ASC");
if ($q_shift) {
    while ($rs = $q_shift->fetch_assoc()) {
        $valid_shifts[] = $rs['shift'];
        $master_shifts[] = $rs;
    }
}
// Tambahkan fallback shift standar jika jam_masuk kosong
if (empty($valid_shifts)) {
    $valid_shifts = ['Pagi', 'Pagi2', 'Siang', 'Siang2', 'Malam', 'Malam2', 'Midle Pagi1', 'Midle Siang1', 'Midle Malam1'];
    $fallback_hours = [
        'Pagi' => ['07:00:00', '14:00:00'],
        'Pagi2' => ['08:00:00', '15:00:00'],
        'Siang' => ['14:00:00', '21:00:00'],
        'Siang2' => ['15:00:00', '22:00:00'],
        'Malam' => ['21:00:00', '07:00:00'],
        'Malam2' => ['22:00:00', '08:00:00'],
        'Midle Pagi1' => ['09:00:00', '16:00:00'],
        'Midle Siang1' => ['11:00:00', '18:00:00'],
        'Midle Malam1' => ['23:00:00', '06:00:00']
    ];
    foreach ($valid_shifts as $vs) {
        $hours = $fallback_hours[$vs] ?? ['00:00:00', '00:00:00'];
        $master_shifts[] = [
            'shift' => $vs,
            'jam_masuk' => $hours[0],
            'jam_pulang' => $hours[1]
        ];
    }
}


// Ambil Daftar Bawahan dari atasan yang dipilih
$bawahan_list = [];
$bawahan_ids = [];
$q_bawahan = $koneksi->prepare("
    SELECT p.id, p.nik, p.nama, p.jbtn 
    FROM atasan_pegawai ap
    INNER JOIN pegawai p ON ap.nik = p.nik
    WHERE ap.nik_atasan = ? AND p.stts_aktif = 'AKTIF'
    ORDER BY p.nama ASC
");
if ($q_bawahan) {
    $q_bawahan->bind_param("s", $selected_atasan_nik);
    $q_bawahan->execute();
    $res_bawahan = $q_bawahan->get_result();
    while ($rb = $res_bawahan->fetch_assoc()) {
        $bawahan_list[] = $rb;
        $bawahan_ids[]  = (int)$rb['id'];
    }
    $q_bawahan->close();
}

// ── PROSES POST: SIMPAN JADWAL MATRIX ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'simpan_jadwal') {
    $post_tahun = (int)($_POST['tahun'] ?? $tahun);
    $post_bulan = (int)($_POST['bulan'] ?? $bulan);
    $jadwal_data = $_POST['jadwal'] ?? [];

    if ($is_locked) {
        $error_msg = "Gagal menyimpan: Jadwal untuk periode " . $nama_bulan_indo[$post_bulan] . " $post_tahun telah TERKUNCI (FINAL) dan tidak dapat diubah lagi.";
    } elseif (empty($bawahan_ids)) {
        $error_msg = "Tidak ada pegawai bawahan untuk disimpan jadwalnya.";
    } else {
        $koneksi->begin_transaction();
        try {
            $saved_count = 0;
            
            // Kolom h1 - h31
            $cols = [];
            for ($d = 1; $d <= 31; $d++) {
                $cols[] = "h" . $d;
            }
            $cols_str = implode(", ", $cols);
            
            // Values placeholder
            $val_placeholders = implode(", ", array_fill(0, 31, "?"));
            
            // ON DUPLICATE KEY UPDATE clause
            $update_assigns = [];
            foreach ($cols as $col) {
                $update_assigns[] = "$col = VALUES($col)";
            }
            $update_str = implode(", ", $update_assigns);

            $sql_upsert = "INSERT INTO jadwal_pegawai (id, tahun, bulan, $cols_str) 
                           VALUES (?, ?, ?, $val_placeholders) 
                           ON DUPLICATE KEY UPDATE $update_str";
            
            $stmt_upsert = $koneksi->prepare($sql_upsert);

            if (!$stmt_upsert) {
                throw new Exception("Gagal menyiapkan query: " . $koneksi->error);
            }

            foreach ($bawahan_list as $b) {
                $b_id = (int)$b['id'];
                $shift_values = [];

                for ($d = 1; $d <= 31; $d++) {
                    // Jika hari > jumlah hari bulan tersebut, isi string kosong ''
                    if ($d > $jumlah_hari) {
                        $shift_values[] = '';
                    } else {
                        $val = trim($jadwal_data[$b_id]['h' . $d] ?? '');
                        // Validasi agar hanya shift valid atau kosong yang masuk
                        if ($val !== '' && !in_array($val, $valid_shifts)) {
                            $val = '';
                        }
                        $shift_values[] = $val;
                    }
                }

                // Bind parameter (id int, tahun int, bulan int, h1..h31 string)
                $types = "iii" . str_repeat("s", 31);
                $bind_params = array_merge([$b_id, $post_tahun, $post_bulan], $shift_values);

                $stmt_upsert->bind_param($types, ...$bind_params);
                if ($stmt_upsert->execute()) {
                    $saved_count++;
                }
            }

            $stmt_upsert->close();
            $koneksi->commit();
            $success_msg = "Berhasil menyimpan jadwal untuk $saved_count pegawai (Periode: " . date('F', mktime(0,0,0,$post_bulan,10)) . " $post_tahun).";
        } catch (Exception $e) {
            $koneksi->rollback();
            $error_msg = "Gagal menyimpan jadwal: " . $e->getMessage();
        }
    }
}

// ── PROSES POST: IMPORT EXCEL (CSV / XLSX PASTE) ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'import_csv') {
    if ($is_locked) {
        $error_msg = "Gagal mengimpor: Jadwal untuk periode ini telah TERKUNCI (FINAL) dan tidak dapat diubah lagi.";
    } elseif (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
        $file_tmp = $_FILES['csv_file']['tmp_name'];
        $handle = fopen($file_tmp, 'r');
        if ($handle !== false) {
            $row_idx = 0;
            $imported_count = 0;
            $koneksi->begin_transaction();
            try {
                // Header check
                $header = fgetcsv($handle, 2000, ",");
                if (!$header || count($header) < 4) {
                    // Coba separator titik koma ;
                    rewind($handle);
                    $header = fgetcsv($handle, 2000, ";");
                }

                while (($data = fgetcsv($handle, 2000, ",")) !== false || ($data = fgetcsv($handle, 2000, ";")) !== false) {
                    if (count($data) < 4) continue;
                    
                    $peg_id = (int)trim($data[0]);
                    if ($peg_id <= 0) continue;

                    // Pastikan peg_id termasuk bawahan dari atasan ini
                    if (!in_array($peg_id, $bawahan_ids)) continue;

                    $shift_values = [];
                    for ($d = 1; $d <= 31; $d++) {
                        $col_idx = 3 + ($d - 1); // h1 ada di index 3 setelah id, tahun, bulan
                        $val = isset($data[$col_idx]) ? trim($data[$col_idx]) : '';
                        if ($val !== '' && !in_array($val, $valid_shifts)) {
                            $val = '';
                        }
                        $shift_values[] = $val;
                    }

                    $cols = [];
                    for ($d = 1; $d <= 31; $d++) $cols[] = "h" . $d;
                    $cols_str = implode(", ", $cols);
                    $val_placeholders = implode(", ", array_fill(0, 31, "?"));
                    $update_assigns = [];
                    foreach ($cols as $col) $update_assigns[] = "$col = VALUES($col)";
                    $update_str = implode(", ", $update_assigns);

                    $sql = "INSERT INTO jadwal_pegawai (id, tahun, bulan, $cols_str) 
                            VALUES (?, ?, ?, $val_placeholders) 
                            ON DUPLICATE KEY UPDATE $update_str";
                    $stmt = $koneksi->prepare($sql);
                    if ($stmt) {
                        $types = "iii" . str_repeat("s", 31);
                        $bind_params = array_merge([$peg_id, $tahun, $bulan], $shift_values);
                        $stmt->bind_param($types, ...$bind_params);
                        if ($stmt->execute()) {
                            $imported_count++;
                        }
                        $stmt->close();
                    }
                }
                fclose($handle);
                $koneksi->commit();
                $success_msg = "Berhasil mengimpor jadwal dari file Excel/CSV untuk $imported_count pegawai.";
            } catch (Exception $ex) {
                fclose($handle);
                $koneksi->rollback();
                $error_msg = "Gagal mengimpor file: " . $ex->getMessage();
            }
        } else {
            $error_msg = "Gagal membaca file impor.";
        }
    } else {
        $error_msg = "File impor tidak ditemukan atau terjadi kesalahan unggah.";
    }
}

// ── PROSES GET: EXPORT EXCEL (CSV DOWNLOAD) ────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'export_excel') {
    // Clear buffer
    if (ob_get_level()) ob_end_clean();
    
    $filename = "Jadwal_Bawahan_" . str_replace(' ', '_', $nama_atasan_aktif) . "_{$tahun}_{$bulan}.csv";
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    // Write UTF-8 BOM agar Microsoft Excel membaca karakter dengan benar
    fputs($output, "\xEF\xBB\xBF");

    // Header Excel
    $header_cols = ['id', 'tahun', 'bulan', 'nik', 'nama_pegawai'];
    for ($d = 1; $d <= 31; $d++) {
        $header_cols[] = "h" . $d;
    }
    fputcsv($output, $header_cols);

    // Ambil data jadwal saat ini
    $existing_jadwal = [];
    if (!empty($bawahan_ids)) {
        $in_ids = implode(',', $bawahan_ids);
        $q_ex = "SELECT * FROM jadwal_pegawai WHERE tahun = $tahun AND bulan = $bulan AND id IN ($in_ids)";
        $res_ex = $koneksi->query($q_ex);
        if ($res_ex) {
            while ($rx = $res_ex->fetch_assoc()) {
                $existing_jadwal[(int)$rx['id']] = $rx;
            }
        }
    }

    foreach ($bawahan_list as $b) {
        $b_id = (int)$b['id'];
        $row_data = [$b_id, $tahun, $bulan, $b['nik'], $b['nama']];
        $j_row = $existing_jadwal[$b_id] ?? [];
        for ($d = 1; $d <= 31; $d++) {
            $row_data[] = $j_row['h' . $d] ?? '';
        }
        fputcsv($output, $row_data);
    }

    fclose($output);
    exit;
}

// ── LOAD JADWAL SAAT INI UNTUK DITAMPILKAN DI MATRIX WEB ──────────────────────
$jadwal_pegawai_map = [];
if (!empty($bawahan_ids)) {
    $in_ids = implode(',', $bawahan_ids);
    $q_load = "SELECT * FROM jadwal_pegawai WHERE tahun = $tahun AND bulan = $bulan AND id IN ($in_ids)";
    $res_load = $koneksi->query($q_load);
    if ($res_load) {
        while ($rl = $res_load->fetch_assoc()) {
            $jadwal_pegawai_map[(int)$rl['id']] = $rl;
        }
    }
}

// Nama-nama bulan Indonesia
$nama_bulan_indo = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
    5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
    9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
];
?>

<style>
    .jadwal-container {
        padding: 20px;
        background: #f8fafc;
        min-height: calc(100vh - 80px);
    }
    .card-custom {
        background: #ffffff;
        border-radius: 12px;
        box-shadow: 0 4px 16px rgba(0, 0, 0, 0.04);
        border: 1px solid #e2e8f0;
        padding: 24px;
        margin-bottom: 24px;
    }
    .filter-bar {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        margin-bottom: 20px;
    }
    .filter-group {
        display: flex;
        align-items: center;
        gap: 12px;
        flex-wrap: wrap;
    }
    .form-control-sm-custom {
        padding: 8px 12px;
        font-size: 13.5px;
        border-radius: 8px;
        border: 1px solid #cbd5e1;
        outline: none;
        transition: all 0.2s ease;
        background: #fff;
    }
    .form-control-sm-custom:focus {
        border-color: #4f46e5;
        box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
    }
    .btn-action {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 8px 16px;
        font-size: 13.5px;
        font-weight: 600;
        border-radius: 8px;
        border: none;
        cursor: pointer;
        transition: all 0.2s ease;
        text-decoration: none;
    }
    .btn-primary-custom {
        background: #4f46e5;
        color: #ffffff;
    }
    .btn-primary-custom:hover {
        background: #4338ca;
        color: #ffffff;
    }
    .btn-secondary-custom {
        background: #f1f5f9;
        color: #475569;
        border: 1px solid #cbd5e1;
    }
    .btn-secondary-custom:hover {
        background: #e2e8f0;
        color: #1e293b;
    }
    .btn-success-custom {
        background: #10b981;
        color: #ffffff;
    }
    .btn-success-custom:hover {
        background: #059669;
    }
    .btn-warning-custom {
        background: #f59e0b;
        color: #ffffff;
    }
    .btn-warning-custom:hover {
        background: #d97706;
    }
    .table-responsive-custom {
        overflow-x: auto;
        border-radius: 8px;
        border: 1px solid #e2e8f0;
        max-height: 70vh;
    }
    .shift-ref-scroll {
        position: relative;
        width: 100%;
        max-width: 100%;
        overflow-x: auto;
        overflow-y: visible;
        -webkit-overflow-scrolling: touch;
        scrollbar-width: thin;
        margin-top: 12px;
        padding-bottom: 6px;
    }
    .shift-ref-row {
        display: inline-flex;
        flex-wrap: nowrap;
        gap: 10px;
        padding: 4px;
        min-width: 100%;
    }
    .table-matrix {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
        font-size: 12px;
    }
    .table-matrix th {
        background: #1e293b;
        color: #f8fafc;
        padding: 8px 4px;
        text-align: center;
        font-weight: 600;
        position: sticky;
        top: 0;
        z-index: 10;
        border-bottom: 2px solid #0f172a;
        white-space: nowrap;
    }
    .table-matrix th.sticky-col, .table-matrix td.sticky-col {
        position: sticky;
        left: 0;
        z-index: 11;
        background: #ffffff;
    }
    .table-matrix th.sticky-col {
        background: #1e293b;
        z-index: 12;
    }
    .table-matrix td {
        padding: 4px 3px;
        border-bottom: 1px solid #e2e8f0;
        border-right: 1px solid #f1f5f9;
        text-align: center;
        background: #ffffff;
    }
    .table-matrix tr:nth-child(even) td {
        background: #f8fafc;
    }
    .table-matrix tr:nth-child(even) td.sticky-col {
        background: #f8fafc;
    }
    .table-matrix td.weekend-cell {
        background: #fff7ed !important;
    }
    .table-matrix th.weekend-header {
        background: #c2410c !important;
    }
    .shift-select {
        width: 100%;
        padding: 4px 2px;
        font-size: 11px;
        border-radius: 4px;
        border: 1px solid #cbd5e1;
        background: #fff;
        cursor: pointer;
        text-align: center;
        text-align-last: center;
    }
    .shift-select:focus {
        border-color: #4f46e5;
        outline: none;
    }
    /* Dynamic styling per shift */
    .shift-select[data-shift="Pagi"], .shift-select[data-shift^="Pagi"] { background: #dbeafe; color: #1e40af; font-weight: 600; }
    .shift-select[data-shift="Siang"], .shift-select[data-shift^="Siang"] { background: #fef3c7; color: #92400e; font-weight: 600; }
    .shift-select[data-shift="Malam"], .shift-select[data-shift^="Malam"] { background: #f3e8ff; color: #6b21a8; font-weight: 600; }
    .shift-select[data-shift="Libur"], .shift-select[data-shift="OFF"], .shift-select[data-shift=""] { background: #ffffff; color: #94a3b8; }
    
    .badge-info-custom {
        display: inline-block;
        padding: 4px 10px;
        border-radius: 9999px;
        font-size: 12px;
        font-weight: 600;
        background: #e0e7ff;
        color: #3730a3;
    }
    .badge-success-custom {
        display: inline-block;
        padding: 4px 10px;
        border-radius: 9999px;
        font-size: 12px;
        font-weight: 600;
        background: #dcfce7;
        color: #166534;
    }
    .badge-danger-custom {
        display: inline-block;
        padding: 4px 10px;
        border-radius: 9999px;
        font-size: 12px;
        font-weight: 600;
        background: #fee2e2;
        color: #991b1b;
    }
    .alert-custom {
        padding: 14px 18px;
        border-radius: 8px;
        margin-bottom: 20px;
        font-size: 14px;
        display: flex;
        align-items: center;
        gap: 12px;
    }
    .alert-success { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
    .alert-danger { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
    .alert-info { background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; }
    
    .modal-overlay {
        display: none;
        position: fixed;
        top: 0; left: 0; right: 0; bottom: 0;
        background: rgba(15, 23, 42, 0.6);
        backdrop-filter: blur(4px);
        z-index: 9999;
        align-items: center;
        justify-content: center;
    }
    .modal-box {
        background: #fff;
        padding: 24px;
        border-radius: 12px;
        width: 100%;
        max-width: 480px;
        box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
    }
</style>

<div class="jadwal-container">
    <div class="card-custom">
        <!-- Header Page -->
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 20px;">
            <div>
                <h3 style="margin: 0; color: #0f172a; font-size: 20px; font-weight: 700;">📅 Pengaturan Jadwal Kerja Bawahan</h3>
                <p style="margin: 4px 0 0 0; color: #64748b; font-size: 13.5px;">
                    Atasan Langsung: <strong><?= htmlspecialchars($nama_atasan_aktif) ?></strong> (NIK: <?= htmlspecialchars($selected_atasan_nik) ?>)
                </p>
            </div>
            <div style="display: flex; align-items: center; gap: 8px;">
                <?php if ($is_period_locked): ?>
                    <span class="badge-danger-custom">🔒 STATUS: FINAL / TERKUNCI</span>
                <?php else: ?>
                    <span class="badge-success-custom">✏️ STATUS: DRAFT / OPEN</span>
                <?php endif; ?>

                <span class="badge-info-custom">
                    <?= count($bawahan_list) ?> Pegawai Bawahan
                </span>
            </div>
        </div>

        <!-- Alert Notification -->
        <?php if (!empty($success_msg)): ?>
            <div class="alert-custom alert-success">
                <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                <span><?= htmlspecialchars($success_msg) ?></span>
            </div>
        <?php endif; ?>

        <?php if (!empty($error_msg)): ?>
            <div class="alert-custom alert-danger">
                <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                <span><?= htmlspecialchars($error_msg) ?></span>
            </div>
        <?php endif; ?>

        <!-- Notifikasi Jika Belum Punya Bawahan -->
        <?php if (empty($bawahan_list)): ?>
            <div class="alert-custom alert-info" style="margin-top: 10px;">
                <svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                <div>
                    <strong>Informasi:</strong> Anda belum memiliki bawahan yang terdaftar. Silakan hubungi Admin Utama untuk pengaturan mapping atasan.
                </div>
            </div>
        <?php else: ?>

        <!-- Form Filter & Toolbar -->
        <form method="GET" action="index.php" id="filterForm">
            <input type="hidden" name="page" value="pegawai">
            <input type="hidden" name="sub" value="jadwal">

            <div class="filter-bar">
                <div class="filter-group">
                    <?php if ($is_admin && !empty($list_atasan)): ?>
                        <div style="display:flex; align-items:center; gap:6px;">
                            <label style="font-weight:600; font-size:13px;">Pilih Atasan:</label>
                            <select name="nik_atasan" class="form-control-sm-custom" onchange="document.getElementById('filterForm').submit()">
                                <?php foreach ($list_atasan as $at): ?>
                                    <option value="<?= htmlspecialchars($at['nik_atasan']) ?>" <?= $selected_atasan_nik === $at['nik_atasan'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($at['nama_atasan']) ?> (<?= htmlspecialchars($at['nik_atasan']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>

                    <div style="display:flex; align-items:center; gap:6px;">
                        <label style="font-weight:600; font-size:13px;">Bulan:</label>
                        <select name="bulan" class="form-control-sm-custom" onchange="document.getElementById('filterForm').submit()">
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                                <option value="<?= $m ?>" <?= $m === $bulan ? 'selected' : '' ?>>
                                    <?= $nama_bulan_indo[$m] ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>

                    <div style="display:flex; align-items:center; gap:6px;">
                        <label style="font-weight:600; font-size:13px;">Tahun:</label>
                        <select name="tahun" class="form-control-sm-custom" onchange="document.getElementById('filterForm').submit()">
                            <?php for ($y = date('Y') - 1; $y <= date('Y') + 2; $y++): ?>
                                <option value="<?= $y ?>" <?= $y === $tahun ? 'selected' : '' ?>>
                                    <?= $y ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>

                <div class="filter-group">
                    <?php if (!$is_locked): ?>
                        <button type="button" class="btn-action btn-secondary-custom" onclick="openImportModal()">
                            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path></svg>
                            Upload Excel
                        </button>
                    <?php endif; ?>
                    
                    <a href="index.php?page=pegawai&sub=jadwal&action=export_excel&tahun=<?= $tahun ?>&bulan=<?= $bulan ?>&nik_atasan=<?= urlencode($selected_atasan_nik) ?>" class="btn-action btn-secondary-custom">
                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg>
                        Download Template
                    </a>

                    <?php if (!$is_locked): ?>
                        <button type="button" class="btn-action btn-success-custom" onclick="document.getElementById('jadwalForm').submit()">
                            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                            Simpan Jadwal
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </form>

        <?php if ($is_period_locked): ?>
            <div class="alert-custom alert-danger" style="margin-bottom: 16px;">
                <svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
                <div>
                    <strong>STATUS: FINAL / TERKUNCI.</strong> Periode <?= $nama_bulan_indo[$bulan] ?> <?= $tahun ?> telah memasuki bulan berjalan/lalu sehingga jadwal telah TERKUNCI resmi. 
                    <?php if ($is_admin): ?>
                        <span style="font-weight:600; color:#b91c1c;">(Mode Admin Utama: Kunci dilewati, Anda tetap dapat melakukan pengeditan).</span>
                    <?php else: ?>
                        Perubahan jadwal tidak dapat dilakukan oleh Atasan Langsung. Hubungi Admin Utama jika ada penyesuaian khusus.
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!$is_locked): ?>
        <!-- Quick Bulk Fill Bar -->
        <div style="background: #f1f5f9; padding: 10px 14px; border-radius: 8px; margin-bottom: 16px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;">
            <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                <span style="font-weight: 600; font-size: 12px; color: #475569;">Quick Fill (Sesuai Master Shift):</span>
                <select id="quickFillShiftSelect" class="form-control-sm-custom" style="padding: 4px 8px; font-size: 11px;">
                    <option value="">-- Pilih Shift --</option>
                    <?php foreach ($valid_shifts as $vs): ?>
                        <option value="<?= htmlspecialchars($vs) ?>"><?= htmlspecialchars($vs) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="btn-action btn-primary-custom" style="padding: 4px 10px; font-size: 11px;" onclick="applyQuickFillFromSelect()">Isi Ke Semua Sel</button>
                
                <span style="border-left: 1px solid #cbd5e1; height: 18px; margin: 0 4px;"></span>

                <button type="button" class="btn-action btn-secondary-custom" style="padding: 4px 10px; font-size: 11px;" onclick="applySaturdayOff()">Set Libur Sabtu</button>
                <button type="button" class="btn-action btn-secondary-custom" style="padding: 4px 10px; font-size: 11px;" onclick="applySundayOff()">Set Libur Minggu</button>
                <button type="button" class="btn-action btn-secondary-custom" style="padding: 4px 10px; font-size: 11px;" onclick="applyWeekendOff()">Set Libur Sabtu & Minggu</button>
                <button type="button" class="btn-action btn-secondary-custom" style="padding: 4px 10px; font-size: 11px; color: #ef4444;" onclick="clearAllShifts()">Kosongkan Semua</button>
            </div>
            <div style="font-size: 11px; color: #64748b; font-style: italic;">
                💡 Tips: Anda dapat memblok data dari Excel dan tekan <strong>Ctrl+V</strong> pada tabel untuk menempel jadwal secara instan!
            </div>
        </div>
        <?php endif; ?>

        <!-- Referensi Jam Shift (Collapsible) -->
        <details style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 14px; margin-bottom: 16px;">
            <summary style="font-weight: 600; font-size: 13px; color: #475569; cursor: pointer; user-select: none; outline: none; display: flex; align-items: center; gap: 6px;">
                <span>👁️ Lihat Referensi Waktu &amp; Jam Kerja Shift (Master Shift)</span>
            </summary>
            <div class="shift-ref-scroll">
                <div class="shift-ref-row">
                    <?php foreach ($master_shifts as $ms): ?>
                        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 6px; padding: 8px 12px; font-size: 11px; box-shadow: 0 1px 2px rgba(0,0,0,0.05); flex: 0 0 140px; min-width: 140px; box-sizing: border-box;">
                            <strong style="color: #0f172a; font-size: 12px; display: block; margin-bottom: 4px; border-bottom: 1px solid #f1f5f9; padding-bottom: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                <?= htmlspecialchars($ms['shift']) ?>
                            </strong>
                            <div style="color: #475569; font-family: monospace; font-size: 11px; line-height: 1.4; white-space: nowrap;">
                                Masuk  : <?= date('H:i', strtotime($ms['jam_masuk'])) ?><br>
                                Pulang : <?= date('H:i', strtotime($ms['jam_pulang'])) ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </details>

        <!-- Form & Table Matrix -->
        <form method="POST" action="index.php?page=pegawai&sub=jadwal&tahun=<?= $tahun ?>&bulan=<?= $bulan ?>&nik_atasan=<?= urlencode($selected_atasan_nik) ?>" id="jadwalForm">
            <input type="hidden" name="action" value="simpan_jadwal">
            <input type="hidden" name="tahun" value="<?= $tahun ?>">
            <input type="hidden" name="bulan" value="<?= $bulan ?>">

            <div class="table-responsive-custom" id="matrixContainer">
                <table class="table-matrix" id="matrixTable">
                    <thead>
                        <tr>
                            <th class="sticky-col" style="width: 40px; min-width: 40px;">No</th>
                            <th class="sticky-col" style="min-width: 180px; text-align: left; padding-left: 8px;">Nama Pegawai</th>
                            <?php for ($d = 1; $d <= $jumlah_hari; $d++): 
                                $time_stamp = mktime(0, 0, 0, $bulan, $d, $tahun);
                                $day_of_week = date('N', $time_stamp); // 1 (Senin) s/d 7 (Minggu)
                                $day_name = date('D', $time_stamp);
                                $is_weekend = ($day_of_week == 6 || $day_of_week == 7);
                            ?>
                                <th class="<?= $is_weekend ? 'weekend-header' : '' ?>" style="min-width: 68px;">
                                    <div style="font-size: 10px; opacity: 0.8;"><?= $day_name ?></div>
                                    <div><?= $d ?></div>
                                </th>
                            <?php endfor; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $no = 1;
                        foreach ($bawahan_list as $b): 
                            $b_id = (int)$b['id'];
                            $b_jadwal = $jadwal_pegawai_map[$b_id] ?? [];
                        ?>
                            <tr data-pegawai-id="<?= $b_id ?>">
                                <td class="sticky-col" style="font-weight: 600; color: #64748b;"><?= $no++ ?></td>
                                <td class="sticky-col" style="text-align: left; padding-left: 8px;">
                                    <div style="font-weight: 600; color: #1e293b;"><?= htmlspecialchars($b['nama']) ?></div>
                                    <div style="font-size: 10px; color: #64748b;">NIK: <?= htmlspecialchars($b['nik']) ?></div>
                                </td>
                                <?php for ($d = 1; $d <= $jumlah_hari; $d++): 
                                    $time_stamp = mktime(0, 0, 0, $bulan, $d, $tahun);
                                    $day_of_week = date('N', $time_stamp);
                                    $is_weekend = ($day_of_week == 6 || $day_of_week == 7);
                                    $val = $b_jadwal['h' . $d] ?? '';
                                ?>
                                    <td class="<?= $is_weekend ? 'weekend-cell' : '' ?>" data-day="<?= $d ?>" data-dow="<?= $day_of_week ?>">
                                        <select name="jadwal[<?= $b_id ?>][h<?= $d ?>]" class="shift-select" data-shift="<?= htmlspecialchars($val) ?>" onchange="updateShiftStyle(this)" <?= $is_locked ? 'disabled' : '' ?>>
                                            <option value="" <?= $val === '' ? 'selected' : '' ?> style="color: #94a3b8;">-</option>
                                            <?php foreach ($valid_shifts as $s): ?>
                                                <option value="<?= htmlspecialchars($s) ?>" <?= $val === $s ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($s) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                <?php endfor; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div style="margin-top: 16px; display: flex; justify-content: flex-end;">
                <button type="submit" class="btn-action btn-success-custom" style="padding: 10px 24px; font-size: 14px;">
                    <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                    Simpan Semua Jadwal
                </button>
            </div>
        </form>

        <?php endif; ?>
    </div>
</div>

<!-- Modal Import CSV/Excel -->
<div class="modal-overlay" id="importModal">
    <div class="modal-box">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
            <h4 style="margin: 0; font-size: 16px; color: #0f172a;">Upload File Jadwal Excel / CSV</h4>
            <button type="button" onclick="closeImportModal()" style="background: none; border: none; font-size: 18px; cursor: pointer; color: #94a3b8;">&times;</button>
        </div>
        
        <form method="POST" action="index.php?page=pegawai&sub=jadwal&tahun=<?= $tahun ?>&bulan=<?= $bulan ?>&nik_atasan=<?= urlencode($selected_atasan_nik) ?>" enctype="multipart/form-data">
            <input type="hidden" name="action" value="import_csv">
            
            <p style="font-size: 13px; color: #64748b; margin-bottom: 14px;">
                Unggah file hasil unduhan <strong>Download Template</strong> yang telah diisi kode shift-nya di Microsoft Excel.
            </p>

            <div style="margin-bottom: 20px;">
                <label style="display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px;">Pilih File (.csv / .xlsx):</label>
                <input type="file" name="csv_file" accept=".csv, .txt" required class="form-control-sm-custom" style="width: 100%;">
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn-action btn-secondary-custom" onclick="closeImportModal()">Batal</button>
                <button type="submit" class="btn-action btn-primary-custom">Unggah & Simpan</button>
            </div>
        </form>
    </div>
</div>

<script>
function updateShiftStyle(selectEl) {
    var val = selectEl.value;
    selectEl.setAttribute('data-shift', val);
}

function applyQuickFill(shiftVal) {
    if (!shiftVal) return;
    if (!confirm('Apakah Anda yakin ingin mengisi shift "' + shiftVal + '" ke SELURUH sel bawahan?')) return;
    var selects = document.querySelectorAll('#matrixTable select.shift-select');
    selects.forEach(function(sel) {
        sel.value = shiftVal;
        updateShiftStyle(sel);
    });
}

function applyQuickFillFromSelect() {
    var sel = document.getElementById('quickFillShiftSelect');
    if (!sel || !sel.value) {
        alert('Silakan pilih shift terlebih dahulu!');
        return;
    }
    applyQuickFill(sel.value);
}

function applySaturdayOff() {
    if (!confirm('Apakah Anda yakin ingin mengosongkan/set libur untuk semua hari SABTU?')) return;
    var satTds = document.querySelectorAll('#matrixTable td[data-dow="6"]');
    satTds.forEach(function(td) {
        var sel = td.querySelector('select.shift-select');
        if (sel) {
            sel.value = '';
            updateShiftStyle(sel);
        }
    });
}

function applySundayOff() {
    if (!confirm('Apakah Anda yakin ingin mengosongkan/set libur untuk semua hari MINGGU?')) return;
    var sunTds = document.querySelectorAll('#matrixTable td[data-dow="7"]');
    sunTds.forEach(function(td) {
        var sel = td.querySelector('select.shift-select');
        if (sel) {
            sel.value = '';
            updateShiftStyle(sel);
        }
    });
}

function applyWeekendOff() {
    if (!confirm('Apakah Anda yakin ingin mengosongkan/set libur untuk semua hari SABTU & MINGGU?')) return;
    var weekendTds = document.querySelectorAll('#matrixTable td[data-dow="6"], #matrixTable td[data-dow="7"]');
    weekendTds.forEach(function(td) {
        var sel = td.querySelector('select.shift-select');
        if (sel) {
            sel.value = '';
            updateShiftStyle(sel);
        }
    });
}

function clearAllShifts() {
    if (!confirm('Apakah Anda yakin ingin MENGOSONGKAN SELURUH jadwal bawahan?')) return;
    var selects = document.querySelectorAll('#matrixTable select.shift-select');
    selects.forEach(function(sel) {
        sel.value = '';
        updateShiftStyle(sel);
    });
}

function openImportModal() {
    document.getElementById('importModal').style.display = 'flex';
}

function closeImportModal() {
    document.getElementById('importModal').style.display = 'none';
}

// ── PENANGANAN PASTE DARI EXCEL (Ctrl + V) ───────────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
    var container = document.getElementById('matrixContainer');
    if (!container) return;

    container.addEventListener('paste', function(e) {
        var clipboardData = e.clipboardData || window.clipboardData;
        if (!clipboardData) return;

        var pastedData = clipboardData.getData('Text');
        if (!pastedData) return;

        // Cegah behavior paste default jika fokus di dalam tabel matrix
        var activeEl = document.activeElement;
        if (activeEl && activeEl.closest('#matrixTable')) {
            e.preventDefault();

            var rowsData = pastedData.split(/\r\n|\n|\r/);
            var trs = document.querySelectorAll('#matrixTable tbody tr');
            
            // Cari baris awal paste berdasarkan baris aktif (atau baris pertama)
            var startTr = activeEl.closest('tr');
            var startTrIdx = 0;
            if (startTr) {
                trs.forEach(function(tr, idx) {
                    if (tr === startTr) startTrIdx = idx;
                });
            }

            var activeTd = activeEl.closest('td');
            var startTdIdx = 1; // Default ke kolom hari ke-1
            if (activeTd && activeTd.dataset.day) {
                startTdIdx = parseInt(activeTd.dataset.day);
            }

            rowsData.forEach(function(rowStr, rIdx) {
                if (!rowStr.trim()) return;
                var targetTrIndex = startTrIdx + rIdx;
                if (targetTrIndex >= trs.length) return;

                var targetTr = trs[targetTrIndex];
                var cellValues = rowStr.split('\t');

                cellValues.forEach(function(cVal, cIdx) {
                    var targetDay = startTdIdx + cIdx;
                    var td = targetTr.querySelector('td[data-day="' + targetDay + '"]');
                    if (td) {
                        var sel = td.querySelector('select.shift-select');
                        if (sel) {
                            var cleanVal = cVal.trim();
                            // Cek apakah nilai cocok dengan option di dropdown
                            var foundOption = false;
                            for (var i = 0; i < sel.options.length; i++) {
                                if (sel.options[i].value.toLowerCase() === cleanVal.toLowerCase()) {
                                    sel.value = sel.options[i].value;
                                    foundOption = true;
                                    break;
                                }
                            }
                            if (!foundOption) {
                                sel.value = '';
                            }
                            updateShiftStyle(sel);
                        }
                    }
                });
            });
        }
    });
});
</script>
