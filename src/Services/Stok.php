<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Db;

/**
 * Stok yalnızca stok_hareketleri toplamıdır; hiçbir yerde "stok = yeni değer" diye güncellenmez.
 * Her değişiklik bir hareket satırıdır (+ giriş, − çıkış). Yazan işlemler tek transaction içindedir
 * ve aynı ürün için eşzamanlı işlemler ürün satırı kilidiyle sıraya girer (yetersiz stok kontrolü yarışa düşmez).
 */
final class Stok
{
    /** @return array{id:int, stok_kodu:string, ad:string, temel_birim:string}|null */
    public static function urun(int $id): ?array
    {
        $u = Db::one('SELECT id, stok_kodu, ad, temel_birim FROM urunler WHERE id = ?', [$id]);
        return $u === null ? null : ['id' => (int)$u['id'], 'stok_kodu' => $u['stok_kodu'], 'ad' => $u['ad'], 'temel_birim' => $u['temel_birim']];
    }

    /** @return array<string,array{0:int,1:int}> birim adı => [pay, payda]; temel birim her zaman [1,1] */
    public static function birimler(array $urun): array
    {
        $map = [$urun['temel_birim'] => [1, 1]];
        foreach (Db::all('SELECT alt_birim, pay, payda FROM urun_birim_cevrim WHERE urun_id = ?', [$urun['id']]) as $c) {
            $map[$c['alt_birim']] = [(int)$c['pay'], (int)$c['payda']];
        }
        return $map;
    }

    /**
     * Stok seviyesi (milli, temel birimde).
     * $tarih verilirse "o gün sonu itibarıyla" (geriye dönük) hesaplanır.
     */
    public static function seviye(int $urunId, ?int $depoId = null, ?string $tarih = null): int
    {
        $sql = 'SELECT COALESCE(SUM(miktar), 0) FROM stok_hareketleri WHERE urun_id = ?';
        $p = [$urunId];
        if ($depoId !== null) { $sql .= ' AND depo_id = ?'; $p[] = $depoId; }
        if ($tarih !== null) { $sql .= ' AND tarih <= ?'; $p[] = $tarih; }
        return Birim::fromDecimal((string)Db::value($sql, $p));
    }

    /** Kullanıcının girdiği miktarı (seçtiği birimle) temel birime çevirir. Geçersizse StokHatasi. */
    public static function miktarTemele(array $urun, string $miktar, string $birim, bool $sifirOlabilir = false): int
    {
        $milli = Birim::parse($miktar);
        if ($milli === null) throw new StokHatasi('Miktar geçersiz. Örnek: 12 veya 12,5 (en çok 3 ondalık).');
        if ($milli < 0 || ($milli === 0 && !$sifirOlabilir)) throw new StokHatasi('Miktar sıfırdan büyük olmalı.');
        $birimler = self::birimler($urun);
        if (!isset($birimler[$birim])) throw new StokHatasi('Bu ürün için "' . $birim . '" birimi tanımlı değil.');
        [$pay, $payda] = $birimler[$birim];
        $temel = Birim::cevir($milli, $pay, $payda);
        if ($temel === 0 && !$sifirOlabilir) throw new StokHatasi('Girilen miktar temel birimde sıfıra yuvarlanıyor; daha büyük bir miktar girin.');
        if (!in_array($urun['temel_birim'], Birim::ONDALIKLI, true) && !Birim::tamMi($temel)) {
            throw new StokHatasi('"' . $urun['temel_birim'] . '" biriminde kesirli miktar tutulamaz: ' . Birim::goster($milli) . ' ' . $birim . ' = ' . Birim::goster($temel) . ' ' . $urun['temel_birim'] . '.');
        }
        return $temel;
    }

