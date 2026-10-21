<?php
require_once __DIR__ . "/../includes/bootstrap.php";
require_role(["admin"]);

$today = date("Y-m-d");
$thisMonth = date("Y-m");

// 1. Slot breakdown stats
$slotStats = $db->query("
    SELECT 
        COUNT(*) AS total_slot,
        SUM(CASE WHEN jenis_slot = 'mobil' THEN 1 ELSE 0 END) AS mobil_total,
        SUM(CASE WHEN jenis_slot = 'mobil' AND status_slot = 'terisi' THEN 1 ELSE 0 END) AS mobil_terisi,
        SUM(CASE WHEN jenis_slot = 'mobil' AND status_slot = 'tersedia' THEN 1 ELSE 0 END) AS mobil_kosong,
        SUM(CASE WHEN jenis_slot = 'motor' THEN 1 ELSE 0 END) AS motor_total,
        SUM(CASE WHEN jenis_slot = 'motor' AND status_slot = 'terisi' THEN 1 ELSE 0 END) AS motor_terisi,
        SUM(CASE WHEN jenis_slot = 'motor' AND status_slot = 'tersedia' THEN 1 ELSE 0 END) AS motor_kosong,
        SUM(CASE WHEN status_slot = 'tersedia' THEN 1 ELSE 0 END) AS slot_tersedia,
        SUM(CASE WHEN status_slot = 'terisi' THEN 1 ELSE 0 END) AS slot_terisi,
        SUM(CASE WHEN status_slot = 'nonaktif' THEN 1 ELSE 0 END) AS slot_nonaktif
    FROM tb_slot_parkir
")->fetch_assoc();

$totalSlot = (int)($slotStats["total_slot"] ?? 0);
$slotTersedia = (int)($slotStats["slot_tersedia"] ?? 0);
$slotTerisi = (int)($slotStats["slot_terisi"] ?? 0);
$slotNonaktif = (int)($slotStats["slot_nonaktif"] ?? 0);
$persenTerisi = $totalSlot > 0 ? round(($slotTerisi / $totalSlot) * 100, 1) : 0;

$mobilTotal = (int)($slotStats["mobil_total"] ?? 0);
$mobilTerisi = (int)($slotStats["mobil_terisi"] ?? 0);
$mobilKosong = (int)($slotStats["mobil_kosong"] ?? 0);
$persenMobil = $mobilTotal > 0 ? round(($mobilTerisi / $mobilTotal) * 100, 1) : 0;

$motorTotal = (int)($slotStats["motor_total"] ?? 0);
$motorTerisi = (int)($slotStats["motor_terisi"] ?? 0);
$motorKosong = (int)($slotStats["motor_kosong"] ?? 0);
$persenMotor = $motorTotal > 0 ? round(($motorTerisi / $motorTotal) * 100, 1) : 0;

// 2. Transactions stats
$trxStats = $db->query("
    SELECT 
        SUM(CASE WHEN status = 'masuk' THEN 1 ELSE 0 END) AS kendaraan_aktif,
        SUM(CASE WHEN DATE(waktu_masuk) = '{$today}' THEN 1 ELSE 0 END) AS masuk_hari_ini,
        SUM(CASE WHEN DATE(waktu_keluar) = '{$today}' AND status = 'keluar' THEN 1 ELSE 0 END) AS keluar_hari_ini,
        COALESCE(SUM(CASE WHEN DATE(waktu_keluar) = '{$today}' AND status = 'keluar' THEN biaya_total ELSE 0 END), 0) AS pendapatan_hari_ini,
        COALESCE(SUM(CASE WHEN DATE_FORMAT(waktu_keluar, '%Y-%m') = '{$thisMonth}' AND status = 'keluar' THEN biaya_total ELSE 0 END), 0) AS pendapatan_bulan_ini
    FROM tb_transaksi
")->fetch_assoc();

$kendaraanAktif = (int)($trxStats["kendaraan_aktif"] ?? 0);
$masukHariIni = (int)($trxStats["masuk_hari_ini"] ?? 0);
$keluarHariIni = (int)($trxStats["keluar_hari_ini"] ?? 0);
$pendapatanHariIni = (float)($trxStats["pendapatan_hari_ini"] ?? 0);
$pendapatanBulanIni = (float)($trxStats["pendapatan_bulan_ini"] ?? 0);

// 3. Data for 7-day trend chart
$chartDays = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date("Y-m-d", strtotime("-{$i} days"));
    $chartDays[$d] = [
        'tanggal' => date("d M", strtotime($d)),
        'masuk' => 0,
        'keluar' => 0,
        'pendapatan' => 0
    ];
}

$start7 = date("Y-m-d 00:00:00", strtotime("-6 days"));
$resTrx7 = $db->query("
    SELECT DATE(waktu_masuk) as tgl, COUNT(*) as jml 
    FROM tb_transaksi 
    WHERE waktu_masuk >= '{$start7}' 
    GROUP BY DATE(waktu_masuk)
");
while ($r = $resTrx7->fetch_assoc()) {
    if (isset($chartDays[$r['tgl']])) {
        $chartDays[$r['tgl']]['masuk'] = (int)$r['jml'];
    }
}

$resKeluar7 = $db->query("
    SELECT DATE(waktu_keluar) as tgl, COUNT(*) as jml, SUM(biaya_total) as total 
    FROM tb_transaksi 
    WHERE waktu_keluar >= '{$start7}' AND status='keluar' 
    GROUP BY DATE(waktu_keluar)
");
while ($r = $resKeluar7->fetch_assoc()) {
    if (isset($chartDays[$r['tgl']])) {
        $chartDays[$r['tgl']]['keluar'] = (int)$r['jml'];
        $chartDays[$r['tgl']]['pendapatan'] = (float)$r['total'];
    }
}

// 4. Area breakdown data for Doughnut chart
$areaChartData = [];
$resArea = $db->query("SELECT nama_area, kapasitas, terisi FROM tb_area_parkir ORDER BY id_area");
while ($a = $resArea->fetch_assoc()) {
    $areaChartData[] = [
        'nama' => $a['nama_area'],
        'kapasitas' => (int)$a['kapasitas'],
        'terisi' => (int)$a['terisi'],
        'sisa' => max(0, (int)$a['kapasitas'] - (int)$a['terisi'])
    ];
}

// 5. Recent Transactions (with color & vehicle details)
$recentTrx = $db->query("
    SELECT t.id_parkir, k.plat_nomor, k.jenis_kendaraan, k.warna, a.nama_area, s.kode_slot, t.waktu_masuk, t.waktu_keluar, t.biaya_total, t.status 
    FROM tb_transaksi t
    JOIN tb_kendaraan k ON k.id_kendaraan = t.id_kendaraan
    JOIN tb_area_parkir a ON a.id_area = t.id_area
    LEFT JOIN tb_slot_parkir s ON s.id_slot = t.id_slot
    ORDER BY t.id_parkir DESC 
    LIMIT 6
");

// 6. Recent Logs
$recentLogs = $db->query("
    SELECT l.*, u.nama_lengkap, u.role
    FROM tb_log_aktivitas l 
    JOIN tb_user u ON u.id_user = l.id_user 
    ORDER BY l.id_log DESC 
    LIMIT 6
");

page_header("Dashboard Admin", "admin/index.php");
?>

<!-- Header Top Toolbar -->
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
    <div>
        <h4 class="fw-bold mb-1 text-dark d-flex align-items-center gap-2">
            <i class="bi bi-speedometer2 text-primary"></i> Dashboard Admin
        </h4>
        <div class="text-secondary small">
            Ringkasan operasional, kapasitas, dan kinerja finansial sistem ParkirKu
        </div>
    </div>
    <div class="d-flex align-items-center gap-2 flex-wrap">
        <div class="badge bg-white text-dark border px-3 py-2 d-none d-md-flex align-items-center gap-2 shadow-sm">
            <i class="bi bi-calendar3 text-primary"></i> <?= date("d F Y") ?>
            <span class="text-muted">|</span>
            <i class="bi bi-clock text-primary"></i> <?= date("H:i") ?> WIB
        </div>
        <a href="<?= e(url("denah.php")) ?>" class="btn btn-primary fw-semibold shadow-sm d-flex align-items-center gap-2">
            <i class="bi bi-map-fill"></i> Buka Denah Parkir
        </a>
    </div>
</div>

<!-- Capacity Alert if Occupancy >= 85% -->
<?php if ($persenTerisi >= 85): ?>
<div class="alert alert-warning border-warning d-flex align-items-center gap-3 p-3 mb-4 shadow-sm rounded-3">
    <i class="bi bi-exclamation-triangle-fill fs-2 text-warning flex-shrink-0"></i>
    <div class="flex-grow-1">
        <h6 class="fw-bold mb-1 text-dark">Peringatan: Keterisian Lahan Parkir Mencapai <?= $persenTerisi ?>%!</h6>
        <p class="mb-0 small text-muted">Hanya tersisa <strong><?= $slotTersedia ?> slot kosong</strong>. Segera arahkan petugas pintu masuk untuk mengatur aliran kendaraan.</p>
    </div>
    <a href="<?= e(url("denah.php")) ?>" class="btn btn-sm btn-warning text-dark fw-bold">Cek Slot Kosong</a>
</div>
<?php endif; ?>

<!-- Primary 4 Statistics Cards (Matching Reference Visual Style) -->
<div class="row g-3 mb-3">
    <!-- Slot Mobil -->
    <div class="col-6 col-lg-3">
        <div class="stat-widget-ref">
            <div class="stat-circle-icon stat-circle-blue">
                <i class="bi bi-car-front-fill"></i>
            </div>
            <div class="flex-grow-1 overflow-hidden">
                <div class="stat-label">Slot Mobil</div>
                <div class="stat-num"><?= $mobilTotal ?></div>
                <div class="stat-info">
                    Terisi <strong class="text-danger"><?= $mobilTerisi ?></strong> | Kosong <strong class="text-success"><?= $mobilKosong ?></strong>
                </div>
                <div class="stat-progress-bar">
                    <div class="stat-progress-fill-red" style="width: <?= $persenMobil ?>%;"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Slot Motor -->
    <div class="col-6 col-lg-3">
        <div class="stat-widget-ref">
            <div class="stat-circle-icon stat-circle-green">
                <i class="bi bi-bicycle"></i>
            </div>
            <div class="flex-grow-1 overflow-hidden">
                <div class="stat-label">Slot Motor</div>
                <div class="stat-num"><?= $motorTotal ?></div>
                <div class="stat-info">
                    Terisi <strong class="text-danger"><?= $motorTerisi ?></strong> | Kosong <strong class="text-success"><?= $motorKosong ?></strong>
                </div>
                <div class="stat-progress-bar">
                    <div class="stat-progress-fill-red" style="width: <?= $persenMotor ?>%;"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Total Slot Parkir -->
    <div class="col-6 col-lg-3">
        <div class="stat-widget-ref">
            <div class="stat-circle-icon stat-circle-green">
                <i class="bi bi-building-check"></i>
            </div>
            <div class="flex-grow-1 overflow-hidden">
                <div class="stat-label">Total Slot</div>
                <div class="stat-num"><?= $totalSlot ?></div>
                <div class="stat-info">
                    Terisi <strong class="text-danger"><?= $slotTerisi ?></strong> | Kosong <strong class="text-success"><?= $slotTersedia ?></strong>
                </div>
                <div class="stat-progress-bar">
                    <div class="stat-progress-fill-green" style="width: <?= 100 - $persenTerisi ?>%;"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Pendapatan Hari Ini -->
    <div class="col-6 col-lg-3">
        <div class="stat-widget-ref">
            <div class="stat-circle-icon stat-circle-navy">
                <i class="bi bi-receipt-cutoff"></i>
            </div>
            <div class="flex-grow-1 overflow-hidden">
                <div class="stat-label">Pendapatan Hari Ini</div>
                <div class="stat-num text-dark fs-5"><?= rupiah($pendapatanHariIni) ?></div>
                <div class="stat-info text-success fw-semibold">
                    <i class="bi bi-arrow-up-right"></i> Aktual transaksi keluar
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Secondary 4 Operational Metrics Row -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="stat-widget">
            <div class="stat-circle-icon stat-circle-purple">
                <i class="bi bi-p-circle-fill"></i>
            </div>
            <div class="flex-grow-1 overflow-hidden">
                <div class="stat-label">Sedang Parkir</div>
                <div class="stat-num text-primary"><?= $kendaraanAktif ?></div>
                <div class="stat-info">Transaksi aktif berlangsung</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-widget">
            <div class="stat-circle-icon stat-circle-blue">
                <i class="bi bi-box-arrow-in-right"></i>
            </div>
            <div class="flex-grow-1 overflow-hidden">
                <div class="stat-label">Masuk Hari Ini</div>
                <div class="stat-num text-dark"><?= $masukHariIni ?></div>
                <div class="stat-info">Total check-in kendaraan</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-widget">
            <div class="stat-circle-icon stat-circle-red">
                <i class="bi bi-box-arrow-right"></i>
            </div>
            <div class="flex-grow-1 overflow-hidden">
                <div class="stat-label">Keluar Hari Ini</div>
                <div class="stat-num text-dark"><?= $keluarHariIni ?></div>
                <div class="stat-info">Total check-out selesai</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-widget">
            <div class="stat-circle-icon stat-circle-cyan">
                <i class="bi bi-wallet2"></i>
            </div>
            <div class="flex-grow-1 overflow-hidden">
                <div class="stat-label">Pendapatan Bulan Ini</div>
                <div class="stat-num text-primary fs-5"><?= rupiah($pendapatanBulanIni) ?></div>
                <div class="stat-info">Bulan <?= date("F Y") ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Dashboard Quick Actions & Interactive Map Banner -->
<div class="dashboard-hero mb-4 d-flex align-items-center justify-content-between flex-wrap gap-3">
    <div>
        <div class="d-inline-flex align-items-center gap-2 px-2 py-1 rounded bg-white bg-opacity-10 text-white small mb-2">
            <i class="bi bi-broadcast text-warning"></i> <span>Live Interactive Layout Engine</span>
        </div>
        <h5 class="fw-bold mb-1">Visualisasi Denah Parkir 2D Berdasarkan Database Nyata</h5>
        <p class="text-white-50 mb-0 small" style="max-width: 650px;">
            Pantau posisi kendaraan, warna bodi kendaraan sesuai database, status slot terisi / kosong secara visual, dan alokasikan kendaraan secara langsung melalui peta interaktif.
        </p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="<?= e(url("denah.php")) ?>" class="btn btn-warning fw-bold text-dark px-3 py-2 shadow-sm">
            <i class="bi bi-map-fill me-1"></i> Buka Denah Parkir
        </a>
        <a href="<?= e(url("admin/slot.php")) ?>" class="btn btn-outline-light px-3 py-2">
            <i class="bi bi-grid-3x3 me-1"></i> Kelola Slot
        </a>
        <a href="<?= e(url("admin/laporan.php")) ?>" class="btn btn-outline-light px-3 py-2">
            <i class="bi bi-file-earmark-bar-graph me-1"></i> Laporan
        </a>
    </div>
</div>

<!-- Charts Row -->
<div class="row g-4 mb-4">
    <!-- Traffic Trend Chart -->
    <div class="col-lg-8">
        <div class="pk-card p-4 h-100">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div>
                    <h5 class="fw-bold mb-0 text-dark">
                        <i class="bi bi-bar-chart-line-fill text-primary me-2"></i> Arus Kendaraan Masuk vs Keluar
                    </h5>
                    <small class="text-secondary">Statistik volume lalu lintas 7 hari terakhir</small>
                </div>
                <span class="badge bg-light text-secondary border px-2 py-1">7 Hari Terakhir</span>
            </div>
            <div style="position: relative; height: 280px;">
                <canvas id="trafficChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Area Capacity Doughnut Chart -->
    <div class="col-lg-4">
        <div class="pk-card p-4 h-100 d-flex flex-direction-column justify-content-between">
            <div>
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="fw-bold mb-0 text-dark">
                        <i class="bi bi-pie-chart-fill text-primary me-2"></i> Okupansi Area
                    </h5>
                    <span class="badge bg-primary-subtle text-primary fw-semibold"><?= $totalSlot ?> Slot</span>
                </div>
                <div style="position: relative; height: 210px;">
                    <canvas id="areaChart"></canvas>
                </div>
            </div>
            <div class="mt-3 pt-3 border-top">
                <?php foreach ($areaChartData as $ar): ?>
                    <?php 
                        $pct = $ar['kapasitas'] > 0 ? round(($ar['terisi'] / $ar['kapasitas']) * 100) : 0;
                    ?>
                    <div class="mb-2">
                        <div class="d-flex justify-content-between small mb-1">
                            <span class="fw-semibold text-dark"><?= e($ar['nama']) ?></span>
                            <span class="text-muted"><?= $ar['terisi'] ?> / <?= $ar['kapasitas'] ?> (<?= $pct ?>%)</span>
                        </div>
                        <div class="progress" style="height: 6px;">
                            <div class="progress-bar <?= $pct >= 85 ? 'bg-danger' : ($pct >= 50 ? 'bg-warning' : 'bg-success') ?>" style="width: <?= $pct ?>%;"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<!-- Recent Transactions & Activity Logs Row -->
<div class="row g-4">
    <!-- Recent Transactions -->
    <div class="col-lg-7">
        <div class="pk-card p-4 h-100">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div>
                    <h5 class="fw-bold mb-0 text-dark">
                        <i class="bi bi-clock-history text-primary me-2"></i> Transaksi Terkini
                    </h5>
                    <small class="text-secondary">Daftar keluar masuk kendaraan terbaru</small>
                </div>
                <a href="<?= e(url("admin/laporan.php")) ?>" class="btn btn-sm btn-outline-primary fw-semibold">
                    Lihat Semua
                </a>
            </div>
            <div class="table-responsive">
                <table class="table pk-table align-middle">
                    <thead>
                        <tr>
                            <th>Plat Nomor</th>
                            <th>Jenis & Warna</th>
                            <th>Slot / Area</th>
                            <th>Waktu Masuk</th>
                            <th>Status</th>
                            <th>Biaya</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($recentTrx->num_rows === 0): ?>
                            <tr>
                                <td colspan="6" class="text-center py-4 text-muted">Belum ada data transaksi tercatat.</td>
                            </tr>
                        <?php endif; ?>
                        <?php while ($t = $recentTrx->fetch_assoc()): ?>
                            <tr>
                                <td>
                                    <span class="badge-plate">
                                        <?= e($t["plat_nomor"]) ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="small fw-semibold text-dark">
                                        <?= ucfirst($t["jenis_kendaraan"]) ?>
                                    </div>
                                    <div class="small text-muted d-flex align-items-center">
                                        <span class="color-dot" style="background-color: <?= warna_hex($t['warna']) ?>;"></span>
                                        <?= e($t["warna"] ?: "Hitam") ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge bg-dark font-monospace"><?= e($t["kode_slot"] ?: "-") ?></span>
                                    <small class="text-secondary d-block"><?= e($t["nama_area"]) ?></small>
                                </td>
                                <td class="small text-muted font-monospace">
                                    <?= date("d/m H:i", strtotime($t["waktu_masuk"])) ?>
                                </td>
                                <td>
                                    <span class="badge-pk <?= $t["status"] === 'masuk' ? 'badge-pk-danger' : 'badge-pk-success' ?>">
                                        <?= $t["status"] === 'masuk' ? 'Sedang Parkir' : 'Selesai' ?>
                                    </span>
                                </td>
                                <td class="fw-bold text-dark font-monospace">
                                    <?= $t["status"] === 'keluar' ? rupiah($t["biaya_total"]) : '<span class="text-muted small">Berjalan</span>' ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Recent Activity Logs -->
    <div class="col-lg-5">
        <div class="pk-card p-4 h-100">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div>
                    <h5 class="fw-bold mb-0 text-dark">
                        <i class="bi bi-shield-check text-primary me-2"></i> Log Aktivitas Sistem
                    </h5>
                    <small class="text-secondary">Riwayat aksi pengguna terbaru</small>
                </div>
                <a href="<?= e(url("admin/log.php")) ?>" class="btn btn-sm btn-outline-secondary fw-semibold">
                    Log Lengkap
                </a>
            </div>
            <div class="table-responsive">
                <table class="table pk-table align-middle">
                    <tbody>
                        <?php if ($recentLogs->num_rows === 0): ?>
                            <tr>
                                <td class="text-center py-4 text-muted">Belum ada aktivitas tercatat.</td>
                            </tr>
                        <?php endif; ?>
                        <?php while ($l = $recentLogs->fetch_assoc()): ?>
                            <tr>
                                <td class="py-2">
                                    <div class="d-flex align-items-center gap-2 mb-1">
                                        <div class="stat-circle-icon stat-circle-blue" style="width: 28px; height: 28px; font-size: 0.85rem;">
                                            <i class="bi bi-person-fill"></i>
                                        </div>
                                        <div class="flex-grow-1 overflow-hidden">
                                            <div class="fw-semibold text-dark small text-truncate"><?= e($l["nama_lengkap"]) ?></div>
                                            <span class="badge bg-light text-secondary border" style="font-size: 0.65rem;"><?= ucfirst($l["role"]) ?></span>
                                        </div>
                                        <div class="text-muted small font-monospace" style="font-size: 0.725rem;">
                                            <?= date("d/m H:i", strtotime($l["waktu_aktivitas"])) ?>
                                        </div>
                                    </div>
                                    <div class="text-secondary small ps-4 ms-2">
                                        <?= e($l["aktivitas"]) ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // 1. Traffic Chart (Masuk vs Keluar)
    const trafficData = <?= json_encode(array_values($chartDays)) ?>;
    const labels = trafficData.map(d => d.tanggal);
    const masukData = trafficData.map(d => d.masuk);
    const keluarData = trafficData.map(d => d.keluar);

    const ctxTraffic = document.getElementById('trafficChart')?.getContext('2d');
    if (ctxTraffic) {
        new Chart(ctxTraffic, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Kendaraan Masuk',
                        data: masukData,
                        backgroundColor: '#2563eb',
                        borderRadius: 6,
                        maxBarThickness: 28
                    },
                    {
                        label: 'Kendaraan Keluar',
                        data: keluarData,
                        backgroundColor: '#10b981',
                        borderRadius: 6,
                        maxBarThickness: 28
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                        labels: {
                            usePointStyle: true,
                            font: { family: "'Plus Jakarta Sans', sans-serif", size: 12 }
                        }
                    },
                    tooltip: {
                        padding: 10,
                        cornerRadius: 8
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { stepSize: 1 },
                        grid: { color: '#f1f5f9' }
                    },
                    x: {
                        grid: { display: false }
                    }
                }
            }
        });
    }

    // 2. Area Capacity Doughnut Chart
    const areaData = <?= json_encode($areaChartData) ?>;
    const areaLabels = areaData.map(a => a.nama);
    const areaTerisi = areaData.map(a => a.terisi);

    const ctxArea = document.getElementById('areaChart')?.getContext('2d');
    if (ctxArea) {
        new Chart(ctxArea, {
            type: 'doughnut',
            data: {
                labels: areaLabels,
                datasets: [{
                    data: areaTerisi,
                    backgroundColor: ['#2563eb', '#10b981', '#f59e0b', '#8b5cf6', '#ec4899'],
                    borderWidth: 2,
                    hoverOffset: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            usePointStyle: true,
                            font: { family: "'Plus Jakarta Sans', sans-serif", size: 11 }
                        }
                    }
                },
                cutout: '65%'
            }
        });
    }
});
</script>

<?php page_footer(); ?>
