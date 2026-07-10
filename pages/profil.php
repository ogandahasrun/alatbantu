<?php
defined('host') or die('Akses langsung tidak diizinkan.');

$nik = $_SESSION['username'];
$success_msg = '';
$error_msg = '';

// Handle POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. AJAX Save Face Vector
    if (isset($_POST['action']) && $_POST['action'] === 'save_face_vector') {
        ob_clean();
        header('Content-Type: application/json');
        
        $vector_json = $_POST['vector'] ?? '';
        if (empty($vector_json)) {
            echo json_encode(['success' => false, 'message' => 'Data wajah (vektor) kosong!']);
            exit;
        }

        // Simpan atau timpa data wajah (ON DUPLICATE KEY UPDATE)
        $stmt_face = $koneksi->prepare("INSERT INTO face_vector (nik, vector) VALUES (?, ?) ON DUPLICATE KEY UPDATE vector = VALUES(vector)");
        if ($stmt_face) {
            $stmt_face->bind_param("ss", $nik, $vector_json);
            if ($stmt_face->execute()) {
                echo json_encode(['success' => true, 'message' => 'Data wajah berhasil direkam!']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Gagal menyimpan wajah: ' . $koneksi->error]);
            }
            $stmt_face->close();
        } else {
            echo json_encode(['success' => false, 'message' => 'Gagal menyiapkan query wajah.']);
        }
        exit;
    }

    // 2. Form Ubah Password
    if (isset($_POST['action']) && $_POST['action'] === 'change_password') {
        $password_lama = $_POST['password_lama'] ?? '';
        $password_baru = $_POST['password_baru'] ?? '';
        $password_konfirmasi = $_POST['password_konfirmasi'] ?? '';

        if (empty($password_lama) || empty($password_baru) || empty($password_konfirmasi)) {
            $error_msg = 'Semua field password wajib diisi!';
        } elseif ($password_baru !== $password_konfirmasi) {
            $error_msg = 'Konfirmasi password baru tidak cocok!';
        } else {
            // Verifikasi password lama
            // Note: Admin utama (spv) ada di tabel 'admin', user biasa di tabel 'user'
            $is_admin = isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true;
            $table = $is_admin ? 'admin' : 'user';
            $user_col = $is_admin ? 'usere' : 'id_user';
            $pass_col = $is_admin ? 'passworde' : 'password';

            $stmt_check = $koneksi->prepare("SELECT aes_decrypt($pass_col, 'windi') as pwd FROM $table WHERE aes_decrypt($user_col, 'nur') = ? LIMIT 1");
            if ($stmt_check) {
                $stmt_check->bind_param("s", $nik);
                $stmt_check->execute();
                $res_check = $stmt_check->get_result();
                if ($row = $res_check->fetch_assoc()) {
                    if ($row['pwd'] === $password_lama) {
                        // Password lama benar, lakukan update
                        $stmt_up = $koneksi->prepare("UPDATE $table SET $pass_col = aes_encrypt(?, 'windi') WHERE aes_decrypt($user_col, 'nur') = ?");
                        if ($stmt_up) {
                            $stmt_up->bind_param("ss", $password_baru, $nik);
                            if ($stmt_up->execute()) {
                                $success_msg = 'Password Anda berhasil diperbarui!';
                            } else {
                                $error_msg = 'Gagal memperbarui password di database: ' . $koneksi->error;
                            }
                            $stmt_up->close();
                        }
                    } else {
                        $error_msg = 'Password lama Anda salah!';
                    }
                } else {
                    $error_msg = 'Akun tidak ditemukan di database!';
                }
                $stmt_check->close();
            }
        }
    }
}

// Cek apakah data wajah sudah ada
$face_registered = false;
$face_date = '';
$stmt_check_face = $koneksi->prepare("SELECT created_at FROM face_vector WHERE nik = ? LIMIT 1");
if ($stmt_check_face) {
    $stmt_check_face->bind_param("s", $nik);
    $stmt_check_face->execute();
    $res_face = $stmt_check_face->get_result();
    if ($row_face = $res_face->fetch_assoc()) {
        $face_registered = true;
        $face_date = date('d F Y H:i', strtotime($row_face['created_at']));
    }
    $stmt_check_face->close();
}
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Profil Saya</h1>
        <p class="text-secondary" style="font-size: 14px;">Kelola password akun Anda dan lakukan perekaman verifikasi wajah absensi.</p>
    </div>
</div>

<?php if (!empty($success_msg)): ?>
    <div class="content-card" style="border-left: 5px solid var(--success); background: rgba(16, 185, 129, 0.08); padding: 16px; margin-bottom: 20px; display: flex; align-items: center; gap: 12px; border-radius: var(--radius-md);">
        <span style="font-size: 20px;">✅</span>
        <span style="font-size: 14px; font-weight: 600; color: #065f46;"><?= htmlspecialchars($success_msg) ?></span>
    </div>
