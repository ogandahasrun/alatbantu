<?php
// Konfigurasi MySQL / MariaDB di XAMPP
$host = 'localhost';
$user = 'root';
$pass = '';
$db   = 'sikbaru';

// Membuat koneksi ke database
$koneksi = new mysqli($host, $user, $pass, $db);
if ($koneksi->connect_errno) {
    die("Koneksi database gagal: " . $koneksi->connect_error);
}

// Menyetel encoding karakter
$koneksi->set_charset('utf8mb4');
?>
