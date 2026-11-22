<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\{Audit, Db};

/**
 * Sipariş → irsaliye → fatura → tahsilat zinciri. Her yazan işlem tek transaction'dır; tüm tutarlar tam sayı kuruş/milli.
 * Kurallar:
 *  - Engelli cariye sipariş girilemez. Risk limiti aşılırsa sipariş 'onay_bekliyor' olur, patron onaylamadan sevk edilemez.
 *    Risk = cari bakiyesi + faturalanmamış açık sipariş tutarı + yeni sipariş. Limit boşsa (tanımsız) her sipariş onay ister; 'limitsiz' ise onay istemez.
 *  - Stok irsaliyede düşer (fatura anında değil). Stok yetersizse irsaliye kesilmez. Kısmi sevkiyat serbesttir.
 *  - Cari borç faturada yazılır (irsaliyede değil). Tahsilat alacak yazar ve en eski vadeli faturadan başlayarak mahsup edilir.
 *  - Bir fatura yalnızca aynı siparişin irsaliyelerinden oluşur (genel iskonto sipariş bazlıdır).
 */
final class Satis
{
    // ---------- yardımcılar ----------
    private static function anahtar(string $a): void
    {
        if (!preg_match('/^[0-9a-f]{32}$/', $a)) throw new IsHatasi('İşlem anahtarı geçersiz. Sayfayı yenileyip tekrar deneyin.');
    }

    private static function belgeNo(string $tur, string $onek, string $tarih): string
    {
        $yil = (int)substr($tarih, 0, 4);
        Db::run('INSERT INTO belge_sayaclari (tur, yil, son_no) VALUES (?,?,0) ON DUPLICATE KEY UPDATE son_no = son_no', [$tur, $yil]);
        Db::value('SELECT son_no FROM belge_sayaclari WHERE tur = ? AND yil = ? FOR UPDATE', [$tur, $yil]);
        Db::run('UPDATE belge_sayaclari SET son_no = son_no + 1 WHERE tur = ? AND yil = ?', [$tur, $yil]);
        $no = (int)Db::value('SELECT son_no FROM belge_sayaclari WHERE tur = ? AND yil = ?', [$tur, $yil]);
        return sprintf('%s-%d-%06d', $onek, $yil, $no);
    }

    private static function tekrar(string $tablo, string $anahtar): ?int
    {
        $id = Db::value("SELECT id FROM $tablo WHERE kaynak_key = ?", [$anahtar]);
        return $id === null ? null : (int)$id;
    }

