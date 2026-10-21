<?php
require_once __DIR__ . "/../includes/bootstrap.php";
require_role(["admin"]);

$uid = (int)current_user()["id_user"];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    verify_csrf();
    $act = post("action");
    $id = (int)post("id_tarif");
    $jenis = post("jenis_kendaraan");
    $tarif = (float)post("tarif_per_jam");

    if ($act === "delete") {
        try {
            $sCheck = $db->prepare("SELECT id_parkir FROM tb_transaksi WHERE id_tarif=? LIMIT 1");
            $sCheck->bind_param("i", $id);
            $sCheck->execute();
            if ($sCheck->get_result()->fetch_assoc()) {
                throw new RuntimeException("Tarif masih direferensikan oleh riwayat transaksi.");
            }

            $s = $db->prepare("DELETE FROM tb_tarif WHERE id_tarif=?");
            $s->bind_param("i", $id);
            $s->execute();
            log_activity($db, $uid, "Hapus tarif ID " . $id);
            flash("success", "Tarif berhasil dihapus.");
        } catch (Throwable $e) {
            flash("danger", "Gagal menghapus tarif: " . $e->getMessage());
        }
    } else {
        if (!in_array($jenis, ["motor", "mobil", "lainnya"], true) || $tarif < 0) {
            flash("danger", "Data tarif tidak valid. Nilai tarif minimal 0.");
        } else {
            try {
                if ($id) {
                    $s = $db->prepare("UPDATE tb_tarif SET jenis_kendaraan=?, tarif_per_jam=? WHERE id_tarif=?");
                    $s->bind_param("sdi", $jenis, $tarif, $id);
                } else {
                    $s = $db->prepare("INSERT INTO tb_tarif (jenis_kendaraan, tarif_per_jam) VALUES (?, ?)");
                    $s->bind_param("sd", $jenis, $tarif);
                }
                $s->execute();
                log_activity($db, $uid, "Simpan tarif " . $jenis . " (Rp " . number_format($tarif, 0, ',', '.') . "/jam)");
                flash("success", "Data tarif {$jenis} berhasil disimpan.");
            } catch (Throwable $e) {
                flash("danger", "Gagal menyimpan: jenis kendaraan ini mungkin sudah terdaftar.");
            }
        }
    }
    redirect("admin/tarif.php");
}

$edit = ["id_tarif" => 0, "jenis_kendaraan" => "motor", "tarif_per_jam" => 2000];
if (get_int("edit")) {
    $id = get_int("edit");
    $s = $db->prepare("SELECT * FROM tb_tarif WHERE id_tarif=?");
    $s->bind_param("i", $id);
    $s->execute();
    $edit = $s->get_result()->fetch_assoc() ?: $edit;
}

$res = $db->query("SELECT * FROM tb_tarif ORDER BY id_tarif");

page_header("Tarif Parkir");
?>

<div class="row g-4">
    <!-- Form Card -->
    <div class="col-md-4">
        <div class="pk-card p-4">
            <h5 class="fw-bold mb-3"><?= $edit["id_tarif"] ? "Edit Tarif" : "Tambah Tarif Baru" ?></h5>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="id_tarif" value="<?= e($edit["id_tarif"]) ?>">

                <div class="mb-3">
                    <label class="form-label small fw-bold">Jenis Kendaraan *</label>
                    <select class="form-select" name="jenis_kendaraan">
                        <?php foreach (["motor", "mobil", "lainnya"] as $j): ?>
                            <option value="<?= $j ?>" <?= $edit["jenis_kendaraan"] === $j ? "selected" : "" ?>>
                                <?= ucfirst($j) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-bold">Tarif per Jam (Rp) *</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light">Rp</span>
                        <input class="form-control" type="number" min="0" step="500" name="tarif_per_jam" value="<?= e($edit["tarif_per_jam"]) ?>" required>
                    </div>
                    <div class="form-text small">Tarif dihitung per jam dengan pembulatan ke atas.</div>
                </div>

                <div class="d-flex gap-2">
                    <button class="btn btn-primary flex-grow-1"><i class="bi bi-save me-1"></i> Simpan Tarif</button>
                    <?php if ($edit["id_tarif"]): ?>
                        <a href="<?= e(url("admin/tarif.php")) ?>" class="btn btn-secondary">Batal</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- Table Card -->
    <div class="col-md-8">
        <div class="pk-card p-4">
            <h5 class="fw-bold mb-3">Daftar Tarif Aktif</h5>
            <div class="table-responsive">
                <table class="table pk-table">
                    <thead>
                        <tr>
                            <th>Jenis Kendaraan</th>
                            <th>Tarif per Jam</th>
                            <th>Aturan Perhitungan</th>
                            <th class="text-end">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($r = $res->fetch_assoc()): ?>
                            <tr>
                                <td class="fw-semibold">
                                    <span class="badge <?= $r['jenis_kendaraan'] === 'motor' ? 'bg-info text-dark' : 'bg-primary' ?> me-2">
                                        <i class="bi <?= $r['jenis_kendaraan'] === 'motor' ? 'bi-bicycle' : 'bi-car-front' ?>"></i>
                                    </span>
                                    <?= ucfirst($r["jenis_kendaraan"]) ?>
                                </td>
                                <td class="fw-bold text-success fs-6">
                                    <?= rupiah($r["tarif_per_jam"]) ?> / jam
                                </td>
                                <td class="text-secondary small">
                                    Minimal 1 jam, pecahan jam berikutnya dibulatkan ke atas.
                                </td>
                                <td class="text-end">
                                    <a class="btn btn-sm btn-outline-primary" href="?edit=<?= $r["id_tarif"] ?>" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Hapus tarif <?= e($r['jenis_kendaraan']) ?>?')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id_tarif" value="<?= $r["id_tarif"] ?>">
                                        <button class="btn btn-sm btn-outline-danger" title="Hapus">
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
