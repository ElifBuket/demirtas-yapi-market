<?php
declare(strict_types=1);

require_once __DIR__ . '/Num.php';

/**
 * Saf (veritabanından bağımsız) normalleştirme fonksiyonları. Hepsi birim testle doğrulanır.
 * Para her yerde TAM SAYI KURUŞ olarak taşınır; float kullanılmaz.
 */
final class Norm
{
    // ---------- yardımcılar ----------

    /** Türkçe karakterleri ASCII'ye indirger, küçük harfe çevirir ("Sıvas" => "sivas"). */
    public static function ascii(?string $s): string
    {
        if ($s === null) return '';
        $s = strtr($s, [
            'İ' => 'i', 'I' => 'i', 'ı' => 'i', 'Ş' => 's', 'ş' => 's', 'Ğ' => 'g', 'ğ' => 'g',
            'Ü' => 'u', 'ü' => 'u', 'Ö' => 'o', 'ö' => 'o', 'Ç' => 'c', 'ç' => 'c', "\u{0307}" => '',
        ]);
        return strtolower(trim($s));
    }

    public static function clean(mixed $v): ?string
    {
        if ($v === null) return null;
        $s = preg_replace('/\s+/u', ' ', trim((string)$v));
        return $s === '' ? null : $s;
    }

    /** Türkçe uyumlu "Baş Harfler Büyük". */
    public static function title(string $s): string
    {
        $s = strtr($s, ['I' => 'ı', 'İ' => 'i']);
        $s = mb_strtolower($s, 'UTF-8');
        return preg_replace_callback('/(^|\s)(\p{L})/u', function ($m) {
            $c = $m[2] === 'i' ? 'İ' : ($m[2] === 'ı' ? 'I' : mb_strtoupper($m[2], 'UTF-8'));
            return $m[1] . $c;
        }, $s);
    }

    public static function kurusToStr(int $kurus): string
    {
        $sign = $kurus < 0 ? '-' : '';
        $k = abs($kurus);
        return sprintf('%s%d.%02d', $sign, intdiv($k, 100), $k % 100);
    }

    // ---------- para ----------

