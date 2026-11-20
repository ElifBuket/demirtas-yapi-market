<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Db;
use App\Core\Http;
use App\Core\HttpException;
use App\Core\View;
use App\Services\{CariKart, Hesap, IsHatasi, Kur, Satis};

final class CariController
{
    private const PER_PAGE = 25;

    public static function index(): void
    {
        $q = trim((string)($_GET['q'] ?? ''));
        $q = mb_substr($q, 0, 60);
        $tip = in_array($_GET['tip'] ?? '', ['musteri', 'tedarikci'], true) ? $_GET['tip'] : '';
        $sayfa = max(1, (int)($_GET['sayfa'] ?? 1));

        $where = ['1=1'];
        $params = [];
        if ($q !== '') {
            // LIKE joker karakterleri kullanıcı girdisinden kaçırılır; değer yine parametre olarak gider.
            $like = '%' . strtr($q, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%';
            $where[] = '(c.unvan LIKE ? OR c.kod LIKE ? OR c.vkn LIKE ?)';
            array_push($params, $like, $like, $like);
        }
        if ($tip !== '') {
            $where[] = 'c.tip = ?';
            $params[] = $tip;
        }
        // Satışçı yalnızca kendi müşterilerini görür (sunucu tarafı kısıt)
        $kisit = Auth::satisciKisiti();
        if ($kisit !== null) {
            $where[] = 'c.satisci_id = ?';
            $params[] = $kisit;
        }
        $w = implode(' AND ', $where);

        $toplam = (int)Db::value("SELECT COUNT(*) FROM cariler c WHERE $w", $params);
        $sayfaSayisi = max(1, (int)ceil($toplam / self::PER_PAGE));
        $sayfa = min($sayfa, $sayfaSayisi);
        $offset = ($sayfa - 1) * self::PER_PAGE;

        $satirlar = Db::all(
            "SELECT c.id, c.kod, c.unvan, c.tip, c.il, c.para_birimi, c.durum, c.uyari_notu, c.risk_limiti, c.risk_limitsiz,
                    s.ad AS satisci,
                    COALESCE((SELECT SUM(h.borc) - SUM(h.alacak) FROM cari_hareketler h WHERE h.cari_id = c.id), 0) AS bakiye
             FROM cariler c LEFT JOIN satiscilar s ON s.id = c.satisci_id
             WHERE $w ORDER BY c.kod LIMIT " . self::PER_PAGE . " OFFSET " . (int)$offset,
            $params
        );

        View::render('cariler/liste', [
            'baslik' => 'Cari hesaplar', 'satirlar' => $satirlar, 'q' => $q, 'tip' => $tip,
            'sayfa' => $sayfa, 'sayfa_sayisi' => $sayfaSayisi, 'toplam' => $toplam,
        ]);
    }

    // ---------- Cari kartı ----------
    private static function bul(int $id): array
    {
        $c = Db::one('SELECT c.*, s.ad AS satisci FROM cariler c LEFT JOIN satiscilar s ON s.id = c.satisci_id WHERE c.id = ?', [$id]);
        if (!$c) throw new HttpException(404);
        $k = Auth::satisciKisiti();
        if ($k !== null && (int)$c['satisci_id'] !== $k) throw new HttpException(404); // varlığı bile sızmasın
        return $c;
    }

    public static function goster(): void
    {
        $c = self::bul((int)($_GET['id'] ?? 0));
        $id = (int)$c['id']; $bugun = date('Y-m-d');
        $bakiye = Satis::bakiye($id);
        $acik = Satis::acikSiparis($id);
        $kur = $c['para_birimi'] !== 'TRY' ? Kur::son($c['para_birimi']) : null;
        View::render('cariler/kart', [
            'baslik' => $c['unvan'], 'c' => $c, 'bakiye' => $bakiye, 'acik_siparis' => $acik,
            'kur' => $kur, 'tl_karsiligi' => $kur ? Kur::tlKarsiligi($bakiye, $kur['kur4']) : null,
            'yas' => CariKart::yaslandirma($id, $bugun),
            'hareketler' => Db::all('SELECT tarih, tur, borc, alacak, aciklama FROM cari_hareketler WHERE cari_id = ? ORDER BY tarih DESC, id DESC LIMIT 10', [$id]),
            'siparisler' => Db::all('SELECT id, belge_no, tarih, durum, toplam FROM siparisler WHERE cari_id = ? ORDER BY id DESC LIMIT 5', [$id]),
        ]);
    }

    public static function duzenleForm(): void
    {
        self::formuCiz(self::bul((int)($_GET['id'] ?? 0)));
    }

    private static function formuCiz(array $c, ?string $hata = null, array $deger = []): void
    {
        View::render('cariler/duzenle', [
            'baslik' => 'Cari kartını düzenle', 'c' => $c, 'hata' => $hata, 'deger' => $deger ?: $c,
            'satiscilar' => Db::all('SELECT id, ad FROM satiscilar ORDER BY ad'), 'patron' => (Auth::user()['rol'] ?? '') === 'patron',
        ], $hata ? 422 : 200);
    }

    public static function duzenle(): void
    {
        $c = self::bul((int)($_POST['id'] ?? 0));
        $g = [];
        foreach (['unvan', 'vkn', 'vergi_dairesi', 'telefon', 'eposta', 'il', 'ilce', 'satisci_id', 'durum', 'uyari_notu', 'risk_mod', 'risk_limiti'] as $a) $g[$a] = (string)($_POST[$a] ?? '');
        try { $degisen = CariKart::guncelle((int)$c['id'], $g, Auth::user()); }
        catch (IsHatasi $e) { self::formuCiz($c, $e->getMessage(), $g); return; }
        flash_koy('basari', $degisen ? 'Cari kartı güncellendi (' . count($degisen) . ' alan).' : 'Değişiklik yok.');
        Http::redirect('/cariler/goster?id=' . $c['id']);
    }

    // ---------- Ekstre ----------
    public static function ekstre(): void
    {
        $c = self::bul((int)($_GET['id'] ?? 0));
        $tarih = function (string $s, string $varsayilan): string {
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $s);
            return ($d && $d->format('Y-m-d') === $s) ? $s : $varsayilan;
        };
        $bit = $tarih((string)($_GET['bit'] ?? ''), date('Y-m-d'));
        $bas = $tarih((string)($_GET['bas'] ?? ''), date('Y-01-01'));
        if ($bas > $bit) [$bas, $bit] = [$bit, $bas];
        View::render('cariler/ekstre', ['baslik' => 'Cari ekstre', 'c' => $c, 'bas' => $bas, 'bit' => $bit, 'e' => CariKart::ekstre((int)$c['id'], $bas, $bit)]);
    }

    // ---------- Döviz kuru ----------
    public static function kurGuncelle(): void
    {
        $donus = '/cariler/goster?id=' . (int)($_POST['id'] ?? 0);
        try { $t = Kur::tcmbGuncelle(Auth::user()); flash_koy('basari', 'TCMB kuru güncellendi (' . date('d.m.Y', strtotime($t)) . ').'); }
        catch (IsHatasi $e) { flash_koy('hata', $e->getMessage()); }
        Http::redirect($donus);
    }

    public static function kurElle(): void
    {
        $donus = '/cariler/goster?id=' . (int)($_POST['id'] ?? 0);
        try {
            $kod = (string)($_POST['kod'] ?? 'EUR');
            $k = Hesap::fiyatParse(trim((string)($_POST['kur'] ?? ''))) ?? throw new IsHatasi('Kur geçersiz. Örnek: 36,6789');
            Kur::kaydet(date('Y-m-d'), $kod, $k, 'elle', Auth::user());
            flash_koy('basari', 'Kur elle kaydedildi.');
        } catch (IsHatasi $e) { flash_koy('hata', $e->getMessage()); }
        Http::redirect($donus);
    }
}