<?php endif; ?>

<?php if (!empty($error_msg)): ?>
    <div class="content-card" style="border-left: 5px solid var(--danger); background: rgba(239, 68, 68, 0.08); padding: 16px; margin-bottom: 20px; display: flex; align-items: center; gap: 12px; border-radius: var(--radius-md);">
        <span style="font-size: 20px;">⚠️</span>
        <span style="font-size: 14px; font-weight: 600; color: #991b1b;"><?= htmlspecialchars($error_msg) ?></span>
    </div>
<?php endif; ?>

<div style="display: grid; grid-template-columns: 1.2fr 1fr; gap: 24px; align-items: start;">

    <!-- Bagian 1: Perekaman Wajah (Face Recognition) -->
    <div class="content-card">
        <h2 style="font-size: 18px; font-weight: 700; margin-bottom: 16px; display: flex; align-items: center; gap: 8px; color: var(--text-primary);">
            👤 Verifikasi Wajah (Absensi)
        </h2>

        <div style="margin-bottom: 20px; display: flex; align-items: center; gap: 12px; padding: 12px; background: var(--bg-secondary); border-radius: var(--radius-md);">
            <div style="font-size: 28px;">
                <?= $face_registered ? '🔒' : '🔓' ?>
            </div>
            <div>
                <div style="font-size: 14px; font-weight: 700; color: var(--text-primary);">
                    Status Pendaftaran Wajah
                </div>
                <div style="font-size: 13px; color: var(--text-secondary);">
                    <?= $face_registered ? 'Terdaftar sejak <span style="color: var(--success); font-weight: 600;">' . $face_date . '</span>' : 'Belum terdaftar. Silakan rekam wajah Anda di bawah.' ?>
                </div>
            </div>
        </div>

        <!-- Camera Area -->
        <div style="position: relative; width: 100%; max-width: 400px; margin: 0 auto 20px; aspect-ratio: 4/3; background: #000; border-radius: var(--radius-md); overflow: hidden; border: 2px solid var(--border-color);">
            <video id="videoElement" autoplay muted playsinline style="width: 100%; height: 100%; object-fit: cover; transform: scaleX(-1);"></video>
            <canvas id="overlayCanvas" style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; pointer-events: none; transform: scaleX(-1);"></canvas>
            
            <div id="cameraPlaceholder" style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; display: flex; flex-direction: column; align-items: center; justify-content: center; background: rgba(15, 23, 42, 0.9); text-align: center; padding: 20px;">
                <span style="font-size: 40px; margin-bottom: 12px;">📷</span>
                <span style="font-size: 14px; font-weight: 600; color: var(--text-secondary); margin-bottom: 16px;">Kamera belum aktif</span>
                <button type="button" class="btn btn-primary btn-sm" onclick="startCamera()">Aktifkan Kamera</button>
            </div>

            <!-- Loading overlay during ML processing -->
            <div id="loadingOverlay" style="display: none; position: absolute; top: 0; left: 0; width: 100%; height: 100%; align-items: center; justify-content: center; background: rgba(15, 23, 42, 0.7); text-align: center; color: white;">
                <div>
                    <div style="width: 40px; height: 40px; border: 4px solid rgba(255,255,255,0.3); border-top-color: var(--accent); border-radius: 50%; animation: spin 1s infinite linear; margin: 0 auto 12px;"></div>
                    <div style="font-size: 14px; font-weight: 600;" id="loadingText">Memuat Model AI...</div>
                </div>
            </div>
        </div>

        <div style="text-align: center;">
            <button type="button" id="btnRecord" class="btn btn-primary" style="display: none;" onclick="captureAndSaveFace()">
                🎯 Rekam & Simpan Wajah
            </button>
            <p class="text-secondary" style="font-size: 12px; margin-top: 10px; line-height: 1.4;">
                Posisikan wajah Anda tepat di tengah kamera dengan pencahayaan yang cukup sebelum merekam.
            </p>
        </div>
    </div>

    <!-- Bagian 2: Ubah Password -->
    <div class="content-card">
        <h2 style="font-size: 18px; font-weight: 700; margin-bottom: 16px; display: flex; align-items: center; gap: 8px; color: var(--text-primary);">
            🔑 Ubah Password Akun
        </h2>

        <form method="POST">
            <input type="hidden" name="action" value="change_password">
            
            <div class="form-group">
                <label class="form-label" for="password_lama">Password Lama</label>
                <input type="password" id="password_lama" name="password_lama" class="form-control" placeholder="Masukkan password saat ini" required>
            </div>

            <div class="form-group">
                <label class="form-label" for="password_baru">Password Baru</label>
                <input type="password" id="password_baru" name="password_baru" class="form-control" placeholder="Minimal 6 karakter" required>
            </div>

            <div class="form-group">
                <label class="form-label" for="password_konfirmasi">Konfirmasi Password Baru</label>
                <input type="password" id="password_konfirmasi" name="password_konfirmasi" class="form-control" placeholder="Ketik ulang password baru" required>
            </div>

            <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center; height: 42px; margin-top: 10px;">
                Simpan Password Baru
            </button>
        </form>
    </div>

