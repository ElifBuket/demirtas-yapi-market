<?php
declare(strict_types=1);

namespace App\Services\Asistan;

require_once dirname(__DIR__, 2) . '/Norm.php';

/** Yapay zekâ anahtarı olmadan çalışan anahtar kelime yönlendiricisi. Aynı salt okunur araçları kullanır. */
final class Yonlendirici
{
    private const DURAK = ['ne', 'kadar', 'var', 'mi', 'mu', 'midir', 'nedir', 'kac', 'adet', 'stok', 'stogu', 'stoku', 'bakiye', 'bakiyesi', 'borc', 'borcu', 'icin', 'bana', 'goster', 'soyle', 'listele', 'hangi', 'kim', 'kimin', 'durumu', 'siparis', 'siparisi', 'cari', 'urun', 'urunu', 'depoda', 'depo', 'kalan', 've', 'ile', 'bir', 'bu', 'su', 'da', 'de'];

    public static function cevapla(string $soru, array $kul): array
    {
        $n = \Norm::ascii($soru);
        if (Asistan::yazmaIstegi($soru)) {
            return Asistan::yanit('Ben yalnızca veri okuyabilen bir asistanım; kayıt ekleyemem, değiştiremem, silemem. Bu işlemi ilgili ekrandan (sipariş, tahsilat, stok, cari) yetkili kullanıcıyla yapabilirsiniz.', null, 'kural', []);
        }
        if (preg_match('/\b(S-\d{4}-\d{6})\b/i', $soru, $m)) return self::araclaSor('siparis_sorgula', ['sorgu' => strtoupper($m[1])], $kul);
        if (str_contains($n, 'kritik')) return self::araclaSor('kritik_stok', [], $kul);
        if (preg_match('/gecik|vadesi gec|vadesi ger/', $n)) return self::araclaSor('geciken_faturalar', [], $kul);
        if (preg_match('/\b(bugun|ozet|ciro)\b|satis(lar)? (ne|kac)|aylik/', $n) && !preg_match('/sto[kg]/', $n)) return self::araclaSor('gunluk_ozet', [], $kul);
        $terim = self::terim($n);
        if ($terim === '') return Asistan::yanit("Ne sormak istediğinizi anlayamadım. Örnekler:\n• Kritik stokta ne var?\n• Geciken faturalar hangileri?\n• Bugünkü satış ne kadar?\n• Çimento stoğu kaç?\n• Yıldız İnşaat bakiyesi ne kadar?\n• S-2026-000001 siparişi hangi durumda?", null, 'kural', []);
        if (preg_match('/sto[kg]|depoda|kac (adet|torba|kg|ton|m)|kalan/', $n)) return self::araclaSor('stok_ara', ['sorgu' => $terim], $kul);
        if (preg_match('/siparis/', $n)) return self::araclaSor('siparis_sorgula', ['sorgu' => $terim], $kul);
        return self::araclaSor('cari_ara', ['sorgu' => $terim], $kul);
    }

    private static function terim(string $n): string
    {
        $kelimeler = array_filter(preg_split('/[^a-z0-9\-]+/', $n) ?: [], fn($k) => $k !== '' && !in_array($k, self::DURAK, true));
        return implode(' ', $kelimeler);
    }

    private static function araclaSor(string $ad, array $arg, array $kul): array
    {
        $r = Araclar::calistir($ad, $arg, $kul);
        if (isset($r['hata'])) return Asistan::yanit($r['hata'], null, 'kural', [$ad]);
        if (isset($r['sonuc'])) {
            if (!$r['sonuc']) return Asistan::yanit('Eşleşen kayıt bulunamadı.', null, 'kural', [$ad]);
            $bas = array_keys($r['sonuc'][0]);
            $metin = count($r['sonuc']) . ' kayıt bulundu.' . (isset($r['adet']) ? ' Toplam kritik ürün: ' . $r['adet'] . '.' : '') . (!empty($r['not']) ? ' ' . $r['not'] : '');
            return Asistan::yanit($metin, ['basliklar' => $bas, 'satirlar' => array_map(fn($s) => array_map(fn($v) => (string)($v ?? ''), array_values($s)), $r['sonuc'])], 'kural', [$ad]);
        }
        $satirlar = []; foreach ($r as $k => $v) $satirlar[] = [str_replace('_', ' ', (string)$k), (string)($v ?? '')];
        return Asistan::yanit('', ['basliklar' => ['Alan', 'Değer'], 'satirlar' => $satirlar], 'kural', [$ad]);
    }
}
