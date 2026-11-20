<main class="giris">
  <section class="giris-fis" aria-labelledby="gb">
    <div class="fis-ust">
      <div class="marka-buyuk">Demirtaş<br>Yapı Market</div>
      <p class="fis-alt">Cari, stok ve sipariş takibi</p>
    </div>
    <form method="post" action="<?= e(url('/giris')) ?>" class="giris-form" autocomplete="on">
      <h1 id="gb">Giriş yap</h1>
      <?php if ($hata): ?><p class="hata" role="alert"><?= e($hata) ?></p><?php endif; ?>
      <?= csrf_field() ?>
      <label for="ka">Kullanıcı adı</label>
      <input id="ka" name="kullanici_adi" type="text" value="<?= e($kullanici_adi) ?>" autocomplete="username" autocapitalize="none" spellcheck="false" required maxlength="40" autofocus>
      <label for="pa">Parola</label>
      <input id="pa" name="parola" type="password" autocomplete="current-password" required maxlength="200">
      <button class="ana-dugme" type="submit">Giriş yap</button>
    </form>
  </section>
</main>
