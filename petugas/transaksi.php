<?php
require_once __DIR__ . "/../includes/bootstrap.php";
require_role(["petugas", "admin"]);

$uid = (int)current_user()["id_user"];

// Handle POST actions
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    verify_csrf();
    $action = post("action");

    try {
        if ($action === "masuk") {
            $idK = (int)post("id_kendaraan");
            $idSlot = (int)post("id_slot");
            $platBaru = strtoupper(trim(post("plat_nomor_baru")));

            $db->begin_transaction();

            // 1. Resolve Slot & Area
            $sSlot = $db->prepare("SELECT s.*, a.id_area, a.kapasitas, a.terisi FROM tb_slot_parkir s JOIN tb_area_parkir a ON a.id_area = s.id_area WHERE s.id_slot = ? FOR UPDATE");
            $sSlot->bind_param("i", $idSlot);
            $sSlot->execute();
            $slot = $sSlot->get_result()->fetch_assoc();

            if (!$slot) {
                throw new RuntimeException("Slot parkir tidak ditemukan.");
            }
            if ($slot["status_slot"] !== "tersedia") {
                throw new RuntimeException("Slot {$slot['kode_slot']} sudah tidak tersedia.");
            }
            if ((int)$slot["terisi"] >= (int)$slot["kapasitas"]) {
                throw new RuntimeException("Area parkir {$slot['nama_area']} sudah penuh.");
            }

            // 2. Resolve Vehicle
            if ($idK === 0 && $platBaru !== "") {
                // Check if vehicle exists
                $sCheck = $db->prepare("SELECT id_kendaraan, jenis_kendaraan FROM tb_kendaraan WHERE plat_nomor = ? LIMIT 1");
                $sCheck->bind_param("s", $platBaru);
                $sCheck->execute();
                $existing = $sCheck->get_result()->fetch_assoc();

                if ($existing) {
                    $idK = (int)$existing["id_kendaraan"];
                    $jenisK = $existing["jenis_kendaraan"];
                } else {
                    $jenisK = post("jenis_kendaraan", $slot["jenis_slot"]);
                    $warna = post("warna");
                    $pemilik = post("pemilik");
                    $sInsK = $db->prepare("INSERT INTO tb_kendaraan (plat_nomor, jenis_kendaraan, warna, pemilik, id_user) VALUES (?, ?, ?, ?, ?)");
                    $sInsK->bind_param("ssssi", $platBaru, $jenisK, $warna, $pemilik, $uid);
                    $sInsK->execute();
                    $idK = $db->insert_id;
                }
            } else {
                $sK = $db->prepare("SELECT jenis_kendaraan FROM tb_kendaraan WHERE id_kendaraan = ?");
                $sK->bind_param("i", $idK);
                $sK->execute();
                $kRow = $sK->get_result()->fetch_assoc();
                if (!$kRow) {
                    throw new RuntimeException("Kendaraan belum dipilih.");
                }
                $jenisK = $kRow["jenis_kendaraan"];
            }

            // 3. Ensure no active transaction for this vehicle
            $sTrxCheck = $db->prepare("SELECT id_parkir FROM tb_transaksi WHERE id_kendaraan = ? AND status = 'masuk' LIMIT 1");
            $sTrxCheck->bind_param("i", $idK);
            $sTrxCheck->execute();
            if ($sTrxCheck->get_result()->fetch_assoc()) {
                throw new RuntimeException("Kendaraan ini masih tercatat di dalam area parkir.");
            }

            // 4. Resolve Rate from tb_tarif
            $sTarif = $db->prepare("SELECT id_tarif FROM tb_tarif WHERE jenis_kendaraan = ? LIMIT 1");
            $sTarif->bind_param("s", $jenisK);
            $sTarif->execute();
            $tRow = $sTarif->get_result()->fetch_assoc();
            if (!$tRow) {
                // Fallback to slot type
                $sTarif->bind_param("s", $slot["jenis_slot"]);
                $sTarif->execute();
                $tRow = $sTarif->get_result()->fetch_assoc();
                if (!$tRow) {
                    throw new RuntimeException("Tarif untuk jenis kendaraan ini belum tersedia di database.");
                }
            }
            $idTarif = (int)$tRow["id_tarif"];

            // 5. Insert Transaction
            $sInsTrx = $db->prepare("INSERT INTO tb_transaksi (id_kendaraan, waktu_masuk, id_tarif, status, id_user, id_area, id_slot) VALUES (?, NOW(), ?, 'masuk', ?, ?, ?)");
            $sInsTrx->bind_param("iiiii", $idK, $idTarif, $uid, $slot["id_area"], $idSlot);
            $sInsTrx->execute();
            $idTrx = $db->insert_id;

            // 6. Update Slot & Area status
            $sUpSlot = $db->prepare("UPDATE tb_slot_parkir SET status_slot = 'terisi' WHERE id_slot = ?");
            $sUpSlot->bind_param("i", $idSlot);
            $sUpSlot->execute();

            $sUpArea = $db->prepare("UPDATE tb_area_parkir SET terisi = terisi + 1 WHERE id_area = ?");
            $sUpArea->bind_param("i", $slot["id_area"]);
            $sUpArea->execute();

            log_activity($db, $uid, "Kendaraan masuk di Slot {$slot['kode_slot']}, ID Trx {$idTrx}");
            $db->commit();

            flash("success", "Kendaraan berhasil masuk di Slot <strong>{$slot['kode_slot']}</strong>. <a href='" . url("petugas/struk.php?id=" . $idTrx) . "' target='_blank' class='btn btn-sm btn-light ms-2'><i class='bi bi-printer'></i> Cetak Struk</a>");
        } elseif ($action === "keluar") {
            $id = (int)post("id_parkir");
            $db->begin_transaction();

            $s = $db->prepare("
                SELECT t.id_parkir, t.waktu_masuk, t.status, t.id_area, t.id_slot, tr.tarif_per_jam, k.plat_nomor, s.kode_slot
                FROM tb_transaksi t
                JOIN tb_tarif tr ON tr.id_tarif = t.id_tarif
                JOIN tb_kendaraan k ON k.id_kendaraan = t.id_kendaraan
                LEFT JOIN tb_slot_parkir s ON s.id_slot = t.id_slot
                WHERE t.id_parkir = ? FOR UPDATE
            ");
            $s->bind_param("i", $id);
            $s->execute();
            $t = $s->get_result()->fetch_assoc();

            if (!$t || $t["status"] !== "masuk") {
                throw new RuntimeException("Transaksi tidak ditemukan atau sudah diproses keluar.");
            }

            $diffSec = max(0, time() - strtotime($t["waktu_masuk"]));
            $durasiJam = max(1, (int)ceil($diffSec / 3600));
            $totalBiaya = $durasiJam * (float)$t["tarif_per_jam"];

            // Update Transaction
            $sUp = $db->prepare("UPDATE tb_transaksi SET waktu_keluar = NOW(), durasi_jam = ?, biaya_total = ?, status = 'keluar' WHERE id_parkir = ?");
            $sUp->bind_param("idi", $durasiJam, $totalBiaya, $id);
            $sUp->execute();

            // Free slot
            if (!empty($t["id_slot"])) {
                $sFree = $db->prepare("UPDATE tb_slot_parkir SET status_slot = 'tersedia' WHERE id_slot = ?");
                $sFree->bind_param("i", $t["id_slot"]);
                $sFree->execute();
            }

            // Decrement Area terisi
            $sDec = $db->prepare("UPDATE tb_area_parkir SET terisi = GREATEST(0, terisi - 1) WHERE id_area = ?");
            $sDec->bind_param("i", $t["id_area"]);
            $sDec->execute();

            log_activity($db, $uid, "Kendaraan keluar {$t['plat_nomor']}, ID Trx {$id}");
            $db->commit();

            flash("success", "Kendaraan <strong>{$t['plat_nomor']}</strong> berhasil diproses keluar. Total Biaya: <strong>" . rupiah($totalBiaya) . "</strong>. <a href='" . url("petugas/struk.php?id=" . $id) . "' target='_blank' class='btn btn-sm btn-light ms-2'><i class='bi bi-printer'></i> Cetak Struk</a>");
        }
    } catch (Throwable $e) {
        $db->rollback();
        flash("danger", $e->getMessage());
    }
    redirect("petugas/transaksi.php");
}