    /**
     * @return array{kurus:?int, durum:string, oncelik:string, gerekce:string, not:?string}
     * durum: ok | duzeltildi | bos | gecersiz
     */
    public static function parseMoney(mixed $raw): array
    {
        $res = fn(?int $k, string $d, string $o = 'dusuk', string $g = '', ?string $n = null) =>
            ['kurus' => $k, 'durum' => $d, 'oncelik' => $o, 'gerekce' => $g, 'not' => $n];

        if ($raw === null) return $res(null, 'bos');

        if ($raw instanceof Num) {
            $r = $raw->raw;
            if (preg_match('/^-?\d+(\.\d+)?$/', $r)) {
                return $res(self::decToKurus($r), 'ok');
            }
            if (is_numeric($r)) { // 1.2E+5 gibi bilimsel gösterim
                return $res((int)round((float)$r * 100), 'duzeltildi', 'orta', 'Bilimsel gösterimdeki sayı çevrildi');
            }
            return $res(null, 'gecersiz', 'yuksek', 'Sayı okunamadı');
        }

        $s = trim((string)$raw);
        if ($s === '' || $s === '-') return $res(null, 'bos');

        $not = null;
        $changed = false;
        if (preg_match('/\(([^)]*)\)/u', $s, $m)) {
            $not = trim($m[1]);
            $s = trim(preg_replace('/\([^)]*\)/u', '', $s));
            $changed = true;
        }
        $before = $s;
        $s = preg_replace('/\s+/u', '', str_ireplace(['₺', 'TRY', 'TL', '€', 'EUR'], '', $s));
        if ($s !== $before && $s !== '') $changed = true;

        if ($s === '' || !preg_match('/^-?[\d.,]+$/', $s)) {
            return $res(null, 'gecersiz', 'yuksek', 'Tutar sayıya çevrilemedi: "' . trim((string)$raw) . '"', $not);
        }
        $neg = $s[0] === '-';
        $s = ltrim($s, '-');
        $amb = false;

        if (preg_match('/^\d{1,3}(\.\d{3})*,\d{1,2}$/', $s)) {            // 16.650,41
            [$i, $f] = explode(',', str_replace('.', '', $s));
        } elseif (preg_match('/^\d+,\d{1,2}$/', $s)) {                    // 999,5
            [$i, $f] = explode(',', $s);
        } elseif (preg_match('/^\d{1,3}(\.\d{3})+\.\d{2}$/', $s)) {       // 9.999.99
            $pos = (int)strrpos($s, '.');
            $i = str_replace('.', '', substr($s, 0, $pos));
            $f = substr($s, $pos + 1);
            $amb = true;
        } elseif (preg_match('/^\d{1,3}(,\d{3})+,\d{2}$/', $s)) {         // 99,999,99
            $pos = (int)strrpos($s, ',');
            $i = str_replace(',', '', substr($s, 0, $pos));
            $f = substr($s, $pos + 1);
            $amb = true;
        } elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $s)) {              // 9.999 => 9999
            $i = str_replace('.', '', $s);
            $f = '0';
            $amb = true;
        } elseif (preg_match('/^\d+(\.\d+)?$/', $s)) {                    // 115101.47
            $k = self::decToKurus($s);
            return $res($neg ? -$k : $k, $changed ? 'duzeltildi' : 'ok', 'dusuk', $changed ? 'Para birimi/metin eki temizlendi' : '', $not);
        } else {
            return $res(null, 'gecersiz', 'yuksek', 'Tutar biçimi tanınmadı: "' . trim((string)$raw) . '"', $not);
        }

        $k = (int)$i * 100 + (int)str_pad(substr($f, 0, 2), 2, '0');
        $k = $neg ? -$k : $k;
        if ($amb) {
            return $res($k, 'duzeltildi', 'orta', 'Belirsiz ayraçlı tutar Türkçe biçime göre yorumlandı (' . trim((string)$raw) . ' => ' . self::kurusToStr($k) . ')', $not);
        }
        if ($not !== null) {
            return $res($k, 'duzeltildi', 'orta', 'Tutar yanındaki not ayrıldı: "' . $not . '"', $not);
        }
        return $res($k, $changed ? 'duzeltildi' : 'ok', 'dusuk', $changed ? 'Para birimi/metin eki temizlendi' : '', $not);
    }

    /** "123.4" / "123.456" => kuruş (3. hane ve sonrası yarım yukarı yuvarlanır), float kullanmadan. */
    public static function decToKurus(string $dec): int
    {
        $neg = str_starts_with($dec, '-');
        $dec = ltrim($dec, '-');
        [$i, $f] = array_pad(explode('.', $dec, 2), 2, '0');
        $f = str_pad($f, 3, '0');
        $k = (int)$i * 100 + (int)substr($f, 0, 2);
        if ((int)$f[2] >= 5) $k++;
        return $neg ? -$k : $k;
    }

    /** @return array{limitsiz:bool, kurus:?int, durum:string, oncelik:string, gerekce:string} */
    public static function parseRisk(mixed $raw): array
    {
        $s = self::clean($raw instanceof Num ? $raw->raw : $raw);
        if ($s === null) {
            return ['limitsiz' => false, 'kurus' => null, 'durum' => 'bos', 'oncelik' => 'orta', 'gerekce' => 'Risk limiti boş: tanımsız bırakıldı (siparişler patron onayına düşer)'];
        }
        $a = self::ascii($s);
        if ($a === 'limitsiz') {
            return ['limitsiz' => true, 'kurus' => null, 'durum' => 'ok', 'oncelik' => 'dusuk', 'gerekce' => ''];
        }
        if (preg_match('/^(\d+(?:[.,]\d+)?)\s*bin$/', $a, $m)) {
            $k = self::decToKurus(str_replace(',', '.', $m[1])) * 1000;
            return ['limitsiz' => false, 'kurus' => $k, 'durum' => 'duzeltildi', 'oncelik' => 'dusuk', 'gerekce' => '"' . $s . '" => ' . self::kurusToStr($k)];
        }
        $m = self::parseMoney($raw);
        return ['limitsiz' => false, 'kurus' => $m['kurus'], 'durum' => $m['durum'], 'oncelik' => $m['oncelik'], 'gerekce' => $m['gerekce']];
    }

    // ---------- cari alanları ----------

    public static function tip(mixed $raw): ?string
    {
        $a = self::ascii(self::clean($raw));
        if ($a === '') return null;
        if ($a[0] === 'm') return 'musteri';
        if ($a[0] === 't') return 'tedarikci';
        return null;
    }

    /** @return 'B'|'A'|null  B = bize borçlu (borç), A = biz borçluyuz (alacak) */
    public static function borcAlacak(mixed $raw): ?string
    {
        $a = self::ascii(self::clean($raw));
        return match ($a) {
            'b', 'borc', 'borclu' => 'B',
            'a', 'alacak', 'alacakli' => 'A',
            default => null,
        };
    }

    public static function currency(mixed $raw): string
    {
        $a = self::ascii(self::clean($raw));
        return in_array($a, ['€', 'eur', 'euro'], true) ? 'EUR' : 'TRY';
    }

    public static function city(?string $raw): ?string
    {
        $s = self::clean($raw);
        if ($s === null) return null;
        $map = ['sivas' => 'Sivas', 'tokat' => 'Tokat', 'yozgat' => 'Yozgat', 'malatya' => 'Malatya',
                'erzincan' => 'Erzincan', 'kayseri' => 'Kayseri', 'erzurum' => 'Erzurum', 'amasya' => 'Amasya'];
        return $map[self::ascii($s)] ?? self::title($s);
    }

    public static function district(?string $raw): ?string
    {
        $s = self::clean($raw);
        if ($s === null) return null;
        return ($s === mb_strtoupper($s, 'UTF-8') || $s === mb_strtolower($s, 'UTF-8')) ? self::title($s) : $s;
    }

    /** @return array{ad:?string, ayrildi:bool} */
    public static function satisci(?string $raw): array
    {
        $a = self::ascii(self::clean($raw));
        if ($a === '') return ['ad' => null, 'ayrildi' => false];
        if (str_contains($a, 'hakan')) return ['ad' => null, 'ayrildi' => true];
        if (str_contains($a, 'emre') || preg_match('/^e\.?\s*dogan/', $a)) return ['ad' => 'Emre Doğan', 'ayrildi' => false];
        if (str_contains($a, 'mehmet')) return ['ad' => 'Mehmet Kaya', 'ayrildi' => false];
        if (str_contains($a, 'serkan')) return ['ad' => 'Serkan Öztürk', 'ayrildi' => false];
        if (str_contains($a, 'ahmet')) return ['ad' => 'Ahmet Yılmaz', 'ayrildi' => false];
        return ['ad' => null, 'ayrildi' => false];
    }

    /** @return array{vkn:?string, gecerli:bool, durum:string, gerekce:string} */
    public static function vkn(mixed $raw): array
    {
        $s = self::clean($raw instanceof Num ? $raw->raw : $raw);
        if ($s === null) return ['vkn' => null, 'gecerli' => false, 'durum' => 'bos', 'gerekce' => 'VKN/TCKN boş (e-Fatura için gerekli)'];
        $d = preg_replace('/\D/', '', $s);
        if ($d === '') return ['vkn' => null, 'gecerli' => false, 'durum' => 'bos', 'gerekce' => 'VKN/TCKN sayısal değil: "' . $s . '"'];
        $orig = $d;
        if (self::vknOk($d) || self::tcknOk($d)) {
            return ['vkn' => $d, 'gecerli' => true, 'durum' => 'ok', 'gerekce' => ''];
        }
        if (strlen($d) < 10) { // Excel baştaki sıfırları silmiş olabilir
            $p = str_pad($d, 10, '0', STR_PAD_LEFT);
            if (self::vknOk($p)) {
                return ['vkn' => $p, 'gecerli' => true, 'durum' => 'duzeltildi', 'gerekce' => 'Baştaki sıfır(lar) geri eklendi: ' . $orig . ' => ' . $p];
            }
        }
        return ['vkn' => $d, 'gecerli' => false, 'durum' => 'gecersiz', 'gerekce' => 'VKN/TCKN doğrulama algoritmasından geçmedi (uzunluk ' . strlen($d) . ')'];
    }

    public static function vknOk(string $v): bool
    {
        if (!preg_match('/^\d{10}$/', $v)) return false;
        $t = 0;
        for ($i = 0; $i < 9; $i++) {
            $d = ((int)$v[$i] + 9 - $i) % 10;
            $x = ($d * (2 ** (9 - $i))) % 9;
            if ($d !== 0 && $x === 0) $x = 9;
            $t += $x;
        }
        return ((10 - $t % 10) % 10) === (int)$v[9];
    }

    public static function tcknOk(string $v): bool
    {
        if (!preg_match('/^[1-9]\d{10}$/', $v)) return false;
        $d = array_map('intval', str_split($v));
        $odd = $d[0] + $d[2] + $d[4] + $d[6] + $d[8];
        $even = $d[1] + $d[3] + $d[5] + $d[7];
        return (($odd * 7 - $even) % 10 + 10) % 10 === $d[9] && (array_sum(array_slice($d, 0, 10)) % 10) === $d[10];
    }

    /** @return array{tel:?string, durum:string, gerekce:string} */
    public static function phone(mixed $raw): array
    {
        $s = self::clean($raw instanceof Num ? $raw->raw : $raw);
        if ($s === null) return ['tel' => null, 'durum' => 'bos', 'gerekce' => ''];
        $d = preg_replace('/\D/', '', $s);
        if (strlen($d) === 12 && str_starts_with($d, '90')) $d = '0' . substr($d, 2);
        if (strlen($d) === 10 && $d[0] !== '0') $d = '0' . $d;
        if (strlen($d) === 11 && $d[0] === '0') {
            return ['tel' => $d, 'durum' => $d === $s ? 'ok' : 'duzeltildi', 'gerekce' => 'Telefon biçimi 0XXXXXXXXXX olarak standartlaştırıldı'];
        }
        return ['tel' => null, 'durum' => 'gecersiz', 'gerekce' => 'Telefon çözümlenemedi: "' . $s . '"'];
    }

    /** @return array{eposta:?string, durum:string, gerekce:string} */
    public static function email(mixed $raw): array
    {
        $s = self::clean($raw);
        if ($s === null) return ['eposta' => null, 'durum' => 'bos', 'gerekce' => ''];
        $s = strtolower($s);
        $fixed = preg_replace('/@{2,}/', '@', $s);
        $ok = fn(string $e) => (bool)preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $e);
        if ($ok($s)) return ['eposta' => $s, 'durum' => 'ok', 'gerekce' => ''];
        if ($ok($fixed)) return ['eposta' => $fixed, 'durum' => 'duzeltildi', 'gerekce' => 'Çift @ düzeltildi: ' . $s . ' => ' . $fixed];
        return ['eposta' => null, 'durum' => 'gecersiz', 'gerekce' => 'Geçersiz e-posta adresi atlandı: "' . $s . '"'];
    }

    /** @return array{tarih:?string, durum:string, gerekce:string} */
    public static function date(mixed $raw, string $ust = '2025-12-31'): array
    {
        if ($raw === null) return ['tarih' => null, 'durum' => 'bos', 'gerekce' => ''];
        $t = null;
        $dur = 'ok';
        if ($raw instanceof DateTimeInterface) {
            $t = $raw->format('Y-m-d');
        } else {
            $s = self::clean($raw instanceof Num ? $raw->raw : $raw);
            if ($s !== null && preg_match('/^(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{2}|\d{4})$/', $s, $m)) {
                $y = (int)$m[3];
                if ($y < 100) $y += 2000;
                if (checkdate((int)$m[2], (int)$m[1], $y)) {
                    $t = sprintf('%04d-%02d-%02d', $y, (int)$m[2], (int)$m[1]);
                    $dur = 'duzeltildi';
                }
            }
        }
        if ($t === null) return ['tarih' => null, 'durum' => 'gecersiz', 'gerekce' => 'Tarih çözümlenemedi: "' . self::clean($raw instanceof Num ? $raw->raw : $raw) . '"'];
        if ($t > $ust) return ['tarih' => null, 'durum' => 'gecersiz', 'gerekce' => "Tarih bakiye tarihinden ($ust) sonra, atlandı: $t"];
        return ['tarih' => $t, 'durum' => $dur, 'gerekce' => $dur === 'duzeltildi' ? 'Tarih biçimi standartlaştırıldı' : ''];
    }

    // ---------- ürün alanları ----------

    private const UNIT_MAP = [
        'ad' => 'adet', 'adt' => 'adet', 'ad.' => 'adet', 'adet' => 'adet', 'kova' => 'kova',
        'kutu' => 'kutu', 'kt' => 'kutu', 'mt' => 'm', 'm' => 'm', 'metre' => 'm',
        'm2' => 'm2', 'm²' => 'm2', 'metrekare' => 'm2', 'torba' => 'torba', 'trb' => 'torba',
        'kg' => 'kg', 'kilo' => 'kg', 'ton' => 'ton', 'paket' => 'paket', 'pk' => 'paket',
        'rulo' => 'rulo', 'boy' => 'boy', 'makara' => 'makara',
    ];
    /** Ondalıklı stok tutulabilen birimler. */
    public const DECIMAL_UNITS = ['kg', 'ton', 'm', 'm2'];

    public static function unit(mixed $raw): ?string
    {
        return self::UNIT_MAP[self::ascii(self::clean($raw))] ?? null;
    }

    /** @return array{oran:?int, durum:string, gerekce:string}  oran: 1|10|20 */
    public static function kdv(mixed $raw): array
    {
        $s = self::clean($raw instanceof Num ? $raw->raw : $raw);
        if ($s === null) return ['oran' => 20, 'durum' => 'duzeltildi', 'gerekce' => 'KDV boştu, %20 varsayıldı'];
        $n = str_replace(',', '.', str_replace(['%', ' '], '', $s));
        if (!is_numeric($n)) return ['oran' => 20, 'durum' => 'duzeltildi', 'gerekce' => 'KDV okunamadı ("' . $s . '"), %20 varsayıldı'];
        $v = (float)$n;
        if ($v > 0 && $v < 1) $v *= 100;
        $v = (int)round($v);
        if (in_array($v, [1, 10, 20], true)) {
            $dur = ($raw instanceof Num && $raw->raw === (string)$v) || $s === (string)$v ? 'ok' : 'duzeltildi';
            return ['oran' => $v, 'durum' => $dur, 'gerekce' => $dur === 'ok' ? '' : 'KDV biçimi standartlaştırıldı: "' . $s . '" => %' . $v];
        }
        if ($v === 18) return ['oran' => 20, 'durum' => 'duzeltildi', 'gerekce' => 'ŞÜPHELİ: %18 güncel KDV oranı değil, %20 olarak aktarıldı (müşteriye soruldu)'];
        return ['oran' => 20, 'durum' => 'duzeltildi', 'gerekce' => 'Geçersiz KDV oranı %' . $v . ', %20 varsayıldı'];
    }

    /**
     * "1 kutu = 1,44 m²" => temel birime göre alt birim çevrimi. 1 alt = pay/payda temel.
     * @return array{alt:string, pay:int, payda:int}|null
     */
    public static function conversion(?string $text, string $temel): ?array
    {
        $t = self::clean($text);
        if ($t === null) return null;
        if (!preg_match('/^1\s*(\S+)\s*=\s*([\d.,]+)\s*(\S+)$/u', $t, $m)) return null;
        $x = self::unit($m[1]);
        $y = self::unit($m[3]);
        if ($x === null || $y === null) return null;
        [$n, $d] = self::fraction($m[2]);
        if ($n <= 0) return null;
        if ($y === $temel) return self::reduce($x, $n, $d);       // 1 x = N temel
        if ($x === $temel) return self::reduce($y, $d, $n);       // 1 y = 1/N temel
        return null;
    }

    /** "1,44" => [144, 100] */
    private static function fraction(string $s): array
    {
        $s = str_replace(',', '.', $s);
        [$i, $f] = array_pad(explode('.', $s, 2), 2, '');
        return [(int)($i . $f), 10 ** strlen($f)];
    }

    private static function reduce(string $alt, int $pay, int $payda): array
    {
        $g = self::gcd($pay, $payda);
        return ['alt' => $alt, 'pay' => intdiv($pay, $g), 'payda' => intdiv($payda, $g)];
    }

    private static function gcd(int $a, int $b): int
    {
        return $b === 0 ? $a : self::gcd($b, $a % $b);
    }

    /**
     * Stok miktarı. @return array{miktar:?string, durum:string, oncelik:string, gerekce:string}
     * miktar: 3 ondalıklı metin (ör. "230.000"), sıfır ise "0.000".
     */
    public static function stock(mixed $raw, string $temelBirim): array
    {
        $fail = fn(string $g, string $o = 'orta') => ['miktar' => '0.000', 'durum' => 'duzeltildi', 'oncelik' => $o, 'gerekce' => $g];
        if ($raw === null) return ['miktar' => '0.000', 'durum' => 'bos', 'oncelik' => 'dusuk', 'gerekce' => ''];
        $s = self::clean($raw instanceof Num ? $raw->raw : $raw);
        if ($s === null) return ['miktar' => '0.000', 'durum' => 'bos', 'oncelik' => 'dusuk', 'gerekce' => ''];
        $a = self::ascii($s);
        if (in_array($a, ['yok', 'bitti'], true)) return $fail('"' . $s . '" yazıyordu, 0 olarak aktarıldı', 'dusuk');
        if (!preg_match('/^-?\d+([.,]\d+)?$/', $s)) return $fail('Sayısal olmayan stok değeri "' . $s . '", 0 olarak aktarıldı (sayım gerekli)');
        $n = str_replace(',', '.', $s);
        if ($n[0] === '-') return $fail('Eksi stok (' . $s . '), 0 olarak aktarıldı (sayım gerekli)');
        if (str_contains($n, '.') && (float)$n != (int)(float)$n && !in_array($temelBirim, self::DECIMAL_UNITS, true)) {
            return $fail('Birim "' . $temelBirim . '" için ondalıklı stok (' . $s . ') olamaz, 0 olarak aktarıldı (sayım gerekli)');
        }
        [$i, $f] = array_pad(explode('.', $n, 2), 2, '0');
        return ['miktar' => (int)$i . '.' . str_pad(substr($f, 0, 3), 3, '0'), 'durum' => 'ok', 'oncelik' => 'dusuk', 'gerekce' => ''];
    }

    /** Barkod: yalnızca 8/12/13/14 haneli sayısal değerler kabul edilir. */
    public static function barcode(mixed $raw): array
    {
        $s = self::clean($raw instanceof Num ? $raw->raw : $raw);
        if ($s === null) return ['barkod' => null, 'durum' => 'bos', 'gerekce' => ''];
        if (preg_match('/^\d{8}$|^\d{12,14}$/', $s)) return ['barkod' => $s, 'durum' => 'ok', 'gerekce' => ''];
        return ['barkod' => null, 'durum' => 'gecersiz', 'gerekce' => 'Barkod bozuk (Excel bilimsel gösterime çevirmiş: "' . $s . '"), gerçek değer kurtarılamadı; boş bırakıldı'];
    }

    public const CATEGORY_PREFIX = [
        'Boya' => 'BOY', 'Hırdavat' => 'HRD', 'Tesisat' => 'TSS', 'Elektrik' => 'ELK', 'Seramik' => 'SRM',
        'İnşaat Demiri' => 'DMR', 'Yalıtım' => 'YLT', 'Çimento' => 'CMN', 'Harç ve Yapıştırıcı' => 'HRC',
        'Duvar Malzemesi' => 'DVR', 'Agrega' => 'AGR', 'Kereste ve Hurda' => 'KRS',
    ];

    public static function category(mixed $raw): ?string
    {
        $a = self::ascii(self::clean($raw));
        foreach (array_keys(self::CATEGORY_PREFIX) as $cat) {
            if (self::ascii($cat) === $a) return $cat;
        }
        return $a === '' ? null : self::title((string)self::clean($raw));
    }

    /** Fiyat metni: "33,44 KDV dahil" => ['kurus'=>3344,'kdv_dahil'=>true,...] */
    public static function price(mixed $raw): array
    {
        $dahil = false;
        if (!($raw instanceof Num) && $raw !== null) {
            $s = (string)$raw;
            if (preg_match('/kdv\s*dahil/iu', $s)) {
                $dahil = true;
                $raw = trim(preg_replace('/kdv\s*dahil/iu', '', $s));
            }
        }
        $m = self::parseMoney($raw);
        $m['kdv_dahil'] = $dahil;
        return $m;
    }

    /** kuruş (KDV dahil) => 4 ondalıklı KDV'siz fiyat metni, tam sayı aritmetiğiyle. */
    public static function kdvDahilToHaric(int $kurus, int $kdv): string
    {
        // p4 = kurus*100 / (1 + kdv/100) = kurus*10000/(100+kdv)  (1/10000 TL biriminde, yarım yukarı)
        $den = 100 + $kdv;
        $p4 = intdiv($kurus * 10000 * 2 + $den, 2 * $den);
        return self::p4ToStr($p4);
    }

    public static function kurusToP4Str(int $kurus): string
    {
        return self::p4ToStr($kurus * 100);
    }

    private static function p4ToStr(int $p4): string
    {
        return sprintf('%d.%04d', intdiv($p4, 10000), $p4 % 10000);
    }
}
