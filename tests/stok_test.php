<?php
declare(strict_types=1);
/**
 * Stok modülü testleri. Yalnızca adı _test ile biten veritabanında çalışır (gerçek veriye dokunmaz).
 *   DB_NAME=demirtas_test php tests/stok_test.php
 */
require dirname(__DIR__) . '/src/autoload.php';

use App\Core\{Config, Db};
use App\Services\{Birim, Stok, StokHatasi};

if (!str_ends_with(Config::env('DB_NAME', 'demirtas'), '_test')) {
    fwrite(STDERR, "Güvenlik: bu test yalnızca DB_NAME=..._test veritabanında çalışır.\n");
    exit(2);
}
$pass = 0; $fail = 0;
function ok(string $ad, bool $k, string $d = ''): void { global $pass, $fail; if ($k) { $pass++; return; } $fail++; echo "BAŞARISIZ: $ad" . ($d !== '' ? " [$d]" : '') . "\n"; }
function hata(string $ad, callable $f, string $parca = ''): void {
    try { $f(); ok($ad, false, 'hata beklendi'); }
    catch (StokHatasi $e) { ok($ad, $parca === '' || str_contains($e->getMessage(), $parca), $e->getMessage()); }
}
function esit(string $ad, mixed $a, mixed $b): void { ok($ad, $a === $b, var_export($a, true) . ' != ' . var_export($b, true)); }
function yeniAnahtar(): string { return bin2hex(random_bytes(16)); }

foreach (['stok_hareketleri', 'urun_birim_cevrim', 'urunler'] as $t) Db::run("DELETE FROM $t");
function urun(string $kod, string $birim, ?string $kritik = null): int {
    return Db::insert("INSERT INTO urunler (stok_kodu, ad, kategori, temel_birim, kdv_orani, kritik_stok) VALUES (?,?,?,?,20,?)", [$kod, "Test $kod", 'Test', $birim, $kritik]);
}
function cevrim(int $u, string $b, int $pay, int $payda): void { Db::run('INSERT INTO urun_birim_cevrim VALUES (?,?,?,?)', [$u, $b, $pay, $payda]); }
function gir(int $u, int $depo, string $miktar, string $tarih = '2026-01-01'): void {
    Db::run("INSERT INTO stok_hareketleri (urun_id, depo_id, tarih, tur, miktar) VALUES (?,?,?,'acilis',?)", [$u, $depo, $tarih, $miktar]);
}
$kul = ['id' => null, 'kullanici_adi' => 'test', 'rol' => 'depo'];

// --- Birim ---
esit('parse 12', Birim::parse('12'), 12000);
esit('parse 12,5', Birim::parse('12,5'), 12500);
esit('parse 0.001', Birim::parse('0.001'), 1);
esit('parse 4 ondalık reddi', Birim::parse('1,2345'), null);
esit('parse harf reddi', Birim::parse('abc'), null);
esit('parse boş reddi', Birim::parse(''), null);
esit('fmt', Birim::fmt(12500), '12.500');
esit('fmt negatif', Birim::fmt(-500), '-0.500');
esit('goster', Birim::goster(12500), '12,5');
esit('goster binlik', Birim::goster(1234000), '1.234');
esit('cevir 36/25', Birim::cevir(10000, 36, 25), 14400);
ok('tamMi', Birim::tamMi(5000) && !Birim::tamMi(5500));

// --- Birim çevrimleri ---
$ton = urun('T-DEMIR', 'kg'); cevrim($ton, 'ton', 1000, 1);
$u = Stok::urun($ton);
esit('2,5 ton → kg', Stok::miktarTemele($u, '2,5', 'ton'), 2500000);
esit('kg temel', Stok::miktarTemele($u, '12,5', 'kg'), 12500);
$sera = urun('T-SERA', 'm2'); cevrim($sera, 'kutu', 36, 25);
esit('10 kutu → 14,4 m2', Stok::miktarTemele(Stok::urun($sera), '10', 'kutu'), 14400);
$cim = urun('T-CIMENTO', 'torba'); cevrim($cim, 'palet', 40, 1);
$uc = Stok::urun($cim);
esit('2 palet → 80 torba', Stok::miktarTemele($uc, '2', 'palet'), 80000);
hata('torba kesirli reddi', fn() => Stok::miktarTemele($uc, '1,5', 'torba'), 'kesirli');
hata('tanımsız birim', fn() => Stok::miktarTemele($uc, '1', 'koli'), 'tanımlı değil');
hata('sıfır miktar', fn() => Stok::miktarTemele($uc, '0', 'torba'), 'büyük');
hata('geçersiz miktar', fn() => Stok::miktarTemele($uc, '1e3', 'torba'), 'geçersiz');
esit('sıfır sayımda serbest', Stok::miktarTemele($uc, '0', 'torba', true), 0);

