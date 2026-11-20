<?php
declare(strict_types=1);
/**
 * Satış zinciri HTTP uçtan uca testi (4 rol). Yalnızca *_test veritabanında çalışır; sunucu da aynı veritabanına bağlı olmalı.
 *   DB_NAME=demirtas_test BASE_URL=http://127.0.0.1:8082 PW_PATRON=.. PW_MUHASEBE=.. PW_DEPO=.. PW_SATISCI=.. php tests/satis_http.php
 */
require dirname(__DIR__) . '/src/autoload.php';
use App\Core\{Config, Db};
use App\Services\{Hesap, Satis, Stok};

if (!str_ends_with(Config::env('DB_NAME', 'demirtas'), '_test')) { fwrite(STDERR, "Yalnızca *_test veritabanı.\n"); exit(2); }
$base = rtrim(getenv('BASE_URL') ?: 'http://127.0.0.1:8082', '/');
$pw = ['patron' => getenv('PW_PATRON'), 'muhasebe' => getenv('PW_MUHASEBE'), 'depo' => getenv('PW_DEPO'), 'satisci' => getenv('PW_SATISCI')];
$pass = 0; $fail = 0;
function ok(string $ad, bool $k, string $d = ''): void { global $pass, $fail; if ($k) { $pass++; return; } $fail++; echo "BAŞARISIZ: $ad" . ($d !== '' ? " [$d]" : '') . "\n"; }

final class Ist {
    public string $jar;
    public function __construct(private string $base) { $this->jar = tempnam(sys_get_temp_dir(), 'j'); }
    public function istek(string $m, string $yol, array $form = []): array {
        $ch = curl_init($this->base . $yol);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_CUSTOMREQUEST => $m, CURLOPT_TIMEOUT => 30]);
        if ($m === 'POST') curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form));
        $y = (string)curl_exec($ch); $kod = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $hs = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
        preg_match('/^Location:\s*(\S+)/mi', substr($y, 0, $hs), $mm);
        return ['kod' => $kod, 'govde' => substr($y, $hs), 'konum' => $mm[1] ?? null];
    }
    public function csrf(string $yol): string { preg_match('/name="_csrf" value="([0-9a-f]+)"/', $this->istek('GET', $yol)['govde'], $m); return $m[1] ?? ''; }
    public function giris(string $ka, string $p): void { $this->istek('POST', '/giris', ['_csrf' => $this->csrf('/giris'), 'kullanici_adi' => $ka, 'parola' => $p]); }
    /** Sayfayı açıp formundaki csrf ile POST eder; yönlendirmeyi takip eder ve son sayfayı döner. */
    public function gonder(string $formSayfasi, string $yol, array $form): array {
        $r = $this->istek('POST', $yol, ['_csrf' => $this->csrf($formSayfasi)] + $form);
        $r['son'] = $r['konum'] ? $this->istek('GET', $r['konum']) : null;
        return $r;
    }
}
function anahtarAl(Ist $i, string $yol): string { preg_match('/name="anahtar" value="([0-9a-f]{32})"/', $i->istek('GET', $yol)['govde'], $m); return $m[1] ?? ''; }

foreach (['tahsilat_dagitim', 'tahsilatlar', 'fatura_kalemleri', 'irsaliye_kalemleri', 'irsaliyeler', 'faturalar', 'siparis_kalemleri', 'siparisler', 'belge_sayaclari', 'cari_hareketler', 'stok_hareketleri', 'urun_birim_cevrim', 'urunler', 'cariler'] as $t) Db::run("DELETE FROM $t");
$satisciKart = (int)Db::value("SELECT satisci_id FROM kullanicilar WHERE kullanici_adi = 'satisci'");
$baskaKart = (int)Db::value('SELECT id FROM satiscilar WHERE id <> ? LIMIT 1', [$satisciKart]);
$cari = Db::insert("INSERT INTO cariler (kod, unvan, tip, risk_limiti, satisci_id) VALUES ('T1','Test Müşteri A.Ş.','musteri','1000.00',?)", [$satisciKart]);
$yabanci = Db::insert("INSERT INTO cariler (kod, unvan, tip, risk_limitsiz, satisci_id) VALUES ('T2','Başkasının Müşterisi','musteri',1,?)", [$baskaKart]);
$urun = Db::insert("INSERT INTO urunler (stok_kodu, ad, kategori, temel_birim, kdv_orani, satis_fiyati) VALUES ('TST-1','Test Ürünü','T','adet',20,'400.0000')");
Db::run("INSERT INTO stok_hareketleri (urun_id, depo_id, tarih, tur, miktar) VALUES (?,1,'2026-01-01','acilis',100)", [$urun]);

