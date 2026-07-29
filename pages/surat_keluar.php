<?php
if (!defined('host')) {
    exit('No direct script access allowed');
}

$nik_user = $_SESSION['username'];
$is_admin = isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true;

$success_msg = '';
$error_msg   = '';

// ── 1. AMBIL DAFTAR PEGAWAI UNTUK DROPDOWN LEVEL DISPOSISI ─────────────────
$pegawai_list = [];
$res_peg = $koneksi->query("SELECT nik, nama, jbtn FROM pegawai WHERE stts_aktif = 'AKTIF' ORDER BY nama ASC");
if ($res_peg) {
    while ($row_p = $res_peg->fetch_assoc()) {
        $pegawai_list[] = $row_p;
    }
}

// ── 2. AMBIL MASTER KLASIFIKASI SURAT (surat_klasifikasi) ───────────────────
$klasifikasi_list = [];
$res_k = $koneksi->query("SELECT k.kd, k.klasifikasi, sk.kd as kd_sub, sk.sub_klasifikasi FROM surat_klasifikasi k LEFT JOIN surat_sub_klasifikasi sk ON k.kd = sk.kd_klasifikasi ORDER BY k.klasifikasi ASC");
if ($res_k) {
    while ($row_k = $res_k->fetch_assoc()) {
        $klasifikasi_list[] = $row_k;
    }
}

// ── 3. HELPER DYNAMIC GENERATE NO SURAT VIA SURAT_KLASIFIKASI ───────────────
function buildGeneratedNoSuratByKlasifikasi($koneksi, $kd_klasifikasi, $tgl_surat) {
    if (empty($tgl_surat)) $tgl_surat = date('Y-m-d');
    $time = strtotime($tgl_surat);
    $month_num = (int)date('m', $time);
    $year_num  = date('Y', $time);

    $romawi_map = [1=>'I', 2=>'II', 3=>'III', 4=>'IV', 5=>'V', 6=>'VI', 7=>'VII', 8=>'VIII', 9=>'IX', 10=>'X', 11=>'XI', 12=>'XII'];
    $bulan_romawi = $romawi_map[$month_num] ?? 'I';

    $kd_esc = $koneksi->real_escape_string($kd_klasifikasi);

    // 1. Ambil nomor terakhir (no_tahunan) dari master surat_sub_klasifikasi jika tahun sesuai
    $res_start = $koneksi->query("SELECT no_tahunan, tahun FROM surat_sub_klasifikasi WHERE kd_klasifikasi = '$kd_esc' OR kd = '$kd_esc' LIMIT 1");
    $initial_start = 0;
    if ($res_start && $row_st = $res_start->fetch_assoc()) {
        if ((int)$row_st['tahun'] === (int)$year_num) {
            $initial_start = (int)$row_st['no_tahunan'];
        }
    }

    // 2. Nomor urut berikutnya = Nomor Terakhir + 1
    $next_num = $initial_start + 1;
    $no_tahunan = str_pad($next_num, 3, '0', STR_PAD_LEFT);

    $kode_org = 'RSBW';

    if ($kd_klasifikasi === 'INT') {
        // Surat Internal: 083/INT/RSBW/VII/2026
        return "{$no_tahunan}/INT/{$kode_org}/{$bulan_romawi}/{$year_num}";
    } elseif ($kd_klasifikasi === 'EKS') {
        // Surat Eksternal: 083/RSBW/VII/2026
        return "{$no_tahunan}/{$kode_org}/{$bulan_romawi}/{$year_num}";
    } elseif ($kd_klasifikasi === 'DKL') {
        // Surat Diklat: 083/INT/DIKLAT/RSBW/VII/2026
        return "{$no_tahunan}/INT/DIKLAT/{$kode_org}/{$bulan_romawi}/{$year_num}";
    } else {
        return "{$no_tahunan}/{$kd_klasifikasi}/{$kode_org}/{$bulan_romawi}/{$year_num}";
    }
}

// ── 3B. AJAX HANDLER UNTUK GENERATE DYNAMIC NO SURAT BY TANGGAL ───────────────
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'get_no_surat') {
    $kd_klasifikasi = $_GET['kd_klasifikasi'] ?? ($_GET['id_no_surat'] ?? 'INT');
    $tgl_surat      = $_GET['tgl_surat'] ?? date('Y-m-d');

    echo buildGeneratedNoSuratByKlasifikasi($koneksi, $kd_klasifikasi, $tgl_surat);
    exit;
}

// Pre-generate sample numbers per klasifikasi for client JS
$generated_map = [];
foreach ($klasifikasi_list as $klas) {
    $generated_map[$klas['kd']] = buildGeneratedNoSuratByKlasifikasi($koneksi, $klas['kd'], date('Y-m-d'));
}

