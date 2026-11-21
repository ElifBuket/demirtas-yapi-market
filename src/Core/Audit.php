<?php
declare(strict_types=1);

namespace App\Core;

/**
 * İşlem kaydı: kim, neyi, ne zaman ekledi/değiştirdi/sildi.
 * Her ekle/değiştir/sil işleminden sonra Audit::log() çağrılır.
 *
 * Kayıtlar bir HASH ZİNCİRİ ile bağlanır: her satırın `hash` değeri, bir önceki satırın hash'ini ve kendi alanlarını
 * kapsayan SHA-256'dır. Bir satır sonradan değiştirilir ya da araya/ortadan silinirse zincir bozulur ve
 * Audit::dogrula() bunu yakalar. (Veritabanı tetikleyicileri kurulamayan ortamlarda — ör. ücretsiz hosting —
 * değişmezlik tetikleyici yerine bu zincirle sağlanır: engellemez, ama fark edilmesini garanti eder.)
 */
final class Audit
{
    private const KILIT = 'dym_islem_kaydi_zincir';
    private const AYRAC = "\x1f";

    public static function log(string $eylem, ?string $tablo = null, string|int|null $kayitId = null, mixed $eski = null, mixed $yeni = null, ?string $aciklama = null, ?array $kullanici = null): void
    {
        $u = $kullanici ?? Auth::user();
        $alanlar = [
            'kullanici_id' => $u['id'] ?? null,
            'kullanici_adi' => $u['kullanici_adi'] ?? null,
            'rol' => $u['rol'] ?? null,
            'ip' => Http::ip(),
            'eylem' => $eylem,
            'tablo' => $tablo,
            'kayit_id' => $kayitId === null ? null : (string)$kayitId,
            'eski_deger' => $eski === null ? null : json_encode($eski, JSON_UNESCAPED_UNICODE),
            'yeni_deger' => $yeni === null ? null : json_encode($yeni, JSON_UNESCAPED_UNICODE),
            'aciklama' => $aciklama === null ? null : mb_substr($aciklama, 0, 255),
        ];

        try {
            self::zincirliYaz($alanlar);
        } catch (\Throwable) {
            // Zincir sütunları henüz yoksa (eski şema) ya da kilit alınamazsa kayıt yine de yazılır; denetim kaydı asla kaybolmasın.
            self::duzYaz($alanlar);
        }
    }

    private static function zincirliYaz(array $a): void
    {
        $pdo = Db::pdo();
        $kilitAlindi = (int)Db::value('SELECT GET_LOCK(?, 5)', [self::KILIT]) === 1;
        try {
            $onceki = (string)(Db::value('SELECT hash FROM islem_kaydi WHERE hash IS NOT NULL ORDER BY id DESC LIMIT 1') ?? '');
            $zaman = (string)Db::value('SELECT NOW(3)');
            $hash = self::hesapla($onceki, $zaman, $a);
            Db::run(
                'INSERT INTO islem_kaydi (zaman, kullanici_id, kullanici_adi, rol, ip, eylem, tablo, kayit_id, eski_deger, yeni_deger, aciklama, onceki_hash, hash) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [$zaman, $a['kullanici_id'], $a['kullanici_adi'], $a['rol'], $a['ip'], $a['eylem'], $a['tablo'], $a['kayit_id'], $a['eski_deger'], $a['yeni_deger'], $a['aciklama'], $onceki, $hash]
            );
        } finally {
            if ($kilitAlindi) { Db::value('SELECT RELEASE_LOCK(?)', [self::KILIT]); }
        }
    }

    private static function duzYaz(array $a): void
    {
        Db::run(
            'INSERT INTO islem_kaydi (kullanici_id, kullanici_adi, rol, ip, eylem, tablo, kayit_id, eski_deger, yeni_deger, aciklama) VALUES (?,?,?,?,?,?,?,?,?,?)',
            [$a['kullanici_id'], $a['kullanici_adi'], $a['rol'], $a['ip'], $a['eylem'], $a['tablo'], $a['kayit_id'], $a['eski_deger'], $a['yeni_deger'], $a['aciklama']]
        );
    }

