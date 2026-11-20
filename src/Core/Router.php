<?php
declare(strict_types=1);

namespace App\Core;

final class Router
{
    /** @var array<string,array{handler:callable,permission:?string,public:bool}> */
    private array $routes = [];

    public function get(string $path, callable $h, ?string $perm = null, bool $public = false): void
    {
        $this->routes['GET ' . $path] = ['handler' => $h, 'permission' => $perm, 'public' => $public];
    }

    public function post(string $path, callable $h, ?string $perm = null, bool $public = false): void
    {
        $this->routes['POST ' . $path] = ['handler' => $h, 'permission' => $perm, 'public' => $public];
    }

    public function dispatch(): void
    {
        Http::securityHeaders();
        try {
            Session::start();
            $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
            if ($method === 'HEAD') $method = 'GET';
            $path = Http::path();

            $route = $this->routes[$method . ' ' . $path] ?? null;
            if ($route === null) {
                $other = $method === 'GET' ? 'POST' : 'GET';
                throw new HttpException(isset($this->routes[$other . ' ' . $path]) ? 405 : 404);
            }

            // Tüm POST isteklerinde CSRF jetonu merkezi olarak doğrulanır.
            if ($method === 'POST') Csrf::verify();

            if (!$route['public']) {
                if (!Auth::check()) Http::redirect('/giris');
                // Yetki her istekte SUNUCUDA denetlenir; menüde gizlemek tek başına koruma sayılmaz.
                if ($route['permission'] !== null) Auth::require($route['permission']);
            }
            ($route['handler'])();
        } catch (HttpException $e) {
            $mesajlar = [
                401 => 'Devam etmek için giriş yapın.',
                403 => 'Bu sayfayı görme yetkiniz yok.',
                404 => 'Aradığınız sayfa bulunamadı.',
                405 => 'Bu adres bu işlemi desteklemiyor.',
                419 => 'Oturum doğrulaması tamamlanamadı. Sayfayı yenileyip tekrar deneyin.',
            ];
            View::render('errors/hata', ['kod' => $e->status, 'mesaj' => $mesajlar[$e->status] ?? 'İstek işlenemedi.', 'baslik' => 'Hata ' . $e->status], $e->status, $this->hataSablonu());
        } catch (\Throwable $e) {
            error_log('[demirtas] ' . $e::class . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            View::render('errors/hata', ['kod' => 500, 'mesaj' => 'Beklenmeyen bir hata oluştu. Ayrıntı sunucu kaydına yazıldı.', 'baslik' => 'Hata 500'], 500, $this->hataSablonu());
        }
    }

    /** Giriş yapmamış kullanıcıya menülü şablon gösterilmez. */
    private function hataSablonu(): string
    {
        try {
            return Auth::check() ? 'layout' : 'layout_bos';
        } catch (\Throwable) {
            return 'layout_bos';
        }
    }
}
