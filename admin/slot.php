<?php
require_once __DIR__ . "/../includes/bootstrap.php";
require_role(["admin"]);

$uid = (int)current_user()["id_user"];

// Handle POST actions
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    verify_csrf();
    $act = post("action");

    if ($act === "save_slot") {
        $idSlot = (int)post("id_slot");
        $idArea = (int)post("id_area");
        $kode = strtoupper(trim(post("kode_slot")));
        $jenis = post("jenis_slot", "mobil");
        $lantai = post("lantai", "Lantai 1");
        $keterangan = post("keterangan");
        $status = post("status_slot", "tersedia");

        if ($kode === "" || $idArea <= 0) {
            flash("danger", "Area dan Kode Slot wajib diisi.");
        } else {
            try {
                if ($idSlot > 0) {
                    $s = $db->prepare("UPDATE tb_slot_parkir SET id_area=?, kode_slot=?, jenis_slot=?, lantai=?, status_slot=?, keterangan=? WHERE id_slot=?");
                    $s->bind_param("isssssi", $idArea, $kode, $jenis, $lantai, $status, $keterangan, $idSlot);
                } else {
                    $s = $db->prepare("INSERT INTO tb_slot_parkir (id_area, kode_slot, jenis_slot, lantai, status_slot, keterangan) VALUES (?, ?, ?, ?, ?, ?)");
                    $s->bind_param("isssss", $idArea, $kode, $jenis, $lantai, $status, $keterangan);
                }
                $s->execute();
                log_activity($db, $uid, ($idSlot > 0 ? "Ubah" : "Tambah") . " slot " . $kode);
                flash("success", "Data slot {$kode} berhasil disimpan.");
            } catch (Throwable $e) {
                flash("danger", "Gagal menyimpan slot. Pastikan kode slot unik dan belum digunakan.");
            }
        }
    } elseif ($act === "bulk_generate") {
        $idArea = (int)post("bulk_id_area");
        $prefix = strtoupper(trim(post("bulk_prefix")));
        $start = (int)post("bulk_start");
        $end = (int)post("bulk_end");
        $jenis = post("bulk_jenis", "mobil");
        $lantai = post("bulk_lantai", "Lantai 1");

        if ($idArea <= 0 || $prefix === "" || $start <= 0 || $end < $start) {
            flash("danger", "Parameter bulk generator tidak valid.");
        } else {
            $created = 0;
            $skipped = 0;
            $sInsert = $db->prepare("INSERT IGNORE INTO tb_slot_parkir (id_area, kode_slot, jenis_slot, lantai, status_slot) VALUES (?, ?, ?, ?, 'tersedia')");

            for ($i = $start; $i <= $end; $i++) {
                $code = $prefix . str_pad((string)$i, 2, "0", STR_PAD_LEFT);
                $sInsert->bind_param("isss", $idArea, $code, $jenis, $lantai);
                $sInsert->execute();
                if ($sInsert->affected_rows > 0) {
                    $created++;
                } else {
                    $skipped++;
                }
            }
            log_activity($db, $uid, "Bulk generate {$created} slot (prefix: {$prefix})");
            flash("success", "Bulk generator selesai: {$created} slot baru dibuat, {$skipped} dilewati (sudah ada).");
        }
    } elseif ($act === "delete_slot") {
        $idSlot = (int)post("id_slot");
        try {
            // Check if slot currently has active transaction
            $sCheck = $db->prepare("SELECT id_parkir FROM tb_transaksi WHERE id_slot=? AND status='masuk' LIMIT 1");
            $sCheck->bind_param("i", $idSlot);
            $sCheck->execute();
            if ($sCheck->get_result()->fetch_assoc()) {
                throw new RuntimeException("Slot tidak dapat dihapus karena masih ditempati kendaraan aktif.");
            }

            $sDel = $db->prepare("DELETE FROM tb_slot_parkir WHERE id_slot=?");
            $sDel->bind_param("i", $idSlot);
            $sDel->execute();
            log_activity($db, $uid, "Hapus slot ID " . $idSlot);
            flash("success", "Slot berhasil dihapus.");
        } catch (Throwable $e) {
            flash("danger", "Gagal menghapus slot: " . $e->getMessage());
        }
    }

    redirect("admin/slot.php");
}

