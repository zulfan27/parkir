<?php
require_once __DIR__ . "/../includes/bootstrap.php";
require_role(["owner"]);

$today = date("Y-m-d");
$thisMonth = date("Y-m");

// 1. Financial and operational summary
$tot = $db->query("
    SELECT 
        COUNT(*) AS total_transaksi,
        SUM(CASE WHEN status = 'keluar' THEN 1 ELSE 0 END) AS transaksi_selesai,
        SUM(CASE WHEN status = 'masuk' THEN 1 ELSE 0 END) AS transaksi_aktif,
        COALESCE(SUM(CASE WHEN DATE(waktu_keluar) = '{$today}' AND status = 'keluar' THEN biaya_total ELSE 0 END), 0) AS pendapatan_hari_ini,
        COALESCE(SUM(CASE WHEN DATE_FORMAT(waktu_keluar, '%Y-%m') = '{$thisMonth}' AND status = 'keluar' THEN biaya_total ELSE 0 END), 0) AS pendapatan_bulan_ini,
        COALESCE(SUM(CASE WHEN status = 'keluar' THEN biaya_total ELSE 0 END), 0) AS total_pendapatan,
        COALESCE(AVG(CASE WHEN status = 'keluar' THEN durasi_jam ELSE NULL END), 0) AS avg_durasi,
        COALESCE(AVG(CASE WHEN status = 'keluar' THEN biaya_total ELSE NULL END), 0) AS avg_biaya
    FROM tb_transaksi
")->fetch_assoc();

// 2. Slot capacity stats for executive overview
$slotStats = $db->query("
    SELECT 
        COUNT(*) AS total_slot,
        SUM(CASE WHEN jenis_slot = 'mobil' THEN 1 ELSE 0 END) AS mobil_total,
        SUM(CASE WHEN jenis_slot = 'mobil' AND status_slot = 'terisi' THEN 1 ELSE 0 END) AS mobil_terisi,
        SUM(CASE WHEN jenis_slot = 'motor' THEN 1 ELSE 0 END) AS motor_total,
        SUM(CASE WHEN jenis_slot = 'motor' AND status_slot = 'terisi' THEN 1 ELSE 0 END) AS motor_terisi,
        SUM(CASE WHEN status_slot = 'terisi' THEN 1 ELSE 0 END) AS slot_terisi,
        SUM(CASE WHEN status_slot = 'tersedia' THEN 1 ELSE 0 END) AS slot_tersedia
    FROM tb_slot_parkir
")->fetch_assoc();

$totalSlot = (int)($slotStats["total_slot"] ?? 0);
$slotTerisi = (int)($slotStats["slot_terisi"] ?? 0);
$slotTersedia = (int)($slotStats["slot_tersedia"] ?? 0);
$persenTerisi = $totalSlot > 0 ? round(($slotTerisi / $totalSlot) * 100, 1) : 0;

// 3. Revenue trend for last 7 days
$chartDays = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date("Y-m-d", strtotime("-{$i} days"));
    $chartDays[$d] = [
        'tanggal' => date("d M", strtotime($d)),
        'pendapatan' => 0,
        'volume' => 0
    ];
}

$start7 = date("Y-m-d 00:00:00", strtotime("-6 days"));
$res7 = $db->query("
    SELECT DATE(waktu_keluar) AS tgl, COUNT(*) AS jml, SUM(biaya_total) AS total
    FROM tb_transaksi
    WHERE waktu_keluar >= '{$start7}' AND status = 'keluar'
    GROUP BY DATE(waktu_keluar)
");
while ($r = $res7->fetch_assoc()) {
    if (isset($chartDays[$r['tgl']])) {
        $chartDays[$r['tgl']]['pendapatan'] = (float)$r['total'];
        $chartDays[$r['tgl']]['volume'] = (int)$r['jml'];
    }
}

// 4. Vehicle type revenue breakdown
$resJenis = $db->query("
    SELECT k.jenis_kendaraan, COUNT(*) AS total_trx, COALESCE(SUM(t.biaya_total), 0) AS total_uang
    FROM tb_transaksi t
    JOIN tb_kendaraan k ON k.id_kendaraan = t.id_kendaraan
    WHERE t.status = 'keluar'
    GROUP BY k.jenis_kendaraan
");
$jenisBreakdown = [];
while ($j = $resJenis->fetch_assoc()) {
    $jenisBreakdown[] = $j;
}

// 5. Recent completed high transactions
$recentTrx = $db->query("
    SELECT t.id_parkir, k.plat_nomor, k.jenis_kendaraan, k.warna, a.nama_area, s.kode_slot, t.waktu_masuk, t.waktu_keluar, t.durasi_jam, t.biaya_total
    FROM tb_transaksi t
    JOIN tb_kendaraan k ON k.id_kendaraan = t.id_kendaraan
    JOIN tb_area_parkir a ON a.id_area = t.id_area
    LEFT JOIN tb_slot_parkir s ON s.id_slot = t.id_slot
    WHERE t.status = 'keluar'
    ORDER BY t.id_parkir DESC
    LIMIT 6
");

page_header("Dashboard Eksekutif Owner", "owner/index.php");
?>

<!-- Header Top Toolbar -->
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
    <div>
        <h4 class="fw-bold mb-1 text-dark d-flex align-items-center gap-2">
            <i class="bi bi-briefcase-fill text-primary"></i> Dashboard Eksekutif & Finansial
        </h4>
        <div class="text-secondary small">
            Ringkasan pendapatan, omset parkir, dan performa bisnis ParkirKu
        </div>
    </div>
    <div class="d-flex align-items-center gap-2 flex-wrap">
        <div class="badge bg-white text-dark border px-3 py-2 d-none d-md-flex align-items-center gap-2 shadow-sm">
            <i class="bi bi-calendar3 text-primary"></i> <?= date("d F Y") ?>
            <span class="text-muted">|</span>
            <i class="bi bi-clock text-primary"></i> <?= date("H:i") ?> WIB
        </div>
        <a href="<?= e(url("owner/rekap.php")) ?>" class="btn btn-outline-primary fw-semibold shadow-sm d-flex align-items-center gap-2">
            <i class="bi bi-file-earmark-bar-graph"></i> Rekap Laporan
        </a>
        <a href="<?= e(url("denah.php")) ?>" class="btn btn-primary fw-semibold shadow-sm d-flex align-items-center gap-2">
            <i class="bi bi-map-fill"></i> Peta Denah Parkir
        </a>
    </div>
</div>

<!-- 4 Executive Revenue Cards (Matching Reference Visual Style) -->
<div class="row g-3 mb-3">
    <!-- Pendapatan Hari Ini -->
    <div class="col-6 col-lg-3">
        <div class="stat-widget-ref">
            <div class="stat-circle-icon stat-circle-green">
                <i class="bi bi-cash-stack"></i>
            </div>
            <div class="flex-grow-1 overflow-hidden">
                <div class="stat-label">Pendapatan Hari Ini</div>
                <div class="stat-num text-success fs-5"><?= rupiah($tot["pendapatan_hari_ini"]) ?></div>
                <div class="stat-info">
                    Tanggal <?= date("d M Y") ?>
                </div>
                <div class="stat-progress-bar">
                    <div class="stat-progress-fill-green" style="width: 100%;"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Pendapatan Bulan Ini -->
    <div class="col-6 col-lg-3">
        <div class="stat-widget-ref">
            <div class="stat-circle-icon stat-circle-blue">
                <i class="bi bi-graph-up-arrow"></i>
            </div>
            <div class="flex-grow-1 overflow-hidden">
                <div class="stat-label">Pendapatan Bulan Ini</div>
                <div class="stat-num text-primary fs-5"><?= rupiah($tot["pendapatan_bulan_ini"]) ?></div>
                <div class="stat-info">
                    Bulan <?= date("F Y") ?>
                </div>
                <div class="stat-progress-bar">
                    <div class="stat-progress-fill-blue" style="width: 100%;"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Total Akumulasi Pendapatan -->
    <div class="col-6 col-lg-3">
        <div class="stat-widget-ref">
            <div class="stat-circle-icon stat-circle-amber">
                <i class="bi bi-wallet2"></i>
            </div>
            <div class="flex-grow-1 overflow-hidden">
                <div class="stat-label">Total Pendapatan</div>
                <div class="stat-num text-dark fs-5"><?= rupiah($tot["total_pendapatan"]) ?></div>
                <div class="stat-info text-secondary">
                    Akumulasi transaksi selesai
                </div>
                <div class="stat-progress-bar">
                    <div class="stat-progress-fill-amber" style="width: 100%;"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Volume Transaksi -->
    <div class="col-6 col-lg-3">
        <div class="stat-widget-ref">
            <div class="stat-circle-icon stat-circle-navy">
                <i class="bi bi-receipt-cutoff"></i>
            </div>
            <div class="flex-grow-1 overflow-hidden">
                <div class="stat-label">Volume Transaksi</div>
                <div class="stat-num text-dark"><?= number_format((int)$tot["total_transaksi"]) ?></div>
                <div class="stat-info">
                    <strong class="text-success"><?= $tot["transaksi_selesai"] ?></strong> keluar | <strong class="text-primary"><?= $tot["transaksi_aktif"] ?></strong> aktif
                </div>
                <div class="stat-progress-bar">
                    <div class="stat-progress-fill-blue" style="width: <?= $tot['total_transaksi'] > 0 ? round(($tot['transaksi_selesai'] / $tot['total_transaksi']) * 100) : 0 ?>%;"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Secondary Operational & Business Insights Row -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="stat-widget">
            <div class="stat-circle-icon stat-circle-purple">
                <i class="bi bi-stopwatch-fill"></i>
            </div>
            <div class="flex-grow-1 overflow-hidden">
                <div class="stat-label">Rata-Rata Durasi</div>
                <div class="stat-num text-dark"><?= round((float)$tot["avg_durasi"], 1) ?> Jam</div>
                <div class="stat-info">Durasi parkir per kendaraan</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-widget">
            <div class="stat-circle-icon stat-circle-cyan">
                <i class="bi bi-tag-fill"></i>
            </div>
            <div class="flex-grow-1 overflow-hidden">
                <div class="stat-label">Rata-Rata Biaya</div>
                <div class="stat-num text-primary fs-6"><?= rupiah($tot["avg_biaya"]) ?></div>
                <div class="stat-info">Nilai per transaksi keluar</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-widget">
            <div class="stat-circle-icon stat-circle-blue">
                <i class="bi bi-car-front-fill"></i>
            </div>
            <div class="flex-grow-1 overflow-hidden">
                <div class="stat-label">Okupansi Slot Mobil</div>
                <div class="stat-num text-dark"><?= $slotStats["mobil_terisi"] ?? 0 ?> / <?= $slotStats["mobil_total"] ?? 0 ?></div>
                <div class="stat-info"><?= $slotStats["mobil_total"] > 0 ? round((($slotStats["mobil_terisi"] ?? 0) / $slotStats["mobil_total"]) * 100) : 0 ?>% kapasitas terisi</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-widget">
            <div class="stat-circle-icon stat-circle-green">
                <i class="bi bi-bicycle"></i>
            </div>
            <div class="flex-grow-1 overflow-hidden">
                <div class="stat-label">Okupansi Slot Motor</div>
                <div class="stat-num text-dark"><?= $slotStats["motor_terisi"] ?? 0 ?> / <?= $slotStats["motor_total"] ?? 0 ?></div>
                <div class="stat-info"><?= $slotStats["motor_total"] > 0 ? round((($slotStats["motor_terisi"] ?? 0) / $slotStats["motor_total"]) * 100) : 0 ?>% kapasitas terisi</div>
            </div>
        </div>
    </div>
</div>

<!-- Banner Quick Action to Rekap & Denah -->
<div class="dashboard-hero mb-4 d-flex align-items-center justify-content-between flex-wrap gap-3">
    <div>
        <div class="d-inline-flex align-items-center gap-2 px-2 py-1 rounded bg-white bg-opacity-10 text-white small mb-2">
            <i class="bi bi-pie-chart-fill text-warning"></i> <span>Executive Business Intelligence</span>
        </div>
        <h5 class="fw-bold mb-1">Rekapitulasi Laporan Finansial & Operasional Lengkap</h5>
        <p class="text-white-50 mb-0 small" style="max-width: 650px;">
            Saring data pendapatan berdasarkan tanggal kustom, jenis kendaraan, dan ekspor ke format CSV / cetak resmi untuk keperluan audit dan pembukuan bisnis.
        </p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="<?= e(url("owner/rekap.php")) ?>" class="btn btn-warning fw-bold text-dark px-3 py-2 shadow-sm">
            <i class="bi bi-file-earmark-text me-1"></i> Buka Rekap Laporan
        </a>
        <a href="<?= e(url("denah.php")) ?>" class="btn btn-outline-light px-3 py-2">
            <i class="bi bi-map-fill me-1"></i> Lihat Denah Parkir
        </a>
    </div>
</div>

<!-- Charts Row -->
<div class="row g-4 mb-4">
    <!-- Revenue Trend Chart -->
    <div class="col-lg-8">
        <div class="pk-card p-4 h-100">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div>
                    <h5 class="fw-bold mb-0 text-dark">
                        <i class="bi bi-graph-up-arrow text-success me-2"></i> Tren Pendapatan 7 Hari Terakhir
                    </h5>
                    <small class="text-secondary">Pertumbuhan omset harian aktual sistem</small>
                </div>
                <span class="badge bg-light text-secondary border px-2 py-1">Harian</span>
            </div>
            <div style="position: relative; height: 280px;">
                <canvas id="revenueChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Vehicle Type Breakdown Chart -->
    <div class="col-lg-4">
        <div class="pk-card p-4 h-100 d-flex flex-column justify-content-between">
            <div>
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="fw-bold mb-0 text-dark">
                        <i class="bi bi-pie-chart-fill text-primary me-2"></i> Pendapatan per Jenis
                    </h5>
                    <span class="badge bg-success-subtle text-success fw-semibold">Transaksi Keluar</span>
                </div>
                <div style="position: relative; height: 210px;">
                    <canvas id="jenisPieChart"></canvas>
                </div>
            </div>
            <div class="mt-3 pt-3 border-top">
                <?php foreach ($jenisBreakdown as $jb): ?>
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge <?= $jb['jenis_kendaraan'] === 'mobil' ? 'bg-primary' : 'bg-info text-dark' ?>">
                                <?= ucfirst($jb['jenis_kendaraan']) ?>
                            </span>
                            <span class="small text-muted">(<?= number_format((int)$jb['total_trx']) ?> trx)</span>
                        </div>
                        <div class="fw-bold text-dark font-monospace small">
                            <?= rupiah($jb['total_uang']) ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<!-- Recent Completed Transactions Table -->
<div class="pk-card p-4">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <div>
            <h5 class="fw-bold mb-0 text-dark">
                <i class="bi bi-receipt text-primary me-2"></i> Transaksi Selesai Terkini
            </h5>
            <small class="text-secondary">Catatan pembayaran parkir yang telah diselesaikan</small>
        </div>
        <a href="<?= e(url("owner/rekap.php")) ?>" class="btn btn-sm btn-outline-primary fw-semibold">
            Lihat Seluruh Rekap
        </a>
    </div>

    <div class="table-responsive">
        <table class="table pk-table align-middle">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Plat Nomor</th>
                    <th>Jenis & Warna</th>
                    <th>Slot & Area</th>
                    <th>Waktu Masuk</th>
                    <th>Waktu Keluar</th>
                    <th>Durasi</th>
                    <th class="text-end">Biaya Selesai</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($recentTrx->num_rows === 0): ?>
                    <tr>
                        <td colspan="8" class="text-center py-4 text-muted">Belum ada transaksi selesai.</td>
                    </tr>
                <?php endif; ?>
                <?php while ($r = $recentTrx->fetch_assoc()): ?>
                    <tr>
                        <td class="font-monospace text-secondary small">#<?= $r["id_parkir"] ?></td>
                        <td>
                            <span class="badge-plate">
                                <?= e($r["plat_nomor"]) ?>
                            </span>
                        </td>
                        <td>
                            <div class="fw-semibold small text-dark">
                                <?= ucfirst($r["jenis_kendaraan"]) ?>
                            </div>
                            <div class="small text-muted d-flex align-items-center">
                                <span class="color-dot" style="background-color: <?= warna_hex($r['warna']) ?>;"></span>
                                <?= e($r["warna"] ?: "Hitam") ?>
                            </div>
                        </td>
                        <td>
                            <span class="badge bg-dark font-monospace"><?= e($r["kode_slot"] ?: "-") ?></span>
                            <small class="text-secondary ms-1"><?= e($r["nama_area"]) ?></small>
                        </td>
                        <td class="small text-muted font-monospace"><?= date("d/m H:i", strtotime($r["waktu_masuk"])) ?></td>
                        <td class="small text-muted font-monospace"><?= date("d/m H:i", strtotime($r["waktu_keluar"])) ?></td>
                        <td>
                            <span class="badge bg-light text-secondary border font-monospace">
                                <?= $r["durasi_jam"] ?> Jam
                            </span>
                        </td>
                        <td class="text-end fw-bold text-success font-monospace fs-6">
                            <?= rupiah($r["biaya_total"]) ?>
                        </td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // 1. Revenue Chart (Line with gradient)
    const revData = <?= json_encode(array_values($chartDays)) ?>;
    const labels = revData.map(d => d.tanggal);
    const amounts = revData.map(d => d.pendapatan);

    const ctxRev = document.getElementById('revenueChart')?.getContext('2d');
    if (ctxRev) {
        const gradient = ctxRev.createLinearGradient(0, 0, 0, 260);
        gradient.addColorStop(0, 'rgba(16, 185, 129, 0.35)');
        gradient.addColorStop(1, 'rgba(16, 185, 129, 0.0)');

        new Chart(ctxRev, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Pendapatan Harian',
                    data: amounts,
                    borderColor: '#10b981',
                    borderWidth: 2.5,
                    backgroundColor: gradient,
                    tension: 0.35,
                    fill: true,
                    pointBackgroundColor: '#10b981',
                    pointBorderColor: '#ffffff',
                    pointBorderWidth: 2,
                    pointRadius: 5,
                    pointHoverRadius: 7
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return 'Pendapatan: Rp ' + Number(context.raw).toLocaleString('id-ID');
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(val) {
                                return 'Rp ' + Number(val).toLocaleString('id-ID');
                            }
                        },
                        grid: { color: '#f1f5f9' }
                    },
                    x: {
                        grid: { display: false }
                    }
                }
            }
        });
    }

    // 2. Jenis Doughnut Chart
    const jData = <?= json_encode($jenisBreakdown) ?>;
    const jLabels = jData.map(j => j.jenis_kendaraan.toUpperCase());
    const jAmounts = jData.map(j => parseFloat(j.total_uang));

    const ctxPie = document.getElementById('jenisPieChart')?.getContext('2d');
    if (ctxPie) {
        new Chart(ctxPie, {
            type: 'doughnut',
            data: {
                labels: jLabels,
                datasets: [{
                    data: jAmounts,
                    backgroundColor: ['#2563eb', '#10b981', '#f59e0b', '#8b5cf6'],
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
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return context.label + ': Rp ' + Number(context.raw).toLocaleString('id-ID');
                            }
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
