<?php
defined('host') or die('Akses langsung tidak diizinkan.');

// $koneksi sudah tersedia dari index.php (alatbantu)

// Helper: ambil distinct options untuk dropdown
function pp_getOptions($koneksi, $field, $table, $where = "") {
    $options = [];
    $query   = "SELECT DISTINCT $field FROM $table $where ORDER BY $field";
    $result  = mysqli_query($koneksi, $query);
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $options[] = $row[$field];
        }
    }
    return $options;
}

// Default filter values
$tanggal_awal    = isset($_POST['tanggal_awal'])    ? $_POST['tanggal_awal']    : date('Y-m-01');
$tanggal_akhir   = isset($_POST['tanggal_akhir'])   ? $_POST['tanggal_akhir']   : date('Y-m-d');
$penjab          = isset($_POST['penjab'])          ? $_POST['penjab']          : '';
$nm_poli         = isset($_POST['nm_poli'])         ? $_POST['nm_poli']         : '';
$jenis_rawat     = isset($_POST['jenis_rawat'])     ? $_POST['jenis_rawat']     : '';
$filter_tanggal  = isset($_POST['filter_tanggal'])  ? $_POST['filter_tanggal']  : 'bayar';
$show_column     = isset($_POST['show_column'])     ? $_POST['show_column']     : 'all';

// Dropdown options
$penjab_options = pp_getOptions($koneksi, 'png_jawab', 'penjab');
$poli_options   = pp_getOptions($koneksi, 'nm_poli', 'poliklinik');
?>

<div class="page-header">
    <div>
        <h1 class="page-title">💰 Payment Point</h1>
        <p class="text-secondary" style="font-size:14px;">Laporan pembayaran pasien rawat inap, rawat jalan, dan piutang.</p>
    </div>
</div>

<div class="content-card">
    <!-- Filter Form -->
    <form method="POST" action="index.php?page=kasir&sub=payment_point">
        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px,1fr)); gap:14px; margin-bottom:16px;">

            <div class="form-group" style="margin:0;">
                <label class="form-label">🔀 Filter Berdasarkan</label>
                <select name="filter_tanggal" class="form-control">
                    <option value="bayar"      <?= $filter_tanggal === 'bayar'      ? 'selected' : '' ?>>Tanggal Bayar</option>
                    <option value="registrasi" <?= $filter_tanggal === 'registrasi' ? 'selected' : '' ?>>Tanggal Registrasi</option>
                </select>
            </div>

            <div class="form-group" style="margin:0;">
                <label class="form-label">📅 Tanggal Awal</label>
                <input type="date" name="tanggal_awal" class="form-control"
                       id="tanggal_awal" value="<?= htmlspecialchars($tanggal_awal) ?>" required>
            </div>

            <div class="form-group" style="margin:0;">
                <label class="form-label">📅 Tanggal Akhir</label>
                <input type="date" name="tanggal_akhir" class="form-control"
                       id="tanggal_akhir" value="<?= htmlspecialchars($tanggal_akhir) ?>" required>
            </div>

            <div class="form-group" style="margin:0;">
                <label class="form-label">💳 Penjab</label>
                <select name="penjab" class="form-control" id="penjab">
                    <option value="">-- Semua Penjab --</option>
                    <?php foreach ($penjab_options as $opt): ?>
                        <option value="<?= htmlspecialchars($opt) ?>" <?= $penjab === $opt ? 'selected' : '' ?>>
                            <?= htmlspecialchars($opt) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" style="margin:0;">
                <label class="form-label">🏥 Poliklinik</label>
                <select name="nm_poli" class="form-control" id="nm_poli">
                    <option value="">-- Semua Poliklinik --</option>
                    <?php foreach ($poli_options as $opt): ?>
                        <option value="<?= htmlspecialchars($opt) ?>" <?= $nm_poli === $opt ? 'selected' : '' ?>>
                            <?= htmlspecialchars($opt) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" style="margin:0;">
                <label class="form-label">🩺 Jenis Rawat</label>
                <select name="jenis_rawat" class="form-control" id="jenis_rawat">
                    <option value="">-- Semua Jenis --</option>
                    <option value="RAWAT INAP"  <?= $jenis_rawat === 'RAWAT INAP'  ? 'selected' : '' ?>>🏥 Rawat Inap</option>
                    <option value="RAWAT JALAN" <?= $jenis_rawat === 'RAWAT JALAN' ? 'selected' : '' ?>>🚶 Rawat Jalan</option>
                </select>
            </div>

            <div class="form-group" style="margin:0;">
                <label class="form-label">💰 Pilih Tunai/Piutang</label>
                <select name="show_column" class="form-control" id="show_column">
                    <option value="all"          <?= $show_column === 'all'          ? 'selected' : '' ?>>Semua</option>
                    <option value="total_bayar"  <?= $show_column === 'total_bayar'  ? 'selected' : '' ?>>Total Bayar saja</option>
                    <option value="sisapiutang"  <?= $show_column === 'sisapiutang'  ? 'selected' : '' ?>>Sisa Piutang saja</option>
                </select>
            </div>
        </div>

        <div style="display:flex; gap:10px; flex-wrap:wrap;">
            <button type="submit" name="filter" class="btn btn-primary">💰 Tampilkan Data</button>
            <button type="button" class="btn btn-secondary" onclick="ppResetForm()">🔄 Reset Filter</button>
            <?php if (isset($_POST['filter'])): ?>
            <button type="button" class="btn btn-secondary" onclick="ppCopyTable()">📋 Copy Tabel</button>
            <?php endif; ?>
        </div>
    </form>
