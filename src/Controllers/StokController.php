<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Db;
use App\Core\Http;
use App\Core\HttpException;
use App\Core\View;
use App\Services\Birim;
use App\Services\Stok;
use App\Services\StokHatasi;

final class StokController
{
    private const PER_PAGE = 25;

    public static function index(): void
    {
        $q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 60);
        $kritik = ($_GET['kritik'] ?? '') === '1';
        $kategori = mb_substr(trim((string)($_GET['kategori'] ?? '')), 0, 40);
        $tarih = self::tarihOku((string)($_GET['tarih'] ?? ''));
        $sayfa = max(1, (int)($_GET['sayfa'] ?? 1));
        $depolar = Db::all('SELECT id, kod, ad FROM depolar ORDER BY id');

        $where = ['u.aktif = 1'];
        $params = [$tarih ?? date('Y-m-d')];
        if ($q !== '') {
            $like = '%' . strtr($q, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%';
            $where[] = '(u.ad LIKE ? OR u.stok_kodu LIKE ? OR u.barkod LIKE ?)';
            array_push($params, $like, $like, $like);
        }
        if ($kategori !== '') { $where[] = 'u.kategori = ?'; $params[] = $kategori; }
        $w = implode(' AND ', $where);

        $depoKolon = '';
        foreach ($depolar as $d) {
            $depoKolon .= ', COALESCE(SUM(CASE WHEN h.depo_id = ' . (int)$d['id'] . ' THEN h.miktar END), 0) AS d' . (int)$d['id'];
        }
        $inner = "SELECT u.id, u.stok_kodu, u.ad, u.kategori, u.temel_birim, u.kritik_stok $depoKolon,
                         COALESCE(SUM(h.miktar), 0) AS toplam
                  FROM urunler u LEFT JOIN stok_hareketleri h ON h.urun_id = u.id AND h.tarih <= ?
                  WHERE $w GROUP BY u.id";
        $filtre = $kritik ? ' WHERE t.kritik_stok IS NOT NULL AND t.toplam <= t.kritik_stok' : '';

        $toplam = (int)Db::value("SELECT COUNT(*) FROM ($inner) t $filtre", $params);
        $sayfaSayisi = max(1, (int)ceil($toplam / self::PER_PAGE));
        $sayfa = min($sayfa, $sayfaSayisi);
        $satirlar = Db::all("SELECT t.* FROM ($inner) t $filtre ORDER BY t.stok_kodu LIMIT " . self::PER_PAGE . ' OFFSET ' . (($sayfa - 1) * self::PER_PAGE), $params);
        $kategoriler = array_column(Db::all('SELECT DISTINCT kategori FROM urunler WHERE aktif = 1 ORDER BY kategori'), 'kategori');

        View::render('stok/liste', [
            'baslik' => 'Stok', 'satirlar' => $satirlar, 'depolar' => $depolar, 'q' => $q, 'kritik' => $kritik, 'kategori' => $kategori,
            'kategoriler' => $kategoriler, 'tarih' => $tarih, 'sayfa' => $sayfa, 'sayfa_sayisi' => $sayfaSayisi, 'toplam' => $toplam,
        ]);
    }