// ── 4. PROCESS POST ACTIONS ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── A. SIMPAN SURAT KELUAR BARU + ALOKASI 3 LEVEL DISPOSISI / PERSETUJUAN ─
    if ($action === 'simpan_surat') {
        $no_surat       = trim($_POST['no_surat'] ?? '');
        $kd_klasifikasi = trim($_POST['kd_klasifikasi'] ?? 'INT');
        $tujuan         = trim($_POST['tujuan'] ?? '');
        $tgl_surat      = $_POST['tgl_surat'] ?? date('Y-m-d');
        $tgl_kirim      = $_POST['tgl_kirim'] ?? date('Y-m-d');
        $perihal        = trim($_POST['perihal'] ?? '');
        $keterangan     = trim($_POST['keterangan'] ?? '');
        
        $level1_nik = $_POST['level1_nik'] ?? '';
        $level2_nik = $_POST['level2_nik'] ?? '';
        $level3_nik = $_POST['level3_nik'] ?? '';

        $qr_x = isset($_POST['qr_x']) && $_POST['qr_x'] !== '' ? (float)$_POST['qr_x'] : null;
        $qr_y = isset($_POST['qr_y']) && $_POST['qr_y'] !== '' ? (float)$_POST['qr_y'] : null;

        if (empty($no_surat) || empty($tujuan) || empty($perihal)) {
            $error_msg = "Nomor Surat, Tujuan, dan Perihal wajib diisi!";
        } elseif (empty($level1_nik) || empty($level2_nik) || empty($level3_nik)) {
            $error_msg = "Harap tentukan penanggung jawab disposisi untuk ketiga level (Level 1, Level 2, dan Level 3)!";
        } else {
            // Generate nomor urut transaksi otomatis (SK + Ymd + 3 digit counter)
            $prefix = 'SK' . date('Ymd');
            $res_no = $koneksi->query("SELECT MAX(no_urut) as max_no FROM surat_keluar WHERE no_urut LIKE '$prefix%'");
            $next_num = 1;
            if ($res_no && $row_no = $res_no->fetch_assoc()) {
                if (!empty($row_no['max_no'])) {
                    $last_counter = (int) substr($row_no['max_no'], -3);
                    $next_num = $last_counter + 1;
                }
            }
            $no_urut = $prefix . str_pad($next_num, 3, '0', STR_PAD_LEFT);

            // Handle Upload File PDF
            $file_url = '';
            if (isset($_FILES['file_surat']) && $_FILES['file_surat']['error'] === UPLOAD_ERR_OK) {
                $file_tmp  = $_FILES['file_surat']['tmp_name'];
                $file_name = $_FILES['file_surat']['name'];
                $ext       = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                $allowed   = ['pdf'];

                if (in_array($ext, $allowed)) {
                    $new_filename = 'SuratKeluar_' . $no_urut . '_' . time() . '.' . $ext;
                    $upload_dir   = __DIR__ . '/upload/';
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }
                    $target_path = $upload_dir . $new_filename;
                    if (move_uploaded_file($file_tmp, $target_path)) {
                        $file_url = 'pages/upload/' . $new_filename;
                    }
                } else {
                    $error_msg = "Format file tidak didukung! File surat keluar WAJIB berformat PDF untuk penandatanganan QR Code.";
                }
            }

            if (empty($error_msg)) {
                $kd_lemari = 'SA001'; $kd_rak = 'SR001'; $kd_map = 'SM001'; $kd_ruang = 'SG001';
                $kd_sifat = 'SF001'; $kd_balas = 'SB002'; $kd_status = 'SS003';
                $lampiran = '-'; $tembusan = '-'; $tgl_deadline = $tgl_kirim;

                $stmt_ins = $koneksi->prepare("INSERT INTO surat_keluar 
                    (no_urut, no_surat, tujuan, tgl_surat, perihal, tgl_kirim, kd_lemari, kd_rak, kd_map, kd_ruang, kd_sifat, lampiran, tembusan, tgl_deadline_balas, kd_balas, keterangan, kd_status, kd_klasifikasi, file_url, qr_x, qr_y)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                
                if ($stmt_ins) {
                    $stmt_ins->bind_param("sssssssssssssssssssdd", 
                        $no_urut, $no_surat, $tujuan, $tgl_surat, $perihal, $tgl_kirim,
                        $kd_lemari, $kd_rak, $kd_map, $kd_ruang, $kd_sifat, $lampiran, $tembusan,
                        $tgl_deadline, $kd_balas, $keterangan, $kd_status, $kd_klasifikasi, $file_url,
                        $qr_x, $qr_y
                    );

                    if ($stmt_ins->execute()) {
                        // Simpan 3 Level Disposisi/Persetujuan + User Input ke surat_keluar_disposisi_level
                        $levels = [
                            1 => ['nik' => $level1_nik, 'label' => 'Level 1 (Konseptor / Ka.Bag)'],
                            2 => ['nik' => $level2_nik, 'label' => 'Level 2 (Pemeriksa / Wadir)'],
                            3 => ['nik' => $level3_nik, 'label' => 'Level 3 (Penandatangan / Direktur)']
                        ];

                        $stmt_lvl = $koneksi->prepare("INSERT INTO surat_keluar_disposisi_level (no_urut, level, nik, jabatan_label, status_disposisi, user_input) VALUES (?, ?, ?, ?, 'Menunggu', ?)");
                        foreach ($levels as $lvl_num => $lvl_info) {
                            $stmt_lvl->bind_param("sisss", $no_urut, $lvl_num, $lvl_info['nik'], $lvl_info['label'], $nik_user);
                            $stmt_lvl->execute();
                        }
                        $stmt_lvl->close();

                        // Update counter no_tahunan & bulan/tahun di tabel master surat_sub_klasifikasi
                        $kd_k_esc = $koneksi->real_escape_string($kd_klasifikasi);
                        $time_s   = strtotime($tgl_surat);
                        $m_num    = (int)date('m', $time_s);
                        $y_num    = (int)date('Y', $time_s);

                        preg_match('/^(\d+)/', $no_surat, $matches);
                        $current_no_num = isset($matches[1]) ? (int)$matches[1] : 0;

                        if ($current_no_num > 0) {
                            $koneksi->query("UPDATE surat_sub_klasifikasi SET no_tahunan = $current_no_num, bulan = $m_num, tahun = $y_num WHERE kd_klasifikasi = '$kd_k_esc' OR kd = '$kd_k_esc'");
                        }

                        $success_msg = "Surat Keluar <strong>" . htmlspecialchars($no_surat) . "</strong> (" . $no_urut . ") berhasil disimpan dan alur persetujuan 3 level telah dialokasikan.";
                    } else {
                        $error_msg = "Gagal menyimpan surat keluar: " . $koneksi->error;
                    }
                    $stmt_ins->close();
                } else {
                    $error_msg = "Gagal menyiapkan query insert surat keluar: " . $koneksi->error;
                }
            }
        }
    }

    // ── B. INPUT / UPDATE DISPOSISI / PERSETUJUAN PER LEVEL ────────────────────
    elseif ($action === 'simpan_disposisi') {
        $no_urut       = trim($_POST['no_urut'] ?? '');
        $level         = (int)($_POST['level'] ?? 0);
        $isi_disposisi = trim($_POST['isi_disposisi'] ?? '');
        $harap         = trim($_POST['harap'] ?? '');
        $catatan       = trim($_POST['catatan'] ?? '');
        $pengesahan    = ($_POST['pengesahan'] ?? '') === 'true' ? 'true' : 'false';

        if (empty($no_urut) || $level < 1 || $level > 3 || empty($isi_disposisi)) {
            $error_msg = "Isi catatan disposisi/persetujuan wajib diisi!";
        } else {
            // VERIFIKASI HAK AKSES: Pastikan user terdaftar di level tersebut atau admin
            $can_submit = $is_admin;
            if (!$can_submit) {
                $stmt_chk = $koneksi->prepare("SELECT id FROM surat_keluar_disposisi_level WHERE no_urut = ? AND level = ? AND nik = ?");
                $stmt_chk->bind_param("sis", $no_urut, $level, $nik_user);
                $stmt_chk->execute();
                $res_chk = $stmt_chk->get_result();
                if ($res_chk && $res_chk->num_rows > 0) {
                    $can_submit = true;
                }
                $stmt_chk->close();
            }

            if ($can_submit) {
                $now = date('Y-m-d H:i:s');
                $stmt_up_disp = $koneksi->prepare("UPDATE surat_keluar_disposisi_level 
                    SET status_disposisi = 'Sudah Disposisi', tgl_disposisi = ?, isi_disposisi = ?, harap = ?, catatan = ?, pengesahan = ?
                    WHERE no_urut = ? AND level = ?");
                if ($stmt_up_disp) {
                    $stmt_up_disp->bind_param("ssssssi", $now, $isi_disposisi, $harap, $catatan, $pengesahan, $no_urut, $level);
                    if ($stmt_up_disp->execute()) {
                        $success_msg = "Persetujuan Disposisi Level $level untuk surat keluar $no_urut berhasil disimpan.";

                        // ===== QR CODE STAMPING UTK LEVEL 3 =====
                        if ($level === 3 && $pengesahan === 'true') {
                            require_once __DIR__ . '/../../vendor/autoload.php';
                            
                            // Ambil info surat keluar
                            $res_sk = $koneksi->query("SELECT file_url, qr_x, qr_y FROM surat_keluar WHERE no_urut = '$no_urut'");
                            if ($res_sk && $row_sk = $res_sk->fetch_assoc()) {
                                $pdf_path = __DIR__ . '/../' . str_replace('pages/upload/', 'upload/', $row_sk['file_url']);
                                // the standard is file_url saves 'pages/upload/...', so inside pages it's __DIR__ . '/../pages/upload/...' or just __DIR__ . '/../' . $row_sk['file_url']
                                $pdf_path = __DIR__ . '/../' . $row_sk['file_url'];
                                
                                if (!empty($row_sk['file_url']) && file_exists($pdf_path) && strtolower(pathinfo($pdf_path, PATHINFO_EXTENSION)) === 'pdf') {
                                    
                                    try {
                                        // 1. Generate QR Code
                                        $options = new \chillerlan\QRCode\QROptions([
                                            'version'      => 5,
                                            'outputType'   => \chillerlan\QRCode\QRCode::OUTPUT_IMAGE_PNG,
                                            'eccLevel'     => \chillerlan\QRCode\QRCode::ECC_L,
                                            'scale'        => 5,
                                            'imageBase64'  => false,
                                        ]);
                                        
                                        $verify_url = "http://" . $_SERVER['HTTP_HOST'] . "/alatbantu/pages/verify_surat.php?no_urut=" . urlencode($no_urut);
                                        $qrcode = new \chillerlan\QRCode\QRCode($options);
                                        $qr_img_path = sys_get_temp_dir() . '/qr_' . $no_urut . '.png';
                                        $qrcode->render($verify_url, $qr_img_path);
                                        
                                        // 2. Manipulasi PDF
                                        $pdf = new \setasign\Fpdi\Fpdi();
                                        $pageCount = $pdf->setSourceFile($pdf_path);
                                        
                                        for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
                                            $templateId = $pdf->importPage($pageNo);
                                            $size = $pdf->getTemplateSize($templateId);
                                            
                                            // FPDI defaults to portrait if height > width
                                            $orientation = $size['width'] > $size['height'] ? 'L' : 'P';
                                            $pdf->AddPage($orientation, [$size['width'], $size['height']]);
                                            $pdf->useTemplate($templateId);
                                            
                                            // 3. Jika halaman terakhir, tempel QR Code
                                            if ($pageNo === $pageCount) {
                                                // Konversi persen ke ukuran milimeter
                                                $qr_x_pct = $row_sk['qr_x'] !== null ? (float)$row_sk['qr_x'] : 70;
                                                $qr_y_pct = $row_sk['qr_y'] !== null ? (float)$row_sk['qr_y'] : 80;
                                                
                                                $x_mm = ($qr_x_pct / 100) * $size['width'];
                                                $y_mm = ($qr_y_pct / 100) * $size['height'];
                                                
                                                // Ukuran QR code approx 25x25 mm
                                                $qr_size = 25;
                                                $pdf->Image($qr_img_path, $x_mm, $y_mm, $qr_size, $qr_size, 'PNG');
                                                
                                                // Tambah teks kecil di bawah QR Code
                                                $pdf->SetFont('Arial', '', 8);
                                                $pdf->SetXY($x_mm, $y_mm + $qr_size);
                                                $pdf->Cell($qr_size, 4, 'Signed Digitally', 0, 0, 'C');
                                            }
                                        }
                                        
                                        // Simpan PDF baru
                                        $new_filename = 'SuratKeluar_' . $no_urut . '_' . time() . '_signed.pdf';
                                        $target_path = __DIR__ . '/upload/' . $new_filename;
                                        $pdf->Output('F', $target_path);
                                        
                                        // Update db dg file baru
                                        $new_file_url = 'pages/upload/' . $new_filename;
                                        $koneksi->query("UPDATE surat_keluar SET file_url = '$new_file_url' WHERE no_urut = '$no_urut'");
                                        
                                        // Cleanup
                                        if (file_exists($qr_img_path)) unlink($qr_img_path);
                                        
                                        $success_msg .= " Dokumen telah berhasil ditandatangani secara digital dengan QR Code.";
                                        
                                    } catch (Exception $e) {
                                        $error_msg = "Gagal memproses QR Code pada PDF: " . $e->getMessage();
                                    }
                                }
                            }
                        }
                        // ==========================================
                    } else {
                        $error_msg = "Gagal memperbarui disposisi: " . $koneksi->error;
                    }
                    $stmt_up_disp->close();
                }
            } else {
                $error_msg = "Akses Ditolak: Anda tidak terdaftar sebagai penanggung jawab Level $level untuk surat keluar ini!";
            }
        }
    }

    // ── C. EDIT SURAT KELUAR ───────────────────────────────────────────────────
    elseif ($action === 'edit_surat') {
        $no_urut       = trim($_POST['no_urut'] ?? '');
        $no_surat       = trim($_POST['no_surat'] ?? '');
        $kd_klasifikasi = trim($_POST['kd_klasifikasi'] ?? 'INT');
        $tujuan         = trim($_POST['tujuan'] ?? '');
        $tgl_surat      = $_POST['tgl_surat'] ?? date('Y-m-d');
        $tgl_kirim      = $_POST['tgl_kirim'] ?? date('Y-m-d');
        $perihal        = trim($_POST['perihal'] ?? '');
        $keterangan     = trim($_POST['keterangan'] ?? '');
        
        $qr_x = isset($_POST['qr_x']) && $_POST['qr_x'] !== '' ? (float)$_POST['qr_x'] : null;
        $qr_y = isset($_POST['qr_y']) && $_POST['qr_y'] !== '' ? (float)$_POST['qr_y'] : null;

        // Cek Hak Akses: Admin atau User yang input
        $can_edit = $is_admin;
        if (!$can_edit) {
            $stmt_chk = $koneksi->prepare("SELECT id FROM surat_keluar_disposisi_level WHERE no_urut = ? AND user_input = ? LIMIT 1");
            $stmt_chk->bind_param("ss", $no_urut, $nik_user);
            $stmt_chk->execute();
            $res_chk = $stmt_chk->get_result();
            if ($res_chk && $res_chk->num_rows > 0) {
                $can_edit = true;
            }
            $stmt_chk->close();
        }

        if (empty($no_urut) || empty($no_surat) || empty($tujuan) || empty($perihal)) {
            $error_msg = "Nomor Surat, Tujuan, dan Perihal wajib diisi!";
        } elseif (!$can_edit) {
            $error_msg = "Akses Ditolak: Hanya Admin atau pembuat surat yang dapat mengedit data ini!";
        } else {
            $update_file_query = "";
            $file_url = "";

            // Handle Upload File PDF (Menyusul/Update)
            if (isset($_FILES['file_surat']) && $_FILES['file_surat']['error'] === UPLOAD_ERR_OK) {
                $file_tmp  = $_FILES['file_surat']['tmp_name'];
                $file_name = $_FILES['file_surat']['name'];
                $ext       = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                $allowed   = ['pdf'];

                if (in_array($ext, $allowed)) {
                    $new_filename = 'SuratKeluar_' . $no_urut . '_' . time() . '.' . $ext;
                    $upload_dir   = __DIR__ . '/upload/';
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }
                    $target_path = $upload_dir . $new_filename;
                    if (move_uploaded_file($file_tmp, $target_path)) {
                        $file_url = 'pages/upload/' . $new_filename;
                        $update_file_query = ", file_url = ?";
                    }
                } else {
                    $error_msg = "Format file tidak didukung! File surat keluar WAJIB berformat PDF.";
                }
            }

            if (empty($error_msg)) {
                $qr_update_query = "";
                if ($qr_x !== null && $qr_y !== null) {
                    $qr_update_query = ", qr_x = ?, qr_y = ?";
                }

                $sql_up = "UPDATE surat_keluar SET no_surat=?, tujuan=?, tgl_surat=?, perihal=?, tgl_kirim=?, keterangan=?, kd_klasifikasi=? $update_file_query $qr_update_query WHERE no_urut=?";
                $stmt_up = $koneksi->prepare($sql_up);
                if ($stmt_up) {
                    // This gets tricky with dynamic binds, so we will construct the params array
                    $types = "sssssss";
                    $params = [&$no_surat, &$tujuan, &$tgl_surat, &$perihal, &$tgl_kirim, &$keterangan, &$kd_klasifikasi];
                    
                    if (!empty($file_url)) {
                        $types .= "s";
                        $params[] = &$file_url;
                    }
                    if ($qr_x !== null && $qr_y !== null) {
                        $types .= "dd";
                        $params[] = &$qr_x;
                        $params[] = &$qr_y;
                    }
                    $types .= "s";
                    $params[] = &$no_urut;

                    $bind_names[] = $types;
                    for ($i=0; $i<count($params); $i++) {
                        $bind_name = 'bind' . $i;
                        $$bind_name = $params[$i];
                        $bind_names[] = &$$bind_name;
                    }

                    call_user_func_array(array($stmt_up, 'bind_param'), $bind_names);
                    
                    if ($stmt_up->execute()) {
                        $success_msg = "Data Surat Keluar $no_urut berhasil diperbarui.";
                    } else {
                        $error_msg = "Gagal memperbarui surat keluar: " . $koneksi->error;
                    }
                    $stmt_up->close();
                } else {
                    $error_msg = "Gagal menyiapkan query update surat keluar.";
                }
            }
        }
    }
}

