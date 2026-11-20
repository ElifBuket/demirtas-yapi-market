<?php use App\Core\Auth; ?>
<section class="hata-sayfa">
  <p class="hata-kod"><?= e($kod) ?></p>
  <p><?= e($mesaj) ?></p>
  <p><a href="<?= e(url(Auth::check() ? '/' : '/giris')) ?>"><?= Auth::check() ? 'Panele dön' : 'Giriş sayfasına git' ?></a></p>
</section>
