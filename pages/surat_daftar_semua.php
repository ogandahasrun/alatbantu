<?php
if (!defined('host')) {
    exit('No direct script access allowed');
}

$nik_user = $_SESSION['username'] ?? '';
$is_admin = isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true;

// ── 1. FILTER PARAMS ───────────────────────────────────────────────────────
$default_tgl_mulai = date('Y-m-d', strtotime('-1 month'));
$default_tgl_akhir = date('Y-m-d');

$tgl_mulai   = $_GET['tgl_mulai'] ?? $default_tgl_mulai;
$tgl_selesai = $_GET['tgl_selesai'] ?? $default_tgl_akhir;
$jenis_surat = $_GET['jenis_surat'] ?? 'semua';
$search      = trim($_GET['search'] ?? '');

$tgl_m_esc   = $koneksi->real_escape_string($tgl_mulai);
$tgl_s_esc   = $koneksi->real_escape_string($tgl_selesai);
$search_esc  = $koneksi->real_escape_string($search);

// ── 2. QUERY SURAT MASUK & KELUAR ─────────────────────────────────────────
$queries = [];

if ($jenis_surat === 'semua' || $jenis_surat === 'surat_masuk') {
    $sql_sm = "SELECT 
                'surat_masuk' AS jenis_surat,
                s.no_urut,
                s.no_surat,
                s.tgl_surat,
                s.perihal,
                s.asal,
                s.tujuan,
                s.file_url,
                s.keterangan,
                s.tgl_terima AS tgl_transaksi
              FROM surat_masuk s
              WHERE s.tgl_surat >= '$tgl_m_esc' AND s.tgl_surat <= '$tgl_s_esc'";
    if (!empty($search)) {
        $sql_sm .= " AND (s.no_surat LIKE '%$search_esc%' OR s.perihal LIKE '%$search_esc%' OR s.no_urut LIKE '%$search_esc%' OR s.asal LIKE '%$search_esc%' OR s.tujuan LIKE '%$search_esc%')";
    }
    $queries[] = $sql_sm;
}

if ($jenis_surat === 'semua' || $jenis_surat === 'surat_keluar') {
    $sql_sk = "SELECT 
                'surat_keluar' AS jenis_surat,
                s.no_urut,
                s.no_surat,
                s.tgl_surat,
                s.perihal,
                '-' AS asal,
                s.tujuan,
                s.file_url,
                s.keterangan,
                s.tgl_kirim AS tgl_transaksi
              FROM surat_keluar s
              WHERE s.tgl_surat >= '$tgl_m_esc' AND s.tgl_surat <= '$tgl_s_esc'";
    if (!empty($search)) {
        $sql_sk .= " AND (s.no_surat LIKE '%$search_esc%' OR s.perihal LIKE '%$search_esc%' OR s.no_urut LIKE '%$search_esc%' OR s.tujuan LIKE '%$search_esc%')";
    }
    $queries[] = $sql_sk;
}

$final_sql = implode(" UNION ALL ", $queries) . " ORDER BY tgl_surat DESC, no_urut DESC";
$res_all   = $koneksi->query($final_sql);

$list_surat = [];

