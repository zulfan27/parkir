<?php
require_once __DIR__ . "/../includes/bootstrap.php";
require_role(["admin"]);

$page = max(1, get_int("page") ?: 1);
$limit = 25;
$offset = ($page - 1) * $limit;
$q = trim($_GET["q"] ?? "");

$where = ["1=1"];
$params = [];
$types = "";

if ($q !== "") {
    $where[] = "(l.aktivitas LIKE ? OR u.username LIKE ? OR u.nama_lengkap LIKE ?)";
    $like = "%{$q}%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= "sss";
}

$whereSql = implode(" AND ", $where);

// Total records
$cStmt = $db->prepare("SELECT COUNT(*) AS total FROM tb_log_aktivitas l JOIN tb_user u ON u.id_user = l.id_user WHERE {$whereSql}");
if ($params) {
    $cStmt->bind_param($types, ...$params);
}
$cStmt->execute();
$totalRows = (int)$cStmt->get_result()->fetch_assoc()["total"];
$totalPages = max(1, (int)ceil($totalRows / $limit));

// Fetch logs
$sql = "
    SELECT l.*, u.username, u.nama_lengkap, u.role 
    FROM tb_log_aktivitas l 
    JOIN tb_user u ON u.id_user = l.id_user 
    WHERE {$whereSql}
    ORDER BY l.id_log DESC 
    LIMIT ? OFFSET ?
";

$typesWithLimit = $types . "ii";
$paramsWithLimit = array_merge($params, [$limit, $offset]);

$s = $db->prepare($sql);
$s->bind_param($typesWithLimit, ...$paramsWithLimit);
$s->execute();
$res = $s->get_result();

page_header("Log Aktivitas Sistem");
?>

<div class="pk-card p-4">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <h5 class="fw-bold mb-0">Riwayat Aktivitas Pengguna</h5>
        <form method="get" class="d-flex gap-2">
            <div class="input-group input-group-sm" style="max-width: 300px;">
                <span class="input-group-text bg-light"><i class="bi bi-search"></i></span>
                <input class="form-control" name="q" placeholder="Cari aktivitas atau user..." value="<?= e($q) ?>">
            </div>
            <button class="btn btn-sm btn-primary">Cari</button>
            <?php if ($q !== ""): ?>
                <a href="<?= e(url("admin/log.php")) ?>" class="btn btn-sm btn-outline-secondary">Reset</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="table-responsive">
        <table class="table pk-table">
            <thead>
                <tr>
                    <th style="width: 80px;">ID Log</th>
                    <th>Pengguna</th>
                    <th>Role</th>
                    <th>Aktivitas</th>
                    <th>Waktu Aktivitas</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($res->num_rows === 0): ?>
                    <tr>
                        <td colspan="5" class="text-center py-4 text-muted">Belum ada catatan log aktivitas.</td>
                    </tr>
                <?php endif; ?>
                <?php while ($r = $res->fetch_assoc()): ?>
                    <tr>
                        <td class="text-secondary font-monospace">#<?= $r["id_log"] ?></td>
                        <td>
                            <div class="fw-semibold text-dark"><?= e($r["nama_lengkap"]) ?></div>
                            <small class="text-muted font-monospace"><?= e($r["username"]) ?></small>
                        </td>
                        <td>
                            <span class="badge <?= match($r["role"]) { "admin" => "bg-danger", "petugas" => "bg-primary", default => "bg-warning text-dark" } ?>">
                                <?= ucfirst($r["role"]) ?>
                            </span>
                        </td>
                        <td>
                            <span class="text-dark"><?= e($r["aktivitas"]) ?></span>
                        </td>
                        <td class="text-secondary small">
                            <i class="bi bi-clock me-1"></i> <?= date("d/m/Y H:i:s", strtotime($r["waktu_aktivitas"])) ?>
                        </td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
        <div class="d-flex justify-content-between align-items-center mt-3 pt-3 border-top">
            <span class="small text-secondary">Halaman <?= $page ?> dari <?= $totalPages ?> (Total <?= $totalRows ?> log)</span>
            <div class="btn-group btn-group-sm">
                <?php if ($page > 1): ?>
                    <a href="?page=<?= $page - 1 ?>&q=<?= urlencode($q) ?>" class="btn btn-outline-secondary">Sebelumnya</a>
                <?php endif; ?>
                <?php if ($page < $totalPages): ?>
                    <a href="?page=<?= $page + 1 ?>&q=<?= urlencode($q) ?>" class="btn btn-outline-secondary">Selanjutnya</a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php page_footer(); ?>
