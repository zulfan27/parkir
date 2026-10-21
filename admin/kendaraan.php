<?php
require_once __DIR__ . "/../includes/bootstrap.php";
require_role(["admin", "petugas"]);

$u = current_user();
$uid = (int)$u["id_user"];
$isAdmin = $u["role"] === "admin";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    verify_csrf();
    $act = post("action");
    $id = (int)post("id_kendaraan");
    $plat = strtoupper(trim(post("plat_nomor")));
    $jenis = post("jenis_kendaraan");
    $warna = post("warna");
    $pemilik = post("pemilik");

    if ($act === "delete" && $isAdmin) {
        try {
            $sCheck = $db->prepare("SELECT id_parkir FROM tb_transaksi WHERE id_kendaraan=? LIMIT 1");
            $sCheck->bind_param("i", $id);
            $sCheck->execute();
            if ($sCheck->get_result()->fetch_assoc()) {
                throw new RuntimeException("Kendaraan memiliki riwayat transaksi parkir.");
            }

            $s = $db->prepare("DELETE FROM tb_kendaraan WHERE id_kendaraan=?");
            $s->bind_param("i", $id);
            $s->execute();
            log_activity($db, $uid, "Hapus kendaraan ID " . $id);
            flash("success", "Kendaraan berhasil dihapus.");
        } catch (Throwable $e) {
            flash("danger", "Gagal menghapus kendaraan: " . $e->getMessage());
        }
    } else {
        if ($plat === "" || !in_array($jenis, ["motor", "mobil", "lainnya"], true)) {
            flash("danger", "Plat nomor dan jenis kendaraan wajib diisi dengan benar.");
        } else {
            try {
                if ($id) {
                    $s = $db->prepare("UPDATE tb_kendaraan SET plat_nomor=?, jenis_kendaraan=?, warna=?, pemilik=? WHERE id_kendaraan=?");
                    $s->bind_param("ssssi", $plat, $jenis, $warna, $pemilik, $id);
                } else {
                    $s = $db->prepare("INSERT INTO tb_kendaraan (plat_nomor, jenis_kendaraan, warna, pemilik, id_user) VALUES (?, ?, ?, ?, ?)");
                    $s->bind_param("ssssi", $plat, $jenis, $warna, $pemilik, $uid);
                }
                $s->execute();
                log_activity($db, $uid, ($id ? "Ubah" : "Tambah") . " data kendaraan " . $plat);
                flash("success", "Data kendaraan {$plat} berhasil disimpan.");
            } catch (Throwable $e) {
                flash("danger", "Gagal menyimpan. Pastikan plat nomor belum pernah terdaftar.");
            }
        }
    }
    redirect("admin/kendaraan.php");
}

$edit = [
    "id_kendaraan" => 0,
    "plat_nomor" => "",
    "jenis_kendaraan" => "mobil",
    "warna" => "",
    "pemilik" => "",
];
if (get_int("edit")) {
    $id = get_int("edit");
    $s = $db->prepare("SELECT * FROM tb_kendaraan WHERE id_kendaraan=?");
    $s->bind_param("i", $id);
    $s->execute();
    $edit = $s->get_result()->fetch_assoc() ?: $edit;
}

// Search and filter parameters
$q = trim($_GET["q"] ?? "");
$filterJenis = trim($_GET["jenis"] ?? "");

$where = ["1=1"];
$params = [];
$types = "";

if ($q !== "") {
    $where[] = "(k.plat_nomor LIKE ? OR k.pemilik LIKE ? OR k.warna LIKE ?)";
    $like = "%{$q}%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= "sss";
}

if ($filterJenis !== "" && in_array($filterJenis, ["motor", "mobil", "lainnya"], true)) {
    $where[] = "k.jenis_kendaraan = ?";
    $params[] = $filterJenis;
    $types .= "s";
}

$whereSql = implode(" AND ", $where);

