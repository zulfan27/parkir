<?php
require_once __DIR__ . "/../includes/bootstrap.php";
require_role(["owner"]);

// Filter parameters
$awal = post("awal", $_GET["awal"] ?? date("Y-m-01"));
$akhir = post("akhir", $_GET["akhir"] ?? date("Y-m-d"));
if (!valid_date($awal)) $awal = date("Y-m-01");
if (!valid_date($akhir)) $akhir = date("Y-m-d");

$jenis = trim($_GET["jenis"] ?? "");
$status = trim($_GET["status"] ?? "");
$q = trim($_GET["q"] ?? "");

// Export CSV handler
if (isset($_GET["export"]) && $_GET["export"] === "csv") {
    header("Content-Type: text/csv; charset=utf-8");
    header("Content-Disposition: attachment; filename=Rekap_Pendapatan_{$awal}_{$akhir}.csv");
    $out = fopen("php://output", "w");
    fputcsv($out, ["ID Transaksi", "Nomor Polisi", "Jenis Kendaraan", "Area", "Slot", "Waktu Masuk", "Waktu Keluar", "Durasi (Jam)", "Biaya Total (Rp)", "Status"]);

    $sqlCsv = "
        SELECT t.id_parkir, k.plat_nomor, k.jenis_kendaraan, a.nama_area, s.kode_slot,
               t.waktu_masuk, t.waktu_keluar, t.durasi_jam, t.biaya_total, t.status
        FROM tb_transaksi t
        JOIN tb_kendaraan k ON k.id_kendaraan = t.id_kendaraan
        JOIN tb_area_parkir a ON a.id_area = t.id_area
        LEFT JOIN tb_slot_parkir s ON s.id_slot = t.id_slot
        WHERE t.waktu_masuk >= ? AND t.waktu_masuk < ?
        ORDER BY t.id_parkir DESC
    ";
    $endExc = date("Y-m-d 23:59:59", strtotime($akhir));
    $sCsv = $db->prepare($sqlCsv);
    $sCsv->bind_param("ss", $awal, $endExc);
    $sCsv->execute();
    $resCsv = $sCsv->get_result();
    while ($row = $resCsv->fetch_assoc()) {
        fputcsv($out, [
            $row["id_parkir"],
            $row["plat_nomor"],
            $row["jenis_kendaraan"],
            $row["nama_area"],
            $row["kode_slot"] ?: "-",
            $row["waktu_masuk"],
            $row["waktu_keluar"] ?: "Belum keluar",
            $row["durasi_jam"] ?: 0,
            $row["biaya_total"] ?: 0,
            $row["status"]
        ]);
    }
    fclose($out);
    exit;
}

// Build query
$where = ["t.waktu_masuk >= ? AND t.waktu_masuk <= ?"];
$akhirFull = $akhir . " 23:59:59";
$params = [$awal, $akhirFull];
$types = "ss";

if ($jenis !== "" && in_array($jenis, ["motor", "mobil", "lainnya"], true)) {
    $where[] = "k.jenis_kendaraan = ?";
    $params[] = $jenis;
    $types .= "s";
}

if ($status !== "" && in_array($status, ["masuk", "keluar"], true)) {
    $where[] = "t.status = ?";
    $params[] = $status;
    $types .= "s";
}

if ($q !== "") {
    $where[] = "(k.plat_nomor LIKE ? OR s.kode_slot LIKE ?)";
    $like = "%{$q}%";
    $params[] = $like;
    $params[] = $like;
    $types .= "ss";
}

$whereSql = implode(" AND ", $where);

// Summary statistics
$sumSql = "
    SELECT 
        COUNT(*) AS total_trx,
        SUM(CASE WHEN t.status = 'keluar' THEN 1 ELSE 0 END) AS selesai,
        SUM(CASE WHEN t.status = 'masuk' THEN 1 ELSE 0 END) AS aktif,
        COALESCE(SUM(CASE WHEN t.status = 'keluar' THEN t.biaya_total ELSE 0 END), 0) AS pendapatan
    FROM tb_transaksi t
    JOIN tb_kendaraan k ON k.id_kendaraan = t.id_kendaraan
    LEFT JOIN tb_slot_parkir s ON s.id_slot = t.id_slot
    WHERE {$whereSql}
