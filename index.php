<?php
session_start();
define('host', true);
require_once 'koneksi.php';

// Redirect to login if not logged in
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit;
}

// Fetch general setting (instansi name & logo) from SIMKES Khanza database
$nama_instansi = "RSUD PRINGSEWU";
$logo_src = "";

$query_instansi = "SELECT nama_instansi, logo FROM setting LIMIT 1";
$result_instansi = mysqli_query($koneksi, $query_instansi);
if ($result_instansi && $row_instansi = mysqli_fetch_assoc($result_instansi)) {
    $nama_instansi = $row_instansi['nama_instansi'];
    if (!empty($row_instansi['logo'])) {
        $logo_blob = $row_instansi['logo'];
        $logo_base64 = base64_encode($logo_blob);
        $logo_src = "data:image/png;base64," . $logo_base64;
    }
}

// Fetch page permissions for the logged-in user from hak_akses
$user_permissions = [
    'dashboard' => '1',
    'manajemen' => '0',
    'dokter' => '0',
    'pegawai' => '0'
];

$nik_logged = $_SESSION['username'];
$stmt_akses = $koneksi->prepare("SELECT dashboard, manajemen, dokter, pegawai FROM hak_akses WHERE nik = ?");
if ($stmt_akses) {
    $stmt_akses->bind_param("s", $nik_logged);
    $stmt_akses->execute();
    $res_akses = $stmt_akses->get_result();
    if ($res_akses && $row_akses = $res_akses->fetch_assoc()) {
        $user_permissions = $row_akses;
    } else {
        // Automatically initialize default permissions in database if not exist
        $stmt_init = $koneksi->prepare("INSERT INTO hak_akses (nik, dashboard, manajemen, dokter, pegawai) VALUES (?, '1', '0', '0', '0')");
        if ($stmt_init) {
            $stmt_init->bind_param("s", $nik_logged);
            $stmt_init->execute();
            $stmt_init->close();
        }
    }
    $stmt_akses->close();
}

// Router Page Configuration
$page = isset($_GET['page']) ? $_GET['page'] : 'dashboard';
$sub = isset($_GET['sub']) ? $_GET['sub'] : '';
$allowed_pages = ['dashboard', 'manajemen', 'dokter', 'pegawai'];

if (!in_array($page, $allowed_pages)) {
    $page = 'dashboard';
}

$has_access = isset($user_permissions[$page]) && $user_permissions[$page] === '1';

