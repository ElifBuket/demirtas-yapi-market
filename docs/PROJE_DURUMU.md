# Proje Durumu — Demirtaş Yapı Market Aday Projesi (20.11.2026 itibarıyla)

## Bitenler (bu ortamda yazıldı + test edildi)
- Modül 01–07, 09: PHP 8.3 + MySQL; birim testleri geçti (güvenlik 97, birim 93, hesap 36, stok 53, satış 72, rapor 25, e-Fatura 41, cari 53, asistan 71, satış HTTP 84).
- Modül 08: mobil API (`/api/v1`, test 31) + Expo uygulaması (`mobil/`, kuyruk testi 12). Uygulama ekranları cihazda DENENMEDİ.
- Modül 10: DEPLOY.md, .env desteği, .htaccess, Git geçmişi (yer tutucu yazar/tarih).
- Bölüm B: `harc-pazarlama.zip` (ürün adı Harç; rakip analizi, Google Ads, Meta/LinkedIn, hedef/CAC, site, takvim, tasarımlar, video, broşür, e-posta).
- Asistan düzeltildi: "en çok borcu olan 5 müşteri" ve "geçen haftaya göre çimento satışı" soruları çalışıyor.
- Hazırlık: 36. saat e-posta taslağı, `Sunum_ve_Demo_Hazirlik.pdf`.

## Kullanıcıda kalanlar
1. Zip'leri indir, KURULUM.md ile XAMPP'ta çalıştır, `bash tests/hepsi.sh`.
2. `cd mobil && npm install && npx expo start`; APK: `eas build -p android --profile apk`.
3. HTTPS canlı yayın (DEPLOY.md) + tanıtım sitesi yayını; GA4/Meta kimlikleri `site/assets/izleme.js`.
4. GitHub'a kendi adıyla yükle; her rol için `php scripts/seed_users.php`.
5. `docs/YAPAY_ZEKA_BEYANI.md` "Adayın katkısı" bölümünü kendi sözleriyle doldur.
6. 5 dk ekran kaydı; pazarlama klasörünü Drive'a yükle.
7. **36. saat durum e-postası ZORUNLU** (rapor göndermeyen aday değerlendirilmez).

## Denenmeyenler
HTTPS barındırma, TCMB canlı kur, gerçek Anthropic çağrısı, GİB şema doğrulaması, mobil uygulamanın cihazda çalışması.

## Bilinen eksikler
17 mükerrer cari bakiyesi alınmadı (251.644,60 ₺); tedarikçi eşleşmesi 233/498; fatura iptali/iade yok; nakit akışında ödemeler yok; marka/alan adı "Harç" araması yapılmadı; pazarlama rakamları varsayım.
