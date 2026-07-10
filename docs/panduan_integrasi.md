# 📋 Panduan: Integrasi Halaman Eksternal ke Alatbantu

Gunakan prompt berikut setiap kali ingin mengintegrasikan halaman dari `rsudpringsewu` (atau sistem lain) ke dalam aplikasi **alatbantu**.

---

## 🔧 Prompt yang Bisa Dipakai

> Saya ingin mengintegrasikan halaman `rsudpringsewu/NAMA_FILE.php` ke dalam aplikasi alatbantu sebagai sub-menu baru di menu **NAMA_MENU**. Tolong:
>
> 1. Buat file baru di `alatbantu/pages/NAMA_FILE_BARU.php`
> 2. Hapus bagian berikut dari file asli:
>    - `session_start()` dan cek login (`if (!isset($_SESSION['username']))`)
>    - `include 'koneksi.php'` dan `include 'functions.php'` (gunakan `$koneksi` yang sudah tersedia)
>    - Seluruh tag HTML pembungkus: `<!DOCTYPE html>`, `<html>`, `<head>`, `<style>`, `<body>`
>    - Tombol "Kembali ke ..." yang mengarah ke halaman rsudpringsewu
> 3. Tambahkan di baris paling atas: `defined('host') or die('Akses langsung tidak diizinkan.');`
> 4. Gunakan komponen desain alatbantu:
>    - Header halaman: `<div class="page-header"><h1 class="page-title">...</h1></div>`
>    - Pembungkus konten: `<div class="content-card">...</div>`
>    - Input form: class `form-control` dan `form-label`
>    - Tombol: class `btn btn-primary` / `btn btn-secondary`
>    - Tabel: class `table-custom` dibungkus `<div class="table-responsive">`
> 5. Perbaiki action form agar submit ke `index.php?page=NAMA_PAGE&sub=NAMA_SUB`
> 6. Tambahkan routing di `alatbantu/pages/NAMA_ROUTER.php` untuk sub baru
> 7. Tambahkan menu di sidebar dan mobile drawer pada `index.php` dengan guard hak akses yang sesuai

---

## 🗂️ Struktur File yang Harus Dibuat/Diedit

```
Setiap integrasi halaman baru biasanya menyentuh 3 file:

1. pages/NAMA_PAGE_BARU.php   ← file konten utama (yang baru dibuat)
2. pages/NAMA_ROUTER.php      ← tambah elseif ($sub === 'nama_sub')
3. index.php                  ← tambah menu sidebar + mobile drawer
```

---

## ✅ Checklist Konversi File

Ketika mengkonversi file dari rsudpringsewu ke alatbantu, pastikan:

| # | Item | Keterangan |
|---|------|------------|
| ✅ | Hapus `session_start()` | Session sudah aktif dari index.php |
| ✅ | Hapus cek login | Auth sudah dijaga index.php |
| ✅ | Hapus `include 'koneksi.php'` | Gunakan `$koneksi` yang sudah ada |
| ✅ | Hapus `include 'functions.php'` | Buat fungsi helper lokal jika perlu |
| ✅ | Hapus `<!DOCTYPE html>` s/d `<body>` | Layout sudah dari index.php |
| ✅ | Hapus `</body></html>` | Sama di atas |
| ✅ | Hapus tombol "Kembali ke ..." | Navigasi via sidebar |
| ✅ | Tambah guard baris pertama | `defined('host') or die(...)` |
| ✅ | Escape input SQL | Gunakan `mysqli_real_escape_string()` |
| ✅ | Fix form action | Arahkan ke `index.php?page=X&sub=Y` |
| ✅ | Ganti class CSS custom | Pakai class alatbantu (lihat di bawah) |

---

## 🎨 Referensi Class CSS Alatbantu

### Layout & Container
```html
<!-- Header halaman -->
<div class="page-header">
    <div>
        <h1 class="page-title">Judul Halaman</h1>
        <p class="text-secondary" style="font-size:14px;">Deskripsi singkat.</p>
    </div>
</div>

<!-- Card konten utama -->
<div class="content-card">
    ...isi konten...
</div>
```

### Form & Input
```html
<!-- Form group standar -->
<div class="form-group">
    <label class="form-label">Label Field</label>
    <input type="text"   class="form-control" name="...">
    <input type="date"   class="form-control" name="...">
    <select              class="form-control" name="...">...</select>
    <textarea            class="form-control" name="..."></textarea>
</div>
```

### Tombol
```html
<button class="btn btn-primary">Tampilkan</button>
<button class="btn btn-secondary">Reset</button>
<button class="btn btn-danger">Hapus</button>
<a href="..." class="btn btn-primary">Link Tombol</a>
```

### Tabel
```html
<div class="table-responsive">
    <table class="table-custom">
        <thead>
            <tr>
                <th>Kolom 1</th>
                <th style="text-align:right;">Angka</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Data</td>
                <td style="text-align:right;">Rp 0</td>
            </tr>
        </tbody>
        <tfoot>
            <tr style="font-weight:700; background:var(--bg-secondary);">
                <td style="text-align:right;">Total:</td>
                <td style="text-align:right;">Rp xxx</td>
            </tr>
        </tfoot>
    </table>
</div>
```

### Badge & Status
```html
<span class="badge badge-success">Aktif</span>
<span class="badge badge-primary">Info</span>
<span class="badge badge-danger">Nonaktif</span>
```

