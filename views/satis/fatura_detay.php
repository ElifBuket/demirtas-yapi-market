<p class="giris-metin"><?= e($f['cari_kod']) ?> · <?= e($f['unvan']) ?><?= $f['vkn'] ? ' · VKN ' . e($f['vkn']) : '' ?> · Sipariş <a href="<?= e(url('/siparisler/goster?id=' . (int)$f['siparis_id'])) ?>"><?= e($f['siparis_no']) ?></a></p>
<p>Tarih: <?= e(date('d.m.Y', strtotime($f['tarih']))) ?> · Vade: <?= e(date('d.m.Y', strtotime($f['vade_tarihi']))) ?> · ETTN: <span class="kod"><?= e($f['ettn']) ?></span></p>
<div class="tablo-kap"><table class="cetvel">
  <thead><tr><th>#</th><th>Ürün</th><th class="sayi">Miktar</th><th class="sayi">Fiyat</th><th class="sayi">Brüt</th><th class="sayi">İskonto</th><th class="sayi">Genel isk.</th><th class="sayi">KDV %</th><th class="sayi">Matrah</th></tr></thead>
  <tbody>
  <?php foreach ($kalemler as $k): ?>
    <tr>
      <td><?= (int)$k['sira'] ?></td><td><span class="kod"><?= e($k['stok_kodu']) ?></span> <?= e($k['ad']) ?></td>
      <td class="sayi"><?= e(miktar($k['miktar'])) ?> <?= e($k['temel_birim']) ?></td><td class="sayi"><?= e(fiyat_goster($k['fiyat'])) ?></td>
      <td class="sayi"><?= e(tl($k['brut'])) ?></td><td class="sayi"><?= e(tl($k['iskonto'])) ?></td><td class="sayi"><?= e(tl($k['genel_iskonto'])) ?></td>
      <td class="sayi"><?= (int)$k['kdv_orani'] ?></td><td class="sayi"><?= e(tl($k['matrah'])) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
  <tfoot>
    <tr><th colspan="8" scope="row">Matrah</th><td class="sayi"><?= e(tl($f['matrah'])) ?></td></tr>
    <?php foreach ($gruplar as $g): ?><tr><th colspan="8" scope="row">KDV %<?= (int)$g['kdv_orani'] ?> (matrah <?= e(tl($g['matrah'])) ?>)</th><td class="sayi"><?= e(tl($g['kdv'])) ?></td></tr><?php endforeach; ?>
    <tr><th colspan="8" scope="row">Genel toplam (<?= e($f['para_birimi']) ?>)</th><td class="sayi"><strong><?= e(tl($f['toplam'])) ?></strong></td></tr>
    <tr><th colspan="8" scope="row">Kalan borç</th><td class="sayi"><strong><?= e(\App\Services\Hesap::goster($kalan)) ?></strong></td></tr>
  </tfoot>
</table></div>
<h2>Ödemeler</h2>
<?php if (!$odemeler): ?><p class="bos-durum">Bu faturaya henüz ödeme mahsup edilmedi.</p><?php else: ?>
<table class="cetvel dar"><thead><tr><th>Tahsilat</th><th>Tarih</th><th>Yöntem</th><th class="sayi">Tutar</th></tr></thead><tbody>
<?php foreach ($odemeler as $o): ?><tr><td class="kod"><?= e($o['belge_no']) ?></td><td><?= e(date('d.m.Y', strtotime($o['tarih']))) ?></td><td><?= e($o['yontem']) ?></td><td class="sayi"><?= e(tl($o['tutar'])) ?></td></tr><?php endforeach; ?>
</tbody></table>
<?php endif; ?>
<p class="aksiyonlar"><a class="ikincil" href="<?= e(url('/faturalar/xml?id=' . (int)$f['id'])) ?>">e-Fatura XML indir (UBL-TR 1.2)</a></p>
<?php if (can('tahsilat.gir') && $kalan > 0): ?><p class="aksiyonlar"><a class="ikincil" href="<?= e(url('/tahsilatlar/yeni?cari=' . (int)$f['cari_id'])) ?>">Tahsilat gir</a></p><?php endif; ?>
