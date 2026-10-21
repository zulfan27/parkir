<?php
require_once __DIR__ . "/../includes/bootstrap.php";
require_role(["admin"]);

// 1. Filter parameters
$tglMulai = post("tgl_mulai", $_GET["tgl_mulai"] ?? date("Y-m-01"));
$tglAkhir = post("tgl_akhir", $_GET["tgl_akhir"] ?? date("Y-m-d"));
if (!valid_date($tglMulai)) $tglMulai = date("Y-m-01");
if (!valid_date($tglAkhir)) $tglAkhir = date("Y-m-d");

$status = trim($_GET["status"] ?? "");
$jenis = trim($_GET["jenis"] ?? "");
$idPetugas = get_int("petugas");
$q = trim($_GET["q"] ?? "");

// Export CSV handler
if (isset($_GET["export"]) && $_GET["export"] === "csv") {
    header("Content-Type: text/csv; charset=utf-8");
    header("Content-Disposition: attachment; filename=Laporan_ParkirKu_{$tglMulai}_{$tglAkhir}.csv");
    $out = fopen("php://output", "w");
    fputcsv($out, ["ID Transaksi", "Nomor Polisi", "Jenis Kendaraan", "Area", "Slot", "Waktu Masuk", "Waktu Keluar", "Durasi (Jam)", "Biaya Total (Rp)", "Status", "Petugas"]);

    $sqlCsv = "
        SELECT t.id_parkir, k.plat_nomor, k.jenis_kendaraan, a.nama_area, s.kode_slot,
               t.waktu_masuk, t.waktu_keluar, t.durasi_jam, t.biaya_total, t.status, u.nama_lengkap
        FROM tb_transaksi t
        JOIN tb_kendaraan k ON k.id_kendaraan = t.id_kendaraan
        JOIN tb_area_parkir a ON a.id_area = t.id_area
        LEFT JOIN tb_slot_parkir s ON s.id_slot = t.id_slot
        JOIN tb_user u ON u.id_user = t.id_user
        WHERE t.waktu_masuk >= ? AND t.waktu_masuk < ?
        ORDER BY t.id_parkir DESC
    ";
    $endExc = date("Y-m-d 23:59:59", strtotime($tglAkhir));
    $sCsv = $db->prepare($sqlCsv);
    $sCsv->bind_param("ss", $tglMulai, $endExc);
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
            $row["status"],
            $row["nama_lengkap"]
        ]);
    }
    fclose($out);
    exit;
}

// Build query
$where = ["t.waktu_masuk >= ? AND t.waktu_masuk <= ?"];
$tglAkhirFull = $tglAkhir . " 23:59:59";
$params = [$tglMulai, $tglAkhirFull];
$types = "ss";

if ($status !== "" && in_array($status, ["masuk", "keluar"], true)) {
    $where[] = "t.status = ?";
    $params[] = $status;
    $types .= "s";
}

if ($jenis !== "" && in_array($jenis, ["motor", "mobil", "lainnya"], true)) {
    $where[] = "k.jenis_kendaraan = ?";
    $params[] = $jenis;
    $types .= "s";
}

if ($idPetugas > 0) {
    $where[] = "t.id_user = ?";
    $params[] = $idPetugas;
    $types .= "i";
}

if ($q !== "") {
    $where[] = "(k.plat_nomor LIKE ? OR s.kode_slot LIKE ? OR t.id_parkir = ?)";
    $like = "%{$q}%";
    $qId = (int)$q;
    $params[] = $like;
    $params[] = $like;
    $params[] = $qId;
    $types .= "ssi";
}

$whereSql = implode(" AND ", $where);

// Summary Stats
$summarySql = "
    SELECT 
        COUNT(*) AS total_trx,
        SUM(CASE WHEN t.status = 'masuk' THEN 1 ELSE 0 END) AS total_aktif,
        SUM(CASE WHEN t.status = 'keluar' THEN 1 ELSE 0 END) AS total_selesai,
        COALESCE(SUM(CASE WHEN t.status = 'keluar' THEN t.biaya_total ELSE 0 END), 0) AS total_pendapatan
    FROM tb_transaksi t
    JOIN tb_kendaraan k ON k.id_kendaraan = t.id_kendaraan
    LEFT JOIN tb_slot_parkir s ON s.id_slot = t.id_slot
    WHERE {$whereSql}
