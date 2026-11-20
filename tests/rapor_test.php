<?php
declare(strict_types=1);
/** Yönetim paneli hesap testleri (*_test veritabanı). */
require dirname(__DIR__) . '/src/autoload.php';
use App\Core\{Config, Db};
use App\Services\{Rapor, Satis};

if (!str_ends_with(Config::env('DB_NAME', 'demirtas'), '_test')) { fwrite(STDERR, "Yalnızca *_test.\n"); exit(2); }
$pass = 0; $fail = 0;
function esit(string $ad, mixed $a, mixed $b): void { global $pass, $fail; if ($a === $b) { $pass++; return; } $fail++; echo "BAŞARISIZ: $ad [" . var_export($a, true) . ' != ' . var_export($b, true) . "]\n"; }
foreach (['tahsilat_dagitim', 'tahsilatlar', 'fatura_kalemleri', 'irsaliye_kalemleri', 'irsaliyeler', 'faturalar', 'siparis_kalemleri', 'siparisler', 'belge_sayaclari', 'cari_hareketler', 'stok_hareketleri', 'urun_birim_cevrim', 'urunler', 'cariler'] as $t) Db::run("DELETE FROM $t");
$bugun = '2026-06-15';
$c1 = Db::insert("INSERT INTO cariler (kod, unvan, tip, risk_limitsiz) VALUES ('R1','Müşteri 1','musteri',1)");
$c2 = Db::insert("INSERT INTO cariler (kod, unvan, tip, risk_limitsiz) VALUES ('R2','Müşteri 2','musteri',1)");
$ce = Db::insert("INSERT INTO cariler (kod, unvan, tip, para_birimi) VALUES ('RE','Euro Müşteri','musteri','EUR')");
$ct = Db::insert("INSERT INTO cariler (kod, unvan, tip) VALUES ('RT','Tedarikçi','tedarikci')");
$u = Db::insert("INSERT INTO urunler (stok_kodu, ad, kategori, temel_birim, kdv_orani, alis_fiyati, kritik_stok) VALUES ('R-U','U','T','adet',20,'10.0000','5')");
Db::run("INSERT INTO stok_hareketleri (urun_id, depo_id, tarih, tur, miktar) VALUES (?,1,'2026-01-01','acilis',100)", [$u]);
// Devir bakiyeleri
Db::run("INSERT INTO cari_hareketler (cari_id, tarih, tur, borc, alacak) VALUES (?, '2025-12-31','acilis',500,0)", [$c1]);
Db::run("INSERT INTO cari_hareketler (cari_id, tarih, tur, borc, alacak) VALUES (?, '2025-12-31','acilis',200,0)", [$ce]);
Db::run("INSERT INTO cari_hareketler (cari_id, tarih, tur, borc, alacak) VALUES (?, '2025-12-31','acilis',0,300)", [$ct]);
$kul = ['id' => null, 'rol' => 'patron'];
function fatura(int $cari, string $tarih, int $vadeGun, int $milli, int $f4): array {
    global $kul, $u;
    $k = bin2hex(random_bytes(16));
    $s = Satis::siparisOlustur($cari, 1, $tarih, [['urun_id' => $u, 'miktar' => $milli, 'fiyat4' => $f4, 'iskonto_bp' => 0]], 0, $k, $kul);
    $kid = (int)Db::value('SELECT id FROM siparis_kalemleri WHERE siparis_id = ?', [$s['id']]);
    $i = Satis::irsaliyeKes($s['id'], [$kid => $milli], $tarih, bin2hex(random_bytes(16)), $kul);
    return Satis::faturaKes([$i['id']], $vadeGun, $tarih, bin2hex(random_bytes(16)), $kul);
}
// Faturalar: 1 adet x 100 TL + %20 = 120 TL
$fBugun = fatura($c1, $bugun, 30, 1000, 1000000);          // vade 15.07 → gelmemiş
$fGec20 = fatura($c1, '2026-05-01', 25, 1000, 1000000);    // vade 26.05 → 20 gün gecikmiş
$fGec45 = fatura($c2, '2026-04-01', 30, 1000, 1000000);    // vade 01.05 → 45 gün
$fGec120 = fatura($c2, '2026-01-10', 10, 1000, 1000000);   // vade 20.01 → 146 gün
$fYakin = fatura($c2, $bugun, 5, 2000, 1000000);           // 240 TL, vade 20.06
// Çek: c2 fatura Gec120'yi (120 TL) çekle öde; vade 25.06
Satis::tahsilatGir($c2, 12000, 'cek', $bugun, '2026-06-25', 'CK', null, bin2hex(random_bytes(16)), $kul);
// (FIFO: en eski vade → Gec120 kapanır)