    /** JSON metnini anahtar sırasından ve boşluktan bağımsız tek biçime getirir (veritabanı JSON'u yeniden biçimlendirse de hash tutsun). */
    private static function kanon(?string $json): string
    {
        if ($json === null) { return ''; }
        $d = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) { return $json; }
        return json_encode(self::sirala($d), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    private static function sirala(mixed $d): mixed
    {
        if (!is_array($d)) { return $d; }
        $d = array_map([self::class, 'sirala'], $d);
        if (!array_is_list($d)) { ksort($d, SORT_STRING); }
        return $d;
    }

    private static function hesapla(string $onceki, string $zaman, array $a): string
    {
        return hash('sha256', implode(self::AYRAC, [
            $onceki, substr($zaman, 0, 23),
            (string)($a['kullanici_id'] ?? ''), (string)($a['kullanici_adi'] ?? ''), (string)($a['rol'] ?? ''), (string)($a['ip'] ?? ''),
            (string)$a['eylem'], (string)($a['tablo'] ?? ''), (string)($a['kayit_id'] ?? ''),
            self::kanon($a['eski_deger'] ?? null), self::kanon($a['yeni_deger'] ?? null), (string)($a['aciklama'] ?? ''),
        ]));
    }

    /**
     * Zinciri baştan sona doğrular.
     * @return array{durum:string, toplam:int, zincirli:int, bozuk_id:?int, mesaj:string}
     *   durum: 'tamam' | 'bozuk' | 'zincir_yok'
     */
    public static function dogrula(): array
    {
        $toplam = (int)Db::value('SELECT COUNT(*) FROM islem_kaydi');
        try {
            $son = 0; $onceki = null; $zincirli = 0;
            while (true) {
                $satirlar = Db::all('SELECT id, zaman, kullanici_id, kullanici_adi, rol, ip, eylem, tablo, kayit_id, eski_deger, yeni_deger, aciklama, onceki_hash, hash FROM islem_kaydi WHERE id > ? ORDER BY id LIMIT 1000', [$son]);
                if (!$satirlar) { break; }
                foreach ($satirlar as $s) {
                    $son = (int)$s['id'];
                    if ($s['hash'] === null) {
                        if ($onceki !== null) { return self::bozuk($toplam, $zincirli, $son, 'Zincirin ortasında hash’siz bir kayıt var.'); }
                        continue; // zincirden önceki eski kayıtlar
                    }
                    if ($onceki !== null && $s['onceki_hash'] !== $onceki) { return self::bozuk($toplam, $zincirli, $son, 'Bir önceki kayıtla bağ kopuk (araya ekleme ya da silme).'); }
                    if ($onceki === null && $s['onceki_hash'] !== '') { return self::bozuk($toplam, $zincirli, $son, 'Zincirin ilk kaydından önceki kayıtlar silinmiş.'); }
                    if (!hash_equals(self::hesapla((string)$s['onceki_hash'], (string)$s['zaman'], $s), (string)$s['hash'])) { return self::bozuk($toplam, $zincirli, $son, 'Kaydın içeriği değiştirilmiş.'); }
                    $onceki = (string)$s['hash'];
                    $zincirli++;
                }
            }
        } catch (\PDOException) {
            return ['durum' => 'zincir_yok', 'toplam' => $toplam, 'zincirli' => 0, 'bozuk_id' => null, 'mesaj' => 'Zincir sütunları henüz eklenmemiş.'];
        }
        if ($zincirli === 0) { return ['durum' => 'zincir_yok', 'toplam' => $toplam, 'zincirli' => 0, 'bozuk_id' => null, 'mesaj' => 'Henüz zincirli kayıt yok.']; }
        return ['durum' => 'tamam', 'toplam' => $toplam, 'zincirli' => $zincirli, 'bozuk_id' => null, 'mesaj' => "$zincirli kayıt doğrulandı."];
    }

    private static function bozuk(int $toplam, int $zincirli, int $id, string $mesaj): array
    {
        return ['durum' => 'bozuk', 'toplam' => $toplam, 'zincirli' => $zincirli, 'bozuk_id' => $id, 'mesaj' => "#$id: $mesaj"];
    }
}
