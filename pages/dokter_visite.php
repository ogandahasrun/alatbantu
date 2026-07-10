<?php
defined('host') or die('Akses langsung tidak diizinkan.');

date_default_timezone_set('Asia/Jakarta');
$username = $_SESSION['username'];
$today_date = date('Y-m-d');

// 1. Identify Logged-in Doctor Code (kd_dokter) and Name (nm_dokter)
$kd_dokter = null;
$nm_dokter = "Dokter";

// Check if username is direct kd_dokter
$stmt_d = $koneksi->prepare("SELECT kd_dokter, nm_dokter FROM dokter WHERE kd_dokter = ? LIMIT 1");
if ($stmt_d) {
    $stmt_d->bind_param("s", $username);
    $stmt_d->execute();
    $res_d = $stmt_d->get_result();
    if ($row_d = $res_d->fetch_assoc()) {
        $kd_dokter = $row_d['kd_dokter'];
        $nm_dokter = $row_d['nm_dokter'];
    }
    $stmt_d->close();
}

// If not found, check if it matches NIK in pegawai, and find matching dokter by name
if (!$kd_dokter) {
    $stmt_d = $koneksi->prepare("SELECT d.kd_dokter, d.nm_dokter FROM dokter d INNER JOIN pegawai p ON d.nm_dokter = p.nama WHERE p.nik = ? LIMIT 1");
    if ($stmt_d) {
        $stmt_d->bind_param("s", $username);
        $stmt_d->execute();
        $res_d = $stmt_d->get_result();
        if ($row_d = $res_d->fetch_assoc()) {
            $kd_dokter = $row_d['kd_dokter'];
            $nm_dokter = $row_d['nm_dokter'];
        }
        $stmt_d->close();
    }
}

// Check if logged in user is admin/super admin
$is_admin = (isset($user_permissions['manajemen']) && $user_permissions['manajemen'] === '1') || ($username == '170985');

$data_pasien = [];

if ($is_admin || !empty($kd_dokter)) {
    if ($is_admin) {
        // Query for admins: see all DPJP ranap patients
        $query_pasien = "SELECT DISTINCT
            dpjp_ranap.no_rawat,
            reg_periksa.no_rkm_medis,
            pasien.nm_pasien,
            penjab.png_jawab,
            dpjp_ranap.kd_dokter,
            dokter.nm_dokter,
            kamar_inap.diagnosa_awal,
            kamar.kd_kamar,
            bangsal.nm_bangsal,
            CASE 
                WHEN EXISTS (
                    SELECT 1 FROM pemeriksaan_ranap 
                    WHERE pemeriksaan_ranap.no_rawat = dpjp_ranap.no_rawat 
                    AND pemeriksaan_ranap.nip IN (
                        SELECT d.kd_dokter 
                        FROM dokter d
                        INNER JOIN dpjp_ranap dr ON d.kd_dokter = dr.kd_dokter 
                        WHERE dr.no_rawat = dpjp_ranap.no_rawat
                    )
                    AND DATE(pemeriksaan_ranap.tgl_perawatan) = ?
                ) THEN 1 ELSE 0 
            END as sudah_periksa,
            CASE 
                WHEN DATE((
                    SELECT MIN(tgl_masuk) 
                    FROM kamar_inap ki2 
                    WHERE ki2.no_rawat = dpjp_ranap.no_rawat
                )) = CURDATE() THEN 1 ELSE 0 
            END as pasien_baru_hari_ini
        FROM dpjp_ranap
        INNER JOIN reg_periksa ON dpjp_ranap.no_rawat = reg_periksa.no_rawat
        INNER JOIN penjab ON reg_periksa.kd_pj = penjab.kd_pj
        INNER JOIN pasien ON reg_periksa.no_rkm_medis = pasien.no_rkm_medis
        INNER JOIN dokter ON dpjp_ranap.kd_dokter = dokter.kd_dokter
        INNER JOIN kamar_inap ON kamar_inap.no_rawat = reg_periksa.no_rawat
        INNER JOIN kamar ON kamar_inap.kd_kamar = kamar.kd_kamar
        INNER JOIN bangsal ON kamar.kd_bangsal = bangsal.kd_bangsal
        WHERE kamar_inap.stts_pulang = '-'
        ORDER BY dokter.nm_dokter, bangsal.nm_bangsal, kamar.kd_kamar";
        
        $stmt_p = $koneksi->prepare($query_pasien);
        if ($stmt_p) {
            $stmt_p->bind_param("s", $today_date);
            $stmt_p->execute();
            $data_pasien = $stmt_p->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt_p->close();
        }
    } else {
        // Query for a single doctor: see only their patients
        $query_pasien = "SELECT DISTINCT
            dpjp_ranap.no_rawat,
            reg_periksa.no_rkm_medis,
            pasien.nm_pasien,
            penjab.png_jawab,
            kamar_inap.diagnosa_awal,
            kamar.kd_kamar,
            bangsal.nm_bangsal,
            CASE 
                WHEN EXISTS (
                    SELECT 1 FROM pemeriksaan_ranap 
                    WHERE pemeriksaan_ranap.no_rawat = dpjp_ranap.no_rawat 
                    AND pemeriksaan_ranap.nip = ?
                    AND DATE(pemeriksaan_ranap.tgl_perawatan) = ?
                ) THEN 1 ELSE 0 
            END as sudah_periksa,
            CASE 
                WHEN DATE((
                    SELECT MIN(tgl_masuk) 
                    FROM kamar_inap ki2 
                    WHERE ki2.no_rawat = dpjp_ranap.no_rawat
                )) = CURDATE() THEN 1 ELSE 0 
            END as pasien_baru_hari_ini
        FROM dpjp_ranap
        INNER JOIN reg_periksa ON dpjp_ranap.no_rawat = reg_periksa.no_rawat
        INNER JOIN penjab ON reg_periksa.kd_pj = penjab.kd_pj
        INNER JOIN pasien ON reg_periksa.no_rkm_medis = pasien.no_rkm_medis
        INNER JOIN kamar_inap ON kamar_inap.no_rawat = reg_periksa.no_rawat
        INNER JOIN kamar ON kamar_inap.kd_kamar = kamar.kd_kamar
        INNER JOIN bangsal ON kamar.kd_bangsal = bangsal.kd_bangsal
        WHERE kamar_inap.stts_pulang = '-'
          AND dpjp_ranap.kd_dokter = ?
        ORDER BY bangsal.nm_bangsal, kamar.kd_kamar";
        
        $stmt_p = $koneksi->prepare($query_pasien);
        if ($stmt_p) {
            $stmt_p->bind_param("sss", $kd_dokter, $today_date, $kd_dokter);
            $stmt_p->execute();
            $data_pasien = $stmt_p->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt_p->close();
        }
    }
}