$P = new Ist($base); $P->giris('patron', $pw['patron']);
$M = new Ist($base); $M->giris('muhasebe', $pw['muhasebe']);
$D = new Ist($base); $D->giris('depo', $pw['depo']);
$S = new Ist($base); $S->giris('satisci', $pw['satisci']);

// Yetki matrisi
$mat = ['/siparisler' => ['P' => 200, 'M' => 200, 'D' => 200, 'S' => 200], '/faturalar' => ['P' => 200, 'M' => 200, 'D' => 403, 'S' => 403], '/tahsilatlar' => ['P' => 200, 'M' => 200, 'D' => 403, 'S' => 403], '/siparisler/yeni' => ['P' => 200, 'M' => 403, 'D' => 403, 'S' => 200]];
$ist = ['P' => $P, 'M' => $M, 'D' => $D, 'S' => $S];
foreach ($mat as $yol => $roller) foreach ($roller as $r => $beklenen) { $k = $ist[$r]->istek('GET', $yol)['kod']; ok("$r $yol → $beklenen", $k === $beklenen, "kod=$k"); }

// 1) Satışçı limit aşan sipariş girer: 3 adet x 400 TL +%20 = 1.440 TL > 1.000 TL limit
$form = ['cari_id' => $cari, 'depo_id' => 1, 'anahtar' => anahtarAl($S, '/siparisler/yeni'), 'genel_iskonto' => '', 'kod' => ['TST-1'], 'miktar' => ['3'], 'birim' => [''], 'fiyat' => [''], 'iskonto' => ['']];
$r = $S->gonder('/siparisler/yeni', '/siparisler/yeni', $form);
ok('sipariş kaydedildi, detaya yönlendirildi', $r['kod'] === 303 && str_contains((string)$r['konum'], '/siparisler/goster'), "kod={$r['kod']}");
$sid = (int)Db::value('SELECT id FROM siparisler WHERE cari_id = ?', [$cari]);
ok('limit aşımı → onay bekliyor', Db::value('SELECT durum FROM siparisler WHERE id = ?', [$sid]) === 'onay_bekliyor');
ok('liste fiyatı kullanıldı, toplam 1.440,00', Db::value('SELECT toplam FROM siparisler WHERE id = ?', [$sid]) === '1440.00');
ok('detay sayfası onay uyarısını gösterir', str_contains($r['son']['govde'], 'Risk limiti aşılıyor'));
// çift gönderim
$r2 = $S->gonder('/siparisler/yeni', '/siparisler/yeni', $form);
ok('çift gönderimde ikinci sipariş yok', (int)Db::value('SELECT COUNT(*) FROM siparisler WHERE cari_id = ?', [$cari]) === 1 && $r2['kod'] === 422);
// satışçı başkasının müşterisine sipariş veremez / belgesini göremez
$f2 = $form; $f2['cari_id'] = $yabanci; $f2['anahtar'] = anahtarAl($S, '/siparisler/yeni');
$r3 = $S->gonder('/siparisler/yeni', '/siparisler/yeni', $f2);
ok('satışçı başkasının müşterisine sipariş veremez', $r3['kod'] === 404 && (int)Db::value('SELECT COUNT(*) FROM siparisler WHERE cari_id = ?', [$yabanci]) === 0, "kod={$r3['kod']}");
$yid = Db::insert("INSERT INTO siparisler (belge_no, cari_id, depo_id, tarih, durum, matrah, kdv, toplam) VALUES ('S-X-1',?,1,'2026-06-01','onayli',1,0,1)", [$yabanci]);
ok('satışçı başkasının siparişini göremez (404)', $S->istek('GET', "/siparisler/goster?id=$yid")['kod'] === 404);
ok('satışçı listede başkasınınkini görmez', !str_contains($S->istek('GET', '/siparisler')['govde'], 'S-X-1'));
ok('patron o siparişi görür', $P->istek('GET', "/siparisler/goster?id=$yid")['kod'] === 200);
Db::run('DELETE FROM siparisler WHERE id = ?', [$yid]);

