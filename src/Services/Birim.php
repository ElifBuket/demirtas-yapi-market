<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Miktarlar "binde bir" biriminde TAM SAYI (milli) olarak işlenir: 12,5 kg = 12500. float yoktur.
 * Çevrim katsayısı pay/payda kesridir: 1 alt_birim = pay/payda temel_birim.
 */
final class Birim
{
    /** Ondalıklı miktar tutulabilen temel birimler; diğerlerinde (adet, torba, kutu…) yalnızca tam sayı. */
    public const ONDALIKLI = ['kg', 'ton', 'm', 'm2'];

    /** "12,5" / "12.5" / "230" => 12500 / 12500 / 230000. Geçersizse null. En çok 3 ondalık. */
    public static function parse(string $s): ?int
    {
        if (!preg_match('/^(-)?(\d{1,9})(?:[.,](\d{1,3}))?$/', trim($s), $m)) return null;
        $milli = (int)$m[2] * 1000 + (int)str_pad($m[3] ?? '', 3, '0');
        return ($m[1] ?? '') === '-' ? -$milli : $milli;
    }

    /** 12500 => "12.500" (veritabanı DECIMAL(15,3) biçimi) */
    public static function fmt(int $milli): string
    {
        $a = abs($milli);
        return ($milli < 0 ? '-' : '') . intdiv($a, 1000) . '.' . str_pad((string)($a % 1000), 3, '0', STR_PAD_LEFT);
    }

    /** Veritabanı DECIMAL metni "12.500" => 12500 */
    public static function fromDecimal(string $dec): int
    {
        $neg = str_starts_with($dec, '-');
        [$i, $f] = array_pad(explode('.', ltrim($dec, '-'), 2), 2, '0');
        $milli = (int)$i * 1000 + (int)str_pad(substr($f, 0, 3), 3, '0');
        return $neg ? -$milli : $milli;
    }

    /** Alt birimdeki miktarı temel birime çevirir: milli * pay / payda, yarım yukarı yuvarlanır. */
    public static function cevir(int $milli, int $pay, int $payda): int
    {
        if ($milli < 0) return -self::cevir(-$milli, $pay, $payda);
        return intdiv($milli * $pay * 2 + $payda, 2 * $payda);
    }

    public static function tamMi(int $milli): bool
    {
        return $milli % 1000 === 0;
    }

    /** Ekranda gereksiz sıfırsız gösterim: 230000 => "230", 12500 => "12,5" */
    public static function goster(int $milli): string
    {
        $s = self::fmt($milli);
        $s = rtrim(rtrim($s, '0'), '.');
        $neg = str_starts_with($s, '-');
        $s = ltrim($s, '-');
        [$i, $f] = array_pad(explode('.', $s, 2), 2, '');
        $i = preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', $i);
        return ($neg ? '-' : '') . $i . ($f !== '' ? ',' . $f : '');
    }
}
