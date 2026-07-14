<?php
defined('host') or die('Akses langsung tidak diizinkan.');

// Ambil tanggal filter, default hari ini
$tgl_awal  = isset($_GET['tgl_awal'])  ? $_GET['tgl_awal']  : date('Y-m-d');
$tgl_akhir = isset($_GET['tgl_akhir']) ? $_GET['tgl_akhir'] : date('Y-m-d');

// Escape input
$tgl_awal_safe  = mysqli_real_escape_string($koneksi, $tgl_awal);
$tgl_akhir_safe = mysqli_real_escape_string($koneksi, $tgl_akhir);

// Query utama
$sql = "SELECT
    penjualan.tgl_jual,
    penjualan.nota_jual,
    SUM(detailjual.total) AS hpp,
    penjualan.ppn,
    penjualan.no_rkm_medis,
    penjualan.nm_pasien,
    pegawai.nama
FROM penjualan
INNER JOIN detailjual ON detailjual.nota_jual = penjualan.nota_jual
INNER JOIN pegawai    ON penjualan.nip        = pegawai.nik
WHERE penjualan.`status` = 'Sudah Dibayar'
  AND penjualan.tgl_jual BETWEEN '$tgl_awal_safe' AND '$tgl_akhir_safe'
GROUP BY penjualan.nota_jual
ORDER BY penjualan.tgl_jual, penjualan.nota_jual";

$result = mysqli_query($koneksi, $sql);

$data = [];
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $row['total'] = $row['hpp'] + $row['ppn'];
        $data[] = $row;
    }
}

// Hitung grand total
$grand_hpp   = 0;
$grand_ppn   = 0;
$grand_total = 0;
foreach ($data as $r) {
    $grand_hpp   += $r['hpp'];
    $grand_ppn   += $r['ppn'];
    $grand_total += $r['total'];
}
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Penjualan Bebas</h1>
        <p class="text-secondary" style="font-size:14px;">Laporan penjualan bebas: HPP, PPN, dan total per nota (status: Sudah Dibayar).</p>
    </div>
</div>

<div class="content-card">
    <!-- Filter Form -->
    <form method="get" style="display:flex; flex-wrap:wrap; gap:12px; align-items:flex-end; margin-bottom:20px;">
        <input type="hidden" name="page" value="kasir">
        <input type="hidden" name="sub"  value="penjualan_bebas">
        <div class="form-group" style="margin:0;">
            <label class="form-label">Dari Tanggal</label>
            <input type="date" name="tgl_awal" class="form-control" value="<?= htmlspecialchars($tgl_awal) ?>">
        </div>
        <div class="form-group" style="margin:0;">
            <label class="form-label">Sampai Tanggal</label>
            <input type="date" name="tgl_akhir" class="form-control" value="<?= htmlspecialchars($tgl_akhir) ?>">
        </div>
        <button type="submit" class="btn btn-primary" style="height:42px;">Tampilkan</button>
        <button type="button" class="btn btn-secondary" style="height:42px;" onclick="copyTableKasirPJB()">
            &#128203; Copy ke Clipboard
        </button>
    </form>

    <!-- Tabel -->
    <div class="table-responsive">
        <table class="table-custom" id="tabelKasirPJB" style="font-size:13px;">
            <thead>
                <tr>
                    <th style="text-align:center; width:40px;">No</th>
                    <th>Tgl Jual</th>
                    <th>Nota Jual</th>
                    <th style="text-align:right;">HPP (Total)</th>
                    <th style="text-align:right;">PPN</th>
                    <th style="text-align:right;">Jumlah (HPP+PPN)</th>
                    <th>No Rekam Medis</th>
                    <th>Nama Pasien</th>
                    <th>Nama Kasir</th>
                </tr>
            </thead>
            <tbody>
            <?php
            $no = 1;
            if (empty($data)):
            ?>
                <tr>
                    <td colspan="9" style="text-align:center; color:var(--text-secondary); padding:30px;">
                        Tidak ada data penjualan untuk rentang tanggal yang dipilih.
                    </td>
                </tr>
            <?php else: foreach ($data as $row): ?>
                <tr>
                    <td style="text-align:center;"><?= $no++ ?></td>
                    <td><?= htmlspecialchars($row['tgl_jual']) ?></td>
                    <td><?= htmlspecialchars($row['nota_jual']) ?></td>
                    <td style="text-align:right;"><?= number_format($row['hpp'],   2, ',', '.') ?></td>
                    <td style="text-align:right;"><?= number_format($row['ppn'],   2, ',', '.') ?></td>
                    <td style="text-align:right; font-weight:600;"><?= number_format($row['total'], 2, ',', '.') ?></td>
                    <td><?= htmlspecialchars($row['no_rkm_medis']) ?></td>
                    <td><?= htmlspecialchars($row['nm_pasien']) ?></td>
                    <td><?= htmlspecialchars($row['nama']) ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
            <tfoot>
                <tr style="font-weight:700; background:var(--bg-secondary);">
                    <td colspan="3" style="text-align:right; padding:10px;">Total Keseluruhan:</td>
                    <td style="text-align:right; padding:10px;"><?= number_format($grand_hpp,   2, ',', '.') ?></td>
                    <td style="text-align:right; padding:10px;"><?= number_format($grand_ppn,   2, ',', '.') ?></td>
                    <td style="text-align:right; padding:10px;"><?= number_format($grand_total, 2, ',', '.') ?></td>
                    <td colspan="3"></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<script>
function copyTableKasirPJB() {
    var table = document.getElementById('tabelKasirPJB');
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
