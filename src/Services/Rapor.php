<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

/**
 * Yönetim paneli hesapları. Tutarlar kuruş (int). Yalnızca TRY toplanır; EUR ayrı gösterilir (kur uydurmayız).
 * Vade yaşlandırması yalnızca FATURALAR üzerindendir: aktarılan açılış bakiyelerinin (devir) vade bilgisi yoktur,
 * bu yüzden ayrı "vade bilgisi yok" kalemi olarak gösterilir, yaşlandırmaya karıştırılmaz.
 */
final class Rapor
{
    private static function k(mixed $v): int { return Hesap::kurus((string)($v ?? '0')); }

    public static function ozet(string $bugun): array
    {
        $ay = substr($bugun, 0, 7);
        $r = [];
        $r['gunluk_satis'] = self::k(Db::value("SELECT COALESCE(SUM(toplam),0) FROM faturalar WHERE tarih = ? AND para_birimi = 'TRY'", [$bugun]));
        $r['aylik_satis'] = self::k(Db::value("SELECT COALESCE(SUM(toplam),0) FROM faturalar WHERE tarih LIKE ? AND para_birimi = 'TRY'", [$ay . '-%']));
        // Alacak: TRY müşteri bakiyeleri (yalnızca borçlu olanlar toplanır; alacaklı müşteri avans demektir, netlenmez)
        $r['alacak'] = self::k(Db::value("SELECT COALESCE(SUM(b),0) FROM (SELECT SUM(h.borc)-SUM(h.alacak) AS b FROM cari_hareketler h JOIN cariler c ON c.id = h.cari_id WHERE c.tip='musteri' AND c.para_birimi='TRY' GROUP BY h.cari_id HAVING b > 0) t"));
        $r['alacak_eur'] = self::k(Db::value("SELECT COALESCE(SUM(b),0) FROM (SELECT SUM(h.borc)-SUM(h.alacak) AS b FROM cari_hareketler h JOIN cariler c ON c.id = h.cari_id WHERE c.tip='musteri' AND c.para_birimi='EUR' GROUP BY h.cari_id HAVING b > 0) t"));
        $r['avans'] = -self::k(Db::value("SELECT COALESCE(SUM(b),0) FROM (SELECT SUM(h.borc)-SUM(h.alacak) AS b FROM cari_hareketler h JOIN cariler c ON c.id = h.cari_id WHERE c.tip='musteri' AND c.para_birimi='TRY' GROUP BY h.cari_id HAVING b < 0) t"));
        // Tedarikçiye borcumuz (tedarikçi hesabında alacak > borç)
        $r['borc'] = self::k(Db::value("SELECT COALESCE(SUM(-b),0) FROM (SELECT SUM(h.borc)-SUM(h.alacak) AS b FROM cari_hareketler h JOIN cariler c ON c.id = h.cari_id WHERE c.tip='tedarikci' AND c.para_birimi='TRY' GROUP BY h.cari_id HAVING b < 0) t"));
        $r['kritik_stok'] = Stok::kritikSayisi();
        $r['stok_degeri'] = self::k(Db::value("SELECT COALESCE(SUM(s.t * u.alis_fiyati),0) FROM (SELECT urun_id, SUM(miktar) t FROM stok_hareketleri GROUP BY urun_id) s JOIN urunler u ON u.id = s.urun_id WHERE u.alis_fiyati IS NOT NULL AND s.t > 0"));
        $r['onay_bekleyen'] = (int)Db::value("SELECT COUNT(*) FROM siparisler WHERE durum = 'onay_bekliyor'");
        $r['portfoy_cek'] = self::k(Db::value("SELECT COALESCE(SUM(tutar),0) FROM tahsilatlar WHERE durum = 'portfoyde'"));
        return $r;
    }

    /** Açık fatura kalanlarının vade yaşlandırması (bugüne göre gecikme günü). */
    public static function yaslandirma(string $bugun): array
    {
        $kovalar = ['vadesi_gelmemis' => 0, 'g0_30' => 0, 'g31_60' => 0, 'g61_90' => 0, 'g90_ustu' => 0];
        $faturalar = Db::all("SELECT f.id, f.vade_tarihi, f.toplam - COALESCE((SELECT SUM(d.tutar) FROM tahsilat_dagitim d WHERE d.fatura_id = f.id),0) AS kalan FROM faturalar f WHERE f.para_birimi = 'TRY'");
        foreach ($faturalar as $f) {
            $kalan = self::k($f['kalan']);
            if ($kalan <= 0) continue;
            $gec = (int)((strtotime($bugun) - strtotime($f['vade_tarihi'])) / 86400);
            $kova = $gec <= 0 ? 'vadesi_gelmemis' : ($gec <= 30 ? 'g0_30' : ($gec <= 60 ? 'g31_60' : ($gec <= 90 ? 'g61_90' : 'g90_ustu')));
            $kovalar[$kova] += $kalan;
        }
        $fatKalan = array_sum($kovalar);
        $kovalar['fatura_toplam'] = $fatKalan;
        return $kovalar;
    }

