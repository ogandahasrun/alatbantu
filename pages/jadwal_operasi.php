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

// Fetch active doctor list for filter dropdown
$list_dokter = [];
$res_dok = $koneksi->query("SELECT kd_dokter, nm_dokter FROM dokter WHERE status = '1' ORDER BY nm_dokter ASC");
if ($res_dok) {
    while ($row = $res_dok->fetch_assoc()) {
        $list_dokter[] = $row;
    }
}

// Get filter inputs
$tgl_awal = isset($_GET['tgl_awal']) ? trim($_GET['tgl_awal']) : '';
$tgl_akhir = isset($_GET['tgl_akhir']) ? trim($_GET['tgl_akhir']) : '';
$kd_dokter_filter = isset($_GET['kd_dokter_filter']) ? trim($_GET['kd_dokter_filter']) : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Default to today's date if not explicitly filter-submitted (initial load)
if (!isset($_GET['tgl_awal']) && !isset($_GET['tgl_akhir'])) {
    $tgl_awal = date('Y-m-d');
    $tgl_akhir = date('Y-m-d');
}

// Non-admin default to their own kd_dokter if no filter is active
$selected_dokter = $kd_dokter_filter;
if (!$is_admin && empty($selected_dokter)) {
    $selected_dokter = $kd_dokter;
}

// Build SQL query dynamically
$conditions = [];
$params = [];
$types = "";

if (!empty($selected_dokter)) {
    $conditions[] = "bo.kd_dokter = ?";
    $params[] = $selected_dokter;
    $types .= "s";
}

if (!empty($tgl_awal)) {
    $conditions[] = "bo.tanggal >= ?";
    $params[] = $tgl_awal;
    $types .= "s";
}

if (!empty($tgl_akhir)) {
    $conditions[] = "bo.tanggal <= ?";
    $params[] = $tgl_akhir;
    $types .= "s";
}

if (!empty($search)) {
    $conditions[] = "(p.nm_pasien LIKE ? OR p.no_rkm_medis LIKE ? OR po.nm_perawatan LIKE ? OR ro.nm_ruang_ok LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "ssss";
}

$where_clause = "";
if (!empty($conditions)) {
    $where_clause = " WHERE " . implode(" AND ", $conditions);
}

$jadwal_operasi = [];
$query_jadwal = "
    SELECT bo.no_rawat, bo.tanggal, bo.jam_mulai, bo.jam_selesai, bo.status, bo.kd_ruang_ok, 
           p.nm_pasien, p.no_rkm_medis, po.nm_perawatan, ro.nm_ruang_ok, d.nm_dokter
    FROM booking_operasi bo
    INNER JOIN reg_periksa rp ON bo.no_rawat = rp.no_rawat
    INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
    INNER JOIN dokter d ON bo.kd_dokter = d.kd_dokter
    LEFT JOIN paket_operasi po ON bo.kode_paket = po.kode_paket
    LEFT JOIN ruang_ok ro ON bo.kd_ruang_ok = ro.kd_ruang_ok
    $where_clause
    ORDER BY bo.tanggal ASC, bo.jam_mulai ASC
";

$stmt_j = $koneksi->prepare($query_jadwal);
if ($stmt_j) {
    if (!empty($params)) {
        $stmt_j->bind_param($types, ...$params);
    }
    $stmt_j->execute();
    $jadwal_operasi = $stmt_j->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_j->close();
}
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Jadwal Operasi</h1>
        <p class="text-secondary" style="font-size: 14px;">Daftar pemesanan dan jadwal operasi pasien Dokter.</p>
    </div>
</div>

