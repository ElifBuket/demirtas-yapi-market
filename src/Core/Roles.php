<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Rol => yetki tablosu. Ekran/menü gizleme yalnızca görünüm içindir;
 * asıl kontrol her istekte sunucuda Router/Auth::require() ile yapılır.
 */
final class Roles
{
    public const PATRON = 'patron';
    public const MUHASEBE = 'muhasebe';
    public const DEPO = 'depo';
    public const SATISCI = 'satisci';

    public const LABELS = [
        self::PATRON => 'Patron',
        self::MUHASEBE => 'Muhasebe',
        self::DEPO => 'Depo',
        self::SATISCI => 'Satışçı',
    ];

    /** Tanımlı tüm yetkiler */
    public const ALL = [
        'panel.goruntule',
        'cari.goruntule', 'cari.duzenle',
        'stok.goruntule', 'stok.duzenle',
        'siparis.goruntule', 'siparis.olustur', 'siparis.onayla', 'fatura.goruntule',
        'fatura.kes', 'tahsilat.gir',
        'rapor.goruntule', 'asistan.kullan',
        'kullanici.yonet', 'denetim.goruntule',
    ];

    private const MATRIX = [
        self::PATRON => self::ALL,
        self::MUHASEBE => [
            'panel.goruntule', 'cari.goruntule', 'cari.duzenle', 'stok.goruntule',
            'siparis.goruntule', 'fatura.goruntule', 'fatura.kes', 'tahsilat.gir', 'rapor.goruntule', 'asistan.kullan',
        ],
        self::DEPO => [
            'panel.goruntule', 'stok.goruntule', 'stok.duzenle', 'siparis.goruntule', 'asistan.kullan',
        ],
        self::SATISCI => [
            'panel.goruntule', 'cari.goruntule', 'stok.goruntule', 'siparis.goruntule', 'siparis.olustur', 'asistan.kullan',
        ],
    ];

    public static function permissions(string $role): array
    {
        return self::MATRIX[$role] ?? [];
    }

    public static function can(string $role, string $permission): bool
    {
        return in_array($permission, self::permissions($role), true);
    }

    public static function valid(string $role): bool
    {
        return isset(self::MATRIX[$role]);
    }
}
