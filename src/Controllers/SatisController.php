<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\{Auth, Db, Http, HttpException, View};
use App\Services\{Birim, Hesap, IsHatasi, Satis, Stok};

/** Sipariş, irsaliye, fatura ve tahsilat ekranları. İş kuralları Satis servisindedir; burası girdiyi doğrular ve gösterir. */
final class SatisController
{
    private const PER_PAGE = 25;
    private const SATIR_SAYISI = 10;

    private static function like(string $q): string { return '%' . strtr($q, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%'; }
    private static function bugun(): string { return date('Y-m-d'); }

    /** Satışçı yalnızca kendi müşterisinin belgesini görür; başkasınınkine 404 (varlığı bile sızmaz). */
    private static function cariErisim(int $cariId): void
    {
        $k = Auth::satisciKisiti();
        if ($k !== null && (int)Db::value('SELECT satisci_id FROM cariler WHERE id = ?', [$cariId]) !== $k) throw new HttpException(404);
    }

    private static function siparisBul(int $id): array
    {
        $s = Db::one('SELECT s.*, c.unvan, c.kod AS cari_kod, d.ad AS depo FROM siparisler s JOIN cariler c ON c.id = s.cari_id JOIN depolar d ON d.id = s.depo_id WHERE s.id = ?', [$id]);
        if (!$s) throw new HttpException(404);
        self::cariErisim((int)$s['cari_id']);
        return $s;
    }

    private static function sayfalama(int $toplam): array
    {
        $n = max(1, (int)ceil($toplam / self::PER_PAGE));
        $s = min(max(1, (int)($_GET['sayfa'] ?? 1)), $n);
        return [$s, $n, ($s - 1) * self::PER_PAGE];
    }

    // ================= Sipariş =================
    public static function siparisler(): void
    {
        $q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 60);
        $durum = in_array($_GET['durum'] ?? '', ['onay_bekliyor', 'onayli', 'kismi_sevk', 'sevk_edildi', 'faturalandi', 'iptal'], true) ? $_GET['durum'] : '';
        $w = ['1=1']; $p = [];
        if ($q !== '') { $w[] = '(s.belge_no LIKE ? OR c.unvan LIKE ?)'; array_push($p, self::like($q), self::like($q)); }
        if ($durum !== '') { $w[] = 's.durum = ?'; $p[] = $durum; }
        if (($k = Auth::satisciKisiti()) !== null) { $w[] = 'c.satisci_id = ?'; $p[] = $k; }
        $ws = implode(' AND ', $w);
        $toplam = (int)Db::value("SELECT COUNT(*) FROM siparisler s JOIN cariler c ON c.id = s.cari_id WHERE $ws", $p);
        [$sayfa, $sn, $off] = self::sayfalama($toplam);
        $satirlar = Db::all("SELECT s.id, s.belge_no, s.tarih, s.durum, s.toplam, s.para_birimi, c.unvan FROM siparisler s JOIN cariler c ON c.id = s.cari_id WHERE $ws ORDER BY s.id DESC LIMIT " . self::PER_PAGE . " OFFSET $off", $p);
        View::render('satis/siparis_liste', ['baslik' => 'Siparişler', 'satirlar' => $satirlar, 'q' => $q, 'durum' => $durum, 'sayfa' => $sayfa, 'sayfa_sayisi' => $sn, 'toplam' => $toplam]);
    }

    public static function siparisYeniForm(): void
    {
        self::siparisFormu();
    }

    private static function siparisFormu(?string $hata = null, array $deger = []): void
    {
        $w = ['c.tip = \'musteri\'', 'c.durum = \'aktif\'']; $p = [];
        if (($k = Auth::satisciKisiti()) !== null) { $w[] = 'c.satisci_id = ?'; $p[] = $k; }
        $cariler = Db::all('SELECT c.id, c.kod, c.unvan FROM cariler c WHERE ' . implode(' AND ', $w) . ' ORDER BY c.unvan', $p);
        $urunler = Db::all("SELECT stok_kodu, ad, temel_birim, satis_fiyati FROM urunler WHERE aktif = 1 ORDER BY stok_kodu");
        View::render('satis/siparis_yeni', [
            'baslik' => 'Yeni sipariş', 'cariler' => $cariler, 'urunler' => $urunler, 'depolar' => Db::all('SELECT id, ad FROM depolar ORDER BY id'),
            'hata' => $hata, 'deger' => $deger, 'satir_sayisi' => self::SATIR_SAYISI, 'anahtar' => $deger['anahtar'] ?? bin2hex(random_bytes(16)),
        ], $hata ? 422 : 200);
    }

    public static function siparisYeni(): void
    {
        $deger = [
            'cari' => (int)($_POST['cari_id'] ?? 0), 'depo' => (int)($_POST['depo_id'] ?? 1), 'genel' => trim((string)($_POST['genel_iskonto'] ?? '')),
            'anahtar' => (string)($_POST['anahtar'] ?? ''), 'kod' => (array)($_POST['kod'] ?? []), 'miktar' => (array)($_POST['miktar'] ?? []),
            'birim' => (array)($_POST['birim'] ?? []), 'fiyat' => (array)($_POST['fiyat'] ?? []), 'iskonto' => (array)($_POST['iskonto'] ?? []),
        ];
        try {
            self::cariErisim($deger['cari']);
            $genelBp = Hesap::iskontoParse($deger['genel']) ?? throw new IsHatasi('Genel iskonto 0-100 arasında bir yüzde olmalı (en çok 2 ondalık).');
            $satirlar = [];
            for ($i = 0; $i < self::SATIR_SAYISI; $i++) {
                $kod = trim((string)($deger['kod'][$i] ?? ''));
                if ($kod === '') continue;
                $n = $i + 1;
                $u = Db::one('SELECT id, stok_kodu, ad, temel_birim, satis_fiyati FROM urunler WHERE stok_kodu = ? AND aktif = 1', [$kod]) ?? throw new IsHatasi("Satır $n: \"$kod\" kodlu ürün bulunamadı.");
                $birim = trim((string)($deger['birim'][$i] ?? '')) ?: $u['temel_birim'];
                $urunDizi = ['id' => (int)$u['id'], 'stok_kodu' => $u['stok_kodu'], 'ad' => $u['ad'], 'temel_birim' => $u['temel_birim']];
                try { $milli = Stok::miktarTemele($urunDizi, trim((string)($deger['miktar'][$i] ?? '')), $birim); }
                catch (\App\Services\StokHatasi $e) { throw new IsHatasi("Satır $n: " . $e->getMessage()); }
                $fs = trim((string)($deger['fiyat'][$i] ?? ''));
                if ($fs === '') {
                    if ($u['satis_fiyati'] === null) throw new IsHatasi("Satır $n: {$u['stok_kodu']} için liste fiyatı yok, fiyat girin.");
                    $f4 = Hesap::fiyatDb((string)$u['satis_fiyati']);
                } else {
                    $f4 = Hesap::fiyatParse($fs) ?? throw new IsHatasi("Satır $n: fiyat geçersiz (örnek 12,50).");
                }
                $isk = Hesap::iskontoParse((string)($deger['iskonto'][$i] ?? '')) ?? throw new IsHatasi("Satır $n: iskonto 0-100 arasında olmalı.");
                $satirlar[] = ['urun_id' => (int)$u['id'], 'miktar' => $milli, 'fiyat4' => $f4, 'iskonto_bp' => $isk];
            }
            $r = Satis::siparisOlustur($deger['cari'], $deger['depo'], self::bugun(), $satirlar, $genelBp, $deger['anahtar'], Auth::user());
        } catch (IsHatasi $e) {
            self::siparisFormu($e->getMessage(), $deger);
            return;
        }
        flash_koy($r['durum'] === 'onay_bekliyor' ? 'hata' : 'basari', $r['durum'] === 'onay_bekliyor'
            ? $r['belge_no'] . ' kaydedildi ama patron onayı bekliyor. ' . $r['risk_notu']
            : $r['belge_no'] . ' numaralı sipariş kaydedildi.');
        Http::redirect('/siparisler/goster?id=' . $r['id']);
    }

    public static function siparisGoster(): void
    {
        $s = self::siparisBul((int)($_GET['id'] ?? 0));
        $kalemler = Db::all('SELECT sk.*, u.stok_kodu, u.ad, u.temel_birim FROM siparis_kalemleri sk JOIN urunler u ON u.id = sk.urun_id WHERE sk.siparis_id = ? ORDER BY sk.sira', [$s['id']]);
        $stoklar = [];
        foreach ($kalemler as $k) $stoklar[(int)$k['id']] = Stok::seviye((int)$k['urun_id'], (int)$s['depo_id']);
        $irsaliyeler = Db::all('SELECT i.id, i.belge_no, i.tarih, i.fatura_id, f.belge_no AS fatura_no FROM irsaliyeler i LEFT JOIN faturalar f ON f.id = i.fatura_id WHERE i.siparis_id = ? ORDER BY i.id', [$s['id']]);
        $faturalar = Db::all('SELECT id, belge_no, tarih, vade_tarihi, toplam FROM faturalar WHERE siparis_id = ? ORDER BY id', [$s['id']]);
        View::render('satis/siparis_detay', [
            'baslik' => $s['belge_no'], 's' => $s, 'kalemler' => $kalemler, 'stoklar' => $stoklar, 'irsaliyeler' => $irsaliyeler, 'faturalar' => $faturalar,
            'anahtar' => bin2hex(random_bytes(16)), 'anahtar2' => bin2hex(random_bytes(16)),
        ]);
    }

    public static function siparisOnayla(): void
    {
        $id = (int)($_POST['id'] ?? 0);
        self::eylem(fn() => Satis::siparisOnayla($id, Auth::user()), 'Sipariş onaylandı.', '/siparisler/goster?id=' . $id);
    }

    public static function siparisIptal(): void
    {
        $id = (int)($_POST['id'] ?? 0);
        self::siparisBul($id);
        self::eylem(fn() => Satis::siparisIptal($id, Auth::user()), 'Sipariş iptal edildi.', '/siparisler/goster?id=' . $id);
    }

    private static function eylem(callable $f, string $basari, string $donus): never
    {
        try { $f(); flash_koy('basari', $basari); }
        catch (IsHatasi $e) { flash_koy('hata', $e->getMessage()); }
        Http::redirect($donus);
    }

    // ================= İrsaliye (sevk) =================
    public static function sevk(): void
    {
        $id = (int)($_POST['siparis_id'] ?? 0);
        self::siparisBul($id);
        try {
            $miktarlar = [];
            foreach ((array)($_POST['sevk'] ?? []) as $kid => $m) {
                $m = trim((string)$m);
                if ($m === '') continue;
                $milli = Birim::parse($m) ?? throw new IsHatasi('Sevk miktarı geçersiz: ' . $m);
                $miktarlar[(int)$kid] = $milli;
            }
            $r = Satis::irsaliyeKes($id, $miktarlar, self::bugun(), (string)($_POST['anahtar'] ?? ''), Auth::user());
            flash_koy('basari', $r['belge_no'] . ' numaralı irsaliye kesildi, stok düşüldü.');
        } catch (IsHatasi $e) { flash_koy('hata', $e->getMessage()); }
        Http::redirect('/siparisler/goster?id=' . $id);
    }

    // ================= Fatura =================
    public static function faturaKes(): void
    {
        $sid = (int)($_POST['siparis_id'] ?? 0);
        self::siparisBul($sid);
        try {
            $ids = array_map('intval', (array)($_POST['irsaliye'] ?? []));
            $r = Satis::faturaKes($ids, (int)($_POST['vade_gun'] ?? 30), self::bugun(), (string)($_POST['anahtar'] ?? ''), Auth::user());
            flash_koy('basari', $r['belge_no'] . ' numaralı fatura kesildi. Toplam: ' . Hesap::goster($r['toplam']) . ' TL.');
            Http::redirect('/faturalar/goster?id=' . $r['id']);
        } catch (IsHatasi $e) { flash_koy('hata', $e->getMessage()); }
        Http::redirect('/siparisler/goster?id=' . $sid);
    }

    public static function faturalar(): void
    {
        $q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 60);
        $acik = ($_GET['acik'] ?? '') === '1';
        $w = ['1=1']; $p = [];
        if ($q !== '') { $w[] = '(f.belge_no LIKE ? OR c.unvan LIKE ?)'; array_push($p, self::like($q), self::like($q)); }
        $ws = implode(' AND ', $w);
        $sel = "SELECT f.id, f.belge_no, f.tarih, f.vade_tarihi, f.toplam, f.para_birimi, c.unvan,
                       f.toplam - COALESCE((SELECT SUM(d.tutar) FROM tahsilat_dagitim d WHERE d.fatura_id = f.id), 0) AS kalan
                FROM faturalar f JOIN cariler c ON c.id = f.cari_id WHERE $ws";
        $toplam = (int)Db::value("SELECT COUNT(*) FROM ($sel) t" . ($acik ? ' WHERE t.kalan > 0' : ''), $p);
        [$sayfa, $sn, $off] = self::sayfalama($toplam);
        $satirlar = Db::all("SELECT * FROM ($sel) t" . ($acik ? ' WHERE t.kalan > 0' : '') . " ORDER BY t.id DESC LIMIT " . self::PER_PAGE . " OFFSET $off", $p);
        View::render('satis/fatura_liste', ['baslik' => 'Faturalar', 'satirlar' => $satirlar, 'q' => $q, 'acik' => $acik, 'sayfa' => $sayfa, 'sayfa_sayisi' => $sn, 'toplam' => $toplam]);
    }

