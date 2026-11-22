# YAPAY ZEKÂ KULLANIM VE ADAY KATKI BEYANI

**Aday:** Elif Buket Duman  
**Bölüm:** Bilgisayar Mühendisliği, yeni mezun  
**Proje:** Demirtaş Yapı Market – Harç  
**Kullanılan yapay zekâ aracı:** Claude (Anthropic)

## 1. Yapay zekâ kullanımının kapsamı

Proje, yaklaşık 72 saatlik geliştirme sürecinde adayın yönlendirmeleri, araştırmaları, tasarım tercihleri ve geri bildirimleri doğrultusunda yapay zekâ desteğiyle geliştirilmiştir. Claude, süreç boyunca yazılım geliştirme, kod üretimi, teknik yönlendirme, dokümantasyon ve içerik oluşturma amacıyla kullanılmıştır.

PHP/MySQL altyapısı, stok ve sipariş yönetimi, sipariş–irsaliye–fatura–tahsilat süreçleri, e-Fatura XML oluşturma, yönetim paneli, cari hesap modülleri, asistan, mobil API, Expo uygulaması ve otomatik testlerin önemli bir bölümü yapay zekâ tarafından oluşturulmuştur.

Kodun büyük bölümü yapay zekâ desteğiyle geliştirilmiş olup aday, yazılımın tüm kodlarını sıfırdan ve bağımsız olarak yazdığını iddia etmemektedir. Geliştirme süreci, adayın ihtiyaçları belirlemesi, üretilen çıktıları değerlendirmesi, uygulaması, test etmesi ve gerekli değişiklikleri yönlendirmesi üzerinden ilerlemiştir.

## 2. Adayın çalışmaya katkısı

Adayın projedeki rolü; araştırma, ihtiyaç analizi, ürün ve tasarım kararları, geliştirme sürecinin yönlendirilmesi, uygulama, test, hata tespiti ve doğrulama aşamalarını kapsamaktadır.

- **Araştırma ve ihtiyaç analizi:** Proje geliştirilmeden önce yapı market ve hırdavat toptancılığı sektörünün işleyişi, hedef kullanıcı ihtiyaçları ve benzer sistemler araştırılmış; proje kapsamı bu çalışmalar doğrultusunda şekillendirilmiştir.

- **Tasarım ve görsel yönlendirme:** Yönetim paneli, mobil uygulama, tanıtım sitesi, pazarlama materyalleri ve diğer görsel çalışmaların tasarım fikirleri, yerleşimleri, renk tercihleri, içerik yapıları ve genel görsel yönü adayın kendi araştırmaları ve fikirleri doğrultusunda belirlenmiştir. Yapay zekâ, adayın tasarım yönlendirmelerini uygulamaya dönüştürmüş, alternatifler oluşturmuş ve istenen düzenlemeleri gerçekleştirmiştir.

- **Ürün ve geliştirme kararları:** Projenin adı, hedef kitlesi, ürün yaklaşımı, hangi özelliklerin geliştirileceği, kullanıcı deneyiminin nasıl şekillendirileceği ve pazarlama çıktılarının kapsamı aday tarafından belirlenmiştir.

- **Problem çözme ve iş mantığı:** Geliştirme sırasında ortaya çıkan eksiklikler incelenmiş, çözüm ihtiyaçları belirlenmiş ve değişiklikler için yönlendirmelerde bulunulmuştur. Özellikle risk limiti hesaplamasında onay bekleyen siparişlerin de hesaba katılması gerektiği aday tarafından fark edilmiş ve bu doğrultuda sistemin hesaplama mantığının güncellenmesi sağlanmıştır. Söz konusu değişiklik canlı sistemde denenerek sonuçları kontrol edilmiştir.

- **Komut satırı ve geliştirme ortamı kullanımı:** Proje sürecinde CMD (Komut İstemi) aktif olarak kullanılmış; dosya ve proje işlemleri, geliştirme sürecinin takibi, testlerin çalıştırılması ve gerekli teknik işlemler aday tarafından gerçekleştirilmiştir.

- **Uygulama ve entegrasyon:** Hosting kurulumu (InfinityFree), veritabanı aktarımı, GitHub işlemleri, dosyaların canlı ortama aktarılması, sistemin yayına alınması ve farklı cihazlarda denenmesi aday tarafından yürütülmüştür.

- **Test ve doğrulama:** Otomatik testler aday tarafından kendi bilgisayarında çalıştırılmış; bilgisayar, iPhone ve Android cihazlar üzerinde gerçek kullanım denemeleri gerçekleştirilmiştir. Testlerde ve cihaz kullanımında karşılaşılan sorunlar belirlenerek yapay zekâya geri bildirim verilmiş, düzeltmeler sonrasında yeniden kontroller yapılmıştır.

