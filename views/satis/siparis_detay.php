<?php
$sevkEdilebilir = in_array($s['durum'], ['onayli', 'kismi_sevk'], true);
$faturasizlar = array_values(array_filter($irsaliyeler, fn($i) => $i['fatura_id'] === null));
$hesap = [];
foreach ($kalemler as $k) $hesap[] = ['miktar' => \App\Services\Birim::fromDecimal((string)$k['miktar']), 'fiyat4' => \App\Services\Hesap::fiyatDb((string)$k['fiyat']), 'iskonto_bp' => (int)$k['iskonto_bp'], 'kdv' => (int)$k['kdv_orani']];
$b = \App\Services\Hesap::belge($hesap, (int)$s['genel_iskonto_bp']);
?>
<p class="giris-metin"><?= e($s['cari_kod']) ?> · <?= e($s['unvan']) ?> · <?= e(date('d.m.Y', strtotime($s['tarih']))) ?> · <?= e($s['depo']) ?> · <span class="rozet<?= $s['durum'] === 'onay_bekliyor' ? ' engelli' : '' ?>"><?= e(durum_ad($s['durum'])) ?></span></p>
<?php if ($s['risk_notu']): ?><p class="hata"><?= e($s['risk_notu']) ?></p><?php endif; ?>
<div class="aksiyonlar">
  <?php if ($s['durum'] === 'onay_bekliyor' && can('siparis.onayla')): ?>
    <form method="post" action="<?= e(url('/siparisler/onayla')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><button class="ana-dugme" type="submit">Siparişi onayla</button></form>
  <?php endif; ?>
  <?php if (in_array($s['durum'], ['onay_bekliyor', 'onayli'], true) && can('siparis.olustur')): ?>
    <form method="post" action="<?= e(url('/siparisler/iptal')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><button class="ikincil" type="submit">Siparişi iptal et</button></form>
  <?php endif; ?>
</div>
<h2>Kalemler</h2>
<div class="tablo-kap"><table class="cetvel">
  <thead><tr><th>#</th><th>Ürün</th><th class="sayi">Miktar</th><th class="sayi">Sevk edilen</th><th class="sayi">Fiyat</th><th class="sayi">İsk. %</th><th class="sayi">KDV %</th><th class="sayi">Matrah</th></tr></thead>
  <tbody>
  <?php foreach ($kalemler as $i => $k): $h = $b['satirlar'][$i]; ?>
    <tr>
      <td><?= (int)$k['sira'] ?></td>
      <td><span class="kod"><?= e($k['stok_kodu']) ?></span> <?= e($k['ad']) ?></td>
      <td class="sayi"><?= e(miktar($k['miktar'])) ?> <?= e($k['temel_birim']) ?></td>
      <td class="sayi"><?= e(miktar($k['sevk_edilen'])) ?></td>
      <td class="sayi"><?= e(fiyat_goster($k['fiyat'])) ?></td>
      <td class="sayi"><?= e(number_format($k['iskonto_bp'] / 100, 2, ',', '')) ?></td>
      <td class="sayi"><?= (int)$k['kdv_orani'] ?></td>
      <td class="sayi"><?= e(\App\Services\Hesap::goster($h['matrah'])) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
  <tfoot>
    <tr><th colspan="7" scope="row">Ara toplam</th><td class="sayi"><?= e(\App\Services\Hesap::goster($b['ara_toplam'])) ?></td></tr>
    <?php if ($b['genel_iskonto'] > 0): ?><tr><th colspan="7" scope="row">Genel iskonto (%<?= e(number_format($s['genel_iskonto_bp'] / 100, 2, ',', '')) ?>)</th><td class="sayi">−<?= e(\App\Services\Hesap::goster($b['genel_iskonto'])) ?></td></tr><?php endif; ?>
    <tr><th colspan="7" scope="row">Matrah</th><td class="sayi"><?= e(\App\Services\Hesap::goster($b['matrah'])) ?></td></tr>
    <?php foreach ($b['kdv_gruplari'] as $oran => $g): ?><tr><th colspan="7" scope="row">KDV %<?= (int)$oran ?></th><td class="sayi"><?= e(\App\Services\Hesap::goster($g['kdv'])) ?></td></tr><?php endforeach; ?>
    <tr><th colspan="7" scope="row">Genel toplam (<?= e($s['para_birimi']) ?>)</th><td class="sayi"><strong><?= e(\App\Services\Hesap::goster($b['toplam'])) ?></strong></td></tr>
  </tfoot>
</table></div>

