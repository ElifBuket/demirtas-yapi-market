<?php
declare(strict_types=1);
/** Sipariş→irsaliye→fatura→tahsilat testleri. Yalnızca *_test veritabanında çalışır. */
require dirname(__DIR__) . '/src/autoload.php';
use App\Core\{Config, Db};
use App\Services\{Hesap, IsHatasi, Satis, Stok};

if (!str_ends_with(Config::env('DB_NAME', 'demirtas'), '_test')) { fwrite(STDERR, "Bu test yalnızca *_test veritabanında çalışır.\n"); exit(2); }
$pass = 0; $fail = 0;
function esit(string $ad, mixed $a, mixed $b): void { global $pass, $fail; if ($a === $b) { $pass++; return; } $fail++; echo "BAŞARISIZ: $ad [" . var_export($a, true) . ' != ' . var_export($b, true) . "]\n"; }
function hata(string $ad, callable $f, string $parca = ''): void {
    global $pass, $fail;
    try { $f(); $fail++; echo "BAŞARISIZ: $ad (hata beklendi)\n"; }
    catch (IsHatasi $e) { if ($parca === '' || str_contains($e->getMessage(), $parca)) { $pass++; } else { $fail++; echo "BAŞARISIZ: $ad [mesaj: " . $e->getMessage() . "]\n"; } }
}
function k(): string { return bin2hex(random_bytes(16)); }

foreach (['tahsilat_dagitim', 'tahsilatlar', 'fatura_kalemleri', 'irsaliye_kalemleri', 'irsaliyeler', 'faturalar', 'siparis_kalemleri', 'siparisler', 'belge_sayaclari', 'cari_hareketler', 'stok_hareketleri', 'urun_birim_cevrim', 'urunler', 'cariler'] as $t) Db::run("DELETE FROM $t");
$patron = ['id' => null, 'kullanici_adi' => 'p', 'rol' => 'patron'];
$satisci = ['id' => null, 'kullanici_adi' => 's', 'rol' => 'satisci'];
$bugun = '2026-06-01';

function cari(string $kod, array $o = []): int {
    return Db::insert('INSERT INTO cariler (kod, unvan, tip, risk_limiti, risk_limitsiz, durum, ham_not) VALUES (?,?,?,?,?,?,?)',
        [$kod, "Test $kod", 'musteri', $o['limit'] ?? null, $o['limitsiz'] ?? 0, $o['durum'] ?? 'aktif', $o['not'] ?? null]);
}
function urun(string $kod, int $kdv, string $stok): int {
    $id = Db::insert('INSERT INTO urunler (stok_kodu, ad, kategori, temel_birim, kdv_orani) VALUES (?,?,?,?,?)', [$kod, "Ürün $kod", 'T', 'adet', $kdv]);
    Db::run("INSERT INTO stok_hareketleri (urun_id, depo_id, tarih, tur, miktar) VALUES (?,1,'2026-01-01','acilis',?)", [$id, $stok]);
    return $id;
}
function sat(int $u, int $milli, int $fiyat4, int $iskBp = 0): array { return ['urun_id' => $u, 'miktar' => $milli, 'fiyat4' => $fiyat4, 'iskonto_bp' => $iskBp]; }

$A = urun('A', 20, '100'); $B = urun('B', 10, '100'); $C = urun('C', 1, '5');

// ===== Ana akış (limitsiz cari) =====
$c1 = cari('C1', ['limitsiz' => 1]);
$s = Satis::siparisOlustur($c1, 1, $bugun, [sat($A, 10000, 1000000, 1000), sat($B, 5000, 200000)], 500, k(), $patron);
esit('limitsiz → onaylı', $s['durum'], 'onayli');
$beklenen = Hesap::belge([['miktar' => 10000, 'fiyat4' => 1000000, 'iskonto_bp' => 1000, 'kdv' => 20], ['miktar' => 5000, 'fiyat4' => 200000, 'iskonto_bp' => 0, 'kdv' => 10]], 500);
esit('sipariş toplamı Hesap ile aynı', Hesap::kurus((string)Db::value('SELECT toplam FROM siparisler WHERE id = ?', [$s['id']])), $beklenen['toplam']);
esit('belge no biçimi', $s['belge_no'], 'S-2026-000001');
$kalemler = Db::all('SELECT id, urun_id FROM siparis_kalemleri WHERE siparis_id = ? ORDER BY sira', [$s['id']]);
[$kA, $kB] = [(int)$kalemler[0]['id'], (int)$kalemler[1]['id']];

