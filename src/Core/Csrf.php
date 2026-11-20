<?php
declare(strict_types=1);

namespace App\Core;

/** POST/PUT/DELETE isteklerinin hepsi Router'da merkezi olarak doğrulanır; tek tek unutulamaz. */
final class Csrf
{
    public static function token(): string
    {
        return $_SESSION['_csrf'] ??= bin2hex(random_bytes(32));
    }

    public static function reset(): void
    {
        unset($_SESSION['_csrf']);
    }

    public static function verify(): void
    {
        $sent = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (!is_string($sent) || $sent === '' || !hash_equals(self::token(), $sent)) {
            throw new HttpException(419, 'Oturum doğrulaması başarısız');
        }
        // Ek savunma: tarayıcı Origin gönderdiyse bizim sitemizden gelmiş olmalı
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ($origin !== '' && $origin !== 'null') {
            $originHost = (string)parse_url($origin, PHP_URL_HOST);
            $host = (string)preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? ''));
            if (!hash_equals(strtolower($host), strtolower($originHost))) {
                throw new HttpException(419, 'İstek kaynağı geçersiz');
            }
        }
    }
}