";
$sSum = $db->prepare($sumSql);
$sSum->bind_param($types, ...$params);
$sSum->execute();
$sumData = $sSum->get_result()->fetch_assoc();

// Pagination
$page = max(1, get_int("page") ?: 1);
$limit = 25;
$offset = ($page - 1) * $limit;
$totalRows = (int)$sumData["total_trx"];
$totalPages = max(1, (int)ceil($totalRows / $limit));

// Fetch transactions
$sql = "
    SELECT 
        t.id_parkir, k.plat_nomor, k.jenis_kendaraan, a.nama_area, s.kode_slot,
        t.waktu_masuk, t.waktu_keluar, t.durasi_jam, t.biaya_total, t.status
    FROM tb_transaksi t
    JOIN tb_kendaraan k ON k.id_kendaraan = t.id_kendaraan
    JOIN tb_area_parkir a ON a.id_area = t.id_area
    LEFT JOIN tb_slot_parkir s ON s.id_slot = t.id_slot
    WHERE {$whereSql}
    ORDER BY t.id_parkir DESC
    LIMIT ? OFFSET ?
";

$typesWithLimit = $types . "ii";
$paramsWithLimit = array_merge($params, [$limit, $offset]);

$stmt = $db->prepare($sql);
$stmt->bind_param($typesWithLimit, ...$paramsWithLimit);
$stmt->execute();
$res = $stmt->get_result();

page_header("Rekap Transaksi & Pendapatan");
?>

<!-- Filter Card -->
<div class="pk-card p-4 mb-4">
    <form method="get" class="row g-3">
        <div class="col-md-3 col-sm-6">
            <label class="form-label small fw-bold text-secondary">Tanggal Awal</label>
            <input type="date" class="form-control" name="awal" value="<?= e($awal) ?>" required>
        </div>
        <div class="col-md-3 col-sm-6">
            <label class="form-label small fw-bold text-secondary">Tanggal Akhir</label>
            <input type="date" class="form-control" name="akhir" value="<?= e($akhir) ?>" required>
        </div>
        <div class="col-md-3 col-sm-6">
            <label class="form-label small fw-bold text-secondary">Jenis Kendaraan</label>
            <select class="form-select" name="jenis">
                <option value="">Semua Jenis</option>
                <option value="mobil" <?= $jenis === 'mobil' ? 'selected' : '' ?>>Mobil</option>
                <option value="motor" <?= $jenis === 'motor' ? 'selected' : '' ?>>Motor</option>
            </select>
        </div>
        <div class="col-md-3 col-sm-6">
            <label class="form-label small fw-bold text-secondary">Status</label>
            <select class="form-select" name="status">
                <option value="">Semua Status</option>
                <option value="keluar" <?= $status === 'keluar' ? 'selected' : '' ?>>Selesai (Keluar)</option>
                <option value="masuk" <?= $status === 'masuk' ? 'selected' : '' ?>>Aktif (Masuk)</option>
            </select>
        </div>

        <div class="col-md-7 col-sm-12">
            <input class="form-control" name="q" placeholder="Cari nomor plat atau kode slot..." value="<?= e($q) ?>">
        </div>
        <div class="col-md-5 col-sm-12 d-flex gap-2">
            <button class="btn btn-primary flex-grow-1"><i class="bi bi-funnel"></i> Tampilkan</button>
            <button type="button" class="btn btn-outline-secondary" onclick="window.print()" title="Cetak Laporan"><i class="bi bi-printer"></i> Cetak</button>
            <a href="?export=csv&awal=<?= urlencode($awal) ?>&akhir=<?= urlencode($akhir) ?>" class="btn btn-outline-success" title="Ekspor CSV"><i class="bi bi-file-earmark-spreadsheet"></i> CSV</a>
        </div>
    </form>
</div>

