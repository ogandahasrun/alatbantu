<?php
defined('host') or die('Akses langsung tidak diizinkan.');

// Ambil NIK dari Session login
$nik = $_SESSION['username'];

// Ambil Nama Pegawai dari NIK
$query_pegawai_info = "SELECT nama FROM pegawai WHERE nik = ? LIMIT 1";
$stmt_peg_info = $koneksi->prepare($query_pegawai_info);
$stmt_peg_info->bind_param("s", $nik);
$stmt_peg_info->execute();
$res_peg_info = $stmt_peg_info->get_result();
$nama_pegawai = "Pegawai";
if ($row_info = $res_peg_info->fetch_assoc()) {
    $nama_pegawai = $row_info['nama'];
}
$stmt_peg_info->close();

$error_msg = '';
$success_msg = '';

// Ambil daftar hari libur nasional dari database
$holidays = [];
$query_holidays = "SELECT tanggal FROM set_hari_libur";
$res_holidays = mysqli_query($koneksi, $query_holidays);
if ($res_holidays) {
    while ($row = mysqli_fetch_assoc($res_holidays)) {
        $holidays[] = $row['tanggal'];
    }
}
$holidays_json = json_encode($holidays);
$holidays_lookup = array_fill_keys($holidays, true);

// Proses Simpan Pengajuan Cuti
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['simpan'])) {
    $tanggal_awal = $_POST['tanggal_awal'] ?? '';
    $tanggal_akhir = $_POST['tanggal_akhir'] ?? '';
    $urgensi = $_POST['urgensi'] ?? '';
    $alamat = $_POST['alamat'] ?? '';
    $kepentingan = $_POST['kepentingan'] ?? '';
    $nik_pj = $_POST['nik_pj'] ?? '';
    
    // Validasi input wajib
    if (empty($tanggal_awal) || empty($tanggal_akhir) || empty($urgensi) || empty($nik_pj)) {
        $error_msg = "Semua kolom wajib diisi!";
    } else {
        $start_ts = strtotime($tanggal_awal);
        $end_ts = strtotime($tanggal_akhir);
        
        if ($end_ts < $start_ts) {
            $error_msg = "Tanggal akhir tidak boleh mendahului tanggal awal!";
        } else {
            $jumlah = 0;
            $current_ts = $start_ts;
            while ($current_ts <= $end_ts) {
                $current_date = date('Y-m-d', $current_ts);
                $day_of_week = (int)date('w', $current_ts); // 0 = Minggu
                
                if ($day_of_week !== 0 && !isset($holidays_lookup[$current_date])) {
                    $jumlah++;
                }
                
                $current_ts = strtotime("+1 day", $current_ts);
            }
            
            // Validasi kuota 12 hari per tahun
            $year = date('Y', $start_ts);
            $query_limit = "SELECT SUM(jumlah) AS total_days FROM pengajuan_cuti WHERE nik = ? AND YEAR(tanggal_awal) = ? AND status != 'Ditolak'";
            $stmt_limit = $koneksi->prepare($query_limit);
            $stmt_limit->bind_param("ss", $nik, $year);
            $stmt_limit->execute();
            $res_limit = $stmt_limit->get_result();
            $row_limit = $res_limit->fetch_assoc();
            $total_days = (int)($row_limit['total_days'] ?? 0);
            $stmt_limit->close();
            
            if ($total_days + $jumlah > 12) {
                $error_msg = "Pengajuan gagal disimpan! Total cuti Anda di tahun $year akan menjadi " . ($total_days + $jumlah) . " hari, melebihi kuota maksimal 12 hari. Sisa kuota Anda: " . (12 - $total_days) . " hari.";
            } else {
                // Generate nomor pengajuan otomatis (PCYYYYMMDDXXX)
                $today = date('Ymd');
                $prefix = 'PC' . $today;
                $query_no = "SELECT no_pengajuan FROM pengajuan_cuti WHERE no_pengajuan LIKE '$prefix%' ORDER BY no_pengajuan DESC LIMIT 1";
                $result_no = mysqli_query($koneksi, $query_no);
                if ($result_no && mysqli_num_rows($result_no) > 0) {
                    $row_no = mysqli_fetch_assoc($result_no);
                    $last_no = $row_no['no_pengajuan'];
                    $last_num = (int)substr($last_no, -3);
                    $next_num = $last_num + 1;
                } else {
                    $next_num = 1;
                }
                $no_pengajuan = $prefix . sprintf('%03d', $next_num);
                $tanggal_pengajuan = date('Y-m-d');
                $status_default = 'Proses Pengajuan';
                
                // Simpan ke database
                $query_insert = "INSERT INTO pengajuan_cuti (no_pengajuan, tanggal, tanggal_awal, tanggal_akhir, nik, urgensi, alamat, jumlah, kepentingan, nik_pj, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt_insert = $koneksi->prepare($query_insert);
                $stmt_insert->bind_param("sssssssisss", $no_pengajuan, $tanggal_pengajuan, $tanggal_awal, $tanggal_akhir, $nik, $urgensi, $alamat, $jumlah, $kepentingan, $nik_pj, $status_default);
                
                if ($stmt_insert->execute()) {
                    // Cari rantai atasan (approvers) secara rekursif dari atasan_pegawai
                    $approvers = [];
                    $current_employee = $nik;
                    
                    for ($level = 1; $level <= 3; $level++) {
                        $query_atasan = "SELECT nik_atasan FROM atasan_pegawai WHERE nik = ?";
                        $stmt_atasan = $koneksi->prepare($query_atasan);
                        $stmt_atasan->bind_param("s", $current_employee);
                        $stmt_atasan->execute();
                        $res_atasan = $stmt_atasan->get_result();
                        
                        if ($row_atasan = $res_atasan->fetch_assoc()) {
                            $atasan_nik = $row_atasan['nik_atasan'];
                            if (!empty($atasan_nik)) {
                                $approvers[] = [
                                    'level' => $level,
                                    'nik_approver' => $atasan_nik
                                ];
                                $current_employee = $atasan_nik; // Naik ke level berikutnya
                            } else {
                                $stmt_atasan->close();
                                break;
                            }
                        } else {
                            $stmt_atasan->close();
                            break;
                        }
                        $stmt_atasan->close();
                    }
                    
                    // Fallback jika tidak ada atasan terdaftar di atasan_pegawai
                    if (empty($approvers)) {
                        $approvers[] = [
                            'level' => 1,
                            'nik_approver' => $nik_pj
                        ];
                    }
                    
                    // Simpan data persetujuan ke tabel persetujuan_cuti
                    $query_insert_pc = "INSERT INTO persetujuan_cuti (no_pengajuan, level, nik_approver, status) VALUES (?, ?, ?, 'Pending')";
                    $stmt_insert_pc = $koneksi->prepare($query_insert_pc);
                    
                    $pc_ok = true;
                    foreach ($approvers as $app) {
                        $stmt_insert_pc->bind_param("sis", $no_pengajuan, $app['level'], $app['nik_approver']);
                        if (!$stmt_insert_pc->execute()) {
                            $pc_ok = false;
                        }
                    }
                    $stmt_insert_pc->close();
                    
                    if ($pc_ok) {
                        $success_msg = "Pengajuan cuti dengan nomor $no_pengajuan berhasil diajukan!";
                    } else {
                        $error_msg = "Pengajuan berhasil diajukan, namun gagal menginisialisasi alur persetujuan: " . $koneksi->error;
                    }
                } else {
                    $error_msg = "Gagal menyimpan pengajuan cuti: " . $koneksi->error;
                }
                $stmt_insert->close();
            }
        }
    }
}

