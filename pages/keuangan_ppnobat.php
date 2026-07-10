<?php
defined('host') or die('Akses langsung tidak diizinkan.');

// $koneksi sudah tersedia dari index.php (alatbantu)

// Ambil tanggal dari filter, default hari ini
$tgl_awal  = isset($_GET['tgl_awal'])  ? $_GET['tgl_awal']  : date('Y-m-d');
$tgl_akhir = isset($_GET['tgl_akhir']) ? $_GET['tgl_akhir'] : date('Y-m-d');
$kd_pj     = isset($_GET['kd_pj'])     ? $_GET['kd_pj']     : '';

// Ambil daftar PJ untuk filter dropdown
$query_pj  = "SELECT kd_pj, png_jawab FROM penjab ORDER BY png_jawab ASC";
$result_pj = mysqli_query($koneksi, $query_pj);
$list_pj   = [];
if ($result_pj) {
    while ($row_pj = mysqli_fetch_assoc($result_pj)) {
        $list_pj[] = $row_pj;
    }
}

// Query data utama
$kd_pj_safe = mysqli_real_escape_string($koneksi, $kd_pj);
$tgl_awal_safe  = mysqli_real_escape_string($koneksi, $tgl_awal);
$tgl_akhir_safe = mysqli_real_escape_string($koneksi, $tgl_akhir);

$sql = "SELECT reg_periksa.tgl_registrasi, reg_periksa.no_rawat,
               pasien.no_rkm_medis, pasien.nm_pasien, penjab.png_jawab,
               databarang.kode_brng, databarang.nama_brng, databarang.kode_sat,
               detail_pemberian_obat.jml, detail_pemberian_obat.total
        FROM reg_periksa
        INNER JOIN pasien          ON reg_periksa.no_rkm_medis          = pasien.no_rkm_medis
        INNER JOIN penjab          ON reg_periksa.kd_pj                 = penjab.kd_pj
        INNER JOIN detail_pemberian_obat ON detail_pemberian_obat.no_rawat = reg_periksa.no_rawat
        INNER JOIN databarang      ON detail_pemberian_obat.kode_brng   = databarang.kode_brng
        WHERE reg_periksa.status_lanjut = 'ralan'
          AND reg_periksa.tgl_registrasi BETWEEN '$tgl_awal_safe' AND '$tgl_akhir_safe'";

if (!empty($kd_pj_safe)) {
    $sql .= " AND reg_periksa.kd_pj = '$kd_pj_safe'";
}
$sql .= " ORDER BY reg_periksa.tgl_registrasi, reg_periksa.no_rawat, databarang.kode_brng";
$result = mysqli_query($koneksi, $sql);

// Proses data (merge rowspan)
$data = [];
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $key = $row['tgl_registrasi'] . '|' . $row['no_rawat'];
        if (!isset($data[$key])) {
            $data[$key] = [
                'tgl_registrasi' => $row['tgl_registrasi'],
                'no_rawat'       => $row['no_rawat'],
                'no_rkm_medis'   => $row['no_rkm_medis'],
                'nm_pasien'      => $row['nm_pasien'],
                'png_jawab'      => $row['png_jawab'],
                'obat'           => []
            ];
        }
        $data[$key]['obat'][] = [
            'kode_brng' => $row['kode_brng'],
            'nama_brng' => $row['nama_brng'],
            'kode_sat'  => $row['kode_sat'],
            'jml'       => $row['jml'],
            'total'     => $row['total']
        ];
    }
}
?>

<div class="page-header">
    <div>
        <h1 class="page-title">PPN Obat Pasien Ralan</h1>
        <p class="text-secondary" style="font-size:14px;">Laporan detail obat pasien rawat jalan beserta perhitungan PPN.</p>
    </div>
</div>

