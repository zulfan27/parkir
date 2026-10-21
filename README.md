# ParkirKu — Sistem Informasi Manajemen Parkir Modern

Aplikasi web modern berbasis **PHP Native** dan **MySQL/MariaDB** untuk pengelolaan operasional tempat parkir, visualisasi denah slot parkir interaktif real-time, pencatatan transaksi masuk dan keluar, perhitungan tarif otomatis, cetak struk thermal, manajemen hak akses multi-role (Admin, Petugas, Owner), serta laporan analitik pendapatan.

---

## 1. Kebutuhan Sistem

- **Web Server:** Apache (via XAMPP)
- **Database:** MySQL / MariaDB (via XAMPP)
- **PHP:** Versi 8.1 ke atas (dilengkapi ekstensi `mysqli`, `session`, `json`, `curl`)
- **Browser:** Google Chrome, Microsoft Edge, Mozilla Firefox, atau browser modern lainnya
- **Aset Vendor:** Sudah di-bundle lokal di dalam `assets/vendor/` (Bootstrap 5.3 & Chart.js 4.4), sehingga aplikasi dapat berjalan normal tanpa koneksi internet (offline).

---

## 2. Cara Menjalankan Aplikasi di XAMPP

1. **Letakkan Proyek:**
   Pastikan folder proyek berada di direktori web root XAMPP:
   ```
   C:\xampp\htdocs\aplikasi-parkir
   ```

2. **Jalankan XAMPP Control Panel:**
   - Buka **XAMPP Control Panel**.
   - Klik tombol **Start** pada modul **Apache**.
   - Klik tombol **Start** pada modul **MySQL**.