// Proses Verifikasi/Persetujuan Cuti oleh Atasan
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_approval'])) {
    $persetujuan_id = (int)($_POST['persetujuan_id'] ?? 0);
    $action = $_POST['action'] ?? ''; // 'Disetujui' atau 'Ditolak'
    $catatan = $_POST['catatan'] ?? '';
    
    if (empty($action) || !in_array($action, ['Disetujui', 'Ditolak'])) {
        $error_msg = "Aksi persetujuan tidak valid!";
    } else {
        // Ambil detail persetujuan
        $query_app = "SELECT no_pengajuan, level, nik_approver FROM persetujuan_cuti WHERE id = ?";
        $stmt_app = $koneksi->prepare($query_app);
        $stmt_app->bind_param("i", $persetujuan_id);
        $stmt_app->execute();
        $res_app = $stmt_app->get_result();
        
        if ($row_app = $res_app->fetch_assoc()) {
            $no_pengajuan = $row_app['no_pengajuan'];
            $level = (int)$row_app['level'];
            $nik_approver = $row_app['nik_approver'];
            $stmt_app->close();
            
            // Pastikan yang menyetujui adalah yang sedang login
            if ($nik_approver === $nik) {
                $now = date('Y-m-d H:i:s');
                $query_update_pc = "UPDATE persetujuan_cuti SET status = ?, tanggal_keputusan = ?, catatan = ? WHERE id = ?";
                $stmt_up_pc = $koneksi->prepare($query_update_pc);
                $stmt_up_pc->bind_param("sssi", $action, $now, $catatan, $persetujuan_id);
                
                if ($stmt_up_pc->execute()) {
                    $stmt_up_pc->close();
                    if ($action === 'Ditolak') {
                        // Jika ditolak di tingkat mana pun, status utama langsung 'Ditolak'
                        $query_update_main = "UPDATE pengajuan_cuti SET status = 'Ditolak' WHERE no_pengajuan = ?";
                        $stmt_up_main = $koneksi->prepare($query_update_main);
                        $stmt_up_main->bind_param("s", $no_pengajuan);
                        $stmt_up_main->execute();
                        $stmt_up_main->close();
                        $success_msg = "Pengajuan cuti nomor $no_pengajuan berhasil ditolak.";
                    } else {
                        // Jika disetujui, cek apakah ini level terakhir untuk pengajuan ini
                        $query_check_last = "SELECT MAX(level) AS max_level FROM persetujuan_cuti WHERE no_pengajuan = ?";
                        $stmt_last = $koneksi->prepare($query_check_last);
                        $stmt_last->bind_param("s", $no_pengajuan);
                        $stmt_last->execute();
                        $res_last = $stmt_last->get_result()->fetch_assoc();
                        $max_level = (int)$res_last['max_level'];
                        $stmt_last->close();
                        
                        if ($level === $max_level) {
                            // Jika sudah di level akhir, status utama menjadi 'Disetujui'
                            $query_update_main = "UPDATE pengajuan_cuti SET status = 'Disetujui' WHERE no_pengajuan = ?";
                            $stmt_up_main = $koneksi->prepare($query_update_main);
                            $stmt_up_main->bind_param("s", $no_pengajuan);
                            $stmt_up_main->execute();
                            $stmt_up_main->close();
                            $success_msg = "Pengajuan cuti nomor $no_pengajuan telah disetujui sepenuhnya.";
                        } else {
                            $success_msg = "Persetujuan Level $level untuk pengajuan $no_pengajuan berhasil disimpan. Menunggu persetujuan level selanjutnya.";
                        }
                    }
                } else {
                    $stmt_up_pc->close();
                    $error_msg = "Gagal memproses persetujuan: " . $koneksi->error;
                }
            } else {
                $error_msg = "Anda tidak memiliki hak akses untuk menyetujui pengajuan ini!";
            }
        } else {
            $stmt_app->close();
            $error_msg = "Data persetujuan tidak ditemukan.";
        }
    }
}

