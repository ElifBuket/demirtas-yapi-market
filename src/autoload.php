<?php
declare(strict_types=1);

// App\Core\Db  =>  src/Core/Db.php   (framework yok, kendi küçük yükleyicimiz)
spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) return;
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) require $file;
});

// Tüm tarih/saatler Türkiye saatiyle (UTC+03:00) üretilir; veritabanı oturumu da aynı dilime çekilir (bkz. Db::pdo).
date_default_timezone_set(\App\Core\Config::env('APP_TZ', 'Europe/Istanbul'));

require_once __DIR__ . '/helpers.php';