// 2) Onaysız sevk reddedilir; muhasebe/satışçı onaylayamaz; patron onaylar
$kid = (int)Db::value('SELECT id FROM siparis_kalemleri WHERE siparis_id = ?', [$sid]);
$D->gonder("/siparisler/goster?id=$sid", '/siparisler/sevk', ['siparis_id' => $sid, 'anahtar' => anahtarAl($D, "/siparisler/goster?id=$sid"), "sevk" => [$kid => '1']]);
ok('onaysız sevk yapılmadı', (int)Db::value('SELECT COUNT(*) FROM irsaliyeler') === 0);
ok('muhasebe onaylayamaz (403)', $M->istek('POST', '/siparisler/onayla', ['_csrf' => $M->csrf('/siparisler'), 'id' => $sid])['kod'] === 403);
ok('satışçı onaylayamaz (403)', $S->istek('POST', '/siparisler/onayla', ['_csrf' => $S->csrf('/siparisler'), 'id' => $sid])['kod'] === 403);
$P->gonder("/siparisler/goster?id=$sid", '/siparisler/onayla', ['id' => $sid]);
ok('patron onayladı', Db::value('SELECT durum FROM siparisler WHERE id = ?', [$sid]) === 'onayli');

// 3) Depo kısmi sevk (2 adet); fazla sevk reddi; muhasebe sevk edemez
$sayfa = "/siparisler/goster?id=$sid";
ok('muhasebe sevk edemez (403)', $M->istek('POST', '/siparisler/sevk', ['_csrf' => $M->csrf('/siparisler'), 'siparis_id' => $sid, 'anahtar' => str_repeat('a', 32), 'sevk' => [$kid => '1']])['kod'] === 403);
$D->gonder($sayfa, '/siparisler/sevk', ['siparis_id' => $sid, 'anahtar' => anahtarAl($D, $sayfa), 'sevk' => [$kid => '5']]);
ok('fazla sevk reddedildi', (int)Db::value('SELECT COUNT(*) FROM irsaliyeler') === 0 && Stok::seviye($urun, 1) === 100000);
$a = anahtarAl($D, $sayfa);
$D->gonder($sayfa, '/siparisler/sevk', ['siparis_id' => $sid, 'anahtar' => $a, 'sevk' => [$kid => '2']]);
$D->gonder($sayfa, '/siparisler/sevk', ['siparis_id' => $sid, 'anahtar' => $a, 'sevk' => [$kid => '2']]); // çift gönderim
ok('kısmi sevk: tek irsaliye, stok 98', (int)Db::value('SELECT COUNT(*) FROM irsaliyeler') === 1 && Stok::seviye($urun, 1) === 98000);
ok('sipariş kısmi sevk durumunda', Db::value('SELECT durum FROM siparisler WHERE id = ?', [$sid]) === 'kismi_sevk');

// 4) Depo fatura kesemez; muhasebe keser
$irs = (int)Db::value('SELECT id FROM irsaliyeler');
ok('depo fatura kesemez (403)', $D->istek('POST', '/faturalar/kes', ['_csrf' => $D->csrf('/siparisler'), 'siparis_id' => $sid, 'irsaliye' => [$irs], 'vade_gun' => 30, 'anahtar' => str_repeat('b', 32)])['kod'] === 403);
$r = $M->gonder($sayfa, '/faturalar/kes', ['siparis_id' => $sid, 'irsaliye' => [$irs], 'vade_gun' => 30, 'anahtar' => anahtarAl($M, $sayfa)]);
ok('fatura kesildi → fatura sayfası', $r['kod'] === 303 && str_contains((string)$r['konum'], '/faturalar/goster'), "kod={$r['kod']} loc={$r['konum']}");
$fid = (int)Db::value('SELECT id FROM faturalar');
ok('fatura 960,00 (2 x 400 + %20)', Db::value('SELECT toplam FROM faturalar WHERE id = ?', [$fid]) === '960.00');
ok('cari bakiye 960,00', Satis::bakiye($cari) === 96000);
ok('fatura sayfası KDV ve toplamı gösterir', str_contains($r['son']['govde'], '960,00') && str_contains($r['son']['govde'], 'KDV %20'));
ok('satışçı fatura sayfasını açamaz (403)', $S->istek('GET', "/faturalar/goster?id=$fid")['kod'] === 403);