// Ambil data riwayat cuti pegawai (untuk dikalkulasi kuota di client-side JS)
$query_yearly = "SELECT YEAR(tanggal_awal) AS thn, SUM(jumlah) AS total FROM pengajuan_cuti WHERE nik = ? AND status != 'Ditolak' GROUP BY YEAR(tanggal_awal)";
$stmt_yearly = $koneksi->prepare($query_yearly);
$stmt_yearly->bind_param("s", $nik);
$stmt_yearly->execute();
$res_yearly = $stmt_yearly->get_result();
$yearly_data = [];
while ($row = $res_yearly->fetch_assoc()) {
    $yearly_data[(int)$row['thn']] = (int)$row['total'];
}
$yearly_json = json_encode($yearly_data);
$stmt_yearly->close();

// Ambil daftar pegawai lain untuk dropdown Penanggung Jawab
$query_pj = "SELECT nik, nama FROM pegawai WHERE nik != ? ORDER BY nama ASC";
$stmt_pj = $koneksi->prepare($query_pj);
$stmt_pj->bind_param("s", $nik);
$stmt_pj->execute();
$res_pj = $stmt_pj->get_result();
$list_pj = [];
while ($row = $res_pj->fetch_assoc()) {
    $list_pj[] = $row;
}
$stmt_pj->close();

