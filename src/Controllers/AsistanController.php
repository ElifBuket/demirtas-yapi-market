<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\{Audit, Auth, Config, Db, Http, View};
use App\Services\Asistan\Asistan;

final class AsistanController
{
    private const LIMIT_10DK = 20;

    public static function index(): void
    {
        View::render('asistan/sohbet', ['baslik' => 'Asistan', 'gecmis' => $_SESSION['asistan'] ?? [], 'llm' => Config::env('ANTHROPIC_API_KEY') !== '']);
    }

    public static function sor(): void
    {
        $u = Auth::user();
        $soru = mb_substr(trim((string)($_POST['soru'] ?? '')), 0, 500);
        $kullanim = (int)Db::value("SELECT COUNT(*) FROM islem_kaydi WHERE eylem = 'asistan' AND kullanici_id = ? AND zaman > (NOW() - INTERVAL 10 MINUTE)", [$u['id']]);
        if ($kullanim >= self::LIMIT_10DK) {
            flash_koy('hata', 'Çok fazla soru sordunuz; birkaç dakika sonra tekrar deneyin.');
            Http::redirect('/asistan');
        }
        if ($soru === '') { flash_koy('hata', 'Bir soru yazın.'); Http::redirect('/asistan'); }
        $y = Asistan::sor($soru, $u);          // okunur modda çalışır; normal bağlantı geri yüklenmiştir
        Audit::log('asistan', null, null, null, ['araclar' => $y['araclar'], 'kaynak' => $y['kaynak']], $soru, $u);
        $gecmis = $_SESSION['asistan'] ?? [];
        array_unshift($gecmis, ['soru' => $soru, 'yanit' => $y]);
        $_SESSION['asistan'] = array_slice($gecmis, 0, 8);
        Http::redirect('/asistan');
    }
}
