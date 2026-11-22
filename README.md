# Demirtaş Yapı Market — ön muhasebe, stok ve saha satış sistemi

Adım adım Windows kurulumu: `KURULUM.md`.

PHP 8.2+ (framework yok), MySQL/MariaDB. Web arayüzü sunucuda çizilir; mobil uygulama `mobil/` klasöründedir (React Native / Expo).

## Bu proje nasıl geliştirildi? (yapay zekâ kullanımı)
Bu projeyi Bilgisayar Mühendisliği'nden yeni mezun olarak ben (Elif Buket Duman) hazırladım; geliştirmeyi **Claude ile birlikte**, 72 saat boyunca adım adım ilerleyerek yaptık. İşin araştırmasını, ürün ve tasarım fikirlerini ben belirledim; başka siteleri ve benzer sistemleri kendim inceledim. Kodu birlikte geliştirdik: Claude kodun bölümünü yazdı, ben ne istediğimizi söyledim, çıkanları değerlendirdim, kendi bilgisayarımda, iPhone ve Android telefonlarda ve canlı sunucuda çalıştırıp denedim, bulduğum hataları ve eksikleri geri bildirdim, düzeltmeleri birlikte yaptık. Hosting kurulumu, veritabanı aktarımı, dosya yüklemeleri, GitHub ve testlerin çalıştırılması benim hesaplarımda, benim elimle yapıldı.

Son iş kuralı değişikliğinde (onay bekleyen siparişlerin risk limitine dahil edilmesi) eksiği ben fark ettim, birlikte düzelttik, canlıda denedim.

Tüm ayrıntı: [`docs/YAPAY_ZEKA_BEYANI.md`](docs/YAPAY_ZEKA_BEYANI.md).

## Kurulum (yerel)
1. XAMPP/Laragon ile PHP 8.2+ ve MySQL çalıştırın; `php-mysql`, `php-zip`, `php-xml`, `php-mbstring` açık olmalı.
2. Veritabanı: `CREATE DATABASE demirtas CHARACTER SET utf8mb4 COLLATE utf8mb4_turkish_ci;`
3. Ortam değişkenleri (gerekirse): `DB_HOST DB_PORT DB_NAME DB_USER DB_PASS` (varsayılan: localhost / demirtas / root / boş).
4. Şema + eski verilerin aktarımı: `php import.php demirtas_eski_kayitlar.xlsx --kur` (tekrar çalıştırılabilir; ikinci çalıştırmada kayıt çoğalmaz).
5. Deneme kullanıcıları: `php scripts/seed_users.php` (rastgele parola üretir ve ekrana yazar).
6. Sunucu: `php -S 127.0.0.1:8081 -t public public/index.php` (üretimde `public/` klasörü web kökü olmalıdır, HTTPS şart).

## Testler
- Hesap/normalleştirme birim testleri: `php tests/run.php`
- Hepsi birden: `sh tests/hepsi.sh` (Windows: `tests\hepsi.bat`; test veritabanını sıfırdan kurar; 9 dosya, 22.11.2026 itibarıyla **475 test**: birim 93, hesap 36, stok 53, satış 86, rapor 25, e-Fatura 41, cari/kur 53, asistan 71, denetim zinciri 17)
- İsteğe bağlı e-Fatura şema testi: GİB UBL-TR 1.2.1 paketindeki `UBL-Invoice-2.1.xsd` yolunu `EFATURA_XSD` ortam değişkenine verin (`tests/efatura_test.php`).
- Stok testleri (yalnızca `_test` veritabanında çalışır): `mysql -e "CREATE DATABASE demirtas_test CHARACTER SET utf8mb4 COLLATE utf8mb4_turkish_ci"; mysql demirtas_test < schema.sql; DB_NAME=demirtas_test php tests/stok_test.php`
- Satış zinciri HTTP testi (4 rol, `_test` veritabanına bağlı sunucuda): `DB_NAME=demirtas_test php -S 127.0.0.1:8082 -t public public/index.php` + `php tests/satis_http.php`
- Güvenlik testleri (sunucu açıkken): `BASE_URL=... PW_PATRON=... PW_MUHASEBE=... PW_DEPO=... PW_SATISCI=... php tests/guvenlik_http.php`

