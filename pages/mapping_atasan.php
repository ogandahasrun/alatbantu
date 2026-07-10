<?php
defined('host') or die('Akses langsung tidak diizinkan.');

$success_msg = '';
$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $nik = $_POST['nik'] ?? ($_POST['nik_hidden'] ?? '');
    $nik_atasan = $_POST['nik_atasan'] ?? '';

    // Sanitization
    $nik = trim(mysqli_real_escape_string($koneksi, $nik));
    $nik_atasan = trim(mysqli_real_escape_string($koneksi, $nik_atasan));

    if ($action === 'tambah') {
        if (empty($nik) || empty($nik_atasan)) {
            $error_msg = "Pegawai dan Atasan harus dipilih!";
        } elseif ($nik === $nik_atasan) {
            $error_msg = "Pegawai tidak boleh menjadi atasan dirinya sendiri!";
        } else {
            $check_query = "SELECT nik FROM atasan_pegawai WHERE nik = ?";
            $stmt = $koneksi->prepare($check_query);
            $stmt->bind_param("s", $nik);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res->num_rows > 0) {
                $error_msg = "Pegawai dengan NIK $nik sudah memiliki atasan terdaftar. Silakan edit data tersebut.";
            } else {
                $insert_query = "INSERT INTO atasan_pegawai (nik, nik_atasan) VALUES (?, ?)";
                $stmt_insert = $koneksi->prepare($insert_query);
                $stmt_insert->bind_param("ss", $nik, $nik_atasan);
                if ($stmt_insert->execute()) {
                    $success_msg = "Berhasil menambahkan mapping atasan pegawai.";
                } else {
                    $error_msg = "Gagal menyimpan data: " . $koneksi->error;
                }
            }
        }
    } elseif ($action === 'edit') {
        if (empty($nik) || empty($nik_atasan)) {
            $error_msg = "Pegawai dan Atasan harus dipilih!";
        } elseif ($nik === $nik_atasan) {
            $error_msg = "Pegawai tidak boleh menjadi atasan dirinya sendiri!";
        } else {
            $update_query = "UPDATE atasan_pegawai SET nik_atasan = ? WHERE nik = ?";
            $stmt_update = $koneksi->prepare($update_query);
            $stmt_update->bind_param("ss", $nik_atasan, $nik);
            if ($stmt_update->execute()) {
                $success_msg = "Berhasil memperbarui mapping atasan pegawai.";
            } else {
                $error_msg = "Gagal memperbarui data: " . $koneksi->error;
            }
        }
    } elseif ($action === 'hapus') {
        if (empty($nik)) {
            $error_msg = "NIK pegawai tidak valid!";
        } else {
            $delete_query = "DELETE FROM atasan_pegawai WHERE nik = ?";
            $stmt_delete = $koneksi->prepare($delete_query);
            $stmt_delete->bind_param("s", $nik);
            if ($stmt_delete->execute()) {
                $success_msg = "Berhasil menghapus mapping atasan pegawai.";
            } else {
                $error_msg = "Gagal menghapus data: " . $koneksi->error;
            }
        }
    }
}

// Ambil daftar semua pegawai untuk dropdown
$query_pegawai = "SELECT nik, nama FROM pegawai ORDER BY nama ASC";
$result_pegawai = mysqli_query($koneksi, $query_pegawai);
$list_pegawai = [];
if ($result_pegawai) {
    while ($row = mysqli_fetch_assoc($result_pegawai)) {
        $list_pegawai[] = $row;
    }
}

// Ambil mapping yang ada
$query_mapping = "
    SELECT 
        ap.nik, 
        p1.nama AS nama_pegawai, 
        ap.nik_atasan, 
        p2.nama AS nama_atasan
    FROM 
        atasan_pegawai ap
    INNER JOIN pegawai p1 ON ap.nik = p1.nik
    INNER JOIN pegawai p2 ON ap.nik_atasan = p2.nik
    ORDER BY p1.nama ASC
";
$result_mapping = mysqli_query($koneksi, $query_mapping);
$list_mapping = [];
if ($result_mapping) {
    while ($row = mysqli_fetch_assoc($result_mapping)) {
        $list_mapping[] = $row;
    }
}
?>

