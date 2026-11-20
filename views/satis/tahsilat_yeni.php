<?php if ($hata): ?><p class="hata" role="alert"><?= e($hata) ?></p><?php endif; ?>
<p>Tahsilat, müşterinin en eski vadeli faturasından başlayarak mahsup edilir. Fazla tutar avans olarak kalır. Çek/senet için vade ve evrak numarası zorunludur.</p>
<form method="post" action="<?= e(url('/tahsilatlar/yeni')) ?>" class="form-blok">
  <?= csrf_field() ?><input type="hidden" name="anahtar" value="<?= e($anahtar) ?>">
  <label for="cari">Müşteri</label>
  <select id="cari" name="cari_id" required>
    <option value="">Seçin…</option>
    <?php $sec = (int)($deger['cari'] ?? $cari_sec); foreach ($cariler as $c): ?><option value="<?= (int)$c['id'] ?>"<?= $sec === (int)$c['id'] ? ' selected' : '' ?>><?= e($c['kod'] . ' · ' . $c['unvan'] . ' (bakiye ' . tl($c['bakiye']) . ')') ?></option><?php endforeach; ?>
  </select>
  <label for="tutar">Tutar</label>
  <input id="tutar" name="tutar" inputmode="decimal" value="<?= e($deger['tutar'] ?? '') ?>" required maxlength="14" placeholder="1250,75">
  <label for="yontem">Yöntem</label>
  <select id="yontem" name="yontem">
    <?php foreach (['nakit' => 'Nakit', 'havale' => 'Havale / EFT', 'kredi_karti' => 'Kredi kartı', 'cek' => 'Çek', 'senet' => 'Senet'] as $k => $ad): ?><option value="<?= e($k) ?>"<?= ($deger['yontem'] ?? '') === $k ? ' selected' : '' ?>><?= e($ad) ?></option><?php endforeach; ?>
  </select>
  <label for="vade">Vade tarihi (yalnızca çek/senet)</label>
  <input id="vade" name="vade" type="date" value="<?= e($deger['vade'] ?? '') ?>">
  <label for="evrak">Çek/senet numarası</label>
  <input id="evrak" name="evrak_no" value="<?= e($deger['evrak'] ?? '') ?>" maxlength="40">
  <label for="aciklama">Not (isteğe bağlı)</label>
  <input id="aciklama" name="aciklama" value="<?= e($deger['aciklama'] ?? '') ?>" maxlength="150">
  <div class="satir"><button class="ana-dugme" type="submit">Tahsilatı kaydet</button><a class="ikincil" href="<?= e(url('/tahsilatlar')) ?>">Vazgeç</a></div>
</form>