// Search Filter in PHP
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
if (!empty($search)) {
    $search_lower = strtolower($search);
    $data_pasien = array_filter($data_pasien, function($item) use ($search_lower) {
        return strpos(strtolower($item['nm_pasien']), $search_lower) !== false ||
               strpos(strtolower($item['no_rkm_medis']), $search_lower) !== false ||
               strpos(strtolower($item['no_rawat']), $search_lower) !== false ||
               strpos(strtolower($item['nm_bangsal']), $search_lower) !== false;
    });
}

// Calculate status counts
$count_belum = 0;
$count_sudah = 0;
$count_baru = 0;

foreach ($data_pasien as $p) {
    if ($p['pasien_baru_hari_ini']) {
        $count_baru++;
    } elseif ($p['sudah_periksa']) {
        $count_sudah++;
    } else {
        $count_belum++;
    }
}
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Visite Dokter DPJP</h1>
        <p class="text-secondary" style="font-size: 14px;">Selamat Datang, <strong><?= htmlspecialchars($nm_dokter) ?></strong>. Pantau pemeriksaan pasien Rawat Inap hari ini.</p>
    </div>
</div>

<!-- Resume Stats Cards -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px;">
    <!-- Belum Periksa Card -->
    <div class="content-card" style="margin-bottom: 0; border-left: 4px solid var(--danger); background: rgba(239, 68, 68, 0.04); display: flex; flex-direction: column; justify-content: center; padding: 20px;">
        <span style="font-size: 13px; color: var(--text-secondary); font-weight: 600;">Belum Diperiksa</span>
        <strong style="font-size: 28px; color: var(--danger); margin-top: 4px;"><?= $count_belum ?></strong>
    </div>

    <!-- Sudah Periksa Card -->
    <div class="content-card" style="margin-bottom: 0; border-left: 4px solid var(--success); background: rgba(16, 185, 129, 0.04); display: flex; flex-direction: column; justify-content: center; padding: 20px;">
        <span style="font-size: 13px; color: var(--text-secondary); font-weight: 600;">Sudah Diperiksa</span>
        <strong style="font-size: 28px; color: var(--success); margin-top: 4px;"><?= $count_sudah ?></strong>
    </div>

    <!-- Pasien Baru Card -->
    <div class="content-card" style="margin-bottom: 0; border-left: 4px solid var(--primary); background: rgba(99, 102, 241, 0.04); display: flex; flex-direction: column; justify-content: center; padding: 20px;">
        <span style="font-size: 13px; color: var(--text-secondary); font-weight: 600;">Pasien Baru Hari Ini</span>
        <strong style="font-size: 28px; color: var(--primary); margin-top: 4px;"><?= $count_baru ?></strong>
    </div>
