<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/Norm.php';

$pass = 0; $fail = 0;
function check(string $name, mixed $got, mixed $want): void {
    global $pass, $fail;
    if ($got === $want) { $pass++; return; }
    $fail++;
    echo "BAŞARISIZ: $name\n  beklenen: " . json_encode($want, JSON_UNESCAPED_UNICODE) . "\n  gelen:    " . json_encode($got, JSON_UNESCAPED_UNICODE) . "\n";
}
$m = fn($v) => Norm::parseMoney($v)['kurus'];

// --- para biçimleri (gerçek Excel örnekleri) ---
check('TR biçim',               $m('16.650,41'), 1665041);
check('TL eki',                 $m('26.400 TL'), 2640000);
check('₺ öneki',                $m('₺49.745,87'), 4974587);
check('nokta ondalık metin',    $m('115101.47'), 11510147);
check('sayı hücre',             $m(new Num('117530.39')), 11753039);
check('tam sayı hücre',         $m(new Num('218500')), 21850000);
check('9.999 binlik',           $m('9.999'), 999900);
check('999.999 binlik',         $m('999.999'), 99999900);
check('9.999.99',               $m('9.999.99'), 999999);
check('99,999,99',              $m('99,999,99'), 9999999);
check('eksi',                   $m('-18.390,45'), -1839045);
check('tire = boş',             $m('-'), null);
check('soru işareti',           Norm::parseMoney('?')['durum'], 'gecersiz');
check('yaklaşık',               Norm::parseMoney('yaklaşık 15 bin')['durum'], 'gecersiz');
check('parantezli not',         $m('4.500 (ödeme sözü var)'), 450000);
check('parantezli not metni',   Norm::parseMoney('4.500 (ödeme sözü var)')['not'], 'ödeme sözü var');
check('float hatası yok',       $m(new Num('1.1')), 110);
check('yarım yukarı',           Norm::decToKurus('10.005'), 1001);
check('kuruş biçimi',           Norm::kurusToStr(-5), '-0.05');

// --- risk limiti ---
check('limitsiz',               Norm::parseRisk('limitsiz')['limitsiz'], true);
check('25 bin',                 Norm::parseRisk('25 bin')['kurus'], 2500000);
check('75.000 TL',              Norm::parseRisk('75.000 TL')['kurus'], 7500000);
check('limit boş',              Norm::parseRisk(null)['kurus'], null);
check('limit sayı',             Norm::parseRisk(new Num('30000'))['kurus'], 3000000);

// --- KDV ---
check('KDV %20',                Norm::kdv('%20')['oran'], 20);
check('KDV 0,20',               Norm::kdv('0,20')['oran'], 20);
check('KDV 0.2 sayı',           Norm::kdv(new Num('0.2'))['oran'], 20);
check('KDV 20 sayı',            Norm::kdv(new Num('20'))['durum'], 'ok');
check('KDV %1',                 Norm::kdv('%1')['oran'], 1);
check('KDV 10',                 Norm::kdv('10')['oran'], 10);
check('KDV %18 -> 20 + uyarı',  [Norm::kdv('%18')['oran'], Norm::kdv('%18')['durum']], [20, 'duzeltildi']);
check('KDV dahil 33,44 (%20)',  Norm::kdvDahilToHaric(3344, 20), '27.8667');
check('KDV dahil 118 (%18)',    Norm::kdvDahilToHaric(11800, 18), '100.0000');
check('KDV dahil 110 (%10)',    Norm::kdvDahilToHaric(11000, 10), '100.0000');
check('fiyat kdv dahil ayrıştır', [Norm::price('33,44 KDV dahil')['kurus'], Norm::price('33,44 KDV dahil')['kdv_dahil']], [3344, true]);

// --- VKN / TCKN ---
check('geçerli VKN',            Norm::vknOk('7279206511'), true);
check('geçersiz VKN',           Norm::vknOk('7279206510'), false);
$sifirli = null; for ($n = 1; $n < 999999999; $n++) { $p = str_pad((string)$n, 10, '0', STR_PAD_LEFT); if (Norm::vknOk($p)) { $sifirli = $p; break; } }
check('sıfır kaybı onarımı', [Norm::vkn((string)(int)$sifirli)['vkn'], Norm::vkn((string)(int)$sifirli)['durum']], [$sifirli, 'duzeltildi']);
check('VKN boş',                Norm::vkn(null)['durum'], 'bos');
check('TCKN algoritması',       Norm::tcknOk('10000000146'), true);
check('TCKN yanlış',            Norm::tcknOk('10000000147'), false);

