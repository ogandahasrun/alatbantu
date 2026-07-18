<?php
defined('host') or die('Akses langsung tidak diizinkan.');

$sub = isset($_GET['sub']) ? $_GET['sub'] : 'pegawai';

if ($sub === 'dokter') {
    include 'manajemen_dokter.php';
} elseif ($sub === 'mapping_atasan') {
    include 'mapping_atasan.php';
} elseif ($sub === 'user') {
    // Halaman Manajemen User hanya untuk admin utama
    if (!isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
        echo "
        <div class='content-card' style='text-align: center; padding: 48px 24px;'>
            <div style='font-size: 56px; margin-bottom: 16px;'>🔒</div>
            <h2 style='margin-bottom: 8px; color: var(--text-primary);'>Akses Terbatas</h2>
            <p class='text-secondary' style='font-size: 14px; max-width: 360px; margin: 0 auto 20px;'>
                Halaman <strong>Manajemen User</strong> hanya dapat diakses oleh <strong>Admin Utama</strong> sistem.
                Silakan hubungi administrator jika Anda membutuhkan akses ini.
            </p>
            <a href='index.php?page=manajemen&sub=pegawai' class='btn btn-secondary'>← Kembali</a>
        </div>";
    } else {
        include 'manajemen_user.php';
    }
} elseif ($sub === 'penggajian') {
    include 'manajemen_penggajian.php';
} elseif ($sub === 'rekap_absensi') {
    include 'manajemen_rekap_absensi.php';
} else {
    include 'manajemen_pegawai.php';
}
?>

