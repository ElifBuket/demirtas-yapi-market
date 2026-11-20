<?php
declare(strict_types=1);

namespace App\Core;

final class View
{
    /** Şablonu layout içinde çizer. Şablon içindeki her dinamik değer e() ile kaçırılmalıdır. */
    public static function render(string $view, array $data = [], int $status = 200, string $layout = 'layout'): void
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=UTF-8');
        $viewFile = dirname(__DIR__, 2) . '/views/' . $view . '.php';
        $layoutFile = dirname(__DIR__, 2) . '/views/' . $layout . '.php';
        extract($data, EXTR_SKIP);
        ob_start();
        require $viewFile;
        $content = ob_get_clean();
        $baslik = $data['baslik'] ?? 'Demirtaş Yapı Market';
        require $layoutFile;
    }
}
