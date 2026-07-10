<?php
defined('host') or die('Akses langsung tidak diizinkan.');

// Ambil NIK dan ID Pegawai dari Session login
$nik = $_SESSION['username'];

// Ambil ID dan Nama Pegawai
$query_pegawai_info = "SELECT id, nama FROM pegawai WHERE nik = ? LIMIT 1";
$stmt_peg_info = $koneksi->prepare($query_pegawai_info);
$stmt_peg_info->bind_param("s", $nik);
$stmt_peg_info->execute();
$res_peg_info = $stmt_peg_info->get_result();
$pegawai_id = 0;
$nama_pegawai = "Pegawai";
if ($row_info = $res_peg_info->fetch_assoc()) {
    $pegawai_id = (int)$row_info['id'];
    $nama_pegawai = $row_info['nama'];
}
$stmt_peg_info->close();

$today_date = date('Y-m-d');
$already_present = false; // Is registered in 'presensi' table
$already_clocked_in = false; // Has a jam_datang in rekap_presensi
$already_clocked_out = false; // Has both jam_datang and jam_pulang in rekap_presensi
$clock_in_time = '';
$clock_out_time = '';
$today_photo_in = '';
$today_photo_out = '';

// Check if registered in presensi table for today
if ($pegawai_id > 0) {
    $stmt_check = $koneksi->prepare("SELECT tgl FROM presensi WHERE id = ? AND tgl = ?");
    $stmt_check->bind_param("is", $pegawai_id, $today_date);
    $stmt_check->execute();
    $res_check = $stmt_check->get_result();
    if ($res_check && $res_check->num_rows > 0) {
        $already_present = true;
    }
    $stmt_check->close();

    // Check in rekap_presensi for clock-in/out times
    $stmt_rekap = $koneksi->prepare("SELECT jam_datang, jam_pulang FROM rekap_presensi WHERE id = ? AND DATE(jam_datang) = ? LIMIT 1");
    $stmt_rekap->bind_param("is", $pegawai_id, $today_date);
    $stmt_rekap->execute();
    $res_rekap = $stmt_rekap->get_result();
    if ($row_rekap = $res_rekap->fetch_assoc()) {
        $already_clocked_in = true;
        $clock_in_time = date('H:i:s', strtotime($row_rekap['jam_datang']));
        
        $possible_photo_in = "assets/uploads/absensi/" . $pegawai_id . "_" . $today_date . "_masuk.jpg";
        if (file_exists($possible_photo_in)) {
            $today_photo_in = $possible_photo_in;
        } else {
            // Fallback to legacy photo name if exists
            $legacy_photo = "assets/uploads/absensi/" . $pegawai_id . "_" . $today_date . ".jpg";
            if (file_exists($legacy_photo)) {
                $today_photo_in = $legacy_photo;
            }
        }

        if (!empty($row_rekap['jam_pulang']) && $row_rekap['jam_pulang'] !== '0000-00-00 00:00:00') {
            $already_clocked_out = true;
            $clock_out_time = date('H:i:s', strtotime($row_rekap['jam_pulang']));
            
            $possible_photo_out = "assets/uploads/absensi/" . $pegawai_id . "_" . $today_date . "_pulang.jpg";
            if (file_exists($possible_photo_out)) {
                $today_photo_out = $possible_photo_out;
            }
        }
    }
    $stmt_rekap->close();
}