    /**
     * İki depo arasında transfer: kaynakta çıkış + hedefte giriş, aynı transaction'da.
     * Yetersiz stokta hiçbir şey yazılmaz. Aynı $anahtar ikinci kez gelirse (çift tıklama) kayıt çoğalmaz.
     */
    public static function transfer(int $urunId, int $kaynakDepo, int $hedefDepo, int $milliTemel, string $anahtar, string $tarih, ?string $aciklama, array $kullanici): void
    {
        if ($kaynakDepo === $hedefDepo) throw new StokHatasi('Kaynak ve hedef depo aynı olamaz.');
        if ($milliTemel <= 0) throw new StokHatasi('Miktar sıfırdan büyük olmalı.');
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $urun = self::kilitle($urunId);
            self::depoVarMi($kaynakDepo);
            self::depoVarMi($hedefDepo);
            self::tekrarMi($anahtar);
            $mevcut = self::seviye($urunId, $kaynakDepo);
            if ($mevcut < $milliTemel) {
                throw new StokHatasi('Kaynak depoda yeterli stok yok. Mevcut: ' . Birim::goster($mevcut) . ' ' . $urun['temel_birim'] . ', istenen: ' . Birim::goster($milliTemel) . '.');
            }
            $not = $aciklama !== null && $aciklama !== '' ? $aciklama : null;
            $c = self::hareket($urunId, $kaynakDepo, $tarih, 'transfer_cikis', -$milliTemel, 'Transfer çıkışı' . ($not ? ': ' . $not : ''), 'islem:' . $anahtar . ':cikis');
            $g = self::hareket($urunId, $hedefDepo, $tarih, 'transfer_giris', $milliTemel, 'Transfer girişi' . ($not ? ': ' . $not : ''), 'islem:' . $anahtar . ':giris');
            Audit::log('ekle', 'stok_hareketleri', $c . ',' . $g, null,
                ['urun' => $urun['stok_kodu'], 'kaynak_depo' => $kaynakDepo, 'hedef_depo' => $hedefDepo, 'miktar' => Birim::fmt($milliTemel), 'birim' => $urun['temel_birim']],
                'Depolar arası transfer', $kullanici);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Sayım: sayılan miktar sistemdekinden farklıysa fark kadar 'sayim' hareketi yazılır (stok üzerine yazılmaz).
     * @return int fark (milli); 0 ise kayıt yazılmaz
     */
    public static function sayim(int $urunId, int $depo, int $sayilanMilli, string $anahtar, string $tarih, ?string $aciklama, array $kullanici): int
    {
        if ($sayilanMilli < 0) throw new StokHatasi('Sayılan miktar eksi olamaz.');
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $urun = self::kilitle($urunId);
            self::depoVarMi($depo);
            self::tekrarMi($anahtar);
            $sistem = self::seviye($urunId, $depo);
            $fark = $sayilanMilli - $sistem;
            if ($fark !== 0) {
                $not = 'Sayım: sistemde ' . Birim::goster($sistem) . ', sayılan ' . Birim::goster($sayilanMilli) . ' ' . $urun['temel_birim'] . ($aciklama ? ' (' . $aciklama . ')' : '');
                $id = self::hareket($urunId, $depo, $tarih, 'sayim', $fark, $not, 'islem:' . $anahtar . ':sayim');
                Audit::log('degistir', 'stok_hareketleri', $id, ['stok' => Birim::fmt($sistem)], ['stok' => Birim::fmt($sayilanMilli)],
                    'Stok sayımı: ' . $urun['stok_kodu'] . ', depo ' . $depo . ', fark ' . Birim::fmt($fark), $kullanici);
            }
            $pdo->commit();
            return $fark;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** Kritik seviyenin altına (veya eşitine) düşen ürün sayısı (iki depo toplamı). */
    public static function kritikSayisi(): int
    {
        return (int)Db::value(
            'SELECT COUNT(*) FROM (
                SELECT u.id, u.kritik_stok, COALESCE(SUM(h.miktar), 0) AS toplam
                FROM urunler u LEFT JOIN stok_hareketleri h ON h.urun_id = u.id
                WHERE u.kritik_stok IS NOT NULL AND u.aktif = 1 GROUP BY u.id, u.kritik_stok
             ) t WHERE t.toplam <= t.kritik_stok'
        );
    }

    // ---------- iç yardımcılar ----------

    public static function kilitle(int $urunId): array
    {
        $u = Db::one('SELECT id, stok_kodu, temel_birim FROM urunler WHERE id = ? FOR UPDATE', [$urunId]);
        if ($u === null) throw new StokHatasi('Ürün bulunamadı.');
        return $u;
    }

    private static function depoVarMi(int $depoId): void
    {
        if (Db::value('SELECT 1 FROM depolar WHERE id = ?', [$depoId]) === null) throw new StokHatasi('Depo bulunamadı.');
    }

    private static function tekrarMi(string $anahtar): void
    {
        if (!preg_match('/^[0-9a-f]{32}$/', $anahtar)) throw new StokHatasi('İşlem anahtarı geçersiz. Sayfayı yenileyip tekrar deneyin.');
        if (Db::value('SELECT 1 FROM stok_hareketleri WHERE kaynak_key LIKE ? LIMIT 1', ['islem:' . $anahtar . ':%']) !== null) {
            throw new StokHatasi('Bu işlem zaten kaydedildi; tekrar eklenmedi.');
        }
    }

    private static function hareket(int $urunId, int $depoId, string $tarih, string $tur, int $milli, string $aciklama, string $key): int
    {
        return Db::insert(
            'INSERT INTO stok_hareketleri (urun_id, depo_id, tarih, tur, miktar, aciklama, kaynak_key) VALUES (?,?,?,?,?,?,?)',
            [$urunId, $depoId, $tarih, $tur, Birim::fmt($milli), mb_substr($aciklama, 0, 255), $key]
        );
    }
}