";
$sSum = $db->prepare($summarySql);
$sSum->bind_param($types, ...$params);
$sSum->execute();
$sumData = $sSum->get_result()->fetch_assoc();

// Pagination
$page = max(1, get_int("page") ?: 1);
$limit = 25;
$offset = ($page - 1) * $limit;
$totalRows = (int)$sumData["total_trx"];
$totalPages = max(1, (int)ceil($totalRows / $limit));

// Fetch records
$sql = "
    SELECT 
        t.id_parkir, k.plat_nomor, k.jenis_kendaraan, a.nama_area, s.kode_slot,
        t.waktu_masuk, t.waktu_keluar, t.durasi_jam, t.biaya_total, t.status,
        u.nama_lengkap AS nama_petugas
    FROM tb_transaksi t
    JOIN tb_kendaraan k ON k.id_kendaraan = t.id_kendaraan
    JOIN tb_area_parkir a ON a.id_area = t.id_area
    LEFT JOIN tb_slot_parkir s ON s.id_slot = t.id_slot
    JOIN tb_user u ON u.id_user = t.id_user
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

// Fetch officers for filter
$officers = $db->query("SELECT id_user, nama_lengkap FROM tb_user WHERE role IN ('petugas', 'admin') ORDER BY nama_lengkap");

page_header("Laporan & Analitik Transaksi");
?>

<!-- Filter Card -->
<div class="pk-card p-4 mb-4">
    <form method="get" class="row g-3">
        <div class="col-md-3 col-sm-6">
            <label class="form-label small fw-bold text-secondary">Tanggal Mulai</label>
            <input type="date" class="form-control" name="tgl_mulai" value="<?= e($tglMulai) ?>" required>
        </div>
        <div class="col-md-3 col-sm-6">
            <label class="form-label small fw-bold text-secondary">Tanggal Akhir</label>
            <input type="date" class="form-control" name="tgl_akhir" value="<?= e($tglAkhir) ?>" required>
        </div>
        <div class="col-md-2 col-sm-4">
            <label class="form-label small fw-bold text-secondary">Status</label>
            <select class="form-select" name="status">
                <option value="">Semua Status</option>
                <option value="keluar" <?= $status === "keluar" ? "selected" : "" ?>>Selesai (Keluar)</option>
                <option value="masuk" <?= $status === "masuk" ? "selected" : "" ?>>Aktif (Masuk)</option>
            </select>
        </div>
        <div class="col-md-2 col-sm-4">
            <label class="form-label small fw-bold text-secondary">Jenis</label>
            <select class="form-select" name="jenis">
                <option value="">Semua Jenis</option>
                <option value="mobil" <?= $jenis === "mobil" ? "selected" : "" ?>>Mobil</option>
                <option value="motor" <?= $jenis === "motor" ? "selected" : "" ?>>Motor</option>
            </select>
        </div>
        <div class="col-md-2 col-sm-4">
            <label class="form-label small fw-bold text-secondary">Petugas</label>
            <select class="form-select" name="petugas">
                <option value="0">Semua Petugas</option>
                <?php while ($off = $officers->fetch_assoc()): ?>
                    <option value="<?= $off['id_user'] ?>" <?= $idPetugas == $off['id_user'] ? 'selected' : '' ?>>
                        <?= e($off['nama_lengkap']) ?>
                    </option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="col-md-8 col-sm-12">
            <div class="input-group">
                <span class="input-group-text bg-light"><i class="bi bi-search"></i></span>
                <input class="form-control" name="q" placeholder="Cari nomor plat, ID transaksi, atau kode slot..." value="<?= e($q) ?>">
            </div>
        </div>
        <div class="col-md-4 col-sm-12 d-flex gap-2">
            <button class="btn btn-primary flex-grow-1"><i class="bi bi-funnel"></i> Terapkan Filter</button>
            <button type="button" class="btn btn-outline-secondary" onclick="window.print()" title="Cetak Laporan"><i class="bi bi-printer"></i></button>
            <a href="?export=csv&tgl_mulai=<?= urlencode($tglMulai) ?>&tgl_akhir=<?= urlencode($tglAkhir) ?>" class="btn btn-outline-success" title="Ekspor CSV / Excel"><i class="bi bi-file-earmark-spreadsheet"></i> CSV</a>
        </div>
    </form>
</div>

