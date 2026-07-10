<?php
defined('host') or die('Akses langsung tidak diizinkan.');

$sub = isset($_GET['sub']) ? $_GET['sub'] : 'cuti';

if ($sub === 'absensi') {
    include 'pegawai_absensi.php';
} else {
    include 'pegawai_cuti.php';
}
?>
