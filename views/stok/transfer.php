<p class="giris-metin"><?= e($urun['stok_kodu']) ?> · <?= e($urun['ad']) ?></p>
<?php if ($hata): ?><p class="hata" role="alert"><?= e($hata) ?></p><?php endif; ?>
<form method="post" action="<?= e(url('/stok/transfer')) ?>" class="form-blok">
  <?= csrf_field() ?>
  <input type="hidden" name="urun_id" value="<?= (int)$urun['id'] ?>">
  <input type="hidden" name="anahtar" value="<?= e($anahtar) ?>">
  <label for="kaynak">Çıkış yapılacak depo</label>
  <select id="kaynak" name="kaynak_depo" required>
    <?php foreach ($depolar as $d): ?><option value="<?= (int)$d['id'] ?>"<?= (int)($deger['kaynak_depo'] ?? 1) === (int)$d['id'] ? ' selected' : '' ?>><?= e($d['ad']) ?> (mevcut: <?= e(\App\Services\Birim::goster($seviyeler[(int)$d['id']])) ?> <?= e($urun['temel_birim']) ?>)</option><?php endforeach; ?>
  </select>
  <label for="hedef">Giriş yapılacak depo</label>
  <select id="hedef" name="hedef_depo" required>
    <?php foreach ($depolar as $d): ?><option value="<?= (int)$d['id'] ?>"<?= (int)($deger['hedef_depo'] ?? 2) === (int)$d['id'] ? ' selected' : '' ?>><?= e($d['ad']) ?></option><?php endforeach; ?>
  </select>
  <label for="miktar">Miktar</label>
  <div class="satir">
    <input id="miktar" name="miktar" type="text" inputmode="decimal" value="<?= e($deger['miktar'] ?? '') ?>" required maxlength="14" autocomplete="off">
    <select name="birim" aria-label="Birim">
      <?php foreach ($birimler as $b): ?><option value="<?= e($b) ?>"<?= ($deger['birim'] ?? $urun['temel_birim']) === $b ? ' selected' : '' ?>><?= e($b) ?></option><?php endforeach; ?>
    </select>
  </div>
  <label for="aciklama">Not (isteğe bağlı)</label>
  <input id="aciklama" name="aciklama" type="text" value="<?= e($deger['aciklama'] ?? '') ?>" maxlength="150">
  <div class="satir"><button class="ana-dugme" type="submit">Transferi kaydet</button>
    <a class="ikincil" href="<?= e(url('/stok/hareket?urun=' . (int)$urun['id'])) ?>">Vazgeç</a></div>
</form>
