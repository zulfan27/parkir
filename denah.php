<?php
require_once __DIR__ . "/includes/bootstrap.php";
require_role(["admin", "petugas", "owner"]);

$user = current_user();
$role = $user["role"];

// Handle POST actions for Check-in / Check-out directly from Denah
if ($_SERVER["REQUEST_METHOD"] === "POST" && in_array($role, ["admin", "petugas"], true)) {
    verify_csrf();
    $act = post("action");
    $uid = (int)$user["id_user"];

    if ($act === "masuk_slot") {
        $idSlot = (int)post("id_slot");
        $idKendaraan = (int)post("id_kendaraan");
        $platInput = strtoupper(trim(post("plat_nomor_baru")));

        try {
            $db->begin_transaction();

            // 1. Verify Slot is available
            $sSlot = $db->prepare("SELECT s.*, a.id_area, a.kapasitas, a.terisi FROM tb_slot_parkir s JOIN tb_area_parkir a ON a.id_area=s.id_area WHERE s.id_slot=? FOR UPDATE");
            $sSlot->bind_param("i", $idSlot);
            $sSlot->execute();
            $slotData = $sSlot->get_result()->fetch_assoc();

            if (!$slotData) {
                throw new RuntimeException("Slot parkir tidak ditemukan.");
            }
            if ($slotData["status_slot"] !== "tersedia") {
                throw new RuntimeException("Slot sudah terisi atau sedang nonaktif.");
            }

            // 2. Resolve vehicle (existing or create on the fly with selected color)
            if ($idKendaraan === 0 && $platInput !== "") {
                // Check if plate already exists
                $sCheck = $db->prepare("SELECT id_kendaraan, jenis_kendaraan, warna FROM tb_kendaraan WHERE plat_nomor=? LIMIT 1");
                $sCheck->bind_param("s", $platInput);
                $sCheck->execute();
                $existingK = $sCheck->get_result()->fetch_assoc();

                if ($existingK) {
                    $idKendaraan = (int)$existingK["id_kendaraan"];
                    $jenisK = $existingK["jenis_kendaraan"];
                    // Update color if provided
                    $warnaInput = post("warna", $existingK["warna"]);
                    $sUpd = $db->prepare("UPDATE tb_kendaraan SET warna=? WHERE id_kendaraan=?");
                    $sUpd->bind_param("si", $warnaInput, $idKendaraan);
                    $sUpd->execute();
                } else {
                    $jenisK = post("jenis_kendaraan", $slotData["jenis_slot"]);
                    $warna = post("warna", "Hitam");
                    $pemilik = post("pemilik", "Umum");

                    // Validate vehicle type matches slot type
                    if ($slotData["jenis_slot"] !== $jenisK) {
                        throw new RuntimeException("Jenis kendaraan ({$jenisK}) tidak cocok dengan jenis slot ({$slotData['jenis_slot']}).");
                    }

                    $sNew = $db->prepare("INSERT INTO tb_kendaraan (plat_nomor, jenis_kendaraan, warna, pemilik, id_user) VALUES (?, ?, ?, ?, ?)");
                    $sNew->bind_param("ssssi", $platInput, $jenisK, $warna, $pemilik, $uid);
                    $sNew->execute();
                    $idKendaraan = $db->insert_id;
                }
            } else {
                $sK = $db->prepare("SELECT jenis_kendaraan, warna FROM tb_kendaraan WHERE id_kendaraan=?");
                $sK->bind_param("i", $idKendaraan);
                $sK->execute();
                $kRow = $sK->get_result()->fetch_assoc();
                if (!$kRow) {
                    throw new RuntimeException("Data kendaraan tidak ditemukan.");
                }
                $jenisK = $kRow["jenis_kendaraan"];

                // Validate vehicle type matches slot type
                if ($slotData["jenis_slot"] !== $jenisK) {
                    throw new RuntimeException("Jenis kendaraan ({$jenisK}) tidak cocok dengan jenis slot ({$slotData['jenis_slot']}).");
                }
            }

            // 3. Ensure vehicle is not already parked
            $sActive = $db->prepare("SELECT id_parkir FROM tb_transaksi WHERE id_kendaraan=? AND status='masuk' LIMIT 1");
            $sActive->bind_param("i", $idKendaraan);
            $sActive->execute();
            if ($sActive->get_result()->fetch_assoc()) {
                throw new RuntimeException("Kendaraan ini masih tercatat parkir aktif.");
            }

            // 4. Resolve Rate
            $sTarif = $db->prepare("SELECT id_tarif FROM tb_tarif WHERE jenis_kendaraan=? LIMIT 1");
            $sTarif->bind_param("s", $jenisK);
            $sTarif->execute();
            $tRow = $sTarif->get_result()->fetch_assoc();
            if (!$tRow) {
                // Fallback to slot type
                $sTarif->bind_param("s", $slotData["jenis_slot"]);
                $sTarif->execute();
                $tRow = $sTarif->get_result()->fetch_assoc();
                if (!$tRow) {
                    throw new RuntimeException("Tarif untuk jenis kendaraan ini belum dikonfigurasi.");
                }
            }
            $idTarif = (int)$tRow["id_tarif"];

            // 5. Insert Transaction
            $sTrx = $db->prepare("INSERT INTO tb_transaksi (id_kendaraan, waktu_masuk, id_tarif, status, id_user, id_area, id_slot) VALUES (?, NOW(), ?, 'masuk', ?, ?, ?)");
            $sTrx->bind_param("iiiii", $idKendaraan, $idTarif, $uid, $slotData["id_area"], $idSlot);
            $sTrx->execute();
            $idTrx = $db->insert_id;

            // 6. Update Slot & Area status
            $sUpSlot = $db->prepare("UPDATE tb_slot_parkir SET status_slot='terisi' WHERE id_slot=?");
            $sUpSlot->bind_param("i", $idSlot);
            $sUpSlot->execute();

            $sUpArea = $db->prepare("UPDATE tb_area_parkir SET terisi = terisi + 1 WHERE id_area=?");
            $sUpArea->bind_param("i", $slotData["id_area"]);
            $sUpArea->execute();

            log_activity($db, $uid, "Kendaraan masuk di Slot {$slotData['kode_slot']}, ID Trx {$idTrx}");
            $db->commit();
            flash("success", "Kendaraan berhasil diparkirkan di Slot " . $slotData['kode_slot']);
        } catch (Throwable $e) {
            $db->rollback();
            flash("danger", $e->getMessage());
        }
        redirect("denah.php");
    }

    if ($act === "keluar_slot") {
        $idTrx = (int)post("id_parkir");
        try {
            $db->begin_transaction();
            $s = $db->prepare("
                SELECT t.id_parkir, t.waktu_masuk, t.id_area, t.id_slot, t.status, tr.tarif_per_jam, k.plat_nomor, s.kode_slot
                FROM tb_transaksi t
                JOIN tb_tarif tr ON tr.id_tarif = t.id_tarif
                JOIN tb_kendaraan k ON k.id_kendaraan = t.id_kendaraan
                LEFT JOIN tb_slot_parkir s ON s.id_slot = t.id_slot
                WHERE t.id_parkir = ? FOR UPDATE
            ");
            $s->bind_param("i", $idTrx);
            $s->execute();
            $trx = $s->get_result()->fetch_assoc();

            if (!$trx || $trx["status"] !== "masuk") {
                throw new RuntimeException("Transaksi tidak ditemukan atau sudah diproses.");
            }

            $diffSec = max(0, time() - strtotime($trx["waktu_masuk"]));
            $durasiJam = max(1, (int)ceil($diffSec / 3600));
            $totalBiaya = $durasiJam * (float)$trx["tarif_per_jam"];

            // Update Transaction
            $sUp = $db->prepare("UPDATE tb_transaksi SET waktu_keluar=NOW(), durasi_jam=?, biaya_total=?, status='keluar' WHERE id_parkir=?");
            $sUp->bind_param("idi", $durasiJam, $totalBiaya, $idTrx);
            $sUp->execute();

            // Free slot
            if (!empty($trx["id_slot"])) {
                $sFree = $db->prepare("UPDATE tb_slot_parkir SET status_slot='tersedia' WHERE id_slot=?");
                $sFree->bind_param("i", $trx["id_slot"]);
                $sFree->execute();
            }

            // Decrement Area count
            $sDec = $db->prepare("UPDATE tb_area_parkir SET terisi = GREATEST(0, terisi - 1) WHERE id_area=?");
            $sDec->bind_param("i", $trx["id_area"]);
            $sDec->execute();

            log_activity($db, $uid, "Kendaraan keluar dari Slot " . ($trx['kode_slot'] ?? '-') . ", Trx ID {$idTrx}");
            $db->commit();

            flash("success", "Kendaraan {$trx['plat_nomor']} berhasil keluar. Total: Rp " . number_format($totalBiaya, 0, ',', '.') . ". <a href='" . url("petugas/struk.php?id=" . $idTrx) . "' target='_blank' class='btn btn-sm btn-light ms-2'><i class='bi bi-printer'></i> Cetak Struk</a>");
        } catch (Throwable $e) {
            $db->rollback();
            flash("danger", $e->getMessage());
        }
        redirect("denah.php");
    }
}

// Fetch areas for filtering
$areasRes = $db->query("SELECT * FROM tb_area_parkir ORDER BY id_area");
$areas = [];
while ($a = $areasRes->fetch_assoc()) {
    $areas[] = $a;
}

// Fetch existing registered vehicles for check-in modal
$kendaraanList = $db->query("
    SELECT k.id_kendaraan, k.plat_nomor, k.jenis_kendaraan, k.warna, k.pemilik 
    FROM tb_kendaraan k
    WHERE k.id_kendaraan NOT IN (SELECT id_kendaraan FROM tb_transaksi WHERE status='masuk')
    ORDER BY k.plat_nomor ASC
");

page_header("Denah Parkir");
?>

<!-- Header Toolbar inside page (matching reference image) -->
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div>
        <h4 class="fw-bold mb-0 text-dark d-flex align-items-center gap-2">
            <i class="bi bi-car-front-fill text-primary"></i> Denah Parkir
        </h4>
        <small class="text-secondary">Pantau ketersediaan slot parkir secara real-time</small>
    </div>
    <div class="d-flex align-items-center gap-3">
        <div class="text-secondary small d-none d-md-flex align-items-center gap-1 font-monospace">
            <i class="bi bi-calendar3"></i> <span id="headerDateText"><?= date("d F Y") ?></span>
        </div>
        <div class="text-secondary small d-none d-md-flex align-items-center gap-1 font-monospace">
            <i class="bi bi-clock"></i> <span id="headerTimeText"><?= date("H:i:s") ?></span>
        </div>
        <button class="btn btn-primary btn-sm px-3 fw-semibold shadow-sm d-flex align-items-center gap-1" id="btnRefreshDenah">
            <i class="bi bi-arrow-clockwise" id="refreshSpin"></i> Refresh Denah
        </button>
    </div>
</div>

<!-- 4 Statistics Cards Matching Reference Image -->
<div class="row g-3 mb-3">
    <!-- Slot Mobil -->
    <div class="col-6 col-lg-3">
        <div class="stat-widget-ref">
            <div class="stat-circle-icon stat-circle-blue">
                <i class="bi bi-car-front-fill"></i>
            </div>
            <div class="flex-grow-1 overflow-hidden">
                <div class="stat-label">Slot Mobil</div>
                <div class="stat-num" id="statMobilTotal">-</div>
                <div class="stat-info">
                    Terisi <strong class="text-danger" id="statMobilTerisi">-</strong> | Kosong <strong class="text-success" id="statMobilKosong">-</strong>
                </div>
                <div class="stat-progress-bar">
                    <div class="stat-progress-fill-red" id="progressMobil" style="width: 0%;"></div>
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
                <div class="stat-num" id="statMotorTotal">-</div>
                <div class="stat-info">
                    Terisi <strong class="text-danger" id="statMotorTerisi">-</strong> | Kosong <strong class="text-success" id="statMotorKosong">-</strong>
                </div>
                <div class="stat-progress-bar">
                    <div class="stat-progress-fill-red" id="progressMotor" style="width: 0%;"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Total Slot -->
    <div class="col-6 col-lg-3">
        <div class="stat-widget-ref">
            <div class="stat-circle-icon stat-circle-green">
                <i class="bi bi-building-check"></i>
            </div>
            <div class="flex-grow-1 overflow-hidden">
                <div class="stat-label">Total Slot</div>
                <div class="stat-num" id="statAllTotal">-</div>
                <div class="stat-info">
                    Terisi <strong class="text-danger" id="statAllTerisi">-</strong> | Kosong <strong class="text-success" id="statAllKosong">-</strong>
                </div>
                <div class="stat-progress-bar">
                    <div class="stat-progress-fill-green" id="progressAll" style="width: 0%;"></div>
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
                <div class="stat-num text-dark fs-5" id="statPendapatan">Rp -</div>
                <div class="stat-info text-success fw-semibold">
                    <i class="bi bi-arrow-up-right"></i> Aktual sistem parkir
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Legend & Filter Bar (Matching Reference Image) -->
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <!-- Legend -->
    <div class="d-flex align-items-center gap-3 small fw-semibold text-secondary">
        <div class="d-flex align-items-center gap-1">
            <span style="display:inline-block; width:14px; height:14px; background:#10b981; border-radius:2px;"></span>
            <span>= Kosong</span>
        </div>
        <div class="d-flex align-items-center gap-1">
            <span style="display:inline-block; width:14px; height:14px; background:#ef4444; border-radius:2px;"></span>
            <span>= Terisi</span>
        </div>
        <div class="d-flex align-items-center gap-1">
            <span style="display:inline-block; width:14px; height:14px; background:#f59e0b; border-radius:2px;"></span>
            <span>= Dipesan</span>
        </div>
        <div class="d-flex align-items-center gap-1">
            <span style="display:inline-block; width:14px; height:14px; background:#94a3b8; border-radius:2px;"></span>
            <span>= Nonaktif</span>
        </div>
    </div>

    <!-- Dropdown Filter Area -->
    <div class="d-flex align-items-center gap-2">
        <div class="input-group input-group-sm" style="width: 220px;">
            <span class="input-group-text bg-white text-secondary border-end-0"><i class="bi bi-geo-alt"></i></span>
            <select class="form-select form-select-sm border-start-0" id="filterAreaSelect">
                <option value="all">Semua Area</option>
                <option value="motor">Area Motor</option>
                <option value="mobil">Area Mobil</option>
            </select>
        </div>
    </div>
</div>

<!-- Main Section: Denah Canvas (Full Width) -->
<div class="row g-3 mb-4">
    <div class="col-12">
        <div class="parking-canvas-wrapper">
            <div class="curb-surround">
                <!-- Security Guard Booth (POS) -->
                <div class="pos-booth">
                    <div>POS</div>
                </div>

                <!-- ================= 1. AREA MOTOR (40 Slot) ================= -->
                <div id="sectionAreaMotor">
                    <div class="zone-pill-header">
                        <div class="zone-pill-icon"><i class="bi bi-bicycle"></i></div>
                        <span>AREA MOTOR (40 Slot)</span>
                    </div>

                    <!-- Motor Row 1: M01 - M20 -->
                    <div class="slots-row-container" id="rowMotorTop">
                        <!-- Injected via JS -->
                    </div>

                    <!-- Driving lane with arrow -->
                    <div class="asphalt-lane">
                        <span class="road-arrow">➔</span>
                    </div>

                    <!-- Motor Row 2: M21 - M40 -->
                    <div class="slots-row-container" id="rowMotorBottom">
                        <!-- Injected via JS -->
                    </div>
                </div>

                <!-- Middle Roadway Separator with Arrow -->
                <div class="asphalt-lane my-3">
                    <span class="road-arrow">←</span>
                </div>

                <!-- ================= 2. AREA MOBIL (20 Slot) ================= -->
                <div id="sectionAreaMobil">
                    <div class="zone-pill-header">
                        <div class="zone-pill-icon"><i class="bi bi-car-front-fill"></i></div>
                        <span>AREA MOBIL (20 Slot)</span>
                    </div>

                    <!-- Mobil Row 1: A01 - A10 -->
                    <div class="slots-row-container" id="rowMobilTop">
                        <!-- Injected via JS -->
                    </div>

                    <!-- Driving lane with arrow -->
                    <div class="asphalt-lane">
                        <span class="road-arrow">←</span>
                    </div>

                    <!-- Mobil Row 2: A11 - A20 -->
                    <div class="slots-row-container" id="rowMobilBottom">
                        <!-- Injected via JS -->
                    </div>
                </div>

                <!-- Gate Indicators at Bottom -->
                <div class="gate-marker gate-marker-masuk">
                    <div class="barrier-boom"></div>
                    <span>MASUK <i class="bi bi-arrow-up"></i></span>
                </div>
                <div class="gate-marker gate-marker-keluar">
                    <span>KELUAR <i class="bi bi-arrow-up"></i></span>
                    <div class="barrier-boom"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Detail Slot Section (Ditempatkan di Bawah Denah Parkiran) -->
<div class="row g-4" id="detailSlotSection">
    <!-- Main Detail Slot Card (Persis Sesuai Gambar Referensi Pengguna) -->
    <div class="col-lg-4 col-md-5 col-12">
        <div class="detail-slot-panel pk-card p-4 shadow-sm h-100" id="detailSlotPanel">
            <div class="panel-header d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom">
                <h5 class="panel-title fw-bold mb-0">Detail Slot</h5>
                <button type="button" class="btn-close btn-sm" id="btnCloseDetail" aria-label="Close" onclick="closeDetailSlot()"></button>
            </div>

            <!-- Status Badge & Slot Code Display -->
            <div class="d-flex align-items-center justify-content-between mb-2">
                <span class="badge-status-lg badge-status-empty" id="panelStatusBadge">KOSONG</span>
            </div>

            <div class="slot-code-display d-flex align-items-center justify-content-between my-2">
                <div class="slot-code-big" id="panelSlotCode">A05</div>
                <div class="slot-type-circle" id="panelTypeCircle">
                    <i class="bi bi-car-front-fill" id="panelTypeIcon"></i>
                </div>
            </div>

            <!-- Property Table -->
            <table class="detail-prop-table my-3">
                <tbody>
                    <tr>
                        <td>Jenis Slot</td>
                        <td id="panelJenisSlot">Mobil</td>
                    </tr>
                    <tr>
                        <td>Area</td>
                        <td id="panelNamaArea">Area Mobil</td>
                    </tr>
                    <tr>
                        <td>Zona</td>
                        <td id="panelZona">Lantai 1</td>
                    </tr>
                    <tr>
                        <td>Status</td>
                        <td id="panelStatusText" class="text-success">Kosong</td>
                    </tr>
                </tbody>
            </table>

            <!-- Main Action Button -->
            <div id="panelActionBox" class="mt-3">
                <button class="btn-slot-action btn-slot-parkir" id="btnPanelAction" onclick="handlePanelAction()">
                    <i class="bi bi-car-front-fill"></i> Parkirkan Kendaraan
                </button>
            </div>
        </div>
    </div>

    <!-- Secondary Card: Informasi Rinci Slot & Panduan Keterangan -->
    <div class="col-lg-8 col-md-7 col-12">
        <div class="pk-card p-4 shadow-sm h-100 d-flex flex-column justify-content-between">
            <div>
                <div class="d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom">
                    <h5 class="fw-bold mb-0 text-dark"><i class="bi bi-info-circle text-primary me-2"></i>Informasi Lengkap Slot Parkir</h5>
                    <span class="badge bg-light text-secondary border font-monospace" id="infoSlotBadge">Slot A05</span>
                </div>
                
                <div class="row g-3">
                    <div class="col-md-6">
                        <table class="detail-prop-table mb-0">
                            <tbody>
                                <tr>
                                    <td>Kode Slot</td>
                                    <td class="font-monospace fw-bold" id="infoKodeSlot">A05</td>
                                </tr>
                                <tr>
                                    <td>Jenis Kendaraan</td>
                                    <td id="infoJenisKendaraan">Mobil</td>
                                </tr>
                                <tr id="rowInfoWarna">
                                    <td>Warna Bodi</td>
                                    <td id="infoWarnaKendaraan">-</td>
                                </tr>
                                <tr>
                                    <td>Tarif Berlaku</td>
                                    <td class="fw-bold text-primary" id="infoTarif">Rp 5.000 / jam</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <table class="detail-prop-table mb-0">
                            <tbody>
                                <tr id="rowInfoPlat" class="d-none">
                                    <td>Plat Nomor</td>
                                    <td id="infoPlatNomor" class="font-monospace text-primary fw-bold fs-6">-</td>
                                </tr>
                                <tr id="rowInfoMasuk" class="d-none">
                                    <td>Waktu Masuk</td>
                                    <td id="infoWaktuMasuk" class="font-monospace small">-</td>
                                </tr>
                                <tr>
                                    <td>Durasi Parkir</td>
                                    <td id="infoDurasi" class="fw-semibold">24 jam</td>
                                </tr>
                                <tr>
                                    <td>Akses Loket</td>
                                    <td><span class="badge bg-success-subtle text-success">Gerbang Masuk POS</span></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Card Keterangan Status Marka -->
            <div class="detail-subcard mt-3">
                <div class="detail-subcard-title mb-2"><i class="bi bi-palette text-secondary me-1"></i>Panduan Warna Status Slot:</div>
                <div class="row g-2 small text-secondary">
                    <div class="col-6 col-sm-3 d-flex align-items-center gap-2">
                        <span style="display:inline-block; width:12px; height:12px; background:#10b981; border-radius:2px;"></span>
                        <span>Hijau = Kosong</span>
                    </div>
                    <div class="col-6 col-sm-3 d-flex align-items-center gap-2">
                        <span style="display:inline-block; width:12px; height:12px; background:#ef4444; border-radius:2px;"></span>
                        <span>Merah = Terisi</span>
                    </div>
                    <div class="col-6 col-sm-3 d-flex align-items-center gap-2">
                        <span style="display:inline-block; width:12px; height:12px; background:#f59e0b; border-radius:2px;"></span>
                        <span>Oranye = Dipesan</span>
                    </div>
                    <div class="col-6 col-sm-3 d-flex align-items-center gap-2">
                        <span style="display:inline-block; width:12px; height:12px; background:#94a3b8; border-radius:2px;"></span>
                        <span>Abu = Nonaktif</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal Parkirkan Kendaraan (Check-In) -->
<?php if (in_array($role, ["admin", "petugas"], true)): ?>
<div class="modal fade" id="checkinModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="masuk_slot">
                <input type="hidden" name="id_slot" id="checkinSlotId">

                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title fw-bold"><i class="bi bi-box-arrow-in-right me-1"></i> Parkirkan Kendaraan Masuk</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="alert alert-info py-2 small d-flex align-items-center gap-2 mb-3">
                        <i class="bi bi-info-circle-fill fs-5"></i>
                        <div>Slot Tujuan: <strong id="checkinSlotKode">-</strong> (<span id="checkinSlotJenis">-</span>)</div>
                    </div>

                    <!-- Pilihan: Kendaraan Terdaftar vs Kendaraan Baru -->
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Pilih Kendaraan Terdaftar</label>
                        <select class="form-select" name="id_kendaraan" id="selectKendaraan">
                            <option value="0">-- Input Kendaraan Baru / Belum Terdaftar --</option>
                            <?php while ($kv = $kendaraanList->fetch_assoc()): ?>
                                <option value="<?= $kv['id_kendaraan'] ?>" data-jenis="<?= $kv['jenis_kendaraan'] ?>" data-warna="<?= e($kv['warna']) ?>">
                                    <?= e($kv['plat_nomor']) ?> — <?= ucfirst($kv['jenis_kendaraan']) ?> (<?= e($kv['warna'] ?: 'Warna -') ?>)
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <!-- Input Kendaraan Baru -->
                    <div id="boxKendaraanBaru" class="p-3 bg-light rounded border mb-3">
                        <h6 class="fw-bold small text-secondary mb-2"><i class="bi bi-plus-circle"></i> Data Kendaraan Baru</h6>
                        <div class="mb-2">
                            <label class="form-label small">Nomor Polisi (Plat Nomor) *</label>
                            <input type="text" class="form-control text-uppercase font-monospace" name="plat_nomor_baru" id="inputPlatBaru" placeholder="Contoh: B 1234 ABC" maxlength="15" required>
                        </div>
                        <div class="row g-2 mb-2">
                            <div class="col-6">
                                <label class="form-label small">Jenis Kendaraan</label>
                                <input type="text" class="form-control form-control-sm text-capitalize bg-white" name="jenis_kendaraan" id="inputJenisBaru" readonly>
                            </div>
                            <div class="col-6">
                                <label class="form-label small">Warna Bodi Kendaraan *</label>
                                <select class="form-select form-select-sm" name="warna" id="selectWarnaBaru">
                                    <option value="Hitam">Hitam</option>
                                    <option value="Putih">Putih</option>
                                    <option value="Merah">Merah</option>
                                    <option value="Biru">Biru</option>
                                    <option value="Silver">Silver</option>
                                    <option value="Abu-abu">Abu-abu</option>
                                    <option value="Kuning">Kuning</option>
                                    <option value="Hijau">Hijau</option>
                                    <option value="Cokelat">Cokelat</option>
                                </select>
                            </div>
                        </div>
                        <div>
                            <label class="form-label small">Nama Pemilik / Sopir (Opsional)</label>
                            <input type="text" class="form-control form-control-sm" name="pemilik" placeholder="Nama pemilik">
                        </div>
                    </div>

                    <div class="small text-muted">
                        <i class="bi bi-clock"></i> Waktu masuk dicatat otomatis oleh server saat formulir disimpan.
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary fw-semibold"><i class="bi bi-check-lg"></i> Konfirmasi Masuk</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Proses Keluar -->
<div class="modal fade" id="checkoutModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="post" id="checkoutForm">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="keluar_slot">
                <input type="hidden" name="id_parkir" id="checkoutIdParkir">

                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title fw-bold"><i class="bi bi-box-arrow-right me-1"></i> Proses Kendaraan Keluar</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4" id="checkoutModalBody">
                    <!-- Injected via JS -->
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-danger fw-semibold"><i class="bi bi-check2-circle"></i> Selesaikan Transaksi Keluar</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
// Data Store
let denahData = null;
let currentSelectedSlot = null;
const userRole = <?= json_encode($role) ?>;
const apiUrl = <?= json_encode(url("api/denah_data.php")) ?>;

// Realistic SVG Generators matching real vehicle color from database
function resolveColorHex(colorName, type) {
    const c = (colorName || '').toLowerCase().trim();
    if (c.includes('hitam') || c.includes('black')) {
        return { body: '#1f2937', stroke: '#374151', roof: '#111827' };
    }
    if (c.includes('putih') || c.includes('white')) {
        return { body: '#f8fafc', stroke: '#cbd5e1', roof: '#e2e8f0' };
    }
    if (c.includes('merah') || c.includes('red')) {
        return { body: '#dc2626', stroke: '#991b1b', roof: '#b91c1c' };
    }
    if (c.includes('biru') || c.includes('blue')) {
        return { body: '#2563eb', stroke: '#1d4ed8', roof: '#1e40af' };
    }
    if (c.includes('silver')) {
        return { body: '#94a3b8', stroke: '#64748b', roof: '#cbd5e1' };
    }
    if (c.includes('abu') || c.includes('gray') || c.includes('grey')) {
        return { body: '#4b5563', stroke: '#374151', roof: '#374151' };
    }
    if (c.includes('kuning') || c.includes('yellow')) {
        return { body: '#eab308', stroke: '#ca8a04', roof: '#ca8a04' };
    }
    if (c.includes('hijau') || c.includes('green')) {
        return { body: '#16a34a', stroke: '#15803d', roof: '#15803d' };
    }
    if (c.includes('cokelat') || c.includes('brown')) {
        return { body: '#78350f', stroke: '#451a03', roof: '#451a03' };
    }
    return { body: '#64748b', stroke: '#475569', roof: '#475569' };
}

function getCarSvg(colorName) {
    const col = resolveColorHex(colorName, 'car');
    return `
    <svg viewBox="0 0 54 94" width="100%" height="100%" style="max-height: 78px;" filter="drop-shadow(0 3px 5px rgba(0,0,0,0.5))">
      <rect x="2" y="14" width="7" height="15" rx="3" fill="#111827"/>
      <rect x="45" y="14" width="7" height="15" rx="3" fill="#111827"/>
      <rect x="2" y="65" width="7" height="15" rx="3" fill="#111827"/>
      <rect x="45" y="65" width="7" height="15" rx="3" fill="#111827"/>
      
      <rect x="3" y="27" width="5" height="4" rx="2" fill="${col.body}"/>
      <rect x="46" y="27" width="5" height="4" rx="2" fill="${col.body}"/>

      <rect x="6" y="6" width="42" height="82" rx="14" fill="${col.body}" stroke="${col.stroke}" stroke-width="1.2"/>
      
      <path d="M14 8 L16 24 M40 8 L38 24" stroke="rgba(255,255,255,0.3)" stroke-width="1.2" stroke-linecap="round"/>
      
      <path d="M9 8 Q 14 10 16 12" stroke="#fef08a" stroke-width="2.5" fill="none" stroke-linecap="round"/>
      <path d="M45 8 Q 40 10 38 12" stroke="#fef08a" stroke-width="2.5" fill="none" stroke-linecap="round"/>

      <path d="M12 28 L42 28 L38 42 L16 42 Z" fill="#0f172a" stroke="#334155" stroke-width="0.8"/>
      <path d="M15 30 L26 30 L22 40 L17 40 Z" fill="rgba(255,255,255,0.22)"/>

      <rect x="14" y="40" width="26" height="24" rx="4" fill="${col.roof}" stroke="rgba(0,0,0,0.15)" stroke-width="1"/>

      <path d="M15 64 L39 64 L41 74 L13 74 Z" fill="#0f172a" stroke="#334155" stroke-width="0.8"/>

      <rect x="9" y="85" width="8" height="3" rx="1" fill="#ef4444"/>
      <rect x="37" y="85" width="8" height="3" rx="1" fill="#ef4444"/>
    </svg>
    `;
}

function getMotorSvg(colorName) {
    const col = resolveColorHex(colorName, 'motor');
    return `
    <svg viewBox="0 0 32 76" width="100%" height="100%" style="max-height: 56px;" filter="drop-shadow(0 2px 4px rgba(0,0,0,0.5))">
      <rect x="13" y="4" width="6" height="18" rx="3" fill="#111827"/>
      
      <path d="M5 22 L27 22" stroke="#475569" stroke-width="3" stroke-linecap="round"/>
      <circle cx="5" cy="22" r="2.2" fill="#0f172a"/>
      <circle cx="27" cy="22" r="2.2" fill="#0f172a"/>

      <circle cx="16" cy="18" r="3" fill="#fef08a"/>

      <path d="M10 27 Q 16 23 22 27 L20 44 Q 16 46 12 44 Z" fill="${col.body}" stroke="${col.stroke}" stroke-width="0.8"/>
      <path d="M14 27 Q 16 25 18 27 L17 40 L15 40 Z" fill="rgba(255,255,255,0.25)"/>

      <rect x="11" y="44" width="10" height="18" rx="4" fill="#1e293b"/>

      <path d="M13 60 L19 60 L17 68 L15 68 Z" fill="${col.body}"/>

      <rect x="13.5" y="58" width="5" height="15" rx="2.5" fill="#111827"/>
      <rect x="14" y="70" width="4" height="2.5" rx="1" fill="#ef4444"/>
    </svg>
    `;
}

function fetchDenahData() {
    const icon = document.getElementById('refreshSpin');
    if (icon) icon.classList.add('spin-animation');

    fetch(apiUrl)
        .then(res => res.json())
        .then(data => {
            if (icon) icon.classList.remove('spin-animation');
            if (data.success) {
                denahData = data;
                updateStatsCards(data.stats);
                renderParkingSlots();

                // Keep selection or default to A05 like reference image
                if (currentSelectedSlot) {
                    const fresh = data.slots.find(s => s.id_slot === currentSelectedSlot.id_slot);
                    if (fresh) selectSlot(fresh);
                } else {
                    const def = data.slots.find(s => s.kode_slot === 'A05') || data.slots[0];
                    if (def) selectSlot(def);
                }

                // Update clock text
                document.getElementById('headerDateText').innerText = data.date_text || '<?= date("d F Y") ?>';
                document.getElementById('headerTimeText').innerText = data.time_text || '<?= date("H:i:s") ?>';
            }
        })
        .catch(err => {
            if (icon) icon.classList.remove('spin-animation');
            console.error("Error fetching denah data:", err);
        });
}

function updateStatsCards(stats) {
    // Slot Mobil
    document.getElementById('statMobilTotal').innerText = stats.mobil.total;
    document.getElementById('statMobilTerisi').innerText = stats.mobil.terisi;
    document.getElementById('statMobilKosong').innerText = stats.mobil.kosong;
    document.getElementById('progressMobil').style.width = stats.mobil.persen + '%';

    // Slot Motor
    document.getElementById('statMotorTotal').innerText = stats.motor.total;
    document.getElementById('statMotorTerisi').innerText = stats.motor.terisi;
    document.getElementById('statMotorKosong').innerText = stats.motor.kosong;
    document.getElementById('progressMotor').style.width = stats.motor.persen + '%';

    // Total Slot
    document.getElementById('statAllTotal').innerText = stats.all.total;
    document.getElementById('statAllTerisi').innerText = stats.all.terisi;
    document.getElementById('statAllKosong').innerText = stats.all.kosong;
    document.getElementById('progressAll').style.width = stats.all.persen + '%';

    // Pendapatan Hari Ini
    document.getElementById('statPendapatan').innerText = stats.pendapatan_formatted || 'Rp 245.000';
}

function renderParkingSlots() {
    if (!denahData) return;

    const rowMotorTop = document.getElementById('rowMotorTop');
    const rowMotorBottom = document.getElementById('rowMotorBottom');
    const rowMobilTop = document.getElementById('rowMobilTop');
    const rowMobilBottom = document.getElementById('rowMobilBottom');

    rowMotorTop.innerHTML = '';
    rowMotorBottom.innerHTML = '';
    rowMobilTop.innerHTML = '';
    rowMobilBottom.innerHTML = '';

    // Filter by area if selected
    const filterArea = document.getElementById('filterAreaSelect').value;
    const secMotor = document.getElementById('sectionAreaMotor');
    const secMobil = document.getElementById('sectionAreaMobil');

    if (filterArea === 'mobil') {
        secMotor.classList.add('d-none');
        secMobil.classList.remove('d-none');
    } else if (filterArea === 'motor') {
        secMotor.classList.remove('d-none');
        secMobil.classList.add('d-none');
    } else {
        secMotor.classList.remove('d-none');
        secMobil.classList.remove('d-none');
    }

    // Separate Motor slots into M01-M20 and M21-M40
    const motorSlots = denahData.slots.filter(s => s.jenis_slot === 'motor');
    motorSlots.forEach((s, idx) => {
        const isTop = idx < 20;
        const targetContainer = isTop ? rowMotorTop : rowMotorBottom;
        const bay = createBayElement(s, 'motor');
        targetContainer.appendChild(bay);
    });

    // Separate Mobil slots into A01-A10 and A11-A20
    const mobilSlots = denahData.slots.filter(s => s.jenis_slot === 'mobil');
    mobilSlots.forEach((s, idx) => {
        const isTop = idx < 10;
        const targetContainer = isTop ? rowMobilTop : rowMobilBottom;
        const bay = createBayElement(s, 'mobil');
        targetContainer.appendChild(bay);
    });
}

function createBayElement(slot, type) {
    const isOccupied = slot.status_slot === 'terisi';
    const isSelected = currentSelectedSlot && currentSelectedSlot.id_slot === slot.id_slot;

    const div = document.createElement('div');
    div.className = `${type === 'motor' ? 'bay-motor' : 'bay-mobil'} ${isOccupied ? 'bay-occupied' : 'bay-empty'} ${isSelected ? 'bay-selected' : ''}`;
    div.id = `bay-slot-${slot.id_slot}`;
    div.title = `Slot ${slot.kode_slot} (${slot.status_slot.toUpperCase()})`;

    // Top Tag
    const tagTop = document.createElement('div');
    tagTop.className = 'bay-tag';
    tagTop.innerText = slot.kode_slot;
    div.appendChild(tagTop);

    // Vehicle Canvas
    const canvas = document.createElement('div');
    canvas.className = 'bay-vehicle-canvas';

    if (isOccupied) {
        // Render Vehicle matching its database color!
        if (type === 'motor') {
            canvas.innerHTML = getMotorSvg(slot.warna);
        } else {
            canvas.innerHTML = getCarSvg(slot.warna);
        }
    }
    div.appendChild(canvas);

    // Bottom Tag
    const tagBottom = document.createElement('div');
    tagBottom.className = 'bay-tag';
    tagBottom.innerText = slot.kode_slot;
    div.appendChild(tagBottom);

    div.addEventListener('click', () => {
        selectSlot(slot);
    });

    return div;
}

function selectSlot(slot) {
    currentSelectedSlot = slot;

    // Highlight selected bay
    document.querySelectorAll('.bay-selected').forEach(el => el.classList.remove('bay-selected'));
    const targetBay = document.getElementById(`bay-slot-${slot.id_slot}`);
    if (targetBay) targetBay.classList.add('bay-selected');

    // Update Right Detail Panel
    const isOccupied = slot.status_slot === 'terisi';
    const isMobil = slot.jenis_slot === 'mobil';

    // Status Badge
    const badge = document.getElementById('panelStatusBadge');
    badge.className = `badge-status-lg ${isOccupied ? 'badge-status-occupied' : 'badge-status-empty'}`;
    badge.innerText = isOccupied ? 'TERISI' : 'KOSONG';

    // Slot Code & Type Icon
    document.getElementById('panelSlotCode').innerText = slot.kode_slot;
    const typeCircle = document.getElementById('panelTypeCircle');
    const typeIcon = document.getElementById('panelTypeIcon');
    typeIcon.className = `bi ${isMobil ? 'bi-car-front-fill' : 'bi-bicycle'}`;

    // Table rows
    document.getElementById('panelJenisSlot').innerText = isMobil ? 'Mobil' : 'Motor';
    document.getElementById('panelNamaArea').innerText = slot.nama_area;
    document.getElementById('panelZona').innerText = slot.lantai || 'Lantai 1';
    
    const statusText = document.getElementById('panelStatusText');
    statusText.className = isOccupied ? 'text-danger fw-bold' : 'text-success fw-bold';
    statusText.innerText = isOccupied ? 'Terisi' : 'Kosong';

    // Action button
    const actionBox = document.getElementById('panelActionBox');
    if (userRole === 'admin' || userRole === 'petugas') {
        actionBox.classList.remove('d-none');
        actionBox.innerHTML = `
            <button class="btn-slot-action ${isOccupied ? 'btn-slot-keluar' : 'btn-slot-parkir'}" onclick="handlePanelAction()">
                <i class="bi ${isMobil ? 'bi-car-front-fill' : 'bi-bicycle'}"></i>
                ${isOccupied ? 'Proses Kendaraan Keluar' : 'Parkirkan Kendaraan'}
            </button>
        `;
    } else {
        actionBox.classList.add('d-none');
    }

    // Informasi Slot Card
    document.getElementById('infoKodeSlot').innerText = slot.kode_slot;
    document.getElementById('infoJenisKendaraan').innerText = isMobil ? 'Mobil' : 'Motor';
    document.getElementById('infoWarnaKendaraan').innerText = slot.warna || '-';
    document.getElementById('infoTarif').innerText = `Rp ${Number(slot.tarif_per_jam || 5000).toLocaleString('id-ID')} / jam`;
    document.getElementById('infoDurasi').innerText = isOccupied ? (slot.teks_durasi || '1 jam') : '24 jam';

    const rowPlat = document.getElementById('rowInfoPlat');
    const rowMasuk = document.getElementById('rowInfoMasuk');

    if (isOccupied) {
        rowPlat.classList.remove('d-none');
        rowMasuk.classList.remove('d-none');
        document.getElementById('infoPlatNomor').innerText = slot.plat_nomor || '-';
        document.getElementById('infoWaktuMasuk').innerText = slot.waktu_masuk || '-';
    } else {
        rowPlat.classList.add('d-none');
        rowMasuk.classList.add('d-none');
    }

    const sec = document.getElementById('detailSlotSection');
    if (sec && sec.classList.contains('d-none')) {
        sec.classList.remove('d-none');
    }
    const infoBadge = document.getElementById('infoSlotBadge');
    if (infoBadge) {
        infoBadge.innerText = 'Slot ' + slot.kode_slot;
    }
}

function closeDetailSlot() {
    const sec = document.getElementById('detailSlotSection');
    if (sec) sec.classList.add('d-none');
    document.querySelectorAll('.bay-selected').forEach(el => el.classList.remove('bay-selected'));
    currentSelectedSlot = null;
}

function handlePanelAction() {
    if (!currentSelectedSlot) return;
    const slot = currentSelectedSlot;

    if (slot.status_slot === 'tersedia') {
        openCheckinModal(slot.id_slot, slot.kode_slot, slot.jenis_slot);
    } else if (slot.status_slot === 'terisi' && slot.id_parkir) {
        openCheckoutModal(slot.id_parkir, slot.plat_nomor, slot.kode_slot, slot.teks_durasi, slot.estimasi_biaya);
    }
}

function openCheckinModal(idSlot, kodeSlot, jenisSlot) {
    document.getElementById('checkinSlotId').value = idSlot;
    document.getElementById('checkinSlotKode').innerText = kodeSlot;
    document.getElementById('checkinSlotJenis').innerText = jenisSlot.toUpperCase();
    document.getElementById('inputJenisBaru').value = jenisSlot;

    const modal = new bootstrap.Modal(document.getElementById('checkinModal'));
    modal.show();
}

function openCheckoutModal(idParkir, platNomor, kodeSlot, teksDurasi, totalBiaya) {
    document.getElementById('checkoutIdParkir').value = idParkir;
    document.getElementById('checkoutModalBody').innerHTML = `
        <div class="text-center mb-3">
            <div class="display-6 fw-bold font-monospace">${escapeHtml(platNomor)}</div>
            <span class="badge bg-secondary">Slot ${escapeHtml(kodeSlot)}</span>
        </div>
        <div class="card p-3 bg-light border-0 mb-3">
            <div class="d-flex justify-content-between mb-1">
                <span class="text-secondary">Durasi Berjalan:</span>
                <span class="fw-bold">${escapeHtml(teksDurasi)}</span>
            </div>
            <div class="d-flex justify-content-between mb-1">
                <span class="text-secondary">Total Tagihan:</span>
                <span class="fw-bold text-danger fs-5">Rp ${Number(totalBiaya).toLocaleString('id-ID')}</span>
            </div>
        </div>
        <div class="mb-2">
            <label class="form-label small fw-bold">Nominal Pembayaran Diterima (Rp)</label>
            <input type="number" class="form-control form-control-lg font-monospace" id="inputBayar" placeholder="Contoh: 10000" min="${totalBiaya}" oninput="calcKembalian(${totalBiaya})">
        </div>
        <div class="p-2 rounded bg-success-subtle text-success-emphasis fw-bold text-center d-none" id="boxKembalian">
            Kembalian: <span id="teksKembalian">Rp 0</span>
        </div>
    `;

    const checkoutModal = new bootstrap.Modal(document.getElementById('checkoutModal'));
    checkoutModal.show();
}

function calcKembalian(total) {
    const val = parseFloat(document.getElementById('inputBayar').value) || 0;
    const box = document.getElementById('boxKembalian');
    const teks = document.getElementById('teksKembalian');
    if (val >= total) {
        const kembalian = val - total;
        teks.innerText = 'Rp ' + Number(kembalian).toLocaleString('id-ID');
        box.classList.remove('d-none');
    } else {
        box.classList.add('d-none');
    }
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

document.addEventListener('DOMContentLoaded', function() {
    fetchDenahData();

    document.getElementById('btnRefreshDenah')?.addEventListener('click', fetchDenahData);
    document.getElementById('filterAreaSelect')?.addEventListener('change', renderParkingSlots);

    // Auto-refresh polling every 10 seconds
    setInterval(fetchDenahData, 10000);

    // Select registered vehicle handler
    document.getElementById('selectKendaraan')?.addEventListener('change', function() {
        const box = document.getElementById('boxKendaraanBaru');
        const inputPlat = document.getElementById('inputPlatBaru');
        if (this.value === '0') {
            box.classList.remove('d-none');
            inputPlat.required = true;
        } else {
            box.classList.add('d-none');
            inputPlat.required = false;
        }
    });
});
</script>

<style>
@keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}
.spin-animation {
    animation: spin 0.8s linear infinite;
    display: inline-block;
}
</style>

<?php page_footer(); ?>