// ── 5. QUERY FILTER DATA SURAT KELUAR KETAT PER USER ────────────────────────
$search = trim($_GET['search'] ?? '');

if ($is_admin) {
    // Admin utama melihat semua surat keluar
    $sql_surat = "SELECT s.*, 
                    (SELECT COUNT(*) FROM surat_keluar_disposisi_level d1 WHERE d1.no_urut = s.no_urut AND d1.status_disposisi = 'Sudah Disposisi') as total_disposisi
                  FROM surat_keluar s";
    if (!empty($search)) {
        $search_esc = $koneksi->real_escape_string($search);
        $sql_surat .= " WHERE s.no_surat LIKE '%$search_esc%' OR s.tujuan LIKE '%$search_esc%' OR s.perihal LIKE '%$search_esc%' OR s.no_urut LIKE '%$search_esc%'";
    }
    $sql_surat .= " ORDER BY s.tgl_kirim DESC, s.no_urut DESC";
} else {
    // User biasa HANYA BISA MEMBUKA surat jika NIK-nya terdaftar di Level 1, Level 2, Level 3, ATAU penginput surat di tabel disposisi_level
    $nik_esc = $koneksi->real_escape_string($nik_user);
    $sql_surat = "SELECT DISTINCT s.*,
                    (SELECT COUNT(*) FROM surat_keluar_disposisi_level d1 WHERE d1.no_urut = s.no_urut AND d1.status_disposisi = 'Sudah Disposisi') as total_disposisi
                  FROM surat_keluar s
                  INNER JOIN surat_keluar_disposisi_level d ON s.no_urut = d.no_urut
                  WHERE (d.nik = '$nik_esc' OR d.user_input = '$nik_esc')";
    
    if (!empty($search)) {
        $search_esc = $koneksi->real_escape_string($search);
        $sql_surat .= " AND (s.no_surat LIKE '%$search_esc%' OR s.tujuan LIKE '%$search_esc%' OR s.perihal LIKE '%$search_esc%' OR s.no_urut LIKE '%$search_esc%')";
    }
    $sql_surat .= " ORDER BY s.tgl_kirim DESC, s.no_urut DESC";
}

