<?php
session_start();
if (!isset($_SESSION['username'])) {
    die("Akses ditolak.");
}
require_once '../koneksi.php';

$nik = $_GET['nik'] ?? '';
$periode = $_GET['periode'] ?? '';

if (empty($nik) || empty($periode)) {
    die("Parameter tidak lengkap.");
}

// Fetch header info
$query_header = "SELECT g.*, p.nama, p.departemen, p.jbtn as jabatan 
                 FROM gajidantunjangan g 
                 JOIN pegawai p ON g.nik = p.nik 
                 WHERE g.nik = ? AND g.periode_gaji = ?";
$stmt = $koneksi->prepare($query_header);
$stmt->bind_param("ss", $nik, $periode);
$stmt->execute();
$res = $stmt->get_result();
if ($res->num_rows == 0) {
    die("Data gaji tidak ditemukan.");
}
$gaji = $res->fetch_assoc();

// Fetch details
$penerimaan = [];
$potongan = [];

$query_detail = "SELECT d.*, m.nama_komponen, m.jenis, m.kode 
                 FROM gajidantunjangan_detail d 
                 JOIN master_komponen_gaji m ON d.kode_komponen = m.kode 
                 WHERE d.nik = ? AND d.periode_gaji = ?
                 ORDER BY m.kode ASC";
$stmt2 = $koneksi->prepare($query_detail);
$stmt2->bind_param("ss", $nik, $periode);
$stmt2->execute();
$res2 = $stmt2->get_result();

while ($row = $res2->fetch_assoc()) {
    if ($row['jenis'] == 'Penerimaan') {
        $penerimaan[] = $row;
    } else {
        $potongan[] = $row;
    }
}

// Setup QR Code & Info
$user_login = $_SESSION['nama_lengkap'] ?? $_SESSION['username'];

$query_instansi = "SELECT nama_instansi, alamat_instansi, kabupaten, propinsi, kontak FROM setting LIMIT 1";
$res_instansi = $koneksi->query($query_instansi);
$instansi = $res_instansi->fetch_assoc();
$nama_instansi = $instansi['nama_instansi'] ?? 'RS MATA LEC';
$alamat_instansi = ($instansi['alamat_instansi'] ?? '') . ', ' . ($instansi['kabupaten'] ?? '') . ', ' . ($instansi['propinsi'] ?? '');
$kontak = $instansi['kontak'] ?? '';

