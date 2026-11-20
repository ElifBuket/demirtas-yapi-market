<?php if ($hata): ?><p class="hata" role="alert"><?= e($hata) ?></p><?php endif; ?>
<?php $v = fn(string $a) => e($deger[$a] ?? ''); $rm = $deger['risk_mod'] ?? ((int)$c['risk_limitsiz'] ? 'limitsiz' : ($c['risk_limiti'] === null ? 'tanimsiz' : 'limitli'));
$rl = $deger['risk_limiti'] ?? ($c['risk_limiti'] === null ? '' : (string)(int)$c['risk_limiti']); ?>
<p class="giris-metin"><span class="kod"><?= e($c['kod']) ?></span></p>
<form method="post" action="<?= e(url('/cariler/duzenle')) ?>" class="form-blok">
  <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
  <label for="unvan">Ünvan</label><input id="unvan" name="unvan" value="<?= $v('unvan') ?>" required maxlength="200">
  <label for="vkn">VKN / TCKN</label><input id="vkn" name="vkn" value="<?= $v('vkn') ?>" maxlength="11" inputmode="numeric">
  <label for="vd">Vergi dairesi</label><input id="vd" name="vergi_dairesi" value="<?= $v('vergi_dairesi') ?>" maxlength="80">
  <label for="tel">Telefon</label><input id="tel" name="telefon" value="<?= $v('telefon') ?>" maxlength="20">
  <label for="ep">E-posta</label><input id="ep" name="eposta" type="email" value="<?= $v('eposta') ?>" maxlength="150">
  <label for="il">İl</label><input id="il" name="il" value="<?= $v('il') ?>" maxlength="40">
  <label for="ilce">İlçe</label><input id="ilce" name="ilce" value="<?= $v('ilce') ?>" maxlength="60">
  <label for="satisci">Satışçı</label>
  <select id="satisci" name="satisci_id"><option value="">Atanmamış</option>
    <?php foreach ($satiscilar as $s): ?><option value="<?= (int)$s['id'] ?>"<?= (int)($deger['satisci_id'] ?? 0) === (int)$s['id'] ? ' selected' : '' ?>><?= e($s['ad']) ?></option><?php endforeach; ?>
  </select>
  <label for="durum">Durum</label>
  <select id="durum" name="durum"><option value="aktif"<?= ($deger['durum'] ?? '') === 'aktif' ? ' selected' : '' ?>>Aktif</option><option value="engelli"<?= ($deger['durum'] ?? '') === 'engelli' ? ' selected' : '' ?>>Engelli (sipariş girilemez)</option></select>
  <label for="uyari">Uyarı notu (sipariş ekranında gösterilir)</label><input id="uyari" name="uyari_notu" value="<?= $v('uyari_notu') ?>" maxlength="255">
  <fieldset<?= $patron ? '' : ' disabled' ?>><legend>Risk limiti<?= $patron ? '' : ' (yalnızca patron değiştirebilir)' ?></legend>
    <label class="onay"><input type="radio" name="risk_mod" value="limitli"<?= $rm === 'limitli' ? ' checked' : '' ?>> Limitli</label>
    <input name="risk_limiti" value="<?= e($rl) ?>" inputmode="decimal" maxlength="14" class="dar" aria-label="Risk limiti tutarı">
    <label class="onay"><input type="radio" name="risk_mod" value="limitsiz"<?= $rm === 'limitsiz' ? ' checked' : '' ?>> Limitsiz</label>
    <label class="onay"><input type="radio" name="risk_mod" value="tanimsiz"<?= $rm === 'tanimsiz' ? ' checked' : '' ?>> Tanımsız (her sipariş onay ister)</label>
  </fieldset>
  <?php if (!$patron): ?>
    <input type="hidden" name="risk_mod" value="<?= e($rm) ?>"><input type="hidden" name="risk_limiti" value="<?= e($rl) ?>">
  <?php endif; ?>
  <div class="satir"><button class="ana-dugme" type="submit">Kaydet</button><a class="ikincil" href="<?= e(url('/cariler/goster?id=' . (int)$c['id'])) ?>">Vazgeç</a></div>
</form>