<!-- jQuery & Select2 (CDN) -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<style>
    /* ---- Local styles for mapping_atasan page ---- */
    .ma-grid {
        display: grid;
        grid-template-columns: 1fr;
        gap: 20px;
        margin-top: 4px;
    }
    @media (min-width: 860px) {
        .ma-grid {
            grid-template-columns: 380px 1fr;
            align-items: start;
        }
    }

    /* Alert overrides */
    .ma-alert {
        padding: 14px 16px;
        border-radius: 12px;
        margin-bottom: 20px;
        font-size: 14px;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .ma-alert-success {
        background: rgba(16, 185, 129, 0.08);
        border: 1px solid rgba(16, 185, 129, 0.3);
        color: #065f46;
    }
    .ma-alert-error {
        background: rgba(239, 68, 68, 0.08);
        border: 1px solid rgba(239, 68, 68, 0.3);
        color: #991b1b;
    }

    /* Info box */
    .ma-info-box {
        background-color: #eff6ff;
        color: #1e40af;
        border: 1px solid #bfdbfe;
        padding: 12px;
        border-radius: 10px;
        font-size: 13px;
        margin-bottom: 16px;
        line-height: 1.5;
    }

    /* Select2 overrides to match alatbantu style */
    .select2-container .select2-selection--single {
        height: 42px;
        background-color: var(--bg-primary);
        border: 1.5px solid var(--border-color);
        border-radius: var(--radius-sm);
        display: flex;
        align-items: center;
        font-family: inherit;
        font-size: 14px;
    }
    .select2-container--default .select2-selection--single .select2-selection__rendered {
        color: var(--text-primary);
        padding-left: 14px;
        padding-right: 14px;
        line-height: 40px;
    }
    .select2-container--default .select2-selection--single .select2-selection__arrow {
        height: 40px;
        right: 10px;
    }
    .select2-container--default.select2-container--focus .select2-selection--single {
        border-color: var(--accent);
        box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.12);
    }
    .select2-container--default.select2-container--disabled .select2-selection--single {
        background-color: var(--bg-tertiary);
        cursor: not-allowed;
    }

    /* Table */
    .ma-table-wrap {
        width: 100%;
        overflow-x: auto;
        border-radius: 10px;
        border: 1px solid var(--border-color);
    }
    .ma-table {
        width: 100%;
        border-collapse: collapse;
        text-align: left;
    }
    .ma-table th, .ma-table td {
        padding: 11px 14px;
        border-bottom: 1px solid var(--border-color);
        font-size: 13.5px;
    }
    .ma-table th {
        background: var(--bg-secondary);
        color: var(--text-secondary);
        font-weight: 600;
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: 0.04em;
    }
    .ma-table tr:last-child td {
        border-bottom: none;
    }
    .ma-table tr:hover td {
        background: var(--bg-secondary);
    }

    /* Action buttons */
    .btn-ma-edit {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 5px 12px;
        font-size: 12px;
        font-weight: 500;
        border-radius: 8px;
        cursor: pointer;
        border: 1.5px solid rgba(180, 83, 9, 0.15);
        background: #fef3c7;
        color: #b45309;
        transition: all 0.2s;
        text-decoration: none;
    }
    .btn-ma-edit:hover { background: #fde68a; transform: translateY(-1px); }

    .btn-ma-delete {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 5px 12px;
        font-size: 12px;
        font-weight: 500;
        border-radius: 8px;
        cursor: pointer;
        border: 1.5px solid rgba(185, 28, 28, 0.15);
        background: #fee2e2;
        color: #b91c1c;
        transition: all 0.2s;
    }
    .btn-ma-delete:hover { background: #fecaca; transform: translateY(-1px); }

    .ma-no-data {
        text-align: center;
        color: var(--text-secondary);
        font-style: italic;
        padding: 30px;
    }

    .ma-badge {
        font-size: 12px;
        background: rgba(99, 102, 241, 0.1);
        color: var(--accent);
        padding: 2px 10px;
        border-radius: 12px;
        font-weight: 500;
    }

    .text-muted-sm {
        font-size: 11.5px;
        color: var(--text-secondary);
        display: inline-block;
        margin-top: 2px;
    }
</style>

<div class="page-header">
    <div>
        <h1 class="page-title">Mapping Atasan Pegawai</h1>
        <p class="text-secondary" style="font-size: 14px;">Kelola data hubungan atasan langsung setiap pegawai untuk keperluan persetujuan cuti.</p>
    </div>
</div>

<?php if (!empty($success_msg)): ?>
    <div class="ma-alert ma-alert-success">
        <span style="font-size: 20px;">✅</span>
        <span><strong>Berhasil!</strong> <?= htmlspecialchars($success_msg) ?></span>
    </div>
<?php endif; ?>

<?php if (!empty($error_msg)): ?>
    <div class="ma-alert ma-alert-error">
        <span style="font-size: 20px;">⚠️</span>
        <span><strong>Gagal!</strong> <?= htmlspecialchars($error_msg) ?></span>
    </div>
<?php endif; ?>

<div class="ma-grid">
    <!-- Column 1: Form Card -->
    <div>
        <div class="content-card">
            <div class="card-header">
                <h3 class="card-title" id="formTitle">Tambah Mapping Atasan</h3>
            </div>
            <div style="padding: 4px 0;">
                <div class="ma-info-box" id="infoBox">
                    Setiap pegawai hanya dapat memiliki satu atasan langsung dalam sistem persetujuan cuti.
                </div>

                <form action="index.php?page=manajemen&sub=mapping_atasan" method="POST" id="mappingForm" onsubmit="return validateFormSubmit()">
                    <input type="hidden" name="action" id="formAction" value="tambah">
                    <input type="hidden" name="nik_hidden" id="nik_hidden" value="" disabled>

                    <div class="form-group">
                        <label class="form-label" for="nik">Pegawai</label>
                        <select name="nik" id="nik" class="form-control select2-ma" required>
                            <option value="" disabled selected>-- Pilih Pegawai --</option>
                            <?php foreach ($list_pegawai as $peg): ?>
                                <option value="<?= htmlspecialchars($peg['nik']) ?>">
                                    <?= htmlspecialchars($peg['nik'] . ' - ' . $peg['nama']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="nik_atasan">Atasan Langsung</label>
                        <select name="nik_atasan" id="nik_atasan" class="form-control select2-ma" required>
                            <option value="" disabled selected>-- Pilih Atasan --</option>
                            <?php foreach ($list_pegawai as $peg): ?>
                                <option value="<?= htmlspecialchars($peg['nik']) ?>">
                                    <?= htmlspecialchars($peg['nik'] . ' - ' . $peg['nama']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div style="display: flex; gap: 10px; margin-top: 16px;">
                        <button type="submit" id="submitBtn" class="btn btn-primary" style="flex: 1;">Simpan Mapping</button>
                        <button type="button" id="cancelBtn" class="btn btn-secondary" style="display:none;" onclick="maResetForm()">Batal</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Column 2: Mappings Table -->
    <div>
        <div class="content-card">
            <div class="card-header">
                <h3 class="card-title">Daftar Atasan Pegawai <span class="ma-badge" id="countBadge"><?= count($list_mapping) ?> Data</span></h3>
            </div>

            <div class="form-group" style="margin-bottom: 16px;">
                <input type="text" id="maSearchBar" class="form-control" placeholder="🔍 Cari nama/NIK pegawai atau atasan..." onkeyup="maFilterTable()">
            </div>

            <div class="ma-table-wrap">
                <table class="ma-table" id="mappingTable">
                    <thead>
                        <tr>
                            <th style="width: 45px; text-align:center;">No</th>
                            <th>Pegawai</th>
                            <th>Atasan Langsung</th>
                            <th style="width: 150px; text-align:center;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($list_mapping)): ?>
                            <tr class="no-data-row">
                                <td colspan="4" class="ma-no-data">Belum ada data mapping atasan pegawai.</td>
                            </tr>
                        <?php else: ?>
                            <?php $no = 1; foreach ($list_mapping as $map): ?>
                                <tr>
                                    <td style="text-align:center;"><?= $no++ ?></td>
                                    <td>
                                        <strong class="emp-name"><?= htmlspecialchars($map['nama_pegawai']) ?></strong><br>
                                        <span class="emp-nik text-muted-sm">NIK: <?= htmlspecialchars($map['nik']) ?></span>
                                    </td>
                                    <td>
                                        <strong class="atasan-name"><?= htmlspecialchars($map['nama_atasan']) ?></strong><br>
                                        <span class="atasan-nik text-muted-sm">NIK: <?= htmlspecialchars($map['nik_atasan']) ?></span>
                                    </td>
                                    <td style="text-align:center;">
                                        <button type="button" class="btn-ma-edit" onclick="maEditMapping('<?= htmlspecialchars($map['nik']) ?>', '<?= htmlspecialchars($map['nik_atasan']) ?>')">
                                            ✏️ Edit
                                        </button>
                                        <form action="index.php?page=manajemen&sub=mapping_atasan" method="POST" style="display:inline;" onsubmit="return confirm('Apakah Anda yakin ingin menghapus mapping atasan untuk pegawai ini?')">
                                            <input type="hidden" name="action" value="hapus">
                                            <input type="hidden" name="nik" value="<?= htmlspecialchars($map['nik']) ?>">
                                            <button type="submit" class="btn-ma-delete">🗑️ Hapus</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    $('.select2-ma').select2({ width: '100%' });
});

function validateFormSubmit() {
    const action = document.getElementById('formAction').value;
    let nikVal = '';
    if (action === 'edit') {
        nikVal = document.getElementById('nik_hidden').value;
    } else {
        nikVal = document.getElementById('nik').value;
    }
    const atasanVal = document.getElementById('nik_atasan').value;
    if (!nikVal || !atasanVal) {
        alert('Silakan pilih Pegawai dan Atasan!');
        return false;
    }
    if (nikVal === atasanVal) {
        alert('Error: Pegawai tidak boleh menjadi atasan dirinya sendiri!');
        return false;
    }
    return true;
}

function maEditMapping(nik, nikAtasan) {
    document.getElementById('formTitle').innerText = 'Edit Mapping Atasan';
    document.getElementById('formAction').value = 'edit';

    const hiddenNik = document.getElementById('nik_hidden');
    hiddenNik.value = nik;
    hiddenNik.disabled = false;

    const selectNik = $('#nik');
    selectNik.val(nik).trigger('change');
    selectNik.prop('disabled', true);

    $('#nik_atasan').val(nikAtasan).trigger('change');

    document.getElementById('cancelBtn').style.display = 'inline-block';
    const infoBox = document.getElementById('infoBox');
    infoBox.innerHTML = '<strong>Mode Edit:</strong> NIK Pegawai tidak dapat diubah karena merupakan kunci utama. Anda hanya dapat mengubah Atasan Langsung dari pegawai ini.';
    infoBox.style.backgroundColor = '#fef3c7';
    infoBox.style.color = '#b45309';
    infoBox.style.borderColor = '#fde68a';
    document.getElementById('submitBtn').innerText = 'Simpan Perubahan';

    document.getElementById('formTitle').scrollIntoView({ behavior: 'smooth' });
}

function maResetForm() {
    document.getElementById('formTitle').innerText = 'Tambah Mapping Atasan';
    document.getElementById('formAction').value = 'tambah';

    const hiddenNik = document.getElementById('nik_hidden');
    hiddenNik.value = '';
    hiddenNik.disabled = true;

    const selectNik = $('#nik');
    selectNik.prop('disabled', false);
    selectNik.val('').trigger('change');
    $('#nik_atasan').val('').trigger('change');

    document.getElementById('cancelBtn').style.display = 'none';
    const infoBox = document.getElementById('infoBox');
    infoBox.innerHTML = 'Setiap pegawai hanya dapat memiliki satu atasan langsung dalam sistem persetujuan cuti.';
    infoBox.style.backgroundColor = '#eff6ff';
    infoBox.style.color = '#1e40af';
    infoBox.style.borderColor = '#bfdbfe';
    document.getElementById('submitBtn').innerText = 'Simpan Mapping';
}

function maFilterTable() {
    const query = document.getElementById('maSearchBar').value.toLowerCase();
    const rows = document.querySelectorAll('#mappingTable tbody tr');
    let visibleCount = 0;
    let isNoDataRow = false;

    rows.forEach(row => {
        if (row.classList.contains('no-data-row')) { isNoDataRow = true; return; }
        const empName = row.querySelector('.emp-name') ? row.querySelector('.emp-name').innerText.toLowerCase() : '';
        const empNik  = row.querySelector('.emp-nik')  ? row.querySelector('.emp-nik').innerText.toLowerCase()  : '';
        const atName  = row.querySelector('.atasan-name') ? row.querySelector('.atasan-name').innerText.toLowerCase() : '';
        const atNik   = row.querySelector('.atasan-nik')  ? row.querySelector('.atasan-nik').innerText.toLowerCase()  : '';
        if (empName.includes(query) || empNik.includes(query) || atName.includes(query) || atNik.includes(query)) {
            row.style.display = '';
            visibleCount++;
        } else {
            row.style.display = 'none';
        }
    });

    document.getElementById('countBadge').innerText = visibleCount + ' Data';

    let noMatchRow = document.getElementById('noMatchRow');
    if (visibleCount === 0 && !isNoDataRow) {
        if (!noMatchRow) {
            const tbody = document.querySelector('#mappingTable tbody');
            noMatchRow = document.createElement('tr');
            noMatchRow.id = 'noMatchRow';
            noMatchRow.innerHTML = '<td colspan="4" class="ma-no-data">Tidak ada data mapping yang cocok dengan pencarian Anda.</td>';
            tbody.appendChild(noMatchRow);
        }
    } else {
        if (noMatchRow) noMatchRow.remove();
    }
}
</script>
