<?php
defined('host') or die('Akses langsung tidak diizinkan.');

// Handle hapus data
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['nik']) && isset($_GET['periode'])) {
    $nik_del = $_GET['nik'];
    $periode_del = $_GET['periode'];
    $stmt1 = $koneksi->prepare("DELETE FROM gajidantunjangan_detail WHERE nik = ? AND periode_gaji = ?");
    $stmt1->bind_param("ss", $nik_del, $periode_del);
    $stmt1->execute();
    
    $stmt2 = $koneksi->prepare("DELETE FROM gajidantunjangan WHERE nik = ? AND periode_gaji = ?");
    $stmt2->bind_param("ss", $nik_del, $periode_del);
    $stmt2->execute();
    
    echo "<script>alert('Data berhasil dihapus'); window.location.href='index.php?page=manajemen&sub=penggajian';</script>";
    exit;
}

// Handle simpan data
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['simpan_gaji'])) {
    $nik = $_POST['nik'];
    $periode_gaji = $_POST['periode_gaji'];
    $tanggal_cetak = $_POST['tanggal_cetak'];
    $total_penerimaan = $_POST['total_penerimaan_val'];
    $total_potongan = $_POST['total_potongan_val'];
    $gaji_diterima = $_POST['gaji_diterima_val'];
    
    // Cek apakah sudah ada
    $cek = $koneksi->query("SELECT * FROM gajidantunjangan WHERE nik='$nik' AND periode_gaji='$periode_gaji'");
    if ($cek->num_rows > 0) {
        echo "<script>alert('Data gaji untuk pegawai dan periode tersebut sudah ada!');</script>";
    } else {
        $stmt = $koneksi->prepare("INSERT INTO gajidantunjangan (nik, periode_gaji, total_penerimaan, total_potongan, gaji_diterima, tanggal_cetak) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssddds", $nik, $periode_gaji, $total_penerimaan, $total_potongan, $gaji_diterima, $tanggal_cetak);
        if ($stmt->execute()) {
            $stmt_det = $koneksi->prepare("INSERT INTO gajidantunjangan_detail (nik, periode_gaji, kode_komponen, nominal) VALUES (?, ?, ?, ?)");
            foreach ($_POST['komponen'] as $kode => $nominal) {
                if ($nominal != "" && $nominal >= 0) {
                    $stmt_det->bind_param("sssd", $nik, $periode_gaji, $kode, $nominal);
                    $stmt_det->execute();
                }
            }
            echo "<script>alert('Data berhasil disimpan'); window.location.href='index.php?page=manajemen&sub=penggajian';</script>";
        } else {
            echo "<script>alert('Gagal menyimpan data');</script>";
        }
    }
}

// Fetch Pegawai for dropdown
$pegawai_list = [];
$res = $koneksi->query("SELECT nik, nama FROM pegawai ORDER BY nama ASC");
while($row = $res->fetch_assoc()){
    $pegawai_list[] = $row;
}

// Fetch Master Komponen
$penerimaan = [];
$potongan = [];
$res = $koneksi->query("SELECT * FROM master_komponen_gaji ORDER BY jenis ASC, kode ASC");
while($row = $res->fetch_assoc()){
    if($row['jenis'] == 'Penerimaan') $penerimaan[] = $row;
    else $potongan[] = $row;
}
?>

<div class="content-header">
    <h2 class="content-title">Manajemen Penggajian</h2>
    <div class="content-actions">
        <button class="btn btn-primary" onclick="showFormGaji()">+ Buat Slip Gaji</button>
    </div>
</div>

<div class="content-card" id="list_gaji">
    <table class="table">
        <thead>
            <tr>
                <th>Periode</th>
                <th>NIK</th>
                <th>Nama Pegawai</th>
                <th>Penerimaan</th>
                <th>Potongan</th>
                <th>Gaji Diterima</th>
                <th>Aksi</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $query_list = "SELECT g.*, p.nama FROM gajidantunjangan g JOIN pegawai p ON g.nik = p.nik ORDER BY g.periode_gaji DESC, p.nama ASC";
            $res_list = $koneksi->query($query_list);
            if ($res_list->num_rows > 0) {
                while($row = $res_list->fetch_assoc()){
                    echo "<tr>
                        <td>{$row['periode_gaji']}</td>
                        <td>{$row['nik']}</td>
                        <td>{$row['nama']}</td>
                        <td>Rp " . number_format($row['total_penerimaan'], 0, ',', '.') . "</td>
                        <td>Rp " . number_format($row['total_potongan'], 0, ',', '.') . "</td>
                        <td><strong>Rp " . number_format($row['gaji_diterima'], 0, ',', '.') . "</strong></td>
                        <td>
                            <a href='pages/cetak_slip_gaji.php?nik={$row['nik']}&periode={$row['periode_gaji']}' target='_blank' class='btn btn-secondary btn-sm'>🖨️ Cetak</a>
                            <a href='index.php?page=manajemen&sub=penggajian&action=delete&nik={$row['nik']}&periode={$row['periode_gaji']}' class='btn btn-danger btn-sm' onclick='return confirm(\"Hapus data gaji ini?\")'>🗑️</a>
                        </td>
                    </tr>";
                }
            } else {
                echo "<tr><td colspan='7' class='text-center'>Belum ada data penggajian.</td></tr>";
            }
            ?>
        </tbody>
    </table>