// Page pagination
$page = max(1, get_int("page") ?: 1);
$limit = pagination_limit();
$offset = ($page - 1) * $limit;

// Count total
$countStmt = $db->prepare("SELECT COUNT(*) AS total FROM tb_kendaraan k WHERE {$whereSql}");
if ($params) {
    $countStmt->bind_param($types, ...$params);
}
$countStmt->execute();
$totalRows = (int)$countStmt->get_result()->fetch_assoc()["total"];
$totalPages = max(1, (int)ceil($totalRows / $limit));

// Fetch query with active transaction status
$sql = "
    SELECT 
        k.*, u.username,
        t.id_parkir AS id_trx_aktif,
        t.waktu_masuk AS waktu_masuk_aktif,
        s.kode_slot
    FROM tb_kendaraan k
    JOIN tb_user u ON u.id_user = k.id_user
    LEFT JOIN tb_transaksi t ON t.id_kendaraan = k.id_kendaraan AND t.status = 'masuk'
    LEFT JOIN tb_slot_parkir s ON s.id_slot = t.id_slot
    WHERE {$whereSql}
    ORDER BY k.id_kendaraan DESC
    LIMIT ? OFFSET ?
";

$typesWithLimit = $types . "ii";
$paramsWithLimit = array_merge($params, [$limit, $offset]);

$stmt = $db->prepare($sql);
$stmt->bind_param($typesWithLimit, ...$paramsWithLimit);
$stmt->execute();
$res = $stmt->get_result();

page_header("Data Kendaraan");
?>

