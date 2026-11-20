<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Db;
use App\Core\View;

final class DenetimController
{
    private const EYLEMLER = ['giris', 'giris_basarisiz', 'cikis', 'ekle', 'degistir', 'sil', 'yetkisiz'];

    public static function index(): void
    {
        $eylem = in_array($_GET['eylem'] ?? '', self::EYLEMLER, true) ? $_GET['eylem'] : '';
        $where = $eylem !== '' ? 'WHERE eylem = ?' : '';
        $satirlar = Db::all(
            "SELECT zaman, kullanici_adi, rol, ip, eylem, tablo, kayit_id, aciklama FROM islem_kaydi $where ORDER BY id DESC LIMIT 200",
            $eylem !== '' ? [$eylem] : []
        );
        View::render('denetim/liste', ['baslik' => 'İşlem kaydı', 'satirlar' => $satirlar, 'eylem' => $eylem, 'eylemler' => self::EYLEMLER]);
    }
}