if ($res_all) {
    while ($row = $res_all->fetch_assoc()) {
        $no_u  = $row['no_urut'];
        $j_type = $row['jenis_surat'];
        
        $table_disp = ($j_type === 'surat_masuk') ? 'surat_masuk_disposisi_level' : 'surat_keluar_disposisi_level';
        
        // Ambil level disposisi & pegawai
        $res_lvl = $koneksi->query("SELECT d.*, p.nama as nama_pegawai, p.jbtn 
                                    FROM $table_disp d 
                                    LEFT JOIN pegawai p ON p.nik = d.nik 
                                    WHERE d.no_urut = '$no_u' 
                                    ORDER BY d.level ASC");
        
        $levels_data = [];
        $creator_nik = '';
        $is_approver_or_creator = false;
        
        if ($res_lvl) {
            while ($r_l = $res_lvl->fetch_assoc()) {
                $levels_data[$r_l['level']] = $r_l;
                if (!empty($r_l['user_input'])) {
                    $creator_nik = $r_l['user_input'];
                }
                if ($r_l['nik'] === $nik_user || $r_l['user_input'] === $nik_user) {
                    $is_approver_or_creator = true;
                }
            }
        }

        $row['levels']      = $levels_data;
        $row['creator_nik'] = $creator_nik;
        $row['can_open']    = $is_admin || $is_approver_or_creator;
        
        $list_surat[] = $row;
    }
}
?>

<div class="content-card">
    <div style="display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 16px; margin-bottom: 24px;">
        <div>
            <h1 style="font-size: 22px; font-weight: 700; color: #1e293b; margin: 0 0 4px 0;">📋 Daftar Semua Surat</h1>
            <p style="font-size: 14px; color: #64748b; margin: 0;">Seluruh dokumen Surat Masuk dan Surat Keluar terintegrasi dalam 1 tabel.</p>
        </div>
        <div style="display: flex; gap: 8px;">
            <a href="index.php?page=surat_menyurat&sub=surat_masuk" class="btn btn-secondary" style="font-size: 13px; display: inline-flex; align-items: center; gap: 6px;">
                📥 Ke Surat Masuk
            </a>
            <a href="index.php?page=surat_menyurat&sub=surat_keluar" class="btn btn-secondary" style="font-size: 13px; display: inline-flex; align-items: center; gap: 6px;">
                📤 Ke Surat Keluar
            </a>
        </div>
    </div>

    <!-- FILTER SECTION -->
    <form method="GET" action="index.php" style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 18px; margin-bottom: 24px;">
        <input type="hidden" name="page" value="surat_menyurat">
        <input type="hidden" name="sub" value="semua">

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px; align-items: end;">
            <div>
                <label style="display: block; font-size: 13px; font-weight: 600; color: #475569; margin-bottom: 6px;">Tanggal Surat (Mulai)</label>
                <input type="date" name="tgl_mulai" value="<?= htmlspecialchars($tgl_mulai) ?>" class="form-control" style="font-size: 13px;">
            </div>
            <div>
                <label style="display: block; font-size: 13px; font-weight: 600; color: #475569; margin-bottom: 6px;">Tanggal Surat (Selesai)</label>
                <input type="date" name="tgl_selesai" value="<?= htmlspecialchars($tgl_selesai) ?>" class="form-control" style="font-size: 13px;">
            </div>
            <div>
                <label style="display: block; font-size: 13px; font-weight: 600; color: #475569; margin-bottom: 6px;">Jenis Surat</label>
                <select name="jenis_surat" class="form-control" style="font-size: 13px;">
                    <option value="semua" <?= $jenis_surat === 'semua' ? 'selected' : '' ?>>Semua Jenis (Masuk & Keluar)</option>
                    <option value="surat_masuk" <?= $jenis_surat === 'surat_masuk' ? 'selected' : '' ?>>📥 Surat Masuk</option>
                    <option value="surat_keluar" <?= $jenis_surat === 'surat_keluar' ? 'selected' : '' ?>>📤 Surat Keluar</option>
                </select>
            </div>
            <div>
                <label style="display: block; font-size: 13px; font-weight: 600; color: #475569; margin-bottom: 6px;">Cari Surat</label>
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Nomor surat, perihal, asal, tujuan..." class="form-control" style="font-size: 13px;">
            </div>
            <div style="display: flex; gap: 8px;">
                <button type="submit" class="btn btn-primary" style="flex: 1; font-size: 13px; height: 38px; display: inline-flex; align-items: center; justify-content: center; gap: 6px;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                    Filter
                </button>
                <a href="index.php?page=surat_menyurat&sub=semua" class="btn btn-secondary" style="font-size: 13px; height: 38px; display: inline-flex; align-items: center; justify-content: center; background: #e2e8f0; color: #475569;">
                    Reset
                </a>
            </div>
        </div>
    </form>

    <!-- LEGEND DISPOSISI STATUS -->
    <div style="display: flex; flex-wrap: wrap; align-items: center; gap: 16px; margin-bottom: 16px; font-size: 12px; color: #64748b; padding: 0 4px;">
        <span style="font-weight: 600; color: #334155;">Status Disposisi Level (1, 2, 3):</span>
        <div style="display: flex; align-items: center; gap: 6px;">
            <span style="background: #fef3c7; border: 1.5px solid #f59e0b; color: #b45309; font-weight: 700; border-radius: 6px; width: 22px; height: 22px; display: inline-flex; align-items: center; justify-content: center; font-size: 11px;">1</span>
            <span>Belum Disposisi (Menunggu)</span>
        </div>
        <div style="display: flex; align-items: center; gap: 6px;">
            <span style="background: #d1fae5; border: 1.5px solid #10b981; color: #047857; font-weight: 700; border-radius: 6px; width: 22px; height: 22px; display: inline-flex; align-items: center; justify-content: center; font-size: 11px;">2</span>
            <span>Sudah Disposisi</span>
        </div>
        <div style="display: flex; align-items: center; gap: 6px;">
            <span style="background: #fee2e2; border: 1.5px solid #ef4444; color: #b91c1c; font-weight: 700; border-radius: 6px; width: 22px; height: 22px; display: inline-flex; align-items: center; justify-content: center; font-size: 11px;">3</span>
            <span>Ditolak</span>
        </div>
    </div>

    <!-- DATA TABLE -->
    <div style="overflow-x: auto; border: 1px solid #e2e8f0; border-radius: 12px;">
        <table class="table" style="width: 100%; border-collapse: collapse; font-size: 13px;">
            <thead>
                <tr style="background: #f1f5f9; color: #334155; text-align: left;">
                    <th style="padding: 12px 14px; width: 50px; text-align: center; border-bottom: 2px solid #cbd5e1;">No</th>
                    <th style="padding: 12px 14px; width: 130px; border-bottom: 2px solid #cbd5e1;">Jenis Surat</th>
                    <th style="padding: 12px 14px; width: 170px; border-bottom: 2px solid #cbd5e1;">Nomor Surat</th>
                    <th style="padding: 12px 14px; width: 120px; border-bottom: 2px solid #cbd5e1;">Tanggal Surat</th>
                    <th style="padding: 12px 14px; border-bottom: 2px solid #cbd5e1;">Perihal</th>
                    <th style="padding: 12px 14px; width: 130px; text-align: center; border-bottom: 2px solid #cbd5e1;">Disposisi Level</th>
                    <th style="padding: 12px 14px; width: 140px; text-align: center; border-bottom: 2px solid #cbd5e1;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($list_surat)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 40px; color: #94a3b8;">
                            <div style="font-size: 36px; margin-bottom: 8px;">📭</div>
                            Tidak ada data surat yang ditemukan pada periode dan filter ini.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php $no = 1; foreach ($list_surat as $surat): ?>
                        <tr style="border-bottom: 1px solid #f1f5f9; transition: background 0.15s;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='transparent'">
                            <td style="padding: 12px 14px; text-align: center; font-weight: 600; color: #64748b;"><?= $no++ ?></td>
                            <td style="padding: 12px 14px;">
                                <?php if ($surat['jenis_surat'] === 'surat_masuk'): ?>
                                    <span style="background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; font-weight: 700; font-size: 11px; padding: 4px 8px; border-radius: 20px; display: inline-flex; align-items: center; gap: 4px;">
                                        📥 Surat Masuk
                                    </span>
                                <?php else: ?>
                                    <span style="background: #f3e8ff; color: #6b21a8; border: 1px solid #e9d5ff; font-weight: 700; font-size: 11px; padding: 4px 8px; border-radius: 20px; display: inline-flex; align-items: center; gap: 4px;">
                                        📤 Surat Keluar
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 12px 14px;">
                                <div style="font-weight: 700; color: #1e293b; margin-bottom: 2px;"><?= htmlspecialchars($surat['no_surat']) ?></div>
                                <div style="font-size: 11px; color: #94a3b8; font-family: monospace;">ID: <?= htmlspecialchars($surat['no_urut']) ?></div>
                            </td>
                            <td style="padding: 12px 14px; color: #475569; white-space: nowrap;">
                                <?= date('d M Y', strtotime($surat['tgl_surat'])) ?>
                            </td>
                            <td style="padding: 12px 14px;">
                                <div style="font-weight: 600; color: #334155; margin-bottom: 2px;"><?= htmlspecialchars($surat['perihal']) ?></div>
                                <div style="font-size: 11px; color: #64748b;">
                                    <?php if ($surat['jenis_surat'] === 'surat_masuk'): ?>
                                        <span>Asal: <strong><?= htmlspecialchars($surat['asal']) ?></strong></span> &bull; 
                                        <span>Tujuan: <strong><?= htmlspecialchars($surat['tujuan']) ?></strong></span>
                                    <?php else: ?>
                                        <span>Tujuan: <strong><?= htmlspecialchars($surat['tujuan']) ?></strong></span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td style="padding: 12px 14px; text-align: center;">
                                <div style="display: flex; justify-content: center; align-items: center; gap: 6px;">
                                    <?php for ($lvl = 1; $lvl <= 3; $lvl++): ?>
                                        <?php
                                        $ldat = $surat['levels'][$lvl] ?? null;
                                        $status_txt = $ldat['status_disposisi'] ?? 'Menunggu';
                                        $pengesahan = $ldat['pengesahan'] ?? 'false';
                                        $nama_app   = $ldat['nama_pegawai'] ?? 'Belum ditentukan';
                                        $jbtn_label = $ldat['jabatan_label'] ?? "Level $lvl";

                                        // Color logic:
                                        // Red: Status Ditolak
                                        // Green: Status Sudah Disposisi atau pengesahan true
                                        // Yellow: Status Menunggu / Belum Disposisi
                                        if (strtolower($status_txt) === 'ditolak' || $pengesahan === 'rejected') {
                                            $bg_color     = '#fee2e2';
                                            $border_color = '#ef4444';
                                            $text_color   = '#b91c1c';
                                            $status_desc  = 'Ditolak';
                                        } elseif ($status_txt === 'Sudah Disposisi' || $pengesahan === 'true') {
                                            $bg_color     = '#d1fae5';
                                            $border_color = '#10b981';
                                            $text_color   = '#047857';
                                            $status_desc  = 'Sudah Disposisi';
                                        } else {
                                            $bg_color     = '#fef3c7';
                                            $border_color = '#f59e0b';
                                            $text_color   = '#b45309';
                                            $status_desc  = 'Belum Disposisi (Menunggu)';
                                        }

                                        $tooltip_title = htmlspecialchars("{$jbtn_label}: {$nama_app} ({$status_desc})");
                                        ?>
                                        <span title="<?= $tooltip_title ?>" style="background: <?= $bg_color ?>; border: 1.5px solid <?= $border_color ?>; color: <?= $text_color ?>; font-weight: 700; border-radius: 6px; width: 28px; height: 28px; display: inline-flex; align-items: center; justify-content: center; font-size: 12px; cursor: help;">
                                            <?= $lvl ?>
                                        </span>
                                    <?php endfor; ?>
                                </div>
                            </td>
                            <td style="padding: 12px 14px; text-align: center;">
                                <?php if ($surat['can_open']): ?>
                                    <button type="button" class="btn btn-sm btn-primary" onclick='openUnifiedModal(<?= json_encode($surat, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)' style="display: inline-flex; align-items: center; gap: 6px; font-size: 12px; padding: 6px 12px;">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                        Lihat / Edit
                                    </button>
                                <?php else: ?>
                                    <button type="button" class="btn btn-sm btn-secondary" disabled title="Akses Terbatas: Hanya pembuat surat dan pendisposisi yang dapat melihat/mengubah." style="display: inline-flex; align-items: center; gap: 6px; font-size: 12px; padding: 6px 12px; opacity: 0.6; cursor: not-allowed; background: #e2e8f0; color: #64748b; border: 1px solid #cbd5e1;">
                                        🔒 Terkunci
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- MODAL UNIFIED DETAIL SURAT -->
<div id="modalUnifiedDetail" class="modal-overlay" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); z-index: 9999; backdrop-filter: blur(4px); align-items: center; justify-content: center; padding: 20px; overflow-y: auto;">
    <div style="background: #ffffff; border-radius: 16px; width: 100%; max-width: 750px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1); overflow: hidden; margin: auto;">
        
        <!-- MODAL HEADER -->
        <div style="padding: 20px 24px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center;">
            <div style="display: flex; align-items: center; gap: 10px;">
                <span id="uniModalBadge"></span>
                <div>
                    <h3 id="uniModalNoSurat" style="margin: 0; font-size: 17px; font-weight: 700; color: #1e293b;"></h3>
                    <div id="uniModalNoUrut" style="font-size: 12px; color: #64748b;"></div>
                </div>
            </div>
            <button type="button" onclick="closeUnifiedModal()" style="background: transparent; border: none; font-size: 24px; color: #64748b; cursor: pointer;">&times;</button>
        </div>

        <!-- MODAL BODY -->
        <div style="padding: 24px; max-height: 70vh; overflow-y: auto;">
            
            <!-- DETAIL SURAT GRID -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 20px; background: #f1f5f9; padding: 14px; border-radius: 10px;">
                <div>
                    <div style="font-size: 11px; color: #64748b; text-transform: uppercase; font-weight: 700;">Tanggal Surat</div>
                    <div id="uniModalTglSurat" style="font-size: 14px; font-weight: 600; color: #1e293b; margin-top: 2px;"></div>
                </div>
                <div>
                    <div style="font-size: 11px; color: #64748b; text-transform: uppercase; font-weight: 700;">Asal Surat</div>
                    <div id="uniModalAsal" style="font-size: 14px; font-weight: 600; color: #1e293b; margin-top: 2px;"></div>
                </div>
                <div>
                    <div style="font-size: 11px; color: #64748b; text-transform: uppercase; font-weight: 700;">Tujuan Surat</div>
                    <div id="uniModalTujuan" style="font-size: 14px; font-weight: 600; color: #1e293b; margin-top: 2px;"></div>
                </div>
            </div>

            <!-- PERIHAL -->
            <div style="margin-bottom: 20px;">
                <div style="font-size: 12px; color: #64748b; text-transform: uppercase; font-weight: 700; margin-bottom: 4px;">Perihal</div>
                <div id="uniModalPerihal" style="font-size: 15px; font-weight: 600; color: #0f172a; line-height: 1.5; background: #fff; border: 1px solid #e2e8f0; padding: 12px; border-radius: 8px;"></div>
            </div>

            <!-- KETERANGAN -->
            <div id="uniModalKeteranganContainer" style="margin-bottom: 20px; display: none;">
                <div style="font-size: 12px; color: #64748b; text-transform: uppercase; font-weight: 700; margin-bottom: 4px;">Keterangan</div>
                <div id="uniModalKeterangan" style="font-size: 13px; color: #334155; background: #fff; border: 1px solid #e2e8f0; padding: 10px 12px; border-radius: 8px;"></div>
            </div>

            <!-- BERKAS ATTACHMENT LINK -->
            <div id="uniModalFileContainer" style="margin-bottom: 24px; display: none;">
                <a id="uniModalFileLink" href="#" target="_blank" class="btn btn-secondary" style="display: inline-flex; align-items: center; gap: 8px; font-size: 13px;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg>
                    Buka / Unduh Berkas Surat
                </a>
            </div>

            <!-- DISPOSISI 3 LEVEL PROGRESS -->
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px;">
                <div style="font-size: 13px; font-weight: 700; color: #334155; margin-bottom: 12px;">Rincian Disposisi 3 Level</div>
                <div id="uniModalLevelsList" style="display: flex; flex-direction: column; gap: 10px;"></div>
            </div>
        </div>

        <!-- MODAL FOOTER -->
        <div style="padding: 16px 24px; background: #f8fafc; border-top: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center;">
            <a id="uniModalDirectBtn" href="#" class="btn btn-primary" style="font-size: 13px; display: inline-flex; align-items: center; gap: 6px;">
                ⚙️ Buka di Halaman Kelola
            </a>
            <button type="button" class="btn btn-secondary" onclick="closeUnifiedModal()" style="font-size: 13px;">Tutup</button>
        </div>
    </div>
