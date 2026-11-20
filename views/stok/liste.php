<form method="get" action="<?= e(url('/stok')) ?>" class="filtre" role="search">
  <label for="q">Ara</label>
  <input id="q" name="q" type="search" value="<?= e($q) ?>" placeholder="Ürün adı, stok kodu veya barkod" maxlength="60">
  <label for="kategori">Kategori</label>
  <select id="kategori" name="kategori">
    <option value="">Tümü</option>
    <?php foreach ($kategoriler as $k): ?><option value="<?= e($k) ?>"<?= $kategori === $k ? ' selected' : '' ?>><?= e($k) ?></option><?php endforeach; ?>
  </select>
  <label for="tarih">Tarih itibarıyla</label>
  <input id="tarih" name="tarih" type="date" value="<?= e($tarih ?? '') ?>" max="<?= e(date('Y-m-d')) ?>">
  <label class="onay"><input type="checkbox" name="kritik" value="1"<?= $kritik ? ' checked' : '' ?>> Yalnızca kritik stok</label>
  <button class="ana-dugme kucuk" type="submit">Filtrele</button>
</form>

<p class="sonuc-ozet"><?= e($toplam) ?> ürün, sayfa <?= e($sayfa) ?> / <?= e($sayfa_sayisi) ?><?= $tarih ? ' · Stoklar ' . e(date('d.m.Y', strtotime($tarih))) . ' gün sonu itibarıyla' : '' ?></p>

<?php if (!$satirlar): ?>
  <p class="bos-durum"><?= $kritik ? 'Kritik seviyede ürün yok.' : 'Bu aramayla eşleşen ürün yok. Aramayı kısaltın veya filtreleri temizleyin.' ?></p>
<?php else: ?>
<div class="tablo-kap">
<table class="cetvel">
  <thead>
    <tr><th>Stok kodu</th><th>Ürün</th><th>Kategori</th>
      <?php foreach ($depolar as $d): ?><th class="sayi"><?= e($d['ad']) ?></th><?php endforeach; ?>
      <th class="sayi">Toplam</th><th>Birim</th><th>Durum</th><th></th></tr>
  </thead>
  <tbody>
  <?php foreach ($satirlar as $s):
    $kritikMi = $s['kritik_stok'] !== null && \App\Services\Birim::fromDecimal((string)$s['toplam']) <= \App\Services\Birim::fromDecimal((string)$s['kritik_stok']); ?>
    <tr>
      <td class="kod"><?= e($s['stok_kodu']) ?></td>
      <td><?= e($s['ad']) ?></td>
      <td><?= e($s['kategori']) ?></td>
      <?php foreach ($depolar as $d): ?><td class="sayi"><?= e(miktar($s['d' . (int)$d['id']])) ?></td><?php endforeach; ?>
      <td class="sayi"><strong><?= e(miktar($s['toplam'])) ?></strong></td>
      <td><?= e($s['temel_birim']) ?></td>
      <td><?= $kritikMi ? '<span class="rozet engelli">Kritik (≤ ' . e(miktar($s['kritik_stok'])) . ')</span>' : '' ?></td>
      <td class="islemler">
        <a href="<?= e(url('/stok/hareket?urun=' . (int)$s['id'])) ?>">Hareketler</a>
        <?php if (can('stok.duzenle')): ?>
          <a href="<?= e(url('/stok/transfer?urun=' . (int)$s['id'])) ?>">Transfer</a>
          <a href="<?= e(url('/stok/sayim?urun=' . (int)$s['id'])) ?>">Sayım</a>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

<?php if ($sayfa_sayisi > 1):
  $kur = fn(int $n) => url('/stok') . '?' . http_build_query(array_filter(['q' => $q, 'kategori' => $kategori, 'tarih' => $tarih, 'kritik' => $kritik ? '1' : '', 'sayfa' => $n], fn($v) => $v !== '' && $v !== null)); ?>
<nav class="sayfalar" aria-label="Sayfalar">
  <?php if ($sayfa > 1): ?><a href="<?= e($kur($sayfa - 1)) ?>">Önceki</a><?php endif; ?>
  <span>Sayfa <?= e($sayfa) ?></span>
  <?php if ($sayfa < $sayfa_sayisi): ?><a href="<?= e($kur($sayfa + 1)) ?>">Sonraki</a><?php endif; ?>
</nav>
<?php endif; ?>
