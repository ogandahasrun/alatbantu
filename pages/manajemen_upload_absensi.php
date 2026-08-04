<?php
// Tangani Request AJAX
if (isset($_GET['ajax_action'])) {
    header('Content-Type: application/json');
    
    // 1. Inisialisasi Upload
    if ($_GET['ajax_action'] === 'upload_init') {
        if (isset($_FILES['file_absensi']) && $_FILES['file_absensi']['error'] == 0) {
            $filename = $_FILES['file_absensi']['name'];
            $tmpName = $_FILES['file_absensi']['tmp_name'];
            
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if ($ext !== 'csv') {
                echo json_encode(['status' => 'error', 'message' => 'Silakan upload file dengan ekstensi .csv']);
                exit;
            }
            
            // Folder temp
            $tempDir = __DIR__ . '/../../assets/temp/';
            if (!is_dir($tempDir)) {
                mkdir($tempDir, 0777, true);
            }
            
            $newFileName = 'absen_' . time() . '_' . rand(1000, 9999) . '.csv';
            $destPath = $tempDir . $newFileName;
            
            if (move_uploaded_file($tmpName, $destPath)) {
                $filesize = filesize($destPath);
                
                // Deteksi delimiter
                $handle = fopen($destPath, 'r');
                $firstLine = fgets($handle);
                $delimiter = (strpos($firstLine, ';') !== false) ? ';' : ',';
                fclose($handle);
                
                echo json_encode([
                    'status' => 'success', 
                    'file' => $newFileName, 
                    'total_bytes' => $filesize,
                    'delimiter' => $delimiter
                ]);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Gagal menyimpan file temporary.']);
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Gagal mengunggah file.']);
        }
        exit;
    }
    
    // 2. Pemrosesan Chunk
    if ($_GET['ajax_action'] === 'process_chunk') {
        $fileName = $_POST['file'] ?? '';
        $offset = (int)($_POST['offset'] ?? 0);
        $delimiter = $_POST['delimiter'] ?? ',';
        
        $tempDir = __DIR__ . '/../../assets/temp/';
        $filePath = $tempDir . $fileName;
        
        if (!file_exists($filePath)) {
            echo json_encode(['status' => 'error', 'message' => 'File tidak ditemukan atau sudah dihapus.']);
            exit;
        }
        
        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            echo json_encode(['status' => 'error', 'message' => 'Gagal membuka file sementara.']);
            exit;
        }
        
        fseek($handle, $offset);
        
        $sukses = 0;
        $diabaikan = 0;
        $processed_rows = 0;
        
        // Batasi maksimal eksekusi sekitar 2 detik atau 1000 query insert batch untuk menghindari error
        $max_time = microtime(true) + 1.5; 
        
        $batch_data = [];
        $is_eof = false;
        
        while (($data = fgetcsv($handle, 4000, $delimiter)) !== FALSE) {
            // Abaikan baris pertama jika header (berada di offset awal)
            if ($offset == 0 && $processed_rows == 0 && (!is_numeric($data[0]) || strtolower(trim($data[0])) == 'id')) {
                $processed_rows++;
                continue;
            }
            
            if (count($data) >= 2) {
                $id_pegawai = trim($data[0]);
                $tanggal = trim($data[1]);
                
                if (!empty($id_pegawai) && !empty($tanggal)) {
                    $batch_data[] = "('" . $koneksi->real_escape_string($id_pegawai) . "', '" . $koneksi->real_escape_string($tanggal) . "')";
                }
            }
            $processed_rows++;
            
            // Insert per batch 500 baris atau jika waktunya hampir habis
            if (count($batch_data) >= 500 || microtime(true) > $max_time) {
                break;
            }
        }
        
        if (count($batch_data) > 0) {
            $query = "INSERT IGNORE INTO detail_absensi (id, tanggal) VALUES " . implode(", ", $batch_data);
            if ($koneksi->query($query)) {
                $sukses += $koneksi->affected_rows;
                $diabaikan += (count($batch_data) - $koneksi->affected_rows);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $koneksi->error]);
                fclose($handle);
                exit;
            }
        }
        
        $new_offset = ftell($handle);
        
        // Periksa EOF
        if (feof($handle) || $data === FALSE) {
            $is_eof = true;
            fclose($handle);
            @unlink($filePath); // Hapus file jika sudah selesai
        } else {
            fclose($handle);
        }
        
        echo json_encode([
            'status' => 'success',
            'new_offset' => $new_offset,
            'sukses' => $sukses,
            'diabaikan' => $diabaikan,
            'is_eof' => $is_eof
        ]);
        exit;
    }
}
?>

<div class="content-header">
    <h2 class="content-title">Upload Detail Absensi</h2>
    <div class="content-actions">
        <a href="index.php?page=manajemen&sub=rekap_absensi" class="btn btn-secondary">Kembali</a>
    </div>
</div>

