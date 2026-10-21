<?php
/**
 * ParkirKu Automated Comprehensive Test Suite
 */

function curl_get(string $url, string $cookieFile): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_HEADER, false);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'body' => $body];
}

function curl_post(string $url, array $data, string $cookieFile): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_HEADER, false);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'body' => $body];
}

$baseUrl = "http://localhost/aplikasi-parkir/";
$cookieAdmin = __DIR__ . "/cookie_admin.txt";
$cookiePetugas = __DIR__ . "/cookie_petugas.txt";
$cookieOwner = __DIR__ . "/cookie_owner.txt";

@unlink($cookieAdmin);
@unlink($cookiePetugas);
@unlink($cookieOwner);

$passed = 0;
$failed = 0;

function assert_test(string $name, bool $condition, string $detail = "") {
    global $passed, $failed;
    if ($condition) {
        echo "[PASS] " . $name . "\n";
        $passed++;
    } else {
        echo "[FAIL] " . $name . " - Detail: " . $detail . "\n";
        $failed++;
    }
}

echo "=======================================================\n";
echo "       PARKIRKU AUTOMATED INTEGRATION TEST SUITE       \n";
echo "=======================================================\n\n";

// 1. GET Login Page & Extract CSRF
$r = curl_get($baseUrl . "login.php", $cookieAdmin);
assert_test("1. Login Page Status 200", $r['status'] === 200);
preg_match('/name="csrf_token"\s+value="([^"]+)"/', $r['body'], $m);
$csrf = $m[1] ?? "";
assert_test("2. Extract CSRF Token", !empty($csrf), "Token: " . $csrf);

// 2. Login as Admin
$r = curl_post($baseUrl . "login.php", [
    'csrf_token' => $csrf,
    'username' => 'admin1',
    'password' => 'admin123'
], $cookieAdmin);
assert_test("3. Login Admin Success", $r['status'] === 200 && str_contains($r['body'], "Dashboard Admin"));

// 3. Admin Dashboard Content
assert_test("4. Dashboard shows 8 Stat Widgets", str_contains($r['body'], "Total Slot") && str_contains($r['body'], "Sedang Parkir") && str_contains($r['body'], "Pendapatan"));
assert_test("5. Dashboard includes Chart.js script", str_contains($r['body'], "chart.umd.min.js"));

// 4. Denah Parkir Page
$r = curl_get($baseUrl . "denah.php", $cookieAdmin);
assert_test("6. Denah Parkir Status 200", $r['status'] === 200);
assert_test("7. Denah Parkir Visual Markers", str_contains($r['body'], "MASUK") && str_contains($r['body'], "KELUAR") && str_contains($r['body'], "sectionAreaMotor"));

// 5. API Denah Data (JSON)
$r = curl_get($baseUrl . "api/denah_data.php", $cookieAdmin);
$json = json_decode($r['body'], true);
assert_test("8. API Denah Status 200 & Valid JSON", $r['status'] === 200 && is_array($json));
assert_test("9. API Denah Success Flag", ($json['success'] ?? false) === true);
assert_test("10. API Denah returns slots data", count($json['slots'] ?? []) > 0, "Slots count: " . count($json['slots'] ?? []));
assert_test("11. API Denah returns stats", isset($json['stats']['total']) && isset($json['stats']['tersedia']));

// 6. Admin Management Pages
$r = curl_get($baseUrl . "admin/slot.php", $cookieAdmin);
assert_test("12. Admin Slot Management Page", $r['status'] === 200 && str_contains($r['body'], "Manajemen Slot Parkir"));

$r = curl_get($baseUrl . "admin/area.php", $cookieAdmin);
assert_test("13. Admin Area Page", $r['status'] === 200 && str_contains($r['body'], "Area Parkir"));

$r = curl_get($baseUrl . "admin/tarif.php", $cookieAdmin);
assert_test("14. Admin Tarif Page", $r['status'] === 200 && str_contains($r['body'], "Tarif Parkir"));

$r = curl_get($baseUrl . "admin/kendaraan.php", $cookieAdmin);
assert_test("15. Admin Kendaraan Page", $r['status'] === 200 && str_contains($r['body'], "Data Kendaraan"));

$r = curl_get($baseUrl . "admin/users.php", $cookieAdmin);
assert_test("16. Admin Users Page", $r['status'] === 200 && str_contains($r['body'], "Manajemen Pengguna"));

$r = curl_get($baseUrl . "admin/laporan.php", $cookieAdmin);
assert_test("17. Admin Laporan Page", $r['status'] === 200 && str_contains($r['body'], "Laporan"));

$r = curl_get($baseUrl . "admin/log.php", $cookieAdmin);
assert_test("18. Admin Log Page", $r['status'] === 200 && str_contains($r['body'], "Log Aktivitas Sistem"));

// 7. Login as Petugas
$r = curl_get($baseUrl . "login.php", $cookiePetugas);
preg_match('/name="csrf_token"\s+value="([^"]+)"/', $r['body'], $m);
$csrfPetugas = $m[1] ?? "";

$r = curl_post($baseUrl . "login.php", [
    'csrf_token' => $csrfPetugas,
    'username' => 'petugas1',
    'password' => 'petugas123'
], $cookiePetugas);
assert_test("19. Login Petugas Success", $r['status'] === 200 && str_contains($r['body'], "Dashboard Operasional Petugas"));

