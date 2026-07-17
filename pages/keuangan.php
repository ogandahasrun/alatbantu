<?php
defined('host') or die('Akses langsung tidak diizinkan.');

$sub = isset($_GET['sub']) ? $_GET['sub'] : 'ppnobat';

if ($sub === 'penjualan_bebas') {
    include 'keuangan_penjualan_bebas.php';
} elseif ($sub === 'hutang_medis') {
    include 'keuangan_hutang_medis.php';
} else {
    include 'keuangan_ppnobat.php';
}
?>