## Mimari kararlar
- Para her yerde tam sayı kuruş veya DECIMAL metni olarak işlenir; `float` yoktur.
- Stok değeri doğrudan güncellenmez; her değişiklik `stok_hareketleri` kaydıdır. Cari bakiye `cari_hareketler` toplamıdır.
- Yetki: rol → yetki tablosu `src/Core/Roles.php`; kontrol her istekte sunucuda (`Router`/`Auth::require`). Menüde gizleme yalnızca görünümdür.
- Güvenlik: argon2id parola özeti, hazırlanmış sorgular (emülasyon kapalı), çıktıda `e()` ile kaçırma + CSP, tüm POST'larda CSRF jetonu + Origin denetimi, HttpOnly/SameSite çerez, oturum kimliği yenileme, 5 hatalı denemede 15 dk engel.
- İşlem kaydı (`islem_kaydi`) yalnızca eklenir; tetikleyiciler UPDATE/DELETE'i engeller. Ayrıca kayıtlar bir **hash zinciriyle** bağlıdır (her satırın SHA-256 özeti bir öncekini kapsar, `src/Core/Audit.php`); bir kayıt değiştirilir ya da silinirse Denetim sayfasında "Zincir bozuk" uyarısı çıkar. Test: `tests/denetim_zincir_test.php`.
- Tüm tarih/saatler Türkiye saatiyle (UTC+03:00) üretilir: `src/autoload.php` PHP saat dilimini, `Db::saatDilimi()` veritabanı oturumunu buna ayarlar (`APP_TZ` ile değiştirilebilir). Bu değişiklikten önceki işlem kayıtları sunucunun eski saat diliminde (UTC−8) saklıdır.

## Varsayımlar ve kararlar (müşteri yanıt vermedi; sorulan sorular için kendi kararımız)
- Aynı VKN + tipte mükerrer carilerde kodlu kayıt esas alındı, diğeri aktarılmadı, bakiyeler toplanmadı (atlanan 17 kaydın bakiyesi sisteme yansımadı: ayrıntı aktarım raporunda).
- Bakiyesi dolu ama B/A boş carilerin bakiyesi aktarılmadı ("karar bekliyor").
- EUR cari bakiyeleri TL'ye çevrilmeden EUR olarak açıldı.
- %18 KDV'li ürünler %20 olarak aktarıldı (rapora şüpheli olarak yazıldı).
- Konkordato/kapandı notlu cariler "engelli" (sipariş girilemez); peşin/çek notları yalnızca uyarı.
- Açılış bakiye ve stok tarihi 31.12.2025 varsayıldı.

### Soru e-postasındaki kalan konular için kararlarımız (VOQA yanıtı: "kararları tarafınızca alın")

VOQA Yazılım, gönderdiğimiz 15 soruya cevap vermedi; eksiklerin tespit edilip kararların adayca alınmasının değerlendirmenin parçası olduğunu bildirdi. Yukarıdaki maddelere ek olarak aşağıdaki kararlar alınmıştır. "Sistemde yapıldı" yazılmayan hiçbir madde yapılmış sayılmamalıdır.

| Konu | Kararımız | Sistemde durumu |
|---|---|---|
| Negatif / ondalıklı / belirsiz stok | Başlangıçta **0** kabul edilir; fiziksel sayım gerekir. Eski veride eksi stoğun anlamı yoktur. | Yapıldı: aktarımda 0 yazıldı, her satır "sayım gerekli" gerekçesiyle aktarım raporunda. Stok → Sayım ekranı farkı `sayim` hareketi olarak ekler. |
| Eksik / tekrarlanan ürün ve cari kodları | Otomatik kod verilir; hangi kodun kimde kaldığı raporlanır. | Yapıldı: tekrar eden kod, kategori/tip önekine uyan kayıtta kalır, diğerine sıradaki kod atanır (`BOY-0020` biçimi, cari için `120.01.xxx`). Hepsi raporda. |
| Ayrılan satış personelinin müşterileri | Müşteri **atanmamış** bırakılır; otomatik başka personele devredilmez, çünkü hangi personelin alacağı iş kararıdır. | Kısmen: aktarımda "Hakan Bey (ayrıldı)" müşterileri atanmamış kaldı ve uyarı yazıldı. **Toplu devir ekranı yok** (bilinen eksik). Atanmamış müşteriyi satışçı göremez; patron/muhasebe görür. |
| Kısmi sevkiyatta fatura | Sevkiyat (irsaliye) serbest ve stoğu düşer. Fatura aynı siparişin bir veya birden çok irsaliyesinden oluşur; her sevkiyata ayrı fatura **zorunlu tutulmadı**. | Yapıldı: sistemde bir fatura yalnızca aynı siparişin irsaliyelerinden oluşur. Kullanıcı isterse her irsaliyeyi ayrı faturalar. |
| e-Arşiv | Bu aşamada **yalnızca e-Fatura UBL-TR 1.2** XML üretilir. e-Arşiv, alıcının e-Fatura mükellefi olup olmamasına göre ayrılır ve GİB/entegratör bağlantısı gerektirir. | **Yapılmadı.** XML şeması benzer olduğundan eklenmesi kolaydır, ancak mükellef sorgulaması ve entegratör bağlantısı yoktur. GİB şema doğrulaması da denenmedi. |

