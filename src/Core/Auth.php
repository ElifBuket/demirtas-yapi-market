<?php
declare(strict_types=1);

namespace App\Core;

final class Auth
{
    private const MAX_FAIL_USER = 5;   // 15 dakikada kullanıcı adı başına
    private const MAX_FAIL_IP = 20;    // 15 dakikada IP başına
    private const WINDOW_MIN = 15;

    private static ?array $user = null;
    private static bool $loaded = false;

    public static function hashPassword(string $password): string
    {
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
        return password_hash($password, $algo);
    }

    /** Geçerli oturumdaki kullanıcı. Her istekte veritabanından okunur: pasife alınan kullanıcı hemen yetkisini kaybeder. */
    public static function user(): ?array
    {
        if (self::$loaded) return self::$user;
        self::$loaded = true;
        $id = $_SESSION['kullanici_id'] ?? null;
        if (!is_int($id)) return self::$user = null;
        $u = Db::one('SELECT id, kullanici_adi, ad_soyad, rol, satisci_id FROM kullanicilar WHERE id = ? AND aktif = 1', [$id]);
        if ($u === null) {
            unset($_SESSION['kullanici_id']);
        }
        return self::$user = $u;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function can(string $permission): bool
    {
        $u = self::user();
        return $u !== null && Roles::can($u['rol'], $permission);
    }

    /** Yetki yoksa 403 fırlatır ve denemeyi işlem kaydına yazar. */
    public static function require(string $permission): void
    {
        $u = self::user();
        if ($u === null) throw new HttpException(401, 'Giriş gerekli');
        if (!Roles::can($u['rol'], $permission)) {
            Audit::log('yetkisiz', null, null, null, null, 'Yetkisiz erişim denemesi: ' . $permission . ' ' . ($_SERVER['REQUEST_METHOD'] ?? '') . ' ' . Http::path());
            throw new HttpException(403, 'Bu işlem için yetkiniz yok');
        }
    }

    /** Satışçı ise bağlı olduğu satışçı kartının kimliği; değilse null (kısıtlama yok). */
    public static function satisciKisiti(): ?int
    {
        $u = self::user();
        if ($u !== null && $u['rol'] === Roles::SATISCI) return (int)($u['satisci_id'] ?? 0);
        return null;
    }

    /**
     * @return string|null hata mesajı; null = giriş başarılı
     */
    public static function attempt(string $username, string $password): ?string
    {
        $username = strtolower(trim($username));
        $ip = Http::ip();
        $genel = 'Kullanıcı adı veya parola hatalı.';

        if (self::tooManyFailures($username, $ip)) {
            Audit::log('giris_basarisiz', 'kullanicilar', null, null, ['kullanici_adi' => mb_substr($username, 0, 40)], 'Çok fazla başarısız deneme: giriş engellendi', ['id' => null]);
            return 'Çok fazla hatalı deneme yapıldı. ' . self::WINDOW_MIN . ' dakika sonra tekrar deneyin.';
        }

        $row = preg_match('/^[a-z0-9._-]{3,40}$/', $username)
            ? Db::one('SELECT id, kullanici_adi, ad_soyad, rol, satisci_id, parola_hash FROM kullanicilar WHERE kullanici_adi = ? AND aktif = 1', [$username])
            : null;

        // Kullanıcı yokken de aynı maliyette hash doğrulanır: yanıt süresinden kullanıcı adı anlaşılmasın.
        $hash = $row['parola_hash'] ?? self::dummyHash();
        $ok = password_verify($password, $hash) && $row !== null;

        Db::run('INSERT INTO giris_denemeleri (kullanici_adi, ip, basarili) VALUES (?,?,?)', [mb_substr($username, 0, 40), $ip, $ok ? 1 : 0]);

        if (!$ok) {
            Audit::log('giris_basarisiz', 'kullanicilar', $row['id'] ?? null, null, ['kullanici_adi' => mb_substr($username, 0, 40)], 'Hatalı kullanıcı adı veya parola', ['id' => $row['id'] ?? null, 'kullanici_adi' => $row['kullanici_adi'] ?? null, 'rol' => $row['rol'] ?? null]);
            return $genel;
        }

        // Başarılı: oturum kimliğini yenile, CSRF jetonunu yenile
        Session::regenerate();
        Csrf::reset();
        $_SESSION['kullanici_id'] = (int)$row['id'];
        self::$loaded = false;
        self::$user = null;

        if (password_needs_rehash($row['parola_hash'], defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT)) {
            Db::run('UPDATE kullanicilar SET parola_hash = ? WHERE id = ?', [self::hashPassword($password), $row['id']]);
        }
        Db::run('UPDATE kullanicilar SET son_giris = NOW() WHERE id = ?', [$row['id']]);
        Audit::log('giris', 'kullanicilar', $row['id'], null, null, 'Giriş yapıldı');
        return null;
    }

    /**
     * Mobil API girişi: oturum/çerez açmaz. Kilitleme ve zamanlama savunmaları web girişiyle aynıdır.
     * @return array{user:array}|array{hata:string}
     */
    public static function apiDogrula(string $username, string $password): array
    {
        $username = strtolower(trim($username));
        $ip = Http::ip();
        if (self::tooManyFailures($username, $ip)) {
            Audit::log('giris_basarisiz', 'kullanicilar', null, null, ['kullanici_adi' => mb_substr($username, 0, 40), 'kanal' => 'api'], 'Çok fazla başarısız deneme (API)', ['id' => null]);
            return ['hata' => 'Çok fazla hatalı deneme yapıldı. ' . self::WINDOW_MIN . ' dakika sonra tekrar deneyin.'];
        }
        $row = preg_match('/^[a-z0-9._-]{3,40}$/', $username)
            ? Db::one('SELECT id, kullanici_adi, ad_soyad, rol, satisci_id, parola_hash FROM kullanicilar WHERE kullanici_adi = ? AND aktif = 1', [$username]) : null;
        $ok = password_verify($password, $row['parola_hash'] ?? self::dummyHash()) && $row !== null;
        Db::run('INSERT INTO giris_denemeleri (kullanici_adi, ip, basarili) VALUES (?,?,?)', [mb_substr($username, 0, 40), $ip, $ok ? 1 : 0]);
        if (!$ok) {
            Audit::log('giris_basarisiz', 'kullanicilar', $row['id'] ?? null, null, ['kullanici_adi' => mb_substr($username, 0, 40), 'kanal' => 'api'], 'Hatalı kullanıcı adı veya parola (API)', ['id' => $row['id'] ?? null, 'kullanici_adi' => $row['kullanici_adi'] ?? null, 'rol' => $row['rol'] ?? null]);
            return ['hata' => 'Kullanıcı adı veya parola hatalı.'];
        }
        unset($row['parola_hash']);
        return ['user' => $row];
    }

    public static function logout(): void
    {
        if (self::check()) Audit::log('cikis', 'kullanicilar', self::user()['id'], null, null, 'Çıkış yapıldı');
        self::$user = null;
        self::$loaded = false;
        Session::destroy();
    }

    private static function tooManyFailures(string $username, string $ip): bool
    {
        $w = self::WINDOW_MIN;
        $u = (int)Db::value("SELECT COUNT(*) FROM giris_denemeleri WHERE kullanici_adi = ? AND basarili = 0 AND zaman > NOW() - INTERVAL $w MINUTE", [mb_substr($username, 0, 40)]);
        if ($u >= self::MAX_FAIL_USER) return true;
        $i = (int)Db::value("SELECT COUNT(*) FROM giris_denemeleri WHERE ip = ? AND basarili = 0 AND zaman > NOW() - INTERVAL $w MINUTE", [$ip]);
        return $i >= self::MAX_FAIL_IP;
    }

    private static function dummyHash(): string
    {
        static $h = null;
        return $h ??= self::hashPassword('kullanici-yok-sahte-parola');
    }
}
