<?php
defined('host') or die('Akses langsung tidak diizinkan.');

// Ambil nilai filter dari GET request
$tgl_pesan_start = isset($_GET['tgl_pesan_start']) ? $_GET['tgl_pesan_start'] : date('Y-m-01');
$tgl_pesan_end   = isset($_GET['tgl_pesan_end']) ? $_GET['tgl_pesan_end'] : date('Y-m-d');
$tgl_faktur_start = isset($_GET['tgl_faktur_start']) ? $_GET['tgl_faktur_start'] : '';
$tgl_faktur_end   = isset($_GET['tgl_faktur_end']) ? $_GET['tgl_faktur_end'] : '';
$suplier = isset($_GET['suplier']) ? $_GET['suplier'] : '';
$status  = isset($_GET['status']) ? $_GET['status'] : '';

// Bangun klausa WHERE secara dinamis
$where_clauses = [];
$where_clauses[] = "pemesanan.tgl_pesan BETWEEN '$tgl_pesan_start' AND '$tgl_pesan_end'";

if (!empty($tgl_faktur_start) && !empty($tgl_faktur_end)) {
    $where_clauses[] = "pemesanan.tgl_faktur BETWEEN '$tgl_faktur_start' AND '$tgl_faktur_end'";
}
if (!empty($suplier)) {
    $where_clauses[] = "datasuplier.nama_suplier LIKE '%" . $koneksi->real_escape_string($suplier) . "%'";
}
if (!empty($status)) {
    $where_clauses[] = "pemesanan.status = '" . $koneksi->real_escape_string($status) . "'";
}

$where_sql = implode(' AND ', $where_clauses);

$query = "SELECT
            pemesanan.tgl_pesan,
            pemesanan.tgl_faktur,
            pemesanan.no_faktur,
            datasuplier.nama_suplier,
            pemesanan.total2,
            pemesanan.ppn,
            pemesanan.meterai,
            pemesanan.tagihan,
            pemesanan.status
          FROM
            pemesanan
          INNER JOIN datasuplier ON pemesanan.kode_suplier = datasuplier.kode_suplier
          WHERE $where_sql
          ORDER BY pemesanan.tgl_pesan DESC";

$result = $koneksi->query($query);
?>

<div class="content-header">
    <h2 class="content-title">Hutang Belanja Medis (Obat & BHP)</h2>
</div>