// --- Seviye / tarih ---
$p = urun('T-PAKET', 'adet', '10');
gir($p, 1, '100', '2026-01-01'); gir($p, 1, '-30', '2026-03-01'); gir($p, 2, '50', '2026-02-01');
esit('merkez seviye', Stok::seviye($p, 1), 70000);
esit('toplam seviye', Stok::seviye($p), 120000);
esit('geçmiş tarih seviyesi', Stok::seviye($p, 1, '2026-02-15'), 100000);
esit('çok eski tarih', Stok::seviye($p, null, '2025-12-31'), 0);

// --- Transfer ---
$k1 = yeniAnahtar();
Stok::transfer($p, 1, 2, 20000, $k1, '2026-04-01', 'test', $kul);
esit('transfer sonrası merkez', Stok::seviye($p, 1), 50000);
esit('transfer sonrası osb', Stok::seviye($p, 2), 70000);
esit('transfer toplamı korunur', Stok::seviye($p), 120000);
esit('iki hareket yazıldı', (int)Db::value("SELECT COUNT(*) FROM stok_hareketleri WHERE kaynak_key LIKE ?", ["islem:$k1:%"]), 2);
hata('çift gönderim', fn() => Stok::transfer($p, 1, 2, 20000, $k1, '2026-04-01', null, $kul), 'zaten kaydedildi');
esit('çift gönderim stoğu değiştirmedi', Stok::seviye($p, 1), 50000);
hata('yetersiz stok', fn() => Stok::transfer($p, 1, 2, 50001, yeniAnahtar(), '2026-04-01', null, $kul), 'yeterli stok');
esit('yetersiz stokta kayıt yok (merkez)', Stok::seviye($p, 1), 50000);
esit('yetersiz stokta kayıt yok (osb)', Stok::seviye($p, 2), 70000);
hata('aynı depo', fn() => Stok::transfer($p, 1, 1, 1000, yeniAnahtar(), '2026-04-01', null, $kul), 'aynı');
hata('eksi miktar', fn() => Stok::transfer($p, 1, 2, -5, yeniAnahtar(), '2026-04-01', null, $kul), 'büyük');
hata('olmayan depo', fn() => Stok::transfer($p, 1, 9, 1000, yeniAnahtar(), '2026-04-01', null, $kul));
esit('olmayan depo sonrası stok aynı', Stok::seviye($p), 120000);
hata('geçersiz anahtar', fn() => Stok::transfer($p, 1, 2, 1000, 'abc', '2026-04-01', null, $kul), 'anahtar');
hata('olmayan ürün', fn() => Stok::transfer(999999, 1, 2, 1000, yeniAnahtar(), '2026-04-01', null, $kul), 'bulunamadı');
Stok::transfer($p, 1, 2, 50000, yeniAnahtar(), '2026-04-02', null, $kul);
esit('tamamını transfer (sıfıra iner)', Stok::seviye($p, 1), 0);

// --- Sayım ---
$s = yeniAnahtar();
$fark = Stok::sayim($p, 2, 100000, $s, '2026-04-03', 'raf sayımı', $kul);
esit('sayım farkı', $fark, 100000 - 120000);
esit('sayım sonrası osb', Stok::seviye($p, 2), 100000);
hata('sayım çift gönderim', fn() => Stok::sayim($p, 2, 1, $s, '2026-04-03', null, $kul), 'zaten kaydedildi');
$n = (int)Db::value('SELECT COUNT(*) FROM stok_hareketleri WHERE urun_id = ?', [$p]);
esit('fark 0 ise kayıt yok', Stok::sayim($p, 2, 100000, yeniAnahtar(), '2026-04-03', null, $kul), 0);
esit('fark 0: hareket sayısı aynı', (int)Db::value('SELECT COUNT(*) FROM stok_hareketleri WHERE urun_id = ?', [$p]), $n);
esit('sıfır sayım', Stok::sayim($p, 2, 0, yeniAnahtar(), '2026-04-04', null, $kul), -100000);
esit('sıfır sayım sonrası', Stok::seviye($p, 2), 0);
hata('eksi sayım', fn() => Stok::sayim($p, 2, -1, yeniAnahtar(), '2026-04-04', null, $kul), 'eksi');

// --- Kritik stok ---
// $p: toplam 0, kritik 10 → kritik; yeni stok girince kritik olmaz
$once = Stok::kritikSayisi();
gir($p, 1, '500', '2026-04-05');
esit('stok artınca kritikten çıkar', Stok::kritikSayisi(), $once - 1);
$q = urun('T-KRITIK', 'adet', '10'); gir($q, 1, '10');
esit('eşit olan kritik sayılır', Stok::kritikSayisi(), $once);
$q2 = urun('T-TANIMSIZ', 'adet', null);
esit('kritik tanımsız → sayılmaz', Stok::kritikSayisi(), $once);

// --- Hareket tablosu toplamı = bakiye (bütünlük) ---
$topl = Db::value("SELECT SUM(miktar) FROM stok_hareketleri WHERE urun_id = ?", [$p]);
esit('hareket toplamı = seviye', Birim::fromDecimal((string)$topl), Stok::seviye($p));

echo "\nStok testleri: $pass geçti, $fail başarısız\n";
exit($fail === 0 ? 0 : 1);
