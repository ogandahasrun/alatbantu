<?php
require 'config/koneksi.php';
$r = $koneksi->query('DESCRIBE jam_jaga');
while($row = $r->fetch_assoc()) {
    print_r($row);
}
?>
