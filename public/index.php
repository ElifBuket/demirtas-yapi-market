<?php
declare(strict_types=1);

// PHP'nin yerleşik sunucusunda (php -S) var olan statik dosyalar doğrudan verilsin.
if (PHP_SAPI === 'cli-server') {
    $p = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if ($p !== '/' && is_file(__DIR__ . $p)) return false;
}

require dirname(__DIR__) . '/src/autoload.php';

// Mobil API: çerez/oturum/CSRF kullanmaz, kendi kimlik doğrulaması vardır.
if (str_starts_with(\App\Core\Http::path(), '/api/')) { \App\Api\Api::calistir(); exit; }

use App\Controllers\{AuthController, CariController, DenetimController, AsistanController, PanelController, RaporController, SatisController, StokController};
use App\Core\Router;

$r = new Router();
$r->get('/giris', [AuthController::class, 'showLogin'], null, true);
$r->post('/giris', [AuthController::class, 'login'], null, true);
$r->post('/cikis', [AuthController::class, 'logout']);

$r->get('/', [PanelController::class, 'index'], 'panel.goruntule');
$r->get('/cariler', [CariController::class, 'index'], 'cari.goruntule');
$r->get('/cariler/goster', [CariController::class, 'goster'], 'cari.goruntule');
$r->get('/cariler/duzenle', [CariController::class, 'duzenleForm'], 'cari.duzenle');
$r->post('/cariler/duzenle', [CariController::class, 'duzenle'], 'cari.duzenle');
$r->get('/cariler/ekstre', [CariController::class, 'ekstre'], 'cari.goruntule');
$r->post('/kur/guncelle', [CariController::class, 'kurGuncelle'], 'cari.duzenle');
$r->post('/kur/elle', [CariController::class, 'kurElle'], 'cari.duzenle');
$r->get('/stok', [StokController::class, 'index'], 'stok.goruntule');
$r->get('/stok/hareket', [StokController::class, 'hareket'], 'stok.goruntule');
$r->get('/stok/transfer', [StokController::class, 'transferForm'], 'stok.duzenle');
$r->post('/stok/transfer', [StokController::class, 'transfer'], 'stok.duzenle');
$r->get('/stok/sayim', [StokController::class, 'sayimForm'], 'stok.duzenle');
$r->post('/stok/sayim', [StokController::class, 'sayim'], 'stok.duzenle');
$r->get('/siparisler', [SatisController::class, 'siparisler'], 'siparis.goruntule');
$r->get('/siparisler/yeni', [SatisController::class, 'siparisYeniForm'], 'siparis.olustur');
$r->post('/siparisler/yeni', [SatisController::class, 'siparisYeni'], 'siparis.olustur');
$r->get('/siparisler/goster', [SatisController::class, 'siparisGoster'], 'siparis.goruntule');
$r->post('/siparisler/onayla', [SatisController::class, 'siparisOnayla'], 'siparis.onayla');
$r->post('/siparisler/iptal', [SatisController::class, 'siparisIptal'], 'siparis.olustur');
$r->post('/siparisler/sevk', [SatisController::class, 'sevk'], 'stok.duzenle');
$r->get('/faturalar', [SatisController::class, 'faturalar'], 'fatura.goruntule');
$r->get('/faturalar/goster', [SatisController::class, 'faturaGoster'], 'fatura.goruntule');
$r->get('/faturalar/xml', [SatisController::class, 'faturaXml'], 'fatura.goruntule');
$r->post('/faturalar/kes', [SatisController::class, 'faturaKes'], 'fatura.kes');
$r->get('/tahsilatlar', [SatisController::class, 'tahsilatlar'], 'tahsilat.gir');
$r->get('/tahsilatlar/yeni', [SatisController::class, 'tahsilatForm'], 'tahsilat.gir');
$r->post('/tahsilatlar/yeni', [SatisController::class, 'tahsilatYeni'], 'tahsilat.gir');
$r->post('/tahsilatlar/tahsil', [SatisController::class, 'cekTahsil'], 'tahsilat.gir');
$r->post('/tahsilatlar/karsiliksiz', [SatisController::class, 'cekKarsiliksiz'], 'tahsilat.gir');
$r->get('/yonetim', [RaporController::class, 'yonetim'], 'rapor.goruntule');
$r->get('/asistan', [AsistanController::class, 'index'], 'asistan.kullan');
$r->post('/asistan', [AsistanController::class, 'sor'], 'asistan.kullan');
$r->get('/denetim', [DenetimController::class, 'index'], 'denetim.goruntule');

$r->dispatch();