Müşteriye bırakılan sorular: atanmamış müşterilerin hangi personele devredileceği, e-Arşiv'in kapsamı, eski sistemdeki eksi stokların nedeni.

## Bitiremediğimiz veya yarım kalan kısımlar
Her şey tamamlanmadı; yapılmayanları ve sınırları saklamadan yazıyoruz.
- **Fatura iptali / iade faturası yok.** Hatalı kesilen faturanın sistem içinden geri alınması yapılmadı.
- **e-Fatura:** XML üretilir ve GİB'in resmî UBL-TR 1.2.1 şemasına (XSD) karşı doğrulanmıştır; ancak mali mühür imzası, özel entegratör bağlantısı, Schematron iş kuralı denetimi, GİB test ortamına yükleme ve e-Arşiv **yoktur**.
- **30 günlük grafik yalnızca beklenen tahsilattır** (açık fatura vadeleri + çek/senet vadeleri). Tedarikçi ödemelerinin vadesi verilmediği için çıkışlar modellenmedi; net nakit akışı değildir.
- **Yapay zekâ asistanı canlıda kural tabanlı modda çalışır.** Claude ile cevap üreten kod yazıldı ve sahte API ile test edildi, ancak canlıda API anahtarı tanımlı değil. Asistan her iki modda da salt okunurdur (ayrıntı aşağıda).
- **EUR için kur ve TL toplamı yok;** EUR bakiyeler ayrı kalem. Tahsilat ekstresi için ayrı PDF üretilmez (tarayıcıdan yazdır/PDF).
- **Ayrılan personelin müşterilerini toplu devreden ekran yok;** bu müşteriler atanmamış bırakıldı.
- **Canlıda veritabanı tetikleyicisi kurulamadı** (ücretsiz hosting yetkisi vermiyor); işlem kaydının değişmezliği hash zinciriyle sağlanır, ayrıntı "Canlı yayın" bölümünde.
- **Mobil uygulamada** `usesCleartextTraffic` ayarı yerel deneme için açık kalmıştır; canlı sunucu HTTPS'tir ve üretimde kapatılmalıdır.

## Stok modülü notları
- Stok `stok_hareketleri` toplamıdır. Transfer = kaynakta çıkış + hedefte giriş, tek transaction; ürün satırı `FOR UPDATE` ile kilitlenir (eşzamanlı işlemde eksi stok oluşmaz).
- Her form gizli bir tek kullanımlık anahtar taşır (`islem:<anahtar>:...`): çift tıklama/yeniden gönderim kayıt çoğaltmaz.
- Sayım stoğu üzerine yazmaz; fark kadar `sayim` hareketi ekler.
- Miktarlar tam sayı "milli" (binde bir) ile hesaplanır; birim çevrimi pay/payda ile yapılır (örn. 1 kutu = 36/25 m²). Adet/torba gibi birimlerde kesirli sonuç reddedilir.
- Yazma işlemleri yalnızca bugünün tarihiyle yapılır; geçmişe dönük kayıt yok.