<div class="content-card">
    <!-- Filter Form -->
    <form method="get" style="display:flex; flex-wrap:wrap; gap:12px; align-items:flex-end; margin-bottom:20px;">
        <input type="hidden" name="page" value="keuangan">
        <input type="hidden" name="sub"  value="ppnobat">

        <div class="form-group" style="margin:0;">
            <label class="form-label">Dari Tanggal</label>
            <input type="date" name="tgl_awal" class="form-control" value="<?= htmlspecialchars($tgl_awal) ?>">
        </div>
        <div class="form-group" style="margin:0;">
            <label class="form-label">Sampai Tanggal</label>
            <input type="date" name="tgl_akhir" class="form-control" value="<?= htmlspecialchars($tgl_akhir) ?>">
        </div>
        <div class="form-group" style="margin:0;">
            <label class="form-label">Penanggung Jawab</label>
            <select name="kd_pj" class="form-control" style="min-width:180px;">
                <option value="">- Semua -</option>
                <?php foreach ($list_pj as $pj): ?>
                    <option value="<?= htmlspecialchars($pj['kd_pj']) ?>" <?= $kd_pj === $pj['kd_pj'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($pj['png_jawab']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-primary" style="height:42px;">Tampilkan</button>
        <button type="button" class="btn btn-secondary" style="height:42px;" onclick="copyTablePPN()">
            📋 Copy ke Clipboard
        </button>
    </form>

    <!-- Tabel Data -->
    <div class="table-responsive">
        <table class="table-custom" id="tabelppn" style="font-size:13px;">
            <thead>
                <tr>
                    <th>No</th>
                    <th>Tgl Registrasi</th>
                    <th>No Rawat</th>
                    <th>No RM</th>
                    <th>Nama Pasien</th>
                    <th>PJ</th>
                    <th>Kode Barang</th>
                    <th>Nama Barang</th>
                    <th>Satuan</th>
                    <th style="text-align:right;">Jml</th>
                    <th style="text-align:right;">Total</th>
                    <th style="text-align:right;">PPN (11%)</th>
                </tr>
            </thead>
            <tbody>
            <?php
            $no          = 1;
            $total_jml   = 0;
            $total_biaya = 0;
            $total_ppn   = 0;

            if (empty($data)): ?>
                <tr><td colspan="12" style="text-align:center; color:var(--text-secondary); padding:30px;">
                    Tidak ada data untuk rentang tanggal yang dipilih.
                </td></tr>
            <?php else:
                foreach ($data as $key => $row):
                    $rowspan = count($row['obat']);
                    $first   = true;
                    foreach ($row['obat'] as $obat):
                        $total_jml   += $obat['jml'];
                        $total_biaya += $obat['total'];
                        $total_ppn   += $obat['total'] * 0.11;
                        echo '<tr>';
                        if ($first) {
                            echo '<td rowspan="'.$rowspan.'">'.$no.'</td>';
                            echo '<td rowspan="'.$rowspan.'">'.htmlspecialchars($row['tgl_registrasi']).'</td>';
                            echo '<td rowspan="'.$rowspan.'">'.htmlspecialchars($row['no_rawat']).'</td>';
                            echo '<td rowspan="'.$rowspan.'">'.htmlspecialchars($row['no_rkm_medis']).'</td>';
                            echo '<td rowspan="'.$rowspan.'">'.htmlspecialchars($row['nm_pasien']).'</td>';
                            echo '<td rowspan="'.$rowspan.'">'.htmlspecialchars($row['png_jawab']).'</td>';
                            $first = false;
                            $no++;
                        }
                        echo '<td>'.htmlspecialchars($obat['kode_brng']).'</td>';
                        echo '<td>'.htmlspecialchars($obat['nama_brng']).'</td>';
                        echo '<td>'.htmlspecialchars($obat['kode_sat']).'</td>';
                        echo '<td style="text-align:right;">'.$obat['jml'].'</td>';
                        echo '<td style="text-align:right;">'.number_format($obat['total'], 2, ',', '.').'</td>';
                        echo '<td style="text-align:right;">'.number_format($obat['total'] * 0.11, 2, ',', '.').'</td>';
                        echo '</tr>';
                    endforeach;
                endforeach;
            endif;
            ?>
            </tbody>
            <tfoot>
                <tr style="font-weight:700; background:var(--bg-secondary);">
                    <td colspan="9" style="text-align:right; padding:10px;">Total Keseluruhan:</td>
                    <td style="text-align:right; padding:10px;"><?= number_format($total_jml, 0, ',', '.') ?></td>
                    <td style="text-align:right; padding:10px;"><?= number_format($total_biaya, 2, ',', '.') ?></td>
                    <td style="text-align:right; padding:10px;"><?= number_format($total_ppn, 2, ',', '.') ?></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<script>
function copyTablePPN() {
    var table = document.getElementById('tabelppn');
    if (!table) return;
    var range = document.createRange();
    range.selectNode(table);
    var sel = window.getSelection();
    sel.removeAllRanges();
    sel.addRange(range);
    try { document.execCommand('copy'); alert('Tabel berhasil disalin ke clipboard!'); }
    catch(e) { alert('Gagal menyalin: ' + e); }
    sel.removeAllRanges();
}
</script>