// Handle AJAX Attendance Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['absen_hari_ini'])) {
    ob_clean();
    header('Content-Type: application/json');

    $photo = $_POST['photo'] ?? '';
    $action_type = $_POST['type'] ?? 'masuk'; // 'masuk' or 'pulang'

    if ($pegawai_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'ID Pegawai Anda tidak ditemukan di sistem master data!']);
        exit;
    }

    if ($action_type === 'masuk' && $already_clocked_in) {
        echo json_encode(['success' => false, 'message' => 'Anda sudah melakukan Absen Masuk hari ini!']);
        exit;
    }

    if ($action_type === 'pulang' && $already_clocked_out) {
        echo json_encode(['success' => false, 'message' => 'Anda sudah melakukan Absen Pulang hari ini!']);
        exit;
    }

    if ($action_type === 'pulang' && !$already_clocked_in) {
        echo json_encode(['success' => false, 'message' => 'Anda belum melakukan Absen Masuk hari ini!']);
        exit;
    }

    if (empty($photo)) {
        echo json_encode(['success' => false, 'message' => 'Foto selfie wajib diambil!']);
        exit;
    }

    // Decode base64 image data
    if (preg_match('/^data:image\/(\w+);base64,/', $photo, $type_img)) {
        $photo = substr($photo, strpos($photo, ',') + 1);
        $type_img = strtolower($type_img[1]); // jpg, jpeg, png

        if (!in_array($type_img, ['jpg', 'jpeg', 'png'])) {
            echo json_encode(['success' => false, 'message' => 'Format gambar tidak didukung! Hanya JPG/JPEG/PNG.']);
            exit;
        }

        $photo_bin = base64_decode($photo);
        if ($photo_bin === false) {
            echo json_encode(['success' => false, 'message' => 'Dekode gambar gagal!']);
            exit;
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Format data foto tidak valid!']);
        exit;
    }

    // Validate size (Must be <= 50KB)
    $file_size = strlen($photo_bin);
    if ($file_size > 50 * 1024) { // 50 KB
        echo json_encode(['success' => false, 'message' => 'Ukuran foto terlalu besar (' . round($file_size/1024, 1) . ' KB). Maksimal 50 KB!']);
        exit;
    }

    // Ensure upload directory exists
    $upload_dir = 'assets/uploads/absensi/';
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }

    // Save photo file
    $file_suffix = ($action_type === 'masuk') ? 'masuk' : 'pulang';
    $file_name = $pegawai_id . '_' . $today_date . '_' . $file_suffix . '.jpg';
    $file_path = $upload_dir . $file_name;

    if (file_put_contents($file_path, $photo_bin)) {
        // Resolve Shift
        $curr_year = date('Y');
        $curr_month = date('m');
        $curr_day_col = 'h' . (int)date('d');

        $shift_name = 'Pagi'; // default fallback
        $stmt_shift = $koneksi->prepare("SELECT $curr_day_col AS shift_today FROM jadwal_pegawai WHERE id = ? AND tahun = ? AND bulan = ? LIMIT 1");
        if ($stmt_shift) {
            $stmt_shift->bind_param("iss", $pegawai_id, $curr_year, $curr_month);
            $stmt_shift->execute();
            $res_shift = $stmt_shift->get_result();
            if ($row_shift = $res_shift->fetch_assoc()) {
                if (!empty($row_shift['shift_today'])) {
                    $shift_name = $row_shift['shift_today'];
                }
            }
            $stmt_shift->close();
        }

        $shift_masuk = '07:00:00'; // defaults
        $shift_pulang = '14:00:00';
        $stmt_time = $koneksi->prepare("SELECT jam_masuk, jam_pulang FROM jam_masuk WHERE shift = ? LIMIT 1");
        if ($stmt_time) {
            $stmt_time->bind_param("s", $shift_name);
            $stmt_time->execute();
            $res_time = $stmt_time->get_result();
            if ($row_time = $res_time->fetch_assoc()) {
                $shift_masuk = $row_time['jam_masuk'];
                $shift_pulang = $row_time['jam_pulang'];
            }
            $stmt_time->close();
        }

        $now_datetime = date('Y-m-d H:i:s');
        $now_time = date('H:i:s');

        if ($action_type === 'masuk') {
            // Lateness calculation
            $diff = strtotime($now_time) - strtotime($shift_masuk);
            if ($diff > 0) {
                $minutes_late = ceil($diff / 60);
                $status_lateness = 'Terlambat I';
                $keterlambatan = "$minutes_late Menit";
            } else {
                $status_lateness = 'Tepat Waktu';
                $keterlambatan = '-';
            }

            // Insert to presensi (general attendance log)
            $day_of_week = (int)date('w'); // 0 = Minggu
            $jns = ($day_of_week === 0) ? 'HB' : 'HR';
            $lembur = 0;

            // Ins into presensi first if not exists
            if (!$already_present) {
                $stmt_pres = $koneksi->prepare("INSERT INTO presensi (tgl, id, jns, lembur) VALUES (?, ?, ?, ?)");
                if ($stmt_pres) {
                    $stmt_pres->bind_param("sisi", $today_date, $pegawai_id, $jns, $lembur);
                    $stmt_pres->execute();
                    $stmt_pres->close();
                }
            }

            // Insert to rekap_presensi
            $stmt_rekap_ins = $koneksi->prepare("INSERT INTO rekap_presensi (id, shift, jam_datang, jam_pulang, status, keterlambatan, durasi, keterangan, photo) VALUES (?, ?, ?, NULL, ?, ?, '-', 'Absen Masuk Swafoto', ?)");
            if ($stmt_rekap_ins) {
                $stmt_rekap_ins->bind_param("isssss", $pegawai_id, $shift_name, $now_datetime, $status_lateness, $keterlambatan, $file_path);
                if ($stmt_rekap_ins->execute()) {
                    echo json_encode(['success' => true, 'message' => 'Absen Masuk (Clock In) berhasil dicatat!']);
                } else {
                    unlink($file_path);
                    echo json_encode(['success' => false, 'message' => 'Gagal menyimpan rekap presensi: ' . $koneksi->error]);
                }
                $stmt_rekap_ins->close();
            } else {
                unlink($file_path);
                echo json_encode(['success' => false, 'message' => 'Gagal menyiapkan query rekap: ' . $koneksi->error]);
            }

        } else { // pulang (Clock Out)
            // Fetch jam_datang to calculate duration
            $jam_datang = '';
            $stmt_fetch_datang = $koneksi->prepare("SELECT jam_datang FROM rekap_presensi WHERE id = ? AND DATE(jam_datang) = ? LIMIT 1");
            if ($stmt_fetch_datang) {
                $stmt_fetch_datang->bind_param("is", $pegawai_id, $today_date);
                $stmt_fetch_datang->execute();
                $res_datang = $stmt_fetch_datang->get_result();
                if ($row_datang = $res_datang->fetch_assoc()) {
                    $jam_datang = $row_datang['jam_datang'];
                }
                $stmt_fetch_datang->close();
            }

            if (!empty($jam_datang)) {
                $diff_dur = strtotime($now_datetime) - strtotime($jam_datang);
                $durasi_hours = floor($diff_dur / 3600);
                $durasi_mins = floor(($diff_dur % 3600) / 60);
                $durasi = "$durasi_hours Jam $durasi_mins Menit";
            } else {
                $durasi = '-';
            }

            // Update rekap_presensi
            $stmt_rekap_up = $koneksi->prepare("UPDATE rekap_presensi SET jam_pulang = ?, durasi = ?, keterangan = CONCAT(keterangan, ' & Absen Pulang Swafoto') WHERE id = ? AND DATE(jam_datang) = ?");
            if ($stmt_rekap_up) {
                $stmt_rekap_up->bind_param("ssis", $now_datetime, $durasi, $pegawai_id, $today_date);
                if ($stmt_rekap_up->execute()) {
                    echo json_encode(['success' => true, 'message' => 'Absen Pulang (Clock Out) berhasil dicatat!']);
                } else {
                    unlink($file_path);
                    echo json_encode(['success' => false, 'message' => 'Gagal memperbarui rekap presensi: ' . $koneksi->error]);
                }
                $stmt_rekap_up->close();
            } else {
                unlink($file_path);
                echo json_encode(['success' => false, 'message' => 'Gagal menyiapkan query update rekap: ' . $koneksi->error]);
            }
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Gagal menyimpan file foto ke server.']);
    }
    exit;
}