### CSS Variables (untuk style inline)
```css
var(--text-primary)    /* warna teks utama */
var(--text-secondary)  /* warna teks abu-abu */
var(--bg-secondary)    /* background abu terang */
var(--border-color)    /* warna border */
var(--accent)          /* warna aksen/biru */
var(--card-bg)         /* background card */
```

---

## ⚙️ Template File Kosong

Salin template ini sebagai titik awal setiap halaman baru:

```php
<?php
defined('host') or die('Akses langsung tidak diizinkan.');

// $koneksi sudah tersedia dari index.php

// 1. Ambil parameter filter dari $_GET atau $_POST
$tgl_awal  = isset($_GET['tgl_awal'])  ? $_GET['tgl_awal']  : date('Y-m-d');
$tgl_akhir = isset($_GET['tgl_akhir']) ? $_GET['tgl_akhir'] : date('Y-m-d');

// 2. Escape sebelum dipakai di query
$tgl_awal_safe  = mysqli_real_escape_string($koneksi, $tgl_awal);
$tgl_akhir_safe = mysqli_real_escape_string($koneksi, $tgl_akhir);

// 3. Query data
$sql    = "SELECT ... WHERE ... BETWEEN '$tgl_awal_safe' AND '$tgl_akhir_safe'";
$result = mysqli_query($koneksi, $sql);
$data   = [];
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $data[] = $row;
    }
}
?>

<!-- 4. Header halaman -->
<div class="page-header">
    <div>
        <h1 class="page-title">Nama Halaman</h1>
        <p class="text-secondary" style="font-size:14px;">Deskripsi halaman.</p>
    </div>
</div>

<!-- 5. Filter form -->
<div class="content-card">
    <form method="get">
        <input type="hidden" name="page" value="NAMA_PAGE">
        <input type="hidden" name="sub"  value="NAMA_SUB">

        <div style="display:flex; flex-wrap:wrap; gap:12px; align-items:flex-end; margin-bottom:16px;">
            <div class="form-group" style="margin:0;">
                <label class="form-label">Dari Tanggal</label>
                <input type="date" name="tgl_awal" class="form-control" value="<?= htmlspecialchars($tgl_awal) ?>">
            </div>
            <div class="form-group" style="margin:0;">
                <label class="form-label">Sampai Tanggal</label>
                <input type="date" name="tgl_akhir" class="form-control" value="<?= htmlspecialchars($tgl_akhir) ?>">
            </div>
            <button type="submit" class="btn btn-primary" style="height:42px;">Tampilkan</button>
        </div>
    </form>

    <!-- 6. Tabel hasil -->
    <div class="table-responsive">
        <table class="table-custom">
            <thead>
                <tr>
                    <th>No</th>
                    <th>Kolom A</th>
                    <th style="text-align:right;">Nilai</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($data)): ?>
                <tr>
                    <td colspan="3" style="text-align:center; color:var(--text-secondary); padding:30px;">
                        Tidak ada data untuk rentang tanggal yang dipilih.
                    </td>
                </tr>
            <?php else: ?>
                <?php $no = 1; foreach ($data as $row): ?>
                <tr>
                    <td><?= $no++ ?></td>
                    <td><?= htmlspecialchars($row['kolom_a']) ?></td>
                    <td style="text-align:right;"><?= number_format($row['nilai'], 2, ',', '.') ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
```

---

## 🧭 Routing — Tambahkan di 3 Tempat

### 1. `pages/NAMA_ROUTER.php`
```php
} elseif ($sub === 'nama_sub_baru') {
    include 'nama_file_baru.php';
}
```

### 2. `index.php` — Sidebar
```php
<?php if (isset($user_permissions['menu_baru']) && $user_permissions['menu_baru'] === '1'): ?>
<div class="menu-group <?= $page === 'menu_baru' ? 'active' : '' ?>">
    <div class="menu-group-header">
        <!-- icon SVG -->
        <span>Nama Menu</span>
    </div>
    <div class="menu-group-items">
        <a href="index.php?page=menu_baru&sub=nama_sub" class="<?= ($page === 'menu_baru' && $sub === 'nama_sub') ? 'active' : '' ?>">
            <span>• Nama Sub Menu</span>
        </a>
    </div>
</div>
<?php endif; ?>
```

### 3. `index.php` — Hak Akses (query + array default)
```php
// Di array $user_permissions:
'menu_baru' => '0',

// Di query SELECT:
"SELECT dashboard, manajemen, ..., menu_baru FROM hak_akses WHERE nik = ?"

// Di INSERT default:
"INSERT INTO hak_akses (..., menu_baru) VALUES (..., '0')"

// Di $allowed_pages:
$allowed_pages = [..., 'menu_baru'];
```

### 4. Database — Tambah kolom hak akses
```sql
ALTER TABLE hak_akses
ADD COLUMN menu_baru ENUM('0','1') NOT NULL DEFAULT '0';
```

---

> [!TIP]
> Selalu jalankan `php -l pages/nama_file_baru.php` untuk memvalidasi syntax sebelum membuka di browser.

> [!IMPORTANT]
> Jangan lupa: setiap menu baru yang memerlukan **hak akses khusus** harus ditambahkan kolomnya ke tabel `hak_akses` di database, dan dikelola lewat halaman **Manajemen User** (menu admin utama).