- **Geri bildirim ve kalite kontrol:** Yapay zekâ tarafından oluşturulan kod, tasarım ve içerikler proje gereksinimleri doğrultusunda değerlendirilmiş; uygun bulunmayan çıktılar için değişiklik istenmiş, karar gerektiren konularda alternatifler değerlendirilerek geliştirme sürecine yön verilmiştir.

## 3. Test ve doğrulama süreci

Yapay zekâ desteğiyle oluşturulan otomatik testler aday tarafından kendi bilgisayarında çalıştırılmıştır. `tests\hepsi.bat` aracılığıyla gerçekleştirilen test çalıştırmasında 475 test başarılı, 0 test hatalı sonucu alınmıştır.

Bunun yanında sistemin temel işleyişi canlı ortamda denenmiş, test siparişleri oluşturulmuş, farklı cihazlarda kullanım deneyimi gözlemlenmiş ve sistemin işleyişine ilişkin kontroller gerçekleştirilmiştir.

Test sonuçları, gerçekleştirilen test senaryolarının başarılı olduğunu göstermektedir. Ancak bu sonuç, tüm olası senaryoların test edildiği veya sistemin bütünüyle hatasız olduğu anlamına gelmemektedir.

## 4. Yapay zekânın proje sürecindeki rolü

Yapay zekâ, proje boyunca yalnızca kod üretimi amacıyla değil; teknik araştırma, çözüm alternatiflerinin değerlendirilmesi, hata giderme, geliştirme sürecinin hızlandırılması, dokümantasyon hazırlanması ve içerik oluşturulması amacıyla da kullanılmıştır.

Projenin teknik altyapısının ve modüllerinin önemli bir bölümü yapay zekâ desteğiyle oluşturulmuş; aday ise ihtiyaçları belirleme, araştırma, tasarım yönlendirmesi, geliştirme sürecini takip etme, uygulama, test etme, hataları tespit etme ve geri bildirim sağlama aşamalarında aktif rol almıştır.

## 5. Yapay zekânın yapmadığı işlemler ve kullanım sınırları

- Hosting hesabı, canlı sunucu, GitHub deposu ve HTTPS ayarları adayın kendi hesaplarında gerçekleştirilmiştir. Claude, adayın bilgisayarına veya hesaplarına doğrudan erişmemiştir.
- Canlı sistemin kurulumu, dosya aktarımı, veritabanı işlemleri ve yayın süreci aday tarafından uygulanmıştır.
- GİB Schematron denetimi, GİB test ortamına yükleme, özel entegratör bağlantısı ve e-Arşiv entegrasyonu gerçekleştirilmemiştir.
- Anthropic API çağrısı canlı sistemde yapılandırılmamıştır.
- Mükerrer cari bakiyeler, EUR bakiyeler, KDV oranları ve benzeri karar gerektiren konularda yapay zekâ öneriler sunmuş; proje kapsamında kabul edilen varsayımlar README dosyasındaki ilgili bölümde belgelenmiştir.

## 6. Çalışma zamanında yapay zekâ kullanımı

Uygulama içerisindeki asistan salt okunur yapıdadır. `ANTHROPIC_API_KEY` tanımlandığı takdirde Claude üzerinden cevap üretme özelliği bulunmaktadır. API anahtarı tanımlı olmadığında ise anahtar kelime eşleştirmesine dayalı sistem çalışmaktadır.

Canlı ortamda Anthropic API anahtarı tanımlı değildir. Dolayısıyla uygulamanın canlı sürümünde doğrudan Claude API üzerinden çalışan bir yapay zekâ bağlantısı bulunmamaktadır.

## 7. Pazarlama ve sunum çıktıları

Proje kapsamında hazırlanan pazarlama materyalleri; metinler, görseller, carousel içerikleri, broşür, tanıtım sitesi kodu ve demo videosu yapay zekâ desteğiyle oluşturulmuştur.

Bu çalışmaların araştırma, fikir, tasarım yönü, hedef kitle ve içerik yaklaşımı aday tarafından belirlenmiş; yapay zekâ bu yönlendirmeler doğrultusunda üretim, düzenleme ve uygulama aşamalarında kullanılmıştır.

## 8. Genel beyan

Bu proje, yapay zekâ destekli yazılım geliştirme yaklaşımıyla oluşturulmuştur. Aday, yapay zekâyı geliştirme sürecinde aktif bir araç olarak kullanmış; projenin araştırma, tasarım, kapsam belirleme, uygulama, test, doğrulama ve geliştirme kararlarında sürece katılım sağlamıştır.

Bu beyan, yapay zekânın proje geliştirme sürecindeki rolünü ve adayın katkılarını şeffaf, doğru ve gerçeğe uygun şekilde açıklamak amacıyla hazırlanmıştır.