<form method="get" action="<?= e(url('/cariler')) ?>" class="filtre" role="search">
  <label for="q">Ara</label>
  <input id="q" name="q" type="search" value="<?= e($q) ?>" placeholder="Ünvan, cari kodu veya vergi no" maxlength="60">
  <label for="tip">Tür</label>
  <select id="tip" name="tip">
    <option value=""<?= $tip === '' ? ' selected' : '' ?>>Tümü</option>
    <option value="musteri"<?= $tip === 'musteri' ? ' selected' : '' ?>>Müşteri</option>
    <option value="tedarikci"<?= $tip === 'tedarikci' ? ' selected' : '' ?>>Tedarikçi</option>
  </select>
  <button class="ana-dugme kucuk" type="submit">Filtrele</button>
</form>

<p class="sonuc-ozet"><?= e($toplam) ?> kayıt, sayfa <?= e($sayfa) ?> / <?= e($sayfa_sayisi) ?></p>

<?php if (!$satirlar): ?>
  <p class="bos-durum">Bu aramayla eşleşen cari yok. Aramayı kısaltın veya türü “Tümü” yapın.</p>
<?php else: ?>
<div class="tablo-kap">
<table class="cetvel">
  <thead>
    <tr><th>Kod</th><th>Ünvan</th><th>Tür</th><th>İl</th><th>Satışçı</th><th class="sayi">Bakiye</th><th>Durum</th></tr>
  </thead>
  <tbody>
  <?php foreach ($satirlar as $s): ?>
    <tr>
      <td class="kod"><?= e($s['kod']) ?></td>
      <td><a href="<?= e(url('/cariler/goster?id=' . (int)$s['id'])) ?>"><?= e($s['unvan']) ?></a><?php if ($s['uyari_notu']): ?><span class="not"><?= e($s['uyari_notu']) ?></span><?php endif; ?></td>
      <td><?= $s['tip'] === 'musteri' ? 'Müşteri' : 'Tedarikçi' ?></td>
      <td><?= e($s['il']) ?></td>
      <td><?= e($s['satisci']) ?></td>
      <td class="sayi"><?= e(money($s['bakiye'])) ?> <span class="para"><?= e($s['para_birimi'] === 'EUR' ? '€' : '₺') ?></span></td>
      <td><?= $s['durum'] === 'engelli' ? '<span class="rozet engelli">Sipariş girilemez</span>' : '' ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

<?php if ($sayfa_sayisi > 1):
  $kur = fn(int $n) => url('/cariler') . '?' . http_build_query(array_filter(['q' => $q, 'tip' => $tip, 'sayfa' => $n], fn($v) => $v !== '' && $v !== null)); ?>
<nav class="sayfalar" aria-label="Sayfalar">
  <?php if ($sayfa > 1): ?><a href="<?= e($kur($sayfa - 1)) ?>">Önceki</a><?php endif; ?>
  <span>Sayfa <?= e($sayfa) ?></span>
  <?php if ($sayfa < $sayfa_sayisi): ?><a href="<?= e($kur($sayfa + 1)) ?>">Sonraki</a><?php endif; ?>
</nav>
<?php endif; ?>
