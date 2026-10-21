<?php
require_once __DIR__ . '/../includes/bootstrap.php';

// 1. Reset active transactions safely
$db->query("SET FOREIGN_KEY_CHECKS = 0");
$db->query("DELETE FROM tb_transaksi WHERE status='masuk'");
$db->query("UPDATE tb_slot_parkir SET status_slot='tersedia'");

// 2. Sample vehicles with explicit colors
$vehicles = [
    // Mobil
    ['B 1002 BLK', 'mobil', 'Hitam', 'Budi Hartono'],
    ['B 1005 RED', 'mobil', 'Merah', 'Ratna Sari'],
    ['B 1006 GRY', 'mobil', 'Abu-abu', 'Ahmad Dani'],
    ['B 1008 BLK', 'mobil', 'Hitam', 'Surya Wijaya'],
    ['B 1010 RED', 'mobil', 'Merah', 'Dewi Lestari'],
    ['B 1012 BLK', 'mobil', 'Hitam', 'Fajar Ramadhan'],
    ['B 1013 WHT', 'mobil', 'Putih', 'Siti Rahma'],
    ['B 1014 SLV', 'mobil', 'Silver', 'Hendra Gunawan'],
    ['B 1015 BLK', 'mobil', 'Hitam', 'Rizal Pratama'],
    ['B 1016 RED', 'mobil', 'Merah', 'Maya Anggraini'],
    ['B 1017 WHT', 'mobil', 'Putih', 'Kurniawan'],
    ['B 1018 BLU', 'mobil', 'Biru', 'Eko Prasetyo'],
    ['B 1004 YEL', 'mobil', 'Kuning', 'Lina Marlina'],
    ['B 1007 GRN', 'mobil', 'Hijau', 'Dian Sastro'],
    
    // Motor
    ['M 2001 BLK', 'motor', 'Hitam', 'Irfan Bachdim'],
    ['M 2003 RED', 'motor', 'Merah', 'Agus Setiawan'],
    ['M 2004 BLK', 'motor', 'Hitam', 'Rudi Hartono'],
    ['M 2009 BLU', 'motor', 'Biru', 'Bambang Tri'],
    ['M 2010 RED', 'motor', 'Merah', 'Wahyudi'],
    ['M 2012 BLK', 'motor', 'Hitam', 'Taufik Hidayat'],
    ['M 2013 WHT', 'motor', 'Putih', 'Fitri Handayani'],
    ['M 2014 RED', 'motor', 'Merah', 'Dimas Anggara'],
    ['M 2015 BLK', 'motor', 'Hitam', 'Bayu Skak'],
    ['M 2017 BLK', 'motor', 'Hitam', 'Gilang Dirga'],
    ['M 2019 RED', 'motor', 'Merah', 'Reza Rahadian'],
    ['M 2020 BLK', 'motor', 'Hitam', 'Vino G Bastian'],
    ['M 2021 RED', 'motor', 'Merah', 'Adipati Dolken'],
    ['M 2022 BLK', 'motor', 'Hitam', 'Raffi Ahmad'],
    ['M 2023 BLU', 'motor', 'Biru', 'Denny Cagur'],
    ['M 2024 RED', 'motor', 'Merah', 'Sule'],
    ['M 2026 BLK', 'motor', 'Hitam', 'Andre Taulany'],
    ['M 2027 RED', 'motor', 'Merah', 'Wendy Cagur'],
    ['M 2028 BLK', 'motor', 'Hitam', 'Parto Patrio'],
    ['M 2029 RED', 'motor', 'Merah', 'Komeng'],
    ['M 2031 BLK', 'motor', 'Hitam', 'Desta Mahendra'],
    ['M 2032 WHT', 'motor', 'Putih', 'Vincent Rompies'],
    ['M 2033 RED', 'motor', 'Merah', 'Hesti Purwadinata'],
    ['M 2034 BLK', 'motor', 'Hitam', 'Enzy Storia'],
    ['M 2035 RED', 'motor', 'Merah', 'Gading Marten'],
    ['M 2036 BLU', 'motor', 'Biru', 'Kaesang'],
    ['M 2037 RED', 'motor', 'Merah', 'Gibran'],
    ['M 2038 BLK', 'motor', 'Hitam', 'Bobby Nasution']
];

$stmtK = $db->prepare("INSERT INTO tb_kendaraan (plat_nomor, jenis_kendaraan, warna, pemilik, id_user) VALUES (?, ?, ?, ?, 2) ON DUPLICATE KEY UPDATE warna=VALUES(warna), pemilik=VALUES(pemilik)");