// Retrieve filter parameters
$filter_bulan = isset($_GET['bulan']) ? trim($_GET['bulan']) : date('m');
$filter_tahun = isset($_GET['tahun']) ? trim($_GET['tahun']) : date('Y');

// Month Names mapping in Indonesian
$months_indonesia = [
    '01' => 'Januari', '02' => 'Februari', '03' => 'Maret', '04' => 'April',
    '05' => 'Mei', '06' => 'Juni', '07' => 'Juli', '08' => 'Agustus',
    '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Desember'
];

$count_hadir = 0;
$count_tepat = 0;
$count_terlambat = 0;

// Fetch statistics for selected month/year
if ($pegawai_id > 0) {
    $stmt_stats = $koneksi->prepare("
        SELECT rp.status
        FROM presensi p
        LEFT JOIN rekap_presensi rp ON rp.id = p.id AND DATE(rp.jam_datang) = p.tgl
        WHERE p.id = ? AND YEAR(p.tgl) = ? AND MONTH(p.tgl) = ?
    ");
    if ($stmt_stats) {
        $stmt_stats->bind_param("iss", $pegawai_id, $filter_tahun, $filter_bulan);
        $stmt_stats->execute();
        $res_stats = $stmt_stats->get_result();
        while ($row_stat = $res_stats->fetch_assoc()) {
            $count_hadir++;
            if ($row_stat['status'] === 'Tepat Waktu') {
                $count_tepat++;
            } else if (!empty($row_stat['status']) && strpos($row_stat['status'], 'Terlambat') !== false) {
                $count_terlambat++;
            }
        }
        $stmt_stats->close();
    }

    // Fetch detailed list of records for the selected month/year
    $stmt_list = $koneksi->prepare("
        SELECT p.tgl, p.jns, rp.jam_datang, rp.jam_pulang, rp.status, rp.durasi, rp.keterangan
        FROM presensi p
        LEFT JOIN rekap_presensi rp ON rp.id = p.id AND DATE(rp.jam_datang) = p.tgl
        WHERE p.id = ? AND YEAR(p.tgl) = ? AND MONTH(p.tgl) = ?
        ORDER BY p.tgl DESC
    ");
    if ($stmt_list) {
        $stmt_list->bind_param("iss", $pegawai_id, $filter_tahun, $filter_bulan);
        $stmt_list->execute();
        $attendance_list = $stmt_list->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt_list->close();
    }
}
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Presensi Harian</h1>
        <p class="text-secondary" style="font-size: 14px;">Catat kehadiran masuk & pulang Anda secara mandiri disertai verifikasi swafoto (selfie).</p>
    </div>
</div>

<div class="tx-layout">
    <!-- Left Column: Clock and Camera Capture -->
    <div class="tx-items">
        <div class="content-card" style="text-align: center; padding: 32px 24px; display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: 420px;">
            
            <div id="live-clock" style="font-size: 38px; font-weight: 800; color: var(--text-primary); letter-spacing: -1px; line-height: 1; margin-bottom: 6px;">
                00:00:00
            </div>
            <div id="live-date" style="font-size: 13px; color: var(--text-secondary); font-weight: 500; margin-bottom: 20px;">
                ...
            </div>

            <!-- Camera Video Feed or Preview Box -->
            <div style="position: relative; width: 320px; height: 240px; border-radius: var(--radius-md); overflow: hidden; background: #000; border: 2.5px solid var(--border-color); margin-bottom: 20px; box-shadow: var(--shadow);">
                <?php if ($already_clocked_out): ?>
                    <!-- If clocked out, show their today's Clock Out selfie as status -->
                    <img src="<?= $today_photo_out ?: ($today_photo_in ?: 'assets/images/default.jpg') ?>" style="width: 100%; height: 100%; object-fit: cover;" alt="Selfie Hari Ini">
                    <div style="position: absolute; top: 12px; right: 12px; background: var(--success); color: white; padding: 4px 8px; font-size: 11px; font-weight: 700; border-radius: 20px; display: flex; align-items: center; gap: 4px;">
                        <span>✓</span> Selesai
                    </div>
                <?php else: ?>
                    <!-- If not completely done, show camera live feed for clocking in or out -->
                    <video id="webcam" autoplay playsinline style="width: 100%; height: 100%; object-fit: cover; transform: scaleX(-1);"></video>
                    <canvas id="canvas" style="display: none;"></canvas>
                    <div id="camera-loading" style="position: absolute; top: 0; left: 0; right: 0; bottom: 0; display: flex; align-items: center; justify-content: center; background: #0f172a; color: #94a3b8; font-size: 13px; flex-direction: column; gap: 8px;">
                        <svg class="animate-spin" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="2" x2="12" y2="6"></line><line x1="12" y1="18" x2="12" y2="22"></line><line x1="4.93" y1="4.93" x2="7.76" y2="7.76"></line><line x1="16.24" y1="16.24" x2="19.07" y2="19.07"></line><line x1="2" y1="12" x2="6" y2="12"></line><line x1="18" y1="12" x2="22" y2="12"></line><line x1="4.93" y1="19.07" x2="7.76" y2="16.24"></line><line x1="16.24" y1="7.76" x2="19.07" y2="4.93"></line></svg>
                        Membuka kamera...
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Today's status block -->
            <div style="width: 100%; max-width: 320px; padding: 12px; border-radius: var(--radius-md); margin-bottom: 20px; border: 1px solid var(--border-color); display: flex; flex-direction: column; gap: 8px; background: #f8fafc; text-align: left;">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <span style="font-size: 13px; color: var(--text-secondary); font-weight: 600;">Status Hari Ini</span>
                    <?php if ($already_clocked_out): ?>
                        <span class="badge badge-success" style="font-size: 11px; padding: 4px 10px;">LENGKAP</span>
                    <?php elseif ($already_clocked_in): ?>
                        <span class="badge badge-warning" style="font-size: 11px; padding: 4px 10px; color:#a16207; background:#fef9c3;">BELUM PULANG</span>
                    <?php else: ?>
                        <span class="badge badge-danger" style="font-size: 11px; padding: 4px 10px;">BELUM ABSEN</span>
                    <?php endif; ?>
                </div>
                
                <?php if ($already_clocked_in): ?>
                    <div style="display: flex; justify-content: space-between; font-size: 12px; border-top: 1px dashed var(--border-color); padding-top: 6px;">
                        <span style="color: var(--text-secondary);">Masuk:</span>
                        <strong style="color: var(--text-primary);"><?= $clock_in_time ?></strong>
                    </div>
                <?php endif; ?>
                <?php if ($already_clocked_out): ?>
                    <div style="display: flex; justify-content: space-between; font-size: 12px;">
                        <span style="color: var(--text-secondary);">Pulang:</span>
                        <strong style="color: var(--text-primary);"><?= $clock_out_time ?></strong>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Attendance Action Button -->
            <div style="width: 100%; max-width: 320px;">
                <?php if ($already_clocked_out): ?>
                    <button type="button" class="btn btn-secondary" disabled style="width: 100%; padding: 14px; justify-content: center; font-size: 15px; cursor: not-allowed;">
                        Kehadiran Hari Ini Lengkap
                    </button>
                <?php elseif ($already_clocked_in): ?>
                    <button type="button" id="absenBtn" onclick="doClockOut()" class="btn btn-warning" style="width: 100%; padding: 14px; justify-content: center; font-size: 15px; font-weight: 700; color: #78350f; background: #fef08a; border: 1px solid #fde047; box-shadow: 0 8px 20px rgba(254, 240, 138, 0.4);">
                        Ambil Foto & Absen Pulang
                    </button>
                <?php else: ?>
                    <button type="button" id="absenBtn" onclick="doClockIn()" class="btn btn-primary" style="width: 100%; padding: 14px; justify-content: center; font-size: 15px; font-weight: 700; box-shadow: 0 8px 20px rgba(99, 102, 241, 0.3);">
                        Ambil Foto & Absen Masuk
                    </button>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Right Column: Kehadiran History -->
    <div class="tx-summary">
        <!-- Filter Bulan & Tahun -->
        <form method="GET" style="display: flex; gap: 8px; margin-bottom: 20px; align-items: center; justify-content: space-between; flex-wrap: wrap;">
            <input type="hidden" name="page" value="pegawai">
            <input type="hidden" name="sub" value="absensi">
            
            <h3 class="card-title" style="margin: 0; border: none; padding: 0;">
                Riwayat Presensi
            </h3>
            
            <div style="display: flex; gap: 8px; align-items: center;">
                <select name="bulan" class="form-control" style="width: 130px; padding: 6px 10px; font-size: 13px;" onchange="this.form.submit()">
                    <?php foreach ($months_indonesia as $num => $name): ?>
                        <option value="<?= $num ?>" <?= $filter_bulan === $num ? 'selected' : '' ?>><?= $name ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="tahun" class="form-control" style="width: 90px; padding: 6px 10px; font-size: 13px;" onchange="this.form.submit()">
                    <?php 
                    $start_year = date('Y') - 5;
                    $end_year = date('Y') + 1;
                    for ($y = $end_year; $y >= $start_year; $y--): ?>
                        <option value="<?= $y ?>" <?= $filter_tahun == $y ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </div>
        </form>

        <!-- Summary Statistics Widgets Panel -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(110px, 1fr)); gap: 12px; margin-bottom: 20px;">
            <!-- Hadir -->
            <div style="background: rgba(79, 70, 229, 0.06); border: 1px solid rgba(79, 70, 229, 0.15); border-radius: var(--radius-md); padding: 12px; text-align: center; box-shadow: var(--shadow-sm);">
                <div style="font-size: 20px; margin-bottom: 4px;">📅</div>
                <div style="font-size: 10px; color: var(--text-secondary); text-transform: uppercase; font-weight: 700; letter-spacing: 0.5px;">Hadir</div>
                <div style="font-size: 20px; font-weight: 800; color: #4f46e5; margin-top: 2px;"><?= $count_hadir ?> <span style="font-size: 11px; font-weight: 500; color: var(--text-secondary);">Hari</span></div>
            </div>
            
            <!-- Tepat Waktu -->
            <div style="background: rgba(16, 185, 129, 0.06); border: 1px solid rgba(16, 185, 129, 0.15); border-radius: var(--radius-md); padding: 12px; text-align: center; box-shadow: var(--shadow-sm);">
                <div style="font-size: 20px; margin-bottom: 4px;">⏱️</div>
                <div style="font-size: 10px; color: var(--text-secondary); text-transform: uppercase; font-weight: 700; letter-spacing: 0.5px;">Tepat Waktu</div>
                <div style="font-size: 20px; font-weight: 800; color: #10b981; margin-top: 2px;"><?= $count_tepat ?> <span style="font-size: 11px; font-weight: 500; color: var(--text-secondary);">Hari</span></div>
            </div>

            <!-- Terlambat -->
            <div style="background: rgba(239, 68, 68, 0.06); border: 1px solid rgba(239, 68, 68, 0.15); border-radius: var(--radius-md); padding: 12px; text-align: center; box-shadow: var(--shadow-sm);">
                <div style="font-size: 20px; margin-bottom: 4px;">🚨</div>
                <div style="font-size: 10px; color: var(--text-secondary); text-transform: uppercase; font-weight: 700; letter-spacing: 0.5px;">Terlambat</div>
                <div style="font-size: 20px; font-weight: 800; color: #ef4444; margin-top: 2px;"><?= $count_terlambat ?> <span style="font-size: 11px; font-weight: 500; color: var(--text-secondary);">Hari</span></div>
            </div>
        </div>
        
        <?php if (empty($attendance_list)): ?>
            <div style="text-align: center; padding: 40px 10px; color: var(--text-secondary);">
                <div style="font-size: 32px; margin-bottom: 10px;">🗓️</div>
                Belum ada riwayat kehadiran tercatat.
            </div>
        <?php else: ?>
            <div style="display: flex; flex-direction: column; gap: 12px; max-height: 480px; overflow-y: auto; padding-right: 4px;">
                <?php foreach ($attendance_list as $att): 
                    $tgl_formatted = date('d-m-Y', strtotime($att['tgl']));
                    $day_name = date('l', strtotime($att['tgl']));
                    
                    // Check photos
                    $photo_in_url = "assets/uploads/absensi/" . $pegawai_id . "_" . $att['tgl'] . "_masuk.jpg";
                    if (!file_exists($photo_in_url)) {
                        $legacy_photo = "assets/uploads/absensi/" . $pegawai_id . "_" . $att['tgl'] . ".jpg";
                        $photo_in_url = file_exists($legacy_photo) ? $legacy_photo : '';
                    }
                    
                    $photo_out_url = "assets/uploads/absensi/" . $pegawai_id . "_" . $att['tgl'] . "_pulang.jpg";
                    if (!file_exists($photo_out_url)) {
                        $photo_out_url = '';
                    }

                    $jam_datang_formatted = !empty($att['jam_datang']) ? date('H:i:s', strtotime($att['jam_datang'])) : '-';
                    $jam_pulang_formatted = !empty($att['jam_pulang']) ? date('H:i:s', strtotime($att['jam_pulang'])) : '-';
                    $status_late = $att['status'] ?? '';
                    $is_holiday = ($att['jns'] === 'HB');
                ?>
                    <div style="background: #ffffff; border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 14px; display: flex; flex-direction: column; gap: 10px; box-shadow: var(--shadow);">
                        <!-- Top Row: Date & Status Badge -->
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <div>
                                <strong style="color: var(--text-primary); font-size: 14px;"><?= $tgl_formatted ?></strong>
                                <span style="font-size: 11px; color: var(--text-secondary); margin-left: 6px;">(<?= $day_name ?>)</span>
                            </div>
                            <div>
                                <?php if ($status_late === 'Tepat Waktu'): ?>
                                    <span class="badge badge-success" style="font-size: 10px; text-transform:none; padding:2px 6px;">Tepat Waktu</span>
                                <?php elseif (!empty($status_late)): ?>
                                    <span class="badge badge-danger" style="font-size: 10px; text-transform:none; padding:2px 6px;"><?= htmlspecialchars($status_late) ?></span>
                                <?php elseif ($is_holiday): ?>
                                    <span class="badge badge-danger" style="font-size: 10px; text-transform:none; padding:2px 6px;">Libur</span>
                                <?php else: ?>
                                    <span class="badge badge-secondary" style="font-size: 10px; text-transform:none; padding:2px 6px;">Kerja</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Middle Row: Times & Photos -->
                        <div style="display: flex; gap: 16px; flex-wrap: wrap; background: #f8fafc; padding: 10px; border-radius: 8px;">
                            <!-- Clock In Info -->
                            <div style="display: flex; align-items: center; gap: 10px; flex: 1; min-width: 120px;">
                                <?php if (!empty($photo_in_url)): ?>
                                    <img src="<?= $photo_in_url ?>" style="width: 38px; height: 38px; border-radius: 6px; object-fit: cover; cursor: pointer; border: 1px solid var(--border-color);" onclick="openLightbox('<?= $photo_in_url ?>', 'Foto Masuk - <?= $tgl_formatted ?>')">
                                <?php else: ?>
                                    <div style="width: 38px; height: 38px; border-radius: 6px; background: #e2e8f0; display: flex; align-items: center; justify-content: center; color: #94a3b8; font-size: 14px;">📸</div>
                                <?php endif; ?>
                                <div>
                                    <span style="font-size: 9px; color: var(--text-secondary); text-transform: uppercase; font-weight: 700; display: block;">Masuk</span>
                                    <span style="font-size: 13px; font-weight: 600; color: var(--text-primary);"><?= $jam_datang_formatted ?></span>
                                </div>
                            </div>

                            <!-- Clock Out Info -->
                            <div style="display: flex; align-items: center; gap: 10px; flex: 1; min-width: 120px;">
                                <?php if (!empty($photo_out_url)): ?>
                                    <img src="<?= $photo_out_url ?>" style="width: 38px; height: 38px; border-radius: 6px; object-fit: cover; cursor: pointer; border: 1px solid var(--border-color);" onclick="openLightbox('<?= $photo_out_url ?>', 'Foto Pulang - <?= $tgl_formatted ?>')">
                                <?php else: ?>
                                    <div style="width: 38px; height: 38px; border-radius: 6px; background: #e2e8f0; display: flex; align-items: center; justify-content: center; color: #94a3b8; font-size: 14px;">📸</div>
                                <?php endif; ?>
                                <div>
                                    <span style="font-size: 9px; color: var(--text-secondary); text-transform: uppercase; font-weight: 700; display: block;">Pulang</span>
                                    <span style="font-size: 13px; font-weight: 600; color: var(--text-primary);"><?= $jam_pulang_formatted ?></span>
                                </div>
                            </div>
                        </div>

                        <!-- Bottom Row: Duration & Details -->
                        <?php if (!empty($att['durasi']) && $att['durasi'] !== '-'): ?>
                            <div style="font-size: 11px; color: var(--text-secondary); display: flex; justify-content: space-between; align-items: center; border-top: 1px solid var(--border-color); padding-top: 8px; margin-top: 2px;">
                                <span>Durasi Kerja: <strong><?= htmlspecialchars($att['durasi']) ?></strong></span>
                                <?php if (!empty($att['keterangan'])): ?>
                                    <span style="font-style: italic; color: #64748b; font-size: 10px;"><?= htmlspecialchars($att['keterangan']) ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Lightbox Modal for Photo Zoom -->
<div id="lightboxModal" class="modal-overlay" onclick="closeModal('lightboxModal')">
    <div class="modal-content" style="max-width: 360px; background: none; border: none; box-shadow: none; text-align: center;" onclick="event.stopPropagation()">
        <img id="lightboxImage" src="" style="width: 100%; border-radius: var(--radius-lg); border: 3px solid #fff; box-shadow: var(--shadow-lg);">
        <p style="color: #fff; margin-top: 12px; font-weight: 600; font-size: 14px;" id="lightboxCaption"></p>
    </div>
</div>

<!-- Clock & Webcam Javascript -->
<script>
function startClock() {
    const clock = document.getElementById('live-clock');
    const dateEl = document.getElementById('live-date');
    if (!clock) return;

    const days = ["Minggu", "Senin", "Selasa", "Rabu", "Kamis", "Jumat", "Sabtu"];
    const months = ["Januari", "Februari", "Maret", "April", "Mei", "Juni", "Juli", "Agustus", "September", "Oktober", "November", "Desember"];

    setInterval(() => {
        const now = new Date();
        const hrs = String(now.getHours()).padStart(2, '0');
        const mins = String(now.getMinutes()).padStart(2, '0');
        const secs = String(now.getSeconds()).padStart(2, '0');
        clock.innerText = `${hrs}:${mins}:${secs}`;
        
        const dayName = days[now.getDay()];
        const day = now.getDate();
        const monthName = months[now.getMonth()];
        const year = now.getFullYear();
        dateEl.innerText = `${dayName}, ${day} ${monthName} ${year}`;
    }, 1000);
}

startClock();

// Camera initialization if user has not completed attendance today
<?php if (!$already_clocked_out): ?>
const video = document.getElementById('webcam');
const loader = document.getElementById('camera-loading');

if (video) {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        loader.innerHTML = '❌ Kamera diblokir oleh browser karena koneksi tidak aman (HTTP). Silakan akses aplikasi menggunakan protokol <strong>HTTPS</strong> pada handphone Anda.';
        const actionBtn = document.getElementById('absenBtn');
        if (actionBtn) actionBtn.disabled = true;
    } else {
        navigator.mediaDevices.getUserMedia({ 
            video: { 
                width: { ideal: 320 }, 
                height: { ideal: 240 },
                facingMode: 'user'
            } 
        })
        .then(stream => {
            video.srcObject = stream;
            loader.style.display = 'none';
        })
        .catch(err => {
            console.error("Gagal mengakses kamera: ", err);
            loader.innerHTML = '❌ Izin kamera ditolak. Silakan aktifkan izin kamera pada browser Anda untuk melakukan presensi.';
            const actionBtn = document.getElementById('absenBtn');
            if (actionBtn) actionBtn.disabled = true;
        });
    }
}

