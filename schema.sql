-- Demirtaş Yapı Market - Aktarım için gerekli çekirdek şema (MySQL 8 / MariaDB 10.5+)
-- Para alanları DECIMAL: asla FLOAT kullanılmaz (yuvarlama hatası olmaması için).
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS depolar (
  id TINYINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  kod VARCHAR(10) NOT NULL UNIQUE,
  ad VARCHAR(60) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO depolar (id,kod,ad) VALUES (1,'MERKEZ','Merkez Depo'),(2,'OSB','OSB Depo');

CREATE TABLE IF NOT EXISTS satiscilar (
  id TINYINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  ad VARCHAR(60) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO satiscilar (ad) VALUES ('Emre Doğan'),('Mehmet Kaya'),('Serkan Öztürk'),('Ahmet Yılmaz');

CREATE TABLE IF NOT EXISTS cariler (
  id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  kod VARCHAR(20) NOT NULL UNIQUE,
  import_key VARCHAR(190) NULL UNIQUE,          -- yeniden çalıştırmada çoğalmayı önler
  unvan VARCHAR(200) NOT NULL,
  tip ENUM('musteri','tedarikci') NOT NULL,
  vkn VARCHAR(11) NULL,
  vkn_gecerli TINYINT(1) NOT NULL DEFAULT 0,
  vergi_dairesi VARCHAR(80) NULL,
  telefon VARCHAR(20) NULL,
  eposta VARCHAR(150) NULL,
  il VARCHAR(40) NULL,
  ilce VARCHAR(60) NULL,
  risk_limiti DECIMAL(15,2) NULL,               -- NULL = tanımsız (patron onayı gerekir)
  risk_limitsiz TINYINT(1) NOT NULL DEFAULT 0,  -- 1 = bilinçli olarak limitsiz
  para_birimi CHAR(3) NOT NULL DEFAULT 'TRY',
  satisci_id TINYINT UNSIGNED NULL,
  satisci_ham VARCHAR(60) NULL,
  durum ENUM('aktif','engelli') NOT NULL DEFAULT 'aktif',
  uyari_notu VARCHAR(255) NULL,                 -- sipariş ekranında gösterilecek uyarı
  ham_not VARCHAR(255) NULL,
  son_islem_tarihi DATE NULL,
  olusturma DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (vkn), KEY (tip),
  FOREIGN KEY (satisci_id) REFERENCES satiscilar(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Bakiye = SUM(borc) - SUM(alacak); pozitif => müşteri bize borçlu. Tutarlar carinin kendi para biriminde.
CREATE TABLE IF NOT EXISTS cari_hareketler (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  cari_id INT UNSIGNED NOT NULL,
  tarih DATE NOT NULL,
  tur ENUM('acilis','fatura','tahsilat','odeme','duzeltme') NOT NULL,
  borc DECIMAL(15,2) NOT NULL DEFAULT 0,
  alacak DECIMAL(15,2) NOT NULL DEFAULT 0,
  aciklama VARCHAR(255) NULL,
  kaynak_key VARCHAR(190) NULL UNIQUE,
  FOREIGN KEY (cari_id) REFERENCES cariler(id),
  CHECK (borc >= 0 AND alacak >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS urunler (
  id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  stok_kodu VARCHAR(20) NOT NULL UNIQUE,
  import_key VARCHAR(190) NULL UNIQUE,
  ad VARCHAR(200) NOT NULL,
  kategori VARCHAR(40) NOT NULL,
  temel_birim VARCHAR(10) NOT NULL,             -- stok bu birimde tutulur
  alis_fiyati DECIMAL(15,4) NULL,               -- KDV hariç
  satis_fiyati DECIMAL(15,4) NULL,              -- KDV hariç
  kdv_orani TINYINT UNSIGNED NOT NULL,
  kritik_stok DECIMAL(15,3) NULL,
  barkod VARCHAR(14) NULL UNIQUE,
  tedarikci_cari_id INT UNSIGNED NULL,
  tedarikci_ham VARCHAR(150) NULL,
  aktif TINYINT(1) NOT NULL DEFAULT 1,
  olusturma DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tedarikci_cari_id) REFERENCES cariler(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 1 alt_birim = pay/payda temel_birim  (kesirli katsayı yok, yuvarlama hatası yok)
CREATE TABLE IF NOT EXISTS urun_birim_cevrim (
  urun_id INT UNSIGNED NOT NULL,
  alt_birim VARCHAR(10) NOT NULL,
  pay INT UNSIGNED NOT NULL,
  payda INT UNSIGNED NOT NULL,
  PRIMARY KEY (urun_id, alt_birim),
  FOREIGN KEY (urun_id) REFERENCES urunler(id),
  CHECK (pay > 0 AND payda > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Stok asla doğrudan güncellenmez; her değişiklik hareket olarak yazılır (+ giriş, - çıkış).
CREATE TABLE IF NOT EXISTS stok_hareketleri (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  urun_id INT UNSIGNED NOT NULL,
  depo_id TINYINT UNSIGNED NOT NULL,
  tarih DATE NOT NULL,
  tur ENUM('acilis','alis','satis','transfer_giris','transfer_cikis','sayim','duzeltme') NOT NULL,
  miktar DECIMAL(15,3) NOT NULL,
  aciklama VARCHAR(255) NULL,
  kaynak_key VARCHAR(190) NULL UNIQUE,
  KEY (urun_id, depo_id, tarih),
  FOREIGN KEY (urun_id) REFERENCES urunler(id),
  FOREIGN KEY (depo_id) REFERENCES depolar(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS aktarim_calismalari (
  id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  baslangic DATETIME NOT NULL,
  bitis DATETIME NULL,
  dosya VARCHAR(255) NOT NULL,
  ozet TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS aktarim_rapor (
  id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  calisma_id INT UNSIGNED NOT NULL,
  sayfa VARCHAR(20) NOT NULL,
  satir INT NOT NULL,
  anahtar VARCHAR(80) NULL,
  durum VARCHAR(20) NOT NULL,
  oncelik VARCHAR(10) NOT NULL,
  alan VARCHAR(40) NULL,
  eski_deger VARCHAR(255) NULL,
  yeni_deger VARCHAR(255) NULL,
  gerekce VARCHAR(500) NOT NULL,
  FOREIGN KEY (calisma_id) REFERENCES aktarim_calismalari(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================================
--  Modül 01: kullanıcılar, giriş denemeleri, işlem kaydı
-- =====================================================================
CREATE TABLE IF NOT EXISTS kullanicilar (
  id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  kullanici_adi VARCHAR(40) NOT NULL UNIQUE,    -- yalnızca küçük harf, rakam, . _ -
  ad_soyad VARCHAR(80) NOT NULL,
  rol ENUM('patron','muhasebe','depo','satisci') NOT NULL,
  satisci_id TINYINT UNSIGNED NULL,             -- rol = satisci ise hangi satışçı kartına bağlı
  parola_hash VARCHAR(255) NOT NULL,            -- password_hash() çıktısı, düz parola asla saklanmaz
  aktif TINYINT(1) NOT NULL DEFAULT 1,
  son_giris DATETIME NULL,
  olusturma DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (satisci_id) REFERENCES satiscilar(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Kaba kuvvet (brute force) engeli için: kullanıcı adı + IP başına başarısız deneme sayılır.
CREATE TABLE IF NOT EXISTS giris_denemeleri (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  kullanici_adi VARCHAR(40) NOT NULL,
  ip VARCHAR(45) NOT NULL,
  zaman DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  basarili TINYINT(1) NOT NULL,
  KEY (kullanici_adi, zaman),
  KEY (ip, zaman)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Kim, neyi, ne zaman ekledi/değiştirdi/sildi. Yalnızca eklenir; tetikleyiciler UPDATE ve DELETE'i engeller.
CREATE TABLE IF NOT EXISTS islem_kaydi (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  zaman DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  kullanici_id INT UNSIGNED NULL,
  kullanici_adi VARCHAR(40) NULL,
  rol VARCHAR(20) NULL,
  ip VARCHAR(45) NULL,
  eylem VARCHAR(30) NOT NULL,                   -- giris, giris_basarisiz, cikis, ekle, degistir, sil, yetkisiz
  tablo VARCHAR(40) NULL,
  kayit_id VARCHAR(40) NULL,
  eski_deger JSON NULL,
  yeni_deger JSON NULL,
  aciklama VARCHAR(255) NULL,
  onceki_hash CHAR(64) NULL,                    -- hash zinciri: bir önceki kaydın hash'i ('' = zincirin ilk kaydı)
  hash CHAR(64) NULL,                           -- SHA-256(önceki hash + bu kaydın alanları); bkz. src/Core/Audit.php
  KEY (zaman), KEY (kullanici_id), KEY (eylem), KEY (tablo, kayit_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TRIGGER IF EXISTS islem_kaydi_degistirilemez;
CREATE TRIGGER islem_kaydi_degistirilemez BEFORE UPDATE ON islem_kaydi FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'islem_kaydi degistirilemez';
DROP TRIGGER IF EXISTS islem_kaydi_silinemez;
CREATE TRIGGER islem_kaydi_silinemez BEFORE DELETE ON islem_kaydi FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'islem_kaydi silinemez';

-- =====================================================================
--  Modül 04: sipariş → irsaliye → fatura → tahsilat
--  Tutarlar DECIMAL(15,2) (kuruş hassasiyeti), fiyat DECIMAL(15,4), miktar DECIMAL(15,3). Float yok.
-- =====================================================================
CREATE TABLE IF NOT EXISTS belge_sayaclari (      -- boşluksuz, yıla göre sıralı belge numarası
  tur VARCHAR(10) NOT NULL, yil SMALLINT NOT NULL, son_no INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (tur, yil)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS siparisler (
  id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  belge_no VARCHAR(20) NOT NULL UNIQUE,
  cari_id INT UNSIGNED NOT NULL,
  depo_id TINYINT UNSIGNED NOT NULL,
  tarih DATE NOT NULL,
  durum ENUM('onay_bekliyor','onayli','kismi_sevk','sevk_edildi','faturalandi','iptal') NOT NULL,
  para_birimi CHAR(3) NOT NULL DEFAULT 'TRY',
  genel_iskonto_bp SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  matrah DECIMAL(15,2) NOT NULL, kdv DECIMAL(15,2) NOT NULL, toplam DECIMAL(15,2) NOT NULL,
  faturalanan_toplam DECIMAL(15,2) NOT NULL DEFAULT 0,
  risk_notu VARCHAR(255) NULL,
  olusturan_id INT UNSIGNED NULL, onaylayan_id INT UNSIGNED NULL,
  kaynak_key VARCHAR(64) NULL UNIQUE,
  olusturma DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (cari_id), KEY (durum),
  FOREIGN KEY (cari_id) REFERENCES cariler(id), FOREIGN KEY (depo_id) REFERENCES depolar(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS siparis_kalemleri (
  id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  siparis_id INT UNSIGNED NOT NULL,
  sira SMALLINT UNSIGNED NOT NULL,
  urun_id INT UNSIGNED NOT NULL,
  miktar DECIMAL(15,3) NOT NULL,                  -- ürünün temel biriminde
  sevk_edilen DECIMAL(15,3) NOT NULL DEFAULT 0,
  fiyat DECIMAL(15,4) NOT NULL,                   -- KDV hariç, temel birim başına
  iskonto_bp SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  kdv_orani TINYINT UNSIGNED NOT NULL,
  UNIQUE KEY (siparis_id, sira),
  FOREIGN KEY (siparis_id) REFERENCES siparisler(id), FOREIGN KEY (urun_id) REFERENCES urunler(id),
  CHECK (miktar > 0 AND sevk_edilen >= 0 AND sevk_edilen <= miktar)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS faturalar (
  id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  belge_no VARCHAR(20) NOT NULL UNIQUE,
  ettn CHAR(36) NOT NULL UNIQUE,                  -- e-Fatura benzersiz kimliği (UUID)
  cari_id INT UNSIGNED NOT NULL,
  siparis_id INT UNSIGNED NOT NULL,
  tarih DATE NOT NULL, vade_tarihi DATE NOT NULL,
  para_birimi CHAR(3) NOT NULL DEFAULT 'TRY',
  genel_iskonto_bp SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  ara_toplam DECIMAL(15,2) NOT NULL, genel_iskonto DECIMAL(15,2) NOT NULL,
  matrah DECIMAL(15,2) NOT NULL, kdv DECIMAL(15,2) NOT NULL, toplam DECIMAL(15,2) NOT NULL,
  kaynak_key VARCHAR(64) NULL UNIQUE,
  olusturan_id INT UNSIGNED NULL,
  olusturma DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (cari_id), KEY (vade_tarihi),
  FOREIGN KEY (cari_id) REFERENCES cariler(id), FOREIGN KEY (siparis_id) REFERENCES siparisler(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS irsaliyeler (
  id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  belge_no VARCHAR(20) NOT NULL UNIQUE,
  siparis_id INT UNSIGNED NOT NULL, cari_id INT UNSIGNED NOT NULL, depo_id TINYINT UNSIGNED NOT NULL,
  tarih DATE NOT NULL,
  fatura_id INT UNSIGNED NULL,                    -- faturalandıysa hangi faturada
  kaynak_key VARCHAR(64) NULL UNIQUE,
  olusturan_id INT UNSIGNED NULL,
  olusturma DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (siparis_id) REFERENCES siparisler(id), FOREIGN KEY (fatura_id) REFERENCES faturalar(id),
  FOREIGN KEY (cari_id) REFERENCES cariler(id), FOREIGN KEY (depo_id) REFERENCES depolar(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS irsaliye_kalemleri (
  id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  irsaliye_id INT UNSIGNED NOT NULL, siparis_kalem_id INT UNSIGNED NOT NULL, urun_id INT UNSIGNED NOT NULL,
  miktar DECIMAL(15,3) NOT NULL,
  FOREIGN KEY (irsaliye_id) REFERENCES irsaliyeler(id), FOREIGN KEY (siparis_kalem_id) REFERENCES siparis_kalemleri(id),
  CHECK (miktar > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS fatura_kalemleri (
  id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  fatura_id INT UNSIGNED NOT NULL, sira SMALLINT UNSIGNED NOT NULL, urun_id INT UNSIGNED NOT NULL,
  miktar DECIMAL(15,3) NOT NULL, fiyat DECIMAL(15,4) NOT NULL, iskonto_bp SMALLINT UNSIGNED NOT NULL,
  kdv_orani TINYINT UNSIGNED NOT NULL,
  brut DECIMAL(15,2) NOT NULL, iskonto DECIMAL(15,2) NOT NULL, genel_iskonto DECIMAL(15,2) NOT NULL,
  matrah DECIMAL(15,2) NOT NULL,
  FOREIGN KEY (fatura_id) REFERENCES faturalar(id), FOREIGN KEY (urun_id) REFERENCES urunler(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tahsilatlar (
  id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  belge_no VARCHAR(20) NOT NULL UNIQUE,
  cari_id INT UNSIGNED NOT NULL, tarih DATE NOT NULL,
  tutar DECIMAL(15,2) NOT NULL,
  yontem ENUM('nakit','havale','kredi_karti','cek','senet') NOT NULL,
  vade_tarihi DATE NULL,                          -- çek/senet için zorunlu
  evrak_no VARCHAR(40) NULL,
  durum ENUM('alindi','portfoyde','tahsil_edildi','karsiliksiz') NOT NULL,
  aciklama VARCHAR(150) NULL,
  kaynak_key VARCHAR(64) NULL UNIQUE,
  olusturan_id INT UNSIGNED NULL,
  olusturma DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (cari_id), KEY (vade_tarihi),
  FOREIGN KEY (cari_id) REFERENCES cariler(id),
  CHECK (tutar > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tahsilatın hangi faturaya ne kadar mahsup edildiği. Geri alma (karşılıksız) NEGATİF satır olarak eklenir; silinmez.
CREATE TABLE IF NOT EXISTS tahsilat_dagitim (
  id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  tahsilat_id INT UNSIGNED NOT NULL, fatura_id INT UNSIGNED NOT NULL,
  tutar DECIMAL(15,2) NOT NULL,
  KEY (fatura_id),
  FOREIGN KEY (tahsilat_id) REFERENCES tahsilatlar(id), FOREIGN KEY (fatura_id) REFERENCES faturalar(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================================
--  Modül 02: döviz kurları (TCMB'den çekilir veya elle girilir; kaynak her satırda yazılı)
-- =====================================================================
CREATE TABLE IF NOT EXISTS kurlar (
  tarih DATE NOT NULL,
  kod CHAR(3) NOT NULL,
  satis DECIMAL(12,4) NOT NULL,                   -- 1 birim döviz = satis TL (döviz satış kuru)
  kaynak VARCHAR(20) NOT NULL,                    -- 'tcmb' veya 'elle'
  olusturma DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (tarih, kod),
  CHECK (satis > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================================
--  Modül 08: mobil API jetonları (yalnızca SHA-256 özeti saklanır; jetonun kendisi veritabanında yoktur)
-- =====================================================================
CREATE TABLE IF NOT EXISTS api_tokenlari (
  id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  kullanici_id INT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  cihaz VARCHAR(60) NULL,
  olusturma DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  son_kullanim DATETIME NULL,
  bitis DATETIME NOT NULL,
  iptal TINYINT(1) NOT NULL DEFAULT 0,
  KEY (kullanici_id),
  FOREIGN KEY (kullanici_id) REFERENCES kullanicilar(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