</div>

<?php if (isset($_POST['filter'])): ?>
<div class="content-card" style="margin-top:0; padding-top:0;">
<?php
    // Escape values
    $ta  = mysqli_real_escape_string($koneksi, $tanggal_awal);
    $tb  = mysqli_real_escape_string($koneksi, $tanggal_akhir);
    $pj  = mysqli_real_escape_string($koneksi, $penjab);
    $poli= mysqli_real_escape_string($koneksi, $nm_poli);

    $select_part = "SELECT DISTINCT
        CASE
            WHEN nota_inap.no_rawat  IS NOT NULL THEN 'RAWAT INAP'
            WHEN nota_jalan.no_rawat IS NOT NULL THEN 'RAWAT JALAN'
            ELSE 'PIUTANG'
        END as jenis_rawat,
        COALESCE(nota_inap.tanggal, nota_jalan.tanggal, piutang_pasien.tgl_piutang) as tanggal_bayar,
        COALESCE(nota_inap.jam, nota_jalan.jam, '00:00:00') as jam_bayar,
        reg_periksa.no_rawat,
        CASE
            WHEN nota_inap.no_rawat  IS NOT NULL THEN nota_inap.no_nota
            WHEN nota_jalan.no_rawat IS NOT NULL THEN nota_jalan.no_nota
            ELSE ''
        END as no_nota,
        pasien.no_rkm_medis,
        pasien.nm_pasien,
        penjab.png_jawab,
        poliklinik.nm_poli,
        (SELECT totalbiaya FROM billing WHERE billing.no_rawat = reg_periksa.no_rawat AND billing.nm_perawatan = 'PPN Obat' LIMIT 1) as ppn_obat,
        COALESCE(
            (SELECT SUM(detail_nota_inap.besar_bayar)  FROM detail_nota_inap  WHERE detail_nota_inap.no_rawat  = reg_periksa.no_rawat),
            (SELECT SUM(detail_nota_jalan.besar_bayar) FROM detail_nota_jalan WHERE detail_nota_jalan.no_rawat = reg_periksa.no_rawat),
            0
        ) as total_bayar,
        COALESCE(piutang_pasien.sisapiutang, 0) as sisapiutang
    FROM reg_periksa
    INNER JOIN pasien      ON reg_periksa.no_rkm_medis = pasien.no_rkm_medis
    INNER JOIN penjab      ON reg_periksa.kd_pj        = penjab.kd_pj
    INNER JOIN poliklinik  ON reg_periksa.kd_poli      = poliklinik.kd_poli";

    if ($filter_tanggal === 'registrasi') {
        $query = $select_part . "
        LEFT JOIN nota_inap       ON reg_periksa.no_rawat = nota_inap.no_rawat
        LEFT JOIN nota_jalan      ON reg_periksa.no_rawat = nota_jalan.no_rawat
        LEFT JOIN piutang_pasien  ON reg_periksa.no_rawat = piutang_pasien.no_rawat
        WHERE reg_periksa.tgl_registrasi BETWEEN '$ta' AND '$tb'";
    } else {
        $query = $select_part . "
        LEFT JOIN nota_inap       ON reg_periksa.no_rawat = nota_inap.no_rawat
                                  AND nota_inap.tanggal       BETWEEN '$ta' AND '$tb'
        LEFT JOIN nota_jalan      ON reg_periksa.no_rawat = nota_jalan.no_rawat
                                  AND nota_jalan.tanggal      BETWEEN '$ta' AND '$tb'
        LEFT JOIN piutang_pasien  ON reg_periksa.no_rawat = piutang_pasien.no_rawat
                                  AND piutang_pasien.tgl_piutang BETWEEN '$ta' AND '$tb'
        WHERE (nota_inap.tanggal IS NOT NULL OR nota_jalan.tanggal IS NOT NULL OR piutang_pasien.tgl_piutang IS NOT NULL)";
    }

    if ($jenis_rawat === 'RAWAT INAP')  $query .= " AND nota_inap.no_rawat  IS NOT NULL";
    if ($jenis_rawat === 'RAWAT JALAN') $query .= " AND nota_jalan.no_rawat IS NOT NULL";
    if ($pj   !== '') $query .= " AND penjab.png_jawab     = '$pj'";
    if ($poli !== '') $query .= " AND poliklinik.nm_poli   = '$poli'";
    $query .= " ORDER BY tanggal_bayar DESC, jam_bayar DESC";

    $result = mysqli_query($koneksi, $query);

    if ($result) {
        $total_rows        = mysqli_num_rows($result);
        $total_pembayaran  = 0;
        $total_sisapiutang = 0;
        $rows_data         = [];

        while ($r = mysqli_fetch_assoc($result)) {
            $rows_data[]        = $r;
            $total_pembayaran  += $r['total_bayar']   ?? 0;
            $total_sisapiutang += $r['sisapiutang']   ?? 0;
        }

        // Summary bar
        echo '<div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; padding: 16px 0 14px; border-bottom:1px solid var(--border-color); margin-bottom:12px;">';
        echo '<div style="font-size:13.5px; font-weight:600; color:var(--text-secondary);">';
        echo 'Total Data: <span style="color:var(--accent);">'.$total_rows.'</span>';
        if ($show_column !== 'sisapiutang')
            echo ' &nbsp;|&nbsp; Total Pembayaran: <span style="color:#059669;">Rp '.number_format($total_pembayaran, 0, ',', '.').'</span>';
        if ($show_column !== 'total_bayar')
            echo ' &nbsp;|&nbsp; Total Sisa Piutang: <span style="color:#dc2626;">Rp '.number_format($total_sisapiutang, 0, ',', '.').'</span>';
        echo '</div></div>';

        if ($total_rows === 0) {
            echo '<div style="text-align:center; color:var(--text-secondary); padding:36px; font-style:italic;">Tidak ada data pembayaran pada filter yang dipilih.</div>';
        } else {
            echo '<div class="table-responsive"><table class="table-custom" id="tabelPaymentPoint" style="font-size:13px; min-width:900px;">';
            echo '<thead><tr>
                    <th>No</th>
                    <th>Jenis Rawat</th>
                    <th>Tanggal</th>
                    <th>Jam Bayar</th>
                    <th>No Rawat</th>
                    <th>No Nota</th>
                    <th>No RM</th>
                    <th>Nama Pasien</th>
                    <th>Penjab</th>
                    <th>Poliklinik</th>
                    <th style="text-align:right;">PPN Obat</th>
                    <th style="text-align:right;">Biaya Sebelum PPN</th>';
            if ($show_column === 'all' || $show_column === 'total_bayar')  echo '<th style="text-align:right;">Total Bayar</th>';
            if ($show_column === 'all' || $show_column === 'sisapiutang')  echo '<th style="text-align:right;">Sisa Piutang</th>';
            echo '</tr></thead><tbody>';

            $no                     = 1;
            $sum_ppn_obat           = 0;
            $sum_biaya_sebelum      = 0;
            $sum_total_bayar        = 0;
            $sum_total_sisapiutang  = 0;

            foreach ($rows_data as $row) {
                $jenis_class      = '';
                if ($row['jenis_rawat'] === 'RAWAT INAP')  $jenis_class = 'style="background:#d1ecf1; color:#0c5460;"';
                if ($row['jenis_rawat'] === 'RAWAT JALAN') $jenis_class = 'style="background:#f8d7da; color:#721c24;"';

                $ppn_obat        = $row['ppn_obat']    ?? 0;
                $total_bayar     = $row['total_bayar'] ?? 0;
                $sisapiutang     = $row['sisapiutang'] ?? 0;
                $biaya_sblm_ppn  = $total_bayar + $sisapiutang - $ppn_obat;

                $sum_ppn_obat          += $ppn_obat;
                $sum_biaya_sebelum     += $biaya_sblm_ppn;
                $sum_total_bayar       += $total_bayar;
                $sum_total_sisapiutang += $sisapiutang;

                echo "<tr>
                    <td>{$no}</td>
                    <td {$jenis_class}>{$row['jenis_rawat']}</td>
                    <td>{$row['tanggal_bayar']}</td>
                    <td>{$row['jam_bayar']}</td>
                    <td>{$row['no_rawat']}</td>
                    <td>{$row['no_nota']}</td>
                    <td>{$row['no_rkm_medis']}</td>
                    <td>" . htmlspecialchars($row['nm_pasien']) . "</td>
                    <td>" . htmlspecialchars($row['png_jawab']) . "</td>
                    <td>" . htmlspecialchars($row['nm_poli'])   . "</td>
                    <td style='text-align:right;'>Rp " . number_format($ppn_obat,       0, ',', '.') . "</td>
                    <td style='text-align:right;'>Rp " . number_format($biaya_sblm_ppn, 0, ',', '.') . "</td>";
                if ($show_column === 'all' || $show_column === 'total_bayar')
                    echo "<td style='text-align:right; font-weight:600;'>Rp " . number_format($total_bayar, 0, ',', '.') . "</td>";
                if ($show_column === 'all' || $show_column === 'sisapiutang')
                    echo "<td style='text-align:right; font-weight:600; color:#dc2626;'>Rp " . number_format($sisapiutang, 0, ',', '.') . "</td>";
                echo "</tr>";
                $no++;
            }

            // Baris total
            echo "<tr style='font-weight:700; background:var(--bg-secondary);'>
                    <td colspan='10' style='text-align:right; padding:10px;'>TOTAL</td>
                    <td style='text-align:right; padding:10px;'>Rp " . number_format($sum_ppn_obat,      0, ',', '.') . "</td>
                    <td style='text-align:right; padding:10px;'>Rp " . number_format($sum_biaya_sebelum, 0, ',', '.') . "</td>";
            if ($show_column === 'all' || $show_column === 'total_bayar')
                echo "<td style='text-align:right; padding:10px;'>Rp " . number_format($sum_total_bayar,       0, ',', '.') . "</td>";
            if ($show_column === 'all' || $show_column === 'sisapiutang')
                echo "<td style='text-align:right; padding:10px; color:#dc2626;'>Rp " . number_format($sum_total_sisapiutang, 0, ',', '.') . "</td>";
            echo "</tr>";
            echo '</tbody></table></div>';
        }
    } else {
        echo '<div style="background:rgba(239,68,68,0.1); border:1px solid rgba(239,68,68,0.3); color:#991b1b; padding:14px; border-radius:10px; margin-top:12px;">';
        echo '❌ Terjadi kesalahan query: ' . mysqli_error($koneksi);
        echo '</div>';
    }
?>
</div>
<?php endif; ?>

<script>
function ppResetForm() {
    document.getElementById('tanggal_awal').value   = '<?= date('Y-m-01') ?>';
    document.getElementById('tanggal_akhir').value  = '<?= date('Y-m-d') ?>';
    document.getElementById('penjab').value         = '';
    document.getElementById('nm_poli').value        = '';
    document.getElementById('jenis_rawat').value    = '';
    document.getElementById('show_column').value    = 'all';
}

function ppCopyTable() {
    var table = document.getElementById('tabelPaymentPoint');
    if (!table) { alert('Tidak ada tabel untuk disalin.'); return; }
    var range = document.createRange();
    range.selectNode(table);
    var sel = window.getSelection();
    sel.removeAllRanges();
    sel.addRange(range);
    try {
        document.execCommand('copy');
        alert('✅ Tabel berhasil disalin ke clipboard!');
    } catch(e) {
        alert('❌ Gagal menyalin: ' + e);
    }
    sel.removeAllRanges();
}
</script>