</div>

<div class="content-card">
    <div class="card-header" style="flex-wrap: wrap; gap: 16px;">
        <h3 class="card-title">Daftar Pasien Rawat Inap (Total: <?= count($data_pasien) ?> Pasien)</h3>
        
        <!-- Search form -->
        <form method="GET" style="display: flex; gap: 10px; width: 100%; max-width: 320px;">
            <input type="hidden" name="page" value="dokter">
            <input type="hidden" name="sub" value="visite">
            <input type="text" name="search" class="form-control" placeholder="Cari nama, RM, atau bangsal..." value="<?= htmlspecialchars($search) ?>">
            <button type="submit" class="btn btn-secondary btn-sm" style="padding: 10px 14px;">Cari</button>
        </form>
    </div>

    <div class="table-responsive">
        <table class="table-custom">
            <thead>
                <tr>
                    <th style="width: 50px;">No</th>
                    <th>No. Rawat / RM</th>
                    <th>Nama Pasien</th>
                    <th>Penjamin</th>
                    <th>Bangsal & Kamar</th>
                    <th>Diagnosa Awal</th>
                    <?php if ($is_admin): ?>
                        <th>DPJP Dokter</th>
                    <?php endif; ?>
                    <th>Status Pemeriksaan</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($data_pasien)): ?>
                    <tr>
                        <td colspan="<?= $is_admin ? '8' : '7' ?>" style="text-align: center; color: var(--text-secondary); padding: 30px;">
                            Tidak ada pasien yang aktif/cocok untuk dicantumkan saat ini.
                        </td>
                    </tr>
                <?php else: 
                    $no = 1;
                    foreach ($data_pasien as $p):
                        // Badge color and label styling
                        if ($p['pasien_baru_hari_ini']) {
                            $badge_class = 'badge-primary';
                            $status_lbl = 'Pasien Baru (Belum Periksa)';
                            if ($p['sudah_periksa']) {
                                $badge_class = 'badge-success';
                                $status_lbl = 'Sudah Diperiksa (Pasien Baru)';
                            }
                        } else {
                            if ($p['sudah_periksa']) {
                                $badge_class = 'badge-success';
                                $status_lbl = 'Sudah Diperiksa';
                            } else {
                                $badge_class = 'badge-danger';
                                $status_lbl = 'Belum Diperiksa';
                            }
                        }
                ?>
                    <tr class="<?= $p['sudah_periksa'] ? 'row-verified' : '' ?>">
                        <td data-label="No"><?= $no++ ?></td>
                        <td data-label="No. Rawat / RM">
                            <span style="font-size: 11px; font-family: monospace; color: var(--text-secondary);"><?= htmlspecialchars($p['no_rawat']) ?></span>
                            <div style="font-weight: 700; color: var(--text-primary); margin-top: 2px;">RM: <?= htmlspecialchars($p['no_rkm_medis']) ?></div>
                        </td>
                        <td data-label="Nama Pasien"><strong><?= htmlspecialchars($p['nm_pasien']) ?></strong></td>
                        <td data-label="Penjamin"><?= htmlspecialchars($p['png_jawab']) ?></td>
                        <td data-label="Bangsal & Kamar">
                            <strong><?= htmlspecialchars($p['nm_bangsal']) ?></strong>
                            <div style="font-size: 12px; color: var(--text-secondary); margin-top: 1px;"><?= htmlspecialchars($p['kd_kamar']) ?></div>
                        </td>
                        <td data-label="Diagnosa Awal" style="font-size: 12.5px; max-width: 250px;"><?= htmlspecialchars($p['diagnosa_awal'] ?: '-') ?></td>
                        <?php if ($is_admin): ?>
                            <td data-label="DPJP Dokter" style="font-size: 12.5px;">
                                <strong><?= htmlspecialchars($p['nm_dokter']) ?></strong>
                                <div style="font-size: 11px; color: var(--text-secondary);"><?= htmlspecialchars($p['kd_dokter']) ?></div>
                            </td>
                        <?php endif; ?>
                        <td data-label="Status Pemeriksaan">
                            <span class="badge <?= $badge_class ?>" style="padding: 6px 12px; font-size: 11.5px;">
                                <?= $status_lbl ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
