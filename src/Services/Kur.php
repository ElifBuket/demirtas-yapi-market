<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\{Audit, Db};

/** Döviz kuru: TCMB günlük XML'inden (döviz satış) çekilir; ağ yoksa elle girilir. Hiçbir yerde kur uydurulmaz. */
final class Kur
{
    public const TCMB_URL = 'https://www.tcmb.gov.tr/kurlar/today.xml';

    /** TCMB XML'ini ayrıştırır. @return array{tarih:string, kurlar:array<string,int>} kur 1/10000 TL cinsinden (EUR 36,1234 → 361234) */
    public static function tcmbParse(string $xml): array
    {
        $prev = libxml_use_internal_errors(true);
        $x = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET);
        libxml_clear_errors(); libxml_use_internal_errors($prev);
        if ($x === false || $x->getName() !== 'Tarih_Date') throw new IsHatasi('TCMB yanıtı beklenen biçimde değil.');
        $d = \DateTimeImmutable::createFromFormat('!m/d/Y', (string)$x['Date']);
        if ($d === false) throw new IsHatasi('TCMB tarihi okunamadı.');
        $out = [];
        foreach ($x->Currency as $c) {
            $kod = (string)$c['CurrencyCode'];
            $f = Hesap::fiyatParse(str_replace(',', '.', trim((string)$c->ForexSelling)));
            if (in_array($kod, ['EUR', 'USD'], true) && $f !== null && $f > 0) $out[$kod] = $f;
        }
        if (!$out) throw new IsHatasi('TCMB yanıtında EUR/USD kuru bulunamadı.');
        return ['tarih' => $d->format('Y-m-d'), 'kurlar' => $out];
    }

    public static function kaydet(string $tarih, string $kod, int $kur4, string $kaynak, array $kul): void
    {
        if (!in_array($kod, ['EUR', 'USD'], true)) throw new IsHatasi('Desteklenmeyen para birimi.');
        if ($kur4 <= 0 || $kur4 > 10000000) throw new IsHatasi('Kur geçersiz.');
        Db::run('INSERT INTO kurlar (tarih, kod, satis, kaynak) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE satis = VALUES(satis), kaynak = VALUES(kaynak)', [$tarih, $kod, Satis::fiyatDec($kur4), $kaynak]);
        Audit::log('ekle', 'kurlar', $tarih . ' ' . $kod, null, ['satis' => Satis::fiyatDec($kur4), 'kaynak' => $kaynak], 'Döviz kuru kaydedildi', $kul);
    }

    /** TCMB'den güncelle; başarısızsa IsHatasi (kullanıcıya "elle girin" denir). */
    public static function tcmbGuncelle(array $kul, ?callable $getir = null): string
    {
        $getir ??= function (): string {
            $ctx = stream_context_create(['http' => ['timeout' => 6], 'ssl' => ['verify_peer' => true]]);
            $r = @file_get_contents(self::TCMB_URL, false, $ctx);
            if ($r === false) throw new IsHatasi('TCMB\'ye ulaşılamadı. Kuru elle girebilirsiniz.');
            return $r;
        };
        $p = self::tcmbParse($getir());
        foreach ($p['kurlar'] as $kod => $k) self::kaydet($p['tarih'], $kod, $k, 'tcmb', $kul);
        return $p['tarih'];
    }

    /** En güncel kur (verilen tarihte veya öncesinde). @return array{tarih:string, kur4:int, kaynak:string}|null */
    public static function son(string $kod, ?string $tarih = null): ?array
    {
        $r = Db::one('SELECT tarih, satis, kaynak FROM kurlar WHERE kod = ? AND tarih <= ? ORDER BY tarih DESC LIMIT 1', [$kod, $tarih ?? date('Y-m-d')]);
        return $r ? ['tarih' => $r['tarih'], 'kur4' => Hesap::fiyatDb((string)$r['satis']), 'kaynak' => $r['kaynak']] : null;
    }

    /** Döviz tutarı (kuruş/cent) → TL kuruş. Yarım yukarı; negatif tutarlarda simetrik. */
    public static function tlKarsiligi(int $dovizKurus, int $kur4): int
    {
        $n = $dovizKurus < 0;
        $v = Hesap::bolRound(abs($dovizKurus) * $kur4, 10000);
        return $n ? -$v : $v;
    }
}
