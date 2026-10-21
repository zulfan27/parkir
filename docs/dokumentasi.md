# Dokumentasi Aplikasi Parkir

## 1. Analisis kebutuhan
Aplikasi mengelola parkir dengan tiga role. Admin mengelola user, tarif, area, kendaraan, dan log. Petugas mengelola transaksi masuk/keluar serta cetak struk. Owner melihat rekap berdasarkan tanggal.

## 2. Kamus data
| Tabel | Kolom | Tipe | Key | Keterangan |
|---|---|---|---|---|
| tb_user | id_user | INT(11) | PK | ID user |
| tb_user | nama_lengkap | VARCHAR(50) | - | Nama lengkap |
| tb_user | username | VARCHAR(50) | UNIQUE | Nama login |
| tb_user | password | VARCHAR(255) | - | Hash password |
| tb_user | role | ENUM | - | admin/petugas/owner |
| tb_user | status_aktif | TINYINT(1) | - | 1 aktif, 0 nonaktif |
| tb_kendaraan | id_kendaraan | INT(11) | PK | ID kendaraan |
| tb_kendaraan | plat_nomor | VARCHAR(15) | UNIQUE | Nomor polisi |
| tb_kendaraan | jenis_kendaraan | VARCHAR(20) | - | motor/mobil/lainnya |
| tb_kendaraan | warna | VARCHAR(20) | - | Warna kendaraan |
| tb_kendaraan | pemilik | VARCHAR(100) | - | Nama pemilik |
| tb_kendaraan | id_user | INT(11) | FK | User pencatat |
| tb_tarif | id_tarif | INT(11) | PK | ID tarif |
| tb_tarif | jenis_kendaraan | ENUM | UNIQUE | Kategori kendaraan |
| tb_tarif | tarif_per_jam | DECIMAL(10,0) | - | Tarif rupiah per jam |
| tb_area_parkir | id_area | INT(11) | PK | ID area |
| tb_area_parkir | nama_area | VARCHAR(50) | - | Nama area |
| tb_area_parkir | kapasitas | INT(5) | - | Kapasitas maksimum |
| tb_area_parkir | terisi | INT(5) | - | Slot terisi |
| tb_transaksi | id_parkir | INT(11) | PK | ID transaksi |
| tb_transaksi | id_kendaraan | INT(11) | FK | Kendaraan |
| tb_transaksi | waktu_masuk | DATETIME | - | Waktu masuk |
| tb_transaksi | waktu_keluar | DATETIME NULL | - | Waktu keluar |
| tb_transaksi | id_tarif | INT(11) | FK | Tarif yang dipakai |
| tb_transaksi | durasi_jam | INT(5) NULL | - | Durasi dibulatkan ke atas |
| tb_transaksi | biaya_total | DECIMAL(10,0) NULL | - | Total biaya |
| tb_transaksi | status | ENUM | - | masuk/keluar |
| tb_transaksi | id_user | INT(11) | FK | Petugas |
| tb_transaksi | id_area | INT(11) | FK | Area parkir |
| tb_log_aktivitas | id_log | INT(11) | PK | ID log |
| tb_log_aktivitas | id_user | INT(11) | FK | User yang beraktivitas |
| tb_log_aktivitas | aktivitas | VARCHAR(100) | - | Deskripsi aktivitas |
| tb_log_aktivitas | waktu_aktivitas | DATETIME | - | Waktu log |

## 3. Relasi ERD
- Satu user dapat mencatat banyak kendaraan; satu kendaraan dicatat oleh satu user.
- Satu kendaraan dapat muncul pada banyak transaksi sepanjang waktu; satu transaksi merujuk satu kendaraan.
- Satu tarif dapat digunakan oleh banyak transaksi; satu transaksi memakai satu tarif.
- Satu user dapat memproses banyak transaksi; satu transaksi memiliki satu user pemroses.
- Satu area dapat menampung banyak transaksi; satu transaksi tercatat pada satu area.
- Satu user dapat memiliki banyak catatan log; satu catatan log merujuk satu user.

## 4. Dokumentasi modul (input, proses, output)
| Modul | Input | Proses | Output |
|---|---|---|---|
| Login | username, password | Validasi user/status/password, buat session, log | Dashboard sesuai role atau pesan gagal |
| CRUD User | nama, username, role, status, password saat tambah | Validasi, prepared statement, simpan/perbarui/hapus | Daftar user dan pesan |
| CRUD Tarif | jenis, tarif/jam | Validasi kategori dan nilai, simpan perubahan | Daftar tarif |
| CRUD Area | nama, kapasitas | Validasi kapasitas tidak di bawah terisi | Daftar area dan slot tersisa |
| CRUD Kendaraan | plat, jenis, warna, pemilik | Validasi dan simpan data | Daftar kendaraan |
| Transaksi masuk | kendaraan, area | Lock area, cek kapasitas/duplikasi, insert transaksi, tambah terisi, log, commit | Pesan berhasil/gagal |
| Transaksi keluar | ID transaksi | Lock transaksi, hitung durasi/biaya, ubah status, kurangi terisi, log, commit | Biaya dan struk |
| Cetak struk | ID transaksi | Ambil data transaksi dan format struk | Halaman cetak |
| Rekap Owner | tanggal awal/akhir | Query rentang waktu, hitung pendapatan transaksi selesai | Tabel rekap dan cetak |
| Log aktivitas | halaman log | Query log dengan limit/paginasi | Daftar aktivitas |

