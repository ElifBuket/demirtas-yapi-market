<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Fatura/sipariş hesapları. YALNIZCA tam sayı aritmetiği kullanılır (float yok):
 *  - miktar: milli (1 adet = 1000)      - birim fiyat: "fiyat4" (1 TL = 10000), KDV hariç
 *  - tutarlar: kuruş                    - iskonto: baz puan (%10 = 1000, %12,5 = 1250)
 * Yuvarlama: her adımda "yarım yukarı" (half-up), tek yerde (bolRound).
 *
 * Sıra: satır brüt → satır iskontosu → genel iskonto (satırlara orantılı, en büyük kalan yöntemiyle,
 * toplam genel iskonto kuruşu tam dağıtılır) → KDV oranı grubu başına matrah toplanır → KDV grup başına
 * bir kez yuvarlanır (satır satır yuvarlayıp toplamak yerine; e-Fatura'da da KDV oranı bazında toplanır).
 */
final class Hesap
{
    public const KDV_ORANLARI = [1, 10, 20];

    /** a / b, yarım yukarı yuvarlanmış (a >= 0, b > 0). */
    public static function bolRound(int $a, int $b): int
    {
        if ($b <= 0) throw new \InvalidArgumentException('Bölen pozitif olmalı');
        if ($a < 0) throw new \InvalidArgumentException('Negatif tutar');
        return intdiv(2 * $a + $b, 2 * $b);
    }

    /** "12,50" / "12.5" gibi TL metnini fiyat4'e çevirir (en çok 4 ondalık). Geçersizse null. */
    public static function fiyatParse(string $s): ?int
    {
        $s = trim($s);
        if (!preg_match('/^(\d{1,9})(?:[.,](\d{1,4}))?$/', $s, $m)) return null;
        return (int)$m[1] * 10000 + (int)str_pad($m[2] ?? '', 4, '0');
    }

    /** DECIMAL metni ("123.4500") → fiyat4 */
    public static function fiyatDb(string $dec): int
    {
        return self::fiyatParse($dec) ?? throw new \InvalidArgumentException("Geçersiz fiyat: $dec");
    }

    /** Yüzde metni ("10", "12,5") → baz puan; 0–100 dışı veya >2 ondalık: null */
    public static function iskontoParse(string $s): ?int
    {
        $s = trim($s);
        if ($s === '') return 0;
        if (!preg_match('/^(\d{1,3})(?:[.,](\d{1,2}))?$/', $s, $m)) return null;
        $bp = (int)$m[1] * 100 + (int)str_pad($m[2] ?? '', 2, '0');
        return $bp > 10000 ? null : $bp;
    }

    /** kuruş → "1234.50" (DB için, nokta ondalık) */
    public static function dec(int $kurus): string
    {
        $neg = $kurus < 0; $k = abs($kurus);
        return ($neg ? '-' : '') . intdiv($k, 100) . '.' . str_pad((string)($k % 100), 2, '0', STR_PAD_LEFT);
    }

    /** DECIMAL(15,2) metni → kuruş */
    public static function kurus(string $dec): int
    {
        if (!preg_match('/^(-)?(\d+)(?:\.(\d{1,2}))?\d*$/', trim($dec), $m)) throw new \InvalidArgumentException("Geçersiz tutar: $dec");
        $v = (int)$m[2] * 100 + (int)str_pad($m[3] ?? '', 2, '0');
        return $m[1] === '-' ? -$v : $v;
    }

    /** Ekranda: 1.234,50 */
    public static function goster(int $kurus): string
    {
        $neg = $kurus < 0; $k = abs($kurus);
        return ($neg ? '-' : '') . number_format(intdiv($k, 100), 0, ',', '.') . ',' . str_pad((string)($k % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Tek satır: brüt, iskonto, net (kuruş). */
    public static function satir(int $miktarMilli, int $fiyat4, int $iskontoBp): array
    {
        if ($miktarMilli <= 0 || $fiyat4 < 0 || $iskontoBp < 0 || $iskontoBp > 10000) throw new \InvalidArgumentException('Geçersiz satır');
        $brut = self::bolRound($miktarMilli * $fiyat4, 100000);
        $isk = self::bolRound($brut * $iskontoBp, 10000);
        return ['brut' => $brut, 'iskonto' => $isk, 'net' => $brut - $isk];
    }

    /**
     * @param list<array{miktar:int, fiyat4:int, iskonto_bp:int, kdv:int}> $satirlar
     * @return array{satirlar:list<array>, kdv_gruplari:array<int,array{matrah:int,kdv:int}>, ara_toplam:int, genel_iskonto:int, matrah:int, kdv:int, toplam:int}
     */
    public static function belge(array $satirlar, int $genelIskontoBp = 0): array
    {
        if ($genelIskontoBp < 0 || $genelIskontoBp > 10000) throw new \InvalidArgumentException('Geçersiz genel iskonto');
        $out = []; $ara = 0;
        foreach ($satirlar as $i => $s) {
            if (!in_array($s['kdv'], self::KDV_ORANLARI, true)) throw new \InvalidArgumentException('Geçersiz KDV oranı: ' . $s['kdv']);
            $h = self::satir($s['miktar'], $s['fiyat4'], $s['iskonto_bp']);
            $out[$i] = $h + ['kdv' => $s['kdv'], 'genel_iskonto' => 0, 'matrah' => $h['net']];
            $ara += $h['net'];
        }
        $gen = self::bolRound($ara * $genelIskontoBp, 10000);
        if ($gen > 0 && $ara > 0) {
            // En büyük kalan yöntemi: pay = gen * net / ara; kalanlar en büyükten başlayarak 1 kuruş alır.
            $dagit = []; $kalan = [];
            foreach ($out as $i => $s) {
                $dagit[$i] = intdiv($gen * $s['net'], $ara);
                $kalan[$i] = ($gen * $s['net']) % $ara;
            }
            $eksik = $gen - array_sum($dagit);
            $idx = array_keys($kalan);
            usort($idx, fn($a, $b) => $kalan[$b] <=> $kalan[$a] ?: $a <=> $b); // eşitlikte küçük sıra önce (deterministik)
            for ($k = 0; $k < $eksik; $k++) $dagit[$idx[$k]]++;
            foreach ($out as $i => &$s) { $s['genel_iskonto'] = $dagit[$i]; $s['matrah'] = $s['net'] - $dagit[$i]; }
            unset($s);
        }
        $gruplar = [];
        foreach ($out as $s) {
            $gruplar[$s['kdv']]['matrah'] = ($gruplar[$s['kdv']]['matrah'] ?? 0) + $s['matrah'];
        }
        ksort($gruplar);
        $matrah = 0; $kdv = 0;
        foreach ($gruplar as $oran => &$g) {
            $g['kdv'] = self::bolRound($g['matrah'] * $oran, 100);
            $matrah += $g['matrah']; $kdv += $g['kdv'];
        }
        unset($g);
        return ['satirlar' => $out, 'kdv_gruplari' => $gruplar, 'ara_toplam' => $ara, 'genel_iskonto' => $gen, 'matrah' => $matrah, 'kdv' => $kdv, 'toplam' => $matrah + $kdv];
    }
}
