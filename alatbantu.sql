/*
 Navicat Premium Data Transfer

 Source Server         : lokal
 Source Server Type    : MySQL
 Source Server Version : 100420
 Source Host           : localhost:3306
 Source Schema         : sikbaru

 Target Server Type    : MySQL
 Target Server Version : 100420
 File Encoding         : 65001

 Date: 18/07/2026 05:48:01
*/

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------
-- Table structure for hak_akses
-- ----------------------------
CREATE TABLE `hak_akses`  (
  `nik` varchar(20) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `dashboard` enum('1','0') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '1',
  `manajemen` enum('1','0') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '0',
  `dokter` enum('1','0') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '0',
  `pegawai` enum('1','0') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '0',
  `kasir` enum('0','1') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '0',
  `keuangan` enum('0','1') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '0',
  `surat_masuk` enum('0','1') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '0',
  `surat_keluar` enum('0','1') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '0',
  PRIMARY KEY (`nik`) USING BTREE,
  CONSTRAINT `hak_akses_ibfk_1` FOREIGN KEY (`nik`) REFERENCES `pegawai` (`nik`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = Dynamic;

SET FOREIGN_KEY_CHECKS = 1;

-- ----------------------------
-- Table structure for atasan_pegawai
-- ----------------------------
CREATE TABLE `atasan_pegawai`  (
  `nik` varchar(20) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `nik_atasan` varchar(20) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  PRIMARY KEY (`nik`) USING BTREE,
  INDEX `nik_atasan`(`nik_atasan`) USING BTREE,
  CONSTRAINT `atasan_pegawai_ibfk_1` FOREIGN KEY (`nik`) REFERENCES `pegawai` (`nik`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `atasan_pegawai_ibfk_2` FOREIGN KEY (`nik_atasan`) REFERENCES `pegawai` (`nik`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE = InnoDB CHARACTER SET = latin1 COLLATE = latin1_swedish_ci ROW_FORMAT = Dynamic;

SET FOREIGN_KEY_CHECKS = 1;

-- ----------------------------
-- Table structure for persetujuan_cuti
-- ----------------------------
CREATE TABLE `persetujuan_cuti`  (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `no_pengajuan` varchar(17) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `level` int(11) NOT NULL,
  `nik_approver` varchar(20) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `status` enum('Pending','Disetujui','Ditolak') CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT 'Pending',
  `tanggal_keputusan` datetime(0) NULL DEFAULT NULL,
  `catatan` varchar(150) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `no_pengajuan`(`no_pengajuan`) USING BTREE,
  INDEX `nik_approver`(`nik_approver`) USING BTREE,
  CONSTRAINT `persetujuan_cuti_ibfk_1` FOREIGN KEY (`no_pengajuan`) REFERENCES `pengajuan_cuti` (`no_pengajuan`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `persetujuan_cuti_ibfk_2` FOREIGN KEY (`nik_approver`) REFERENCES `pegawai` (`nik`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE = InnoDB AUTO_INCREMENT = 1 CHARACTER SET = latin1 COLLATE = latin1_swedish_ci ROW_FORMAT = Dynamic;

SET FOREIGN_KEY_CHECKS = 1;

-- ----------------------------
-- Table structure for kontrak_pegawai
-- ----------------------------
CREATE TABLE `kontrak_pegawai`  (
  `nik` varchar(20) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `tipe` enum('Kontrak Pegawai','SIP Dokter') CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `nomor_dokumen` varchar(100) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `tanggal_mulai` date NULL DEFAULT NULL,
  `tanggal_habis` date NULL DEFAULT NULL,
  `keterangan` varchar(200) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  PRIMARY KEY (`nik`, `tipe`) USING BTREE,
  CONSTRAINT `kontrak_pegawai_ibfk_1` FOREIGN KEY (`nik`) REFERENCES `pegawai` (`nik`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE = InnoDB CHARACTER SET = latin1 COLLATE = latin1_swedish_ci ROW_FORMAT = Dynamic;

SET FOREIGN_KEY_CHECKS = 1;

-- ----------------------------
-- Table structure for face_vector
-- ----------------------------
CREATE TABLE `face_vector`  (
  `nik` varchar(20) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `vector` longtext CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `created_at` timestamp(0) NOT NULL DEFAULT current_timestamp(0),
  PRIMARY KEY (`nik`) USING BTREE,
  CONSTRAINT `fk_face_vector_pegawai` FOREIGN KEY (`nik`) REFERENCES `pegawai` (`nik`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE = InnoDB CHARACTER SET = latin1 COLLATE = latin1_swedish_ci ROW_FORMAT = Dynamic;

SET FOREIGN_KEY_CHECKS = 1;

-- ----------------------------
-- Table structure for master_komponen_gaji
-- ----------------------------
CREATE TABLE `master_komponen_gaji`  (
  `kode` varchar(10) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `nama_komponen` varchar(100) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `jenis` enum('Penerimaan','Potongan') CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  PRIMARY KEY (`kode`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = latin1 COLLATE = latin1_swedish_ci ROW_FORMAT = Dynamic;
SET FOREIGN_KEY_CHECKS = 1;

-- ----------------------------
-- Table structure for gajidantunjangan
-- ----------------------------
CREATE TABLE `gajidantunjangan`  (
  `nik` varchar(20) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `periode_gaji` varchar(10) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `total_penerimaan` decimal(15, 2) NULL DEFAULT 0,
  `total_potongan` decimal(15, 2) NULL DEFAULT 0,
  `gaji_diterima` decimal(15, 2) NULL DEFAULT 0,
  `tanggal_cetak` date NULL DEFAULT NULL,
  PRIMARY KEY (`nik`, `periode_gaji`) USING BTREE,
  CONSTRAINT `fk_gaji_pegawai` FOREIGN KEY (`nik`) REFERENCES `pegawai` (`nik`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE = InnoDB CHARACTER SET = latin1 COLLATE = latin1_swedish_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for gajidantunjangan_detail
-- ----------------------------
CREATE TABLE `gajidantunjangan_detail`  (
  `nik` varchar(20) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `periode_gaji` varchar(10) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `kode_komponen` varchar(10) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `nominal` decimal(15, 2) NULL DEFAULT 0,
  PRIMARY KEY (`nik`, `periode_gaji`, `kode_komponen`) USING BTREE,
  INDEX `fk_detail_master`(`kode_komponen`) USING BTREE,
  CONSTRAINT `fk_detail_gaji` FOREIGN KEY (`nik`, `periode_gaji`) REFERENCES `gajidantunjangan` (`nik`, `periode_gaji`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_detail_master` FOREIGN KEY (`kode_komponen`) REFERENCES `master_komponen_gaji` (`kode`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_detail_pegawai` FOREIGN KEY (`nik`) REFERENCES `pegawai` (`nik`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE = InnoDB CHARACTER SET = latin1 COLLATE = latin1_swedish_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for mapping_absensi
-- ----------------------------
CREATE TABLE `mapping_absensi`  (
  `nik` varchar(20) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `id` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '0',
  PRIMARY KEY (`nik`) USING BTREE,
  INDEX `id`(`id`) USING BTREE,
  CONSTRAINT `mapping_absensi_ibfk_1` FOREIGN KEY (`nik`) REFERENCES `pegawai` (`nik`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = Dynamic;

SET FOREIGN_KEY_CHECKS = 1;


-- ----------------------------
-- Table structure for detail_absensi
-- ----------------------------
CREATE TABLE `detail_absensi`  (
  `id` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '0',
  `tanggal` datetime(0) NOT NULL,
  INDEX `detail_absensi_ibfk_1`(`id`) USING BTREE,
  CONSTRAINT `detail_absensi_ibfk_1` FOREIGN KEY (`id`) REFERENCES `mapping_absensi` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = Dynamic;


-- ----------------------------
-- Table structure for surat_masuk_disposisi_level
-- ----------------------------
CREATE TABLE `surat_masuk_disposisi_level`  (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `no_urut` varchar(15) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `level` tinyint(4) NOT NULL,
  `nik` varchar(20) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `jabatan_label` varchar(100) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `status_disposisi` enum('Menunggu','Sudah Disposisi') CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT 'Menunggu',
  `tgl_disposisi` datetime(0) NULL DEFAULT NULL,
  `isi_disposisi` text CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL,
  `harap` varchar(300) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `catatan` varchar(300) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `pengesahan` enum('true','false') CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT 'false',
  `user_input` varchar(20) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `no_urut`(`no_urut`) USING BTREE,
  INDEX `nik`(`nik`) USING BTREE,
  INDEX `level`(`level`) USING BTREE,
  INDEX `fk_sm_disposisi_user_input`(`user_input`) USING BTREE,
  CONSTRAINT `fk_disposisi_level_pegawai` FOREIGN KEY (`nik`) REFERENCES `pegawai` (`nik`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_disposisi_level_surat` FOREIGN KEY (`no_urut`) REFERENCES `surat_masuk` (`no_urut`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_sm_disposisi_user_input` FOREIGN KEY (`user_input`) REFERENCES `pegawai` (`nik`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE = InnoDB AUTO_INCREMENT = 13 CHARACTER SET = latin1 COLLATE = latin1_swedish_ci ROW_FORMAT = Dynamic;

SET FOREIGN_KEY_CHECKS = 1;

-- ----------------------------
-- Table structure for surat_keluar_disposisi_level
-- ----------------------------

CREATE TABLE `surat_keluar_disposisi_level`  (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `no_urut` varchar(15) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `level` tinyint(4) NOT NULL,
  `nik` varchar(20) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `jabatan_label` varchar(100) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `status_disposisi` enum('Menunggu','Sudah Disposisi') CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT 'Menunggu',
  `tgl_disposisi` datetime(0) NULL DEFAULT NULL,
  `isi_disposisi` text CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL,
  `harap` varchar(300) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `catatan` varchar(300) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `pengesahan` enum('true','false') CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT 'false',
  `user_input` varchar(20) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `no_urut`(`no_urut`) USING BTREE,
  INDEX `nik`(`nik`) USING BTREE,
  INDEX `level`(`level`) USING BTREE,
  INDEX `fk_sk_disposisi_user_input`(`user_input`) USING BTREE,
  CONSTRAINT `fk_sk_disposisi_level_pegawai` FOREIGN KEY (`nik`) REFERENCES `pegawai` (`nik`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_sk_disposisi_level_surat` FOREIGN KEY (`no_urut`) REFERENCES `surat_keluar` (`no_urut`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_sk_disposisi_user_input` FOREIGN KEY (`user_input`) REFERENCES `pegawai` (`nik`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE = InnoDB AUTO_INCREMENT = 10 CHARACTER SET = latin1 COLLATE = latin1_swedish_ci ROW_FORMAT = Dynamic;

SET FOREIGN_KEY_CHECKS = 1;