// Edit handler
$edit = [
    "id_slot" => 0,
    "id_area" => 1,
    "kode_slot" => "",
    "jenis_slot" => "mobil",
    "lantai" => "Lantai 1",
    "status_slot" => "tersedia",
    "keterangan" => "",
];
$editId = get_int("edit");
if ($editId > 0) {
    $sEd = $db->prepare("SELECT * FROM tb_slot_parkir WHERE id_slot=?");
    $sEd->bind_param("i", $editId);
    $sEd->execute();
    $edit = $sEd->get_result()->fetch_assoc() ?: $edit;
}

// Fetch Areas
$areas = [];
$resArea = $db->query("SELECT * FROM tb_area_parkir ORDER BY id_area");
while ($a = $resArea->fetch_assoc()) {
    $areas[] = $a;
}

// Filter parameters
$filArea = get_int("area");
$filJenis = post("fil_jenis", $_GET["jenis"] ?? "");
$filStatus = post("fil_status", $_GET["status"] ?? "");
$search = trim($_GET["q"] ?? "");

$where = ["1=1"];
$params = [];
$types = "";

if ($filArea > 0) {
    $where[] = "s.id_area = ?";
    $params[] = $filArea;
    $types .= "i";
}
if ($filJenis !== "" && in_array($filJenis, ["motor", "mobil", "lainnya"], true)) {
    $where[] = "s.jenis_slot = ?";
    $params[] = $filJenis;
    $types .= "s";
}
if ($filStatus !== "" && in_array($filStatus, ["tersedia", "terisi", "nonaktif"], true)) {
    $where[] = "s.status_slot = ?";
    $params[] = $filStatus;
    $types .= "s";
}
if ($search !== "") {
    $where[] = "(s.kode_slot LIKE ? OR s.lantai LIKE ? OR s.keterangan LIKE ?)";
    $like = "%{$search}%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= "sss";
}

$whereSql = implode(" AND ", $where);
$sql = "
    SELECT s.*, a.nama_area, t.id_parkir, k.plat_nomor 
    FROM tb_slot_parkir s
    JOIN tb_area_parkir a ON a.id_area = s.id_area
    LEFT JOIN tb_transaksi t ON t.id_slot = s.id_slot AND t.status = 'masuk'
    LEFT JOIN tb_kendaraan k ON k.id_kendaraan = t.id_kendaraan
    WHERE {$whereSql}
    ORDER BY s.id_area ASC, s.kode_slot ASC
";

$stmt = $db->prepare($sql);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$slots = $stmt->get_result();

page_header("Manajemen Slot Parkir");
?>

