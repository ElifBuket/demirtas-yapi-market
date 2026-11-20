<form method="get" action="<?= e(url('/tahsilatlar')) ?>" class="filtre">
  <label for="durum">Durum</label>
  <select id="durum" name="durum"><option value="">Tümü</option>
    <?php foreach (['alindi', 'portfoyde', 'tahsil_edildi', 'karsiliksiz'] as $d): ?><option value="<?= e($d) ?>"<?= $durum === $d ? ' selected' : '' ?>><?= e(durum_ad($d)) ?></option><?php endforeach; ?>
  </select>
  <button class="ana-dugme kucuk" type="submit">Filtrele</button>
  <a class="ikincil" href="<?= e(url('/tahsilatlar/yeni')) ?>">Tahsilat gir</a>
</form>
<p class="sonuc-ozet"><?= e($toplam) ?> kayıt, sayfa <?= e($sayfa) ?> / <?= e($sayfa_sayisi) ?></p>
<?php if (!$satirlar): ?><p class="bos-durum">Kayıt yok.</p><?php else: ?>
<div class="tablo-kap"><table class="cetvel">
  <thead><tr><th>No</th><th>Tarih</th><th>Müşteri</th><th>Yöntem</th><th>Vade</th><th class="sayi">Tutar</th><th>Durum</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($satirlar as $t): ?>
    <tr>
      <td class="kod"><?= e($t['belge_no']) ?></td><td><?= e(date('d.m.Y', strtotime($t['tarih']))) ?></td><td><?= e($t['unvan']) ?></td>
      <td><?= e($t['yontem']) ?><?= $t['evrak_no'] ? ' · ' . e($t['evrak_no']) : '' ?></td>
      <td><?= $t['vade_tarihi'] ? e(date('d.m.Y', strtotime($t['vade_tarihi']))) : '' ?></td>
      <td class="sayi"><?= e(tl($t['tutar'])) ?></td>
      <td><span class="rozet<?= $t['durum'] === 'karsiliksiz' ? ' engelli' : '' ?>"><?= e(durum_ad($t['durum'])) ?></span></td>
      <td class="islemler">
        <?php if ($t['durum'] === 'portfoyde'): ?>
          <form method="post" action="<?= e(url('/tahsilatlar/tahsil')) ?>" class="satirici"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><button class="ikincil kucuk" type="submit">Tahsil edildi</button></form>
          <form method="post" action="<?= e(url('/tahsilatlar/karsiliksiz')) ?>" class="satirici"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><button class="ikincil kucuk" type="submit">Karşılıksız</button></form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php endif; ?>
<?php if ($sayfa_sayisi > 1):
  $kur = fn(int $n) => url('/tahsilatlar') . '?' . http_build_query(array_filter(['durum' => $durum, 'sayfa' => $n], fn($v) => $v !== '' && $v !== null)); ?>
<nav class="sayfalar" aria-label="Sayfalar">
  <?php if ($sayfa > 1): ?><a href="<?= e($kur($sayfa - 1)) ?>">Önceki</a><?php endif; ?>
  <span>Sayfa <?= e($sayfa) ?></span>
  <?php if ($sayfa < $sayfa_sayisi): ?><a href="<?= e($kur($sayfa + 1)) ?>">Sonraki</a><?php endif; ?>
</nav>
<?php endif; ?>
