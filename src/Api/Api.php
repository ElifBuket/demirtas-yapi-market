<?php
declare(strict_types=1);

namespace App\Api;

use App\Core\{Audit, Auth, Db, Http, Roles};
use App\Services\{Birim, Hesap, IsHatasi, Satis, Stok, StokHatasi};

/**
 * Mobil uygulama API'si (/api/v1). Çerez/oturum yok: "Authorization: Bearer <jeton>" ile kimlik doğrulanır.
 * CSRF jetonu gerekmez çünkü tarayıcı jetonu kendiliğinden eklemez (CSRF yalnızca çerez kimliği için geçerli bir saldırıdır).
 * Yetki ve satışçı kısıtı web ile aynı kurallarla SUNUCUDA denetlenir; istemciye güvenilmez.
 */
final class Api
{
    private const JETON_GUN = 30;
    private const TOPLU_LIMIT = 50;

    public static function calistir(): void
    {
        Http::securityHeaders();
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        try {
            $yontem = $_SERVER['REQUEST_METHOD'] ?? 'GET';
            $yol = Http::path();
            $rota = $yontem . ' ' . $yol;
            if ($rota === 'POST /api/v1/giris') { self::giris(); return; }
            $kul = self::kimlik();
            switch ($rota) {
                case 'POST /api/v1/cikis': self::cikis($kul); return;
                case 'GET /api/v1/senkron': self::senkron($kul); return;
                case 'GET /api/v1/siparisler': self::siparisler($kul); return;
                case 'POST /api/v1/siparisler': self::siparisGonder($kul); return;
            }
            self::cikti(['hata' => 'Bulunamadı.'], 404);
        } catch (\Throwable $e) {
            error_log('[demirtas-api] ' . $e::class . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            self::cikti(['hata' => 'Beklenmeyen bir hata oluştu.'], 500);
        }
    }

    private static function cikti(array $veri, int $kod = 200): never
    {
        http_response_code($kod);
        echo json_encode($veri, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    private static function govde(): array
    {
        $ham = file_get_contents('php://input', false, null, 0, 1048577);
        if ($ham === false || strlen($ham) > 1048576) self::cikti(['hata' => 'İstek çok büyük.'], 413);
        $j = json_decode($ham, true);
        if (!is_array($j)) self::cikti(['hata' => 'Geçerli JSON gönderin.'], 400);
        return $j;
    }

    // ---------- kimlik ----------
    private static function giris(): void
    {
        $g = self::govde();
        $r = Auth::apiDogrula((string)($g['kullanici_adi'] ?? ''), (string)($g['parola'] ?? ''));
        if (isset($r['hata'])) self::cikti(['hata' => $r['hata']], str_contains($r['hata'], 'Çok fazla') ? 429 : 401);
        $u = $r['user'];
        if (!Roles::can($u['rol'], 'siparis.olustur')) self::cikti(['hata' => 'Bu rol mobil sipariş uygulamasını kullanamaz.'], 403);
        $jeton = 'dym_' . bin2hex(random_bytes(32));
        $bitis = (new \DateTimeImmutable())->modify('+' . self::JETON_GUN . ' days')->format('Y-m-d H:i:s');
        Db::run('INSERT INTO api_tokenlari (kullanici_id, token_hash, cihaz, bitis) VALUES (?,?,?,?)', [$u['id'], hash('sha256', $jeton), mb_substr((string)($g['cihaz'] ?? ''), 0, 60) ?: null, $bitis]);
        Db::run('UPDATE kullanicilar SET son_giris = NOW() WHERE id = ?', [$u['id']]);
        Audit::log('giris', 'kullanicilar', $u['id'], null, ['kanal' => 'mobil'], 'Mobil giriş', $u);
        self::cikti(['token' => $jeton, 'bitis' => $bitis, 'kullanici' => ['ad_soyad' => $u['ad_soyad'], 'rol' => $u['rol']]]);
    }

    /** @return array{id:int,kullanici_adi:string,rol:string,satisci_id:?int,token_id:int} */
    private static function kimlik(): array
    {
        $h = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if (!preg_match('/^Bearer (dym_[0-9a-f]{64})$/', (string)$h, $m)) self::cikti(['hata' => 'Giriş gerekli.'], 401);
        // Kullanıcı pasife alındıysa jeton anında geçersizdir (her istekte veritabanından okunur).
        $r = Db::one('SELECT t.id AS token_id, u.id, u.kullanici_adi, u.ad_soyad, u.rol, u.satisci_id FROM api_tokenlari t JOIN kullanicilar u ON u.id = t.kullanici_id WHERE t.token_hash = ? AND t.iptal = 0 AND t.bitis > NOW() AND u.aktif = 1', [hash('sha256', $m[1])]);
        if (!$r) self::cikti(['hata' => 'Oturum geçersiz veya süresi dolmuş. Yeniden giriş yapın.'], 401);
        if (!Roles::can($r['rol'], 'siparis.olustur')) self::cikti(['hata' => 'Yetkiniz yok.'], 403);
        Db::run('UPDATE api_tokenlari SET son_kullanim = NOW() WHERE id = ?', [$r['token_id']]);
        $r['id'] = (int)$r['id']; $r['token_id'] = (int)$r['token_id']; $r['satisci_id'] = $r['satisci_id'] === null ? null : (int)$r['satisci_id'];
        return $r;
    }

    private static function cikis(array $kul): void
    {
        Db::run('UPDATE api_tokenlari SET iptal = 1 WHERE id = ?', [$kul['token_id']]);
        Audit::log('cikis', 'kullanicilar', $kul['id'], null, ['kanal' => 'mobil'], 'Mobil çıkış', $kul);
        self::cikti(['tamam' => true]);
    }

    private static function satisciKisiti(array $kul): ?int
    {
        return $kul['rol'] === Roles::SATISCI ? (int)($kul['satisci_id'] ?? 0) : null;
    }

    // ---------- veri ----------
    /** Çevrimdışı çalışmak için telefona indirilen önbellek: müşteriler + ürünler. */
    private static function senkron(array $kul): void
    {
        $k = self::satisciKisiti($kul);
        $w = "c.tip = 'musteri'"; $p = [];
        if ($k !== null) { $w .= ' AND c.satisci_id = ?'; $p[] = $k; }
        $musteriler = array_map(fn($c) => [
            'id' => (int)$c['id'], 'kod' => $c['kod'], 'unvan' => $c['unvan'], 'il' => $c['il'], 'telefon' => $c['telefon'], 'para_birimi' => $c['para_birimi'], 'durum' => $c['durum'],
            'uyari_notu' => $c['uyari_notu'], 'bakiye' => Hesap::dec(Hesap::kurus((string)$c['bakiye'])),
            'risk' => (int)$c['risk_limitsiz'] ? 'limitsiz' : ($c['risk_limiti'] === null ? 'tanimsiz' : Hesap::dec(Hesap::kurus((string)$c['risk_limiti']))),
        ], Db::all("SELECT c.id, c.kod, c.unvan, c.il, c.telefon, c.para_birimi, c.durum, c.uyari_notu, c.risk_limiti, c.risk_limitsiz, COALESCE((SELECT SUM(h.borc)-SUM(h.alacak) FROM cari_hareketler h WHERE h.cari_id = c.id),0) AS bakiye FROM cariler c WHERE $w ORDER BY c.unvan", $p));
        $cevrim = [];
        foreach (Db::all('SELECT urun_id, alt_birim, pay, payda FROM urun_birim_cevrim') as $b) $cevrim[(int)$b['urun_id']][] = ['birim' => $b['alt_birim'], 'pay' => (int)$b['pay'], 'payda' => (int)$b['payda']];
        $urunler = array_map(fn($u) => [
            'id' => (int)$u['id'], 'stok_kodu' => $u['stok_kodu'], 'ad' => $u['ad'], 'birim' => $u['temel_birim'], 'kdv' => (int)$u['kdv_orani'],
            'fiyat' => $u['satis_fiyati'] === null ? null : (string)$u['satis_fiyati'], 'stok' => Birim::goster(Birim::fromDecimal((string)$u['stok'])),
            'ondalikli' => in_array($u['temel_birim'], Birim::ONDALIKLI, true), 'alt_birimler' => $cevrim[(int)$u['id']] ?? [],
        ], Db::all('SELECT u.id, u.stok_kodu, u.ad, u.temel_birim, u.kdv_orani, u.satis_fiyati, COALESCE((SELECT SUM(h.miktar) FROM stok_hareketleri h WHERE h.urun_id = u.id),0) AS stok FROM urunler u WHERE u.aktif = 1 AND u.satis_fiyati IS NOT NULL ORDER BY u.stok_kodu'));
        self::cikti(['sunucu_zamani' => date('c'), 'musteriler' => $musteriler, 'urunler' => $urunler]);
    }

    private static function siparisler(array $kul): void
    {
        $r = Db::all('SELECT s.belge_no, s.tarih, s.durum, s.toplam, c.unvan FROM siparisler s JOIN cariler c ON c.id = s.cari_id WHERE s.olusturan_id = ? ORDER BY s.id DESC LIMIT 30', [$kul['id']]);
        self::cikti(['siparisler' => array_map(fn($s) => ['belge_no' => $s['belge_no'], 'tarih' => $s['tarih'], 'durum' => $s['durum'], 'toplam' => (string)$s['toplam'], 'musteri' => $s['unvan']], $r)]);
    }

    // ---------- sipariş gönderimi (çevrimdışı kuyruktan) ----------
    private static function siparisGonder(array $kul): void
    {
        $g = self::govde();
        $liste = $g['siparisler'] ?? null;
        if (!is_array($liste) || !$liste || !array_is_list($liste)) self::cikti(['hata' => 'siparisler listesi gerekli.'], 422);
        if (count($liste) > self::TOPLU_LIMIT) self::cikti(['hata' => 'Tek seferde en çok ' . self::TOPLU_LIMIT . ' sipariş gönderilebilir.'], 422);
        $sonuclar = [];
        foreach ($liste as $s) $sonuclar[] = self::tekSiparis(is_array($s) ? $s : [], $kul);
        self::cikti(['sonuclar' => $sonuclar]);
    }

    private static function tekSiparis(array $s, array $kul): array
    {
        $anahtar = (string)($s['istemci_anahtar'] ?? '');
        $r = fn(string $durum, array $ek = []) => ['istemci_anahtar' => $anahtar, 'durum' => $durum] + $ek;
        if (!preg_match('/^[0-9a-f]{32}$/', $anahtar)) return $r('hata', ['mesaj' => 'İstemci anahtarı geçersiz (32 haneli onaltılık olmalı).', 'kalici' => true]);
        // Aynı anahtar daha önce işlendiyse: ikinci sipariş oluşturulmaz, mevcut sonuç aynen döner (idempotent).
        $var = self::mevcut($anahtar, $kul);
        if ($var !== null) return $var;
        try {
            $cariId = (int)($s['cari_id'] ?? 0);
            $k = self::satisciKisiti($kul);
            $cari = Db::one('SELECT id, satisci_id FROM cariler WHERE id = ?', [$cariId]);
            if (!$cari || ($k !== null && (int)$cari['satisci_id'] !== $k)) throw new IsHatasi('Müşteri bulunamadı veya size atanmamış.');
            $kalemler = $s['kalemler'] ?? null;
            if (!is_array($kalemler) || !$kalemler || count($kalemler) > 50) throw new IsHatasi('Siparişte 1-50 kalem olmalı.');
            $satirlar = []; $gorulen = [];
            foreach ($kalemler as $i => $kl) {
                $n = $i + 1; $kl = is_array($kl) ? $kl : [];
                $u = Db::one('SELECT id, stok_kodu, ad, temel_birim, satis_fiyati FROM urunler WHERE id = ? AND aktif = 1', [(int)($kl['urun_id'] ?? 0)]);
                if (!$u) throw new IsHatasi("Kalem $n: ürün bulunamadı veya pasif.");
                if ($u['satis_fiyati'] === null) throw new IsHatasi("Kalem $n: {$u['stok_kodu']} için satış fiyatı tanımlı değil.");
                $ud = ['id' => (int)$u['id'], 'stok_kodu' => $u['stok_kodu'], 'ad' => $u['ad'], 'temel_birim' => $u['temel_birim']];
                try { $milli = Stok::miktarTemele($ud, trim((string)($kl['miktar'] ?? '')), trim((string)($kl['birim'] ?? '')) ?: $u['temel_birim']); }
                catch (StokHatasi $e) { throw new IsHatasi("Kalem $n: " . $e->getMessage()); }
                if (isset($gorulen[$u['id']])) { $satirlar[$gorulen[$u['id']]]['miktar'] += $milli; continue; } // aynı ürün iki kez eklenmişse birleştir
                $gorulen[$u['id']] = count($satirlar);
                // Fiyat DAİMA sunucudaki güncel liste fiyatıdır; istemcinin gönderdiği fiyat dikkate alınmaz (manipülasyon yok).
                $satirlar[] = ['urun_id' => (int)$u['id'], 'miktar' => $milli, 'fiyat4' => Hesap::fiyatDb((string)$u['satis_fiyati']), 'iskonto_bp' => 0];
            }
            $tarih = self::tarih((string)($s['tarih'] ?? ''));
            $depo = (int)Db::value('SELECT MIN(id) FROM depolar');
            $y = Satis::siparisOlustur($cariId, $depo, $tarih, $satirlar, 0, $anahtar, $kul);
            $t = Db::value('SELECT toplam FROM siparisler WHERE id = ?', [$y['id']]);
            return $r('olustu', ['belge_no' => $y['belge_no'], 'siparis_durum' => $y['durum'], 'toplam' => (string)$t, 'mesaj' => $y['risk_notu']]);
        } catch (IsHatasi $e) {
            // Yarış: aynı anahtarla eşzamanlı ikinci istek kilit sırasında "zaten kaydedildi" görür → mevcut sonucu döndür.
            $var = self::mevcut($anahtar, $kul);
            if ($var !== null) return $var;
            return $r('hata', ['mesaj' => $e->getMessage(), 'kalici' => true]);
        } catch (\Throwable $e) {
            error_log('[demirtas-api] sipariş: ' . $e->getMessage());
            return $r('hata', ['mesaj' => 'Sunucu hatası; daha sonra tekrar denenecek.', 'kalici' => false]);
        }
    }

    private static function mevcut(string $anahtar, array $kul): ?array
    {
        $x = Db::one('SELECT belge_no, durum, toplam, olusturan_id FROM siparisler WHERE kaynak_key = ?', ['siparis:' . $anahtar]);
        if (!$x) return null;
        if ((int)$x['olusturan_id'] !== $kul['id']) return ['istemci_anahtar' => $anahtar, 'durum' => 'hata', 'mesaj' => 'Anahtar çakışması.', 'kalici' => true];
        return ['istemci_anahtar' => $anahtar, 'durum' => 'zaten_var', 'belge_no' => $x['belge_no'], 'siparis_durum' => $x['durum'], 'toplam' => (string)$x['toplam']];
    }

    /** Telefondaki sipariş tarihi: gelecekte olamaz, 60 günden eski olamaz; aksi halde bugünün tarihi kullanılır. */
    private static function tarih(string $t): string
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $t);
        $bugun = date('Y-m-d');
        if ($d === false || $d->format('Y-m-d') !== $t || $t > $bugun || $t < date('Y-m-d', strtotime('-60 days'))) return $bugun;
        return $t;
    }
}