</div>

<div class="content-card" id="form_gaji" style="display:none;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h3 style="margin:0;">Form Input Gaji</h3>
        <button class="btn btn-secondary btn-sm" onclick="hideFormGaji()">Kembali</button>
    </div>
    
    <form method="POST" action="">
        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px; margin-bottom: 20px;">
            <div>
                <label>Pilih Pegawai</label>
                <select name="nik" class="form-control" required>
                    <option value="">-- Pilih Pegawai --</option>
                    <?php foreach($pegawai_list as $p): ?>
                        <option value="<?= $p['nik'] ?>"><?= $p['nik'] ?> - <?= htmlspecialchars($p['nama']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Periode Gaji (Mis: 2026-06)</label>
                <input type="month" name="periode_gaji" class="form-control" required value="<?= date('Y-m') ?>">
            </div>
            <div>
                <label>Tanggal Cetak</label>
                <input type="date" name="tanggal_cetak" class="form-control" required value="<?= date('Y-m-d') ?>">
            </div>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px;">
            <!-- Penerimaan -->
            <div>
                <h4 style="border-bottom: 1px solid #e2e8f0; padding-bottom: 8px; margin-bottom: 15px;">PENERIMAAN</h4>
                <?php foreach($penerimaan as $p): ?>
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                    <label style="width: 50%; font-size: 13px;"><?= $p['nama_komponen'] ?> (<?= $p['kode'] ?>)</label>
                    <input type="number" name="komponen[<?= $p['kode'] ?>]" class="form-control val-penerimaan" style="width: 45%; text-align: right;" oninput="hitungTotal()" placeholder="0">
                </div>
                <?php endforeach; ?>
                <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 15px; border-top: 2px dashed #e2e8f0; padding-top: 10px;">
                    <strong>Total Penerimaan</strong>
                    <strong id="label_total_penerimaan">Rp 0</strong>
                    <input type="hidden" name="total_penerimaan_val" id="total_penerimaan_val" value="0">
                </div>
            </div>

            <!-- Potongan -->
            <div>
                <h4 style="border-bottom: 1px solid #e2e8f0; padding-bottom: 8px; margin-bottom: 15px;">POTONGAN</h4>
                <?php foreach($potongan as $p): ?>
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                    <label style="width: 50%; font-size: 13px;"><?= $p['nama_komponen'] ?> (<?= $p['kode'] ?>)</label>
                    <input type="number" name="komponen[<?= $p['kode'] ?>]" class="form-control val-potongan" style="width: 45%; text-align: right;" oninput="hitungTotal()" placeholder="0">
                </div>
                <?php endforeach; ?>
                <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 15px; border-top: 2px dashed #e2e8f0; padding-top: 10px;">
                    <strong>Total Potongan</strong>
                    <strong id="label_total_potongan">Rp 0</strong>
                    <input type="hidden" name="total_potongan_val" id="total_potongan_val" value="0">
                </div>
            </div>
        </div>

        <div style="background: #f8fafc; padding: 20px; border-radius: 8px; margin-top: 30px; text-align: center; border: 1px solid #e2e8f0;">
            <h3 style="margin: 0 0 10px 0;">Gaji yang Diterima</h3>
            <h2 id="label_gaji_diterima" style="margin: 0; color: #10b981; font-size: 32px;">Rp 0</h2>
            <input type="hidden" name="gaji_diterima_val" id="gaji_diterima_val" value="0">
        </div>

        <div style="margin-top: 20px; text-align: right;">
            <button type="submit" name="simpan_gaji" class="btn btn-primary" style="padding: 12px 24px;">Simpan Data Gaji</button>
        </div>
    </form>
</div>

<script>
function showFormGaji() {
    document.getElementById('list_gaji').style.display = 'none';
    document.getElementById('form_gaji').style.display = 'block';
}

function hideFormGaji() {
    document.getElementById('list_gaji').style.display = 'block';
    document.getElementById('form_gaji').style.display = 'none';
}

function hitungTotal() {
    let totalPenerimaan = 0;
    document.querySelectorAll('.val-penerimaan').forEach(el => {
        let val = parseFloat(el.value);
        if(!isNaN(val)) totalPenerimaan += val;
    });

    let totalPotongan = 0;
    document.querySelectorAll('.val-potongan').forEach(el => {
        let val = parseFloat(el.value);
        if(!isNaN(val)) totalPotongan += val;
    });

    let gajiDiterima = totalPenerimaan - totalPotongan;

    document.getElementById('label_total_penerimaan').innerText = formatRupiah(totalPenerimaan);
    document.getElementById('total_penerimaan_val').value = totalPenerimaan;

    document.getElementById('label_total_potongan').innerText = formatRupiah(totalPotongan);
    document.getElementById('total_potongan_val').value = totalPotongan;

    document.getElementById('label_gaji_diterima').innerText = formatRupiah(gajiDiterima);
    document.getElementById('gaji_diterima_val').value = gajiDiterima;
}

function formatRupiah(angka) {
    return 'Rp ' + angka.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ".");
}
</script>
