<?php
defined('host') or die('Akses langsung tidak diizinkan.');

// Intercept AJAX search pegawai
if (isset($_GET['ajax_search_pegawai'])) {
    header('Content-Type: application/json');
    $val = trim($_GET['ajax_search_pegawai']);
    $res = [];
    if (strlen($val) >= 2) {
        $q = "%$val%";
        $stmt = $koneksi->prepare("SELECT nik, nama FROM pegawai WHERE nama LIKE ? OR nik LIKE ? LIMIT 10");
        $stmt->bind_param("ss", $q, $q);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $res[] = ['nik' => $row['nik'], 'nama' => $row['nama']];
        }
        $stmt->close();
    }
    echo json_encode($res);
    exit;
}

// Validasi akses manajemen
if (!isset($user_permissions['manajemen']) || $user_permissions['manajemen'] !== '1') {
    die('<div class="content-card"><h3 style="color:red;">Akses Ditolak</h3><p>Anda tidak memiliki izin untuk mengakses halaman Manajemen.</p></div>');
}

$bulan = $_GET['bulan'] ?? date('m');
$tahun = $_GET['tahun'] ?? date('Y');
$nik_peg = $_GET['nik_pegawai'] ?? '';

// Daftar bulan untuk filter
$nama_bulan = [
    '01' => 'Januari', '02' => 'Februari', '03' => 'Maret',
    '04' => 'April', '05' => 'Mei', '06' => 'Juni',
    '07' => 'Juli', '08' => 'Agustus', '09' => 'September',
    '10' => 'Oktober', '11' => 'November', '12' => 'Desember'
];

// Ambil info pegawai yang dipilih jika ada
$peg_id = 0;
$peg_nama = '';
$peg_dept = '';
if (!empty($nik_peg)) {
    $q_peg = $koneksi->prepare("SELECT id, nama, departemen FROM pegawai WHERE nik = ? LIMIT 1");
    if ($q_peg) {
        $q_peg->bind_param("s", $nik_peg);
        $q_peg->execute();
        $res = $q_peg->get_result();
        if ($d = $res->fetch_assoc()) {
            $peg_id = (int)$d['id'];
            $peg_nama = $d['nama'];
            $peg_dept = $d['departemen'];
        }
        $q_peg->close();
    }
}

// Ambil toleransi keterlambatan
$toleransi = 0;
$q_tol = $koneksi->query("SELECT toleransi FROM set_keterlambatan LIMIT 1");
if ($q_tol && $dt = $q_tol->fetch_assoc()) {
    $toleransi = (int)$dt['toleransi'];
}

// Data Jam Jaga
$map_jam_masuk = [];
if ($peg_id > 0) {
    if (!empty($peg_dept)) {
        $q_jam = $koneksi->prepare("SELECT shift, jam_masuk FROM jam_jaga WHERE dep_id = ?");
        if ($q_jam) {
            $q_jam->bind_param("s", $peg_dept);
            $q_jam->execute();
            $r_jam = $q_jam->get_result();
            while($rj = $r_jam->fetch_assoc()){
                $map_jam_masuk[$rj['shift']] = $rj['jam_masuk'];
            }
            $q_jam->close();
        }
    }
    
    // Fallback jika departemen pegawai '-' atau belum di set di jam_jaga
    if (empty($map_jam_masuk)) {
        $r_jam_fallback = $koneksi->query("SELECT shift, MIN(jam_masuk) as jam_masuk FROM jam_jaga GROUP BY shift");
        if ($r_jam_fallback) {
            while($rj = $r_jam_fallback->fetch_assoc()){
                $map_jam_masuk[$rj['shift']] = $rj['jam_masuk'];
            }
        }
    }
}

