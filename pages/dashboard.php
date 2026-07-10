<?php
defined('host') or die('Akses langsung tidak diizinkan.');

// Get user permissions
$has_manajemen = isset($user_permissions['manajemen']) && $user_permissions['manajemen'] === '1';
$has_dokter = isset($user_permissions['dokter']) && $user_permissions['dokter'] === '1';
$has_pegawai = isset($user_permissions['pegawai']) && $user_permissions['pegawai'] === '1';
?>

<div class="content-header">
    <h1 class="page-title">Dashboard</h1>
    <p class="page-subtitle">Selamat datang kembali di Aplikasi Alat Bantu Tambahan</p>
</div>

<div class="content-grid" style="display: grid; grid-template-columns: 1fr; gap: 28px; margin-top: 24px;">
    <!-- Welcome Card Banner -->
    <div class="content-card" style="padding: 32px; background: linear-gradient(135deg, #4f46e5 0%, #312e81 100%); color: #ffffff; border: none; position: relative; overflow: hidden; border-radius: 20px; box-shadow: 0 10px 25px -5px rgba(79, 70, 229, 0.4);">
        <!-- Background decoration blobs -->
        <div style="position: absolute; width: 160px; height: 160px; background: rgba(255, 255, 255, 0.1); border-radius: 50%; top: -30px; right: -30px; filter: blur(20px);"></div>
        <div style="position: absolute; width: 120px; height: 120px; background: rgba(255, 255, 255, 0.08); border-radius: 50%; bottom: -30px; right: 80px; filter: blur(15px);"></div>
        
        <div style="position: relative; z-index: 2;">
            <div style="font-size: 36px; margin-bottom: 12px; animation: wave 1.5s infinite;">👋</div>
            <h2 style="font-size: 28px; font-weight: 800; margin-bottom: 8px; color: #ffffff; letter-spacing: -0.5px;">Halo, <?= htmlspecialchars($_SESSION['nama_lengkap'] ?? $_SESSION['username']) ?>!</h2>
            <p style="font-size: 15px; color: #e0e7ff; max-width: 650px; line-height: 1.6; font-weight: 400;">
                Selamat datang di portal kontrol aplikasi **Alat Bantu Tambahan SIMKES**. Gunakan panel menu di sebelah kiri (atau klik menu utama di bagian bawah pada layar ponsel Anda) untuk mengakses modul-modul fungsionalitas yang tersedia.
            </p>
            <div style="margin-top: 24px; display: inline-flex; align-items: center; gap: 10px; background: rgba(255, 255, 255, 0.15); padding: 8px 18px; border-radius: 50px; font-size: 13px; font-weight: 600; backdrop-filter: blur(4px);">
                <span style="display: inline-block; width: 8px; height: 8px; background: #10b981; border-radius: 50%; box-shadow: 0 0 8px #10b981;"></span>
                Sistem Terintegrasi Secara Real-Time dengan Database Simkes Khanza
            </div>
        </div>
    </div>

    <!-- Modules and Features Explanation -->
    <div>
        <h3 style="font-size: 18px; font-weight: 800; color: var(--text-primary); margin-bottom: 18px; letter-spacing: -0.3px;">Panduan Modul & Fitur Aplikasi Anda</h3>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 20px;">
            
            <!-- MANAJEMEN SECTION (Visible to Admin) -->
            <?php if ($has_manajemen): ?>
            <div class="content-card" style="border-top: 4px solid #4f46e5; display: flex; flex-direction: column; gap: 16px;">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <div style="background: rgba(79, 70, 229, 0.1); color: #4f46e5; width: 36px; height: 36px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 18px; font-weight: 700;">⚙️</div>
                    <h4 style="font-size: 16px; font-weight: 700; margin: 0; color: var(--text-primary);">Modul Administrasi & Manajemen</h4>
                </div>
                <hr style="border: 0; border-top: 1px solid var(--border-color); margin: 0;">
                <div style="display: flex; flex-direction: column; gap: 14px;">
                    <div>
                        <strong style="font-size: 13.5px; color: var(--text-primary); display: block; margin-bottom: 3px;">👥 Data Pegawai</strong>
                        <p style="font-size: 13px; color: var(--text-secondary); line-height: 1.5; margin: 0;">
                            Pengelolaan data induk pegawai. Dilengkapi dengan **pop-up dokumen** untuk mengatur Kontrak Kerja dan SIP (Surat Izin Praktik) Dokter, serta **lencana warna peringatan dini** (merah/kuning) otomatis di tabel bagi dokumen yang mendekati kedaluwarsa.
                        </p>
                    </div>
                    <div>
                        <strong style="font-size: 13.5px; color: var(--text-primary); display: block; margin-bottom: 3px;">🩺 Data Dokter</strong>
                        <p style="font-size: 13px; color: var(--text-secondary); line-height: 1.5; margin: 0;">
                            Portal basis data dokter umum dan spesialis, memetakan spesialisasi klinis, nomor izin praktek bawaan, instansi alumni, hingga status aktif dokter.
                        </p>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- DOKTER SECTION (Visible to Doctors/Admin) -->
            <?php if ($has_dokter): ?>
            <div class="content-card" style="border-top: 4px solid #06b6d4; display: flex; flex-direction: column; gap: 16px;">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <div style="background: rgba(6, 182, 212, 0.1); color: #06b6d4; width: 36px; height: 36px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 18px; font-weight: 700;">🏥</div>
                    <h4 style="font-size: 16px; font-weight: 700; margin: 0; color: var(--text-primary);">Modul Klinis Pelayanan Dokter</h4>
                </div>
                <hr style="border: 0; border-top: 1px solid var(--border-color); margin: 0;">
                <div style="display: flex; flex-direction: column; gap: 14px;">
                    <div>
                        <strong style="font-size: 13.5px; color: var(--text-primary); display: block; margin-bottom: 3px;">📝 Visite Dokter</strong>
                        <p style="font-size: 13px; color: var(--text-secondary); line-height: 1.5; margin: 0;">
                            Panel kontrol monitoring rawat inap pasien DPJP secara real-time. Menyajikan ringkasan pemeriksaan harian (Belum Diperiksa, Sudah Diperiksa, Pasien Baru), nama bangsal/kamar, serta riwayat penanggung jawab pasien.
                        </p>
                    </div>
                    <div>
                        <strong style="font-size: 13.5px; color: var(--text-primary); display: block; margin-bottom: 3px;">📅 Jadwal Operasi</strong>
                        <p style="font-size: 13px; color: var(--text-secondary); line-height: 1.5; margin: 0;">
                            Daftar pemantauan jadwal operasi (booking operasi). Menampilkan jadwal **hari ini** secara default untuk efisiensi jadwal dokter operator, dilengkapi penyaringan rentang tanggal, pencarian kata kunci, dan filter nama dokter.
                        </p>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- PEGAWAI SECTION (Visible to Employees/Admin) -->
            <?php if ($has_pegawai): ?>
            <div class="content-card" style="border-top: 4px solid #10b981; display: flex; flex-direction: column; gap: 16px;">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <div style="background: rgba(16, 185, 129, 0.1); color: #10b981; width: 36px; height: 36px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 18px; font-weight: 700;">👤</div>
                    <h4 style="font-size: 16px; font-weight: 700; margin: 0; color: var(--text-primary);">Modul Mandiri Kepegawaian</h4>
                </div>
                <hr style="border: 0; border-top: 1px solid var(--border-color); margin: 0;">
                <div style="display: flex; flex-direction: column; gap: 14px;">
                    <div>
                        <strong style="font-size: 13.5px; color: var(--text-primary); display: block; margin-bottom: 3px;">📬 Pengajuan Cuti</strong>
                        <p style="font-size: 13px; color: var(--text-secondary); line-height: 1.5; margin: 0;">
                            Formulir pengajuan cuti pegawai mandiri. Membantu pegawai untuk mengajukan permohonan istirahat/sakit secara digital yang langsung terintegrasi dengan data kehadiran utama.
                        </p>
                    </div>
                    <div>
                        <strong style="font-size: 13.5px; color: var(--text-primary); display: block; margin-bottom: 3px;">📸 Absensi Swafoto (Webcam)</strong>
                        <p style="font-size: 13px; color: var(--text-secondary); line-height: 1.5; margin: 0;">
                            Pencatatan kehadiran mandiri berbasis **kamera live selfie**. Dilengkapi dengan kompresi otomatis client-side (<50KB) untuk hemat penyimpanan server, deteksi keamanan HTTPS di ponsel, serta visualisasi foto kehadiran.
                        </p>
                    </div>
                </div>
            </div>
            <?php endif; ?>

        </div>
    </div>
</div>

<style>
@keyframes wave {
    0% { transform: rotate(0deg); }
    15% { transform: rotate(14deg); }
    30% { transform: rotate(-8deg); }
    40% { transform: rotate(14deg); }
    50% { transform: rotate(-4deg); }
    60% { transform: rotate(10deg); }
    70% { transform: rotate(0deg); }
    100% { transform: rotate(0deg); }
}
</style>
