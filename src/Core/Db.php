<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Tüm sorgular hazırlanmış ifade (prepared statement) ile çalışır; kullanıcı girdisi SQL metnine ASLA eklenmez.
 * ATTR_EMULATE_PREPARES kapalı: parametreler MySQL sunucusunda ayrı gönderilir (SQL injection'a karşı asıl savunma).
 */
final class Db
{
    private static ?\PDO $pdo = null;

    public static function pdo(): \PDO
    {
        return self::$pdo ??= new \PDO(Config::dsn(), Config::dbUser(), Config::dbPass(), [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    /**
     * Verilen işlevi YALNIZCA OKUNUR bağlantıyla çalıştırır (yapay zekâ asistanı için).
     * AI_DB_USER/AI_DB_PASS tanımlıysa o kullanıcıyla bağlanılır (veritabanında yalnızca SELECT yetkisi verilmesi önerilir: scripts/ai_readonly.sql).
     * Her durumda oturum READ ONLY işaretlenir; yazma denemesi sunucu tarafından reddedilir. Normal bağlantı işlev sonunda geri yüklenir.
     */
    public static function okunurModda(callable $f): mixed
    {
        $kullanici = Config::env('AI_DB_USER') !== '' ? Config::env('AI_DB_USER') : Config::dbUser();
        $parola = Config::env('AI_DB_USER') !== '' ? Config::env('AI_DB_PASS') : Config::dbPass();
        $ro = new \PDO(Config::dsn(), $kullanici, $parola, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC, \PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $ro->exec('SET SESSION TRANSACTION READ ONLY');
        $eski = self::$pdo;
        self::$pdo = $ro;
        try { return $f(); }
        finally { self::$pdo = $eski; $ro = null; }
    }

    /** @return list<array<string,mixed>> */
    public static function all(string $sql, array $params = []): array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    public static function value(string $sql, array $params = []): mixed
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        $v = $st->fetchColumn();
        return $v === false ? null : $v;
    }

    /** Etkilenen satır sayısını döndürür. */
    public static function run(string $sql, array $params = []): int
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    public static function insert(string $sql, array $params = []): int
    {
        self::run($sql, $params);
        return (int)self::pdo()->lastInsertId();
    }
}
