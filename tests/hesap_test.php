<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/autoload.php';
use App\Services\Hesap;

$pass = 0; $fail = 0;
function esit(string $ad, mixed $a, mixed $b): void { global $pass, $fail; if ($a === $b) { $pass++; return; } $fail++; echo "BAŞARISIZ: $ad  [" . var_export($a, true) . ' != ' . var_export($b, true) . "]\n"; }
function s(int $q, int $f, int $i, int $k): array { return ['miktar' => $q, 'fiyat4' => $f, 'iskonto_bp' => $i, 'kdv' => $k]; }

esit('yuvarlama .5 yukarı', Hesap::bolRound(5, 10), 1);
esit('yuvarlama .49 aşağı', Hesap::bolRound(49, 100), 0);
esit('fiyat parse', Hesap::fiyatParse('12,5'), 125000);
esit('fiyat parse 4 hane', Hesap::fiyatParse('0,0001'), 1);
esit('fiyat 5 hane reddi', Hesap::fiyatParse('1,23456'), null);
esit('fiyat negatif reddi', Hesap::fiyatParse('-1'), null);
esit('iskonto 12,5', Hesap::iskontoParse('12,5'), 1250);
esit('iskonto 100', Hesap::iskontoParse('100'), 10000);
esit('iskonto 100,01 reddi', Hesap::iskontoParse('100,01'), null);
esit('iskonto boş = 0', Hesap::iskontoParse(''), 0);
esit('dec', Hesap::dec(123450), '1234.50');
esit('dec küçük', Hesap::dec(5), '0.05');
esit('dec negatif', Hesap::dec(-105), '-1.05');
esit('kurus', Hesap::kurus('1234.50'), 123450);
esit('kurus 4 ondalıklı DB', Hesap::kurus('12.3400'), 1234);
esit('goster', Hesap::goster(123456789), '1.234.567,89');
esit('goster negatif', Hesap::goster(-5), '-0,05');

// Basit: 3 adet x 33,33 TL, %20 KDV
$b = Hesap::belge([s(3000, 333300, 0, 20)]);
esit('brüt 99,99', $b['ara_toplam'], 9999);
esit('kdv 20,00 (19,998 → 20,00)', $b['kdv'], 2000);
esit('toplam 119,99', $b['toplam'], 11999);

// Satır iskontosu: 10 x 10,00 %15 → 85,00
esit('satır iskonto', Hesap::satir(10000, 100000, 1500)['net'], 8500);
// Kesirli miktar: 12,345 kg x 7,89 TL = 97,40205 → 97,40
esit('kesirli miktar', Hesap::satir(12345, 78900, 0)['brut'], 9740);
// Tam .5 kuruş: 1 adet x 0,005 TL → 0,5 kuruş → 1 kuruş (half-up)
esit('yarım kuruş yukarı', Hesap::satir(1000, 50, 0)['brut'], 1);

// Karışık KDV + genel iskonto, toplam her zaman tutarlı
$b = Hesap::belge([s(1000, 1000000, 0, 1), s(1000, 1000000, 0, 10), s(1000, 1000000, 0, 20)], 1000);
esit('ara toplam', $b['ara_toplam'], 30000);
esit('genel iskonto %10', $b['genel_iskonto'], 3000);
esit('genel iskonto dağıtıldı', array_sum(array_column($b['satirlar'], 'genel_iskonto')), 3000);
esit('matrah', $b['matrah'], 27000);
esit('kdv grupları', $b['kdv_gruplari'], [1 => ['matrah' => 9000, 'kdv' => 90], 10 => ['matrah' => 9000, 'kdv' => 900], 20 => ['matrah' => 9000, 'kdv' => 1800]]);
esit('kdv toplamı', $b['kdv'], 2790);
esit('toplam = matrah + kdv', $b['toplam'], 29790);

// Genel iskonto kuruş dağıtımı: 3 eşit satır, 1 kuruş artık → toplam şaşmaz
$b = Hesap::belge([s(1000, 33330, 0, 20), s(1000, 33330, 0, 20), s(1000, 33330, 0, 20)], 333);
esit('dağıtım toplamı genel iskontoya eşit', array_sum(array_column($b['satirlar'], 'genel_iskonto')), $b['genel_iskonto']);
esit('matrah = ara - genel', $b['matrah'], $b['ara_toplam'] - $b['genel_iskonto']);
esit('matrah = satır matrah toplamı', $b['matrah'], array_sum(array_column($b['satirlar'], 'matrah')));

// %100 iskonto
$b = Hesap::belge([s(1000, 100000, 10000, 20)]);
esit('%100 iskonto → 0', $b['toplam'], 0);
// KDV oranı hatalı
try { Hesap::belge([s(1000, 100, 0, 18)]); esit('18 reddi', true, false); } catch (InvalidArgumentException) { esit('KDV 18 reddedilir', true, true); }
// Rastgele tutarlılık (1000 belge): toplam = matrah+kdv, matrah = ara-genel, dağıtım tam
mt_srand(42);
$kotu = 0;
for ($n = 0; $n < 1000; $n++) {
    $sat = [];
    for ($i = 0, $k = mt_rand(1, 8); $i < $k; $i++) $sat[] = s(mt_rand(1, 500000), mt_rand(0, 9999999), mt_rand(0, 3000), [1, 10, 20][mt_rand(0, 2)]);
    $b = Hesap::belge($sat, mt_rand(0, 2500));
    $ok = $b['toplam'] === $b['matrah'] + $b['kdv']
        && $b['matrah'] === $b['ara_toplam'] - $b['genel_iskonto']
        && array_sum(array_column($b['satirlar'], 'genel_iskonto')) === $b['genel_iskonto']
        && array_sum(array_column($b['kdv_gruplari'], 'matrah')) === $b['matrah']
        && min(array_column($b['satirlar'], 'matrah')) >= 0;
    if (!$ok) $kotu++;
}
esit('1000 rastgele belgede tutarlılık', $kotu, 0);

echo "\nHesap testleri: $pass geçti, $fail başarısız\n";
exit($fail ? 1 : 0);
