-- Migrasi Penambahan Tabel Slot Parkir untuk ParkirKu
-- Dijalankan secara aman tanpa merusak data lama

USE parkir;

-- 1. Buat tabel tb_slot_parkir jika belum ada
CREATE TABLE IF NOT EXISTS tb_slot_parkir (
  id_slot INT(11) NOT NULL AUTO_INCREMENT,
  id_area INT(11) NOT NULL,
  kode_slot VARCHAR(20) NOT NULL UNIQUE,
  jenis_slot ENUM('motor','mobil','lainnya') NOT NULL DEFAULT 'mobil',
  lantai VARCHAR(20) NOT NULL DEFAULT 'Lantai 1',
  status_slot ENUM('tersedia','terisi','nonaktif') NOT NULL DEFAULT 'tersedia',
  keterangan VARCHAR(100) NULL,
  urutan INT(5) NOT NULL DEFAULT 0,
  PRIMARY KEY (id_slot),
  KEY idx_slot_area (id_area),
  KEY idx_slot_status (status_slot),
  CONSTRAINT fk_slot_area FOREIGN KEY (id_area) REFERENCES tb_area_parkir(id_area) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 2. Tambahkan kolom id_slot pada tb_transaksi jika belum ada
SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS 
  WHERE TABLE_SCHEMA = 'parkir' 
    AND TABLE_NAME = 'tb_transaksi' 
    AND COLUMN_NAME = 'id_slot'
);

SET @sql_add_col = IF(@col_exists = 0, 
  'ALTER TABLE tb_transaksi ADD COLUMN id_slot INT(11) NULL AFTER id_area', 
  'SELECT "Column id_slot already exists"'
);
PREPARE stmt FROM @sql_add_col;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 3. Tambahkan foreign key fk_trx_slot jika belum ada
SET @fk_exists = (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS 
  WHERE TABLE_SCHEMA = 'parkir' 
    AND TABLE_NAME = 'tb_transaksi' 
    AND CONSTRAINT_NAME = 'fk_trx_slot'
);

SET @sql_add_fk = IF(@fk_exists = 0, 
  'ALTER TABLE tb_transaksi ADD CONSTRAINT fk_trx_slot FOREIGN KEY (id_slot) REFERENCES tb_slot_parkir(id_slot) ON DELETE SET NULL', 
  'SELECT "FK fk_trx_slot already exists"'
);
PREPARE stmt_fk FROM @sql_add_fk;
EXECUTE stmt_fk;
DEALLOCATE PREPARE stmt_fk;

-- 4. Seed slot awal jika tabel tb_slot_parkir masih kosong
INSERT IGNORE INTO tb_slot_parkir (id_area, kode_slot, jenis_slot, lantai, status_slot, urutan) VALUES
-- Area A: Motor (10 slot baris 1, 10 slot baris 2)
(1, 'A-01', 'motor', 'Lantai 1', 'tersedia', 1),
(1, 'A-02', 'motor', 'Lantai 1', 'tersedia', 2),
(1, 'A-03', 'motor', 'Lantai 1', 'tersedia', 3),
(1, 'A-04', 'motor', 'Lantai 1', 'tersedia', 4),
(1, 'A-05', 'motor', 'Lantai 1', 'tersedia', 5),
(1, 'A-06', 'motor', 'Lantai 1', 'tersedia', 6),
(1, 'A-07', 'motor', 'Lantai 1', 'tersedia', 7),
(1, 'A-08', 'motor', 'Lantai 1', 'tersedia', 8),
(1, 'A-09', 'motor', 'Lantai 1', 'tersedia', 9),
(1, 'A-10', 'motor', 'Lantai 1', 'tersedia', 10),
(1, 'A-11', 'motor', 'Lantai 1', 'tersedia', 11),
(1, 'A-12', 'motor', 'Lantai 1', 'tersedia', 12),
(1, 'A-13', 'motor', 'Lantai 1', 'tersedia', 13),
(1, 'A-14', 'motor', 'Lantai 1', 'tersedia', 14),
(1, 'A-15', 'motor', 'Lantai 1', 'tersedia', 15),

-- Area B: Mobil (10 slot baris 1, 5 slot baris 2)
(2, 'B-01', 'mobil', 'Lantai 1', 'tersedia', 1),
(2, 'B-02', 'mobil', 'Lantai 1', 'tersedia', 2),
(2, 'B-03', 'mobil', 'Lantai 1', 'tersedia', 3),
(2, 'B-04', 'mobil', 'Lantai 1', 'tersedia', 4),
(2, 'B-05', 'mobil', 'Lantai 1', 'tersedia', 5),
(2, 'B-06', 'mobil', 'Lantai 1', 'tersedia', 6),
(2, 'B-07', 'mobil', 'Lantai 1', 'tersedia', 7),
(2, 'B-08', 'mobil', 'Lantai 1', 'tersedia', 8),
(2, 'B-09', 'mobil', 'Lantai 1', 'tersedia', 9),
(2, 'B-10', 'mobil', 'Lantai 1', 'tersedia', 10);

-- 5. Hubungkan transaksi aktif yang sudah ada ke slot dan set status slot menjadi 'terisi'
UPDATE tb_transaksi t 
JOIN tb_slot_parkir s ON s.kode_slot = 'A-01'
SET t.id_slot = s.id_slot 
WHERE t.id_parkir = 1 AND t.id_slot IS NULL;

UPDATE tb_slot_parkir SET status_slot = 'terisi' WHERE kode_slot = 'A-01';

UPDATE tb_transaksi t 
JOIN tb_slot_parkir s ON s.kode_slot = 'B-01'
SET t.id_slot = s.id_slot 
WHERE t.id_parkir = 3 AND t.id_slot IS NULL;

UPDATE tb_slot_parkir SET status_slot = 'terisi' WHERE kode_slot = 'B-01';

-- 6. Perbarui View Rekap Transaksi agar menyertakan kode_slot
CREATE OR REPLACE VIEW v_rekap_transaksi AS
SELECT t.id_parkir, k.plat_nomor, k.jenis_kendaraan, a.nama_area, s.kode_slot,
       t.waktu_masuk, t.waktu_keluar, t.durasi_jam, t.biaya_total, t.status
FROM tb_transaksi t
JOIN tb_kendaraan k ON k.id_kendaraan=t.id_kendaraan
JOIN tb_area_parkir a ON a.id_area=t.id_area
LEFT JOIN tb_slot_parkir s ON s.id_slot=t.id_slot;