// Data Absensi
$absensi = [];
if (!empty($nik_peg)) {
    $q_absen = "SELECT 
                    DATE(detail_absensi.tanggal) as tgl,
                    MIN(TIME(detail_absensi.tanggal)) as jam_masuk,
                    MAX(TIME(detail_absensi.tanggal)) as jam_keluar
                FROM pegawai
                INNER JOIN mapping_absensi ON mapping_absensi.nik = pegawai.nik
                INNER JOIN detail_absensi ON detail_absensi.id = mapping_absensi.id
                WHERE pegawai.nik = '" . $koneksi->real_escape_string($nik_peg) . "'
                  AND YEAR(detail_absensi.tanggal) = '" . $koneksi->real_escape_string($tahun) . "'
                  AND MONTH(detail_absensi.tanggal) = '" . $koneksi->real_escape_string($bulan) . "'
                GROUP BY DATE(detail_absensi.tanggal)";
    $res_absen = $koneksi->query($q_absen);
    if ($res_absen) {
        while ($row = $res_absen->fetch_assoc()) {
            $absensi[$row['tgl']] = $row;
        }
    }
}

// Data Jadwal Shift
$jadwal = [];
if ($peg_id > 0) {
    $q_jadwal = $koneksi->prepare("SELECT * FROM jadwal_pegawai WHERE id = ? AND tahun = ? AND bulan = ? LIMIT 1");
    if ($q_jadwal) {
        $q_jadwal->bind_param("iss", $peg_id, $tahun, $bulan);
        $q_jadwal->execute();
        $res_jadwal = $q_jadwal->get_result();
        if ($row_jadwal = $res_jadwal->fetch_assoc()) {
            $jadwal = $row_jadwal;
        }
        $q_jadwal->close();
    }
}
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Rekap Absensi Pegawai</h1>
        <p class="text-secondary" style="font-size: 14px;">Melihat perbandingan jadwal pegawai dan data riil scan wajah/sidik jari.</p>
    </div>
</div>