// Kısmi sevkiyat 1: A'dan 4, B'den 5
$i1 = Satis::irsaliyeKes($s['id'], [$kA => 4000, $kB => 5000], $bugun, k(), $patron);
esit('stok düştü A', Stok::seviye($A, 1), 96000);
esit('stok düştü B', Stok::seviye($B, 1), 95000);
esit('kısmi sevk durumu', Db::value('SELECT durum FROM siparisler WHERE id = ?', [$s['id']]), 'kismi_sevk');
esit('irsaliye cariyi borçlandırmaz', Satis::bakiye($c1), 0);
hata('kalandan fazla sevk', fn() => Satis::irsaliyeKes($s['id'], [$kA => 7000], $bugun, k(), $patron), 'kalan');
esit('hatalı sevkte stok değişmedi', Stok::seviye($A, 1), 96000);
esit('hatalı sevkte irsaliye yazılmadı', (int)Db::value('SELECT COUNT(*) FROM irsaliyeler'), 1);

// Fatura 1 (yalnızca irsaliye 1)
$f1 = Satis::faturaKes([$i1['id']], 30, $bugun, k(), $patron);
$b1 = Hesap::belge([['miktar' => 4000, 'fiyat4' => 1000000, 'iskonto_bp' => 1000, 'kdv' => 20], ['miktar' => 5000, 'fiyat4' => 200000, 'iskonto_bp' => 0, 'kdv' => 10]], 500);
esit('fatura toplamı Hesap ile aynı', $f1['toplam'], $b1['toplam']);
esit('cari borçlandı', Satis::bakiye($c1), $b1['toplam']);
esit('vade tarihi', Db::value('SELECT vade_tarihi FROM faturalar WHERE id = ?', [$f1['id']]), '2026-07-01');
esit('fatura matrah+kdv = toplam', Hesap::kurus((string)Db::value('SELECT matrah + kdv FROM faturalar WHERE id = ?', [$f1['id']])), $f1['toplam']);
esit('fatura satır matrah toplamı = matrah', Hesap::kurus((string)Db::value('SELECT SUM(matrah) FROM fatura_kalemleri WHERE fatura_id = ?', [$f1['id']])), Hesap::kurus((string)Db::value('SELECT matrah FROM faturalar WHERE id = ?', [$f1['id']])));
hata('aynı irsaliye iki kez faturalanamaz', fn() => Satis::faturaKes([$i1['id']], 30, $bugun, k(), $patron), 'zaten faturalanmış');
esit('ETTN UUID biçimi', (bool)preg_match('/^[0-9A-F]{8}-[0-9A-F]{4}-4[0-9A-F]{3}-[89AB][0-9A-F]{3}-[0-9A-F]{12}$/', (string)Db::value('SELECT ettn FROM faturalar WHERE id = ?', [$f1['id']])), true);

// Sevkiyat 2: kalan A (6)
$i2 = Satis::irsaliyeKes($s['id'], [$kA => 6000], $bugun, k(), $patron);
esit('tam sevk durumu', Db::value('SELECT durum FROM siparisler WHERE id = ?', [$s['id']]), 'sevk_edildi');
hata('tamamlanmış siparişe tekrar sevk', fn() => Satis::irsaliyeKes($s['id'], [$kA => 1000], $bugun, k(), $patron), 'sevk edilemez');
$f2 = Satis::faturaKes([$i2['id']], 0, $bugun, k(), $patron);
esit('tüm irsaliyeler faturalı → faturalandı', Db::value('SELECT durum FROM siparisler WHERE id = ?', [$s['id']]), 'faturalandi');
esit('bakiye = iki fatura', Satis::bakiye($c1), $f1['toplam'] + $f2['toplam']);