## Satış zinciri kuralları (modül 04)
- Hesap: miktar milli, fiyat 1/10000 TL, tutar kuruş; tek yuvarlama kuralı yarım yukarı. Sıra: satır brüt → satır iskontosu → genel iskonto (satırlara orantılı, kuruşlar en büyük kalan yöntemiyle tam dağıtılır) → KDV oranı (%1/%10/%20) grubu başına matrah toplanır, KDV grup başına bir kez yuvarlanır.
- Risk: bakiye + faturalanmamış açık sipariş (**onay bekleyenler dahil**, iptaller hariç) + yeni sipariş > limit ise sipariş "onay bekliyor"; yalnızca patron onaylar. Onay bekleyen siparişi dahil etmemizin nedeni: bekleyen büyük bir siparişin yanından küçük siparişlerin limiti sessizce geçmesini önlemek. Bedeli: onay bekleyen sipariş varken müşterinin yeni siparişleri de onaya düşer; patron bekleyeni onaylar ya da iptal eder. Bir sipariş her durumda yalnızca bir kez sayılır. Limit tanımsızsa her sipariş onay ister; "limitsiz" işaretliyse istemez. Engelli cariye sipariş girilemez.
- Stok irsaliyede düşer, cari borç faturada yazılır. Kısmi sevkiyat serbest; yetersiz stokta irsaliyenin tamamı geri alınır.
- Bir fatura yalnızca aynı siparişin irsaliyelerinden oluşur. Fatura numarası boşluksuz sıralıdır.
- Tahsilat en eski vadeli faturadan başlayarak mahsup edilir; fazlası avans. Çek/senet için vade ve evrak no zorunlu; karşılıksız çıkarsa cari yeniden borçlanır, mahsuplar negatif satırla geri alınır (hiçbir kayıt silinmez).
- Henüz yok (bilinen eksikler): fatura iptali/iade faturası, farklı para birimi (EUR) için kur, tahsilat ekstresi PDF.

## Yönetim paneli notları
- Yalnızca TRY toplanır; EUR ayrı kalem (kur uydurulmadı). Yaşlandırma yalnızca faturalar üzerindendir; eski sistemden gelen devir bakiyelerinin vadesi yoktur, ayrı notla gösterilir.
- 30 günlük akış **beklenen tahsilattır** (açık fatura vadeleri + portföy çek/senet vadeleri). Tedarikçi ödemelerinin vade bilgisi olmadığı için çıkışlar modellenmedi; bu bir eksiktir, net nakit akışı değildir.

## e-Fatura (modül 05)
- Faturadan `GET /faturalar/xml?id=` ile UBL-TR 1.2 XML indirilir (patron/muhasebe). Firma bilgileri ortam değişkenleriyle verilir: `FIRMA_UNVAN FIRMA_VKN FIRMA_VERGI_DAIRESI FIRMA_ADRES FIRMA_ILCE FIRMA_IL FIRMA_EPOSTA`. **Varsayılan değerler deneme verisidir; gerçek kullanımda mutlaka değiştirilmelidir.**
- Eşleme: numara `FTR-2026-000001` → `FTR2026000000001`; UUID = ETTN; profil TICARIFATURA, tip SATIS; KDV oran grubu başına TaxSubtotal, satır KDV'leri grup KDV'sine kuruşu kuruşuna dağıtılır; satır + genel iskonto satırda tek AllowanceCharge; irsaliye referansı DespatchDocumentReference.
- Engelleyiciler (üretilmez, nedeni ekranda yazar): müşteri VKN/TCKN eksik/geçersiz, il boş, TRY dışı para birimi (kur verisi yok), UN/ECE kodu tanımsız birim, satır-başlık toplamı tutmuyor.
- **Sınırlar:** XML mali mühürle imzalanmamıştır (imza/gönderim entegratör işidir; imza yeri `ext:UBLExtensions` içinde bir yer tutucudur). Üretilen XML, GİB'in resmî **UBL-TR 1.2.1 şemasına (XSD) karşı doğrulanmıştır** (22.11.2026, `ornek_efatura.xml` ve `EFATURA_XSD` testi). **Schematron iş kuralı denetimi yapılmadı**, GİB test ortamına yükleme yapılmadı, özel entegratör bağlantısı yoktur; e-Arşiv yapılmadı. İskonto eşlemesi (satır düzeyi) entegratörle teyit edilmelidir. Aktarılan carilerden VKN'si 9 haneli/hatalı olanlar için XML üretilemez; önce cari kartı düzeltilmelidir.

