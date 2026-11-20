<?php
declare(strict_types=1);

namespace App\Core;

final class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) return;
        ini_set('session.use_strict_mode', '1');     // sunucunun üretmediği oturum kimliği kabul edilmez
        ini_set('session.use_only_cookies', '1');
        session_name('DYM_OTURUM');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => Http::basePath() ?: '/',
            'secure' => Http::isHttps(),
            'httponly' => true,                       // JavaScript çerezi okuyamaz (XSS'te çalınmasın)
            'samesite' => 'Lax',                      // başka siteden gelen POST'ta çerez gitmez (CSRF'ye ek savunma)
        ]);
        session_start();

        // Boşta kalma süresi dolduysa oturumu bitir
        $now = time();
        if (isset($_SESSION['son_islem']) && $now - (int)$_SESSION['son_islem'] > Config::idleTimeout()) {
            self::destroy();
            session_start();
        }
        $_SESSION['son_islem'] = $now;
    }

    public static function regenerate(): void
    {
        session_regenerate_id(true); // oturum sabitleme (session fixation) saldırısına karşı
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Lax']);
        }
        if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
    }
}