function submitAttendance(type) {
    const video = document.getElementById('webcam');
    const canvas = document.getElementById('canvas');
    const absenBtn = document.getElementById('absenBtn');
    
    if (!video || !canvas) return;

    absenBtn.disabled = true;
    absenBtn.innerText = 'Memproses...';

    const context = canvas.getContext('2d');
    canvas.width = 320;
    canvas.height = 240;
    
    // Draw mirrored video to canvas
    context.translate(canvas.width, 0);
    context.scale(-1, 1);
    context.drawImage(video, 0, 0, canvas.width, canvas.height);
    
    // Export image as compressed JPEG (~15KB)
    const photoBase64 = canvas.toDataURL('image/jpeg', 0.6);

    // Send via AJAX
    const formData = new FormData();
    formData.append('absen_hari_ini', '1');
    formData.append('type', type);
    formData.append('photo', photoBase64);

    fetch('index.php?page=pegawai&sub=absensi', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            location.reload();
        } else {
            alert("Gagal melakukan presensi: " + data.message);
            absenBtn.disabled = false;
            absenBtn.innerText = (type === 'masuk') ? 'Ambil Foto & Absen Masuk' : 'Ambil Foto & Absen Pulang';
        }
    })
    .catch(err => {
        alert("Terjadi kesalahan koneksi!");
        absenBtn.disabled = false;
        absenBtn.innerText = (type === 'masuk') ? 'Ambil Foto & Absen Masuk' : 'Ambil Foto & Absen Pulang';
    });
}

function doClockIn() {
    submitAttendance('masuk');
}

function doClockOut() {
    submitAttendance('pulang');
}
<?php endif; ?>

// Lightbox controller
function openModal(id) {
    document.getElementById(id).classList.add('active');
}

function closeModal(id) {
    document.getElementById(id).classList.remove('active');
}

function openLightbox(src, caption) {
    document.getElementById('lightboxImage').src = src;
    document.getElementById('lightboxCaption').innerText = caption;
    openModal('lightboxModal');
}
</script>

<style>
    /* CSS animation for camera spinner */
    .animate-spin {
        animation: spin 1s linear infinite;
    }
    @keyframes spin {
        from { transform: rotate(0deg); }
        to { transform: rotate(360deg); }
    }
</style>