<div class="content-card">
    <!-- Form Filter -->
    <form method="GET" action="index.php" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 20px;">
        <input type="hidden" name="page" value="keuangan">
        <input type="hidden" name="sub" value="hutang_medis">
        
        <div>
            <label>Tgl Datang (Pesan) Dari</label>
            <input type="date" name="tgl_pesan_start" class="form-control" value="<?= $tgl_pesan_start ?>" required>
        </div>
        <div>
            <label>Tgl Datang (Pesan) Sampai</label>
            <input type="date" name="tgl_pesan_end" class="form-control" value="<?= $tgl_pesan_end ?>" required>
        </div>
        <div>
            <label>Tgl Faktur Dari (Opsional)</label>
            <input type="date" name="tgl_faktur_start" class="form-control" value="<?= $tgl_faktur_start ?>">
        </div>
        <div>
            <label>Tgl Faktur Sampai (Opsional)</label>
            <input type="date" name="tgl_faktur_end" class="form-control" value="<?= $tgl_faktur_end ?>">
        </div>
        <div>
            <label>Nama Suplier</label>
            <select name="suplier" class="form-control">
                <option value="">Semua Suplier</option>
                <?php
                $q_suplier = "SELECT kode_suplier, nama_suplier FROM datasuplier ORDER BY nama_suplier ASC";
                $rs_suplier = $koneksi->query($q_suplier);
                if ($rs_suplier) {
                    while ($sup = $rs_suplier->fetch_assoc()) {
                        $selected = ($suplier == $sup['nama_suplier']) ? 'selected' : '';
                        echo "<option value='" . htmlspecialchars($sup['nama_suplier']) . "' {$selected}>" . htmlspecialchars($sup['nama_suplier']) . "</option>";
                    }
                }
                ?>
            </select>
        </div>
        <div>
            <label>Status Bayar</label>
            <select name="status" class="form-control">
                <option value="">Semua Status</option>
                <option value="Belum Dibayar" <?= $status === 'Belum Dibayar' ? 'selected' : '' ?>>Belum Dibayar</option>
                <option value="Sudah Dibayar" <?= $status === 'Sudah Dibayar' ? 'selected' : '' ?>>Sudah Dibayar</option>
                <option value="Belum Lunas" <?= $status === 'Belum Lunas' ? 'selected' : '' ?>>Belum Lunas</option>
                <option value="Titip Faktur" <?= $status === 'Titip Faktur' ? 'selected' : '' ?>>Titip Faktur</option>
            </select>
        </div>
        <div style="display: flex; align-items: flex-end;">
            <button type="submit" class="btn btn-primary" style="width: 100%; height: 38px;">Filter Tampilkan</button>
        </div>
    </form>

    <!-- Tombol Aksi -->
    <div style="display: flex; justify-content: flex-end; margin-bottom: 10px;">
        <button onclick="copyTable()" class="btn btn-secondary" style="display: flex; align-items: center; gap: 8px;">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
            Copy Table
        </button>
    </div>

    <!-- Tabel Data -->
    <div style="overflow-x: auto;">
        <table class="table" id="tabelHutang" style="border-collapse: collapse; width: 100%;">
            <thead>
                <tr>
                    <th style="border: 1px solid #cbd5e1; padding: 10px;">No</th>
                    <th style="border: 1px solid #cbd5e1; padding: 10px;">Tgl Pesan</th>
                    <th style="border: 1px solid #cbd5e1; padding: 10px;">Tgl Faktur</th>
                    <th style="border: 1px solid #cbd5e1; padding: 10px;">No Faktur</th>
                    <th style="border: 1px solid #cbd5e1; padding: 10px;">Nama Suplier</th>
                    <th style="border: 1px solid #cbd5e1; padding: 10px; text-align: right;">Total</th>
                    <th style="border: 1px solid #cbd5e1; padding: 10px; text-align: right;">PPN</th>
                    <th style="border: 1px solid #cbd5e1; padding: 10px; text-align: right;">Meterai</th>
                    <th style="border: 1px solid #cbd5e1; padding: 10px; text-align: right;">Tagihan</th>
                    <th style="border: 1px solid #cbd5e1; padding: 10px;">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php
                if ($result && $result->num_rows > 0) {
                    $no = 1;
                    $sum_total2 = 0;
                    $sum_ppn = 0;
                    $sum_meterai = 0;
                    $sum_tagihan = 0;

                    while ($row = $result->fetch_assoc()) {
                        $sum_total2 += $row['total2'];
                        $sum_ppn += $row['ppn'];
                        $sum_meterai += $row['meterai'];
                        $sum_tagihan += $row['tagihan'];
                        
                        // Badge status
                        $status_badge = '';
                        if ($row['status'] == 'Sudah Dibayar') {
                            $status_badge = "<span style='background:#d1fae5; color:#065f46; padding:4px 8px; border-radius:4px; font-size:12px; font-weight:600;'>Sudah Dibayar</span>";
                        } elseif ($row['status'] == 'Belum Dibayar') {
                            $status_badge = "<span style='background:#fee2e2; color:#991b1b; padding:4px 8px; border-radius:4px; font-size:12px; font-weight:600;'>Belum Dibayar</span>";
                        } elseif ($row['status'] == 'Belum Lunas') {
                            $status_badge = "<span style='background:#fef3c7; color:#92400e; padding:4px 8px; border-radius:4px; font-size:12px; font-weight:600;'>Belum Lunas</span>";
                        } elseif ($row['status'] == 'Titip Faktur') {
                            $status_badge = "<span style='background:#dbeafe; color:#1e40af; padding:4px 8px; border-radius:4px; font-size:12px; font-weight:600;'>Titip Faktur</span>";
                        } else {
                            $status_badge = "<span style='background:#f3f4f6; color:#374151; padding:4px 8px; border-radius:4px; font-size:12px; font-weight:600;'>" . htmlspecialchars($row['status']) . "</span>";
                        }

                        echo "<tr>
                                <td style='border: 1px solid #e2e8f0; padding: 8px;'>{$no}</td>
                                <td style='border: 1px solid #e2e8f0; padding: 8px;'>" . date('d-m-Y', strtotime($row['tgl_pesan'])) . "</td>
                                <td style='border: 1px solid #e2e8f0; padding: 8px;'>" . (!empty($row['tgl_faktur']) && $row['tgl_faktur'] != '0000-00-00' ? date('d-m-Y', strtotime($row['tgl_faktur'])) : '-') . "</td>
                                <td style='border: 1px solid #e2e8f0; padding: 8px;'>" . htmlspecialchars($row['no_faktur']) . "</td>
                                <td style='border: 1px solid #e2e8f0; padding: 8px;'>" . htmlspecialchars($row['nama_suplier']) . "</td>
                                <td style='border: 1px solid #e2e8f0; padding: 8px; text-align: right;'>" . number_format($row['total2'], 0, ',', '.') . "</td>
                                <td style='border: 1px solid #e2e8f0; padding: 8px; text-align: right;'>" . number_format($row['ppn'], 0, ',', '.') . "</td>
                                <td style='border: 1px solid #e2e8f0; padding: 8px; text-align: right;'>" . number_format($row['meterai'], 0, ',', '.') . "</td>
                                <td style='border: 1px solid #e2e8f0; padding: 8px; text-align: right;'><strong>" . number_format($row['tagihan'], 0, ',', '.') . "</strong></td>
                                <td style='border: 1px solid #e2e8f0; padding: 8px;'>{$status_badge}</td>
                              </tr>";
                        $no++;
                    }
                    
                    // Baris Total Keseluruhan
                    echo "<tr style='background: #f8fafc; font-weight: bold;'>
                            <td colspan='5' style='border: 1px solid #cbd5e1; text-align: right; padding: 10px 15px;'>TOTAL KESELURUHAN</td>
                            <td style='border: 1px solid #cbd5e1; padding: 10px; text-align: right;'>" . number_format($sum_total2, 0, ',', '.') . "</td>
                            <td style='border: 1px solid #cbd5e1; padding: 10px; text-align: right;'>" . number_format($sum_ppn, 0, ',', '.') . "</td>
                            <td style='border: 1px solid #cbd5e1; padding: 10px; text-align: right;'>" . number_format($sum_meterai, 0, ',', '.') . "</td>
                            <td style='border: 1px solid #cbd5e1; padding: 10px; text-align: right; color:#6366f1;'>" . number_format($sum_tagihan, 0, ',', '.') . "</td>
                            <td style='border: 1px solid #cbd5e1; padding: 10px;'></td>
                          </tr>";
                } else {
                    echo "<tr><td colspan='10' style='border: 1px solid #e2e8f0; text-align: center; padding: 20px;'>Data hutang medis tidak ditemukan pada filter yang dipilih.</td></tr>";
                }
                ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function copyTable() {
    const table = document.getElementById("tabelHutang");
    const range = document.createRange();
    range.selectNode(table);
    
    const selection = window.getSelection();
    selection.removeAllRanges();
    selection.addRange(range);
    
    try {
        document.execCommand('copy');
        alert("Tabel berhasil di-copy ke clipboard! Silakan paste (Ctrl+V) ke Excel atau Word.");
    } catch (err) {
        alert("Gagal meng-copy tabel. Silakan block manual.");
    }
    
    selection.removeAllRanges();
}
</script>