<div class="content-card">
    <form method="GET" style="display: flex; gap: 15px; flex-wrap: wrap; align-items: flex-end; margin-bottom: 20px;">
        <input type="hidden" name="page" value="manajemen">
        <input type="hidden" name="sub" value="rekap_absensi">
        
        <div>
            <label class="form-label">Bulan</label>
            <select name="bulan" class="form-control" style="width: 150px;">
                <?php foreach ($nama_bulan as $num => $nama): ?>
                    <option value="<?= $num ?>" <?= $bulan === $num ? 'selected' : '' ?>><?= $nama ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label class="form-label">Tahun</label>
            <select name="tahun" class="form-control" style="width: 120px;">
                <?php 
                $start_year = 2020;
                $end_year = date('Y') + 1;
                for ($y = $end_year; $y >= $start_year; $y--): 
                ?>
                    <option value="<?= $y ?>" <?= (string)$tahun === (string)$y ? 'selected' : '' ?>><?= $y ?></option>
                <?php endfor; ?>
            </select>
        </div>

        <div class="autocomplete-container" style="flex: 1; min-width: 250px;">
            <label class="form-label">Pegawai</label>
            <input type="text" id="search_pegawai" class="form-control" placeholder="Ketik nama atau NIK pegawai..." autocomplete="off" oninput="suggestPegawai(this.value)" value="<?= htmlspecialchars($nik_peg . ($peg_nama ? ' - '.$peg_nama : '')) ?>">
            <input type="hidden" id="selected_nik" name="nik_pegawai" value="<?= htmlspecialchars($nik_peg) ?>" required>
            <div id="pegawai-suggestions" class="autocomplete-suggestions" style="display: none;"></div>
        </div>

        <button type="submit" class="btn btn-primary" style="padding: 10px 20px;">Tampilkan</button>
    </form>

    <?php if (empty($nik_peg)): ?>
        <?php
        $search_list = $_GET['search_list'] ?? '';
        $page_num = isset($_GET['p']) ? max(1, (int)$_GET['p']) : 1;
        $limit = 15;
        $offset = ($page_num - 1) * $limit;
        
        $where = "";
        $params = [];
        $types = "";
        if (!empty($search_list)) {
            $where = "WHERE nama LIKE ? OR nik LIKE ?";
            $q = "%$search_list%";
            $params = [$q, $q];
            $types = "ss";
        }
        
        // Count total
        $q_count = "SELECT COUNT(id) as total FROM pegawai $where";
        $stmt_c = $koneksi->prepare($q_count);
        if ($where) $stmt_c->bind_param($types, ...$params);
        $stmt_c->execute();
        $total_peg = $stmt_c->get_result()->fetch_assoc()['total'];
        $stmt_c->close();
        
        $total_pages = ceil($total_peg / $limit);
        if ($total_pages < 1) $total_pages = 1;
        
        $q_list = "SELECT id, nik, nama, departemen FROM pegawai $where ORDER BY nama ASC LIMIT $limit OFFSET $offset";
        $stmt_l = $koneksi->prepare($q_list);
        if ($where) $stmt_l->bind_param($types, ...$params);
        $stmt_l->execute();
        $res_list = $stmt_l->get_result();
        
        $pegawai_list = [];
        $peg_ids = [];
        $peg_niks = [];
        while($p = $res_list->fetch_assoc()) {
            $pegawai_list[] = $p;
            $peg_ids[] = (int)$p['id'];
            $peg_niks[] = "'" . $koneksi->real_escape_string($p['nik']) . "'";
        }
        $stmt_l->close();

        // Fetch jam_jaga
        $map_jam_jaga = []; 
        $r_jam = $koneksi->query("SELECT dep_id, shift, jam_masuk, jam_pulang FROM jam_jaga");
        if ($r_jam) {
            while($rj = $r_jam->fetch_assoc()){
                $map_jam_jaga[$rj['dep_id']][$rj['shift']] = ['masuk' => $rj['jam_masuk'], 'pulang' => $rj['jam_pulang']];
            }
        }
        $r_jam_fallback = $koneksi->query("SELECT shift, MIN(jam_masuk) as jam_masuk, MIN(jam_pulang) as jam_pulang FROM jam_jaga GROUP BY shift");
        $map_jam_fallback = [];
        if ($r_jam_fallback) {
            while($rj = $r_jam_fallback->fetch_assoc()){
                $map_jam_fallback[$rj['shift']] = ['masuk' => $rj['jam_masuk'], 'pulang' => $rj['jam_pulang']];
            }
        }

        $rekap_data = [];
        if (!empty($peg_ids)) {
            // 1. Fetch jadwal for these employees
            $id_list = implode(',', $peg_ids);
            $q_jadwal = "SELECT * FROM jadwal_pegawai WHERE id IN ($id_list) AND tahun = ? AND bulan = ?";
            $stmt_j = $koneksi->prepare($q_jadwal);
            $stmt_j->bind_param("ss", $tahun, $bulan);
            $stmt_j->execute();
            $res_jadwal = $stmt_j->get_result();
            $jadwal_all = [];
            while($row = $res_jadwal->fetch_assoc()) {
                $jadwal_all[$row['id']] = $row;
            }
            $stmt_j->close();
            
            // 2. Fetch absensi for these employees
            $nik_list = implode(',', $peg_niks);
            $q_absen = "SELECT 
                            pegawai.id,
                            DATE(detail_absensi.tanggal) as tgl,
                            MIN(TIME(detail_absensi.tanggal)) as jam_masuk,
                            MAX(TIME(detail_absensi.tanggal)) as jam_keluar
                        FROM pegawai
                        INNER JOIN mapping_absensi ON mapping_absensi.nik = pegawai.nik
                        INNER JOIN detail_absensi ON detail_absensi.id = mapping_absensi.id
                        WHERE pegawai.nik IN ($nik_list)
                          AND YEAR(detail_absensi.tanggal) = ?
                          AND MONTH(detail_absensi.tanggal) = ?
                        GROUP BY pegawai.id, DATE(detail_absensi.tanggal)";
            $stmt_a = $koneksi->prepare($q_absen);
            $stmt_a->bind_param("ss", $tahun, $bulan);
            $stmt_a->execute();
            $res_absen = $stmt_a->get_result();
            $absen_all = [];
            while($row = $res_absen->fetch_assoc()) {
                $absen_all[$row['id']][$row['tgl']] = $row;
            }
            $stmt_a->close();
            
            // 3. Process the logic for each employee
            $days_in_month = cal_days_in_month(CAL_GREGORIAN, (int)$bulan, (int)$tahun);
            
            foreach($pegawai_list as $p) {
                $pid = $p['id'];
                $pdept = $p['departemen'];
                
                $count_hadir = 0;
                $count_telat = 0;
                $count_cepat = 0;
                $count_alfa = 0;
                
                $p_jadwal = $jadwal_all[$pid] ?? [];
                $p_absen = $absen_all[$pid] ?? [];
                
                $jam_map = isset($map_jam_jaga[$pdept]) && !empty($map_jam_jaga[$pdept]) ? $map_jam_jaga[$pdept] : $map_jam_fallback;
                
                for ($d = 1; $d <= $days_in_month; $d++) {
                    $date_str = $tahun . '-' . $bulan . '-' . str_pad($d, 2, '0', STR_PAD_LEFT);
                    $col_h = 'h' . $d;
                    $shift = $p_jadwal[$col_h] ?? '-';
                    if ($shift === '') $shift = '-';
                    
                    $absen_hari_ini = $p_absen[$date_str] ?? null;
                    
                    if ($absen_hari_ini) {
                        $count_hadir++;
                        
                        if ($shift !== '-' && isset($jam_map[$shift])) {
                            $j_masuk = $jam_map[$shift]['masuk'];
                            $j_pulang = $jam_map[$shift]['pulang'];
                            
                            $a_masuk = date('H:i:s', strtotime($absen_hari_ini['jam_masuk']));
                            $j_masuk_tol = date('H:i:s', strtotime("+$toleransi minutes", strtotime($j_masuk)));
                            
                            if ($a_masuk > $j_masuk_tol) {
                                $count_telat++;
                            }
                            
                            if ($absen_hari_ini['jam_masuk'] !== $absen_hari_ini['jam_keluar']) {
                                $a_keluar = date('H:i:s', strtotime($absen_hari_ini['jam_keluar']));
                                if ($j_pulang && $a_keluar < $j_pulang) {
                                    $count_cepat++;
                                }
                            }
                        }
                    } else {
                        if ($shift !== '-') {
                            $count_alfa++;
                        }
                    }
                }
                
                $rekap_data[$pid] = [
                    'hadir' => $count_hadir,
                    'telat' => $count_telat,
                    'cepat' => $count_cepat,
                    'alfa' => $count_alfa
                ];
            }
        }
        ?>

        <div style="margin-bottom: 20px; display: flex; gap: 10px; justify-content: space-between; align-items: center;">
            <h3 style="margin: 0; font-size: 16px; color: var(--text-primary);">Daftar Pegawai</h3>
            <form method="GET" style="display: flex; gap: 10px; width: 100%; max-width: 300px;">
                <input type="hidden" name="page" value="manajemen">
                <input type="hidden" name="sub" value="rekap_absensi">
                <input type="hidden" name="bulan" value="<?= htmlspecialchars($bulan) ?>">
                <input type="hidden" name="tahun" value="<?= htmlspecialchars($tahun) ?>">
                <input type="text" name="search_list" class="form-control" placeholder="Cari nama / NIK..." value="<?= htmlspecialchars($search_list) ?>">
                <button type="submit" class="btn btn-secondary">Cari</button>
            </form>
        </div>

        <div class="table-responsive">
            <table class="table-custom">
                <thead>
                    <tr>
                        <th>NIK</th>
                        <th>Nama Pegawai</th>
                        <th>Departemen</th>
                        <th style="text-align: center;">Hadir</th>
                        <th style="text-align: center;">Telat</th>
                        <th style="text-align: center;">Cpt. Pulang</th>
                        <th style="text-align: center;">Alfa</th>
                        <th style="width: 120px; text-align: center;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($pegawai_list as $p): 
                        $pid = $p['id'];
                        $rd = $rekap_data[$pid] ?? ['hadir'=>0, 'telat'=>0, 'cepat'=>0, 'alfa'=>0];
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($p['nik']) ?></td>
                        <td><strong><?= htmlspecialchars($p['nama']) ?></strong></td>
                        <td><?= htmlspecialchars($p['departemen'] ?? '-') ?></td>
                        <td style="text-align: center; color: #059669; font-weight: bold;"><?= $rd['hadir'] ?> x</td>
                        <td style="text-align: center; color: #ea580c; font-weight: bold;"><?= $rd['telat'] ?> x</td>
                        <td style="text-align: center; color: #d97706; font-weight: bold;"><?= $rd['cepat'] ?> x</td>
                        <td style="text-align: center; color: #dc2626; font-weight: bold;"><?= $rd['alfa'] ?> x</td>
                        <td style="text-align: center;">
                            <a href="index.php?page=manajemen&sub=rekap_absensi&bulan=<?= $bulan ?>&tahun=<?= $tahun ?>&nik_pegawai=<?= urlencode($p['nik']) ?>" class="btn btn-primary" style="padding: 6px 12px; font-size: 12px; text-decoration: none;">Lihat Rekap</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($pegawai_list)): ?>
                    <tr><td colspan="8" style="text-align: center; color: var(--text-secondary); padding: 20px;">Data pegawai tidak ditemukan.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <?php if ($total_pages > 1): ?>
        <div style="margin-top: 15px; display: flex; gap: 5px; justify-content: center; flex-wrap: wrap;">
            <?php for($i = 1; $i <= $total_pages; $i++): 
                if ($i == 1 || $i == $total_pages || ($i >= $page_num - 2 && $i <= $page_num + 2)):
            ?>
                <a href="index.php?page=manajemen&sub=rekap_absensi&bulan=<?= $bulan ?>&tahun=<?= $tahun ?>&search_list=<?= urlencode($search_list) ?>&p=<?= $i ?>" class="btn <?= $i === $page_num ? 'btn-primary' : 'btn-secondary' ?>" style="padding: 5px 10px; text-decoration: none;"><?= $i ?></a>
            <?php 
                elseif ($i == $page_num - 3 || $i == $page_num + 3):
                    echo "<span style='padding: 5px; color: var(--text-secondary);'>...</span>";
                endif;
            endfor; ?>
        </div>
        <?php endif; ?>

    <?php else: ?>
        <div style="margin-bottom: 20px;">
            <a href="index.php?page=manajemen&sub=rekap_absensi&bulan=<?= $bulan ?>&tahun=<?= $tahun ?>" class="btn btn-secondary" style="text-decoration: none;">&larr; Kembali ke Daftar Pegawai</a>
        </div>
        <div class="table-responsive">
            <table class="table-custom">
                <thead>
                    <tr>
                        <th style="width: 80px;">Tanggal</th>
                        <th>Jadwal Shift</th>
                        <th>Scan Masuk</th>
                        <th>Scan Keluar</th>
                        <th>Keterangan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $days_in_month = cal_days_in_month(CAL_GREGORIAN, (int)$bulan, (int)$tahun);
                    for ($d = 1; $d <= $days_in_month; $d++): 
                        $date_str = $tahun . '-' . $bulan . '-' . str_pad($d, 2, '0', STR_PAD_LEFT);
                        $col_h = 'h' . $d;
                        $shift_hari_ini = $jadwal[$col_h] ?? '-';
                        if ($shift_hari_ini === '') $shift_hari_ini = '-';
                        
                        $data_absen = $absensi[$date_str] ?? null;
                        
                        $jam_masuk = '-';
                        $jam_keluar = '-';
                        $keterangan = '';
                        $keterangan_color = '';
                        
                        if ($data_absen) {
                            $jam_masuk = date('H:i:s', strtotime($data_absen['jam_masuk']));
                            
                            // Jika min != max (atau ada lebih dari 1 scan beda detik), maka ada jam keluar
                            if ($data_absen['jam_masuk'] !== $data_absen['jam_keluar']) {
                                $jam_keluar = date('H:i:s', strtotime($data_absen['jam_keluar']));
                            }
                            
                            $keterangan = "Hadir";
                            $keterangan_color = 'color: #059669; font-weight: bold;';

                            // Hitung Keterlambatan
                            if ($shift_hari_ini !== '-' && isset($map_jam_masuk[$shift_hari_ini])) {
                                $jadwal_masuk = $map_jam_masuk[$shift_hari_ini];
                                $jadwal_masuk_tol = date('H:i:s', strtotime("+$toleransi minutes", strtotime($jadwal_masuk)));
                                
                                if ($jam_masuk > $jadwal_masuk_tol) {
                                    $selisih = strtotime($jam_masuk) - strtotime($jadwal_masuk);
                                    $menit_telat = floor($selisih / 60);
                                    $keterangan .= " (Telat $menit_telat menit)";
                                    $keterangan_color = 'color: #ea580c; font-weight: bold;'; // Orange untuk telat
                                }
                            }

                        } else {
                            if ($shift_hari_ini !== '-') {
                                $keterangan = "Tidak ada scan";
                                $keterangan_color = 'color: #dc2626; font-weight: bold;';
                            }
                        }
                    ?>
                        <tr>
                            <td style="text-align: center;"><strong><?= $d ?></strong></td>
                            <td>
                                <?php if ($shift_hari_ini !== '-'): ?>
                                    <span class="badge badge-primary"><?= htmlspecialchars($shift_hari_ini) ?></span>
                                <?php else: ?>
                                    <span style="color: var(--text-secondary); font-size: 13px;">Libur / Kosong</span>
                                <?php endif; ?>
                            </td>
                            <td><?= $jam_masuk ?></td>
                            <td><?= $jam_keluar ?></td>
                            <td style="<?= $keterangan_color ?>"><?= $keterangan ?></td>
                        </tr>
                    <?php endfor; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<script>