<!-- Summary Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="stat-widget">
            <div>
                <div class="stat-title">Total Transaksi</div>
                <div class="stat-value text-dark"><?= number_format($sumData["total_trx"]) ?></div>
                <div class="stat-sub">Sesuai rentang filter</div>
            </div>
            <div class="stat-icon stat-icon-blue"><i class="bi bi-receipt"></i></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-widget">
            <div>
                <div class="stat-title">Total Pendapatan</div>
                <div class="stat-value text-success fs-5"><?= rupiah($sumData["total_pendapatan"]) ?></div>
                <div class="stat-sub">Transaksi selesai</div>
            </div>
            <div class="stat-icon stat-icon-green"><i class="bi bi-cash-stack"></i></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-widget">
            <div>
                <div class="stat-title">Transaksi Selesai</div>
                <div class="stat-value text-primary"><?= number_format($sumData["total_selesai"]) ?></div>
                <div class="stat-sub">Kendaraan sudah keluar</div>
            </div>
            <div class="stat-icon stat-icon-purple"><i class="bi bi-check2-all"></i></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-widget">
            <div>
                <div class="stat-title">Transaksi Aktif</div>
                <div class="stat-value text-danger"><?= number_format($sumData["total_aktif"]) ?></div>
                <div class="stat-sub">Masih di area parkir</div>
            </div>
            <div class="stat-icon stat-icon-red"><i class="bi bi-p-circle-fill"></i></div>
        </div>
    </div>
</div>

<!-- Report Table -->
<div class="pk-card p-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="fw-bold mb-0">Rincian Transaksi Parkir</h5>
        <span class="small text-secondary">Periode: <strong><?= date("d/m/Y", strtotime($tglMulai)) ?></strong> s/d <strong><?= date("d/m/Y", strtotime($tglAkhir)) ?></strong></span>
    </div>

    <div class="table-responsive">
        <table class="table pk-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Nomor Plat</th>
                    <th>Jenis</th>
                    <th>Slot & Area</th>
                    <th>Waktu Masuk</th>
                    <th>Waktu Keluar</th>
                    <th>Durasi</th>
                    <th>Total Biaya</th>
                    <th>Status</th>
                    <th>Petugas</th>
                    <th class="text-end no-print">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($res->num_rows === 0): ?>
                    <tr>
                        <td colspan="11" class="text-center py-4 text-muted">Tidak ditemukan transaksi pada rentang filter ini.</td>
                    </tr>
                <?php endif; ?>
                <?php while ($r = $res->fetch_assoc()): ?>
                    <tr>
                        <td class="font-monospace text-secondary">#<?= $r["id_parkir"] ?></td>
                        <td class="font-monospace fw-bold fs-6"><?= e($r["plat_nomor"]) ?></td>
                        <td>
                            <span class="badge <?= $r["jenis_kendaraan"] === 'motor' ? 'bg-info text-dark' : 'bg-primary' ?>">
                                <?= ucfirst($r["jenis_kendaraan"]) ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge bg-dark font-monospace"><?= e($r["kode_slot"] ?: "-") ?></span>
                            <small class="text-secondary d-block"><?= e($r["nama_area"]) ?></small>
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
                        <td class="small"><?= e($r["nama_petugas"]) ?></td>
                        <td class="text-end no-print">
                            <a href="<?= e(url("petugas/struk.php?id=" . $r["id_parkir"])) ?>" target="_blank" class="btn btn-sm btn-outline-primary" title="Cetak Struk">
                                <i class="bi bi-printer"></i>
                            </a>
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
                    <a href="?page=<?= $page - 1 ?>&tgl_mulai=<?= urlencode($tglMulai) ?>&tgl_akhir=<?= urlencode($tglAkhir) ?>&status=<?= urlencode($status) ?>&jenis=<?= urlencode($jenis) ?>&petugas=<?= $idPetugas ?>&q=<?= urlencode($q) ?>" class="btn btn-outline-secondary">Sebelumnya</a>
                <?php endif; ?>
                <?php if ($page < $totalPages): ?>
                    <a href="?page=<?= $page + 1 ?>&tgl_mulai=<?= urlencode($tglMulai) ?>&tgl_akhir=<?= urlencode($tglAkhir) ?>&status=<?= urlencode($status) ?>&jenis=<?= urlencode($jenis) ?>&petugas=<?= $idPetugas ?>&q=<?= urlencode($q) ?>" class="btn btn-outline-secondary">Selanjutnya</a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php page_footer(); ?>

