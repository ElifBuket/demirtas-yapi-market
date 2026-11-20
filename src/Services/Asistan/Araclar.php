<?php
declare(strict_types=1);

namespace App\Services\Asistan;

use App\Core\{Db, Roles};
use App\Services\{Birim, Hesap, Rapor, Satis, Stok};

/**
 * Asistanın kullanabildiği TEK araç kümesi: hepsi salt okunur, sabit sorgulu ve yetki denetimli.
 * Asistana serbest SQL verilmez; yazan/silen hiçbir araç yoktur. Bağlantı ayrıca okunur modda açılır (Db::okunurModda).
 */
final class Araclar
{
    private const LIMIT = 10;

    /** @return list<array{name:string, description:string, input_schema:array, izin:string}> */
    public static function tanimlar(): array
    {
        $metin = fn(string $aciklama) => ['type' => 'object', 'properties' => ['sorgu' => ['type' => 'string', 'description' => $aciklama]], 'required' => ['sorgu']];
        $bos = ['type' => 'object', 'properties' => new \stdClass()];
        return [
            ['name' => 'cari_ara', 'description' => 'Cari hesabı ünvan, cari kodu veya vergi numarasıyla arar; bakiyesini listeler (+ müşteri bize borçlu).', 'input_schema' => $metin('Ünvanın bir parçası, kod veya VKN'), 'izin' => 'cari.goruntule'],
            ['name' => 'cari_ozet', 'description' => 'Tek bir carinin bakiye, açık sipariş, risk limiti ve geciken fatura özetini verir.', 'input_schema' => ['type' => 'object', 'properties' => ['cari_id' => ['type' => 'integer']], 'required' => ['cari_id']], 'izin' => 'cari.goruntule'],
            ['name' => 'stok_ara', 'description' => 'Ürünü ad, stok kodu veya barkodla arar; depo bazında güncel stoğu gösterir.', 'input_schema' => $metin('Ürün adı parçası, stok kodu veya barkod'), 'izin' => 'stok.goruntule'],
            ['name' => 'kritik_stok', 'description' => 'Kritik stok seviyesinin altındaki/eşitindeki ürünleri listeler.', 'input_schema' => $bos, 'izin' => 'stok.goruntule'],
            ['name' => 'geciken_faturalar', 'description' => 'Vadesi geçmiş ve ödenmemiş faturaları, en çok gecikenden başlayarak listeler.', 'input_schema' => $bos, 'izin' => 'rapor.goruntule'],
            ['name' => 'siparis_sorgula', 'description' => 'Sipariş durumunu sipariş numarasıyla (S-2026-000001) veya cari koduyla sorgular.', 'input_schema' => $metin('Sipariş numarası veya cari kodu'), 'izin' => 'siparis.goruntule'],
            ['name' => 'gunluk_ozet', 'description' => 'Bugünkü ve aylık satış, toplam alacak, kritik stok ve onay bekleyen sipariş özeti.', 'input_schema' => $bos, 'izin' => 'rapor.goruntule'],
            ['name' => 'en_borclu_musteriler', 'description' => 'Bakiyesi (bize borcu) en yüksek müşterileri sıralar. adet: 1-20 (varsayılan 5). Bakiye güncel durumdur, "bu ay" filtresi yoktur.', 'input_schema' => ['type' => 'object', 'properties' => ['adet' => ['type' => 'integer', 'description' => '1-20']]], 'izin' => 'rapor.goruntule'],
            ['name' => 'satis_karsilastir', 'description' => 'Bir ürün (ad/kod parçası) için son 7 gün ile önceki 7 günün sevk (irsaliye) miktarını karşılaştırır.', 'input_schema' => $metin('Ürün adı parçası, örn. çimento'), 'izin' => 'rapor.goruntule'],
        ];
    }

    public static function izinli(string $ad, array $kul): bool
    {
        foreach (self::tanimlar() as $t) if ($t['name'] === $ad) return Roles::can($kul['rol'] ?? '', $t['izin']);
        return false;
    }