<!-- Summary Cards -->
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="stat-widget">
            <div>
                <div class="stat-title">Jumlah Transaksi</div>
                <div class="stat-value text-dark"><?= number_format($sumData["total_trx"]) ?></div>
                <div class="stat-sub"><?= $sumData["selesai"] ?> selesai, <?= $sumData["aktif"] ?> aktif</div>
            </div>
            <div class="stat-icon stat-icon-blue"><i class="bi bi-receipt"></i></div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-widget">
            <div>
                <div class="stat-title">Pendapatan Selesai</div>
                <div class="stat-value text-success fs-5"><?= rupiah($sumData["pendapatan"]) ?></div>
                <div class="stat-sub">Dana masuk terverifikasi</div>
            </div>
            <div class="stat-icon stat-icon-green"><i class="bi bi-cash-stack"></i></div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-widget">
            <div>
                <div class="stat-title">Periode Rekap</div>
                <div class="stat-value text-primary fs-6"><?= date("d M Y", strtotime($awal)) ?> - <?= date("d M Y", strtotime($akhir)) ?></div>
                <div class="stat-sub"><?= round((strtotime($akhir) - strtotime($awal)) / 86400) + 1 ?> Hari Kalender</div>
            </div>
            <div class="stat-icon stat-icon-purple"><i class="bi bi-calendar3"></i></div>
        </div>
    </div>
</div>

<!-- Table Card -->
<div class="pk-card p-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="fw-bold mb-0">Rincian Data Rekapitulasi</h5>
        <span class="small text-secondary">Menampilkan data sesuai filter waktu masuk</span>
    </div>

    <div class="table-responsive">
        <table class="table pk-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Plat Nomor</th>
                    <th>Jenis</th>
                    <th>Slot & Area</th>
                    <th>Masuk</th>
                    <th>Keluar</th>
                    <th>Durasi</th>
                    <th>Biaya</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($res->num_rows === 0): ?>
                    <tr>
                        <td colspan="9" class="text-center py-4 text-muted">Tidak ditemukan transaksi pada rentang filter ini.</td>
                    </tr>
                <?php endif; ?>
                <?php while ($r = $res->fetch_assoc()): ?>
                    <tr>
                        <td class="font-monospace text-secondary">#<?= $r["id_parkir"] ?></td>
                        <td class="font-monospace fw-bold"><?= e($r["plat_nomor"]) ?></td>
                        <td>
                            <span class="badge <?= $r["jenis_kendaraan"] === 'motor' ? 'bg-info text-dark' : 'bg-primary' ?>">
                                <?= ucfirst($r["jenis_kendaraan"]) ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge bg-dark font-monospace"><?= e($r["kode_slot"] ?: "-") ?></span>
                            <small class="text-secondary ms-1"><?= e($r["nama_area"]) ?></small>
                        </td>
                        <td class="small"><?= date("d/m/Y H:i", strtotime($r["waktu_masuk"])) ?></td>
                        <td class="small"><?= $r["waktu_keluar"] ? date("d/m/Y H:i", strtotime($r["waktu_keluar"])) : '<span class="text-muted">-</span>' ?></td>
                        <td><?= $r["durasi_jam"] ? $r["durasi_jam"] . " jam" : "-" ?></td>
                        <td class="fw-bold text-success">
                            <?= $r["status"] === 'keluar' ? rupiah($r["biaya_total"]) : '<span class="text-muted">-</span>' ?>
                        </td>
                        <td>
                            <span class="badge-pk <?= $r["status"] === 'keluar' ? 'badge-pk-success' : 'badge-pk-danger' ?>">
                                <?= ucfirst($r["status"]) ?>
                            </span>
                        </td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
        <div class="d-flex justify-content-between align-items-center mt-3 pt-3 border-top no-print">
            <span class="small text-secondary">Halaman <?= $page ?> dari <?= $totalPages ?> (Total <?= $totalRows ?> data)</span>
            <div class="btn-group btn-group-sm">
                <?php if ($page > 1): ?>
                    <a href="?page=<?= $page - 1 ?>&awal=<?= urlencode($awal) ?>&akhir=<?= urlencode($akhir) ?>&jenis=<?= urlencode($jenis) ?>&status=<?= urlencode($status) ?>&q=<?= urlencode($q) ?>" class="btn btn-outline-secondary">Sebelumnya</a>
                <?php endif; ?>
                <?php if ($page < $totalPages): ?>
                    <a href="?page=<?= $page + 1 ?>&awal=<?= urlencode($awal) ?>&akhir=<?= urlencode($akhir) ?>&jenis=<?= urlencode($jenis) ?>&status=<?= urlencode($status) ?>&q=<?= urlencode($q) ?>" class="btn btn-outline-secondary">Selanjutnya</a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php page_footer(); ?>
