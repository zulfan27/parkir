<?php
require_once __DIR__ . "/../includes/bootstrap.php";
require_role(["admin"]);

$uid = (int)current_user()["id_user"];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    verify_csrf();
    $act = post("action");
    $id = (int)post("id_area");
    $nama = post("nama_area");
    $kap = (int)post("kapasitas");

    if ($act === "delete") {
        try {
            // Check if area still has transactions or slots
            $sTrx = $db->prepare("SELECT id_parkir FROM tb_transaksi WHERE id_area=? AND status='masuk' LIMIT 1");
            $sTrx->bind_param("i", $id);
            $sTrx->execute();
            if ($sTrx->get_result()->fetch_assoc()) {
                throw new RuntimeException("Area masih memiliki kendaraan aktif parkir.");
            }

            $s = $db->prepare("DELETE FROM tb_area_parkir WHERE id_area=?");
            $s->bind_param("i", $id);
            $s->execute();
            log_activity($db, $uid, "Hapus area ID " . $id);
            flash("success", "Area berhasil dihapus.");
        } catch (Throwable $e) {
            flash("danger", "Gagal menghapus area: " . $e->getMessage());
        }
    } else {
        if ($nama === "" || $kap < 0) {
            flash("danger", "Nama area dan kapasitas harus valid.");
        } else {
            try {
                if ($id) {
                    $s = $db->prepare("UPDATE tb_area_parkir SET nama_area=?, kapasitas=? WHERE id_area=? AND terisi<=?");
                    $s->bind_param("siii", $nama, $kap, $id, $kap);
                } else {
                    $s = $db->prepare("INSERT INTO tb_area_parkir (nama_area, kapasitas, terisi) VALUES (?, ?, 0)");
                    $s->bind_param("si", $nama, $kap);
                }
                $s->execute();
                if ($id && !$s->affected_rows) {
                    flash("warning", "Data tidak berubah atau kapasitas yang dimasukkan lebih kecil dari jumlah terisi.");
                } else {
                    flash("success", "Area berhasil disimpan.");
                }
                log_activity($db, $uid, "Simpan area " . $nama);
            } catch (Throwable $e) {
                flash("danger", "Gagal menyimpan area: " . $e->getMessage());
            }
        }
    }
    redirect("admin/area.php");
}

$edit = ["id_area" => 0, "nama_area" => "", "kapasitas" => 20, "terisi" => 0];
if (get_int("edit")) {
    $id = get_int("edit");
    $s = $db->prepare("SELECT * FROM tb_area_parkir WHERE id_area=?");
    $s->bind_param("i", $id);
    $s->execute();
    $edit = $s->get_result()->fetch_assoc() ?: $edit;
}

$res = $db->query("
    SELECT a.*, COUNT(s.id_slot) AS total_slot 
    FROM tb_area_parkir a 
    LEFT JOIN tb_slot_parkir s ON s.id_area = a.id_area 
    GROUP BY a.id_area 
    ORDER BY a.id_area
");

page_header("Area Parkir");
?>

<div class="row g-4">
    <!-- Form Card -->
    <div class="col-md-4">
        <div class="pk-card p-4">
            <h5 class="fw-bold mb-3"><?= $edit["id_area"] ? "Edit Area" : "Tambah Area Baru" ?></h5>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="id_area" value="<?= e($edit["id_area"]) ?>">

                <div class="mb-3">
                    <label class="form-label small fw-bold">Nama Area *</label>
                    <input class="form-control" name="nama_area" maxlength="50" required placeholder="Contoh: Area A - Motor" value="<?= e($edit["nama_area"]) ?>">
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-bold">Kapasitas Maksimal *</label>
                    <input class="form-control" type="number" min="<?= $edit["terisi"] ?>" name="kapasitas" required value="<?= e($edit["kapasitas"]) ?>">
                    <div class="form-text small">Minimal tidak boleh kurang dari jumlah terisi (<?= $edit["terisi"] ?>).</div>
                </div>

                <div class="d-flex gap-2">
                    <button class="btn btn-primary flex-grow-1"><i class="bi bi-save me-1"></i> Simpan Area</button>
                    <?php if ($edit["id_area"]): ?>
                        <a href="<?= e(url("admin/area.php")) ?>" class="btn btn-secondary">Batal</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- Table Card -->
    <div class="col-md-8">
        <div class="pk-card p-4">
            <h5 class="fw-bold mb-3">Daftar Area Parkir</h5>
            <div class="table-responsive">
                <table class="table pk-table">
                    <thead>
                        <tr>
                            <th>Nama Area</th>
                            <th>Kapasitas</th>
                            <th>Terisi</th>
                            <th>Sisa</th>
                            <th>Slot Terdaftar</th>
                            <th class="text-end">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($r = $res->fetch_assoc()): ?>
                            <?php $sisa = max(0, $r["kapasitas"] - $r["terisi"]); ?>
                            <tr>
                                <td class="fw-semibold">
                                    <i class="bi bi-geo-alt-fill text-primary me-1"></i> <?= e($r["nama_area"]) ?>
                                </td>
                                <td><span class="badge bg-secondary"><?= $r["kapasitas"] ?></span></td>
                                <td>
                                    <span class="badge-pk <?= $r["terisi"] > 0 ? 'badge-pk-danger' : 'badge-pk-secondary' ?>">
                                        <?= $r["terisi"] ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge-pk <?= $sisa > 0 ? 'badge-pk-success' : 'badge-pk-danger' ?>">
                                        <?= $sisa ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="<?= e(url("admin/slot.php?area=" . $r["id_area"])) ?>" class="btn btn-sm btn-outline-info">
                                        <i class="bi bi-bounding-box"></i> <?= $r["total_slot"] ?> Slot
                                    </a>
                                </td>
                                <td class="text-end">
                                    <a class="btn btn-sm btn-outline-primary" href="?edit=<?= $r["id_area"] ?>" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Hapus area <?= e($r['nama_area']) ?>?')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id_area" value="<?= $r["id_area"] ?>">
                                        <button class="btn btn-sm btn-outline-danger" title="Hapus" <?= $r["terisi"] > 0 ? "disabled" : "" ?>>
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
