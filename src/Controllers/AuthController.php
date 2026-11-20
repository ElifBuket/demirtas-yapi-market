<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Http;
use App\Core\View;

final class AuthController
{
    public static function showLogin(): void
    {
        if (Auth::check()) Http::redirect('/');
        View::render('giris', ['baslik' => 'Giriş', 'hata' => null, 'kullanici_adi' => ''], 200, 'layout_bos');
    }

    public static function login(): void
    {
        $kullanici = is_string($_POST['kullanici_adi'] ?? null) ? $_POST['kullanici_adi'] : '';
        $parola = is_string($_POST['parola'] ?? null) ? $_POST['parola'] : '';
        $hata = Auth::attempt($kullanici, $parola);
        if ($hata === null) Http::redirect('/');
        View::render('giris', ['baslik' => 'Giriş', 'hata' => $hata, 'kullanici_adi' => mb_substr($kullanici, 0, 40)], 200, 'layout_bos');
    }

    public static function logout(): void
    {
        Auth::logout();
        Http::redirect('/giris');
    }
}