    public static function faturaGoster(): void
    {
        $f = Db::one('SELECT f.*, c.unvan, c.kod AS cari_kod, c.vkn, s.belge_no AS siparis_no FROM faturalar f JOIN cariler c ON c.id = f.cari_id JOIN siparisler s ON s.id = f.siparis_id WHERE f.id = ?', [(int)($_GET['id'] ?? 0)]);
        if (!$f) throw new HttpException(404);
        $kalemler = Db::all('SELECT fk.*, u.stok_kodu, u.ad, u.temel_birim FROM fatura_kalemleri fk JOIN urunler u ON u.id = fk.urun_id WHERE fk.fatura_id = ? ORDER BY fk.sira', [$f['id']]);
        $gruplar = Db::all('SELECT kdv_orani, SUM(matrah) AS matrah FROM fatura_kalemleri WHERE fatura_id = ? GROUP BY kdv_orani ORDER BY kdv_orani', [$f['id']]);
        foreach ($gruplar as &$g) $g['kdv'] = Hesap::dec(Hesap::bolRound(Hesap::kurus((string)$g['matrah']) * (int)$g['kdv_orani'], 100));
        unset($g);
        $odemeler = Db::all('SELECT t.belge_no, t.tarih, t.yontem, d.tutar FROM tahsilat_dagitim d JOIN tahsilatlar t ON t.id = d.tahsilat_id WHERE d.fatura_id = ? ORDER BY d.id', [$f['id']]);
        View::render('satis/fatura_detay', ['baslik' => $f['belge_no'], 'f' => $f, 'kalemler' => $kalemler, 'gruplar' => $gruplar, 'odemeler' => $odemeler, 'kalan' => Satis::faturaKalan((int)$f['id'])]);
    }