// Tahsilat: en eski VADELİ faturadan başlar (f2 vadesi bugün, f1 vadesi 30 gün sonra → f2 önce)
$t1 = Satis::tahsilatGir($c1, 10000, 'nakit', $bugun, null, null, null, k(), $patron);
esit('mahsup tamamı', $t1['mahsup'], 10000);
esit('f2 kalan azaldı (vadesi önce)', Satis::faturaKalan($f2['id']), $f2['toplam'] - 10000);
esit('f1 dokunulmadı', Satis::faturaKalan($f1['id']), $f1['toplam']);
esit('bakiye azaldı', Satis::bakiye($c1), $f1['toplam'] + $f2['toplam'] - 10000);
// Fazla ödeme → avans
$kalanToplam = Satis::bakiye($c1);
$t2 = Satis::tahsilatGir($c1, $kalanToplam + 5000, 'havale', $bugun, null, null, null, k(), $patron);
esit('fazla ödemede avans', $t2['avans'], 5000);
esit('bakiye -avans', Satis::bakiye($c1), -5000);
esit('faturalar kapandı', Satis::faturaKalan($f1['id']) + Satis::faturaKalan($f2['id']), 0);

// ===== Çift gönderim =====
$ka = k();
$c2 = cari('C2', ['limitsiz' => 1]);
$sa = Satis::siparisOlustur($c2, 1, $bugun, [sat($A, 1000, 100000)], 0, $ka, $patron);
hata('sipariş çift gönderim', fn() => Satis::siparisOlustur($c2, 1, $bugun, [sat($A, 1000, 100000)], 0, $ka, $patron), 'zaten');
esit('tek sipariş', (int)Db::value('SELECT COUNT(*) FROM siparisler WHERE cari_id = ?', [$c2]), 1);
$kk = (int)Db::value('SELECT id FROM siparis_kalemleri WHERE siparis_id = ?', [$sa['id']]);
$ki = k(); Satis::irsaliyeKes($sa['id'], [$kk => 500], $bugun, $ki, $patron);
hata('irsaliye çift gönderim', fn() => Satis::irsaliyeKes($sa['id'], [$kk => 500], $bugun, $ki, $patron), 'zaten');
esit('stok bir kez düştü', Stok::seviye($A, 1), 89500);

// ===== Risk limiti & engelli =====
$ce = cari('CE', ['durum' => 'engelli', 'not' => 'KAPANDI']);
hata('engelli cariye sipariş', fn() => Satis::siparisOlustur($ce, 1, $bugun, [sat($A, 1000, 100000)], 0, k(), $patron), 'engelli');
$cl = cari('CL', ['limit' => '1000.00']);
$ok = Satis::siparisOlustur($cl, 1, $bugun, [sat($A, 1000, 4000000)], 0, k(), $patron); // 400 TL + %20 = 480 TL
esit('limit altı → onaylı', $ok['durum'], 'onayli');
$as = Satis::siparisOlustur($cl, 1, $bugun, [sat($A, 1000, 4000000)], 0, k(), $patron); // açık sipariş dahil 960 → hâlâ altında
esit('açık sipariş dahil ama limit altı', $as['durum'], 'onayli');
$asr = Satis::siparisOlustur($cl, 1, $bugun, [sat($A, 1000, 4000000)], 0, k(), $patron); // 1440 > 1000
esit('açık sipariş + yeni > limit → onay bekliyor', $asr['durum'], 'onay_bekliyor');
esit('risk notu dolu', str_contains((string)$asr['risk_notu'], 'aşılıyor'), true);
$kr = (int)Db::value('SELECT id FROM siparis_kalemleri WHERE siparis_id = ?', [$asr['id']]);
hata('onaysız sevk', fn() => Satis::irsaliyeKes($asr['id'], [$kr => 1000], $bugun, k(), $patron), 'onayı bekliyor');
hata('satışçı onaylayamaz', fn() => Satis::siparisOnayla($asr['id'], $satisci), 'patron');
Satis::siparisOnayla($asr['id'], $patron);
esit('patron onayladı', Db::value('SELECT durum FROM siparisler WHERE id = ?', [$asr['id']]), 'onayli');
hata('onaylı siparisi tekrar onayla', fn() => Satis::siparisOnayla($asr['id'], $patron), 'beklemiyor');
Satis::irsaliyeKes($asr['id'], [$kr => 1000], $bugun, k(), $patron);
$cn = cari('CN'); // limit tanımsız
esit('limit tanımsız → onay', Satis::siparisOlustur($cn, 1, $bugun, [sat($A, 1000, 100000)], 0, k(), $patron)['durum'], 'onay_bekliyor');
$sIpt = Satis::siparisOlustur($cn, 1, $bugun, [sat($A, 1000, 100000)], 0, k(), $patron);
Satis::siparisIptal($sIpt['id'], $patron);
esit('iptal', Db::value('SELECT durum FROM siparisler WHERE id = ?', [$sIpt['id']]), 'iptal');

