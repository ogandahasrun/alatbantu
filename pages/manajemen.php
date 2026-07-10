<?php
defined('host') or die('Akses langsung tidak diizinkan.');

// Default sub-page is now 'pegawai' (Data Pegawai) to disable user management
$sub = isset($_GET['sub']) ? $_GET['sub'] : 'pegawai';

if ($sub === 'dokter') {
    include 'manajemen_dokter.php';
} else {
    include 'manajemen_pegawai.php';
}
?>
