<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Db;
use App\Core\Roles;
use App\Core\View;

final class PanelController
{
    public static function index(): void
    {
        $u = Auth::user();
        $satisci = Auth::satisciKisiti();
        $filtre = $satisci !== null ? ' AND satisci_id = ' . (int)$satisci : '';
        $sayilar = [
            'cari' => (int)Db::value("SELECT COUNT(*) FROM cariler WHERE 1=1" . $filtre),
            'engelli' => (int)Db::value("SELECT COUNT(*) FROM cariler WHERE durum = 'engelli'" . $filtre),
            'urun' => (int)Db::value('SELECT COUNT(*) FROM urunler'),
            'kritik' => Auth::can('stok.goruntule') ? \App\Services\Stok::kritikSayisi() : 0,
            'bekleyen' => (int)Db::value("SELECT COUNT(DISTINCT satir) FROM aktarim_rapor WHERE durum = 'karar_bekliyor' AND calisma_id = (SELECT MAX(id) FROM aktarim_calismalari)"),
        ];
        View::render('panel', ['baslik' => 'Panel', 'u' => $u, 'rol_adi' => Roles::LABELS[$u['rol']], 'sayilar' => $sayilar, 'yetkiler' => Roles::permissions($u['rol'])]);
    }
}
