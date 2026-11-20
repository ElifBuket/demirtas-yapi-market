<?php
declare(strict_types=1);

namespace App\Core;

final class Http
{
    public static function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' && Config::env('TRUST_PROXY') === '1');
    }

    /** Uygulama bir alt klasörde (ör. /demirtas/public) çalışıyorsa o klasör; kökteyse boş. */
    public static function basePath(): string
    {
        $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
        return $dir === '/' || $dir === '.' ? '' : rtrim($dir, '/');
    }

    public static function path(): string
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $base = self::basePath();
        if ($base !== '' && str_starts_with($path, $base)) $path = substr($path, strlen($base));
        $path = '/' . trim($path, '/');
        if ($path === '/index.php') $path = '/';
        return $path;
    }

    public static function securityHeaders(): void
    {
        header_remove('X-Powered-By');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: same-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        // Satır içi script yok; yalnızca kendi sitemiz + Google Fonts.
        header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' https://fonts.googleapis.com; "
            . "font-src https://fonts.gstatic.com; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'self'");
        if (self::isHttps()) header('Strict-Transport-Security: max-age=31536000');
        header('Cache-Control: no-store'); // giriş yapılmış sayfalar tarayıcı önbelleğinde kalmasın
    }

    public static function redirect(string $path): never
    {
        header('Location: ' . self::basePath() . $path, true, 303);
        exit;
    }

    public static function ip(): string
    {
        // X-Forwarded-For'a güvenilmez (sahte yazılabilir); yalnızca doğrudan bağlantı adresi.
        return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }
}