// 8. Petugas Transaksi Page
$r = curl_get($baseUrl . "petugas/transaksi.php", $cookiePetugas);
assert_test("20. Petugas Transaksi Page", $r['status'] === 200 && str_contains($r['body'], "Transaksi Parkir"));

// 9. Perform Check-in via POST on Denah or Transaksi
preg_match('/name="csrf_token"\s+value="([^"]+)"/', $r['body'], $m);
$csrfTrx = $m[1] ?? "";

// Find available slot ID
$rApi = curl_get($baseUrl . "api/denah_data.php", $cookiePetugas);
$apiData = json_decode($rApi['body'], true);
$targetSlot = null;
foreach ($apiData['slots'] as $sl) {
    if ($sl['status_slot'] === 'tersedia') {
        $targetSlot = $sl;
        break;
    }
}
assert_test("21. Find Available Slot for Test", $targetSlot !== null, "Slot: " . ($targetSlot['kode_slot'] ?? 'None'));

if ($targetSlot) {
    $testPlat = "B " . rand(1000, 9999) . " TST";
    $rMasuk = curl_post($baseUrl . "denah.php", [
        'csrf_token' => $csrfTrx,
        'action' => 'masuk_slot',
        'id_slot' => $targetSlot['id_slot'],
        'id_kendaraan' => 0,
        'plat_nomor_baru' => $testPlat,
        'jenis_kendaraan' => $targetSlot['jenis_slot'],
        'warna' => 'Biru Metalik',
        'pemilik' => 'Tester User'
    ], $cookiePetugas);

    // Verify slot is now 'terisi' via API
    $rApiAfter = curl_get($baseUrl . "api/denah_data.php", $cookiePetugas);
    $apiAfter = json_decode($rApiAfter['body'], true);
    $slotAfter = null;
    foreach ($apiAfter['slots'] as $sl) {
        if ($sl['id_slot'] === $targetSlot['id_slot']) {
            $slotAfter = $sl;
            break;
        }
    }
    assert_test("22. Vehicle Check-In Success & Slot Status 'terisi'", $slotAfter['status_slot'] === 'terisi' && $slotAfter['plat_nomor'] === $testPlat);

    // Test Struk Printing
    $idTrx = $slotAfter['id_parkir'];
    $rStruk = curl_get($baseUrl . "petugas/struk.php?id=" . $idTrx, $cookiePetugas);
    assert_test("23. Cetak Struk Masuk Valid", $rStruk['status'] === 200 && str_contains($rStruk['body'], $testPlat));

    // Test Check-Out
    preg_match('/name="csrf_token"\s+value="([^"]+)"/', $rMasuk['body'], $m);
    $csrfOut = $m[1] ?? $csrfTrx;

    $rKeluar = curl_post($baseUrl . "denah.php", [
        'csrf_token' => $csrfOut,
        'action' => 'keluar_slot',
        'id_parkir' => $idTrx
    ], $cookiePetugas);

    // Verify slot is now 'tersedia' again
    $rApiOut = curl_get($baseUrl . "api/denah_data.php", $cookiePetugas);
    $apiOut = json_decode($rApiOut['body'], true);
    $slotOut = null;
    foreach ($apiOut['slots'] as $sl) {
        if ($sl['id_slot'] === $targetSlot['id_slot']) {
            $slotOut = $sl;
            break;
        }
    }
    assert_test("24. Vehicle Check-Out Success & Slot Back to 'tersedia'", $slotOut['status_slot'] === 'tersedia');

    // Test Struk after Keluar
    $rStrukOut = curl_get($baseUrl . "petugas/struk.php?id=" . $idTrx, $cookiePetugas);
    assert_test("25. Cetak Struk Selesai Valid with Final Bill", $rStrukOut['status'] === 200 && str_contains($rStrukOut['body'], "TOTAL BIAYA"));
}

// 10. Login as Owner
$r = curl_get($baseUrl . "login.php", $cookieOwner);
preg_match('/name="csrf_token"\s+value="([^"]+)"/', $r['body'], $m);
$csrfOwner = $m[1] ?? "";

$r = curl_post($baseUrl . "login.php", [
    'csrf_token' => $csrfOwner,
    'username' => 'owner1',
    'password' => 'owner123'
], $cookieOwner);
assert_test("26. Login Owner Success", $r['status'] === 200 && str_contains($r['body'], "Dashboard Eksekutif Owner"));

$r = curl_get($baseUrl . "owner/rekap.php", $cookieOwner);
assert_test("27. Owner Rekap Page Status 200", $r['status'] === 200 && str_contains($r['body'], "Rekap Transaksi"));

// 11. Security & RBAC Enforcement Check
$rForbidden = curl_get($baseUrl . "admin/users.php", $cookiePetugas);
assert_test("28. Security RBAC: Petugas cannot access admin/users.php", $rForbidden['status'] === 403);

$rForbiddenOwner = curl_get($baseUrl . "admin/slot.php", $cookieOwner);
assert_test("29. Security RBAC: Owner cannot access admin/slot.php", $rForbiddenOwner['status'] === 403);

@unlink($cookieAdmin);
@unlink($cookiePetugas);
@unlink($cookieOwner);

echo "\n=======================================================\n";
echo "TEST RESULTS: {$passed} PASSED, {$failed} FAILED\n";
echo "=======================================================\n";
