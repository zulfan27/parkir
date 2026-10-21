# Flowchart / Pseudocode

## Login
```text
MULAI
Input username dan password
Cari user berdasarkan username
Jika user tidak ada atau status nonaktif -> tampilkan pesan gagal
Jika password tidak cocok -> tampilkan pesan gagal
Jika password cocok -> regenerasi session, catat log
Alihkan berdasarkan role:
  admin -> Dashboard Admin
  petugas -> Dashboard Petugas
  owner -> Dashboard Owner
SELESAI
```

## Transaksi kendaraan masuk
```text
MULAI
Petugas memilih kendaraan dan area
BEGIN TRANSACTION
Kunci baris area dengan SELECT ... FOR UPDATE
Jika area tidak ada atau terisi >= kapasitas -> ROLLBACK dan tampilkan pesan
Jika kendaraan masih berstatus masuk -> ROLLBACK dan tampilkan pesan
Cari tarif berdasarkan jenis kendaraan
Simpan transaksi status masuk
Tambah nilai terisi pada area
Simpan log aktivitas
COMMIT
Tampilkan konfirmasi
SELESAI
```

## Transaksi kendaraan keluar dan cetak struk
```text
MULAI
Petugas memilih transaksi berstatus masuk
BEGIN TRANSACTION dan kunci transaksi
Jika transaksi tidak ditemukan atau sudah keluar -> ROLLBACK
Hitung durasi = maksimum 1 jam, dibulatkan ke atas
Hitung biaya = durasi x tarif per jam
Isi waktu_keluar, durasi_jam, biaya_total, status keluar
Kurangi terisi area dan simpan log aktivitas
COMMIT
Tampilkan struk yang mengambil data transaksi tersimpan
SELESAI
```

## Rekap Owner
```text
MULAI
Owner memasukkan tanggal awal dan akhir
Validasi format tanggal
Ambil transaksi pada rentang tanggal (maksimum 500 baris)
Hitung jumlah transaksi dan total biaya transaksi berstatus keluar
Tampilkan tabel dan sediakan cetak browser
SELESAI
```
