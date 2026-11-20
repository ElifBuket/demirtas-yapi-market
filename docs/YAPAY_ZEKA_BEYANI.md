# Yapay zekâ kullanım beyanı

**Kullanılan araç:** Claude (Anthropic), sohbet/kod asistanı olarak. Adayın talimatıyla çalıştı; her adım aday tarafından istendi ve gözden geçirilecek.

## Yapay zekânın yaptığı işler
- Proje şartlarının ve Excel verisinin incelenmesi, eksik/çelişkili bilgilerin listelenmesi, varsayımların önerilmesi.
- PHP/MySQL kodunun yazılması: altyapı ve güvenlik, eski veri aktarımı, stok, sipariş→irsaliye→fatura→tahsilat, e-Fatura XML, yönetim paneli, cari yönetimi, asistan.
- Otomatik testlerin yazılması ve çalıştırılması (hesap, stok, satış, rapor, e-Fatura, cari, asistan, HTTP güvenlik).
- README, kurulum yönergesi ve bu beyanın taslağı.

## Yapay zekânın yapmadığı / sınırları
- Canlı sunucuya yayın, hosting hesabı, GitHub deposu, alan adı ve HTTPS adayın işidir.
- TCMB canlı bağlantısı, GİB resmî şema doğrulaması ve gerçek mali mühür/entegratör akışı yapay zekâ ortamında denenemedi (README'de belirtildi).
- Karar gerektiren konularda (mükerrer cari bakiyeleri, EUR bakiyeler, %18 KDV vb.) yapay zekâ öneri sundu; kararlar README'deki "Varsayımlar" bölümünde adayın sorumluluğunda belgelenmiştir.

## Adayın katkısı (adayın doldurması gerekir)
- [ ] Hangi bölümleri satır satır okuyup anladım:
- [ ] Hangi bölümleri değiştirdim/geliştirdim:
- [ ] Canlı sunumda kendi başıma anlatabileceğim bölümler:

## Çalışma zamanında yapay zekâ
- Uygulamadaki **asistan** salt okunurdur (bkz. README "Asistan"). `ANTHROPIC_API_KEY` tanımlıysa Claude ile cevap üretir; tanımlı değilse yapay zekâ kullanılmaz, anahtar kelime eşleştirmesi çalışır.