    /** @return array<string,mixed> araç çıktısı (JSON'a çevrilebilir); hata varsa ['hata' => ...] */
    public static function calistir(string $ad, array $arg, array $kul): array
    {
        if (!self::izinli($ad, $kul)) return ['hata' => 'Bu bilgiye erişim yetkiniz yok.'];
        $bugun = date('Y-m-d');
        $kisit = ($kul['rol'] ?? '') === Roles::SATISCI ? (int)($kul['satisci_id'] ?? 0) : null;
        $sorgu = mb_substr(trim((string)($arg['sorgu'] ?? '')), 0, 60);
        $like = '%' . strtr($sorgu, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%';
        $tl = fn(int $k) => Hesap::goster($k) . ' ₺';
        switch ($ad) {
            case 'cari_ara':
                if ($sorgu === '') return ['hata' => 'Arama metni boş.'];
                $w = '(c.unvan LIKE ? OR c.kod LIKE ? OR c.vkn LIKE ?)'; $p = [$like, $like, $like];
                if ($kisit !== null) { $w .= ' AND c.satisci_id = ?'; $p[] = $kisit; }
                $r = Db::all("SELECT c.id, c.kod, c.unvan, c.tip, c.para_birimi, c.durum, COALESCE((SELECT SUM(h.borc)-SUM(h.alacak) FROM cari_hareketler h WHERE h.cari_id = c.id),0) AS bakiye FROM cariler c WHERE $w ORDER BY c.unvan LIMIT " . self::LIMIT, $p);
                return ['sonuc' => array_map(fn($x) => ['id' => (int)$x['id'], 'kod' => $x['kod'], 'unvan' => $x['unvan'], 'tip' => $x['tip'], 'durum' => $x['durum'], 'bakiye' => Hesap::goster(Hesap::kurus((string)$x['bakiye'])) . ($x['para_birimi'] === 'EUR' ? ' €' : ' ₺')], $r), 'not' => count($r) === self::LIMIT ? 'İlk ' . self::LIMIT . ' sonuç gösterildi.' : null];
            case 'cari_ozet':
                $id = (int)($arg['cari_id'] ?? 0);
                $c = Db::one('SELECT * FROM cariler WHERE id = ?', [$id]);
                if (!$c || ($kisit !== null && (int)$c['satisci_id'] !== $kisit)) return ['hata' => 'Cari bulunamadı.'];
                $y = \App\Services\CariKart::yaslandirma($id, $bugun);
                $geciken = $y['g0_30'] + $y['g31_60'] + $y['g61_90'] + $y['g90_ustu'];
                return ['kod' => $c['kod'], 'unvan' => $c['unvan'], 'tip' => $c['tip'], 'durum' => $c['durum'], 'para_birimi' => $c['para_birimi'],
                    'bakiye' => Hesap::goster(Satis::bakiye($id)), 'acik_siparis' => Hesap::goster(Satis::acikSiparis($id)),
                    'risk_limiti' => (int)$c['risk_limitsiz'] ? 'limitsiz' : ($c['risk_limiti'] === null ? 'tanımsız' : Hesap::goster(Hesap::kurus((string)$c['risk_limiti']))),
                    'geciken_fatura_toplami' => Hesap::goster($geciken), 'uyari_notu' => $c['uyari_notu']];
            case 'stok_ara':
                if ($sorgu === '') return ['hata' => 'Arama metni boş.'];
                $r = Db::all("SELECT u.id, u.stok_kodu, u.ad, u.temel_birim, u.kritik_stok FROM urunler u WHERE u.aktif = 1 AND (u.ad LIKE ? OR u.stok_kodu LIKE ? OR u.barkod LIKE ?) ORDER BY u.stok_kodu LIMIT " . self::LIMIT, [$like, $like, $like]);
                return ['sonuc' => array_map(fn($u) => ['stok_kodu' => $u['stok_kodu'], 'ad' => $u['ad'], 'birim' => $u['temel_birim'], 'merkez_depo' => Birim::goster(Stok::seviye((int)$u['id'], 1)), 'osb_depo' => Birim::goster(Stok::seviye((int)$u['id'], 2)), 'toplam' => Birim::goster(Stok::seviye((int)$u['id'])), 'kritik_seviye' => $u['kritik_stok'] === null ? null : miktar($u['kritik_stok'])], $r)];
            case 'kritik_stok':
                $r = Db::all("SELECT t.stok_kodu, t.ad, t.temel_birim, t.kritik_stok, t.toplam FROM (SELECT u.stok_kodu, u.ad, u.temel_birim, u.kritik_stok, COALESCE(SUM(h.miktar),0) AS toplam FROM urunler u LEFT JOIN stok_hareketleri h ON h.urun_id = u.id WHERE u.aktif = 1 AND u.kritik_stok IS NOT NULL GROUP BY u.id) t WHERE t.toplam <= t.kritik_stok ORDER BY t.toplam - t.kritik_stok LIMIT " . self::LIMIT);
                return ['adet' => Stok::kritikSayisi(), 'sonuc' => array_map(fn($u) => ['stok_kodu' => $u['stok_kodu'], 'ad' => $u['ad'], 'mevcut' => miktar($u['toplam']) . ' ' . $u['temel_birim'], 'kritik_seviye' => miktar($u['kritik_stok'])], $r)];
            case 'geciken_faturalar':
                $r = Db::all("SELECT f.belge_no, f.vade_tarihi, c.unvan, f.toplam - COALESCE((SELECT SUM(d.tutar) FROM tahsilat_dagitim d WHERE d.fatura_id = f.id),0) AS kalan FROM faturalar f JOIN cariler c ON c.id = f.cari_id WHERE f.para_birimi = 'TRY' AND f.vade_tarihi < ? HAVING kalan > 0 ORDER BY f.vade_tarihi LIMIT " . self::LIMIT, [$bugun]);
                return ['sonuc' => array_map(fn($f) => ['fatura' => $f['belge_no'], 'musteri' => $f['unvan'], 'vade' => $f['vade_tarihi'], 'gecikme_gun' => (int)((strtotime($bugun) - strtotime($f['vade_tarihi'])) / 86400), 'kalan' => $tl(Hesap::kurus((string)$f['kalan']))], $r)];
            case 'siparis_sorgula':
                if ($sorgu === '') return ['hata' => 'Sipariş numarası veya cari kodu girin.'];
                $w = '(s.belge_no = ? OR c.kod = ?)'; $p = [$sorgu, $sorgu];
                if ($kisit !== null) { $w .= ' AND c.satisci_id = ?'; $p[] = $kisit; }
                $r = Db::all("SELECT s.belge_no, s.tarih, s.durum, s.toplam, c.unvan FROM siparisler s JOIN cariler c ON c.id = s.cari_id WHERE $w ORDER BY s.id DESC LIMIT " . self::LIMIT, $p);
                return ['sonuc' => array_map(fn($s) => ['siparis' => $s['belge_no'], 'musteri' => $s['unvan'], 'tarih' => $s['tarih'], 'durum' => durum_ad($s['durum']), 'toplam' => tl($s['toplam'])], $r)];
            case 'gunluk_ozet':
                $o = Rapor::ozet($bugun);
                return ['bugunku_satis' => $tl($o['gunluk_satis']), 'aylik_satis' => $tl($o['aylik_satis']), 'toplam_alacak_TL' => $tl($o['alacak']), 'tedarikciye_borc' => $tl($o['borc']), 'portfoy_cek' => $tl($o['portfoy_cek']), 'kritik_stok_urun' => $o['kritik_stok'], 'onay_bekleyen_siparis' => $o['onay_bekleyen']];
            case 'en_borclu_musteriler':
                $n = max(1, min(20, (int)($arg['adet'] ?? 5)));
                return ['not' => 'Güncel bakiyeye göre sıralıdır (+ müşteri bize borçlu).', 'sonuc' => array_map(fn($x) => ['kod' => $x['kod'], 'musteri' => $x['unvan'], 'bakiye' => $tl(Hesap::kurus((string)$x['bakiye']))], Rapor::enBorclular($n))];
            case 'satis_karsilastir':
                if ($sorgu === '') return ['hata' => 'Ürün adı gerekli.'];
                $b = new \DateTimeImmutable($bugun);
                $s1 = $b->modify('-6 days')->format('Y-m-d'); $s0 = $b->modify('-13 days')->format('Y-m-d'); $e0 = $b->modify('-7 days')->format('Y-m-d');
                $r = Db::all("SELECT u.stok_kodu, u.ad, u.temel_birim, COALESCE(SUM(CASE WHEN i.tarih BETWEEN ? AND ? THEN k.miktar END),0) AS bu, COALESCE(SUM(CASE WHEN i.tarih BETWEEN ? AND ? THEN k.miktar END),0) AS onceki FROM urunler u JOIN irsaliye_kalemleri k ON k.urun_id = u.id JOIN irsaliyeler i ON i.id = k.irsaliye_id WHERE (u.ad LIKE ? OR u.stok_kodu LIKE ?) AND i.tarih BETWEEN ? AND ? GROUP BY u.id, u.stok_kodu, u.ad, u.temel_birim ORDER BY bu DESC LIMIT " . self::LIMIT, [$s1, $bugun, $s0, $e0, $like, $like, $s0, $bugun]);
                return ['not' => "Son 7 gün ($s1 – $bugun) ile önceki 7 gün ($s0 – $e0) sevk miktarı.", 'sonuc' => array_map(function ($x) {
                    $bu = (float)$x['bu']; $on = (float)$x['onceki'];
                    $d = $on > 0 ? sprintf('%+.1f%%', ($bu - $on) / $on * 100) : ($bu > 0 ? 'önceki hafta satış yok' : '—');
                    return ['urun' => $x['stok_kodu'] . ' ' . $x['ad'], 'son_7_gun' => rtrim(rtrim(number_format($bu, 3, ',', '.'), '0'), ',') . ' ' . $x['temel_birim'], 'onceki_7_gun' => rtrim(rtrim(number_format($on, 3, ',', '.'), '0'), ',') . ' ' . $x['temel_birim'], 'degisim' => $d];
                }, $r)];
        }
        return ['hata' => 'Bilinmeyen araç.'];
    }
}
