<?php
session_start();
require_once 'koneksi.php';

// Redirect if already logged in
if (isset($_SESSION['username'])) {
    header("Location: index.php");
    exit;
}

$error = '';

// Query untuk mengambil nama instansi dan logo dari database SIMKES Khanza
$query_instansi = "SELECT nama_instansi, logo FROM setting LIMIT 1";
$result_instansi = mysqli_query($koneksi, $query_instansi);
$nama_instansi = "RSUD PRINGSEWU"; // default jika tidak ada
$logo_src = ""; // default jika tidak ada

if ($result_instansi && $row_instansi = mysqli_fetch_assoc($result_instansi)) {
    $nama_instansi = $row_instansi['nama_instansi'];
    if (!empty($row_instansi['logo'])) {
        $logo_blob = $row_instansi['logo'];
        $logo_base64 = base64_encode($logo_blob);
        $logo_src = "data:image/png;base64," . $logo_base64;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!empty($username) && !empty($password)) {
        
        $login_ok    = false;
        $is_admin_login = false;

        // ── Jalur 1: Cek tabel 'user' SIMKES Khanza ──────────────────────────
        $stmt = $koneksi->prepare("SELECT id_user FROM user
                                    WHERE aes_decrypt(id_user, 'nur') = ?
                                    AND aes_decrypt(password, 'windi') = ?
                                    LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("ss", $username, $password);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result && $result->num_rows > 0) {
                $login_ok = true;
            }
            $stmt->close();
        }

        // ── Jalur 2: Cek tabel 'admin' (admin utama sistem) ──────────────────
        if (!$login_ok) {
            $stmt_adm = $koneksi->prepare("SELECT usere FROM admin
                                            WHERE aes_decrypt(usere, 'nur') = ?
                                            AND aes_decrypt(passworde, 'windi') = ?
                                            LIMIT 1");
            if ($stmt_adm) {
                $stmt_adm->bind_param("ss", $username, $password);
                $stmt_adm->execute();
                $res_adm = $stmt_adm->get_result();
                if ($res_adm && $res_adm->num_rows > 0) {
                    $login_ok       = true;
                    $is_admin_login = true;   // login langsung dari tabel admin
                }
                $stmt_adm->close();
            }
        }

        // ── Proses session jika login berhasil ────────────────────────────────
        if ($login_ok) {
            $_SESSION['username'] = $username;
            $_SESSION['status']   = "login";

            // Ambil nama lengkap dari tabel pegawai (mungkin tidak ada untuk admin murni)
            $stmtPeg = $koneksi->prepare("SELECT nama FROM pegawai WHERE nik = ? LIMIT 1");
            if ($stmtPeg) {
                $stmtPeg->bind_param("s", $username);
                $stmtPeg->execute();
                $resPeg = $stmtPeg->get_result();
                if ($resPeg && $rowPeg = $resPeg->fetch_assoc()) {
                    $_SESSION['nama_lengkap'] = $rowPeg['nama'];
                } else {
                    $_SESSION['nama_lengkap'] = strtoupper($username); // fallback: pakai username
                }
                $stmtPeg->close();
            } else {
                $_SESSION['nama_lengkap'] = strtoupper($username);
            }

            // Tentukan status admin:
            // → admin jika login dari jalur 2, ATAU jika NIK-nya ada di tabel admin
            if ($is_admin_login) {
                $_SESSION['is_admin'] = true;
            } else {
                $_SESSION['is_admin'] = false;
                $stmt_chk = $koneksi->prepare("SELECT usere FROM admin WHERE aes_decrypt(usere, 'nur') = ? LIMIT 1");
                if ($stmt_chk) {
                    $stmt_chk->bind_param("s", $username);
                    $stmt_chk->execute();
                    $res_chk = $stmt_chk->get_result();
                    if ($res_chk && $res_chk->num_rows > 0) {
                        $_SESSION['is_admin'] = true;
                    }
                    $stmt_chk->close();
                }
            }

            header("Location: index.php");
            exit;

        } else {
            $error = 'Username atau Password salah!';
        }

    } else {
        $error = 'Silakan isi semua bidang!';
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?= htmlspecialchars($nama_instansi) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%);
            --secondary-gradient: linear-gradient(135deg, #a855f7 0%, #9333ea 100%);
            --background: #0f172a;
            --card-bg: rgba(255, 255, 255, 0.08);
            --border-color: rgba(255, 255, 255, 0.1);
            --text-color: #f8fafc;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Outfit', sans-serif;
        }

        body {
            background: var(--background);
            color: var(--text-color);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            overflow: hidden;
            position: relative;
        }

        /* Abstract Background blobs */
        .blob {
            position: absolute;
            border-radius: 50%;
            filter: blur(100px);
            z-index: 1;
        }
        .blob-1 {
            width: 300px;
            height: 300px;
            background: #6366f1;
            top: -50px;
            left: -50px;
            opacity: 0.3;
        }
        .blob-2 {
            width: 400px;
            height: 400px;
            background: #a855f7;
            bottom: -100px;
            right: -100px;
            opacity: 0.25;
        }

        .login-container {
            width: 100%;
            max-width: 420px;
            z-index: 10;
        }

        .login-card {
            background: var(--card-bg);
            backdrop-filter: blur(20px);
            border: 1px solid var(--border-color);
            border-radius: 24px;
            padding: 40px 30px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.3);
            text-align: center;
        }

        .logo-container {
            margin-bottom: 20px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .logo-image {
            max-height: 90px;
            object-fit: contain;
        }

        .logo-fallback {
            width: 60px;
            height: 60px;
            background: var(--primary-gradient);
            border-radius: 16px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 32px;
            font-weight: 800;
            box-shadow: 0 10px 20px rgba(99, 102, 241, 0.4);
        }

        .title {
            font-size: 22px;
            font-weight: 800;
            margin-bottom: 6px;
            letter-spacing: -0.5px;
            line-height: 1.3;
        }

        .subtitle {
            font-size: 13px;
            color: #94a3b8;
            margin-bottom: 30px;
            line-height: 1.4;
        }

        .form-group {
            text-align: left;
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }

        .form-control {
            width: 100%;
            padding: 12px 16px;
            border-radius: 12px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            background: rgba(0, 0, 0, 0.2);
            color: white;
            font-size: 15px;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: #6366f1;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.25);
            background: rgba(0, 0, 0, 0.3);
        }

        .alert-error {
            background: rgba(239, 68, 68, 0.15);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #fca5a5;
            padding: 12px;
            border-radius: 12px;
            font-size: 14px;
            margin-bottom: 20px;
            font-weight: 500;
            text-align: left;
        }

        .btn-submit {
            width: 100%;
            padding: 14px;
            border-radius: 12px;
            border: none;
            background: var(--primary-gradient);
            color: white;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            box-shadow: 0 10px 20px rgba(79, 70, 229, 0.3);
            transition: all 0.3s ease;
            margin-top: 10px;
        }

        .btn-submit:hover {
            box-shadow: 0 12px 24px rgba(79, 70, 229, 0.4);
            transform: translateY(-1px);
        }

        .footer-note {
            margin-top: 24px;
            font-size: 12px;
            color: #64748b;
            line-height: 1.4;
        }
    </style>
</head>
<body>
    <div class="blob blob-1"></div>
    <div class="blob blob-2"></div>

    <div class="login-container">
        <div class="login-card">
            <div class="logo-container">
                <?php if (!empty($logo_src)): ?>
                    <img class="logo-image" src="<?= $logo_src ?>" alt="Logo Instansi">
                <?php else: ?>
                    <div class="logo-fallback">A</div>
                <?php endif; ?>
            </div>
            
            <h1 class="title"><?= htmlspecialchars($nama_instansi) ?></h1>
            <p class="subtitle">Aplikasi Alat Bantu Tambahan</p>

            <?php if (!empty($error)): ?>
                <div class="alert-error">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form action="" method="POST">
                <div class="form-group">
                    <label class="form-label" for="username">ID User / NIK</label>
                    <input type="text" id="username" name="username" class="form-control" placeholder="Masukkan ID User SIMKES" required autofocus autocomplete="off">
                </div>

                <div class="form-group">
                    <label class="form-label" for="password">Password</label>
                    <input type="password" id="password" name="password" class="form-control" placeholder="••••••••" required>
                </div>

                <button type="submit" class="btn-submit">Masuk Aplikasi</button>
            </form>

            <div class="footer-note">
                Silakan login menggunakan akun <b>SIMKES Khanza</b> Anda.
            </div>
        </div>
    </div>
</body>
</html>