// Ambil daftar pengajuan cuti yang butuh persetujuan dari user ini (jika user adalah atasan)
$query_approvals = "
    SELECT p.no_pengajuan, p.tanggal, p.tanggal_awal, p.tanggal_akhir, p.jumlah, p.urgensi, p.kepentingan,
           peg.nama AS nama_pegawai, pc.level, pc.id AS persetujuan_id
    FROM persetujuan_cuti pc
    INNER JOIN pengajuan_cuti p ON pc.no_pengajuan = p.no_pengajuan
    INNER JOIN pegawai peg ON p.nik = peg.nik
    WHERE pc.nik_approver = ? AND pc.status = 'Pending' AND p.status = 'Proses Pengajuan'
      AND (
          pc.level = 1 
          OR (
              pc.level > 1 
              AND EXISTS (
                  SELECT 1 FROM persetujuan_cuti pc_prev 
                  WHERE pc_prev.no_pengajuan = pc.no_pengajuan 
                    AND pc_prev.level = pc.level - 1 
                    AND pc_prev.status = 'Disetujui'
              )
          )
      )
    ORDER BY p.tanggal_awal ASC
";
$stmt_approvals = $koneksi->prepare($query_approvals);
$stmt_approvals->bind_param("s", $nik);
$stmt_approvals->execute();
$res_approvals = $stmt_approvals->get_result();
$list_approvals = [];
while ($row = $res_approvals->fetch_assoc()) {
    $list_approvals[] = $row;
}
$stmt_approvals->close();

// Ambil riwayat cuti per pegawai (untuk ditampilkan di bagian bawah)
$query_history = "SELECT p.no_pengajuan, p.tanggal, p.tanggal_awal, p.tanggal_akhir, p.urgensi, p.alamat, p.jumlah, p.kepentingan, p.nik_pj, peg.nama AS nama_pegawai, pj.nama AS nama_pj, p.status 
                  FROM pengajuan_cuti p 
                  INNER JOIN pegawai peg ON p.nik = peg.nik 
                  LEFT JOIN pegawai pj ON p.nik_pj = pj.nik 
                  WHERE p.nik = ? 
                  ORDER BY p.tanggal_awal DESC";
$stmt_hist = $koneksi->prepare($query_history);
$stmt_hist->bind_param("s", $nik);
$stmt_hist->execute();
$res_hist = $stmt_hist->get_result();
$list_history = [];
while ($row = $res_hist->fetch_assoc()) {
    $list_history[] = $row;
}
$stmt_hist->close();
?>

<!-- jQuery & Select2 -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<style>
    /* Styling khusus widget kuota agar terlihat elegan & match */
    .quota-widget {
        background-color: rgba(99, 102, 241, 0.08);
        border: 1.5px dashed #6366f1;
        border-radius: var(--radius-md);
        padding: 16px;
        margin-bottom: 16px;
    }
    .quota-header {
        display: flex;
        justify-content: space-between;
        font-size: 13px;
        font-weight: 700;
        color: #4f46e5;
        margin-bottom: 8px;
    }
    .quota-bar-container {
        height: 10px;
        background-color: #cbd5e1;
        border-radius: 6px;
        overflow: hidden;
        position: relative;
    }
    .quota-bar-used {
        height: 100%;
        background-color: #4f46e5;
        border-radius: 6px 0 0 6px;
        transition: width 0.3s ease;
    }
    .quota-bar-proposed {
        height: 100%;
        background-color: #f59e0b;
        position: absolute;
        top: 0;
        transition: all 0.3s ease;
    }
    .quota-warning {
        font-size: 12px;
        color: var(--danger);
        margin-top: 8px;
        font-weight: 600;
        display: none;
    }