<div class="row g-4">
    <!-- Form Card -->
    <div class="col-lg-4">
        <div class="pk-card p-4">
            <h5 class="fw-bold mb-3"><?= $edit["id_kendaraan"] ? "Edit Kendaraan" : "Tambah Kendaraan" ?></h5>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="id_kendaraan" value="<?= e($edit["id_kendaraan"]) ?>">

                <div class="mb-3">
                    <label class="form-label small fw-bold">Nomor Polisi (Plat Nomor) *</label>
                    <input class="form-control text-uppercase font-monospace fs-6" name="plat_nomor" required maxlength="15" placeholder="Contoh: B 1234 ABC" value="<?= e($edit["plat_nomor"]) ?>">
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-bold">Jenis Kendaraan *</label>
                    <select class="form-select" name="jenis_kendaraan">
                        <?php foreach (["mobil", "motor", "lainnya"] as $j): ?>
                            <option value="<?= $j ?>" <?= $edit["jenis_kendaraan"] === $j ? "selected" : "" ?>>
                                <?= ucfirst($j) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-bold">Warna Kendaraan *</label>
                    <select class="form-select" name="warna">
                        <?php foreach (["Hitam", "Putih", "Merah", "Biru", "Silver", "Abu-abu", "Kuning", "Hijau", "Cokelat"] as $col): ?>
                            <option value="<?= $col ?>" <?= strcasecmp((string)$edit["warna"], $col) === 0 ? "selected" : "" ?>>
                                <?= $col ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-bold">Nama Pemilik / Pengemudi</label>
                    <input class="form-control" name="pemilik" maxlength="100" placeholder="Nama pemilik kendaraan" value="<?= e($edit["pemilik"]) ?>">
                </div>

                <div class="d-flex gap-2">
                    <button class="btn btn-primary flex-grow-1"><i class="bi bi-save me-1"></i> Simpan Data</button>
                    <?php if ($edit["id_kendaraan"]): ?>
                        <a href="<?= e(url("admin/kendaraan.php")) ?>" class="btn btn-secondary">Batal</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- Table Card -->
    <div class="col-lg-8">
        <div class="pk-card p-4">
            <!-- Search & Filter Bar -->
            <form method="get" class="row g-2 mb-3">
                <div class="col-md-5">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-light"><i class="bi bi-search"></i></span>
                        <input class="form-control" name="q" placeholder="Cari plat, pemilik, warna..." value="<?= e($q) ?>">
                    </div>
                </div>
                <div class="col-md-4">
                    <select class="form-select form-select-sm" name="jenis" onchange="this.form.submit()">
                        <option value="">Semua Jenis Kendaraan</option>
                        <option value="mobil" <?= $filterJenis === 'mobil' ? 'selected' : '' ?>>Mobil</option>
                        <option value="motor" <?= $filterJenis === 'motor' ? 'selected' : '' ?>>Motor</option>
                        <option value="lainnya" <?= $filterJenis === 'lainnya' ? 'selected' : '' ?>>Lainnya</option>
                    </select>
                </div>
                <div class="col-md-3 d-flex gap-1">
                    <button class="btn btn-sm btn-primary w-100">Filter</button>
                    <a href="<?= e(url("admin/kendaraan.php")) ?>" class="btn btn-sm btn-outline-secondary" title="Reset filter"><i class="bi bi-arrow-counterclockwise"></i></a>
                </div>
            </form>

            <div class="table-responsive">
                <table class="table pk-table">
                    <thead>
                        <tr>
                            <th>Plat Nomor</th>
                            <th>Jenis</th>
                            <th>Warna</th>
                            <th>Pemilik</th>
                            <th>Status Parkir</th>
                            <th class="text-end">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($res->num_rows === 0): ?>
                            <tr>
                                <td colspan="6" class="text-center py-4 text-muted">Belum ada data kendaraan yang cocok.</td>
                            </tr>
                        <?php endif; ?>
                        <?php while ($r = $res->fetch_assoc()): ?>
                            <tr>
                                <td class="font-monospace fw-bold fs-6 text-dark">
                                    <?= e($r["plat_nomor"]) ?>
                                </td>
                                <td>
                                    <span class="badge <?= $r["jenis_kendaraan"] === 'motor' ? 'bg-info text-dark' : 'bg-primary' ?>">
                                        <?= ucfirst($r["jenis_kendaraan"]) ?>
                                    </span>
                                </td>
                                <td><?= e($r["warna"] ?: "-") ?></td>
                                <td><?= e($r["pemilik"] ?: "Umum") ?></td>
                                <td>
                                    <?php if ($r["id_trx_aktif"]): ?>
                                        <span class="badge-pk badge-pk-danger">
                                            <i class="bi bi-p-circle-fill"></i> Sedang Parkir <?= $r["kode_slot"] ? "(" . e($r["kode_slot"]) . ")" : "" ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="badge-pk badge-pk-secondary">
                                            Tidak Parkir
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <a class="btn btn-sm btn-outline-primary" href="?edit=<?= $r["id_kendaraan"] ?>" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <?php if ($isAdmin): ?>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Hapus kendaraan <?= e($r['plat_nomor']) ?>?')">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id_kendaraan" value="<?= $r["id_kendaraan"] ?>">
                                            <button class="btn btn-sm btn-outline-danger" title="Hapus" <?= $r["id_trx_aktif"] ? "disabled" : "" ?>>
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
                <div class="d-flex justify-content-between align-items-center mt-3 pt-3 border-top">
                    <span class="small text-secondary">Halaman <?= $page ?> dari <?= $totalPages ?> (Total <?= $totalRows ?> data)</span>
                    <div class="btn-group btn-group-sm">
                        <?php if ($page > 1): ?>
                            <a href="?page=<?= $page - 1 ?>&q=<?= urlencode($q) ?>&jenis=<?= urlencode($filterJenis) ?>" class="btn btn-outline-secondary">Sebelumnya</a>
                        <?php endif; ?>
                        <?php if ($page < $totalPages): ?>
                            <a href="?page=<?= $page + 1 ?>&q=<?= urlencode($q) ?>&jenis=<?= urlencode($filterJenis) ?>" class="btn btn-outline-secondary">Selanjutnya</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php page_footer(); ?>