<div class="content-card">
    <div class="card-header" style="flex-wrap: wrap; gap: 16px; border-bottom: 1.5px dashed var(--border-color); padding-bottom: 20px; margin-bottom: 20px;">
        <h3 class="card-title" style="margin-bottom: 0;">Filter Jadwal (Total: <?= count($jadwal_operasi) ?> Tindakan)</h3>
        
        <!-- Filter Panel -->
        <form method="GET" style="display: flex; gap: 12px; width: 100%; flex-wrap: wrap; align-items: flex-end;">
            <input type="hidden" name="page" value="dokter">
            <input type="hidden" name="sub" value="jadwal_operasi">
            
            <div style="flex: 1; min-width: 140px; display: flex; flex-direction: column; gap: 6px;">
                <label style="font-size: 11px; font-weight: 700; color: var(--text-secondary); text-transform: uppercase;">Tanggal Awal</label>
                <input type="date" name="tgl_awal" class="form-control" value="<?= htmlspecialchars($tgl_awal) ?>">
            </div>

            <div style="flex: 1; min-width: 140px; display: flex; flex-direction: column; gap: 6px;">
                <label style="font-size: 11px; font-weight: 700; color: var(--text-secondary); text-transform: uppercase;">Tanggal Akhir</label>
                <input type="date" name="tgl_akhir" class="form-control" value="<?= htmlspecialchars($tgl_akhir) ?>">
            </div>

            <div style="flex: 1.5; min-width: 180px; display: flex; flex-direction: column; gap: 6px;">
                <label style="font-size: 11px; font-weight: 700; color: var(--text-secondary); text-transform: uppercase;">Dokter Operator</label>
                <select name="kd_dokter_filter" class="form-control">
                    <option value="">-- Semua Dokter --</option>
                    <?php foreach ($list_dokter as $dok): ?>
                        <option value="<?= htmlspecialchars($dok['kd_dokter']) ?>" <?= $selected_dokter === $dok['kd_dokter'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($dok['nm_dokter']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="flex: 2; min-width: 200px; display: flex; flex-direction: column; gap: 6px;">
                <label style="font-size: 11px; font-weight: 700; color: var(--text-secondary); text-transform: uppercase;">Pencarian Kata Kunci</label>
                <input type="text" name="search" class="form-control" placeholder="Nama, No. RM, paket, ruang..." value="<?= htmlspecialchars($search) ?>">
            </div>

            <div style="display: flex; gap: 8px;">
                <button type="submit" class="btn btn-primary" style="padding: 10px 18px; font-weight: 700;">Filter</button>
                <a href="index.php?page=dokter&sub=jadwal_operasi" class="btn btn-secondary" style="padding: 10px 18px; text-decoration: none; justify-content: center; display: inline-flex; align-items: center; font-weight: 600;">Reset</a>
            </div>
        </form>
    </div>

    <div class="table-responsive">
        <table class="table-custom">
            <thead>
                <tr>
                    <th style="width: 50px;">No</th>
                    <th>No. Rawat / RM</th>
                    <th>Nama Pasien</th>
                    <th>Tindakan Operasi</th>
                    <th>Ruang Operasi</th>
                    <th>Waktu Pelaksanaan</th>
                    <?php if ($is_admin): ?>
                        <th>Dokter Operator</th>
                    <?php endif; ?>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($jadwal_operasi)): ?>
                    <tr>
                        <td colspan="<?= $is_admin ? '8' : '7' ?>" style="text-align: center; color: var(--text-secondary); padding: 30px;">
                            Tidak ada jadwal operasi terdaftar untuk saat ini.
                        </td>
                    </tr>
                <?php else: 
                    $no = 1;
                    foreach ($jadwal_operasi as $j):
                        // Status badge colors
                        $status_lbl = $j['status'];
                        $badge_class = 'badge-warning'; // Menunggu
                        if ($status_lbl === 'Proses Operasi') {
                            $badge_class = 'badge-primary';
                        } elseif ($status_lbl === 'Selesai') {
                            $badge_class = 'badge-success';
                        }
                ?>
                    <tr>
                        <td data-label="No"><?= $no++ ?></td>
                        <td data-label="No. Rawat / RM">
                            <span style="font-size: 11px; font-family: monospace; color: var(--text-secondary);"><?= htmlspecialchars($j['no_rawat']) ?></span>
                            <div style="font-weight: 700; color: var(--text-primary); margin-top: 2px;">RM: <?= htmlspecialchars($j['no_rkm_medis']) ?></div>
                        </td>
                        <td data-label="Nama Pasien"><strong><?= htmlspecialchars($j['nm_pasien']) ?></strong></td>
                        <td data-label="Tindakan Operasi"><strong><?= htmlspecialchars($j['nm_perawatan'] ?: 'Operasi Kustom') ?></strong></td>
                        <td data-label="Ruang Operasi">
                            <span class="badge badge-secondary"><?= htmlspecialchars($j['nm_ruang_ok'] ?: $j['kd_ruang_ok']) ?></span>
                        </td>
                        <td data-label="Waktu Pelaksanaan">
                            <strong><?= date('d-m-Y', strtotime($j['tanggal'])) ?></strong>
                            <div style="font-size: 11.5px; color: var(--text-secondary); margin-top: 2px;">
                                🕒 <?= date('H:i', strtotime($j['jam_mulai'])) ?> - <?= date('H:i', strtotime($j['jam_selesai'])) ?>
                            </div>
                        </td>
                        <?php if ($is_admin): ?>
                            <td data-label="Dokter Operator"><strong><?= htmlspecialchars($j['nm_dokter']) ?></strong></td>
                        <?php endif; ?>
                        <td data-label="Status">
                            <span class="badge <?= $badge_class ?>" style="padding: 6px 12px; font-size: 11.5px;">
                                <?= htmlspecialchars($status_lbl) ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