    public static function hareket(): void
    {
        $urun = self::urunBul((int)($_GET['urun'] ?? 0));
        $depolar = Db::all('SELECT id, kod, ad FROM depolar ORDER BY id');
        $seviyeler = [];
        foreach ($depolar as $d) $seviyeler[(int)$d['id']] = Stok::seviye($urun['id'], (int)$d['id']);
        $satirlar = Db::all(
            'SELECT h.id, h.tarih, d.ad AS depo, h.tur, h.miktar, h.aciklama FROM stok_hareketleri h JOIN depolar d ON d.id = h.depo_id
             WHERE h.urun_id = ? ORDER BY h.tarih DESC, h.id DESC LIMIT 200', [$urun['id']]);
        View::render('stok/hareket', ['baslik' => $urun['ad'], 'urun' => $urun, 'depolar' => $depolar, 'seviyeler' => $seviyeler, 'satirlar' => $satirlar]);
    }

    public static function transferForm(): void
    {
        self::formuCiz('transfer', self::urunBul((int)($_GET['urun'] ?? 0)));
    }

    public static function transfer(): void
    {
        $urun = self::urunBul((int)($_POST['urun_id'] ?? 0));
        $kaynak = (int)($_POST['kaynak_depo'] ?? 0);
        $hedef = (int)($_POST['hedef_depo'] ?? 0);
        $birim = (string)($_POST['birim'] ?? '');
        $miktarMetin = (string)($_POST['miktar'] ?? '');
        $aciklama = mb_substr(trim((string)($_POST['aciklama'] ?? '')), 0, 150);
        try {
            $milli = Stok::miktarTemele($urun, $miktarMetin, $birim);
            Stok::transfer($urun['id'], $kaynak, $hedef, $milli, (string)($_POST['anahtar'] ?? ''), date('Y-m-d'), $aciklama, Auth::user());
        } catch (StokHatasi $e) {
            self::formuCiz('transfer', $urun, $e->getMessage(), ['kaynak_depo' => $kaynak, 'hedef_depo' => $hedef, 'birim' => $birim, 'miktar' => $miktarMetin, 'aciklama' => $aciklama]);
            return;
        }
        flash_koy('basari', 'Transfer kaydedildi: ' . $miktarMetin . ' ' . $birim . ' ' . $urun['stok_kodu'] . '.');
        Http::redirect('/stok/hareket?urun=' . $urun['id']);
    }

    public static function sayimForm(): void
    {
        self::formuCiz('sayim', self::urunBul((int)($_GET['urun'] ?? 0)));
    }

    public static function sayim(): void
    {
        $urun = self::urunBul((int)($_POST['urun_id'] ?? 0));
        $depo = (int)($_POST['depo'] ?? 0);
        $birim = (string)($_POST['birim'] ?? '');
        $miktarMetin = (string)($_POST['miktar'] ?? '');
        $aciklama = mb_substr(trim((string)($_POST['aciklama'] ?? '')), 0, 150);
        try {
            $milli = Stok::miktarTemele($urun, $miktarMetin, $birim, true);
            $fark = Stok::sayim($urun['id'], $depo, $milli, (string)($_POST['anahtar'] ?? ''), date('Y-m-d'), $aciklama, Auth::user());
        } catch (StokHatasi $e) {
            self::formuCiz('sayim', $urun, $e->getMessage(), ['depo' => $depo, 'birim' => $birim, 'miktar' => $miktarMetin, 'aciklama' => $aciklama]);
            return;
        }
        flash_koy('basari', $fark === 0 ? 'Sayım sistemdeki stokla aynı, kayıt eklenmedi.' : 'Sayım kaydedildi. Fark: ' . ($fark > 0 ? '+' : '') . Birim::goster($fark) . ' ' . $urun['temel_birim'] . '.');
        Http::redirect('/stok/hareket?urun=' . $urun['id']);
    }

    // ---------- yardımcılar ----------

    private static function urunBul(int $id): array
    {
        $u = $id > 0 ? Stok::urun($id) : null;
        if ($u === null) throw new HttpException(404);
        return $u;
    }

    private static function tarihOku(string $s): ?string
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $s);
        if ($d === false || $d->format('Y-m-d') !== $s) return null;
        return $d->format('Y-m-d') > date('Y-m-d') ? date('Y-m-d') : $d->format('Y-m-d');
    }

    private static function formuCiz(string $tur, array $urun, ?string $hata = null, array $deger = []): void
    {
        $depolar = Db::all('SELECT id, kod, ad FROM depolar ORDER BY id');
        $seviyeler = [];
        foreach ($depolar as $d) $seviyeler[(int)$d['id']] = Stok::seviye($urun['id'], (int)$d['id']);
        View::render('stok/' . $tur, [
            'baslik' => $tur === 'transfer' ? 'Depo transferi' : 'Stok sayımı', 'urun' => $urun, 'depolar' => $depolar, 'seviyeler' => $seviyeler,
            'birimler' => array_keys(Stok::birimler($urun)), 'hata' => $hata, 'deger' => $deger, 'anahtar' => bin2hex(random_bytes(16)),
        ], $hata ? 422 : 200);
    }
}