// Available slots for check-in form
$availableSlots = $db->query("
    SELECT s.id_slot, s.kode_slot, s.jenis_slot, s.lantai, a.nama_area 
    FROM tb_slot_parkir s 
    JOIN tb_area_parkir a ON a.id_area = s.id_area 
    WHERE s.status_slot = 'tersedia' 
    ORDER BY s.id_area ASC, s.kode_slot ASC
");

// Registered vehicles without active parking
$kendList = $db->query("
    SELECT id_kendaraan, plat_nomor, jenis_kendaraan, warna, pemilik 
    FROM tb_kendaraan 
    WHERE id_kendaraan NOT IN (SELECT id_kendaraan FROM tb_transaksi WHERE status = 'masuk')
    ORDER BY plat_nomor ASC
");

// Search filter for checkout
$cariPlat = strtoupper(trim($_GET["cari_plat"] ?? ""));

// Active parking transactions
$sqlActive = "
    SELECT 
        t.id_parkir, k.plat_nomor, k.jenis_kendaraan, k.warna, k.pemilik,
        a.nama_area, s.kode_slot, t.waktu_masuk, tr.tarif_per_jam
    FROM tb_transaksi t
    JOIN tb_kendaraan k ON k.id_kendaraan = t.id_kendaraan
    JOIN tb_area_parkir a ON a.id_area = t.id_area
    JOIN tb_tarif tr ON tr.id_tarif = t.id_tarif
    LEFT JOIN tb_slot_parkir s ON s.id_slot = t.id_slot
    WHERE t.status = 'masuk'
";
if ($cariPlat !== "") {
    $sqlActive .= " AND (k.plat_nomor LIKE '%{$cariPlat}%' OR s.kode_slot LIKE '%{$cariPlat}%' OR t.id_parkir = '{$cariPlat}')";
}
$sqlActive .= " ORDER BY t.waktu_masuk DESC LIMIT 50";
$activeTrx = $db->query($sqlActive);

// Recent completed transactions
$recentTrx = $db->query("
    SELECT 
        t.id_parkir, k.plat_nomor, k.jenis_kendaraan, a.nama_area, s.kode_slot,
        t.waktu_masuk, t.waktu_keluar, t.durasi_jam, t.biaya_total, t.status
    FROM tb_transaksi t
    JOIN tb_kendaraan k ON k.id_kendaraan = t.id_kendaraan
    JOIN tb_area_parkir a ON a.id_area = t.id_area
    LEFT JOIN tb_slot_parkir s ON s.id_slot = t.id_slot
    ORDER BY t.id_parkir DESC 
    LIMIT 20
");

page_header("Transaksi Parkir");
?>

<div class="row g-4 mb-4">
    <!-- Form Kendaraan Masuk -->
    <div class="col-lg-5">
        <div class="pk-card p-4 h-100">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="fw-bold mb-0 text-primary"><i class="bi bi-box-arrow-in-right me-1"></i> Kendaraan Masuk (Check-In)</h5>
                <a href="<?= e(url("denah.php")) ?>" class="btn btn-sm btn-outline-primary" title="Pilih dari Denah"><i class="bi bi-map"></i> Denah</a>
            </div>

            <form method="post" id="formMasuk">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="masuk">

                <div class="mb-3">
                    <label class="form-label small fw-bold">Slot Parkir Tersedia *</label>
                    <select class="form-select font-monospace" name="id_slot" id="slotSelect" required>
                        <?php if ($availableSlots->num_rows === 0): ?>
                            <option value="" disabled selected>-- Semua Slot Penuh / Tidak Tersedia --</option>
                        <?php else: ?>
                            <option value="">-- Pilih Slot Kosong --</option>
                            <?php while ($sl = $availableSlots->fetch_assoc()): ?>
                                <option value="<?= $sl['id_slot'] ?>" data-jenis="<?= $sl['jenis_slot'] ?>">
                                    [<?= e($sl['kode_slot']) ?>] <?= e($sl['nama_area']) ?> — <?= ucfirst($sl['jenis_slot']) ?>
                                </option>
                            <?php endwhile; ?>
                        <?php endif; ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-bold">Kendaraan</label>
                    <select class="form-select" name="id_kendaraan" id="selectKendaraanTrx">
                        <option value="0">-- Input Kendaraan Baru / Ketik Plat --</option>
                        <?php while ($kv = $kendList->fetch_assoc()): ?>
                            <option value="<?= $kv['id_kendaraan'] ?>" data-jenis="<?= $kv['jenis_kendaraan'] ?>">
                                <?= e($kv['plat_nomor']) ?> — <?= ucfirst($kv['jenis_kendaraan']) ?> (<?= e($kv['pemilik'] ?: 'Umum') ?>)
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <!-- Box Input Kendaraan Baru -->
                <div id="boxPlatBaru" class="p-3 bg-light rounded border mb-3">
                    <div class="mb-2">
                        <label class="form-label small fw-bold">Nomor Polisi (Plat) *</label>
                        <input class="form-control text-uppercase font-monospace fs-6" name="plat_nomor_baru" id="inputPlatMasuk" placeholder="Contoh: B 1234 ABC" maxlength="15">
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col-6">
                            <label class="form-label small">Jenis</label>
                            <select class="form-select form-select-sm" name="jenis_kendaraan" id="selectJenisMasuk">
                                <option value="mobil">Mobil</option>
                                <option value="motor">Motor</option>
                                <option value="lainnya">Lainnya</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label small">Warna</label>
                            <select class="form-select form-select-sm" name="warna">
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
                        <label class="form-label small">Nama Pemilik (Opsional)</label>
                        <input class="form-control form-control-sm" name="pemilik" placeholder="Nama pemilik/sopir">
                    </div>
                </div>

                <button class="btn btn-primary w-100 py-2 fw-semibold" id="btnSubmitMasuk" <?= $availableSlots->num_rows === 0 ? 'disabled' : '' ?>>
                    <i class="bi bi-box-arrow-in-right me-1"></i> Simpan Kendaraan Masuk
                </button>
            </form>
        </div>
    </div>

    <!-- Live Search & Checkout Helper -->
    <div class="col-lg-7">
        <div class="pk-card p-4 h-100">
            <h5 class="fw-bold mb-3 text-danger"><i class="bi bi-search me-1"></i> Cari Kendaraan Keluar Cepat</h5>
            <form method="get" class="row g-2 mb-3">
                <div class="col-8">
                    <div class="input-group">
                        <span class="input-group-text bg-light"><i class="bi bi-upc-scan"></i></span>
                        <input class="form-control text-uppercase font-monospace" name="cari_plat" placeholder="Ketik nomor plat / kode slot..." value="<?= e($cariPlat) ?>" autofocus>
                    </div>
                </div>
                <div class="col-4 d-flex gap-1">
                    <button class="btn btn-danger w-100"><i class="bi bi-search"></i> Cari</button>
                    <?php if ($cariPlat !== ""): ?>
                        <a href="<?= e(url("petugas/transaksi.php")) ?>" class="btn btn-outline-secondary" title="Reset"><i class="bi bi-x-lg"></i></a>
                    <?php endif; ?>
                </div>
            </form>

            <div class="alert alert-secondary small py-2 mb-0">
                <i class="bi bi-lightbulb-fill text-warning me-1"></i> Tips: Petugas dapat mengetik sebagian plat nomor kendaraan atau kode slot di atas untuk langsung menemukan dan memproses kendaraan keluar.
            </div>
        </div>
    </div>
</div>

<!-- Active Parking Table -->
<div class="pk-card p-4 mb-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="fw-bold mb-0"><i class="bi bi-car-front-fill text-primary me-2"></i> Kendaraan Sedang Parkir Aktif</h5>
        <span class="badge bg-danger"><?= $activeTrx->num_rows ?> Kendaraan</span>
    </div>

    <div class="table-responsive">
        <table class="table pk-table">
            <thead>
                <tr>
                    <th>Plat Nomor</th>
                    <th>Jenis</th>
                    <th>Slot & Area</th>
                    <th>Waktu Masuk</th>
                    <th>Durasi Berjalan</th>
                    <th>Estimasi Biaya</th>
                    <th class="text-end">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($activeTrx->num_rows === 0): ?>
                    <tr>
                        <td colspan="7" class="text-center py-4 text-muted">Tidak ada kendaraan parkir aktif yang cocok dengan pencarian.</td>
                    </tr>
                <?php endif; ?>
                <?php while ($r = $activeTrx->fetch_assoc()): ?>
                    <?php $calc = hitung_biaya($r["waktu_masuk"], (float)$r["tarif_per_jam"]); ?>
                    <tr>
                        <td class="font-monospace fw-bold fs-6"><?= e($r["plat_nomor"]) ?></td>
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
                        <td class="fw-bold text-primary small"><?= $calc["teks_durasi"] ?></td>
                        <td class="fw-bold text-danger"><?= rupiah($calc["biaya"]) ?></td>
                        <td class="text-end">
                            <a href="<?= e(url("petugas/struk.php?id=" . $r["id_parkir"])) ?>" target="_blank" class="btn btn-sm btn-outline-secondary" title="Cetak Struk Masuk">
                                <i class="bi bi-printer"></i>
                            </a>
                            <button type="button" class="btn btn-sm btn-success fw-semibold" onclick="openCheckoutModal(<?= $r['id_parkir'] ?>, '<?= e($r['plat_nomor']) ?>', '<?= e($r['kode_slot'] ?: '-') ?>', '<?= e($calc['teks_durasi']) ?>', <?= $calc['biaya'] ?>)">
                                <i class="bi bi-box-arrow-right"></i> Proses Keluar
                            </button>
                        </td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Recent Transactions Table -->
<div class="pk-card p-4">
    <h5 class="fw-bold mb-3"><i class="bi bi-clock-history text-secondary me-2"></i> Transaksi Terakhir (20 Data)</h5>
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
                    <th class="text-end">Struk</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($t = $recentTrx->fetch_assoc()): ?>
                    <tr>
                        <td class="font-monospace text-secondary">#<?= $t["id_parkir"] ?></td>
                        <td class="font-monospace fw-bold"><?= e($t["plat_nomor"]) ?></td>
                        <td>
                            <span class="badge <?= $t["jenis_kendaraan"] === 'motor' ? 'bg-info text-dark' : 'bg-primary' ?>">
                                <?= ucfirst($t["jenis_kendaraan"]) ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge bg-dark font-monospace"><?= e($t["kode_slot"] ?: "-") ?></span>
                            <small class="text-secondary d-block"><?= e($t["nama_area"]) ?></small>
                        </td>
                        <td class="small"><?= date("d/m H:i", strtotime($t["waktu_masuk"])) ?></td>
                        <td class="small"><?= $t["waktu_keluar"] ? date("d/m H:i", strtotime($t["waktu_keluar"])) : '<span class="text-muted">-</span>' ?></td>
                        <td><?= $t["durasi_jam"] ? $t["durasi_jam"] . " jam" : "-" ?></td>
                        <td class="fw-bold text-success">
                            <?= $t["status"] === 'keluar' ? rupiah($t["biaya_total"]) : '<span class="text-muted">-</span>' ?>
                        </td>
                        <td>
                            <span class="badge-pk <?= $t["status"] === 'keluar' ? 'badge-pk-success' : 'badge-pk-danger' ?>">
                                <?= ucfirst($t["status"]) ?>
                            </span>
                        </td>
                        <td class="text-end">
                            <a href="<?= e(url("petugas/struk.php?id=" . $t["id_parkir"])) ?>" target="_blank" class="btn btn-sm btn-outline-primary" title="Cetak Struk">
                                <i class="bi bi-printer"></i>
                            </a>
                        </td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal Proses Keluar Transaksi -->
<div class="modal fade" id="modalTrxKeluar" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="keluar">
                <input type="hidden" name="id_parkir" id="modalKeluarIdParkir">

                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title fw-bold"><i class="bi bi-box-arrow-right me-1"></i> Proses Kendaraan Keluar</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="text-center mb-3">
                        <div class="display-6 fw-bold font-monospace" id="modalKeluarPlat">-</div>
                        <span class="badge bg-secondary" id="modalKeluarSlot">Slot -</span>
                    </div>

                    <div class="card p-3 bg-light border-0 mb-3">
                        <div class="d-flex justify-content-between mb-1">
                            <span class="text-secondary">Durasi Parkir:</span>
                            <span class="fw-bold" id="modalKeluarDurasi">-</span>
                        </div>
                        <div class="d-flex justify-content-between mb-1">
                            <span class="text-secondary">Total Biaya:</span>
                            <span class="fw-bold text-danger fs-5" id="modalKeluarTotal">-</span>
                        </div>
                    </div>

                    <div class="mb-2">
                        <label class="form-label small fw-bold">Nominal Pembayaran Diterima (Rp)</label>
                        <input type="number" class="form-control form-control-lg font-monospace" id="modalInputBayar" placeholder="Masukkan jumlah uang" oninput="calcKembalianTrx()">
                    </div>
                    <div class="p-2 rounded bg-success-subtle text-success-emphasis fw-bold text-center d-none" id="modalBoxKembalian">
                        Kembalian: <span id="modalTeksKembalian">Rp 0</span>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-danger fw-semibold"><i class="bi bi-check2-circle"></i> Konfirmasi Selesai</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
let currentKeluarTotal = 0;

function openCheckoutModal(idParkir, platNomor, kodeSlot, teksDurasi, totalBiaya) {
    currentKeluarTotal = totalBiaya;
    document.getElementById('modalKeluarIdParkir').value = idParkir;
    document.getElementById('modalKeluarPlat').innerText = platNomor;
    document.getElementById('modalKeluarSlot').innerText = 'Slot ' + kodeSlot;
    document.getElementById('modalKeluarDurasi').innerText = teksDurasi;
    document.getElementById('modalKeluarTotal').innerText = 'Rp ' + Number(totalBiaya).toLocaleString('id-ID');
    document.getElementById('modalInputBayar').value = '';
    document.getElementById('modalInputBayar').min = totalBiaya;
    document.getElementById('modalBoxKembalian').classList.add('d-none');

    const modal = new bootstrap.Modal(document.getElementById('modalTrxKeluar'));
    modal.show();
}

function calcKembalianTrx() {
    const val = parseFloat(document.getElementById('modalInputBayar').value) || 0;
    const box = document.getElementById('modalBoxKembalian');
    const teks = document.getElementById('modalTeksKembalian');
    if (val >= currentKeluarTotal) {
        const kembalian = val - currentKeluarTotal;
        teks.innerText = 'Rp ' + Number(kembalian).toLocaleString('id-ID');
        box.classList.remove('d-none');
    } else {
        box.classList.add('d-none');
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const selectK = document.getElementById('selectKendaraanTrx');
    const boxPlat = document.getElementById('boxPlatBaru');
    const inputPlat = document.getElementById('inputPlatMasuk');

    selectK?.addEventListener('change', function() {
        if (this.value === '0') {
            boxPlat.classList.remove('d-none');
            inputPlat.required = true;
        } else {
            boxPlat.classList.add('d-none');
            inputPlat.required = false;
        }
    });

    // Auto-adjust vehicle type when slot is selected
    const slotSelect = document.getElementById('slotSelect');
    const selectJenis = document.getElementById('selectJenisMasuk');
    slotSelect?.addEventListener('change', function() {
        const selectedOpt = this.options[this.selectedIndex];
        const jenis = selectedOpt.getAttribute('data-jenis');
        if (jenis && selectJenis) {
            selectJenis.value = jenis;
        }
    });
});
</script>

<?php page_footer(); ?>