## 5. Fungsi dan prosedur
- `e($value)`: mengamankan output HTML dengan `htmlspecialchars`.
- `url($path)`: membentuk URL berdasarkan base URL aplikasi.
- `redirect($path)`: mengarahkan halaman.
- `current_user()`: mengambil identitas dari session.
- `require_login()` dan `require_role($roles)`: kontrol autentikasi/otorisasi.
- `csrf_token()` dan `verify_csrf()`: melindungi formulir POST dari CSRF.
- `log_activity($db, $userId, $activity)`: menulis aktivitas ke `tb_log_aktivitas`.
- `page_header($title)` dan `page_footer()`: template tampilan bersama.
- Transaksi masuk dan keluar berada di `petugas/transaksi.php`, menggunakan `begin_transaction()`, `commit()`, dan `rollback()`.
- Stored procedure `sp_parkir_keluar` disediakan dalam `database/parkir.sql`.

## 6. Keamanan dan coding guidelines
- Query yang menerima input menggunakan prepared statements.
- Output dinamis HTML di-escape.
- Password user baru disimpan dengan `password_hash`; seed SHA-256 dari jobsheet ditingkatkan ke hash PHP ketika login berhasil.
- Session diregenerasi setelah login.
- Formulir POST memakai CSRF token.
- Role diverifikasi di sisi server, bukan hanya menyembunyikan menu.
- Daftar besar menggunakan `LIMIT`; rekap dibatasi 500 baris.
- Transaksi parkir menggunakan InnoDB dan penguncian `FOR UPDATE`.

## 7. Hak akses aplikasi
| Fitur | Admin | Petugas | Owner |
|---|---:|---:|---:|
| Login/logout | Ya | Ya | Ya |
| CRUD user | Ya | Tidak | Tidak |
| CRUD tarif | Ya | Tidak | Tidak |
| CRUD area | Ya | Tidak | Tidak |
| CRUD kendaraan | Ya | Tidak | Tidak |
| Log aktivitas | Ya | Tidak | Tidak |
| Transaksi masuk/keluar | Tidak | Ya | Tidak |
| Cetak struk | Tidak | Ya | Tidak |
| Rekap rentang waktu | Tidak | Tidak | Ya |

## 8. Debugging dan hasil pengujian
Belum ada hasil eksekusi aktual yang dapat dinyatakan lulus dari dokumen ini. Jalankan skenario berikut, ambil screenshot, lalu isi hasil berdasarkan pengamatan:
1. Login masing-masing role dengan akun demo.
2. Coba akses URL admin menggunakan akun Petugas; harus ditolak dengan HTTP 403.
3. Input kendaraan masuk pada area tersedia; periksa transaksi dan nilai `terisi`.
4. Coba masuk pada area penuh; aplikasi harus menolak.
5. Proses keluar; periksa status, durasi, biaya, kapasitas, dan log.
6. Cetak struk transaksi.
7. Owner filter rentang tanggal; cocokkan jumlah dan pendapatan dengan SQL.
8. Uji CRUD dan duplikasi plat/username.
9. Backup dan restore `parkir.sql`/hasil ekspor ke database uji.
10. Periksa `SHOW TABLES`, engine InnoDB, FK, view, dan stored procedure.

Tabel catatan aktual:
| No. | Skenario | Hasil aktual | Bukti/screenshot | Status |
|---|---|---|---|---|
| 1 | Login Admin | Isi setelah diuji | Lampiran 1 | Belum diuji |
| 2 | Login Petugas | Isi setelah diuji | Lampiran 2 | Belum diuji |
| 3 | Login Owner | Isi setelah diuji | Lampiran 3 | Belum diuji |
| 4 | Transaksi masuk | Isi setelah diuji | Lampiran 4 | Belum diuji |
| 5 | Transaksi keluar dan struk | Isi setelah diuji | Lampiran 5 | Belum diuji |
| 6 | Area penuh | Isi setelah diuji | Lampiran 6 | Belum diuji |
| 7 | Rekap Owner | Isi setelah diuji | Lampiran 7 | Belum diuji |
| 8 | Hak akses role | Isi setelah diuji | Lampiran 8 | Belum diuji |

## 9. Backup dan restore
Backup melalui Shell XAMPP:
```bat
mkdir C:\backup
mysqldump -u root --databases parkir --routines --triggers --single-transaction > C:\backup\parkir_full.sql
```
Restore dari Shell XAMPP:
```bat
mysql -u root < C:\backup\parkir_full.sql
```
Jalankan perintah di Shell XAMPP/CMD, bukan pada prompt `MariaDB>`. Pastikan file backup benar-benar ada dan uji restore ke database terpisah sebelum menghapus database sumber.

## 10. Evaluasi singkat
**Fitur yang dirancang:** login tiga role, CRUD admin, transaksi petugas, cetak struk, log aktivitas, rekap Owner, validasi kapasitas, prepared statements, CSRF, transaksi database.

**Bug yang belum diperbaiki:** belum diketahui karena aplikasi belum diuji pada lingkungan target. Catat bug nyata beserta pesan error dan langkah reproduksinya setelah pengujian.

**Rencana pengembangan:** cetak PDF, ekspor CSV, pencarian dan filter lanjutan, konfigurasi koneksi MySQL dengan akun terbatas, pengaturan password, audit log yang lebih lengkap, automated test, serta deployment HTTPS.

## 11. Batasan
Aplikasi ini merupakan prototipe pembelajaran. Login awal memakai data demo. Konfigurasi role fitur berada di aplikasi; akun MySQL khusus dari latihan DCL tidak otomatis digunakan oleh koneksi aplikasi. Jangan men-deploy ke internet tanpa audit keamanan, HTTPS, pengaturan hak akses, backup terjadwal, dan penggantian kredensial.