<div class="row g-4">
    <!-- Form Card -->
    <div class="col-lg-4">
        <!-- Tab Form: Single vs Bulk -->
        <div class="pk-card p-4">
            <ul class="nav nav-pills nav-fill mb-3" id="slotTab" role="tablist">
                <li class="nav-item">
                    <button class="nav-link active small fw-bold" id="single-tab" data-bs-toggle="pill" data-bs-target="#tabSingle">
                        <i class="bi bi-pencil-square"></i> <?= $edit["id_slot"] ? "Edit Slot" : "Tambah Slot" ?>
                    </button>
                </li>
                <?php if (!$edit["id_slot"]): ?>
                <li class="nav-item">
                    <button class="nav-link small fw-bold" id="bulk-tab" data-bs-toggle="pill" data-bs-target="#tabBulk">
                        <i class="bi bi-stack"></i> Bulk Generate
                    </button>
                </li>
                <?php endif; ?>
            </ul>

            <div class="tab-content" id="slotTabContent">
                <!-- Single Slot Form -->
                <div class="tab-pane fade show active" id="tabSingle">
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="save_slot">
                        <input type="hidden" name="id_slot" value="<?= e($edit["id_slot"]) ?>">

                        <div class="mb-3">
                            <label class="form-label small fw-bold">Area Parkir *</label>
                            <select class="form-select" name="id_area" required>
                                <?php foreach ($areas as $a): ?>
                                    <option value="<?= $a['id_area'] ?>" <?= $edit['id_area'] == $a['id_area'] ? 'selected' : '' ?>>
                                        <?= e($a['nama_area']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-bold">Kode Slot *</label>
                            <input class="form-control text-uppercase font-monospace" name="kode_slot" required maxlength="20" placeholder="Contoh: A-01, B-12" value="<?= e($edit["kode_slot"]) ?>">
                        </div>

                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="form-label small fw-bold">Jenis Slot</label>
                                <select class="form-select" name="jenis_slot">
                                    <option value="mobil" <?= $edit["jenis_slot"] === "mobil" ? "selected" : "" ?>>Mobil</option>
                                    <option value="motor" <?= $edit["jenis_slot"] === "motor" ? "selected" : "" ?>>Motor</option>
                                    <option value="lainnya" <?= $edit["jenis_slot"] === "lainnya" ? "selected" : "" ?>>Lainnya</option>
                                </select>
                            </div>
                            <div class="col-6">
                                <label class="form-label small fw-bold">Lantai / Level</label>
                                <input class="form-control" name="lantai" value="<?= e($edit["lantai"]) ?>" placeholder="Lantai 1">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-bold">Status Slot</label>
                            <select class="form-select" name="status_slot">
                                <option value="tersedia" <?= $edit["status_slot"] === "tersedia" ? "selected" : "" ?>>Tersedia (Kosong)</option>
                                <option value="terisi" <?= $edit["status_slot"] === "terisi" ? "selected" : "" ?>>Terisi</option>
                                <option value="nonaktif" <?= $edit["status_slot"] === "nonaktif" ? "selected" : "" ?>>Nonaktif</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-bold">Keterangan (Opsional)</label>
                            <input class="form-control" name="keterangan" value="<?= e($edit["keterangan"]) ?>" placeholder="Dekat pintu keluar, VIP, dll">
                        </div>

                        <div class="d-flex gap-2">
                            <button class="btn btn-primary flex-grow-1"><i class="bi bi-save me-1"></i> Simpan Slot</button>
                            <?php if ($edit["id_slot"]): ?>
                                <a href="<?= e(url("admin/slot.php")) ?>" class="btn btn-secondary">Batal</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>

                <!-- Bulk Generator Tab -->
                <?php if (!$edit["id_slot"]): ?>
                <div class="tab-pane fade" id="tabBulk">
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="bulk_generate">

                        <div class="mb-3">
                            <label class="form-label small fw-bold">Pilih Area Target</label>
                            <select class="form-select" name="bulk_id_area" required>
                                <?php foreach ($areas as $a): ?>
                                    <option value="<?= $a['id_area'] ?>"><?= e($a['nama_area']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-bold">Prefix Kode</label>
                            <input class="form-control text-uppercase font-monospace" name="bulk_prefix" required value="A-" placeholder="Contoh: A-, B-, VIP-">
                        </div>

                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="form-label small fw-bold">Nomor Mulai</label>
                                <input class="form-control" type="number" name="bulk_start" value="1" min="1" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label small fw-bold">Nomor Selesai</label>
                                <input class="form-control" type="number" name="bulk_end" value="15" min="1" required>
                            </div>
                        </div>

                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="form-label small fw-bold">Jenis</label>
                                <select class="form-select" name="bulk_jenis">
                                    <option value="mobil">Mobil</option>
                                    <option value="motor">Motor</option>
                                </select>
                            </div>
                            <div class="col-6">
                                <label class="form-label small fw-bold">Lantai</label>
                                <input class="form-control" name="bulk_lantai" value="Lantai 1">
                            </div>
                        </div>

                        <button class="btn btn-success w-100"><i class="bi bi-magic me-1"></i> Generate Slot Otomatis</button>
                    </form>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Table Card -->
    <div class="col-lg-8">
        <div class="pk-card p-4">
            <!-- Filter Bar -->
            <form method="get" class="row g-2 mb-3">
                <div class="col-md-3 col-sm-6">
                    <select class="form-select form-select-sm" name="area" onchange="this.form.submit()">
                        <option value="0">Semua Area</option>
                        <?php foreach ($areas as $a): ?>
                            <option value="<?= $a['id_area'] ?>" <?= $filArea == $a['id_area'] ? 'selected' : '' ?>><?= e($a['nama_area']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 col-sm-6">
                    <select class="form-select form-select-sm" name="status" onchange="this.form.submit()">
                        <option value="">Semua Status</option>
                        <option value="tersedia" <?= $filStatus === "tersedia" ? "selected" : "" ?>>Tersedia</option>
                        <option value="terisi" <?= $filStatus === "terisi" ? "selected" : "" ?>>Terisi</option>
                        <option value="nonaktif" <?= $filStatus === "nonaktif" ? "selected" : "" ?>>Nonaktif</option>
                    </select>
                </div>
                <div class="col-md-4 col-sm-8">
                    <input class="form-control form-control-sm" name="q" placeholder="Cari kode slot..." value="<?= e($search) ?>">
                </div>
                <div class="col-md-2 col-sm-4 d-flex gap-1">
                    <button class="btn btn-sm btn-primary w-100"><i class="bi bi-search"></i></button>
                    <a href="<?= e(url("admin/slot.php")) ?>" class="btn btn-sm btn-outline-secondary" title="Reset filter"><i class="bi bi-arrow-counterclockwise"></i></a>
                </div>
            </form>

            <div class="table-responsive">
                <table class="table pk-table">
                    <thead>
                        <tr>
                            <th>Kode</th>
                            <th>Area</th>
                            <th>Jenis</th>
                            <th>Lantai</th>
                            <th>Status</th>
                            <th>Kendaraan</th>
                            <th class="text-end">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($slots->num_rows === 0): ?>
                            <tr>
                                <td colspan="7" class="text-center py-4 text-muted">Belum ada data slot yang sesuai.</td>
                            </tr>
                        <?php endif; ?>
                        <?php while ($s = $slots->fetch_assoc()): ?>
                            <?php 
                                $status = $s["status_slot"];
                                if ($s["id_parkir"]) {
                                    $status = "terisi";
                                }
                            ?>
                            <tr>
                                <td><span class="badge bg-dark font-monospace fs-6 px-2"><?= e($s["kode_slot"]) ?></span></td>
                                <td><?= e($s["nama_area"]) ?></td>
                                <td>
                                    <span class="badge <?= $s["jenis_slot"] === 'motor' ? 'bg-info text-dark' : 'bg-primary' ?>">
                                        <?= ucfirst($s["jenis_slot"]) ?>
                                    </span>
                                </td>
                                <td><?= e($s["lantai"]) ?></td>
                                <td>
                                    <span class="badge-pk <?= $status === 'tersedia' ? 'badge-pk-success' : ($status === 'terisi' ? 'badge-pk-danger' : 'badge-pk-secondary') ?>">
                                        <?= ucfirst($status) ?>
                                    </span>
                                </td>
                                <td>
                                    <?= $s["plat_nomor"] ? '<span class="font-monospace fw-bold">' . e($s["plat_nomor"]) . '</span>' : '-' ?>
                                </td>
                                <td class="text-end">
                                    <a class="btn btn-sm btn-outline-primary" href="?edit=<?= $s["id_slot"] ?>" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Hapus slot <?= e($s['kode_slot']) ?>?')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete_slot">
                                        <input type="hidden" name="id_slot" value="<?= $s["id_slot"] ?>">
                                        <button class="btn btn-sm btn-outline-danger" title="Hapus" <?= $s["id_parkir"] ? "disabled" : "" ?>>
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php page_footer(); ?>