$tgl_cetak = date('d-m-Y', strtotime($gaji['tanggal_cetak']));
$qr_text = "Ditandatangani oleh " . $user_login . " pada tanggal " . $tgl_cetak . " di " . $nama_instansi . " alamat " . $alamat_instansi;
$qr_url = "https://quickchart.io/qr?size=100&text=" . urlencode($qr_text);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Cetak Slip Gaji - <?= htmlspecialchars($gaji['nama']) ?></title>
    <style>
        @page {
            size: A4 portrait;
            margin: 15mm;
        }
        body {
            font-family: Arial, sans-serif;
            background-color: #ffffff; /* Background diubah menjadi putih */
            margin: 0;
            padding: 0;
            color: #000;
            font-size: 12px;
            -webkit-print-color-adjust: exact; 
            print-color-adjust: exact;
        }
        .container {
            width: 100%;
            max-width: 800px;
            margin: 0 auto;
            border: 1px solid #7cb342;
            background-color: #ffffff; /* Background diubah menjadi putih */
        }
        .header {
            text-align: center;
            border-bottom: 1px solid #7cb342;
            padding: 10px;
            position: relative;
        }
        .header h2 {
            margin: 0 0 5px 0;
            font-size: 16px;
        }
        .header p {
            margin: 0;
            font-size: 11px;
        }
        .info-table {
            width: 100%;
            border-collapse: collapse;
            border-bottom: 1px solid #7cb342;
        }
        .info-table td {
            padding: 5px 10px;
            border-right: 1px solid #7cb342;
        }
        .info-table td:last-child {
            border-right: none;
        }
        
        .main-table {
            width: 100%;
            border-collapse: collapse;
        }
        .main-table th {
            text-align: left;
            padding: 8px 10px;
            border-bottom: 1px solid #7cb342;
            border-right: 1px solid #7cb342;
        }
        .main-table td {
            padding: 4px 10px;
            border-right: 1px solid #7cb342;
            vertical-align: top;
        }
        .main-table th:last-child, .main-table td:last-child {
            border-right: none;
        }
        .col-half {
            width: 50%;
        }
        
        .row-item {
            display: flex;
            justify-content: space-between;
        }
        .row-item span:first-child {
            width: 30px;
        }
        .row-item span:nth-child(2) {
            flex-grow: 1;
        }
        
        .totals {
            border-top: 1px solid #7cb342;
            border-bottom: 1px solid #7cb342;
            font-weight: bold;
        }
        .totals td {
            padding: 8px 10px;
        }
        
        .take-home {
            padding: 15px 10px;
            font-weight: bold;
            border-bottom: 1px solid #7cb342;
            display: flex;
            align-items: center;
        }
        
        .signatures {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        .signatures td {
            width: 50%;
            padding: 10px;
            vertical-align: top;
            font-weight: bold;
        }
        
        @media print {
            body {
                background-color: #ffffff !important;
            }
            .no-print {
                display: none !important;
            }
        }
    </style>
</head>
<body onload="window.print()">

    <div class="no-print" style="text-align:center; padding: 10px; background:#f1f5f9; margin-bottom: 20px;">
        <button onclick="window.print()" style="padding: 10px 20px; cursor: pointer; font-weight: bold; background: #6366f1; color: white; border: none; border-radius: 4px;">Cetak Slip</button>
    </div>

    <div class="container">
        <!-- Header -->
        <div class="header">
            <h2><?= htmlspecialchars($nama_instansi) ?></h2>
            <p><?= htmlspecialchars($alamat_instansi) ?></p>
            <p><?= htmlspecialchars($kontak) ?></p>
        </div>

        <!-- Info Pegawai -->
        <table class="info-table">
            <tr>
                <td style="width: 25%;">Tanggal Periode</td>
                <td style="width: 25%;">: <?= $gaji['periode_gaji'] ?></td>
                <td style="width: 20%;">Nama</td>
                <td style="width: 30%;">: <?= htmlspecialchars($gaji['nama']) ?></td>
            </tr>
            <tr>
                <td>Instalasi</td>
                <td>: <?= htmlspecialchars($gaji['departemen'] ?? 'Kamar Operasi') ?></td>
                <td>Jabatan</td>
                <td>: <?= htmlspecialchars($gaji['jabatan'] ?? '-') ?></td>
            </tr>
        </table>

        <!-- Detail Komponen -->
        <table class="main-table">
            <thead>
                <tr>
                    <th class="col-half">PENERIMAAN</th>
                    <th class="col-half">POTONGAN</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>
                        <?php foreach($penerimaan as $p): ?>
                        <div class="row-item">
                            <span><?= $p['kode'] ?></span>
                            <span><?= $p['nama_komponen'] ?></span>
                            <span><?= number_format($p['nominal'], 0, ',', '.') ?></span>
                        </div>
                        <?php endforeach; ?>
                    </td>
                    <td>
                        <?php foreach($potongan as $p): ?>
                        <div class="row-item">
                            <span><?= $p['kode'] ?></span>
                            <span><?= $p['nama_komponen'] ?></span>
                            <span><?= number_format($p['nominal'], 0, ',', '.') ?></span>
                        </div>
                        <?php endforeach; ?>
                    </td>
                </tr>
                <tr class="totals">
                    <td>
                        <div style="display:flex; justify-content:space-between;">
                            <span>Total</span>
                            <span><?= number_format($gaji['total_penerimaan'], 0, ',', '.') ?></span>
                        </div>
                    </td>
                    <td>
                        <div style="display:flex; justify-content:space-between;">
                            <span>Total Potongan</span>
                            <span><?= number_format($gaji['total_potongan'], 0, ',', '.') ?></span>
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>

        <div class="take-home">
            <span style="width: 150px;">Gaji yang diterima :</span>
            <span>Rp <?= number_format($gaji['gaji_diterima'], 0, ',', '.') ?></span>
        </div>

        <table class="signatures">
            <tr>
                <td>Diserahkan oleh,</td>
                <td>Diterima oleh,</td>
            </tr>
            <tr>
                <td style="padding-top: 10px;">
                    <img src="<?= $qr_url ?>" alt="QR Code" width="80" height="80" style="margin-bottom: 5px;"><br>
                    _______________________<br>
                    <strong><?= htmlspecialchars($user_login) ?></strong>
                </td>
                <td style="padding-top: 80px;">
                    <br>
                    _______________________<br>
                    <strong><?= htmlspecialchars($gaji['nama']) ?></strong>
                </td>
            </tr>
        </table>
    </div>

</body>
</html>
