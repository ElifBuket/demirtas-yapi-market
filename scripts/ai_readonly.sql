-- Yapay zekâ asistanı için YALNIZCA OKUMA yetkili veritabanı kullanıcısı.
-- Kullanım (root ile):  mysql -u root < scripts/ai_readonly.sql   (parolayı değiştirin!)
-- Sonra ortam değişkenleri: AI_DB_USER=demirtas_ai  AI_DB_PASS=<parola>
-- Parola özetleri (kullanicilar), giriş denemeleri ve işlem kaydı bilerek verilmedi: asistan onları okuyamaz.
CREATE USER IF NOT EXISTS 'demirtas_ai'@'localhost' IDENTIFIED BY 'DEGISTIR_BENI';
GRANT SELECT ON demirtas.cariler TO 'demirtas_ai'@'localhost';
GRANT SELECT ON demirtas.cari_hareketler TO 'demirtas_ai'@'localhost';
GRANT SELECT ON demirtas.satiscilar TO 'demirtas_ai'@'localhost';
GRANT SELECT ON demirtas.depolar TO 'demirtas_ai'@'localhost';
GRANT SELECT ON demirtas.urunler TO 'demirtas_ai'@'localhost';
GRANT SELECT ON demirtas.urun_birim_cevrim TO 'demirtas_ai'@'localhost';
GRANT SELECT ON demirtas.stok_hareketleri TO 'demirtas_ai'@'localhost';
GRANT SELECT ON demirtas.siparisler TO 'demirtas_ai'@'localhost';
GRANT SELECT ON demirtas.siparis_kalemleri TO 'demirtas_ai'@'localhost';
GRANT SELECT ON demirtas.irsaliyeler TO 'demirtas_ai'@'localhost';
GRANT SELECT ON demirtas.irsaliye_kalemleri TO 'demirtas_ai'@'localhost';
GRANT SELECT ON demirtas.faturalar TO 'demirtas_ai'@'localhost';
GRANT SELECT ON demirtas.fatura_kalemleri TO 'demirtas_ai'@'localhost';
GRANT SELECT ON demirtas.tahsilatlar TO 'demirtas_ai'@'localhost';
GRANT SELECT ON demirtas.tahsilat_dagitim TO 'demirtas_ai'@'localhost';
GRANT SELECT ON demirtas.kurlar TO 'demirtas_ai'@'localhost';
FLUSH PRIVILEGES;
