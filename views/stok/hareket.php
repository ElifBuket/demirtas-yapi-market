<?php $turAd = ['acilis' => 'Açılış', 'alis' => 'Alış', 'satis' => 'Satış', 'transfer_giris' => 'Transfer girişi', 'transfer_cikis' => 'Transfer çıkışı', 'sayim' => 'Sayım', 'duzeltme' => 'Düzeltme']; ?>
<p class="giris-metin"><?= e($urun['stok_kodu']) ?> · temel birim: <?= e($urun['temel_birim']) ?></p>
<table class="cetvel dar">
  <caption>Güncel stok</caption>
  <tbody>
  <?php foreach ($depolar as $d): ?>
    <tr><th scope="row"><?= e($d['ad']) ?></th><td class="sayi"><?= e(\App\Services\Birim::goster($seviyeler[(int)$d['id']])) ?> <?= e($urun['temel_birim']) ?></td></tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php if (can('stok.duzenle')): ?>
<p class="aksiyonlar">
  <a class="ikincil" href="<?= e(url('/stok/transfer?urun=' . (int)$urun['id'])) ?>">Depolar arası transfer</a>
  <a class="ikincil" href="<?= e(url('/stok/sayim?urun=' . (int)$urun['id'])) ?>">Stok sayımı gir</a>
</p>
<?php endif; ?>
<h2>Hareketler</h2>
<?php if (!$satirlar): ?>
  <p class="bos-durum">Bu ürün için henüz stok hareketi yok.</p>
<?php else: ?>
<div class="tablo-kap">
<table class="cetvel">
  <thead><tr><th>Tarih</th><th>Depo</th><th>Hareket</th><th class="sayi">Miktar</th><th>Açıklama</th></tr></thead>
  <tbody>
  <?php foreach ($satirlar as $s): ?>
    <tr>
      <td class="kod"><?= e(date('d.m.Y', strtotime($s['tarih']))) ?></td>
      <td><?= e($s['depo']) ?></td>
      <td><?= e($turAd[$s['tur']] ?? $s['tur']) ?></td>
      <td class="sayi"><?= ((float)$s['miktar'] > 0 ? '+' : '') . e(miktar($s['miktar'])) ?></td>
      <td><?= e($s['aciklama']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
