<?php
defined('host') or die('Akses langsung tidak diizinkan.');

$sub = isset($_GET['sub']) ? $_GET['sub'] : 'cuti';

if ($sub === 'absensi') {
    include 'pegawai_absensi.php';
} elseif ($sub === 'jadwal') {
    include 'pegawai_jadwal.php';
} else {
    include 'pegawai_cuti.php';
}
?>
