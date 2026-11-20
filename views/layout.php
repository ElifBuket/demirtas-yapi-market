<?php
use App\Core\Auth;
use App\Core\Http;
use App\Core\Roles;

$u = Auth::user();
$yol = Http::path();
$menu = [
    ['/',        'Panel',       'panel.goruntule'],
    ['/cariler', 'Cari hesaplar', 'cari.goruntule'],
    ['/yonetim', 'Yönetim paneli', 'rapor.goruntule'],
    ['/siparisler', 'Siparişler', 'siparis.goruntule'],
    ['/faturalar', 'Faturalar', 'fatura.goruntule'],
    ['/tahsilatlar', 'Tahsilatlar', 'tahsilat.gir'],
    ['/stok',    'Stok',        'stok.goruntule'],
    ['/asistan', 'Asistan', 'asistan.kullan'],
    ['/denetim', 'İşlem kaydı', 'denetim.goruntule'],
];
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($baslik) ?> · Demirtaş Yapı Market</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@600;700&family=Public+Sans:wght@400;500;600&display=swap">
<link rel="stylesheet" href="<?= e(url('/assets/app.css')) ?>">
</head>
<body>
<a class="atla" href="#icerik">İçeriğe geç</a>
<div class="kabuk">
  <nav class="menu" aria-label="Ana menü">
    <div class="marka">Demirtaş<br>Yapı Market</div>
    <ul>
      <?php foreach ($menu as [$href, $ad, $yetki]): if (!can($yetki)) continue; $aktif = $href === '/' ? $yol === '/' : str_starts_with($yol, $href); ?>
        <li><a href="<?= e(url($href)) ?>"<?= $aktif ? ' class="aktif" aria-current="page"' : '' ?>><?= e($ad) ?></a></li>
      <?php endforeach; ?>
    </ul>
    <div class="kullanici">
      <div class="kullanici-ad"><?= e($u['ad_soyad']) ?></div>
      <div class="kullanici-rol"><?= e(Roles::LABELS[$u['rol']] ?? $u['rol']) ?></div>
      <form method="post" action="<?= e(url('/cikis')) ?>">
        <?= csrf_field() ?>
        <button class="duz" type="submit">Çıkış yap</button>
      </form>
    </div>
  </nav>
  <main id="icerik" class="ana">
    <h1><?= e($baslik) ?></h1>
    <?php if ($f = flash_al()): ?><p class="<?= $f['tur'] === 'hata' ? 'hata' : 'basari' ?>" role="status"><?= e($f['mesaj']) ?></p><?php endif; ?>
    <?= $content ?>
  </main>
</div>
</body>
</html>