// 5) Tahsilat (muhasebe): çek vadesiz hata, çek ile ödeme, karşılıksız
$r = $M->gonder('/tahsilatlar/yeni', '/tahsilatlar/yeni', ['cari_id' => $cari, 'tutar' => '960,00', 'yontem' => 'cek', 'vade' => '', 'evrak_no' => 'C1', 'aciklama' => '', 'anahtar' => anahtarAl($M, '/tahsilatlar/yeni')]);
ok('vadesiz çek reddedildi (422)', $r['kod'] === 422 && str_contains($r['govde'], 'vade'), "kod={$r['kod']}");
$ak = anahtarAl($M, '/tahsilatlar/yeni');
$vade = date('Y-m-d', strtotime('+30 days'));
$ft = ['cari_id' => $cari, 'tutar' => '960,00', 'yontem' => 'cek', 'vade' => $vade, 'evrak_no' => 'C1', 'aciklama' => '', 'anahtar' => $ak];
$M->gonder('/tahsilatlar/yeni', '/tahsilatlar/yeni', $ft);
$M->gonder('/tahsilatlar/yeni', '/tahsilatlar/yeni', $ft);
ok('çek kaydı tek (çift gönderim engellendi)', (int)Db::value('SELECT COUNT(*) FROM tahsilatlar') === 1);
ok('fatura kapandı, bakiye 0', Satis::faturaKalan($fid) === 0 && Satis::bakiye($cari) === 0);
$tid = (int)Db::value('SELECT id FROM tahsilatlar');
$M->gonder('/tahsilatlar?durum=portfoyde', '/tahsilatlar/karsiliksiz', ['id' => $tid]);
ok('karşılıksız: bakiye geri 960,00', Satis::bakiye($cari) === 96000 && Satis::faturaKalan($fid) === 96000);
ok('depo tahsilat sayfasına giremez', $D->istek('GET', '/tahsilatlar/yeni')['kod'] === 403);

// 5b) e-Fatura XML indirme
ok('VKN yokken XML üretilmez (yönlendirme + hata)', $M->istek('GET', "/faturalar/xml?id=$fid")['kod'] === 303);
Db::run("UPDATE cariler SET vkn = '1234567890', vkn_gecerli = 1, il = 'Sivas' WHERE id = ?", [$cari]);
$rx = $M->istek('GET', "/faturalar/xml?id=$fid");
ok('XML indirildi (200)', $rx['kod'] === 200 && str_contains($rx['govde'], '<Invoice') && str_contains($rx['govde'], 'TR1.2'), "kod={$rx['kod']}");
ok('XML geçerli ve ETTN içerir', (new DOMDocument())->loadXML($rx['govde']) && str_contains($rx['govde'], (string)Db::value('SELECT ettn FROM faturalar WHERE id = ?', [$fid])));
ok('depo XML indiremez (403)', $D->istek('GET', "/faturalar/xml?id=$fid")['kod'] === 403);
ok('satışçı XML indiremez (403)', $S->istek('GET', "/faturalar/xml?id=$fid")['kod'] === 403);
ok('olmayan fatura XML 404', $M->istek('GET', '/faturalar/xml?id=99999')['kod'] === 404);
ok('XML indirme işlem kaydına yazıldı', (int)Db::value("SELECT COUNT(*) FROM islem_kaydi WHERE eylem = 'indir' AND tablo = 'faturalar'") >= 1);

