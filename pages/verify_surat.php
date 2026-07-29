<?php
require_once __DIR__ . '/../koneksi.php';

$no_urut = $_GET['no_urut'] ?? '';

if (empty($no_urut)) {
    die("Parameter Nomor Urut tidak valid.");
}

$no_urut_esc = $koneksi->real_escape_string($no_urut);

// Ambil data surat keluar
$sql = "SELECT sk.*, k.klasifikasi 
        FROM surat_keluar sk 
        LEFT JOIN surat_klasifikasi k ON sk.kd_klasifikasi = k.kd 
        WHERE sk.no_urut = '$no_urut_esc'";
$res = $koneksi->query($sql);

if (!$res || $res->num_rows === 0) {
    die("Data surat tidak ditemukan atau QR Code tidak valid.");
}

$surat = $res->fetch_assoc();

// Ambil info disposisi level 3 (Penandatangan / Direktur)
$sql_lvl3 = "SELECT * FROM surat_keluar_disposisi_level WHERE no_urut = '$no_urut_esc' AND level = 3 AND status_disposisi = 'Sudah Disposisi' AND pengesahan = 'true'";
$res_lvl3 = $koneksi->query($sql_lvl3);
$lvl3 = $res_lvl3 ? $res_lvl3->fetch_assoc() : null;

// Ambil info nama pegawai penandatangan
$nama_petugas = "Penandatangan / Direktur";
if ($lvl3) {
    $nik = $koneksi->real_escape_string($lvl3['nik']);
    $res_peg = $koneksi->query("SELECT nama FROM pegawai WHERE nik = '$nik'");
    if ($res_peg && $row_peg = $res_peg->fetch_assoc()) {
        $nama_petugas = $row_peg['nama'];
    }
}

// Ambil profil instansi dari tabel setting
$nama_instansi = "RSUD PRINGSEWU";
$alamat_instansi = "Jl. Kesehatan No. 1 Pringsewu";

$res_set = $koneksi->query("SELECT nama_instansi, alamat_instansi, kabupaten, propinsi FROM setting LIMIT 1");
if ($res_set && $row_set = $res_set->fetch_assoc()) {
    if (!empty($row_set['nama_instansi'])) {
        $nama_instansi = $row_set['nama_instansi'];
    }
    if (!empty($row_set['alamat_instansi'])) {
        $alamat_instansi = $row_set['alamat_instansi'];
        if (!empty($row_set['kabupaten'])) {
            $alamat_instansi .= ", " . $row_set['kabupaten'];
        }
    }
}

$tgl_surat_fmt = date('d F Y', strtotime($surat['tgl_surat']));
$pernyataan_digital = "Ditandatangani secara digital oleh '" . htmlspecialchars($nama_petugas) . "' pada tanggal '" . htmlspecialchars($tgl_surat_fmt) . "' di '" . htmlspecialchars($nama_instansi) . "' alamat '" . htmlspecialchars($alamat_instansi) . "'";
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verifikasi Keabsahan Surat Digital</title>
    <style>
        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            background-color: #f8fafc;
            padding: 20px;
            color: #0f172a;
            margin: 0;
        }
        .card {
            background: #ffffff;
            max-width: 580px;
            margin: 30px auto;
            border-radius: 16px;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.08);
            padding: 32px;
            text-align: center;
            border-top: 6px solid #0284c7;
        }
        .badge-icon {
            width: 64px;
            height: 64px;
            background: #dcfce7;
            color: #16a34a;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 18px;
            box-shadow: 0 4px 12px rgba(22, 163, 74, 0.15);
        }
        .badge-icon.invalid {
            background: #fee2e2;
            color: #dc2626;
            box-shadow: 0 4px 12px rgba(220, 38, 38, 0.15);
        }
        h2 {
            margin: 0 0 8px;
            color: #0f172a;
            font-size: 22px;
            font-weight: 700;
        }
        p.subtitle {
            margin: 0 0 24px;
            color: #64748b;
            font-size: 14px;
        }
        .statement-box {
            background: #f0f9ff;
            border: 1px solid #bae6fd;
            border-left: 5px solid #0284c7;
            border-radius: 12px;
            padding: 16px 20px;
            margin-bottom: 24px;
            text-align: left;
            font-size: 14px;
            line-height: 1.6;
            color: #0369a1;
            font-weight: 600;
        }
        .detail-box {
            text-align: left;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .detail-item {
            margin-bottom: 12px;
        }
        .detail-item:last-child {
            margin-bottom: 0;
        }
        .detail-label {
            font-size: 11px;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 3px;
            display: block;
        }
        .detail-value {
            font-size: 14px;
            font-weight: 600;
            color: #0f172a;
        }
        .footer {
            font-size: 12px;
            color: #94a3b8;
            margin-top: 24px;
            border-top: 1px solid #f1f5f9;
            padding-top: 18px;
        }
    </style>
</head>
<body>

<div class="card">
    <?php if ($lvl3): ?>
        <div class="badge-icon">
            <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
            </svg>
        </div>
        <h2>Dokumen Terverifikasi Sah</h2>
        <p class="subtitle">Berkas surat keluar ini terdaftar dan memiliki keabsahan resmi di sistem.</p>

        <!-- STATEMENT PERNYATAAN RESMI -->
        <div class="statement-box">
            📜 <strong>Pernyataan Keabsahan:</strong><br>
            <?= $pernyataan_digital ?>
        </div>
    <?php else: ?>
        <div class="badge-icon invalid">
            <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
        </div>
        <h2>Dokumen Belum Disahkan</h2>
        <p class="subtitle">Surat ini terdaftar di sistem namun belum melalui persetujuan akhir penandatangan.</p>
    <?php endif; ?>

    <div class="detail-box">
        <div class="detail-item">
            <span class="detail-label">Nomor Surat</span>
            <span class="detail-value"><?= htmlspecialchars($surat['no_surat']) ?></span>
        </div>
        <div class="detail-item">
            <span class="detail-label">Perihal / Subjek</span>
            <span class="detail-value"><?= htmlspecialchars($surat['perihal']) ?></span>
        </div>
        <div class="detail-item">
            <span class="detail-label">Tujuan Surat</span>
            <span class="detail-value"><?= htmlspecialchars($surat['tujuan']) ?></span>
        </div>
        <div class="detail-item">
            <span class="detail-label">Tanggal Surat</span>
            <span class="detail-value"><?= htmlspecialchars($tgl_surat_fmt) ?></span>
        </div>
        <?php if ($lvl3): ?>
        <div class="detail-item" style="margin-top: 14px; padding-top: 14px; border-top: 1px dashed #cbd5e1;">
            <span class="detail-label">Penandatangan Digital</span>
            <span class="detail-value" style="color: #0284c7;"><?= htmlspecialchars($nama_petugas) ?></span>
            <div style="font-size: 11px; color: #64748b; margin-top: 2px;">Waktu Disetujui: <?= date('d M Y, H:i', strtotime($lvl3['tgl_disposisi'])) ?> WIB</div>
        </div>
        <?php endif; ?>
    </div>

    <div class="footer">
        Sistem Manajemen Surat Resmi &bull; <?= htmlspecialchars($nama_instansi) ?><br>
        &copy; <?= date('Y') ?> All Rights Reserved.
    </div>
</div>

</body>
</html>