</div>

<!-- CSS Animation spinner -->
<style>
@keyframes spin {
    to { transform: rotate(360deg); }
}
</style>

<!-- Load Face-API.js -->
<script src="assets/js/face-api.min.js"></script>
<script>
    let localStream = null;
    let modelsLoaded = false;

    // Load AI Models
    async function loadModels() {
        const loadingOverlay = document.getElementById('loadingOverlay');
        const loadingText = document.getElementById('loadingText');
        
        if (modelsLoaded) return true;
        
        loadingOverlay.style.display = 'flex';
        loadingText.innerText = "Membuat Model AI...";
        
        try {
            // Path models relative to index.php
            await faceapi.nets.ssdMobilenetv1.loadFromUri('assets/models');
            await faceapi.nets.faceLandmark68Net.loadFromUri('assets/models');
            await faceapi.nets.faceRecognitionNet.loadFromUri('assets/models');
            
            modelsLoaded = true;
            loadingOverlay.style.display = 'none';
            return true;
        } catch (e) {
            console.error(e);
            alert("Gagal memuat model AI wajah. Pastikan folder assets/models terisi lengkap.");
            loadingOverlay.style.display = 'none';
            return false;
        }
    }

    // Start Camera Stream
    async function startCamera() {
        const video = document.getElementById('videoElement');
        const placeholder = document.getElementById('cameraPlaceholder');
        const btnRecord = document.getElementById('btnRecord');

        // Load models first
        const ok = await loadModels();
        if (!ok) return;

        try {
            localStream = await navigator.mediaDevices.getUserMedia({
                video: { width: 640, height: 480, facingMode: 'user' }
            });
            video.srcObject = localStream;
            placeholder.style.display = 'none';
            btnRecord.style.display = 'inline-flex';

            // Real-time canvas drawing overlay for guide
            video.addEventListener('play', () => {
                const canvas = document.getElementById('overlayCanvas');
                const displaySize = { width: video.clientWidth, height: video.clientHeight };
                faceapi.matchDimensions(canvas, displaySize);

                setInterval(async () => {
                    if (video.paused || video.ended) return;
                    const detections = await faceapi.detectSingleFace(video).withFaceLandmarks();
                    const context = canvas.getContext('2d');
                    context.clearRect(0, 0, canvas.width, canvas.height);
                    
                    if (detections) {
                        const resizedDetections = faceapi.resizeResults(detections, displaySize);
                        // Draw box guide nicely
                        const box = resizedDetections.detection.box;
                        context.strokeStyle = '#3b82f6';
                        context.lineWidth = 3;
                        context.strokeRect(box.x, box.y, box.width, box.height);
                    }
                }, 200);
            });

        } catch (err) {
            console.error(err);
            alert("Gagal membuka kamera: " + err.message + "\nPastikan Anda mengizinkan akses kamera dan koneksi HTTPS.");
        }
    }

    // Capture & Extract descriptor
    async function captureAndSaveFace() {
        const video = document.getElementById('videoElement');
        const loadingOverlay = document.getElementById('loadingOverlay');
        const loadingText = document.getElementById('loadingText');

        if (!localStream) {
            alert("Kamera belum aktif!");
            return;
        }

        loadingOverlay.style.display = 'flex';
        loadingText.innerText = "Mendeteksi & Merekam Wajah...";

        // Wait a tiny bit to make sure scan is stable
        setTimeout(async () => {
            try {
                const detection = await faceapi.detectSingleFace(video)
                    .withFaceLandmarks()
                    .withFaceDescriptor();

                if (!detection) {
                    alert("Wajah tidak terdeteksi! Coba posisikan wajah Anda tepat di tengah dan tidak terlalu jauh.");
                    loadingOverlay.style.display = 'none';
                    return;
                }

                // Get descriptor array (128 floats)
                const descriptorArray = Array.from(detection.descriptor);
                const vectorJson = JSON.stringify(descriptorArray);

                // Send via AJAX to save_face_vector
                const formData = new FormData();
                formData.append('action', 'save_face_vector');
                formData.append('vector', vectorJson);

                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                if (result.success) {
                    alert("Berhasil! Wajah Anda telah terdaftar.");
                    window.location.reload();
                } else {
                    alert("Gagal: " + result.message);
                }

            } catch (err) {
                console.error(err);
                alert("Kesalahan pemrosesan wajah: " + err.message);
            } finally {
                loadingOverlay.style.display = 'none';
            }
        }, 500);
    }
</script>