foreach ($vehicles as $v) {
    $stmtK->bind_param("ssss", $v[0], $v[1], $v[2], $v[3]);
    $stmtK->execute();
}

// 3. Map vehicles to specific slots matching reference image
$carSlots = [
    'A02' => 'B 1002 BLK',
    'A05' => 'B 1005 RED',
    'A06' => 'B 1006 GRY',
    'A08' => 'B 1008 BLK',
    'A10' => 'B 1010 RED',
    'A12' => 'B 1012 BLK',
    'A13' => 'B 1013 WHT',
    'A14' => 'B 1014 SLV',
    'A15' => 'B 1015 BLK',
    'A16' => 'B 1016 RED',
    'A17' => 'B 1017 WHT',
    'A18' => 'B 1018 BLU',
    'A04' => 'B 1004 YEL',
    'A07' => 'B 1007 GRN'
];

$motorSlots = [
    'M01' => 'M 2001 BLK',
    'M03' => 'M 2003 RED',
    'M04' => 'M 2004 BLK',
    'M09' => 'M 2009 BLU',
    'M10' => 'M 2010 RED',
    'M12' => 'M 2012 BLK',
    'M13' => 'M 2013 WHT',
    'M14' => 'M 2014 RED',
    'M15' => 'M 2015 BLK',
    'M17' => 'M 2017 BLK',
    'M19' => 'M 2019 RED',
    'M20' => 'M 2020 BLK',
    'M21' => 'M 2021 RED',
    'M22' => 'M 2022 BLK',
    'M23' => 'M 2023 BLU',
    'M24' => 'M 2024 RED',
    'M26' => 'M 2026 BLK',
    'M27' => 'M 2027 RED',
    'M28' => 'M 2028 BLK',
    'M29' => 'M 2029 RED',
    'M31' => 'M 2031 BLK',
    'M32' => 'M 2032 WHT',
    'M33' => 'M 2033 RED',
    'M34' => 'M 2034 BLK',
    'M35' => 'M 2035 RED',
    'M36' => 'M 2036 BLU',
    'M37' => 'M 2037 RED',
    'M38' => 'M 2038 BLK'
];

$sTrx = $db->prepare("INSERT INTO tb_transaksi (id_kendaraan, waktu_masuk, id_tarif, status, id_user, id_area, id_slot) VALUES (?, DATE_SUB(NOW(), INTERVAL ? MINUTE), ?, 'masuk', 2, ?, ?)");

// Insert Car transactions
foreach ($carSlots as $code => $plat) {
    $k = $db->query("SELECT id_kendaraan FROM tb_kendaraan WHERE plat_nomor='{$plat}'")->fetch_assoc();
    $s = $db->query("SELECT id_slot, id_area FROM tb_slot_parkir WHERE kode_slot='{$code}'")->fetch_assoc();
    if ($k && $s) {
        $interval = rand(25, 240);
        $idTarif = 2; // mobil
        $sTrx->bind_param("iiiii", $k['id_kendaraan'], $interval, $idTarif, $s['id_area'], $s['id_slot']);
        $sTrx->execute();
        $db->query("UPDATE tb_slot_parkir SET status_slot='terisi' WHERE id_slot={$s['id_slot']}");
    }
}

// Insert Motor transactions
foreach ($motorSlots as $code => $plat) {
    $k = $db->query("SELECT id_kendaraan FROM tb_kendaraan WHERE plat_nomor='{$plat}'")->fetch_assoc();
    $s = $db->query("SELECT id_slot, id_area FROM tb_slot_parkir WHERE kode_slot='{$code}'")->fetch_assoc();
    if ($k && $s) {
        $interval = rand(20, 180);
        $idTarif = 1; // motor
        $sTrx->bind_param("iiiii", $k['id_kendaraan'], $interval, $idTarif, $s['id_area'], $s['id_slot']);
        $sTrx->execute();
        $db->query("UPDATE tb_slot_parkir SET status_slot='terisi' WHERE id_slot={$s['id_slot']}");
    }
}

// 4. Update area count
$terisiMotor = count($motorSlots);
$terisiMobil = count($carSlots);
$db->query("UPDATE tb_area_parkir SET terisi={$terisiMotor} WHERE id_area=1");
$db->query("UPDATE tb_area_parkir SET terisi={$terisiMobil} WHERE id_area=2");

$db->query("SET FOREIGN_KEY_CHECKS = 1");

echo "Seeded successfully! Motor: {$terisiMotor} terisi, Mobil: {$terisiMobil} terisi.\n";
