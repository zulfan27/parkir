CREATE DATABASE IF NOT EXISTS parkir CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE parkir;

SET FOREIGN_KEY_CHECKS = 0;
DROP VIEW IF EXISTS v_rekap_transaksi;
DROP PROCEDURE IF EXISTS sp_parkir_keluar;
DROP TABLE IF EXISTS tb_log_aktivitas;
DROP TABLE IF EXISTS tb_transaksi;
DROP TABLE IF EXISTS tb_kendaraan;
DROP TABLE IF EXISTS tb_slot_parkir;
DROP TABLE IF EXISTS tb_area_parkir;
DROP TABLE IF EXISTS tb_tarif;
DROP TABLE IF EXISTS tb_user;
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE tb_user (
  id_user INT(11) NOT NULL AUTO_INCREMENT,
  nama_lengkap VARCHAR(50) NOT NULL,
  username VARCHAR(50) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  role ENUM('admin','petugas','owner') NOT NULL,
  status_aktif TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id_user)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE tb_tarif (
  id_tarif INT(11) NOT NULL AUTO_INCREMENT,
  jenis_kendaraan ENUM('motor','mobil','lainnya') NOT NULL,
  tarif_per_jam DECIMAL(10,0) NOT NULL,
  PRIMARY KEY (id_tarif),
  UNIQUE KEY uq_tarif_jenis (jenis_kendaraan),
  CHECK (tarif_per_jam >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE tb_area_parkir (
  id_area INT(11) NOT NULL AUTO_INCREMENT,
  nama_area VARCHAR(50) NOT NULL,
  kapasitas INT(5) NOT NULL,
  terisi INT(5) NOT NULL DEFAULT 0,
  PRIMARY KEY (id_area),
  CHECK (kapasitas >= 0),
  CHECK (terisi >= 0 AND terisi <= kapasitas)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE tb_slot_parkir (
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

CREATE TABLE tb_kendaraan (
  id_kendaraan INT(11) NOT NULL AUTO_INCREMENT,
  plat_nomor VARCHAR(15) NOT NULL,
  jenis_kendaraan VARCHAR(20) NOT NULL,
  warna VARCHAR(20) NULL,
  pemilik VARCHAR(100) NULL,
  id_user INT(11) NOT NULL,
  PRIMARY KEY (id_kendaraan),
  UNIQUE KEY uq_kendaraan_plat (plat_nomor),
  CONSTRAINT fk_kendaraan_user FOREIGN KEY (id_user) REFERENCES tb_user(id_user)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE tb_transaksi (
  id_parkir INT(11) NOT NULL AUTO_INCREMENT,
  id_kendaraan INT(11) NOT NULL,
  waktu_masuk DATETIME NOT NULL,
  waktu_keluar DATETIME NULL,
  id_tarif INT(11) NOT NULL,
  durasi_jam INT(5) NULL,
  biaya_total DECIMAL(10,0) NULL,
  status ENUM('masuk','keluar') NOT NULL DEFAULT 'masuk',
  id_user INT(11) NOT NULL,
  id_area INT(11) NOT NULL,
  id_slot INT(11) NULL,
  PRIMARY KEY (id_parkir),
  KEY idx_trx_status_waktu (status, waktu_masuk),
  KEY idx_trx_waktu_keluar (waktu_keluar),
  CONSTRAINT fk_trx_kendaraan FOREIGN KEY (id_kendaraan) REFERENCES tb_kendaraan(id_kendaraan),
  CONSTRAINT fk_trx_tarif FOREIGN KEY (id_tarif) REFERENCES tb_tarif(id_tarif),
  CONSTRAINT fk_trx_user FOREIGN KEY (id_user) REFERENCES tb_user(id_user),
  CONSTRAINT fk_trx_area FOREIGN KEY (id_area) REFERENCES tb_area_parkir(id_area),
  CONSTRAINT fk_trx_slot FOREIGN KEY (id_slot) REFERENCES tb_slot_parkir(id_slot) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE tb_log_aktivitas (
  id_log INT(11) NOT NULL AUTO_INCREMENT,
  id_user INT(11) NOT NULL,
  aktivitas VARCHAR(150) NOT NULL,
  waktu_aktivitas DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_log),
  KEY idx_log_waktu (waktu_aktivitas),
  CONSTRAINT fk_log_user FOREIGN KEY (id_user) REFERENCES tb_user(id_user)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Akun Demo
INSERT INTO tb_user (nama_lengkap, username, password, role) VALUES
('Budi Santoso','admin1',SHA2('admin123',256),'admin'),
('Siti Aminah','petugas1',SHA2('petugas123',256),'petugas'),
('Pak Hendra','owner1',SHA2('owner123',256),'owner');

-- Tarif
INSERT INTO tb_tarif (jenis_kendaraan, tarif_per_jam) VALUES
('motor',2000),('mobil',5000),('lainnya',3000);

-- Area Parkir
INSERT INTO tb_area_parkir (id_area, nama_area, kapasitas, terisi) VALUES
(1, 'Area Motor', 40, 28),
(2, 'Area Mobil', 20, 14);

-- Slot Motor M01 - M40
INSERT INTO tb_slot_parkir (id_area, kode_slot, jenis_slot, lantai, status_slot, urutan) VALUES
(1, 'M01', 'motor', 'Lantai 1', 'terisi', 1),
(1, 'M02', 'motor', 'Lantai 1', 'tersedia', 2),
(1, 'M03', 'motor', 'Lantai 1', 'terisi', 3),
(1, 'M04', 'motor', 'Lantai 1', 'terisi', 4),
(1, 'M05', 'motor', 'Lantai 1', 'tersedia', 5),
(1, 'M06', 'motor', 'Lantai 1', 'tersedia', 6),
(1, 'M07', 'motor', 'Lantai 1', 'tersedia', 7),
(1, 'M08', 'motor', 'Lantai 1', 'tersedia', 8),
(1, 'M09', 'motor', 'Lantai 1', 'terisi', 9),
(1, 'M10', 'motor', 'Lantai 1', 'terisi', 10),
(1, 'M11', 'motor', 'Lantai 1', 'tersedia', 11),
(1, 'M12', 'motor', 'Lantai 1', 'terisi', 12),
(1, 'M13', 'motor', 'Lantai 1', 'terisi', 13),
(1, 'M14', 'motor', 'Lantai 1', 'terisi', 14),
(1, 'M15', 'motor', 'Lantai 1', 'terisi', 15),
(1, 'M16', 'motor', 'Lantai 1', 'tersedia', 16),
(1, 'M17', 'motor', 'Lantai 1', 'terisi', 17),
(1, 'M18', 'motor', 'Lantai 1', 'tersedia', 18),
(1, 'M19', 'motor', 'Lantai 1', 'terisi', 19),
(1, 'M20', 'motor', 'Lantai 1', 'terisi', 20),
(1, 'M21', 'motor', 'Lantai 1', 'terisi', 21),
(1, 'M22', 'motor', 'Lantai 1', 'terisi', 22),
(1, 'M23', 'motor', 'Lantai 1', 'terisi', 23),
(1, 'M24', 'motor', 'Lantai 1', 'terisi', 24),
(1, 'M25', 'motor', 'Lantai 1', 'tersedia', 25),
(1, 'M26', 'motor', 'Lantai 1', 'terisi', 26),
(1, 'M27', 'motor', 'Lantai 1', 'terisi', 27),
(1, 'M28', 'motor', 'Lantai 1', 'terisi', 28),
(1, 'M29', 'motor', 'Lantai 1', 'terisi', 29),
(1, 'M30', 'motor', 'Lantai 1', 'tersedia', 30),
(1, 'M31', 'motor', 'Lantai 1', 'terisi', 31),
(1, 'M32', 'motor', 'Lantai 1', 'terisi', 32),
(1, 'M33', 'motor', 'Lantai 1', 'terisi', 33),
(1, 'M34', 'motor', 'Lantai 1', 'terisi', 34),
(1, 'M35', 'motor', 'Lantai 1', 'terisi', 35),
(1, 'M36', 'motor', 'Lantai 1', 'terisi', 36),
(1, 'M37', 'motor', 'Lantai 1', 'terisi', 37),
(1, 'M38', 'motor', 'Lantai 1', 'terisi', 38),
(1, 'M39', 'motor', 'Lantai 1', 'tersedia', 39),
(1, 'M40', 'motor', 'Lantai 1', 'tersedia', 40);

-- Slot Mobil A01 - A20
INSERT INTO tb_slot_parkir (id_area, kode_slot, jenis_slot, lantai, status_slot, urutan) VALUES
(2, 'A01', 'mobil', 'Lantai 1', 'tersedia', 1),
(2, 'A02', 'mobil', 'Lantai 1', 'terisi', 2),
(2, 'A03', 'mobil', 'Lantai 1', 'tersedia', 3),
(2, 'A04', 'mobil', 'Lantai 1', 'terisi', 4),
(2, 'A05', 'mobil', 'Lantai 1', 'terisi', 5),
(2, 'A06', 'mobil', 'Lantai 1', 'terisi', 6),
(2, 'A07', 'mobil', 'Lantai 1', 'terisi', 7),
(2, 'A08', 'mobil', 'Lantai 1', 'terisi', 8),
(2, 'A09', 'mobil', 'Lantai 1', 'tersedia', 9),
(2, 'A10', 'mobil', 'Lantai 1', 'terisi', 10),
(2, 'A11', 'mobil', 'Lantai 1', 'tersedia', 11),
(2, 'A12', 'mobil', 'Lantai 1', 'terisi', 12),
(2, 'A13', 'mobil', 'Lantai 1', 'terisi', 13),
(2, 'A14', 'mobil', 'Lantai 1', 'terisi', 14),
(2, 'A15', 'mobil', 'Lantai 1', 'terisi', 15),
(2, 'A16', 'mobil', 'Lantai 1', 'terisi', 16),
(2, 'A17', 'mobil', 'Lantai 1', 'terisi', 17),
(2, 'A18', 'mobil', 'Lantai 1', 'terisi', 18),
(2, 'A19', 'mobil', 'Lantai 1', 'tersedia', 19),
(2, 'A20', 'mobil', 'Lantai 1', 'tersedia', 20);

CREATE OR REPLACE VIEW v_rekap_transaksi AS
SELECT t.id_parkir, k.plat_nomor, k.jenis_kendaraan, a.nama_area, s.kode_slot,
       t.waktu_masuk, t.waktu_keluar, t.durasi_jam, t.biaya_total, t.status
FROM tb_transaksi t
JOIN tb_kendaraan k ON k.id_kendaraan=t.id_kendaraan
JOIN tb_area_parkir a ON a.id_area=t.id_area
LEFT JOIN tb_slot_parkir s ON s.id_slot=t.id_slot;