    /** Devir (açılış) bakiyesi: faturaya bağlı olmayan, vade bilgisi bulunmayan alacak. Müşteri bazında net bakiye − açık fatura kalanı. */
    public static function devirAlacak(): int
    {
        $bak = self::k(Db::value("SELECT COALESCE(SUM(b),0) FROM (SELECT SUM(h.borc)-SUM(h.alacak) AS b FROM cari_hareketler h JOIN cariler c ON c.id = h.cari_id WHERE c.tip='musteri' AND c.para_birimi='TRY' AND h.tur = 'acilis' GROUP BY h.cari_id HAVING b > 0) t"));
        return $bak;
    }

    /** En çok borçlu müşteriler (TRY). */
    public static function enBorclular(int $n = 5): array
    {
        $n = max(1, min(20, $n));
        return Db::all("SELECT c.id, c.kod, c.unvan, SUM(h.borc)-SUM(h.alacak) AS bakiye FROM cari_hareketler h JOIN cariler c ON c.id = h.cari_id WHERE c.tip='musteri' AND c.para_birimi='TRY' GROUP BY c.id, c.kod, c.unvan HAVING bakiye > 0 ORDER BY bakiye DESC LIMIT $n");
    }

    public static function enCokAlanlar(string $bugun, int $gun = 30, int $n = 5): array
    {
        $bas = (new \DateTimeImmutable($bugun))->modify('-' . max(1, $gun) . ' days')->format('Y-m-d');
        return Db::all("SELECT c.id, c.kod, c.unvan, SUM(f.toplam) AS toplam FROM faturalar f JOIN cariler c ON c.id = f.cari_id WHERE f.tarih > ? AND f.tarih <= ? AND f.para_birimi = 'TRY' GROUP BY c.id, c.kod, c.unvan ORDER BY toplam DESC LIMIT " . max(1, min(20, $n)), [$bas, $bugun]);
    }

    /**
     * 30 günlük BEKLENEN tahsilat akışı: açık faturaların vade günü + portföydeki çek/senet vadeleri.
     * Vadesi geçmiş açık faturalar "gecikmiş" olarak ayrı tutulur (gerçekleşme tarihi belirsiz). Ödemeler (çıkış) modellenmez:
     * tedarikçi borçlarının vade bilgisi yoktur.
     * @return array{gunler:list<array{tarih:string,fatura:int,cek:int,toplam:int}>, gecikmis:int, toplam_giris:int, ufuk_disi:int}
     */
    public static function nakitAkisi(string $bugun, int $gun = 30): array
    {
        $gun = max(1, min(90, $gun));
        $gunler = [];
        for ($i = 0; $i < $gun; $i++) {
            $t = (new \DateTimeImmutable($bugun))->modify("+$i days")->format('Y-m-d');
            $gunler[$t] = ['tarih' => $t, 'fatura' => 0, 'cek' => 0, 'toplam' => 0];
        }
        $son = array_key_last($gunler);
        $gecikmis = 0; $disi = 0;
        foreach (Db::all("SELECT f.vade_tarihi, f.toplam - COALESCE((SELECT SUM(d.tutar) FROM tahsilat_dagitim d WHERE d.fatura_id = f.id),0) AS kalan FROM faturalar f WHERE f.para_birimi = 'TRY'") as $f) {
            $kalan = self::k($f['kalan']);
            if ($kalan <= 0) continue;
            if ($f['vade_tarihi'] < $bugun) $gecikmis += $kalan;
            elseif ($f['vade_tarihi'] > $son) $disi += $kalan;
            else $gunler[$f['vade_tarihi']]['fatura'] += $kalan;
        }
        foreach (Db::all("SELECT t.vade_tarihi, t.tutar FROM tahsilatlar t JOIN cariler c ON c.id = t.cari_id WHERE t.durum = 'portfoyde' AND c.para_birimi = 'TRY'") as $c) {
            $tt = self::k($c['tutar']);
            // Vadesi geçmiş çekler gecikmisCekler() ile ayrıca takip edilir; ufuk dışındakiler bu grafiğe girmez.
            if ($c['vade_tarihi'] >= $bugun && $c['vade_tarihi'] <= $son) $gunler[$c['vade_tarihi']]['cek'] += $tt;
        }
        $girisToplam = 0;
        foreach ($gunler as &$g) { $g['toplam'] = $g['fatura'] + $g['cek']; $girisToplam += $g['toplam']; }
        unset($g);
        return ['gunler' => array_values($gunler), 'gecikmis' => $gecikmis, 'toplam_giris' => $girisToplam, 'ufuk_disi' => $disi];
    }

    /** Vadesi geçmiş ama hâlâ portföyde olan çek/senetler (takip gerekir). */
    public static function gecikmisCekler(string $bugun): array
    {
        return Db::all("SELECT t.id, t.belge_no, t.evrak_no, t.vade_tarihi, t.tutar, c.unvan FROM tahsilatlar t JOIN cariler c ON c.id = t.cari_id WHERE t.durum = 'portfoyde' AND t.vade_tarihi < ? ORDER BY t.vade_tarihi", [$bugun]);
    }
}