let debounceTimer;

async function suggestPegawai(query) {
    clearTimeout(debounceTimer);
    const suggestionsBox = document.getElementById('pegawai-suggestions');
    const hiddenNik = document.getElementById('selected_nik');
    
    if (!query) {
        suggestionsBox.style.display = 'none';
        hiddenNik.value = '';
        return;
    }
    
    if (query.length < 2) return;

    debounceTimer = setTimeout(async () => {
        try {
            const response = await fetch('index.php?page=manajemen&sub=rekap_absensi&ajax_search_pegawai=' + encodeURIComponent(query));
            const data = await response.json();
            
            suggestionsBox.innerHTML = '';
            if (data.length > 0) {
                data.forEach(item => {
                    const div = document.createElement('div');
                    div.className = 'autocomplete-suggestion';
                    div.innerHTML = `<strong>${item.nama}</strong><br><small>${item.nik}</small>`;
                    div.onclick = () => {
                        document.getElementById('search_pegawai').value = item.nik + ' - ' + item.nama;
                        hiddenNik.value = item.nik;
                        suggestionsBox.style.display = 'none';
                    };
                    suggestionsBox.appendChild(div);
                });
                suggestionsBox.style.display = 'block';
            } else {
                suggestionsBox.style.display = 'none';
            }
        } catch (error) {
            console.error('Error fetching pegawai:', error);
        }
    }, 300);
}

document.addEventListener('click', function(e) {
    if (!document.getElementById('search_pegawai').contains(e.target)) {
        document.getElementById('pegawai-suggestions').style.display = 'none';
    }
});
</script>