// ===== Yetersiz stok → hiçbir şey yazılmaz =====
$cs = cari('CS', ['limitsiz' => 1]);
$ss = Satis::siparisOlustur($cs, 1, $bugun, [sat($C, 5000, 100000), sat($A, 1000, 100000)], 0, k(), $patron);
$ks = Db::all('SELECT id FROM siparis_kalemleri WHERE siparis_id = ? ORDER BY sira', [$ss['id']]);
Db::run("INSERT INTO stok_hareketleri (urun_id, depo_id, tarih, tur, miktar) VALUES (?,1,'2026-06-01','duzeltme',-3)", [$C]); // C stoğu 2'ye düştü
$onceA = Stok::seviye($A, 1); $onceIrs = (int)Db::value('SELECT COUNT(*) FROM irsaliyeler');
hata('yetersiz stok', fn() => Satis::irsaliyeKes($ss['id'], [(int)$ks[1]['id'] => 1000, (int)$ks[0]['id'] => 5000], $bugun, k(), $patron), 'yeterli stok');
esit('yetersiz stokta A da düşmedi (geri alındı)', Stok::seviye($A, 1), $onceA);
esit('yetersiz stokta irsaliye yok', (int)Db::value('SELECT COUNT(*) FROM irsaliyeler'), $onceIrs);
esit('yetersiz stokta sipariş durumu aynı', Db::value('SELECT durum FROM siparisler WHERE id = ?', [$ss['id']]), 'onayli');

// ===== Doğrulamalar =====
hata('boş sipariş', fn() => Satis::siparisOlustur($c1, 1, $bugun, [], 0, k(), $patron), 'en az bir');
hata('aynı ürün iki satır', fn() => Satis::siparisOlustur($c1, 1, $bugun, [sat($A, 1000, 100), sat($A, 1000, 100)], 0, k(), $patron), 'birden fazla');
hata('geçersiz tarih', fn() => Satis::siparisOlustur($c1, 1, '2026-02-30', [sat($A, 1000, 100)], 0, k(), $patron), 'Tarih');
hata('geçersiz anahtar', fn() => Satis::siparisOlustur($c1, 1, $bugun, [sat($A, 1000, 100)], 0, 'x', $patron), 'anahtar');
hata('olmayan müşteri', fn() => Satis::siparisOlustur(999999, 1, $bugun, [sat($A, 1000, 100)], 0, k(), $patron), 'bulunamadı');
hata('negatif tahsilat', fn() => Satis::tahsilatGir($c1, -5, 'nakit', $bugun, null, null, null, k(), $patron), 'sıfırdan');
hata('çek vadesiz', fn() => Satis::tahsilatGir($c1, 100, 'cek', $bugun, null, 'X1', null, k(), $patron), 'vade');
hata('çek numarasız', fn() => Satis::tahsilatGir($c1, 100, 'cek', $bugun, '2026-09-01', '', null, k(), $patron), 'numarası');
hata('vade geçmişte', fn() => Satis::tahsilatGir($c1, 100, 'cek', $bugun, '2026-05-01', 'X', null, k(), $patron), 'önce');
hata('geçersiz yöntem', fn() => Satis::tahsilatGir($c1, 100, 'bitcoin', $bugun, null, null, null, k(), $patron), 'yöntem');
$tk = k(); Satis::tahsilatGir($c1, 100, 'nakit', $bugun, null, null, null, $tk, $patron);
hata('tahsilat çift gönderim', fn() => Satis::tahsilatGir($c1, 100, 'nakit', $bugun, null, null, null, $tk, $patron), 'zaten');