## Cari modülü notları (modül 02)
- Cari kartı: bilgiler, bakiye, risk limiti kullanımı, yaşlandırma, son hareketler. Satışçı yalnızca kendi müşterisini görür (başkasınınki 404).
- Düzenleme (patron/muhasebe): VKN/TCKN algoritmayla doğrulanır, aynı tipte mükerrer VKN engellenir, her değişiklik eski/yeni değerle işlem kaydına yazılır. **Risk limitini yalnızca patron değiştirebilir** (sunucuda kontrol edilir).
- Ekstre: devreden bakiye + dönem hareketleri + yürüyen bakiye; "Yazdır / PDF" tarayıcının yazdırma özelliğiyle PDF'e çevrilir (sunucuda ayrı PDF üretilmez).
- Döviz: TCMB günlük XML'inden döviz satış kuru çekilir (`/kur/guncelle`); ağ yoksa elle girilir. Kaynak ("tcmb"/"elle") her kayıtta yazılıdır. TCMB bağlantısı canlı sunucuda denendi ve çalışıyor (22.11.2026); ayrıştırma ayrıca örnek yanıtla test edildi.
- EUR carilerin bakiyesi kendi para biriminde tutulur; kartta TL karşılığı gösterilir, toplamlara kur uygulanarak katılmaz.

## Asistan (modül 09) — salt okunur güvence katmanları
1. **Serbest SQL yok:** asistan yalnızca `src/Services/Asistan/Araclar.php` içindeki 7 sabit okuma aracını çağırır (cari_ara, cari_ozet, stok_ara, kritik_stok, geciken_faturalar, siparis_sorgula, gunluk_ozet). Yazan/silen araç yoktur; yapay zekâ olmayan kural yönlendirici de aynı araçları kullanır.
2. **Okunur bağlantı:** asistan işi `Db::okunurModda()` içinde, oturumu `READ ONLY` olan ayrı bağlantıyla çalışır. INSERT/UPDATE/DELETE/TRUNCATE/DROP/ALTER sunucuda reddedilir (testli).
3. **En güçlü katman — ayrı veritabanı kullanıcısı:** `scripts/ai_readonly.sql` yalnızca SELECT yetkili `demirtas_ai` kullanıcısını oluşturur (parola özetleri ve işlem kaydı tablolarına bile erişemez). `AI_DB_USER` / `AI_DB_PASS` ortam değişkenleriyle devreye alın. **Canlıda bunu mutlaka kurun.**
4. **Yetki:** araçlar kullanıcının rolüne göre kısıtlanır (depo cari bilgisi göremez, satışçı yalnızca kendi müşterilerini görür). Modele yalnızca izinli araçlar sunulur.
5. **Enjeksiyon:** veritabanındaki metinler (ünvan, not) veridir; yazma aracı olmadığından içlerindeki "sil" talimatları bir şey yapamaz (testli). Çıktı HTML'e kaçırılarak yazılır.
6. Her soru `islem_kaydi`'na yazılır; kullanıcı başına 10 dakikada 20 soru sınırı vardır.
- Yapay zekâ: `ANTHROPIC_API_KEY` (ve isteğe bağlı `ANTHROPIC_MODEL`) tanımlıysa Claude araç kullanımıyla cevap yazar; hata olursa kural yönlendiriciye düşer. **Gerçek API çağrısı bu ortamda denenemedi;** döngü sahte API ile test edildi.

## Modül 08 — Mobil uygulama (React Native / Expo) ve API

**API (`/api/v1`, `src/Api/Api.php`)**: çerez/oturum yok; `Authorization: Bearer dym_…` jetonu. Jeton 30 gün geçerlidir, veritabanında yalnızca SHA-256 özeti saklanır, çıkışta iptal edilir, kullanıcı pasife alınırsa **anında** geçersizdir (her istekte kullanıcı durumu okunur). Yalnızca `siparis.olustur` yetkili roller (patron, satışçı) giriş yapabilir; satışçı sadece kendi müşterilerini görür/sipariş verebilir.
- `POST /giris`, `POST /cikis`, `GET /senkron` (müşteri+ürün önbelleği), `POST /siparisler` (toplu ≤50, kalem başına sonuç `olustu|zaten_var|hata`), `GET /siparisler`.
- **Çift kayıt koruması**: istemci her sipariş için 32 haneli anahtarı bir kez üretir; sunucu `kaynak_key` ile tekrarı `zaten_var` olarak yanıtlar. 6 paralel aynı-anahtar istek testte tek sipariş üretir.
- **Fiyat sunucudan**: istemci fiyat gönderse bile yok sayılır. Tarih gelecekte/60 günden eskiyse bugüne çekilir.
- Test: `DB_NAME=demirtas_test PW_PATRON=.. PW_DEPO=.. PW_SATISCI=.. php tests/api_http.php` (31 kontrol).