// Intercept AJAX requests before HTML output
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($has_access) {
        if ($page === 'manajemen' && $sub === 'pegawai' && isset($_POST['ajax_action'])) {
            include 'pages/manajemen_pegawai.php';
            exit;
        }
        if ($page === 'pegawai' && $sub === 'absensi' && isset($_POST['absen_hari_ini'])) {
            include 'pages/pegawai_absensi.php';
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($nama_instansi) ?> - Alat Bantu</title>
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- Main Style Sheet -->
    <link rel="stylesheet" href="assets/css/style.css">
    
    <!-- Inline CSS for Mobile Menu Bottom Drawer -->
    <style>
        .drawer-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(4px);
            z-index: 2000;
            display: none;
            align-items: flex-end;
        }
        .drawer-overlay.active {
            display: flex;
        }
        .drawer-content {
            background: #fff;
            width: 100%;
            border-radius: 24px 24px 0 0;
            padding: 24px;
            box-sizing: border-box;
            transform: translateY(100%);
            transition: transform 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            max-height: 80vh;
            overflow-y: auto;
            box-shadow: 0 -10px 25px -5px rgba(0, 0, 0, 0.1), 0 -8px 10px -6px rgba(0, 0, 0, 0.1);
        }
        .drawer-overlay.active .drawer-content {
            transform: translateY(0);
        }
        .drawer-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            border-bottom: 1px solid #f1f5f9;
            padding-bottom: 12px;
        }
        .drawer-title {
            font-size: 18px;
            font-weight: 800;
            color: #0f172a;
        }
        .drawer-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            padding-bottom: 20px;
        }
        .drawer-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            padding: 14px 8px;
            border-radius: 16px;
            background: #f8fafc;
            color: #334155;
            text-decoration: none;
            font-size: 11px;
            font-weight: 600;
            transition: all 0.2s ease;
            border: 1px solid #f1f5f9;
        }
        .drawer-item:active {
            background: #e2e8f0;
            transform: scale(0.95);
        }
        .drawer-item svg {
            width: 24px;
            height: 24px;
            margin-bottom: 6px;
            color: #4f46e5;
        }

        /* Sidebar Accordion Submenus */
        .menu-group {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .menu-group-header {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            color: #94a3b8;
            cursor: default;
            font-weight: 600;
            font-size: 15px;
            user-select: none;
        }
        .menu-group-header svg {
            width: 20px;
            height: 20px;
            stroke-width: 2;
        }
        .menu-group-items {
            display: flex;
            flex-direction: column;
            gap: 4px;
            padding-left: 20px;
        }
        .menu-group-items a {
            padding: 8px 16px !important;
            font-size: 13.5px !important;
            color: #64748b !important;
            display: flex;
            align-items: center;
            text-decoration: none;
            border-radius: var(--radius-sm);
            transition: var(--transition);
        }
        .menu-group-items a:hover {
            color: #fff !important;
            background: rgba(255, 255, 255, 0.03) !important;
        }
        .menu-group-items a.active {
            color: #fff !important;
            background: rgba(99, 102, 241, 0.15) !important;
            border-left: 3px solid #6366f1;
            border-radius: 0 var(--radius-sm) var(--radius-sm) 0;
            font-weight: 600;
        }
        .menu-group.active .menu-group-header {
            color: #cbd5e1;
        }
    </style>
</head>
<body>

    <!-- Sidebar Left (Visible on Desktop Screen) -->
    <aside class="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-logo">
                <?php if (!empty($logo_src)): ?>
                    <img src="<?= $logo_src ?>" style="max-width: 100%; max-height: 100%; object-fit: contain;" alt="Logo">
                <?php else: ?>
                    A
                <?php endif; ?>
            </div>
            <div class="sidebar-title">
                <?= htmlspecialchars(strlen($nama_instansi) > 16 ? substr($nama_instansi, 0, 14) . '..' : $nama_instansi) ?>
            </div>
        </div>

        <nav class="sidebar-menu">
            <a href="index.php?page=dashboard" class="<?= $page === 'dashboard' ? 'active' : '' ?>">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>
                <span>Dashboard</span>
            </a>

            <?php if (isset($user_permissions['manajemen']) && $user_permissions['manajemen'] === '1'): ?>
            <div class="menu-group <?= $page === 'manajemen' ? 'active' : '' ?>">
                <div class="menu-group-header">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                    <span>Manajemen</span>
                </div>
                <div class="menu-group-items">
                    <a href="index.php?page=manajemen&sub=pegawai" class="<?= ($page === 'manajemen' && $sub === 'pegawai') ? 'active' : '' ?>">
                        <span>• Data Pegawai</span>
                    </a>
                    <a href="index.php?page=manajemen&sub=dokter" class="<?= ($page === 'manajemen' && $sub === 'dokter') ? 'active' : '' ?>">
                        <span>• Data Dokter</span>
                    </a>
                </div>
            </div>
            <?php endif; ?>

            <?php if (isset($user_permissions['dokter']) && $user_permissions['dokter'] === '1'): ?>
            <div class="menu-group <?= $page === 'dokter' ? 'active' : '' ?>">
                <div class="menu-group-header">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
                    <span>Dokter</span>
                </div>
                <div class="menu-group-items">
                    <a href="index.php?page=dokter&sub=visite" class="<?= ($page === 'dokter' && ($sub === 'visite' || empty($sub))) ? 'active' : '' ?>">
                        <span>• Visite Dokter</span>
                    </a>
                    <a href="index.php?page=dokter&sub=jadwal_operasi" class="<?= ($page === 'dokter' && $sub === 'jadwal_operasi') ? 'active' : '' ?>">
                        <span>• Jadwal Operasi</span>
                    </a>
                </div>
            </div>
            <?php endif; ?>

            <?php if (isset($user_permissions['pegawai']) && $user_permissions['pegawai'] === '1'): ?>
            <div class="menu-group <?= $page === 'pegawai' ? 'active' : '' ?>">
                <div class="menu-group-header">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                    <span>Pegawai</span>
                </div>
                <div class="menu-group-items">
                    <a href="index.php?page=pegawai&sub=cuti" class="<?= ($page === 'pegawai' && ($sub === 'cuti' || empty($sub))) ? 'active' : '' ?>">
                        <span>• Pengajuan Cuti</span>
                    </a>
                    <a href="index.php?page=pegawai&sub=absensi" class="<?= ($page === 'pegawai' && $sub === 'absensi') ? 'active' : '' ?>">
                        <span>• Absensi</span>
                    </a>
                </div>
            </div>
            <?php endif; ?>
        </nav>

        <div class="sidebar-footer">
            <div class="user-profile">
                <div class="user-avatar">
                    <?= strtoupper(substr($_SESSION['username'], 0, 2)) ?>
                </div>
                <div class="user-info">
                    <div class="user-name"><?= htmlspecialchars($_SESSION['nama_lengkap'] ?? $_SESSION['username']) ?></div>
                    <div class="user-role">Pegawai</div>
                </div>
            </div>
            <a href="logout.php" class="btn btn-danger btn-sm btn-logout" style="width: 100%; display: flex; justify-content: center;">
                Keluar
            </a>
        </div>
    </aside>

    <!-- Bottom Navigation Bar (Visible on Mobile Screens) -->
    <nav class="bottom-nav">
        <a href="index.php?page=dashboard" class="bottom-nav-item <?= $page === 'dashboard' ? 'active' : '' ?>">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>
            <span>Dashboard</span>
        </a>

        <!-- Menu Lainnya untuk Drawer di Mobile -->
        <a href="#" class="bottom-nav-item" onclick="toggleDrawerMenu(); return false;">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"></line><line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="18" x2="21" y2="18"></line></svg>
            <span>Lainnya</span>
        </a>
    </nav>

    <!-- Main Content Container -->
    <main class="main-layout">
        <?php 
        if (!$has_access) {
            echo "
            <div class='content-card' style='text-align: center; padding: 40px;'>
                <div style='color: #ef4444; font-size: 48px; margin-bottom: 16px;'>🚫</div>
                <h2 style='margin-bottom: 8px;'>Akses Ditolak</h2>
                <p class='text-secondary'>Anda tidak memiliki hak akses untuk membuka halaman ini.</p>
                <br>
                <a href='index.php?page=dashboard' class='btn btn-primary'>Kembali ke Dashboard</a>
            </div>";
        } else {
            include "pages/{$page}.php"; 
        }
        ?>
    </main>

    <!-- Drawer Overlay Menu Lainnya (Mobile Only) -->
    <div id="drawerMenu" class="drawer-overlay" onclick="closeDrawerMenu(event)">
        <div class="drawer-content" onclick="event.stopPropagation()">
            <div class="drawer-header">
                <span class="drawer-title">Semua Menu Utama</span>
                <button class="btn-close" onclick="closeDrawerMenu(event)" style="background:none; border:none; padding:4px; cursor:pointer;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="color:#64748b;"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                </button>
            </div>
            <div class="drawer-grid">
                <a href="index.php?page=dashboard" class="drawer-item" onclick="closeDrawerMenu(event)">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>
                    <span>Dashboard</span>
                </a>
                
                <?php if (isset($user_permissions['manajemen']) && $user_permissions['manajemen'] === '1'): ?>
                <a href="index.php?page=manajemen&sub=pegawai" class="drawer-item" onclick="closeDrawerMenu(event)">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                    <span>Data Pegawai</span>
                </a>
                <a href="index.php?page=manajemen&sub=dokter" class="drawer-item" onclick="closeDrawerMenu(event)">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-4l-3 9L9 3l-3 9H2"></path></svg>
                    <span>Data Dokter</span>
                </a>
                <?php endif; ?>
                
                <?php if (isset($user_permissions['dokter']) && $user_permissions['dokter'] === '1'): ?>
                <a href="index.php?page=dokter&sub=visite" class="drawer-item" onclick="closeDrawerMenu(event)">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"></path><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"></path></svg>
                    <span>Visite Dokter</span>
                </a>
                <a href="index.php?page=dokter&sub=jadwal_operasi" class="drawer-item" onclick="closeDrawerMenu(event)">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                    <span>Jadwal Operasi</span>
                </a>
                <?php endif; ?>
                
                <?php if (isset($user_permissions['pegawai']) && $user_permissions['pegawai'] === '1'): ?>
                <a href="index.php?page=pegawai&sub=cuti" class="drawer-item" onclick="closeDrawerMenu(event)">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                    <span>Pengajuan Cuti</span>
                </a>
                <a href="index.php?page=pegawai&sub=absensi" class="drawer-item" onclick="closeDrawerMenu(event)">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                    <span>Absensi</span>
                </a>
                <?php endif; ?>
                
                <a href="logout.php" class="drawer-item" style="color: #ef4444;">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
                    <span>Log Keluar</span>
                </a>
            </div>
        </div>
    </div>

    <!-- Common Javascript Scripts -->
    <script src="assets/js/app.js"></script>
    <script>
    function toggleDrawerMenu() {
        const drawer = document.getElementById('drawerMenu');
        drawer.classList.toggle('active');
    }
    function closeDrawerMenu(event) {
        const drawer = document.getElementById('drawerMenu');
        drawer.classList.remove('active');
    }
    </script>
</body>
</html>