// ===== Çek: karşılıksız =====
$cc = cari('CC', ['limitsiz' => 1]);
$sc = Satis::siparisOlustur($cc, 1, $bugun, [sat($B, 1000, 1000000)], 0, k(), $patron);
$kc = (int)Db::value('SELECT id FROM siparis_kalemleri WHERE siparis_id = ?', [$sc['id']]);
$ic = Satis::irsaliyeKes($sc['id'], [$kc => 1000], $bugun, k(), $patron);
$fc = Satis::faturaKes([$ic['id']], 15, $bugun, k(), $patron);
esit('fatura 110,00 (100 + %10)', $fc['toplam'], 11000);
$tc = Satis::tahsilatGir($cc, 11000, 'cek', $bugun, '2026-08-01', 'CK-1', null, k(), $patron);
esit('çek ile fatura kapandı', Satis::faturaKalan($fc['id']), 0);
esit('çek ile bakiye 0', Satis::bakiye($cc), 0);
esit('çek portföyde', Db::value('SELECT durum FROM tahsilatlar WHERE id = ?', [$tc['id']]), 'portfoyde');
Satis::cekKarsiliksiz($tc['id'], '2026-08-02', $patron);
esit('karşılıksız → cari yeniden borçlu', Satis::bakiye($cc), 11000);
esit('karşılıksız → fatura yeniden açık', Satis::faturaKalan($fc['id']), 11000);
esit('kayıtlar silinmedi (dağıtım 2 satır)', (int)Db::value('SELECT COUNT(*) FROM tahsilat_dagitim WHERE tahsilat_id = ?', [$tc['id']]), 2);
hata('karşılıksız tekrar', fn() => Satis::cekKarsiliksiz($tc['id'], '2026-08-03', $patron), 'portföydeki');
$tc2 = Satis::tahsilatGir($cc, 11000, 'cek', $bugun, '2026-09-01', 'CK-2', null, k(), $patron);
Satis::cekTahsilEdildi($tc2['id'], $patron);
esit('çek tahsil edildi', Db::value('SELECT durum FROM tahsilatlar WHERE id = ?', [$tc2['id']]), 'tahsil_edildi');
hata('tahsil edilmiş çek karşılıksız yapılamaz', fn() => Satis::cekKarsiliksiz($tc2['id'], '2026-09-02', $patron), 'portföydeki');

// ===== Genel tutarlılık: bakiye = Σfatura − Σtahsilat(+karşılıksız) her cari için =====
$kotu = 0;
foreach (Db::all('SELECT id FROM cariler') as $r) {
    $cid = (int)$r['id'];
    $f = Hesap::kurus((string)Db::value('SELECT COALESCE(SUM(toplam),0) FROM faturalar WHERE cari_id = ?', [$cid]));
    $t = Hesap::kurus((string)Db::value("SELECT COALESCE(SUM(tutar),0) FROM tahsilatlar WHERE cari_id = ? AND durum <> 'karsiliksiz'", [$cid]));
    if (Satis::bakiye($cid) !== $f - $t) $kotu++;
}
esit('tüm carilerde bakiye = faturalar − geçerli tahsilatlar', $kotu, 0);
esit('hiçbir fatura fazla mahsup edilmedi', (int)Db::value('SELECT COUNT(*) FROM faturalar f WHERE (SELECT COALESCE(SUM(tutar),0) FROM tahsilat_dagitim d WHERE d.fatura_id = f.id) > f.toplam'), 0);
esit('hiçbir stok eksiye düşmedi', (int)Db::value('SELECT COUNT(*) FROM (SELECT urun_id, depo_id FROM stok_hareketleri GROUP BY urun_id, depo_id HAVING SUM(miktar) < 0) t'), 0);
$nos = Db::all('SELECT belge_no FROM siparisler ORDER BY id'); $dizi = array_map(fn($r) => (int)substr($r['belge_no'], -6), $nos);
esit('belge numaraları boşluksuz', $dizi, range(1, count($dizi)));

echo "\nSatış testleri: $pass geçti, $fail başarısız\n";
exit($fail ? 1 : 0);