**Uygulama (`mobil/`)**: giriş (sunucu adresi ayarlanabilir), müşteri arama, ürün arama, sipariş, **çevrimdışı kuyruk** (AsyncStorage), bağlantı gelince otomatik gönderim (NetInfo), kuyruk ekranı (bekliyor/gönderildi/hata, yeniden dene). Kuyruk mantığı `src/queue.js` içinde saf fonksiyonlardır: `cd mobil && node --test test/queue.test.mjs` (12 test).
- Çalıştırma: `cd mobil && npm install && npx expo start` → telefonda Expo Go ile QR okut.
- APK: `npm i -g eas-cli && eas login && eas build -p android --profile apk` (Expo hesabı gerekir, ücretsiz katman yeterli) veya Android Studio ile `npx expo prebuild && cd android && ./gradlew assembleRelease`.
- **Gerçek durum (21.11.2026)**: Uygulama iPhone'da Expo Go (SDK 58) ile **gerçek cihazda ve canlı sunucuda** denendi: giriş, 67 müşteri listesi (satışçıya ait), sipariş gönderme, **uçak modunda kuyruğa alma**, bağlantı gelince otomatik gönderim; web panelinde sipariş **tek kopya** göründü. APK Expo'nun bulut derleyicisiyle (EAS) üretildi; APK 21.11.2026'da bir Android cihazda da denendi; alt çubuğun gezinme tuşlarının altında kaldığı hata bulundu, safe-area ile düzeltildi ve yeni APK yeniden denendi. HTTP (cleartext) yalnızca yerel deneme içindir; canlıda HTTPS kullanılır.
- **Ücretsiz hosting bot koruması**: InfinityFree, tarayıcı olmayan istemcilere önce küçük bir JavaScript sınavı (`toNumbers(...)` → AES → `__test` çerezi) döndürür. Uygulama bu sınavı çözüp isteği tekrarlar (`mobil/src/botkoruma.js`). Postman/curl gibi istemcilerle bu hostingte API denemesi aynı nedenle çalışmayabilir; kendi sunucunuzda (VPS/XAMPP) böyle bir koruma yoktur.

## Canlı yayın (InfinityFree, ücretsiz) — ne yapıldı, ne kısıtlı
- Adres: `https://demirtasyapi.rf.gd` (HTTPS çalışıyor). PHP 8.4, MariaDB; dosya yapısı: `htdocs/` = `public/` içeriği, kaynak kod `htdocs/app/` (içine `.htaccess` ile dışarıdan erişim kapalı), `.env` `htdocs/app/.env`.
- Veritabanı phpMyAdmin ile içe aktarıldı (şema + eski kayıt aktarımı + demo kullanıcılar).
- **Kısıt 1 — tetikleyici yetkisi yok**: ücretsiz hesap `TRIGGER` yetkisi vermiyor (`#1142`). `islem_kaydi` tablosunun UPDATE/DELETE'e karşı veritabanı seviyesindeki koruması canlıda **kurulamadı**. Uygulama bu tabloya yalnızca INSERT yapar; yerel kurulumda ve VPS'te `schema.sql` tetikleyicileri çalışır. Canlıda değişmezlik tetikleyici yerine **hash zinciriyle** sağlanır: engellemez, ama kurcalamayı Denetim sayfasında görünür kılar (silinen *son* kayıtlar zincirle yakalanamaz; bu bilinen bir sınırdır).
- **Kısıt 2 — bot koruması** (yukarıda).
- **Kısıt 3 — dışa çıkış**: TCMB kur çekimi canlıda **denendi ve çalışıyor** (22.11.2026). Anthropic API çağrısı canlıda yapılandırılmamıştır; asistan bu durumda kural tabanlı "anahtar kelime eşleştirme" modunda çalışır (sayfada uyarı yazar). Kur gerekirse "elle gir" ekranından da girilebilir.
- Şifre algoritması: kod `argon2id` kullanır, yoksa bcrypt'e düşer (`Auth::hashPassword`); canlıda demo kullanıcıların parolaları bcrypt ile yazıldı.
