<?php
require_once 'koneksi.php';
$res = $koneksi->query("SELECT * FROM jam_jaga WHERE dep_id = '-'");
while($r = $res->fetch_assoc()) {
    print_r($r);
}
?>