// 5c) Cari kartı, düzenleme, ekstre, kur
$cs = "/cariler/goster?id=$cari";
foreach (['P' => 200, 'M' => 200, 'S' => 200, 'D' => 403] as $r => $beklenen) { $k = $ist[$r]->istek('GET', $cs)['kod']; ok("cari kartı $r → $beklenen", $k === $beklenen, "kod=$k"); }
ok('satışçı başkasının cari kartını göremez (404)', $S->istek('GET', "/cariler/goster?id=$yabanci")['kod'] === 404);
ok('satışçı başkasının ekstresini göremez (404)', $S->istek('GET', "/cariler/ekstre?id=$yabanci")['kod'] === 404);
ok('satışçı cari düzenleyemez (403)', $S->istek('GET', "/cariler/duzenle?id=$cari")['kod'] === 403 && $D->istek('GET', "/cariler/duzenle?id=$cari")['kod'] === 403);
$formC = ['id' => $cari, 'unvan' => 'Düzenlenmiş Unvan', 'vkn' => '1234567890', 'vergi_dairesi' => 'VD', 'telefon' => '', 'eposta' => '', 'il' => 'Sivas', 'ilce' => 'Merkez', 'satisci_id' => $satisciKart, 'durum' => 'aktif', 'uyari_notu' => '', 'risk_mod' => 'limitli', 'risk_limiti' => '1000'];
$r = $M->gonder("/cariler/duzenle?id=$cari", '/cariler/duzenle', $formC);
ok('muhasebe cariyi düzenledi (303)', $r['kod'] === 303 && Db::value('SELECT unvan FROM cariler WHERE id = ?', [$cari]) === 'Düzenlenmiş Unvan', "kod={$r['kod']}");
$r = $M->gonder("/cariler/duzenle?id=$cari", '/cariler/duzenle', array_merge($formC, ['risk_limiti' => '999999']));
ok('muhasebe risk limitini değiştiremez (422)', $r['kod'] === 422 && str_contains($r['govde'], 'patron') && Hesap::kurus((string)Db::value('SELECT risk_limiti FROM cariler WHERE id = ?', [$cari])) === 100000, "kod={$r['kod']}");
$r = $M->gonder("/cariler/duzenle?id=$cari", '/cariler/duzenle', array_merge($formC, ['vkn' => '1234567891']));
ok('geçersiz VKN reddedilir (422)', $r['kod'] === 422 && str_contains($r['govde'], 'geçersiz'));
$r = $P->gonder("/cariler/duzenle?id=$cari", '/cariler/duzenle', array_merge($formC, ['risk_limiti' => '2500']));
ok('patron risk limitini değiştirdi', $r['kod'] === 303 && Hesap::kurus((string)Db::value('SELECT risk_limiti FROM cariler WHERE id = ?', [$cari])) === 250000);
ok('sahte CSRF cari düzenlemede 419', $M->istek('POST', '/cariler/duzenle', array_merge($formC, ['_csrf' => 'x']))['kod'] === 419);
$ex = $M->istek('GET', "/cariler/ekstre?id=$cari&bas=2026-01-01&bit=2026-12-31");
ok('ekstre açılır ve fatura/tahsilat satırlarını içerir', $ex['kod'] === 200 && str_contains($ex['govde'], 'Fatura FTR-') && str_contains($ex['govde'], 'Kapanış bakiyesi'));
ok('ekstre geçersiz tarihte çökmez', $M->istek('GET', "/cariler/ekstre?id=$cari&bas=abc&bit=2026-99-99")['kod'] === 200);
$eurC = Db::insert("INSERT INTO cariler (kod, unvan, tip, para_birimi, risk_limitsiz) VALUES ('T3','Euro Müşteri','musteri','EUR',1)");
Db::run("INSERT INTO cari_hareketler (cari_id, tarih, tur, borc, alacak) VALUES (?, '2026-01-01','acilis','100.00',0)", [$eurC]);
$M->gonder("/cariler/goster?id=$eurC", '/kur/elle', ['id' => $eurC, 'kod' => 'EUR', 'kur' => '36,5']);
ok('kur elle kaydedildi', (string)Db::value("SELECT satis FROM kurlar WHERE kod = 'EUR' AND tarih = ?", [date('Y-m-d')]) === '36.5000');
ok('EUR kartında TL karşılığı görünür (3.650,00)', str_contains($M->istek('GET', "/cariler/goster?id=$eurC")['govde'], '3.650,00'));
ok('geçersiz kur reddedilir', $M->gonder("/cariler/goster?id=$eurC", '/kur/elle', ['id' => $eurC, 'kod' => 'EUR', 'kur' => 'abc'])['kod'] === 303 && (string)Db::value("SELECT satis FROM kurlar WHERE kod='EUR' AND tarih = ?", [date('Y-m-d')]) === '36.5000');
ok('satışçı kur giremez (403)', $S->istek('POST', '/kur/elle', ['_csrf' => $S->csrf('/cariler'), 'id' => $eurC, 'kod' => 'EUR', 'kur' => '1'])['kod'] === 403);