</style>

<div class="page-header">
    <div>
        <h1 class="page-title">Pengajuan Cuti Online</h1>
        <p class="text-secondary" style="font-size: 14px;">Ajukan cuti dan pantau status persetujuan dari atasan secara real-time.</p>
    </div>
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

<!-- Persetujuan Cuti Card (Hanya muncul jika ada antrean approval untuk user yang login) -->
<?php if (!empty($list_approvals)): ?>
    <div class="content-card" style="border: 2px solid var(--warning); background: rgba(245, 158, 11, 0.05);">
        <div class="card-header" style="margin-bottom: 16px;">
            <h3 class="card-title" style="color: var(--warning); display: flex; align-items: center; gap: 8px;">
                <span>📝</span> Persetujuan Cuti Staf (<?php echo count($list_approvals); ?> Butuh Tindakan)
            </h3>
        </div>
        
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px;">
            <?php foreach ($list_approvals as $app): ?>
                <div class="content-card" style="margin-bottom: 0; background: #ffffff; border: 1px solid var(--border-color); padding: 16px; border-radius: var(--radius-md);">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 10px; align-items: center;">
                        <strong style="font-size: 15px; color: var(--text-primary);"><?php echo htmlspecialchars($app['nama_pegawai']); ?></strong>
                        <span class="badge badge-primary">Level <?php echo $app['level']; ?></span>
                    </div>
                    <div style="font-size: 13px; color: var(--text-secondary); display: flex; flex-direction: column; gap: 6px; margin-bottom: 14px;">
                        <div><strong>No Pengajuan:</strong> <?php echo htmlspecialchars($app['no_pengajuan']); ?></div>
                        <div><strong>Tanggal Cuti:</strong> <?php echo date('d-m-Y', strtotime($app['tanggal_awal'])); ?> s/d <?php echo date('d-m-Y', strtotime($app['tanggal_akhir'])); ?></div>
                        <div><strong>Durasi:</strong> <?php echo $app['jumlah']; ?> Hari Kerja</div>
                        <div><strong>Urgensi:</strong> <span class="badge badge-warning"><?php echo htmlspecialchars($app['urgensi']); ?></span></div>
                        <div style="background: #f8fafc; padding: 8px; border-radius: 8px; font-style: italic; border-left: 3px solid #cbd5e1; margin-top: 4px;">
                            "<?php echo htmlspecialchars($app['kepentingan']); ?>"
                        </div>
                    </div>
                    
                    <form method="POST" style="border-top: 1px solid var(--border-color); padding-top: 12px;">
                        <input type="hidden" name="persetujuan_id" value="<?php echo $app['persetujuan_id']; ?>">
                        <input type="hidden" name="action" id="action-<?php echo $app['persetujuan_id']; ?>" value="">
                        
                        <div class="form-group">
                            <input type="text" name="catatan" class="form-control" placeholder="Tulis catatan (opsional)..." style="font-size: 13px; padding: 8px 12px;">
                        </div>
                        
                        <div style="display: flex; gap: 8px;">
                            <button type="submit" name="action_approval" value="setujui" onclick="document.getElementById('action-<?php echo $app['persetujuan_id']; ?>').value='Disetujui';" class="btn btn-primary btn-sm" style="flex: 1; justify-content: center;">
                                Setujui
                            </button>
                            <button type="submit" name="action_approval" value="tolak" onclick="document.getElementById('action-<?php echo $app['persetujuan_id']; ?>').value='Ditolak';" class="btn btn-danger btn-sm" style="flex: 1; justify-content: center;">
                                Tolak
                            </button>
                        </div>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<!-- Layout Columns (Form on left, history on right) -->
