<?php
require_once __DIR__ . "/../includes/bootstrap.php";
require_role(["petugas", "admin", "owner"]);

$id = get_int("id");
$s = $db->prepare("
    SELECT 
        t.*, 
        k.plat_nomor, k.jenis_kendaraan, k.warna, k.pemilik,
        a.nama_area, 
        s.kode_slot,
        tr.tarif_per_jam, 
        u.nama_lengkap AS nama_petugas
    FROM tb_transaksi t 
    JOIN tb_kendaraan k ON k.id_kendaraan = t.id_kendaraan 
    JOIN tb_area_parkir a ON a.id_area = t.id_area 
    JOIN tb_tarif tr ON tr.id_tarif = t.id_tarif 
    JOIN tb_user u ON u.id_user = t.id_user 
    LEFT JOIN tb_slot_parkir s ON s.id_slot = t.id_slot
    WHERE t.id_parkir = ?
");
$s->bind_param("i", $id);
$s->execute();
$t = $s->get_result()->fetch_assoc();

if (!$t) {
    http_response_code(404);
    exit("Struk transaksi #{$id} tidak ditemukan.");
}

$isKeluar = $t["status"] === "keluar";
$durasi = $t["durasi_jam"] ? $t["durasi_jam"] . " Jam" : "-";
$biaya = $isKeluar ? rupiah($t["biaya_total"]) : "Belum dihitung (Masih Parkir)";
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Struk ParkirKu #<?= $t["id_parkir"] ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= e(url("assets/css/style.css")) ?>">
</head>
<body style="background: #f1f5f9; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 1.5rem;">

<div class="receipt-box">
    <!-- Header -->
    <div class="receipt-header">
        <h3 class="mb-0">PARKIRKU</h3>
        <small class="text-secondary fw-semibold">SISTEM MANAJEMEN PARKIR</small>
        <div class="small text-muted mt-1">Struk Bukti Parkir Resmi</div>
    </div>

    <!-- Info Transaksi -->
    <div class="receipt-body">
        <div class="receipt-row">
            <span>No. Tiket</span>
            <strong>#<?= str_pad((string)$t["id_parkir"], 6, "0", STR_PAD_LEFT) ?></strong>
        </div>
        <div class="receipt-row">
            <span>Nomor Polisi</span>
            <strong style="font-size: 1.1rem;"><?= e($t["plat_nomor"]) ?></strong>
        </div>
        <div class="receipt-row">
            <span>Jenis Kendaraan</span>
            <span><?= ucfirst($t["jenis_kendaraan"]) ?></span>
        </div>
        <div class="receipt-row">
            <span>Slot Parkir</span>
            <strong><?= e($t["kode_slot"] ?: "-") ?></strong>
        </div>
        <div class="receipt-row">
            <span>Area</span>
            <span><?= e($t["nama_area"]) ?></span>
        </div>
        <div class="receipt-row">
            <span>Pemilik / Sopir</span>
            <span><?= e($t["pemilik"] ?: "Umum") ?></span>
        </div>

        <div class="receipt-divider"></div>

        <div class="receipt-row">
            <span>Waktu Masuk</span>
            <span><?= date("d/m/Y H:i:s", strtotime($t["waktu_masuk"])) ?></span>
        </div>
        <div class="receipt-row">
            <span>Waktu Keluar</span>
            <span><?= $t["waktu_keluar"] ? date("d/m/Y H:i:s", strtotime($t["waktu_keluar"])) : "Belum Keluar" ?></span>
        </div>
        <div class="receipt-row">
            <span>Durasi Dihitung</span>
            <span><?= $durasi ?></span>
        </div>
        <div class="receipt-row">
            <span>Tarif per Jam</span>
            <span><?= rupiah($t["tarif_per_jam"]) ?></span>
        </div>

        <div class="receipt-divider"></div>

        <div class="receipt-total">
            <span>TOTAL BIAYA</span>
            <span><?= $biaya ?></span>
        </div>

        <div class="receipt-divider"></div>

        <div class="receipt-row small text-secondary">
            <span>Petugas</span>
            <span><?= e($t["nama_petugas"]) ?></span>
        </div>
        <div class="receipt-row small text-secondary">
            <span>Waktu Cetak</span>
            <span><?= date("d/m/Y H:i:s") ?> WIB</span>
        </div>
    </div>

    <!-- Barcode / QR Simulation for Thermal Receipt -->
    <div class="text-center my-3 py-2 border-top border-bottom">
        <div class="font-monospace fw-bold" style="letter-spacing: 5px; font-size: 0.9rem;">
            *PKR-<?= str_pad((string)$t["id_parkir"], 6, "0", STR_PAD_LEFT) ?>*
        </div>
        <small class="text-muted" style="font-size: 0.7rem;">Simpan struk ini sebagai bukti pembayaran</small>
    </div>

    <!-- Footer -->
    <div class="receipt-footer">
        <div class="fw-bold">Terima Kasih atas Kunjungan Anda</div>
        <div>Semoga Selamat Sampai Tujuan</div>
    </div>

    <!-- Buttons -->
    <div class="text-center no-print mt-4 d-flex gap-2 justify-content-center">
        <button class="btn btn-primary btn-sm fw-semibold px-3" onclick="window.print()">
            <i class="bi bi-printer me-1"></i> Cetak Struk
        </button>
        <button class="btn btn-secondary btn-sm px-3" onclick="window.close()">
            <i class="bi bi-x-lg me-1"></i> Tutup
        </button>
    </div>
</div>

</body>
</html>