<?php if ($sevkEdilebilir && can('stok.duzenle')): ?>
<h2>Sevkiyat (irsaliye kes)</h2>
<p>Kısmi sevk yapabilirsiniz; boş bıraktığınız kalem sevk edilmez. Stok bu işlemde düşer.</p>
<form method="post" action="<?= e(url('/siparisler/sevk')) ?>" class="form-blok genis">
  <?= csrf_field() ?><input type="hidden" name="siparis_id" value="<?= (int)$s['id'] ?>"><input type="hidden" name="anahtar" value="<?= e($anahtar) ?>">
  <div class="tablo-kap"><table class="cetvel">
    <thead><tr><th>Ürün</th><th class="sayi">Kalan sipariş</th><th class="sayi">Depoda</th><th>Bu sevkiyat</th></tr></thead>
    <tbody>
    <?php foreach ($kalemler as $k): $kalan = \App\Services\Birim::fromDecimal((string)$k['miktar']) - \App\Services\Birim::fromDecimal((string)$k['sevk_edilen']); if ($kalan <= 0) continue; ?>
      <tr>
        <td><span class="kod"><?= e($k['stok_kodu']) ?></span> <?= e($k['ad']) ?></td>
        <td class="sayi"><?= e(\App\Services\Birim::goster($kalan)) ?> <?= e($k['temel_birim']) ?></td>
        <td class="sayi"><?= e(\App\Services\Birim::goster($stoklar[(int)$k['id']])) ?></td>
        <td><input name="sevk[<?= (int)$k['id'] ?>]" inputmode="decimal" maxlength="14" class="dar" aria-label="Sevk miktarı <?= e($k['stok_kodu']) ?>"></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <div class="satir"><button class="ana-dugme" type="submit">İrsaliye kes</button></div>
</form>
<?php endif; ?>

<h2>İrsaliyeler</h2>
<?php if (!$irsaliyeler): ?><p class="bos-durum">Henüz sevkiyat yapılmadı.</p><?php else: ?>
<?php if ($faturasizlar && can('fatura.kes')): ?>
<form method="post" action="<?= e(url('/faturalar/kes')) ?>" class="form-blok genis">
  <?= csrf_field() ?><input type="hidden" name="siparis_id" value="<?= (int)$s['id'] ?>"><input type="hidden" name="anahtar" value="<?= e($anahtar2) ?>">
<?php endif; ?>
<div class="tablo-kap"><table class="cetvel">
  <thead><tr><th></th><th>İrsaliye no</th><th>Tarih</th><th>Fatura</th></tr></thead>
  <tbody>
  <?php foreach ($irsaliyeler as $i): ?>
    <tr>
      <td><?php if ($i['fatura_id'] === null && can('fatura.kes')): ?><input type="checkbox" name="irsaliye[]" value="<?= (int)$i['id'] ?>" aria-label="<?= e($i['belge_no']) ?> faturaya ekle" checked><?php endif; ?></td>
      <td class="kod"><?= e($i['belge_no']) ?></td>
      <td><?= e(date('d.m.Y', strtotime($i['tarih']))) ?></td>
      <td><?= $i['fatura_id'] ? '<a href="' . e(url('/faturalar/goster?id=' . (int)$i['fatura_id'])) . '">' . e($i['fatura_no']) . '</a>' : 'Faturalanmadı' ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php if ($faturasizlar && can('fatura.kes')): ?>
  <label for="vade">Vade (gün)</label>
  <input id="vade" name="vade_gun" type="number" min="0" max="365" value="30" class="dar">
  <div class="satir"><button class="ana-dugme" type="submit">İşaretli irsaliyeleri faturala</button></div>
</form>
<?php endif; ?>
<?php endif; ?>

<?php if ($faturalar): ?>
<h2>Faturalar</h2>
<div class="tablo-kap"><table class="cetvel dar">
  <thead><tr><th>Fatura no</th><th>Tarih</th><th>Vade</th><th class="sayi">Toplam</th></tr></thead>
  <tbody>
  <?php foreach ($faturalar as $f): ?>
    <tr><td class="kod"><a href="<?= e(url('/faturalar/goster?id=' . (int)$f['id'])) ?>"><?= e($f['belge_no']) ?></a></td><td><?= e(date('d.m.Y', strtotime($f['tarih']))) ?></td><td><?= e(date('d.m.Y', strtotime($f['vade_tarihi']))) ?></td><td class="sayi"><?= e(tl($f['toplam'])) ?></td></tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php endif; ?>