    public static function faturaXml(): void
    {
        $id = (int)($_GET['id'] ?? 0);
        $f = Db::one('SELECT id, belge_no FROM faturalar WHERE id = ?', [$id]);
        if (!$f) throw new HttpException(404);
        try { $xml = \App\Services\EFatura::uret($id); }
        catch (IsHatasi $e) { flash_koy('hata', 'e-Fatura XML üretilemedi: ' . $e->getMessage()); Http::redirect('/faturalar/goster?id=' . $id); }
        \App\Core\Audit::log('indir', 'faturalar', (string)$id, null, null, 'e-Fatura XML indirildi: ' . $f['belge_no'], Auth::user());
        header('Content-Type: application/xml; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . \App\Services\EFatura::gibNo($f['belge_no']) . '.xml"');
        header('X-Content-Type-Options: nosniff');
        echo $xml;
        exit;
    }

    // ================= Tahsilat =================
    public static function tahsilatlar(): void
    {
        $durum = in_array($_GET['durum'] ?? '', ['alindi', 'portfoyde', 'tahsil_edildi', 'karsiliksiz'], true) ? $_GET['durum'] : '';
        $w = ['1=1']; $p = [];
        if ($durum !== '') { $w[] = 't.durum = ?'; $p[] = $durum; }
        $ws = implode(' AND ', $w);
        $toplam = (int)Db::value("SELECT COUNT(*) FROM tahsilatlar t WHERE $ws", $p);
        [$sayfa, $sn, $off] = self::sayfalama($toplam);
        $satirlar = Db::all("SELECT t.*, c.unvan FROM tahsilatlar t JOIN cariler c ON c.id = t.cari_id WHERE $ws ORDER BY t.id DESC LIMIT " . self::PER_PAGE . " OFFSET $off", $p);
        View::render('satis/tahsilat_liste', ['baslik' => 'Tahsilatlar', 'satirlar' => $satirlar, 'durum' => $durum, 'sayfa' => $sayfa, 'sayfa_sayisi' => $sn, 'toplam' => $toplam]);
    }

    public static function tahsilatForm(): void { self::tahsilatFormu(); }

    private static function tahsilatFormu(?string $hata = null, array $deger = []): void
    {
        $cariler = Db::all("SELECT c.id, c.kod, c.unvan, COALESCE((SELECT SUM(borc)-SUM(alacak) FROM cari_hareketler h WHERE h.cari_id = c.id),0) AS bakiye FROM cariler c WHERE c.tip = 'musteri' ORDER BY c.unvan");
        View::render('satis/tahsilat_yeni', ['baslik' => 'Tahsilat gir', 'cariler' => $cariler, 'hata' => $hata, 'deger' => $deger,
            'anahtar' => $deger['anahtar'] ?? bin2hex(random_bytes(16)), 'cari_sec' => (int)($_GET['cari'] ?? 0)], $hata ? 422 : 200);
    }

    public static function tahsilatYeni(): void
    {
        $deger = ['cari' => (int)($_POST['cari_id'] ?? 0), 'tutar' => trim((string)($_POST['tutar'] ?? '')), 'yontem' => (string)($_POST['yontem'] ?? ''),
            'vade' => trim((string)($_POST['vade'] ?? '')), 'evrak' => trim((string)($_POST['evrak_no'] ?? '')), 'aciklama' => trim((string)($_POST['aciklama'] ?? '')),
            'anahtar' => (string)($_POST['anahtar'] ?? '')];
        try {
            $f4 = Hesap::fiyatParse($deger['tutar']);
            // Tutar en çok 2 ondalık: fiyat4 değerinin son iki hanesi 0 olmalı
            if ($f4 === null || $f4 % 100 !== 0) throw new IsHatasi('Tutar geçersiz. Örnek: 1250,75 (en çok 2 ondalık).');
            $r = Satis::tahsilatGir($deger['cari'], intdiv($f4, 100), $deger['yontem'], self::bugun(), $deger['vade'] ?: null, $deger['evrak'] ?: null, $deger['aciklama'] ?: null, $deger['anahtar'], Auth::user());
        } catch (IsHatasi $e) { self::tahsilatFormu($e->getMessage(), $deger); return; }
        flash_koy('basari', $r['belge_no'] . ' kaydedildi. Faturalara mahsup edilen: ' . Hesap::goster($r['mahsup']) . ' TL' . ($r['avans'] > 0 ? ', avans olarak kalan: ' . Hesap::goster($r['avans']) . ' TL.' : '.'));
        Http::redirect('/tahsilatlar');
    }

    public static function cekTahsil(): void
    {
        self::eylem(fn() => Satis::cekTahsilEdildi((int)($_POST['id'] ?? 0), Auth::user()), 'Çek/senet tahsil edildi olarak işaretlendi.', '/tahsilatlar?durum=portfoyde');
    }

    public static function cekKarsiliksiz(): void
    {
        self::eylem(fn() => Satis::cekKarsiliksiz((int)($_POST['id'] ?? 0), self::bugun(), Auth::user()), 'Karşılıksız işlendi: cari yeniden borçlandırıldı.', '/tahsilatlar?durum=karsiliksiz');
    }
}