$res_surat = $koneksi->query($sql_surat);
$surat_data = [];
if ($res_surat) {
    while ($row_s = $res_surat->fetch_assoc()) {
        $no_u = $row_s['no_urut'];
        // Ambil rincian 3 level disposisi/persetujuan untuk surat keluar ini
        $res_lvl = $koneksi->query("SELECT d.*, p.nama as nama_pegawai, p.jbtn 
                                    FROM surat_keluar_disposisi_level d
                                    LEFT JOIN pegawai p ON p.nik = d.nik
                                    WHERE d.no_urut = '$no_u'
                                    ORDER BY d.level ASC");
        $levels_data = [];
        if ($res_lvl) {
            while ($r_l = $res_lvl->fetch_assoc()) {
                $levels_data[$r_l['level']] = $r_l;
            }
        }
        $row_s['levels'] = $levels_data;
        $surat_data[] = $row_s;
    }
}
?>

<div class="content-card">
    <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 16px; margin-bottom: 24px;">
        <div>
            <h1 class="page-title" style="margin-bottom: 4px; display: flex; align-items: center; gap: 10px;">
                <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="color: #0284c7;"><line x1="22" y1="2" x2="11" y2="13"></line><polygon points="22 2 15 22 11 13 2 9 22 2"></polygon></svg>
                Surat Keluar & Disposisi Bertingkat
            </h1>
            <p class="text-secondary" style="margin: 0; font-size: 14px;">
                Pengelolaan pengajuan surat keluar dengan penomoran otomatis & alur persetujuan 3 level independen (paralel).
            </p>
        </div>
        
        <button type="button" class="btn btn-primary" onclick="openModal('addSuratKeluarModal')" style="display: flex; align-items: center; gap: 8px; background: #0284c7; border-color: #0284c7;">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
            Buat Surat Keluar Baru
        </button>
    </div>

    <!-- NOTIFIKASI PESAN -->
    <?php if (!empty($success_msg)): ?>
        <div style="background: #dcfce7; border: 1px solid #86efac; color: #166534; padding: 14px 18px; border-radius: 12px; margin-bottom: 20px; font-size: 14px; display: flex; align-items: center; gap: 10px;">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"></polyline></svg>
            <div><?= $success_msg ?></div>
        </div>
    <?php endif; ?>

    <?php if (!empty($error_msg)): ?>
        <div style="background: #fee2e2; border: 1px solid #fca5a5; color: #991b1b; padding: 14px 18px; border-radius: 12px; margin-bottom: 20px; font-size: 14px; display: flex; align-items: center; gap: 10px;">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
            <div><?= $error_msg ?></div>
        </div>
    <?php endif; ?>

    <!-- CARI SURAT -->
    <div style="margin-bottom: 20px;">
        <form method="GET" style="display: flex; gap: 12px; max-width: 480px;">
            <input type="hidden" name="page" value="surat_menyurat">
            <input type="hidden" name="sub" value="surat_keluar">
            <input type="text" name="search" class="form-control" placeholder="Cari No. Surat, Tujuan, Perihal..." value="<?= htmlspecialchars($search) ?>">
            <button type="submit" class="btn btn-secondary">Cari</button>
            <?php if (!empty($search)): ?>
                <a href="index.php?page=surat_menyurat&sub=surat_keluar" class="btn btn-secondary" style="background: #e2e8f0; color: #475569;">Reset</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- LIST DATA SURAT KELUAR -->
    <?php if (empty($surat_data)): ?>
        <div style="text-align: center; padding: 48px 20px; background: #f8fafc; border-radius: 16px; border: 1px dashed #cbd5e1;">
            <div style="font-size: 40px; margin-bottom: 12px;">📤</div>
            <h3 style="margin-bottom: 6px; color: #334155;">Belum Ada Surat Keluar</h3>
            <p style="color: #64748b; font-size: 14px; max-width: 400px; margin: 0 auto;">
                <?= !empty($search) ? 'Tidak ditemukan surat keluar yang cocok dengan pencarian.' : 'Anda belum memiliki pengajuan surat keluar yang ditugaskan kepada Anda.' ?>
            </p>
        </div>
    <?php else: ?>
        <div style="display: grid; grid-template-columns: 1fr; gap: 20px;">
            <?php foreach ($surat_data as $surat): ?>
                <?php
                $user_level = 0;
                foreach ($surat['levels'] as $lvl => $ldat) {
                    if ($ldat['nik'] === $nik_user) {
                        $user_level = $lvl;
                        break;
                    }
                }
                ?>
                <div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 20px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.04); transition: all 0.2s ease;">
                    
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 12px; margin-bottom: 14px; border-bottom: 1px solid #f1f5f9; padding-bottom: 14px;">
                        <div>
                            <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 4px;">
                                <span style="background: #f0f9ff; color: #0284c7; font-weight: 700; font-size: 12px; padding: 3px 10px; border-radius: 6px; border: 1px solid #bae6fd;">
                                    <?= htmlspecialchars($surat['no_urut']) ?>
                                </span>
                                <h3 style="margin: 0; font-size: 16px; color: #0f172a; font-weight: 700;">
                                    No. Surat: <?= htmlspecialchars($surat['no_surat']) ?>
                                </h3>
                            </div>
                            <div style="color: #64748b; font-size: 13px;">
                                🎯 <strong>Tujuan:</strong> <?= htmlspecialchars($surat['tujuan']) ?> &bull; 🗓️ <strong>Tgl Kirim:</strong> <?= date('d M Y', strtotime($surat['tgl_kirim'])) ?>
                            </div>
                        </div>

                        <div style="display: flex; align-items: center; gap: 8px;">
                            <?php if (!empty($surat['file_url']) && file_exists(__DIR__ . '/../' . $surat['file_url'])): ?>
                                <a href="<?= htmlspecialchars($surat['file_url']) ?>" target="_blank" class="btn btn-sm btn-secondary" style="display: inline-flex; align-items: center; gap: 6px; font-size: 12px;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg>
                                    Draf / Berkas
                                </a>
                            <?php endif; ?>

                            <?php 
                            $can_edit = $is_admin;
                            if (!$can_edit) {
                                foreach ($surat['levels'] as $l_num => $l_data) {
                                    if (isset($l_data['user_input']) && $l_data['user_input'] === $nik_user) {
                                        $can_edit = true;
                                        break;
                                    }
                                }
                            }
                            ?>
                            <?php if ($can_edit): ?>
                                <button type="button" class="btn btn-sm btn-warning" onclick='openEditSKModal(<?= json_encode($surat) ?>)' style="display: inline-flex; align-items: center; gap: 6px; font-size: 12px; background: #f59e0b; border-color: #f59e0b; color: white;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                                    Edit
                                </button>
                            <?php endif; ?>

                            <button type="button" class="btn btn-sm btn-primary" onclick='openDetailDisposisiSKModal(<?= json_encode($surat) ?>)' style="display: inline-flex; align-items: center; gap: 6px; font-size: 12px; background: #0284c7; border-color: #0284c7;">
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                Detail / Persetujuan
                            </button>
                        </div>
                    </div>

                    <!-- PERIHAL & KETERANGAN -->
                    <div style="margin-bottom: 16px;">
                        <div style="font-weight: 600; color: #1e293b; font-size: 15px; margin-bottom: 4px;">
                            <?= htmlspecialchars($surat['perihal']) ?>
                        </div>
                        <?php if (!empty($surat['keterangan'])): ?>
                            <div style="font-size: 13px; color: #64748b;">
                                💬 <?= htmlspecialchars($surat['keterangan']) ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- PROGRESS DISPOSISI 3 LEVEL -->
                    <div style="background: #f8fafc; border-radius: 12px; padding: 14px; border: 1px solid #f1f5f9;">
                        <div style="font-size: 12px; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 10px; display: flex; justify-content: space-between; align-items: center;">
                            <span>Status Persetujuan / Disposisi 3 Level</span>
                            <span style="color: #0284c7; font-weight: 800; text-transform: none;">
                                <?= $surat['total_disposisi'] ?> / 3 Selesai
                            </span>
                        </div>

                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px;">
                            <?php for ($l = 1; $l <= 3; $l++): ?>
                                <?php 
                                $ldat = $surat['levels'][$l] ?? null; 
                                $is_my_level = ($ldat && $ldat['nik'] === $nik_user);
                                $is_done = ($ldat && $ldat['status_disposisi'] === 'Sudah Disposisi');
                                ?>
                                <div style="background: #fff; border: 1px solid <?= $is_my_level ? '#0284c7' : '#e2e8f0' ?>; border-radius: 10px; padding: 10px 12px; position: relative;">
                                    
                                    <?php if ($is_my_level): ?>
                                        <div style="position: absolute; top: -8px; right: 10px; background: #0284c7; color: #fff; font-size: 9px; font-weight: 800; padding: 2px 6px; border-radius: 4px; text-transform: uppercase;">
                                            Tugas Anda
                                        </div>
                                    <?php endif; ?>

                                    <div style="font-weight: 700; font-size: 12px; color: #334155; margin-bottom: 2px;">
                                        Level <?= $l ?>
                                    </div>
                                    <div style="font-size: 13px; font-weight: 600; color: #0f172a; margin-bottom: 4px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?= htmlspecialchars($ldat['nama_pegawai'] ?? '-') ?>">
                                        <?= htmlspecialchars($ldat['nama_pegawai'] ?? 'Belum Ditunjuk') ?>
                                    </div>

                                    <div style="display: flex; align-items: center; justify-content: space-between;">
                                        <span style="display: inline-block; font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 6px; background: <?= $is_done ? '#dcfce7' : '#fef3c7' ?>; color: <?= $is_done ? '#15803d' : '#b45309' ?>;">
                                            <?= $is_done ? '✓ Disetujui' : '⏳ Menunggu' ?>
                                        </span>

                                        <?php if ($is_done && !empty($ldat['tgl_disposisi'])): ?>
                                            <span style="font-size: 10px; color: #94a3b8;">
                                                <?= date('d/m/y H:i', strtotime($ldat['tgl_disposisi'])) ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endfor; ?>
                        </div>
                    </div>

                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- ========================================================================= -->
