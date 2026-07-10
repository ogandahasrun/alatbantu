<?php
defined('host') or die('Akses langsung tidak diizinkan.');

$sub = isset($_GET['sub']) ? $_GET['sub'] : 'visite';

if ($sub === 'jadwal_operasi') {
    include 'jadwal_operasi.php';
} else {
    include 'dokter_visite.php';
}
?>