<div class="content-card">
    <div class="alert alert-info" style="background-color: #e0f2fe; color: #0284c7; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
        <strong>Informasi:</strong><br>
        Karena keterbatasan server (ketiadaan ekstensi ZIP untuk membaca format .xlsx), fitur ini dikonfigurasi untuk menerima file <b>.csv</b> (Comma Separated Values).<br>
        Jika Anda menggunakan Excel, pilih <i>Save As</i> lalu pilih format <b>CSV (Comma delimited)</b> atau <b>CSV (MS-DOS)</b>.<br><br>
        <b>Format File:</b>
        <ul style="margin-top: 5px; margin-bottom: 0;">
            <li>Kolom 1: ID Pegawai (Misal: 12345)</li>
            <li>Kolom 2: Waktu (Format: YYYY-MM-DD HH:MM:SS)</li>
        </ul>
        <i>*Baris pertama akan diabaikan jika merupakan header teks.</i>
    </div>

    <!-- Form Upload Asinkron -->
    <form id="uploadForm" onsubmit="startUpload(event)">
        <div class="form-group" style="margin-bottom: 20px;">
            <label for="file_absensi" style="display:block; margin-bottom: 8px; font-weight: 500;">Pilih File CSV</label>
            <input type="file" id="file_absensi" accept=".csv" required class="form-control" style="max-width: 400px; padding: 8px;">
        </div>
        <button type="submit" id="btnUpload" class="btn btn-primary" style="padding: 10px 20px;">Upload & Proses Data</button>
    </form>

    <!-- Progress UI -->
    <div id="progressContainer" style="display: none; margin-top: 25px; padding: 20px; border-radius: 8px; background: #f8fafc; border: 1px solid #e2e8f0;">
        <h4 style="margin-top:0; margin-bottom: 10px; font-size: 16px; color:#334155;">Status Proses: <span id="statusText">Mengunggah file...</span></h4>
        
        <div style="width: 100%; background-color: #e2e8f0; border-radius: 6px; overflow: hidden; height: 20px; margin-bottom: 15px;">
            <div id="progressBar" style="width: 0%; height: 100%; background-color: #3b82f6; transition: width 0.3s ease;"></div>
        </div>
        
        <div style="display: flex; justify-content: space-between; font-size: 14px; color: #64748b;">
            <span>Persentase: <strong id="progressPercent">0%</strong></span>
            <span>Berhasil: <strong id="countSukses" style="color: #16a34a;">0</strong> | Diabaikan (Duplikat): <strong id="countDiabaikan" style="color: #ea580c;">0</strong></span>
        </div>
    </div>
    
    <div id="errorMessage" style="display: none; color: #dc2626; margin-top: 20px; padding: 12px; background: #fee2e2; border-radius: 6px;"></div>
    <div id="successMessage" style="display: none; margin-top:20px; padding:15px; border-radius:6px; background-color: #dcfce7; color: #166534; border: 1px solid #bbf7d0;">
        <strong>Upload Selesai Sepenuhnya!</strong><br>
        Seluruh data berhasil diproses tanpa terkendala batas waktu eksekusi.
    </div>
</div>

<script>
let totalBytes = 0;
let tempFileName = '';
let csvDelimiter = ',';
let totalSukses = 0;
let totalDiabaikan = 0;

async function startUpload(e) {
    e.preventDefault();
    
    const fileInput = document.getElementById('file_absensi');
    if(fileInput.files.length === 0) return;
    
    const file = fileInput.files[0];
    
    // UI Reset
    document.getElementById('btnUpload').disabled = true;
    document.getElementById('progressContainer').style.display = 'block';
    document.getElementById('errorMessage').style.display = 'none';
    document.getElementById('successMessage').style.display = 'none';
    document.getElementById('progressBar').style.width = '0%';
    document.getElementById('progressPercent').innerText = '0%';
    document.getElementById('statusText').innerText = 'Mengunggah file ke server...';
    
    totalSukses = 0;
    totalDiabaikan = 0;
    updateCounts();
    
    const formData = new FormData();
    formData.append('file_absensi', file);
    
    try {
        const response = await fetch('index.php?page=manajemen&sub=upload_absensi&ajax_action=upload_init', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if(data.status === 'success') {
            tempFileName = data.file;
            totalBytes = data.total_bytes;
            csvDelimiter = data.delimiter;
            
            document.getElementById('statusText').innerText = 'Memproses data secara bertahap (chunking)...';
            // Mulai proses chunk dari byte 0
            processChunk(0);
        } else {
            showError(data.message || 'Terjadi kesalahan saat upload.');
        }
    } catch (err) {
        showError('Kesalahan jaringan: ' + err.message);
    }
}

async function processChunk(offset) {
    const formData = new FormData();
    formData.append('file', tempFileName);
    formData.append('offset', offset);
    formData.append('delimiter', csvDelimiter);
    
    try {
        const response = await fetch('index.php?page=manajemen&sub=upload_absensi&ajax_action=process_chunk', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.status === 'success') {
            totalSukses += data.sukses;
            totalDiabaikan += data.diabaikan;
            updateCounts();
            
            let percent = 100;
            if (totalBytes > 0) {
                percent = Math.floor((data.new_offset / totalBytes) * 100);
                if (percent > 100) percent = 100;
            }
            
            document.getElementById('progressBar').style.width = percent + '%';
            document.getElementById('progressPercent').innerText = percent + '%';
            
            if (data.is_eof) {
                document.getElementById('statusText').innerText = 'Selesai!';
                document.getElementById('progressBar').style.width = '100%';
                document.getElementById('progressPercent').innerText = '100%';
                document.getElementById('btnUpload').disabled = false;
                document.getElementById('successMessage').style.display = 'block';
                document.getElementById('file_absensi').value = '';
            } else {
                // Lanjutkan proses ke offset berikutnya
                processChunk(data.new_offset);
            }
        } else {
            showError(data.message || 'Terjadi kesalahan pemrosesan.');
        }
    } catch (err) {
        showError('Kesalahan jaringan saat memproses: ' + err.message);
    }
}

function updateCounts() {
    document.getElementById('countSukses').innerText = totalSukses;
    document.getElementById('countDiabaikan').innerText = totalDiabaikan;
}

function showError(msg) {
    document.getElementById('errorMessage').innerText = msg;
    document.getElementById('errorMessage').style.display = 'block';
    document.getElementById('btnUpload').disabled = false;
    document.getElementById('statusText').innerText = 'Berhenti karena error.';
}
</script>
