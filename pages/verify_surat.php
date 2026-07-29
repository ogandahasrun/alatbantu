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

// Ambil info disposisi level 3
$sql_lvl3 = "SELECT * FROM surat_keluar_disposisi_level WHERE no_urut = '$no_urut_esc' AND level = 3 AND status_disposisi = 'Sudah Disposisi' AND pengesahan = 'true'";
$res_lvl3 = $koneksi->query($sql_lvl3);
$lvl3 = $res_lvl3 ? $res_lvl3->fetch_assoc() : null;

// Ambil info nama pegawai level 3
$direktur_nama = "Direktur";
if ($lvl3) {
    $nik = $koneksi->real_escape_string($lvl3['nik']);
    $res_peg = $koneksi->query("SELECT nama FROM db_simrs_v2.pegawai WHERE nik = '$nik'");
    if ($res_peg && $row_peg = $res_peg->fetch_assoc()) {
        $direktur_nama = $row_peg['nama'];
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verifikasi Surat Resmi</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f1f5f9; padding: 20px; color: #334155; }
        .card { background: #fff; max-width: 500px; margin: 40px auto; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); padding: 30px; text-align: center; border-top: 5px solid #0284c7; }
        .icon { width: 60px; height: 60px; background: #dcfce7; color: #16a34a; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; }
        .icon svg { width: 30px; height: 30px; }
        h2 { margin: 0 0 10px; color: #0f172a; font-size: 22px; }
        p.subtitle { margin: 0 0 20px; color: #64748b; font-size: 14px; }
        .detail-box { text-align: left; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; margin-bottom: 20px; }
        .detail-item { margin-bottom: 12px; }
        .detail-item:last-child { margin-bottom: 0; }
        .detail-label { font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; margin-bottom: 4px; display: block; }
        .detail-value { font-size: 14px; font-weight: 600; color: #0f172a; }
        .footer { font-size: 12px; color: #94a3b8; margin-top: 20px; border-top: 1px solid #e2e8f0; padding-top: 16px; }
    </style>
</head>
<body>

<div class="card">
    <?php if ($lvl3): ?>
        <div class="icon">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
            </svg>
        </div>
        <h2>Dokumen Valid & Sah</h2>
        <p class="subtitle">Surat ini telah disetujui dan ditandatangani secara digital melalui sistem resmi.</p>
    <?php else: ?>
        <div class="icon" style="background:#fee2e2; color:#dc2626;">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
        </div>
        <h2>Dokumen Belum Disahkan</h2>
        <p class="subtitle">Surat ini terdaftar di sistem namun belum melalui proses persetujuan akhir.</p>
    <?php endif; ?>

    <div class="detail-box">
        <div class="detail-item">
            <span class="detail-label">Nomor Surat</span>
            <span class="detail-value"><?= htmlspecialchars($surat['no_surat']) ?></span>
        </div>
        <div class="detail-item">
            <span class="detail-label">Perihal</span>
            <span class="detail-value"><?= htmlspecialchars($surat['perihal']) ?></span>
        </div>
        <div class="detail-item">
            <span class="detail-label">Tujuan</span>
            <span class="detail-value"><?= htmlspecialchars($surat['tujuan']) ?></span>
        </div>
        <div class="detail-item">
            <span class="detail-label">Tanggal Surat</span>
            <span class="detail-value"><?= date('d F Y', strtotime($surat['tgl_surat'])) ?></span>
        </div>
        <?php if ($lvl3): ?>
        <div class="detail-item" style="margin-top: 16px; padding-top: 16px; border-top: 1px dashed #cbd5e1;">
            <span class="detail-label">Ditandatangani Digital Oleh</span>
            <span class="detail-value" style="color: #0284c7;"><?= htmlspecialchars($direktur_nama) ?> (Direktur)</span>
            <div style="font-size: 11px; color: #64748b; margin-top: 4px;">Pada: <?= date('d M Y, H:i', strtotime($lvl3['tgl_disposisi'])) ?> WIB</div>
        </div>
        <?php endif; ?>
    </div>

    <div class="footer">
        Diterbitkan oleh Sistem Manajemen Surat Resmi<br>
        &copy; <?= date('Y') ?> RSUD
    </div>
</div>

</body>
</html>
