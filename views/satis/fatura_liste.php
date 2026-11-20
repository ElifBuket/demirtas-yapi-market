<form method="get" action="<?= e(url('/faturalar')) ?>" class="filtre" role="search">
  <label for="q">Ara</label>
  <input id="q" name="q" type="search" value="<?= e($q) ?>" placeholder="Fatura no veya müşteri" maxlength="60">
  <label class="onay"><input type="checkbox" name="acik" value="1"<?= $acik ? ' checked' : '' ?>> Yalnızca ödenmemiş</label>
  <button class="ana-dugme kucuk" type="submit">Filtrele</button>
</form>
<p class="sonuc-ozet"><?= e($toplam) ?> fatura, sayfa <?= e($sayfa) ?> / <?= e($sayfa_sayisi) ?></p>
<?php if (!$satirlar): ?><p class="bos-durum">Fatura bulunamadı.</p><?php else: ?>
<div class="tablo-kap"><table class="cetvel">
  <thead><tr><th>Fatura no</th><th>Tarih</th><th>Vade</th><th>Müşteri</th><th class="sayi">Toplam</th><th class="sayi">Kalan</th></tr></thead>
  <tbody>
  <?php foreach ($satirlar as $f): $gecikti = (float)$f['kalan'] > 0 && $f['vade_tarihi'] < date('Y-m-d'); ?>
    <tr>
      <td class="kod"><a href="<?= e(url('/faturalar/goster?id=' . (int)$f['id'])) ?>"><?= e($f['belge_no']) ?></a></td>
      <td><?= e(date('d.m.Y', strtotime($f['tarih']))) ?></td>
      <td><?= e(date('d.m.Y', strtotime($f['vade_tarihi']))) ?><?= $gecikti ? ' <span class="rozet engelli">Gecikmiş</span>' : '' ?></td>
      <td><?= e($f['unvan']) ?></td>
      <td class="sayi"><?= e(tl($f['toplam'])) ?> <?= e($f['para_birimi']) ?></td>
      <td class="sayi"><?= e(tl($f['kalan'])) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php endif; ?>
<?php if ($sayfa_sayisi > 1):
  $kur = fn(int $n) => url('/faturalar') . '?' . http_build_query(array_filter(['q' => $q, 'acik' => $acik ? '1' : '', 'sayfa' => $n], fn($v) => $v !== '' && $v !== null)); ?>
<nav class="sayfalar" aria-label="Sayfalar">
  <?php if ($sayfa > 1): ?><a href="<?= e($kur($sayfa - 1)) ?>">Önceki</a><?php endif; ?>
  <span>Sayfa <?= e($sayfa) ?></span>
  <?php if ($sayfa < $sayfa_sayisi): ?><a href="<?= e($kur($sayfa + 1)) ?>">Sonraki</a><?php endif; ?>
</nav>
<?php endif; ?>
