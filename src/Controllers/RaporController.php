<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\View;
use App\Services\Rapor;

final class RaporController
{
    public static function yonetim(): void
    {
        $bugun = date('Y-m-d');
        View::render('rapor/yonetim', [
            'baslik' => 'Yönetim paneli', 'bugun' => $bugun,
            'o' => Rapor::ozet($bugun), 'y' => Rapor::yaslandirma($bugun), 'devir' => Rapor::devirAlacak(),
            'borclular' => Rapor::enBorclular(5), 'alanlar' => Rapor::enCokAlanlar($bugun, 30, 5),
            'nakit' => Rapor::nakitAkisi($bugun, 30), 'gecikmis_cekler' => Rapor::gecikmisCekler($bugun),
        ]);
    }
}