3. **Impor Database:**
   - Buka browser dan akses [http://localhost/phpmyadmin](http://localhost/phpmyadmin).
   - Buat database baru bernama `parkir` (atau biarkan script SQL yang membuatnya otomatis).
   - Pilih menu **Import**, klik **Choose File**, pilih file:
     ```
     C:\xampp\htdocs\aplikasi-parkir\database\parkir.sql
     ```
   - Klik **Import / Kirim** di bagian bawah.
   *(Jika database lama sudah ada dan ingin menambahkan slot tanpa menghapus data lama, cukup jalankan `database/migration_slot.sql`).*

4. **Konfigurasi Database:**
   File konfigurasi terletak di `config/config.php`:
   ```php
   return [
       'db_host' => '127.0.0.1',
       'db_name' => 'parkir',
       'db_user' => 'root',
       'db_pass' => '',
       'base_url' => '/aplikasi-parkir',
       'timezone' => 'Asia/Jakarta',
   ];
   ```

5. **Akses Aplikasi:**
   Buka peramban (browser) dan ketik alamat:
   ```
   http://localhost/aplikasi-parkir/
   ```

---

## 3. Akun Demo Tersedia

Semua akun terdaftar aktif di database dan telah dilengkapi tombol **Akun Demo Cepat** pada halaman login:

| Role | Username | Password | Deskripsi Kewenangan |
|---|---|---|---|
| **Admin** | `admin1` | `admin123` | Akses penuh: Dashboard analitik, Denah Parkir, Kelola Slot & Bulk Generator, Area Parkir, Tarif, Data Kendaraan, Pengguna, Laporan, dan Log Aktivitas. |
| **Petugas** | `petugas1` | `petugas123` | Akses operasional: Dashboard operasional, Denah Parkir, Pencatatan Kendaraan Masuk/Keluar, Kalkulator Kembalian, dan Cetak Struk. |
| **Owner** | `owner1` | `owner123` | Akses eksekutif: Dashboard finansial, Tren Pendapatan 7 Hari, Denah Parkir, Rekap Pendapatan, dan Ekspor CSV/Cetak Laporan. |

---

## 4. Fitur Utama ParkirKu

### 🚗 A. Denah Parkir Interaktif Real-Time (Fitur Unggulan)
- Visualisasi denah parkir layaknya area sungguhan: penanda **PINTU MASUK** & **PINTU KELUAR**, marka garis pembatas slot, dan **JALUR KENDARAAN SATU ARAH**.
- **Warna Status Slot:**
  - 🟢 **Hijau (Tersedia):** Slot kosong siap ditempati kendaraan baru.
  - 🔴 **Merah (Terisi):** Menampilkan ilustrasi mobil/motor, plat nomor, waktu masuk, dan durasi berjalan.
  - ⚪ **Abu-abu (Nonaktif):** Slot dalam pemeliharaan/perbaikan.
- **Interaksi Satu Sentuh (Modal Dialog):**
  - Klik slot kosong: Langsung muncul tombol **Parkirkan Kendaraan** dengan slot otomatis terpilih.
  - Klik slot terisi: Langsung muncul rincian kendaraan, durasi, estimasi biaya, dan tombol **Proses Keluar**.
- **Pembaruan Otomatis (Live AJAX Polling):** Denah memperbarui status secara otomatis setiap 10 detik tanpa perlu me-reload seluruh halaman.
- **Filter Cepat:** Berdasarkan Area/Zona, Jenis Kendaraan (Mobil/Motor), dan Status Slot.

### 📊 B. Dashboard Khusus per Role
- **Admin Dashboard:** 8 kartu statistik aktual (Total Slot, Sedang Parkir, Masuk Hari Ini, Keluar Hari Ini, Pendapatan Hari Ini, Pendapatan Bulan Ini, Okupansi %, Slot Nonaktif), Grafik Arus Kendaraan 7 Hari (Chart.js), Grafik Okupansi Area (Doughnut Chart), dan Transaksi Terkini.
- **Petugas Dashboard:** Kartu operasional cepat, daftar kendaraan yang sedang parkir beserta durasi berjalan dan estimasi biaya, serta tombol proses keluar cepat.
- **Owner Dashboard:** Statistik pendapatan harian, bulanan, dan total, grafik tren omzet, diagram komposisi pendapatan per jenis kendaraan, dan rekapitulasi.

### ⚡ C. Transaksi Parkir Terintegrasi
- **Check-In (Masuk):** Validasi plat nomor, alokasi slot aktif, pencegahan kendaraan parkir ganda, pencatatan otomatis waktu server dan petugas login.
- **Check-Out (Keluar):** Pencarian plat atau nomor tiket cepat, perhitungan durasi per jam (pembulatan ke atas sesuai aturan), kalkulator uang diterima & kembalian, pengosongan slot kembali ke database, dan pencetakan struk.

### 🧾 D. Cetak Struk Parkir Profesional
- Layout ramah kertas thermal (ukuran 58mm / 80mm) dan kertas standar A4/A5.
- Dilengkapi barcode identifikasi tiket, rincian waktu masuk/keluar, durasi, tarif/jam, total biaya, nama petugas, dan tanggal cetak.

### 🛠️ E. Manajemen Slot & Area (Dengan Bulk Generator)
- Manajemen slot individual: kode slot, jenis slot, lantai, keterangan.
- **Fitur Bulk Slot Generator:** Men-generate puluhan slot sekaligus (contoh: Prefix `A-`, dari nomor 1 sampai 20) hanya dalam satu klik.
- Proteksi database: Mencegah penghapusan slot atau area yang masih ditempati transaksi aktif.

### 📑 F. Laporan, Rekapitulasi & Ekspor
- Filter tanggal fleksibel, filter status (masuk/keluar), jenis kendaraan, dan petugas.
- Tombol **Ekspor CSV** untuk pengolahan di Microsoft Excel.
- Tampilan ramah cetak (**Print-ready view**).

### 🔒 G. Keamanan & Kestabilan
- Dilengkapi **CSRF Token** pada semua formulir POST.
- Menggunakan **Prepared Statements (MySQLi)** untuk mencegah SQL Injection.
- **Output Escaping (`htmlspecialchars`)** untuk mencegah XSS.
- Hashing password menggunakan algoritma Bcrypt via `password_hash()`.
- Pembatasan Role-Based Access Control (RBAC) ketat di level backend pada setiap halaman dan endpoint API.

---

## 5. Cara Backup dan Restore Database

### Melakukan Backup:
Jalankan perintah berikut di PowerShell atau Command Prompt:
```powershell
C:\xampp\mysql\bin\mysqldump.exe -u root parkir > C:\xampp\htdocs\aplikasi-parkir\database\backup_terbaru.sql
```

### Melakukan Restore:
```powershell
Get-Content C:\xampp\htdocs\aplikasi-parkir\database\backup_terbaru.sql | C:\xampp\mysql\bin\mysql.exe -u root parkir
```

---

## 6. Pengujian Sistem (Automated Test Suite)

Aplikasi telah diuji menggunakan script pengujian otomatis `test_suite.php` yang mencakup 29 skenario pengujian:

Jalankan test suite kapan saja dengan perintah:
```powershell
php test_suite.php
```

**Hasil Pengujian Terakhir:**
```
=======================================================
       PARKIRKU AUTOMATED INTEGRATION TEST SUITE       
=======================================================
[PASS] 1. Login Page Status 200
[PASS] 2. Extract CSRF Token
[PASS] 3. Login Admin Success
[PASS] 4. Dashboard shows 8 Stat Widgets
[PASS] 5. Dashboard includes Chart.js script
[PASS] 6. Denah Parkir Status 200
[PASS] 7. Denah Parkir Visual Markers
[PASS] 8. API Denah Status 200 & Valid JSON
[PASS] 9. API Denah Success Flag
[PASS] 10. API Denah returns slots data
[PASS] 11. API Denah returns stats
[PASS] 12. Admin Slot Management Page
[PASS] 13. Admin Area Page
[PASS] 14. Admin Tarif Page
[PASS] 15. Admin Kendaraan Page
[PASS] 16. Admin Users Page
[PASS] 17. Admin Laporan Page
[PASS] 18. Admin Log Page
[PASS] 19. Login Petugas Success
[PASS] 20. Petugas Transaksi Page
[PASS] 21. Find Available Slot for Test
[PASS] 22. Vehicle Check-In Success & Slot Status 'terisi'
[PASS] 23. Cetak Struk Masuk Valid
[PASS] 24. Vehicle Check-Out Success & Slot Back to 'tersedia'
[PASS] 25. Cetak Struk Selesai Valid with Final Bill
[PASS] 26. Login Owner Success
[PASS] 27. Owner Rekap Page Status 200
[PASS] 28. Security RBAC: Petugas cannot access admin/users.php
[PASS] 29. Security RBAC: Owner cannot access admin/slot.php
=======================================================
TEST RESULTS: 29 PASSED, 0 FAILED (100% SUKSES)
=======================================================
```