<div class="tx-layout">
    <!-- Left Column: Form Pengajuan -->
    <div class="tx-items">
        <div class="content-card">
            <div class="card-header" style="border-bottom: 1px solid var(--border-color); padding-bottom: 12px; margin-bottom: 20px;">
                <h3 class="card-title">Formulir Cuti</h3>
                <span class="text-secondary" style="font-size: 12px; font-weight: 500;">NIK: <?= htmlspecialchars($nik) ?></span>
            </div>
            
            <form method="POST" id="leaveForm" onsubmit="return validateFormOnSubmit()">
                <div class="form-group">
                    <label class="form-label">Nama Pegawai</label>
                    <input type="text" class="form-control" value="<?= htmlspecialchars($nama_pegawai) ?>" readonly>
                </div>

                <div class="form-group">
                    <label class="form-label" for="tanggal_awal">Tanggal Mulai Cuti *</label>
                    <input type="date" name="tanggal_awal" id="tanggal_awal" class="form-control" required onchange="onDateChanged()">
                </div>

                <div class="form-group">
                    <label class="form-label" for="tanggal_akhir">Tanggal Akhir Cuti *</label>
                    <input type="date" name="tanggal_akhir" id="tanggal_akhir" class="form-control" required onchange="onDateChanged()">
                </div>

                <!-- Widget Kuota Cuti Dinamis -->
                <div class="quota-widget" id="quotaWidget" style="display:none;">
                    <div class="quota-header">
                        <span id="quotaYearTitle">Kuota Cuti</span>
                        <span id="quotaDetailsLabel">0 / 12 Hari</span>
                    </div>
                    <div class="quota-bar-container">
                        <div class="quota-bar-used" id="quotaBarUsed" style="width: 0%;"></div>
                        <div class="quota-bar-proposed" id="quotaBarProposed" style="width: 0%; left: 0%;"></div>
                    </div>
                    <div class="quota-warning" id="quotaWarning">
                        ⚠️ Pengajuan melebihi sisa kuota 12 hari per tahun!
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="jumlah">Durasi Cuti (Hari Kerja)</label>
                    <input type="number" name="jumlah" id="jumlah" class="form-control" value="0" readonly style="background:#f1f5f9; cursor:not-allowed;">
                    <p class="text-secondary" style="font-size: 11px; margin-top: 4px;">* Dihitung otomatis tidak termasuk hari Minggu dan Libur Nasional.</p>
                </div>

                <div class="form-group">
                    <label class="form-label" for="urgensi">Urgensi Cuti *</label>
                    <select name="urgensi" id="urgensi" class="form-control" required>
                        <option value="" disabled selected>-- Pilih Urgensi Cuti --</option>
                        <option value="Tahunan">Tahunan</option>
                        <option value="Besar">Besar</option>
                        <option value="Sakit">Sakit</option>
                        <option value="Bersalin">Bersalin</option>
                        <option value="Alasan Penting">Alasan Penting</option>
                        <option value="Keterangan Lainnya">Keterangan Lainnya</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label" for="alamat">Alamat Selama Cuti *</label>
                    <input type="text" name="alamat" id="alamat" class="form-control" placeholder="Masukkan alamat domisili saat cuti..." maxlength="100" required autocomplete="off">
                </div>

                <div class="form-group">
                    <label class="form-label" for="kepentingan">Keperluan / Keterangan *</label>
                    <input type="text" name="kepentingan" id="kepentingan" class="form-control" placeholder="Tulis kepentingan cuti secara detail..." maxlength="70" required autocomplete="off">
                </div>

                <div class="form-group">
                    <label class="form-label" for="nik_pj">Penanggung Jawab (PJ) Selama Cuti *</label>
                    <select name="nik_pj" id="nik_pj" class="form-control" required>
                        <option value="" disabled selected>-- Pilih PJ Pengganti --</option>
                        <?php foreach ($list_pj as $pj): ?>
                            <option value="<?php echo htmlspecialchars($pj['nik']); ?>">
                                <?php echo htmlspecialchars($pj['nama']); ?> (<?php echo htmlspecialchars($pj['nik']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button type="submit" name="simpan" id="submitBtn" class="btn btn-primary" style="width: 100%; justify-content: center; margin-top: 10px; padding: 12px;">
                    Kirim Pengajuan Cuti
                </button>
            </form>
        </div>
    </div>

    <!-- Right Column: Riwayat Cuti -->
    <div class="tx-summary">
        <h3 class="card-title" style="margin-bottom: 16px; border-bottom: 1.5px dashed var(--border-color); padding-bottom: 10px;">
            Riwayat Cuti Anda
        </h3>
        
        <?php if (empty($list_history)): ?>
            <div style="text-align: center; padding: 40px 10px; color: var(--text-secondary);">
                <div style="font-size: 32px; margin-bottom: 10px;">📂</div>
                Belum ada riwayat pengajuan cuti.
            </div>
        <?php else: ?>
            <div style="display: flex; flex-direction: column; gap: 14px; max-height: 700px; overflow-y: auto; padding-right: 4px;">
                <?php foreach ($list_history as $hist): 
                    $status_class = '';
                    $status_label = $hist['status'];
                    if ($status_label === 'Proses Pengajuan') {
                        $status_class = 'badge-warning';
                    } elseif ($status_label === 'Disetujui') {
                        $status_class = 'badge-success';
                    } elseif ($status_label === 'Ditolak') {
                        $status_class = 'badge-danger';
                    }
                ?>
                    <div style="background: #ffffff; border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 16px; position: relative; box-shadow: var(--shadow);">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                            <strong style="color: var(--text-primary); font-size: 13px; font-family: monospace;"><?= htmlspecialchars($hist['no_pengajuan']) ?></strong>
                            <span class="badge <?= $status_class ?>"><?= htmlspecialchars($status_label) ?></span>
                        </div>
                        <div style="font-size: 12px; color: var(--text-secondary); display: flex; flex-direction: column; gap: 4px;">
                            <div class="tx-summary-row" style="margin-bottom: 0;">
                                <span>Tgl Pengajuan</span>
                                <strong><?= date('d-m-Y', strtotime($hist['tanggal'])) ?></strong>
                            </div>
                            <div class="tx-summary-row" style="margin-bottom: 0;">
                                <span>Tanggal Cuti</span>
                                <strong><?= date('d-m-Y', strtotime($hist['tanggal_awal'])) ?> s/d <?= date('d-m-Y', strtotime($hist['tanggal_akhir'])) ?></strong>
                            </div>
                            <div class="tx-summary-row" style="margin-bottom: 0;">
                                <span>Durasi</span>
                                <strong><?= htmlspecialchars($hist['jumlah']) ?> Hari Kerja</strong>
                            </div>
                            <div class="tx-summary-row" style="margin-bottom: 0;">
                                <span>PJ Pengganti</span>
                                <strong><?= htmlspecialchars($hist['nama_pj'] ?? $hist['nik_pj']) ?></strong>
                            </div>
                            <div style="background: #f8fafc; padding: 6px 10px; border-radius: 6px; font-style: italic; color: #475569; margin-top: 4px; border-left: 2.5px solid #cbd5e1; font-size: 11.5px; word-break: break-all;">
                                "<?= htmlspecialchars($hist['kepentingan']) ?>"
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Scripts untuk Kalkulasi Dinamis -->
<script>
    $(document).ready(function() {
        $('#nik_pj').select2({
            placeholder: "-- Pilih PJ Pengganti --",
            width: '100%'
        });
    });

    const historyCutiByYear = <?php echo $yearly_json; ?>;
    const listHariLibur = <?php echo $holidays_json; ?>;

    function onDateChanged() {
        const tglAwalVal = document.getElementById('tanggal_awal').value;
        const tglAkhirVal = document.getElementById('tanggal_akhir').value;
        const jumlahInput = document.getElementById('jumlah');
        const submitBtn = document.getElementById('submitBtn');
        
        const quotaWidget = document.getElementById('quotaWidget');
        const quotaYearTitle = document.getElementById('quotaYearTitle');
        const quotaDetailsLabel = document.getElementById('quotaDetailsLabel');
        const quotaBarUsed = document.getElementById('quotaBarUsed');
        const quotaBarProposed = document.getElementById('quotaBarProposed');
        const quotaWarning = document.getElementById('quotaWarning');

        if (!tglAwalVal || !tglAkhirVal) {
            jumlahInput.value = 0;
            quotaWidget.style.display = 'none';
            submitBtn.disabled = false;
            return;
        }

        const start = new Date(tglAwalVal);
        const end = new Date(tglAkhirVal);
        
        if (end < start) {
            jumlahInput.value = 0;
            quotaWidget.style.display = 'none';
            submitBtn.disabled = true;
            alert("Tanggal akhir tidak boleh kurang dari tanggal awal!");
            return;
        }

        // hitung durasi cuti mengecualikan hari Minggu & Libur Nasional
        let diffDays = 0;
        let curDate = new Date(start);
        curDate.setHours(0, 0, 0, 0);
        const normalizedEnd = new Date(end);
        normalizedEnd.setHours(0, 0, 0, 0);

        while (curDate <= normalizedEnd) {
            const dayOfWeek = curDate.getDay(); // 0 = Minggu
            
            const yyyy = curDate.getFullYear();
            const mm = String(curDate.getMonth() + 1).padStart(2, '0');
            const dd = String(curDate.getDate()).padStart(2, '0');
            const dateStr = `${yyyy}-${mm}-${dd}`;
            
            if (dayOfWeek !== 0 && !listHariLibur.includes(dateStr)) {
                diffDays++;
            }
            curDate.setDate(curDate.getDate() + 1);
        }

        jumlahInput.value = diffDays;

        const year = start.getFullYear();
        const usedDays = historyCutiByYear[year] || 0;
        const maxQuota = 12;
        const totalProjected = usedDays + diffDays;

        // update widget kuota
        quotaWidget.style.display = 'block';
        quotaYearTitle.innerText = `Kuota Cuti Tahun ${year}`;
        quotaDetailsLabel.innerText = `${usedDays} / 12 Hari`;

        const usedPercent = Math.min((usedDays / maxQuota) * 100, 100);
        const proposedPercent = Math.min((diffDays / maxQuota) * 100, 100 - usedPercent);
        
        quotaBarUsed.style.width = `${usedPercent}%`;
        quotaBarProposed.style.left = `${usedPercent}%`;
        quotaBarProposed.style.width = `${proposedPercent}%`;

        // validasi batas cuti 12 hari
        if (totalProjected > maxQuota) {
            quotaWarning.innerText = `⚠️ Pengajuan (${diffDays} hari) melebihi batas kuota cuti tahun ${year}! Terpakai: ${usedDays} hari, Maks: 12 hari.`;
            quotaWarning.style.display = 'block';
            quotaBarProposed.style.backgroundColor = 'var(--danger)';
            submitBtn.disabled = true;
        } else {
            quotaWarning.style.display = 'none';
            quotaBarProposed.style.backgroundColor = '#f59e0b';
            submitBtn.disabled = false;
        }
    }

    function validateFormOnSubmit() {
        const jumlah = parseInt(document.getElementById('jumlah').value) || 0;
        const tglAwalVal = document.getElementById('tanggal_awal').value;
        
        if (jumlah <= 0) {
            alert("Jumlah hari cuti harus lebih dari 0!");
            return false;
        }

        const start = new Date(tglAwalVal);
        const year = start.getFullYear();
        const usedDays = historyCutiByYear[year] || 0;
        
        if (usedDays + jumlah > 12) {
            alert(`Pengajuan gagal! Anda sudah menggunakan ${usedDays} hari cuti di tahun ${year}. Tambahan ${jumlah} hari melebihi batas maksimal 12 hari.`);
            return false;
        }

        return true;
    }
</script>