<!-- MODAL 1: INPUT SURAT KELUAR BARU + PENENTUAN 3 LEVEL                      -->
<!-- ========================================================================= -->
<div id="addSuratKeluarModal" class="modal-overlay" onclick="closeModal('addSuratKeluarModal')">
    <div class="modal-content" onclick="event.stopPropagation()" style="max-width: 720px;">
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="simpan_surat">
            <div class="modal-header">
                <h3>Buat Pengajuan Surat Keluar Baru</h3>
                <button type="button" class="btn-close" onclick="closeModal('addSuratKeluarModal')">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                </button>
            </div>

            <div class="modal-body" style="display: flex; flex-direction: column; gap: 14px;">
                
                <!-- DROPDOWN PATTERN SURAT_KLASIFIKASI -->
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 14px;">
                    <label class="form-label" style="color: #0284c7; font-weight: 700; display: flex; align-items: center; gap: 6px;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                        Pilih Klasifikasi Surat (surat_klasifikasi)
                    </label>
                    <select id="select_jenis_surat" name="kd_klasifikasi" class="form-control" onchange="autoGenerateNoSurat()" style="background: #fff;">
                        <option value="">-- Pilih Klasifikasi Surat --</option>
                        <?php foreach ($klasifikasi_list as $klas): ?>
                            <option value="<?= $klas['kd'] ?>">
                                📜 <?= htmlspecialchars($klas['klasifikasi']) ?> (<?= htmlspecialchars($klas['kd']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div style="font-size: 11px; color: #64748b; margin-top: 4px;">
                        💡 Memilih klasifikasi surat di atas akan otomatis merangkai Nomor Surat resmi berdasarkan master SIMRS.
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                    <div>
                        <label class="form-label">Nomor Surat Keluar *</label>
                        <input type="text" id="input_no_surat" name="no_surat" class="form-control" placeholder="Contoh: 045/RSUD/SK/2026" required>
                    </div>
                    <div>
                        <label class="form-label">Tujuan Pengiriman *</label>
                        <input type="text" name="tujuan" class="form-control" placeholder="Contoh: Kepala Dinas Kesehatan" required>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                    <div>
                        <label class="form-label">Tanggal Surat</label>
                        <input type="date" id="input_tgl_surat" name="tgl_surat" class="form-control" value="<?= date('Y-m-d') ?>" onchange="autoGenerateNoSurat()" required>
                    </div>
                    <div>
                        <label class="form-label">Rencana Tanggal Kirim</label>
                        <input type="date" name="tgl_kirim" class="form-control" value="<?= date('Y-m-d') ?>" required>
                    </div>
                </div>

                <div>
                    <label class="form-label">Perihal / Subjek Surat Keluar *</label>
                    <input type="text" name="perihal" class="form-control" placeholder="Ringkasan perihal surat keluar..." required>
                </div>

                <div>
                    <label class="form-label">Keterangan / Catatan Pengajuan</label>
                    <textarea name="keterangan" class="form-control" rows="2" placeholder="Catatan internal pengajuan..."></textarea>
                </div>

                <div>
                    <label class="form-label">Upload Draf Berkas Surat (Wajib PDF)</label>
                    <input type="file" name="file_surat" id="file_surat_add" class="form-control" accept=".pdf" onchange="renderPdfPreview(this, 'add')">
                    
                    <!-- Hidden inputs for QR coords -->
                    <input type="hidden" name="qr_x" id="qr_x_add" value="70">
                    <input type="hidden" name="qr_y" id="qr_y_add" value="80">
                    
                    <div id="preview_container_add" style="display:none; margin-top:10px; position:relative; width:100%; border:1px solid #ccc; overflow:hidden;">
                        <canvas id="pdf_canvas_add" style="width:100%; display:block;"></canvas>
                        <div id="qr_box_add" style="position:absolute; width:50px; height:50px; background:rgba(2, 132, 199, 0.5); border:2px dashed #0369a1; cursor:move; display:flex; align-items:center; justify-content:center; font-size:10px; color:#fff; text-align:center;">
                            Posisi QR
                        </div>
                    </div>
                    <small style="color:#64748b; display:none; margin-top:4px;" id="preview_help_add">Geser kotak biru di atas untuk menentukan posisi Tanda Tangan QR Code.</small>
                </div>

                <!-- ALOKASI 3 LEVEL DISPOSISI / PERSETUJUAN -->
                <div style="background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 12px; padding: 16px; margin-top: 6px;">
                    <div style="font-weight: 700; color: #0369a1; font-size: 14px; margin-bottom: 12px; display: flex; align-items: center; gap: 8px;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><polyline points="16 11 18 13 22 9"></polyline></svg>
                        Penunjukan Penanggung Jawab Disposisi / Persetujuan 3 Level
                    </div>

                    <div style="display: flex; flex-direction: column; gap: 12px;">
                        <div>
                            <label class="form-label" style="color: #0284c7; font-weight: 700;">Level 1 (Konseptor / Ka.Bag) *</label>
                            <select name="level1_nik" class="form-control" required style="background: #fff;">
                                <option value="">-- Pilih Pegawai Level 1 --</option>
                                <?php foreach ($pegawai_list as $p): ?>
                                    <option value="<?= $p['nik'] ?>"><?= htmlspecialchars($p['nama']) ?> (<?= htmlspecialchars($p['jbtn'] ?: 'Pegawai') ?> - NIK: <?= $p['nik'] ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="form-label" style="color: #0284c7; font-weight: 700;">Level 2 (Pemeriksa / Wadir) *</label>
                            <select name="level2_nik" class="form-control" required style="background: #fff;">
                                <option value="">-- Pilih Pegawai Level 2 --</option>
                                <?php foreach ($pegawai_list as $p): ?>
                                    <option value="<?= $p['nik'] ?>"><?= htmlspecialchars($p['nama']) ?> (<?= htmlspecialchars($p['jbtn'] ?: 'Pegawai') ?> - NIK: <?= $p['nik'] ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="form-label" style="color: #0284c7; font-weight: 700;">Level 3 (Penandatangan / Direktur) *</label>
                            <select name="level3_nik" class="form-control" required style="background: #fff;">
                                <option value="">-- Pilih Pegawai Level 3 --</option>
                                <?php foreach ($pegawai_list as $p): ?>
                                    <option value="<?= $p['nik'] ?>"><?= htmlspecialchars($p['nama']) ?> (<?= htmlspecialchars($p['jbtn'] ?: 'Pegawai') ?> - NIK: <?= $p['nik'] ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('addSuratKeluarModal')">Batal</button>
                <button type="submit" class="btn btn-primary" style="background: #0284c7; border-color: #0284c7;">Simpan & Dialokasikan</button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL 2: DETAIL SURAT KELUAR & INPUT DISPOSISI PER USER LEVEL             -->
<!-- ========================================================================= -->
<div id="detailDisposisiSKModal" class="modal-overlay" onclick="closeModal('detailDisposisiSKModal')">
    <div class="modal-content" onclick="event.stopPropagation()" style="max-width: 760px; max-height: 90vh; overflow-y: auto;">
        <div class="modal-header">
            <h3 id="det_sk_no_surat_title">Detail Surat Keluar</h3>
            <button type="button" class="btn-close" onclick="closeModal('detailDisposisiSKModal')">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
            </button>
        </div>

        <div class="modal-body">
            <!-- HEAD INFO SURAT KELUAR -->
            <div style="background: #f8fafc; border-radius: 12px; padding: 16px; border: 1px solid #e2e8f0; margin-bottom: 20px;">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; font-size: 13px; margin-bottom: 10px;">
                    <div><strong>No. Urut:</strong> <span id="det_sk_no_urut"></span></div>
                    <div><strong>No. Surat:</strong> <span id="det_sk_no_surat"></span></div>
                    <div><strong>Tujuan:</strong> <span id="det_sk_tujuan"></span></div>
                    <div><strong>Tgl Kirim:</strong> <span id="det_sk_tgl_kirim"></span></div>
                </div>
                <div style="font-size: 14px; color: #1e293b;">
                    <strong>Perihal:</strong> <span id="det_sk_perihal"></span>
                </div>
                <div id="det_sk_file_container" style="margin-top: 10px;"></div>
            </div>

            <!-- TABEL HISTORI 3 LEVEL -->
            <h4 style="margin-bottom: 12px; font-size: 15px; color: #334155; display: flex; align-items: center; gap: 8px;">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"></line><line x1="8" y1="12" x2="21" y2="12"></line><line x1="8" y1="18" x2="21" y2="18"></line><line x1="3" y1="6" x2="3.01" y2="6"></line><line x1="3" y1="12" x2="3.01" y2="12"></line><line x1="3" y1="18" x2="3.01" y2="18"></line></svg>
                Status Persetujuan / Disposisi 3 Level
            </h4>
            
            <div id="det_sk_levels_container" style="display: flex; flex-direction: column; gap: 12px; margin-bottom: 24px;">
                <!-- Filled via JS -->
            </div>

            <!-- FORM INPUT DISPOSISI / PERSETUJUAN SAYA -->
            <div id="det_sk_form_input_container" style="display: none; background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 14px; padding: 18px;">
                <h4 style="margin-bottom: 12px; font-size: 15px; color: #0369a1; display: flex; align-items: center; gap: 8px;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                    Input Persetujuan / Disposisi Anda (<span id="det_sk_my_level_title"></span>)
                </h4>

                <form method="POST">
                    <input type="hidden" name="action" value="simpan_disposisi">
                    <input type="hidden" id="form_sk_no_urut" name="no_urut">
                    <input type="hidden" id="form_sk_level" name="level">

                    <div style="display: flex; flex-direction: column; gap: 12px;">
                        <div>
                            <label class="form-label" style="color: #0369a1; font-weight: 700;">Catatan / Tanggapan Disposisi *</label>
                            <textarea id="form_sk_isi_disposisi" name="isi_disposisi" class="form-control" rows="3" placeholder="Masukkan instruksi atau tanggapan persetujuan..." required style="background: #fff;"></textarea>
                        </div>

                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                            <div>
                                <label class="form-label" style="color: #0369a1;">Harap / Tindakan</label>
                                <input type="text" id="form_sk_harap" name="harap" class="form-control" placeholder="Contoh: Kirimkan / Revisi" style="background: #fff;">
                            </div>
                            <div>
                                <label class="form-label" style="color: #0369a1;">Pengesahan / Setujui</label>
                                <select id="form_sk_pengesahan" name="pengesahan" class="form-control" style="background: #fff;">
                                    <option value="true">Disetujui (True)</option>
                                    <option value="false">Tidak (False)</option>
                                </select>
                            </div>
                        </div>

                        <div>
                            <label class="form-label" style="color: #0369a1;">Catatan Tambahan</label>
                            <input type="text" id="form_sk_catatan" name="catatan" class="form-control" placeholder="Catatan internal..." style="background: #fff;">
                        </div>

                        <button type="submit" class="btn btn-primary" style="align-self: flex-end; margin-top: 4px; background: #0284c7; border-color: #0284c7;">
                            Simpan Persetujuan
                        </button>
                    </div>
                </form>
            </div>

        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL 3: EDIT SURAT KELUAR                                                -->
<!-- ========================================================================= -->
<div id="editSuratKeluarModal" class="modal-overlay" onclick="closeModal('editSuratKeluarModal')">
    <div class="modal-content" onclick="event.stopPropagation()" style="max-width: 720px;">
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="edit_surat">
            <input type="hidden" name="no_urut" id="edit_no_urut">
            <div class="modal-header">
                <h3>Edit Surat Keluar</h3>
                <button type="button" class="btn-close" onclick="closeModal('editSuratKeluarModal')">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                </button>
            </div>

            <div class="modal-body" style="display: flex; flex-direction: column; gap: 14px;">
                
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 14px;">
                    <label class="form-label" style="color: #0284c7; font-weight: 700;">Klasifikasi Surat</label>
                    <select id="edit_kd_klasifikasi" name="kd_klasifikasi" class="form-control" style="background: #fff;">
                        <?php foreach ($klasifikasi_list as $klas): ?>
                            <option value="<?= $klas['kd'] ?>">
                                📜 <?= htmlspecialchars($klas['klasifikasi']) ?> (<?= htmlspecialchars($klas['kd']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                    <div>
                        <label class="form-label">Nomor Surat Keluar *</label>
                        <input type="text" id="edit_no_surat" name="no_surat" class="form-control" required>
                    </div>
                    <div>
                        <label class="form-label">Tujuan Pengiriman *</label>
                        <input type="text" id="edit_tujuan" name="tujuan" class="form-control" required>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                    <div>
                        <label class="form-label">Tanggal Surat</label>
                        <input type="date" id="edit_tgl_surat" name="tgl_surat" class="form-control" required>
                    </div>
                    <div>
                        <label class="form-label">Rencana Tanggal Kirim</label>
                        <input type="date" id="edit_tgl_kirim" name="tgl_kirim" class="form-control" required>
                    </div>
                </div>

                <div>
                    <label class="form-label">Perihal / Subjek Surat Keluar *</label>
                    <input type="text" id="edit_perihal" name="perihal" class="form-control" required>
                </div>

                <div>
                    <label class="form-label">Keterangan / Catatan Pengajuan</label>
                    <textarea id="edit_keterangan" name="keterangan" class="form-control" rows="2"></textarea>
                </div>

                <div>
                    <label class="form-label">Upload Draf Berkas Surat Menyusul/Update (Wajib PDF)</label>
                    <input type="file" name="file_surat" id="file_surat_edit" class="form-control" accept=".pdf" onchange="renderPdfPreview(this, 'edit')">
                    <small style="color: #64748b;">Biarkan kosong jika tidak ingin mengubah file.</small>
                    
                    <!-- Hidden inputs for QR coords -->
                    <input type="hidden" name="qr_x" id="qr_x_edit" value="70">
                    <input type="hidden" name="qr_y" id="qr_y_edit" value="80">
                    
                    <div id="preview_container_edit" style="display:none; margin-top:10px; position:relative; width:100%; border:1px solid #ccc; overflow:hidden;">
                        <canvas id="pdf_canvas_edit" style="width:100%; display:block;"></canvas>
                        <div id="qr_box_edit" style="position:absolute; width:50px; height:50px; background:rgba(2, 132, 199, 0.5); border:2px dashed #0369a1; cursor:move; display:flex; align-items:center; justify-content:center; font-size:10px; color:#fff; text-align:center;">
                            Posisi QR
                        </div>
                    </div>
                    <small style="color:#64748b; display:none; margin-top:4px;" id="preview_help_edit">Geser kotak biru di atas untuk menentukan posisi Tanda Tangan QR Code.</small>
                </div>

            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('editSuratKeluarModal')">Batal</button>
                <button type="submit" class="btn btn-primary" style="background: #f59e0b; border-color: #f59e0b; color: white;">Simpan Perubahan</button>
            </div>
        </form>
    </div>
</div>

<script>
const LOGGED_NIK     = <?= json_encode($nik_user) ?>;
const IS_ADMIN       = <?= json_encode($is_admin) ?>;
const GENERATED_MAP  = <?= json_encode($generated_map) ?>;

function autoGenerateNoSurat() {
    const selectElem = document.getElementById('select_jenis_surat');
    const inputTgl   = document.getElementById('input_tgl_surat');
    const inputNo    = document.getElementById('input_no_surat');

    if (!selectElem || !inputNo) return;

    const kdKlasifikasi = selectElem.value;
    const tglSurat      = inputTgl ? inputTgl.value : '';

    if (!kdKlasifikasi) return;

    fetch(`index.php?page=surat_keluar&ajax_action=get_no_surat&kd_klasifikasi=${encodeURIComponent(kdKlasifikasi)}&tgl_surat=${encodeURIComponent(tglSurat)}`)
        .then(response => response.text())
        .then(noSurat => {
            if (noSurat && noSurat.trim() !== '') {
                inputNo.value = noSurat.trim();
            }
        })
        .catch(err => console.error('Error auto generating no surat:', err));
}

function openDetailDisposisiSKModal(s) {
    document.getElementById('det_sk_no_surat_title').innerText = "Surat Keluar: " + s.no_surat;
    document.getElementById('det_sk_no_urut').innerText       = s.no_urut;
    document.getElementById('det_sk_no_surat').innerText      = s.no_surat;
    document.getElementById('det_sk_tujuan').innerText        = s.tujuan;
    document.getElementById('det_sk_tgl_kirim').innerText      = s.tgl_kirim;
    document.getElementById('det_sk_perihal').innerText       = s.perihal;

    // File Preview Button
    const fileContainer = document.getElementById('det_sk_file_container');
    if (s.file_url) {
        fileContainer.innerHTML = `<a href="${s.file_url}" target="_blank" class="btn btn-sm btn-secondary" style="display:inline-flex; align-items:center; gap:6px;">📄 Lihat Berkas Draf/Final (PDF/Gambar)</a>`;
    } else {
        fileContainer.innerHTML = `<span style="font-size:12px; color:#94a3b8;">(Tidak ada file draf terlampir)</span>`;
    }

    // Render 3 Level Disposisi Info
    const lvlContainer = document.getElementById('det_sk_levels_container');
    lvlContainer.innerHTML = '';

    let myAssignedLevel = 0;
    let myExistingData = null;

    for (let l = 1; l <= 3; l++) {
        const ldat = (s.levels && s.levels[l]) ? s.levels[l] : null;
        const isDone = (ldat && ldat.status_disposisi === 'Sudah Disposisi');
        const isUserMe = (ldat && ldat.nik === LOGGED_NIK);

        if (isUserMe) {
            myAssignedLevel = l;
            myExistingData = ldat;
        }

        let cardHtml = `
            <div style="background: ${isUserMe ? '#f0f9ff' : '#fff'}; border: 1px solid ${isUserMe ? '#bae6fd' : '#e2e8f0'}; border-radius: 12px; padding: 14px;">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 6px;">
                    <div>
                        <span style="font-size: 11px; font-weight: 800; text-transform: uppercase; color: #64748b;">Level ${l}</span> &bull; 
                        <strong style="color: #0f172a;">${ldat ? ldat.nama_pegawai : 'Belum Ditunjuk'}</strong> 
                        <span style="font-size: 12px; color: #64748b;">(${ldat ? (ldat.jbtn || 'Pegawai') : ''})</span>
                    </div>
                    <span style="font-size: 11px; font-weight: 700; padding: 3px 10px; border-radius: 20px; background: ${isDone ? '#dcfce7' : '#fef3c7'}; color: ${isDone ? '#15803d' : '#b45309'};">
                        ${isDone ? '✓ Disetujui' : '⏳ Menunggu'}
                    </span>
                </div>
        `;

        if (isDone) {
            cardHtml += `
                <div style="font-size: 13px; color: #1e293b; margin-top: 6px; padding: 8px 12px; background: rgba(255,255,255,0.7); border-radius: 8px; border-left: 3px solid #0284c7;">
                    <div><strong>Tanggapan:</strong> ${ldat.isi_disposisi || '-'}</div>
                    ${ldat.harap ? `<div style="font-size:12px; color:#475569; margin-top:2px;"><strong>Harap:</strong> ${ldat.harap}</div>` : ''}
                    ${ldat.catatan ? `<div style="font-size:12px; color:#475569; margin-top:2px;"><strong>Catatan:</strong> ${ldat.catatan}</div>` : ''}
                    <div style="font-size: 10px; color: #94a3b8; margin-top: 4px;">📅 Disetujui pada ${ldat.tgl_disposisi || '-'}</div>
                </div>
            `;
        } else {
            cardHtml += `<div style="font-size: 12px; color: #94a3b8; font-style: italic;">Belum ada persetujuan/disposisi.</div>`;
        }

        cardHtml += `</div>`;
        lvlContainer.innerHTML += cardHtml;
    }

    // FORM INPUT UNTUK USER JIKA APPLICABLE (OR ADMIN CAN CHOOSE LEVEL)
    const formContainer = document.getElementById('det_sk_form_input_container');
    if (myAssignedLevel > 0 || IS_ADMIN) {
        const activeLevel = myAssignedLevel > 0 ? myAssignedLevel : 1;
        document.getElementById('det_sk_my_level_title').innerText = "Level " + activeLevel;
        document.getElementById('form_sk_no_urut').value           = s.no_urut;
        document.getElementById('form_sk_level').value             = activeLevel;
        
        if (myExistingData) {
            document.getElementById('form_sk_isi_disposisi').value = myExistingData.isi_disposisi || '';
            document.getElementById('form_sk_harap').value         = myExistingData.harap || '';
            document.getElementById('form_sk_catatan').value       = myExistingData.catatan || '';
            document.getElementById('form_sk_pengesahan').value     = myExistingData.pengesahan || 'true';
        } else {
            document.getElementById('form_sk_isi_disposisi').value = '';
            document.getElementById('form_sk_harap').value         = '';
            document.getElementById('form_sk_catatan').value       = '';
            document.getElementById('form_sk_pengesahan').value     = 'true';
        }
        formContainer.style.display = 'block';
    } else {
        formContainer.style.display = 'none';
    }

    openModal('detailDisposisiSKModal');
}

function openEditSKModal(s) {
    document.getElementById('edit_no_urut').value = s.no_urut;
    document.getElementById('edit_kd_klasifikasi').value = s.kd_klasifikasi;
    document.getElementById('edit_no_surat').value = s.no_surat;
    document.getElementById('edit_tujuan').value = s.tujuan;
    document.getElementById('edit_tgl_surat').value = s.tgl_surat;
    document.getElementById('edit_tgl_kirim').value = s.tgl_kirim;
    document.getElementById('edit_perihal').value = s.perihal;
    document.getElementById('edit_keterangan').value = s.keterangan || '';
    
    openModal('editSuratKeluarModal');
}
</script>

<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.4.120/pdf.min.js"></script>
<script>
    pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.4.120/pdf.worker.min.js';

    function renderPdfPreview(inputElement, prefix) {
        const file = inputElement.files[0];
        const container = document.getElementById('preview_container_' + prefix);
        
        if (file && file.type === 'application/pdf') {
            container.style.display = 'block';
            document.getElementById('preview_help_' + prefix).style.display = 'block';
            
            const fileReader = new FileReader();
            fileReader.onload = function() {
                const typedarray = new Uint8Array(this.result);
                
                pdfjsLib.getDocument(typedarray).promise.then(pdf => {
                    // Render last page
                    pdf.getPage(pdf.numPages).then(page => {
                        const scale = 1.0;
                        const viewport = page.getViewport({scale: scale});
                        
                        const canvas = document.getElementById('pdf_canvas_' + prefix);
                        const context = canvas.getContext('2d');
                        
                        // We scale the canvas width to 100% of the container, but we need the aspect ratio
                        const containerWidth = container.clientWidth;
                        const scaleFactor = containerWidth / viewport.width;
                        const scaledViewport = page.getViewport({scale: scaleFactor});
                        
                        canvas.height = scaledViewport.height;
                        canvas.width = scaledViewport.width;
                        
                        const renderContext = {
                            canvasContext: context,
                            viewport: scaledViewport
                        };
                        page.render(renderContext).promise.then(() => {
                            setupDraggableQR(prefix);
                        });
                    });
                });
            };
            fileReader.readAsArrayBuffer(file);
        } else {
            container.style.display = 'none';
            document.getElementById('preview_help_' + prefix).style.display = 'none';
        }
    }
    
    function setupDraggableQR(prefix) {
        const box = document.getElementById('qr_box_' + prefix);
        const container = document.getElementById('preview_container_' + prefix);
        const inputX = document.getElementById('qr_x_' + prefix);
        const inputY = document.getElementById('qr_y_' + prefix);
        
        let isDragging = false;
        
        // Initial position (X: 70%, Y: 80%)
        const initX = (container.clientWidth * 0.70);
        const initY = (container.clientHeight * 0.80);
        box.style.left = initX + 'px';
        box.style.top = initY + 'px';
        
        box.addEventListener('mousedown', function(e) {
            isDragging = true;
            e.preventDefault();
        });
        
        document.addEventListener('mouseup', function(e) {
            if (isDragging) {
                isDragging = false;
                // Calculate percentage
                const xPct = (parseFloat(box.style.left) / container.clientWidth) * 100;
                const yPct = (parseFloat(box.style.top) / container.clientHeight) * 100;
                inputX.value = xPct.toFixed(2);
                inputY.value = yPct.toFixed(2);
            }
        });
        
        document.addEventListener('mousemove', function(e) {
            if (!isDragging) return;
            
            const rect = container.getBoundingClientRect();
            let x = e.clientX - rect.left - (box.offsetWidth / 2);
            let y = e.clientY - rect.top - (box.offsetHeight / 2);
            
            // Constrain
            if (x < 0) x = 0;
            if (y < 0) y = 0;
            if (x > container.clientWidth - box.offsetWidth) x = container.clientWidth - box.offsetWidth;
            if (y > container.clientHeight - box.offsetHeight) y = container.clientHeight - box.offsetHeight;
            
            box.style.left = x + 'px';
            box.style.top = y + 'px';
        });
    }
</script>