// 5d) Asistan (HTTP)
foreach (['P', 'M', 'D', 'S'] as $r) ok("asistan sayfası $r (200)", $ist[$r]->istek('GET', '/asistan')['kod'] === 200);
$sayCari = (int)Db::value('SELECT COUNT(*) FROM cariler'); $sayHar = (int)Db::value('SELECT COUNT(*) FROM cari_hareketler');
$ra = $P->gonder('/asistan', '/asistan', ['soru' => 'Test Müşteri bakiyesi ne kadar?']);
ok('asistan cevap verir ve araç adını gösterir', $ra['kod'] === 303 && str_contains($ra['son']['govde'], 'cari_ara'));
$rb = $P->gonder('/asistan', '/asistan', ['soru' => 'Tüm carileri sil']);
ok('silme isteği reddedilir, veri aynı', str_contains($rb['son']['govde'], 'yalnızca veri okuyabilen') && (int)Db::value('SELECT COUNT(*) FROM cariler') === $sayCari && (int)Db::value('SELECT COUNT(*) FROM cari_hareketler') === $sayHar);
ok('soru işlem kaydına yazıldı', (int)Db::value("SELECT COUNT(*) FROM islem_kaydi WHERE eylem = 'asistan'") >= 2);
$rx = $D->gonder('/asistan', '/asistan', ['soru' => 'Test Müşteri bakiyesi ne kadar?']);
ok('depo cari bilgisini asistandan da alamaz', str_contains($rx['son']['govde'], 'yetkiniz yok') && !str_contains($rx['son']['govde'], '960,00'));
ok('asistan sahte CSRF 419', $P->istek('POST', '/asistan', ['_csrf' => 'x', 'soru' => 'a'])['kod'] === 419);
ok('asistan XSS kaçırılır', !str_contains($P->gonder('/asistan', '/asistan', ['soru' => '<script>alert(1)</script>'])['son']['govde'], '<script>alert(1)</script>'));
$sid2 = (int)Db::value("SELECT id FROM kullanicilar WHERE kullanici_adi = 'satisci'");
$eksik = 20 - (int)Db::value("SELECT COUNT(*) FROM islem_kaydi WHERE eylem = 'asistan' AND kullanici_id = ? AND zaman > (NOW() - INTERVAL 10 MINUTE)", [$sid2]);
for ($i = 0; $i < $eksik; $i++) Db::run("INSERT INTO islem_kaydi (kullanici_id, kullanici_adi, rol, eylem, aciklama) VALUES (?, 'satisci', 'satisci', 'asistan', 'hız sınırı testi')", [$sid2]);
$rl = $S->gonder('/asistan', '/asistan', ['soru' => 'Kritik stok?']);
ok('20 sorudan sonra hız sınırı devreye girer', str_contains($rl['son']['govde'], 'Çok fazla soru'));

// 6) XSS: müşteri unvanı sayfada kaçırılır
Db::run("UPDATE cariler SET unvan = '<img src=x onerror=alert(1)>' WHERE id = ?", [$cari]);
ok('unvanda HTML kaçırılır', !str_contains($P->istek('GET', '/siparisler')['govde'], '<img src=x') && str_contains($P->istek('GET', $sayfa)['govde'], '&lt;img'));
// 7) Parametre saldırıları
ok('SQL enjeksiyonu denemesi 200', $P->istek('GET', '/siparisler?q=' . urlencode("' OR 1=1 --") . '&durum=x&sayfa=-9')['kod'] === 200);
ok('olmayan sipariş 404', $P->istek('GET', '/siparisler/goster?id=99999')['kod'] === 404);
ok('sahte CSRF reddedilir (419)', $P->istek('POST', '/siparisler/onayla', ['_csrf' => 'x', 'id' => $sid])['kod'] === 419);

echo "\nSatış HTTP testleri: $pass geçti, $fail başarısız\n";
exit($fail ? 1 : 0);
