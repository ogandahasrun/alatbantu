<?php
defined('host') or die('Akses langsung tidak diizinkan.');

$sub = isset($_GET['sub']) ? $_GET['sub'] : 'payment_point';

if ($sub === 'payment_point') {
    include 'kasir_payment_point.php';
} elseif ($sub === 'penjualan_bebas') {
    include 'kasir_penjualan_bebas.php';
} else {
    include 'kasir_payment_point.php';
}
?>
