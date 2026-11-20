<?php
declare(strict_types=1);

namespace App\Core;

/**
 * İşlem kaydı: kim, neyi, ne zaman ekledi/değiştirdi/sildi.
 * Sonraki modüllerde her ekle/değiştir/sil işleminden sonra Audit::log() çağrılır.
 */
final class Audit
{
    public static function log(string $eylem, ?string $tablo = null, string|int|null $kayitId = null, mixed $eski = null, mixed $yeni = null, ?string $aciklama = null, ?array $kullanici = null): void
    {
        $u = $kullanici ?? Auth::user();
        Db::run(
            'INSERT INTO islem_kaydi (kullanici_id, kullanici_adi, rol, ip, eylem, tablo, kayit_id, eski_deger, yeni_deger, aciklama) VALUES (?,?,?,?,?,?,?,?,?,?)',
            [
                $u['id'] ?? null,
                $u['kullanici_adi'] ?? null,
                $u['rol'] ?? null,
                Http::ip(),
                $eylem,
                $tablo,
                $kayitId === null ? null : (string)$kayitId,
                $eski === null ? null : json_encode($eski, JSON_UNESCAPED_UNICODE),
                $yeni === null ? null : json_encode($yeni, JSON_UNESCAPED_UNICODE),
                $aciklama === null ? null : mb_substr($aciklama, 0, 255),
            ]
        );
    }
}
