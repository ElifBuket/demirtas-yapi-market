<?php
declare(strict_types=1);

namespace App\Core;

/** Ayarlar ortam değişkenlerinden okunur; gizli bilgi koda yazılmaz. */
final class Config
{
    public static function env(string $key, string $default = ''): string
    {
        $v = getenv($key);
        if ($v === false || $v === '') $v = self::dosya()[$key] ?? false;
        return $v === false || $v === '' ? $default : $v;
    }

    /** Proje kökündeki .env (web kökünün DIŞINDA) okunur; gerçek ortam değişkenleri her zaman önceliklidir. */
    private static function dosya(): array
    {
        static $d = null;
        if ($d !== null) return $d;
        $d = [];
        $yol = dirname(__DIR__, 2) . '/.env';
        if (is_readable($yol)) foreach (file($yol, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $satir) {
            $satir = trim($satir);
            if ($satir === '' || $satir[0] === '#' || !str_contains($satir, '=')) continue;
            [$k, $v] = explode('=', $satir, 2);
            $d[trim($k)] = trim(trim($v), "\"'");
        }
        return $d;
    }

    public static function isProduction(): bool
    {
        return self::env('APP_ENV', 'local') === 'production';
    }

    public static function dsn(): string
    {
        $socket = self::env('DB_SOCKET');
        $name = self::env('DB_NAME', 'demirtas');
        if ($socket !== '') return "mysql:unix_socket=$socket;dbname=$name;charset=utf8mb4";
        return sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', self::env('DB_HOST', 'localhost'), self::env('DB_PORT', '3306'), $name);
    }

    public static function dbUser(): string { return self::env('DB_USER', 'root'); }
    public static function dbPass(): string { return self::env('DB_PASS', ''); }

    /** Boşta kalma süresi (saniye) */
    public static function idleTimeout(): int { return (int)self::env('SESSION_IDLE', '1800'); }
}
