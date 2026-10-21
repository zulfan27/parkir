<?php
require_once __DIR__ . "/../includes/bootstrap.php";
require_role(["petugas"]);

$today = date("Y-m-d");

// 1. Petugas dashboard stats
$stats = $db->query("
    SELECT 
        SUM(CASE WHEN status = 'masuk' THEN 1 ELSE 0 END) AS aktif,
        SUM(CASE WHEN DATE(waktu_masuk) = '{$today}' THEN 1 ELSE 0 END) AS masuk_hari_ini,
        SUM(CASE WHEN DATE(waktu_keluar) = '{$today}' AND status = 'keluar' THEN 1 ELSE 0 END) AS keluar_hari_ini
    FROM tb_transaksi
")->fetch_assoc();

$aktif = (int)($stats["aktif"] ?? 0);
$masukHariIni = (int)($stats["masuk_hari_ini"] ?? 0);
$keluarHariIni = (int)($stats["keluar_hari_ini"] ?? 0);

// 2. Slot summary breakdown
$slotData = $db->query("
    SELECT 
        COUNT(*) AS total,
        SUM(CASE WHEN jenis_slot = 'mobil' THEN 1 ELSE 0 END) AS mobil_total,
        SUM(CASE WHEN jenis_slot = 'mobil' AND status_slot = 'terisi' THEN 1 ELSE 0 END) AS mobil_terisi,
        SUM(CASE WHEN jenis_slot = 'mobil' AND status_slot = 'tersedia' THEN 1 ELSE 0 END) AS mobil_kosong,
        SUM(CASE WHEN jenis_slot = 'motor' THEN 1 ELSE 0 END) AS motor_total,
        SUM(CASE WHEN jenis_slot = 'motor' AND status_slot = 'terisi' THEN 1 ELSE 0 END) AS motor_terisi,
        SUM(CASE WHEN jenis_slot = 'motor' AND status_slot = 'tersedia' THEN 1 ELSE 0 END) AS motor_kosong,
        SUM(CASE WHEN status_slot='tersedia' THEN 1 ELSE 0 END) AS tersedia,
        SUM(CASE WHEN status_slot='terisi' THEN 1 ELSE 0 END) AS terisi
    FROM tb_slot_parkir
")->fetch_assoc();

$totalSlot = (int)($slotData["total"] ?? 0);
$slotTersedia = (int)($slotData["tersedia"] ?? 0);
$slotTerisi = (int)($slotData["terisi"] ?? 0);
$persenTerisi = $totalSlot > 0 ? round(($slotTerisi / $totalSlot) * 100, 1) : 0;

$mobilTotal = (int)($slotData["mobil_total"] ?? 0);
$mobilTerisi = (int)($slotData["mobil_terisi"] ?? 0);
$mobilKosong = (int)($slotData["mobil_kosong"] ?? 0);
$persenMobil = $mobilTotal > 0 ? round(($mobilTerisi / $mobilTotal) * 100, 1) : 0;

$motorTotal = (int)($slotData["motor_total"] ?? 0);
$motorTerisi = (int)($slotData["motor_terisi"] ?? 0);
$motorKosong = (int)($slotData["motor_kosong"] ?? 0);
$persenMotor = $motorTotal > 0 ? round(($motorTerisi / $motorTotal) * 100, 1) : 0;

// 3. Active vehicles list (up to 12)
$activeList = $db->query("
    SELECT t.id_parkir, k.plat_nomor, k.jenis_kendaraan, k.warna, a.nama_area, s.kode_slot, t.waktu_masuk, tr.tarif_per_jam
    FROM tb_transaksi t
    JOIN tb_kendaraan k ON k.id_kendaraan = t.id_kendaraan
    JOIN tb_area_parkir a ON a.id_area = t.id_area
    JOIN tb_tarif tr ON tr.id_tarif = t.id_tarif
    LEFT JOIN tb_slot_parkir s ON s.id_slot = t.id_slot
    WHERE t.status = 'masuk'
    ORDER BY t.waktu_masuk DESC
    LIMIT 12
");

page_header("Dashboard Operasional Petugas", "petugas/index.php");
?>

<!-- Header Top Toolbar -->
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
    <div>
        <h4 class="fw-bold mb-1 text-dark d-flex align-items-center gap-2">
            <i class="bi bi-person-badge-fill text-primary"></i> Dashboard Operasional Pos Parkir
        </h4>
        <div class="text-secondary small">
            Monitoring kendaraan aktif, alokasi slot parkir, dan loket pembayaran
        </div>
    </div>
    <div class="d-flex align-items-center gap-2 flex-wrap">
        <div class="badge bg-white text-dark border px-3 py-2 d-none d-md-flex align-items-center gap-2 shadow-sm">
            <i class="bi bi-calendar3 text-primary"></i> <?= date("d F Y") ?>
            <span class="text-muted">|</span>
            <i class="bi bi-clock text-primary"></i> <span id="petugasClock"><?= date("H:i:s") ?></span> WIB
        </div>
        <a href="<?= e(url("denah.php")) ?>" class="btn btn-primary fw-semibold shadow-sm d-flex align-items-center gap-2">
            <i class="bi bi-map-fill"></i> Peta Denah Parkir
        </a>
    </div>
</div>

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

    <!-- Kendaraan Sedang Parkir -->
    <div class="col-6 col-lg-3">
        <div class="stat-widget-ref">
            <div class="stat-circle-icon stat-circle-navy">
                <i class="bi bi-p-circle-fill"></i>
            </div>
            <div class="flex-grow-1 overflow-hidden">
                <div class="stat-label">Sedang Parkir</div>
                <div class="stat-num text-dark"><?= $aktif ?></div>
                <div class="stat-info text-primary fw-semibold">
                    <i class="bi bi-shield-check"></i> <?= $slotTerisi ?> slot terisi
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Secondary Operational Metrics -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="stat-widget">
            <div class="stat-circle-icon stat-circle-green">
                <i class="bi bi-check2-circle"></i>
            </div>
            <div class="flex-grow-1 overflow-hidden">
                <div class="stat-label">Slot Tersedia</div>
                <div class="stat-num text-success"><?= $slotTersedia ?></div>
                <div class="stat-info">Siap digunakan kendaraan baru</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-widget">
            <div class="stat-circle-icon stat-circle-blue">
                <i class="bi bi-box-arrow-in-right"></i>
            </div>
            <div class="flex-grow-1 overflow-hidden">
                <div class="stat-label">Masuk Hari Ini</div>
                <div class="stat-num text-dark"><?= $masukHariIni ?></div>
                <div class="stat-info">Check-in kendaraan</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-widget">
            <div class="stat-circle-icon stat-circle-red">
                <i class="bi bi-box-arrow-right"></i>
            </div>
            <div class="flex-grow-1 overflow-hidden">
                <div class="stat-label">Keluar Hari Ini</div>
                <div class="stat-num text-dark"><?= $keluarHariIni ?></div>
                <div class="stat-info">Check-out kendaraan</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-widget">
            <div class="stat-circle-icon stat-circle-amber">
                <i class="bi bi-pie-chart-fill"></i>
            </div>
            <div class="flex-grow-1 overflow-hidden">
                <div class="stat-label">Tingkat Okupansi</div>
                <div class="stat-num <?= $persenTerisi >= 80 ? 'text-danger' : 'text-primary' ?>"><?= $persenTerisi ?>%</div>
                <div class="stat-info"><?= $slotTerisi ?> dari <?= $totalSlot ?> slot terisi</div>
            </div>
        </div>
    </div>
</div>

<!-- Operational Fast Action Panel -->
<div class="dashboard-hero mb-4 d-flex align-items-center justify-content-between flex-wrap gap-3">
    <div>
        <div class="d-inline-flex align-items-center gap-2 px-2 py-1 rounded bg-white bg-opacity-10 text-white small mb-2">
            <i class="bi bi-lightning-charge-fill text-warning"></i> <span>Aksi Cepat Loket Parkir</span>
        </div>
        <h5 class="fw-bold mb-1">Akses Langsung ke Peta Denah & Loket Transaksi</h5>
        <p class="text-white-50 mb-0 small" style="max-width: 650px;">
            Gunakan denah visual untuk memarkirkan kendaraan langsung ke slot tertentu dengan warna bodi yang akurat, atau gunakan formulir transaksi untuk pencarian plat cepat.
        </p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="<?= e(url("denah.php")) ?>" class="btn btn-warning fw-bold text-dark px-3 py-2 shadow-sm">
            <i class="bi bi-map-fill me-1"></i> Buka Denah Parkir
        </a>
        <a href="<?= e(url("petugas/transaksi.php")) ?>" class="btn btn-outline-light px-3 py-2">
            <i class="bi bi-box-arrow-in-right me-1"></i> Transaksi Masuk
        </a>
    </div>
</div>

<!-- Active Vehicles in Parking Table -->
<div class="pk-card p-4">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-3">
        <div>
            <h5 class="fw-bold mb-0 text-dark">
                <i class="bi bi-p-circle-fill text-danger me-2"></i> Kendaraan Sedang Parkir
            </h5>
            <small class="text-secondary">Daftar kendaraan aktif yang masih berada di lokasi parkir</small>
        </div>
        <div class="d-flex align-items-center gap-2">
            <div class="input-group input-group-sm" style="width: 250px;">
                <span class="input-group-text bg-white"><i class="bi bi-search text-muted"></i></span>
                <input type="text" id="quickSearchPlate" class="form-control text-uppercase" placeholder="Filter plat nomor...">
            </div>
            <a href="<?= e(url("petugas/transaksi.php")) ?>" class="btn btn-sm btn-outline-primary fw-semibold">
                Lihat Semua
            </a>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table pk-table align-middle" id="tableActiveParkir">
            <thead>
                <tr>
                    <th>Plat Nomor</th>
                    <th>Jenis & Warna</th>
                    <th>Slot & Area</th>
                    <th>Waktu Masuk</th>
                    <th>Durasi Berjalan</th>
                    <th>Estimasi Biaya</th>
                    <th class="text-end">Aksi Petugas</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($activeList->num_rows === 0): ?>
                    <tr>
                        <td colspan="7" class="text-center py-5 text-muted">
                            <i class="bi bi-check-circle fs-1 text-success d-block mb-2"></i>
                            Saat ini tidak ada kendaraan yang sedang parkir. Semua slot kosong.
                        </td>
                    </tr>
                <?php endif; ?>
                <?php while ($r = $activeList->fetch_assoc()): ?>
                    <?php 
                        $calc = hitung_biaya($r["waktu_masuk"], (float)$r["tarif_per_jam"]);
                    ?>
                    <tr class="active-vehicle-row" data-plat="<?= strtolower(e($r["plat_nomor"])) ?>">
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
                        <td class="small text-muted font-monospace">
                            <?= date("d/m H:i", strtotime($r["waktu_masuk"])) ?>
                        </td>
                        <td>
                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle fw-semibold">
                                <i class="bi bi-stopwatch me-1"></i><?= $calc["teks_durasi"] ?>
                            </span>
                        </td>
                        <td class="fw-bold text-danger font-monospace">
                            <?= rupiah($calc["biaya"]) ?>
                        </td>
                        <td class="text-end">
                            <div class="d-inline-flex gap-1">
                                <a href="<?= e(url("petugas/struk.php?id=" . $r["id_parkir"])) ?>" target="_blank" class="btn btn-sm btn-outline-secondary" title="Cetak Karcis Masuk">
                                    <i class="bi bi-printer"></i> Struk
                                </a>
                                <a href="<?= e(url("petugas/transaksi.php?cari_plat=" . urlencode($r["plat_nomor"]))) ?>" class="btn btn-sm btn-success fw-semibold">
                                    <i class="bi bi-box-arrow-right me-1"></i> Proses Keluar
                                </a>
                            </div>
                        </td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Quick search filter for active plate table
    const searchInput = document.getElementById('quickSearchPlate');
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            const query = this.value.trim().toLowerCase();
            const rows = document.querySelectorAll('.active-vehicle-row');
            rows.forEach(row => {
                const plat = row.getAttribute('data-plat') || '';
                if (plat.includes(query)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });
    }

    // Live clock update
    setInterval(function() {
        const now = new Date();
        const clockEl = document.getElementById('petugasClock');
        if (clockEl) {
            clockEl.innerText = now.toTimeString().split(' ')[0];
        }
    }, 1000);
});
</script>

<?php page_footer(); ?>