// --- telefon / e-posta / tarih ---
check('tel 0543-078-73-41',     Norm::phone('0543-078-73-41')['tel'], '05430787341');
check('tel 544 899 9866',       Norm::phone('544 899 9866')['tel'], '05448999866');
check('tel (0346) 223 82 43',   Norm::phone('(0346) 223 82 43')['tel'], '03462238243');
check('tel 90 önekli',          Norm::phone('905339731796')['tel'], '05339731796');
check('tel geçersiz',           Norm::phone('123')['tel'], null);
check('çift @',                 Norm::email('muhasebe@@yapi.com')['eposta'], 'muhasebe@yapi.com');
check('e-posta geçersiz',       Norm::email('abc')['eposta'], null);
check('tarih dd.mm.yyyy',       Norm::date('05.10.2025')['tarih'], '2025-10-05');
check('tarih dd/mm/yy',         Norm::date('17/10/25')['tarih'], '2025-10-17');
check('tarih d.m.yyyy',         Norm::date('5.3.2025')['tarih'], '2025-03-05');
check('gelecek tarih atlanır',  Norm::date('14.02.2027')['tarih'], null);
check('tarih nesnesi',          Norm::date(new DateTimeImmutable('2025-08-17'))['tarih'], '2025-08-17');

// --- cari alanları ---
check('tip M',                  Norm::tip('M'), 'musteri');
check('tip MÜŞTERİ',            Norm::tip('MÜŞTERİ'), 'musteri');
check('tip tedarikci',          Norm::tip('tedarikci'), 'tedarikci');
check('tip T',                  Norm::tip('T'), 'tedarikci');
check('B/A borçlu',             Norm::borcAlacak('borçlu'), 'B');
check('B/A alacaklı',           Norm::borcAlacak('alacaklı'), 'A');
check('B/A tire',               Norm::borcAlacak('-'), null);
check('döviz €',                Norm::currency('€'), 'EUR');
check('döviz Euro',             Norm::currency('Euro'), 'EUR');
check('döviz ₺',                Norm::currency('₺'), 'TRY');
check('döviz boş',              Norm::currency(null), 'TRY');
check('il Sıvas',               Norm::city('Sıvas'), 'Sivas');
check('il KAYSERI',             Norm::city('KAYSERI'), 'Kayseri');
check('satışçı E. Doğan',       Norm::satisci('E. Doğan')['ad'], 'Emre Doğan');
check('satışçı MEHMET',         Norm::satisci('MEHMET')['ad'], 'Mehmet Kaya');
check('satışçı Hakan ayrıldı',  Norm::satisci('Hakan Bey (ayrıldı)'), ['ad' => null, 'ayrildi' => true]);
check('title Türkçe',           Norm::title('SİVAS MERKEZ'), 'Sivas Merkez');

// --- birim ve çevrim ---
check('birim adt',              Norm::unit('adt'), 'adet');
check('birim m²',               Norm::unit('m²'), 'm2');
check('birim kilo',             Norm::unit('kilo'), 'kg');
check('çevrim torba/kg',        Norm::conversion('1 torba = 50 kg', 'torba'), ['alt' => 'kg', 'pay' => 1, 'payda' => 50]);
check('çevrim ton/kg',          Norm::conversion('1 ton = 1000 kg', 'kg'), ['alt' => 'ton', 'pay' => 1000, 'payda' => 1]);
check('çevrim kutu/m2',         Norm::conversion('1 kutu = 1,44 m²', 'm2'), ['alt' => 'kutu', 'pay' => 36, 'payda' => 25]);
check('çevrim kutu/adet',       Norm::conversion('1 kutu = 200 adet', 'kutu'), ['alt' => 'adet', 'pay' => 1, 'payda' => 200]);
check('çevrim boy/m',           Norm::conversion('1 boy = 4 m', 'm'), ['alt' => 'boy', 'pay' => 4, 'payda' => 1]);
check('çevrim 35 kg',           Norm::conversion('1 torba = 35 kg', 'torba'), ['alt' => 'kg', 'pay' => 1, 'payda' => 35]);
check('çevrim bozuk',           Norm::conversion('torba', 'torba'), null);
check('çevrim eşleşmeyen',      Norm::conversion('1 rulo = 25 kg', 'adet'), null);

// --- stok ---
check('stok 230',               Norm::stock(new Num('230'), 'adet')['miktar'], '230.000');
check('stok yok',               Norm::stock('yok', 'adet')['miktar'], '0.000');
check('stok sayılacak',         Norm::stock('sayılacak', 'adet')['durum'], 'duzeltildi');
check('stok eksi',              Norm::stock(new Num('-73'), 'adet')['miktar'], '0.000');
check('stok ondalık adet',      Norm::stock('12,5', 'adet')['miktar'], '0.000');
check('stok ondalık kg ok',     Norm::stock('12,5', 'kg')['miktar'], '12.500');
check('stok boş',               Norm::stock(null, 'adet')['durum'], 'bos');

// --- barkod / kategori ---
check('barkod geçerli',         Norm::barcode('8693060641898')['barkod'], '8693060641898');
check('barkod bilimsel',        Norm::barcode('8,60E+12')['barkod'], null);
check('kategori ÇIMENTO',       Norm::category('ÇIMENTO'), 'Çimento');
check('kategori i̇nşaat demiri', Norm::category("i\u{0307}nşaat demiri"), 'İnşaat Demiri');
check('kategori HARÇ',          Norm::category('HARÇ VE YAPIŞTIRICI'), 'Harç ve Yapıştırıcı');

echo "\n$pass geçti, $fail başarısız\n";
exit($fail > 0 ? 1 : 0);