</div>

<script>
function openUnifiedModal(surat) {
    const isMasuk = surat.jenis_surat === 'surat_masuk';
    
    // Badge
    const badge = document.getElementById('uniModalBadge');
    if (isMasuk) {
        badge.innerHTML = '<span style="background: #e0f2fe; color: #0369a1; font-weight: 700; font-size: 11px; padding: 4px 10px; border-radius: 20px;">📥 Surat Masuk</span>';
    } else {
        badge.innerHTML = '<span style="background: #f3e8ff; color: #6b21a8; font-weight: 700; font-size: 11px; padding: 4px 10px; border-radius: 20px;">📤 Surat Keluar</span>';
    }

    document.getElementById('uniModalNoSurat').innerText = surat.no_surat || '-';
    document.getElementById('uniModalNoUrut').innerText = 'ID Transaksi: ' + (surat.no_urut || '-');
    document.getElementById('uniModalTglSurat').innerText = surat.tgl_surat || '-';
    document.getElementById('uniModalAsal').innerText = surat.asal || '-';
    document.getElementById('uniModalTujuan').innerText = surat.tujuan || '-';
    document.getElementById('uniModalPerihal').innerText = surat.perihal || '-';

    // Keterangan
    const ketContainer = document.getElementById('uniModalKeteranganContainer');
    if (surat.keterangan && surat.keterangan.trim() !== '') {
        document.getElementById('uniModalKeterangan').innerText = surat.keterangan;
        ketContainer.style.display = 'block';
    } else {
        ketContainer.style.display = 'none';
    }

    // File Link
    const fileContainer = document.getElementById('uniModalFileContainer');
    if (surat.file_url && surat.file_url.trim() !== '') {
        document.getElementById('uniModalFileLink').href = surat.file_url;
        fileContainer.style.display = 'block';
    } else {
        fileContainer.style.display = 'none';
    }

    // Direct link to full management page
    const directBtn = document.getElementById('uniModalDirectBtn');
    if (isMasuk) {
        directBtn.href = 'index.php?page=surat_menyurat&sub=surat_masuk';
    } else {
        directBtn.href = 'index.php?page=surat_menyurat&sub=surat_keluar';
    }

    // Render 3 Level Disposisi Cards
    const levelsList = document.getElementById('uniModalLevelsList');
    levelsList.innerHTML = '';

    for (let l = 1; l <= 3; l++) {
        const ldat = (surat.levels && surat.levels[l]) ? surat.levels[l] : null;
        const status = ldat ? ldat.status_disposisi : 'Menunggu';
        const pengesahan = ldat ? ldat.pengesahan : 'false';
        const nama = ldat ? (ldat.nama_pegawai || ldat.nik) : 'Belum ditentukan';
        const jbtn = ldat ? (ldat.jabatan_label || ('Level ' + l)) : ('Level ' + l);
        const catatan = ldat && ldat.catatan ? ldat.catatan : (ldat && ldat.isi_disposisi ? ldat.isi_disposisi : '');

        let statusBadge = '';
        if (status.toLowerCase() === 'ditolak' || pengesahan === 'rejected') {
            statusBadge = '<span style="background: #fee2e2; border: 1px solid #ef4444; color: #b91c1c; font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 12px;">Ditolak</span>';
        } else if (status === 'Sudah Disposisi' || pengesahan === 'true') {
            statusBadge = '<span style="background: #d1fae5; border: 1px solid #10b981; color: #047857; font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 12px;">Sudah Disposisi</span>';
        } else {
            statusBadge = '<span style="background: #fef3c7; border: 1px solid #f59e0b; color: #b45309; font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 12px;">Menunggu</span>';
        }

        const card = document.createElement('div');
        card.style.cssText = 'background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; display: flex; flex-direction: column; gap: 4px;';
        card.innerHTML = `
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <span style="font-size: 12px; font-weight: 700; color: #475569;">Level ${l}: ${jbtn}</span>
                ${statusBadge}
            </div>
            <div style="font-size: 13px; font-weight: 600; color: #1e293b;">👤 ${nama}</div>
            ${catatan ? `<div style="font-size: 12px; color: #64748b; background: #f8fafc; padding: 6px 8px; border-radius: 6px; margin-top: 4px;">💬 ${catatan}</div>` : ''}
        `;
        levelsList.appendChild(card);
    }

    const modal = document.getElementById('modalUnifiedDetail');
    modal.style.display = 'flex';
}

function closeUnifiedModal() {
    document.getElementById('modalUnifiedDetail').style.display = 'none';
}
</script>