$o = Rapor::ozet($bugun);
esit('günlük satış = bugünkü 2 fatura (120 + 240)', $o['gunluk_satis'], 36000);
esit('aylık satış (haziran)', $o['aylik_satis'], 36000);
// c1: 500 devir + 120 + 120 = 740 TL ; c2: 120 + 120 + 240 − 120 = 360 TL → 1100 TL = 110000 kuruş
esit('TRY alacak toplamı', $o['alacak'], 110000);
esit('EUR alacak ayrı', $o['alacak_eur'], 20000);
esit('tedarikçi borcu', $o['borc'], 30000);
esit('portföy çek', $o['portfoy_cek'], 12000);
$y = Rapor::yaslandirma($bugun);
esit('vadesi gelmemiş (120 + 240)', $y['vadesi_gelmemis'], 36000);
esit('0-30 gün gecikmiş', $y['g0_30'], 12000);
esit('31-60 gün', $y['g31_60'], 12000);
esit('61-90 gün', $y['g61_90'], 0);
esit('90+ gün (çekle kapandı → 0)', $y['g90_ustu'], 0);
esit('fatura toplamı = kovaların toplamı', $y['fatura_toplam'], 36000 + 12000 + 12000);
esit('en borçlu 1.', (int)Rapor::enBorclular(1)[0]['id'], $c1);
esit('en borçlu tutarı', Rapor::enBorclular(1)[0]['bakiye'], '740.00');
esit('en çok alan', (int)Rapor::enCokAlanlar($bugun, 30, 1)[0]['id'], $c2);
$n = Rapor::nakitAkisi($bugun, 30);
esit('30 gün', count($n['gunler']), 30);
esit('gecikmiş açık faturalar', $n['gecikmis'], 24000);
$gun = array_column($n['gunler'], null, 'tarih');
esit('20.06 vade: 240 TL fatura', $gun['2026-06-20']['fatura'], 24000);
esit('25.06 vade: 120 TL çek', $gun['2026-06-25']['cek'], 12000);
esit('15.07 vade 30 gün ufkunun dışında → ufuk_disi', $n['ufuk_disi'], 12000);
esit('toplam giriş = 240 + 120', $n['toplam_giris'], 36000);
esit('gecikmiş çek yok', count(Rapor::gecikmisCekler($bugun)), 0);
esit('ileri tarihte gecikmiş çek var', count(Rapor::gecikmisCekler('2026-07-01')), 1);
// Tutarlılık: açık fatura kalanı = gecikmiş + ufuk içi fatura + ufuk dışı
$fatGiris = array_sum(array_column($n['gunler'], 'fatura'));
esit('faturalar: gecikmiş + 30 gün + sonrası = yaşlandırma toplamı', $n['gecikmis'] + $fatGiris + $n['ufuk_disi'], $y['fatura_toplam']);
esit('stok değeri: 100 - 6 satılan = 94 adet x 10 TL', $o['stok_degeri'], 94 * 1000);

echo "\nRapor testleri: $pass geçti, $fail başarısız\n";
exit($fail ? 1 : 0);
