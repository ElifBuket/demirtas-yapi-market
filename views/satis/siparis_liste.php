<form method="get" action="<?= e(url('/siparisler')) ?>" class="filtre" role="search">
  <label for="q">Ara</label>
  <input id="q" name="q" type="search" value="<?= e($q) ?>" placeholder="Sipariş no veya müşteri" maxlength="60">
  <label for="durum">Durum</label>
  <select id="durum" name="durum">
    <option value="">Tümü</option>
    <?php foreach (['onay_bekliyor', 'onayli', 'kismi_sevk', 'sevk_edildi', 'faturalandi', 'iptal'] as $d): ?><option value="<?= e($d) ?>"<?= $durum === $d ? ' selected' : '' ?>><?= e(durum_ad($d)) ?></option><?php endforeach; ?>
  </select>
  <button class="ana-dugme kucuk" type="submit">Filtrele</button>
  <?php if (can('siparis.olustur')): ?><a class="ikincil" href="<?= e(url('/siparisler/yeni')) ?>">Yeni sipariş</a><?php endif; ?>
</form>
<p class="sonuc-ozet"><?= e($toplam) ?> sipariş, sayfa <?= e($sayfa) ?> / <?= e($sayfa_sayisi) ?></p>
<?php if (!$satirlar): ?><p class="bos-durum">Sipariş bulunamadı.</p><?php else: ?>
<div class="tablo-kap"><table class="cetvel">
  <thead><tr><th>Sipariş no</th><th>Tarih</th><th>Müşteri</th><th class="sayi">Toplam</th><th>Durum</th></tr></thead>
  <tbody>
  <?php foreach ($satirlar as $s): ?>
    <tr>
      <td class="kod"><a href="<?= e(url('/siparisler/goster?id=' . (int)$s['id'])) ?>"><?= e($s['belge_no']) ?></a></td>
      <td><?= e(date('d.m.Y', strtotime($s['tarih']))) ?></td>
      <td><?= e($s['unvan']) ?></td>
      <td class="sayi"><?= e(tl($s['toplam'])) ?> <?= e($s['para_birimi']) ?></td>
      <td><span class="rozet<?= $s['durum'] === 'onay_bekliyor' ? ' engelli' : '' ?>"><?= e(durum_ad($s['durum'])) ?></span></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php endif; ?>
<?php if ($sayfa_sayisi > 1):
  $kur = fn(int $n) => url('/siparisler') . '?' . http_build_query(array_filter(['q' => $q, 'durum' => $durum, 'sayfa' => $n], fn($v) => $v !== '' && $v !== null)); ?>
<nav class="sayfalar" aria-label="Sayfalar">
  <?php if ($sayfa > 1): ?><a href="<?= e($kur($sayfa - 1)) ?>">Önceki</a><?php endif; ?>
  <span>Sayfa <?= e($sayfa) ?></span>
  <?php if ($sayfa < $sayfa_sayisi): ?><a href="<?= e($kur($sayfa + 1)) ?>">Sonraki</a><?php endif; ?>
</nav>
<?php endif; ?>