    private static function islem(callable $f): mixed
    {
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try { $r = $f(); $pdo->commit(); return $r; }
        catch (\Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }

    private static function tarihKontrol(string $t): void
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $t);
        if ($d === false || $d->format('Y-m-d') !== $t) throw new IsHatasi('Tarih geçersiz.');
    }

    /** Cari bakiyesi (kuruş, + = müşteri bize borçlu). */
    public static function bakiye(int $cariId): int
    {
        return Hesap::kurus((string)Db::value('SELECT COALESCE(SUM(borc) - SUM(alacak), 0) FROM cari_hareketler WHERE cari_id = ?', [$cariId]));
    }

    /** Faturalanmamış açık sipariş tutarı (kuruş). İptal edilenler hariç; onay bekleyenler DAHİL: bekleyen büyük bir siparişin yanından küçük siparişler limiti delip geçmesin. */
    public static function acikSiparis(int $cariId): int
    {
        return Hesap::kurus((string)Db::value("SELECT COALESCE(SUM(toplam - faturalanan_toplam), 0) FROM siparisler WHERE cari_id = ? AND durum IN ('onay_bekliyor','onayli','kismi_sevk','sevk_edildi')", [$cariId]));
    }

    // ---------- 1. Sipariş ----------
    /**
     * @param list<array{urun_id:int, miktar:int, fiyat4:int, iskonto_bp:int}> $satirlar miktar milli (temel birim)
     * @return array{id:int, belge_no:string, durum:string, risk_notu:?string}
     */
    public static function siparisOlustur(int $cariId, int $depoId, string $tarih, array $satirlar, int $genelBp, string $anahtar, array $kul): array
    {
        self::anahtar($anahtar); self::tarihKontrol($tarih);
        if (!$satirlar) throw new IsHatasi('Siparişe en az bir ürün ekleyin.');
        return self::islem(function () use ($cariId, $depoId, $tarih, $satirlar, $genelBp, $anahtar, $kul) {
            if (($id = self::tekrar('siparisler', 'siparis:' . $anahtar)) !== null) {
                $s = Db::one('SELECT id, belge_no, durum, risk_notu FROM siparisler WHERE id = ?', [$id]);
                throw new IsHatasi('Bu sipariş zaten kaydedildi: ' . $s['belge_no']);
            }
            $cari = Db::one('SELECT * FROM cariler WHERE id = ? FOR UPDATE', [$cariId]);
            if (!$cari || $cari['tip'] !== 'musteri') throw new IsHatasi('Müşteri bulunamadı.');
            if ($cari['durum'] === 'engelli') throw new IsHatasi('Bu cari "engelli" durumda, sipariş girilemez' . ($cari['ham_not'] ? ' (not: ' . $cari['ham_not'] . ')' : '') . '.');
            if (Db::value('SELECT 1 FROM depolar WHERE id = ?', [$depoId]) === null) throw new IsHatasi('Depo bulunamadı.');

            $hesapSatirlari = []; $urunler = [];
            foreach ($satirlar as $i => $s) {
                $u = Db::one('SELECT id, stok_kodu, ad, kdv_orani, aktif FROM urunler WHERE id = ?', [$s['urun_id']]);
                if (!$u || !(int)$u['aktif']) throw new IsHatasi('Satır ' . ($i + 1) . ': ürün bulunamadı veya pasif.');
                if (isset($urunler[$u['id']])) throw new IsHatasi('Satır ' . ($i + 1) . ': ' . $u['stok_kodu'] . ' siparişte birden fazla kez var; tek satırda birleştirin.');
                if ($s['miktar'] <= 0) throw new IsHatasi('Satır ' . ($i + 1) . ': miktar sıfırdan büyük olmalı.');
                $urunler[$u['id']] = $u;
                $hesapSatirlari[] = ['miktar' => $s['miktar'], 'fiyat4' => $s['fiyat4'], 'iskonto_bp' => $s['iskonto_bp'], 'kdv' => (int)$u['kdv_orani']];
            }
            try { $b = Hesap::belge($hesapSatirlari, $genelBp); }
            catch (\InvalidArgumentException $e) { throw new IsHatasi('Hesap hatası: ' . $e->getMessage()); }

            // Risk kontrolü
            $durum = 'onayli'; $not = null;
            if (!(int)$cari['risk_limitsiz']) {
                $maruz = self::bakiye($cariId) + self::acikSiparis($cariId) + $b['toplam'];
                if ($cari['risk_limiti'] === null) {
                    $durum = 'onay_bekliyor'; $not = 'Risk limiti tanımsız: patron onayı gerekir.';
                } elseif ($maruz > Hesap::kurus((string)$cari['risk_limiti'])) {
                    $durum = 'onay_bekliyor';
                    $not = 'Risk limiti aşılıyor: limit ' . Hesap::goster(Hesap::kurus((string)$cari['risk_limiti'])) . ', sipariş sonrası risk ' . Hesap::goster($maruz) . '.';
                }
            }
            $no = self::belgeNo('siparis', 'S', $tarih);
            $id = Db::insert('INSERT INTO siparisler (belge_no, cari_id, depo_id, tarih, durum, para_birimi, genel_iskonto_bp, matrah, kdv, toplam, risk_notu, olusturan_id, kaynak_key) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [$no, $cariId, $depoId, $tarih, $durum, $cari['para_birimi'], $genelBp, Hesap::dec($b['matrah']), Hesap::dec($b['kdv']), Hesap::dec($b['toplam']), $not, $kul['id'] ?? null, 'siparis:' . $anahtar]);
            $sira = 1;
            foreach ($satirlar as $i => $s) {
                Db::run('INSERT INTO siparis_kalemleri (siparis_id, sira, urun_id, miktar, fiyat, iskonto_bp, kdv_orani) VALUES (?,?,?,?,?,?,?)',
                    [$id, $sira++, $s['urun_id'], Birim::fmt($s['miktar']), self::fiyatDec($s['fiyat4']), $s['iskonto_bp'], $hesapSatirlari[$i]['kdv']]);
            }
            Audit::log('ekle', 'siparisler', (string)$id, null, ['belge_no' => $no, 'durum' => $durum, 'toplam' => Hesap::dec($b['toplam'])], $not ?? 'Sipariş oluşturuldu', $kul);
            return ['id' => $id, 'belge_no' => $no, 'durum' => $durum, 'risk_notu' => $not];
        });
    }

    public static function fiyatDec(int $f4): string
    {
        return intdiv($f4, 10000) . '.' . str_pad((string)($f4 % 10000), 4, '0', STR_PAD_LEFT);
    }

    public static function siparisOnayla(int $id, array $kul): void
    {
        if (($kul['rol'] ?? '') !== 'patron') throw new IsHatasi('Yalnızca patron sipariş onaylayabilir.');
        self::islem(function () use ($id, $kul) {
            $s = Db::one('SELECT id, durum, cari_id FROM siparisler WHERE id = ? FOR UPDATE', [$id]);
            if (!$s) throw new IsHatasi('Sipariş bulunamadı.');
            if ($s['durum'] !== 'onay_bekliyor') throw new IsHatasi('Sipariş onay beklemiyor.');
            if (Db::value('SELECT durum FROM cariler WHERE id = ?', [$s['cari_id']]) === 'engelli') throw new IsHatasi('Cari engelli; sipariş onaylanamaz.');
            Db::run("UPDATE siparisler SET durum = 'onayli', onaylayan_id = ? WHERE id = ?", [$kul['id'] ?? null, $id]);
            Audit::log('degistir', 'siparisler', (string)$id, ['durum' => 'onay_bekliyor'], ['durum' => 'onayli'], 'Sipariş onaylandı', $kul);
        });
    }

    /** Yalnızca hiç sevk edilmemiş sipariş iptal edilir. */
    public static function siparisIptal(int $id, array $kul): void
    {
        self::islem(function () use ($id, $kul) {
            $s = Db::one('SELECT id, durum FROM siparisler WHERE id = ? FOR UPDATE', [$id]);
            if (!$s) throw new IsHatasi('Sipariş bulunamadı.');
            if (!in_array($s['durum'], ['onay_bekliyor', 'onayli'], true)) throw new IsHatasi('Sevkiyatı başlamış veya kapanmış sipariş iptal edilemez.');
            if ((float)Db::value('SELECT COALESCE(SUM(sevk_edilen),0) FROM siparis_kalemleri WHERE siparis_id = ?', [$id]) > 0) throw new IsHatasi('Sevk edilmiş ürün var; iptal edilemez.');
            Db::run("UPDATE siparisler SET durum = 'iptal' WHERE id = ?", [$id]);
            Audit::log('degistir', 'siparisler', (string)$id, ['durum' => $s['durum']], ['durum' => 'iptal'], 'Sipariş iptal edildi', $kul);
        });
    }

    // ---------- 2. İrsaliye (sevkiyat) ----------
    /**
     * @param array<int,int> $miktarlar siparis_kalem_id => milli (0 olanlar atlanır)
     * @return array{id:int, belge_no:string}
     */
    public static function irsaliyeKes(int $siparisId, array $miktarlar, string $tarih, string $anahtar, array $kul): array
    {
        self::anahtar($anahtar); self::tarihKontrol($tarih);
        $miktarlar = array_filter($miktarlar, fn($m) => $m > 0);
        if (!$miktarlar) throw new IsHatasi('En az bir kalem için sevk miktarı girin.');
        return self::islem(function () use ($siparisId, $miktarlar, $tarih, $anahtar, $kul) {
            if (self::tekrar('irsaliyeler', 'irsaliye:' . $anahtar) !== null) throw new IsHatasi('Bu irsaliye zaten kaydedildi.');
            $s = Db::one('SELECT * FROM siparisler WHERE id = ? FOR UPDATE', [$siparisId]);
            if (!$s) throw new IsHatasi('Sipariş bulunamadı.');
            if ($s['durum'] === 'onay_bekliyor') throw new IsHatasi('Sipariş patron onayı bekliyor; sevk edilemez.');
            if (!in_array($s['durum'], ['onayli', 'kismi_sevk'], true)) throw new IsHatasi('Bu sipariş sevk edilemez (durum: ' . $s['durum'] . ').');
            if (Db::value('SELECT durum FROM cariler WHERE id = ?', [$s['cari_id']]) === 'engelli') throw new IsHatasi('Cari engelli; sevkiyat yapılamaz.');

            $kalemler = [];
            foreach (Db::all('SELECT * FROM siparis_kalemleri WHERE siparis_id = ? ORDER BY sira', [$siparisId]) as $k) $kalemler[(int)$k['id']] = $k;
            foreach ($miktarlar as $kid => $m) if (!isset($kalemler[$kid])) throw new IsHatasi('Kalem bu siparişe ait değil.');

            // Ürün kilitleri sabit sırada (kilitlenme/deadlock riskini azaltır)
            $urunIds = array_unique(array_map(fn($kid) => (int)$kalemler[$kid]['urun_id'], array_keys($miktarlar))); sort($urunIds);
            foreach ($urunIds as $uid) Stok::kilitle($uid);

            $no = self::belgeNo('irsaliye', 'IRS', $tarih);
            $irsId = Db::insert('INSERT INTO irsaliyeler (belge_no, siparis_id, cari_id, depo_id, tarih, kaynak_key, olusturan_id) VALUES (?,?,?,?,?,?,?)',
                [$no, $siparisId, $s['cari_id'], $s['depo_id'], $tarih, 'irsaliye:' . $anahtar, $kul['id'] ?? null]);
            foreach ($miktarlar as $kid => $m) {
                $k = $kalemler[$kid];
                $kalan = Birim::fromDecimal((string)$k['miktar']) - Birim::fromDecimal((string)$k['sevk_edilen']);
                $urun = Db::one('SELECT stok_kodu, temel_birim FROM urunler WHERE id = ?', [$k['urun_id']]);
                if ($m > $kalan) throw new IsHatasi($urun['stok_kodu'] . ': kalan sipariş miktarından fazla sevk edilemez (kalan ' . Birim::goster($kalan) . ' ' . $urun['temel_birim'] . ').');
                $mevcut = Stok::seviye((int)$k['urun_id'], (int)$s['depo_id']);
                if ($m > $mevcut) throw new IsHatasi($urun['stok_kodu'] . ': depoda yeterli stok yok (mevcut ' . Birim::goster($mevcut) . ', istenen ' . Birim::goster($m) . ' ' . $urun['temel_birim'] . ').');
                Db::run('INSERT INTO irsaliye_kalemleri (irsaliye_id, siparis_kalem_id, urun_id, miktar) VALUES (?,?,?,?)', [$irsId, $kid, $k['urun_id'], Birim::fmt($m)]);
                Db::run('INSERT INTO stok_hareketleri (urun_id, depo_id, tarih, tur, miktar, aciklama, kaynak_key) VALUES (?,?,?,?,?,?,?)',
                    [$k['urun_id'], $s['depo_id'], $tarih, 'satis', Birim::fmt(-$m), 'Satış: ' . $no . ' / ' . $s['belge_no'], 'irsaliye:' . $irsId . ':' . $kid]);
                Db::run('UPDATE siparis_kalemleri SET sevk_edilen = sevk_edilen + ? WHERE id = ?', [Birim::fmt($m), $kid]);
            }
            $bitti = (int)Db::value('SELECT COUNT(*) FROM siparis_kalemleri WHERE siparis_id = ? AND sevk_edilen < miktar', [$siparisId]) === 0;
            Db::run('UPDATE siparisler SET durum = ? WHERE id = ?', [$bitti ? 'sevk_edildi' : 'kismi_sevk', $siparisId]);
            Audit::log('ekle', 'irsaliyeler', (string)$irsId, null, ['belge_no' => $no, 'siparis' => $s['belge_no']], 'İrsaliye kesildi, stok düşüldü', $kul);
            return ['id' => $irsId, 'belge_no' => $no];
        });
    }

    // ---------- 3. Fatura ----------
    /**
     * @param list<int> $irsaliyeIds aynı siparişin, henüz faturalanmamış irsaliyeleri
     * @return array{id:int, belge_no:string, toplam:int}
     */
    public static function faturaKes(array $irsaliyeIds, int $vadeGun, string $tarih, string $anahtar, array $kul): array
    {
        self::anahtar($anahtar); self::tarihKontrol($tarih);
        $irsaliyeIds = array_values(array_unique(array_map('intval', $irsaliyeIds))); sort($irsaliyeIds);
        if (!$irsaliyeIds) throw new IsHatasi('Faturalanacak irsaliye seçin.');
        if ($vadeGun < 0 || $vadeGun > 365) throw new IsHatasi('Vade 0-365 gün olmalı.');
        return self::islem(function () use ($irsaliyeIds, $vadeGun, $tarih, $anahtar, $kul) {
            if (self::tekrar('faturalar', 'fatura:' . $anahtar) !== null) throw new IsHatasi('Bu fatura zaten kaydedildi.');
            $in = implode(',', array_fill(0, count($irsaliyeIds), '?'));
            $irsler = Db::all("SELECT * FROM irsaliyeler WHERE id IN ($in) ORDER BY id FOR UPDATE", $irsaliyeIds);
            if (count($irsler) !== count($irsaliyeIds)) throw new IsHatasi('İrsaliye bulunamadı.');
            $siparisId = (int)$irsler[0]['siparis_id'];
            foreach ($irsler as $r) {
                if ((int)$r['siparis_id'] !== $siparisId) throw new IsHatasi('Bir fatura yalnızca aynı siparişin irsaliyelerinden oluşabilir.');
                if ($r['fatura_id'] !== null) throw new IsHatasi($r['belge_no'] . ' zaten faturalanmış.');
            }
            $s = Db::one('SELECT * FROM siparisler WHERE id = ? FOR UPDATE', [$siparisId]);
            $cari = Db::one('SELECT id, para_birimi FROM cariler WHERE id = ? FOR UPDATE', [$s['cari_id']]);

            // Satırlar: ürün bazında birleştir (aynı ürünün iki irsaliyesi tek fatura satırı olur)
            $birles = [];
            foreach (Db::all("SELECT ik.urun_id, ik.miktar, sk.fiyat, sk.iskonto_bp, sk.kdv_orani, sk.sira FROM irsaliye_kalemleri ik JOIN siparis_kalemleri sk ON sk.id = ik.siparis_kalem_id WHERE ik.irsaliye_id IN ($in) ORDER BY sk.sira, ik.id", $irsaliyeIds) as $r) {
                $u = (int)$r['urun_id'];
                $birles[$u] ??= ['urun_id' => $u, 'miktar' => 0, 'fiyat4' => Hesap::fiyatDb((string)$r['fiyat']), 'iskonto_bp' => (int)$r['iskonto_bp'], 'kdv' => (int)$r['kdv_orani']];
                $birles[$u]['miktar'] += Birim::fromDecimal((string)$r['miktar']);
            }
            $satirlar = array_values($birles);
            $b = Hesap::belge($satirlar, (int)$s['genel_iskonto_bp']);
            $no = self::belgeNo('fatura', 'FTR', $tarih);
            $vade = (new \DateTimeImmutable($tarih))->modify("+$vadeGun days")->format('Y-m-d');
            $ettn = self::uuid();
            $fid = Db::insert('INSERT INTO faturalar (belge_no, ettn, cari_id, siparis_id, tarih, vade_tarihi, para_birimi, genel_iskonto_bp, ara_toplam, genel_iskonto, matrah, kdv, toplam, kaynak_key, olusturan_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [$no, $ettn, $cari['id'], $siparisId, $tarih, $vade, $cari['para_birimi'], $s['genel_iskonto_bp'], Hesap::dec($b['ara_toplam']), Hesap::dec($b['genel_iskonto']), Hesap::dec($b['matrah']), Hesap::dec($b['kdv']), Hesap::dec($b['toplam']), 'fatura:' . $anahtar, $kul['id'] ?? null]);
            foreach ($satirlar as $i => $sa) {
                $h = $b['satirlar'][$i];
                Db::run('INSERT INTO fatura_kalemleri (fatura_id, sira, urun_id, miktar, fiyat, iskonto_bp, kdv_orani, brut, iskonto, genel_iskonto, matrah) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                    [$fid, $i + 1, $sa['urun_id'], Birim::fmt($sa['miktar']), self::fiyatDec($sa['fiyat4']), $sa['iskonto_bp'], $sa['kdv'], Hesap::dec($h['brut']), Hesap::dec($h['iskonto']), Hesap::dec($h['genel_iskonto']), Hesap::dec($h['matrah'])]);
            }
            Db::run("UPDATE irsaliyeler SET fatura_id = ? WHERE id IN ($in)", array_merge([$fid], $irsaliyeIds));
            Db::run('INSERT INTO cari_hareketler (cari_id, tarih, tur, borc, alacak, aciklama, kaynak_key) VALUES (?,?,?,?,0,?,?)',
                [$cari['id'], $tarih, 'fatura', Hesap::dec($b['toplam']), 'Fatura ' . $no, 'fatura:' . $fid]);
            Db::run('UPDATE siparisler SET faturalanan_toplam = faturalanan_toplam + ? WHERE id = ?', [Hesap::dec($b['toplam']), $siparisId]);
            if ($s['durum'] === 'sevk_edildi' && (int)Db::value('SELECT COUNT(*) FROM irsaliyeler WHERE siparis_id = ? AND fatura_id IS NULL', [$siparisId]) === 0) {
                Db::run("UPDATE siparisler SET durum = 'faturalandi' WHERE id = ?", [$siparisId]);
            }
            Audit::log('ekle', 'faturalar', (string)$fid, null, ['belge_no' => $no, 'toplam' => Hesap::dec($b['toplam'])], 'Fatura kesildi, cari borçlandırıldı', $kul);
            return ['id' => $fid, 'belge_no' => $no, 'toplam' => $b['toplam']];
        });
    }

    private static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40); $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return strtoupper(vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4)));
    }

    /** Faturanın kalan (ödenmemiş) tutarı, kuruş. */
    public static function faturaKalan(int $faturaId): int
    {
        $t = Hesap::kurus((string)Db::value('SELECT toplam FROM faturalar WHERE id = ?', [$faturaId]));
        $o = Hesap::kurus((string)Db::value('SELECT COALESCE(SUM(tutar),0) FROM tahsilat_dagitim WHERE fatura_id = ?', [$faturaId]));
        return $t - $o;
    }

    // ---------- 4. Tahsilat ----------
    /**
     * Tahsilat alır; cariyi alacaklandırır; tutarı en eski vadeli faturalardan başlayarak mahsup eder. Artan tutar avans olarak kalır.
     * @return array{id:int, belge_no:string, mahsup:int, avans:int}
     */
    public static function tahsilatGir(int $cariId, int $tutar, string $yontem, string $tarih, ?string $vade, ?string $evrakNo, ?string $aciklama, string $anahtar, array $kul): array
    {
        self::anahtar($anahtar); self::tarihKontrol($tarih);
        if ($tutar <= 0) throw new IsHatasi('Tutar sıfırdan büyük olmalı.');
        if (!in_array($yontem, ['nakit', 'havale', 'kredi_karti', 'cek', 'senet'], true)) throw new IsHatasi('Ödeme yöntemi geçersiz.');
        $vadeli = in_array($yontem, ['cek', 'senet'], true);
        if ($vadeli) {
            if ($vade === null || $vade === '') throw new IsHatasi('Çek/senet için vade tarihi zorunludur.');
            self::tarihKontrol($vade);
            if ($vade < $tarih) throw new IsHatasi('Vade tarihi tahsilat tarihinden önce olamaz.');
            if ($evrakNo === null || trim($evrakNo) === '') throw new IsHatasi('Çek/senet numarası zorunludur.');
        } else { $vade = null; }
        return self::islem(function () use ($cariId, $tutar, $yontem, $tarih, $vade, $evrakNo, $aciklama, $anahtar, $kul, $vadeli) {
            if (self::tekrar('tahsilatlar', 'tahsilat:' . $anahtar) !== null) throw new IsHatasi('Bu tahsilat zaten kaydedildi.');
            $cari = Db::one('SELECT id, tip FROM cariler WHERE id = ? FOR UPDATE', [$cariId]);
            if (!$cari || $cari['tip'] !== 'musteri') throw new IsHatasi('Müşteri bulunamadı.');
            $no = self::belgeNo('tahsilat', 'THS', $tarih);
            $tid = Db::insert('INSERT INTO tahsilatlar (belge_no, cari_id, tarih, tutar, yontem, vade_tarihi, evrak_no, durum, aciklama, kaynak_key, olusturan_id) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                [$no, $cariId, $tarih, Hesap::dec($tutar), $yontem, $vade, $evrakNo ? mb_substr(trim($evrakNo), 0, 40) : null, $vadeli ? 'portfoyde' : 'alindi', $aciklama ? mb_substr($aciklama, 0, 150) : null, 'tahsilat:' . $anahtar, $kul['id'] ?? null]);
            Db::run('INSERT INTO cari_hareketler (cari_id, tarih, tur, borc, alacak, aciklama, kaynak_key) VALUES (?,?,?,0,?,?,?)',
                [$cariId, $tarih, 'tahsilat', Hesap::dec($tutar), 'Tahsilat ' . $no . ' (' . $yontem . ')', 'tahsilat:' . $tid]);
            $kalanTutar = $tutar;
            foreach (Db::all('SELECT id FROM faturalar WHERE cari_id = ? ORDER BY vade_tarihi, id FOR UPDATE', [$cariId]) as $f) {
                if ($kalanTutar <= 0) break;
                $acik = self::faturaKalan((int)$f['id']);
                if ($acik <= 0) continue;
                $m = min($acik, $kalanTutar);
                Db::run('INSERT INTO tahsilat_dagitim (tahsilat_id, fatura_id, tutar) VALUES (?,?,?)', [$tid, $f['id'], Hesap::dec($m)]);
                $kalanTutar -= $m;
            }
            Audit::log('ekle', 'tahsilatlar', (string)$tid, null, ['belge_no' => $no, 'tutar' => Hesap::dec($tutar), 'yontem' => $yontem], 'Tahsilat alındı', $kul);
            return ['id' => $tid, 'belge_no' => $no, 'mahsup' => $tutar - $kalanTutar, 'avans' => $kalanTutar];
        });
    }

    /** Çek vadesi geldi ve tahsil edildi. */
    public static function cekTahsilEdildi(int $tahsilatId, array $kul): void
    {
        self::islem(function () use ($tahsilatId, $kul) {
            $t = Db::one('SELECT id, durum FROM tahsilatlar WHERE id = ? FOR UPDATE', [$tahsilatId]);
            if (!$t || $t['durum'] !== 'portfoyde') throw new IsHatasi('Bu kayıt portföyde bekleyen çek/senet değil.');
            Db::run("UPDATE tahsilatlar SET durum = 'tahsil_edildi' WHERE id = ?", [$tahsilatId]);
            Audit::log('degistir', 'tahsilatlar', (string)$tahsilatId, ['durum' => 'portfoyde'], ['durum' => 'tahsil_edildi'], 'Çek tahsil edildi', $kul);
        });
    }

    /** Karşılıksız çıktı: cari yeniden borçlanır, mahsuplar negatif satırlarla geri alınır (hiçbir kayıt silinmez). */
    public static function cekKarsiliksiz(int $tahsilatId, string $tarih, array $kul): void
    {
        self::tarihKontrol($tarih);
        self::islem(function () use ($tahsilatId, $tarih, $kul) {
            $t = Db::one('SELECT * FROM tahsilatlar WHERE id = ? FOR UPDATE', [$tahsilatId]);
            if (!$t || $t['durum'] !== 'portfoyde') throw new IsHatasi('Yalnızca portföydeki çek/senet karşılıksız işaretlenebilir.');
            Db::run("UPDATE tahsilatlar SET durum = 'karsiliksiz' WHERE id = ?", [$tahsilatId]);
            Db::run('INSERT INTO cari_hareketler (cari_id, tarih, tur, borc, alacak, aciklama, kaynak_key) VALUES (?,?,?,?,0,?,?)',
                [$t['cari_id'], $tarih, 'duzeltme', $t['tutar'], 'Karşılıksız çek/senet: ' . $t['belge_no'], 'karsiliksiz:' . $tahsilatId]);
            foreach (Db::all('SELECT fatura_id, SUM(tutar) AS t FROM tahsilat_dagitim WHERE tahsilat_id = ? GROUP BY fatura_id', [$tahsilatId]) as $d) {
                Db::run('INSERT INTO tahsilat_dagitim (tahsilat_id, fatura_id, tutar) VALUES (?,?,?)', [$tahsilatId, $d['fatura_id'], '-' . ltrim((string)$d['t'], '-')]);
            }
            Audit::log('degistir', 'tahsilatlar', (string)$tahsilatId, ['durum' => 'portfoyde'], ['durum' => 'karsiliksiz'], 'Çek karşılıksız: cari yeniden borçlandırıldı', $kul);
        });
    }
}
