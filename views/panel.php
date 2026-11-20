<p class="giris-metin"><?= e($u['ad_soyad']) ?>, <?= e(mb_strtolower($rol_adi, 'UTF-8')) ?> olarak giriş yaptınız.</p>

<table class="cetvel dar">
  <caption>Veri durumu</caption>
  <tbody>
    <tr><th scope="row">Cari hesap</th><td class="sayi"><?= e($sayilar['cari']) ?></td></tr>
    <tr><th scope="row">Sipariş girilemeyen (engelli) cari</th><td class="sayi"><?= e($sayilar['engelli']) ?></td></tr>
    <?php if (can('stok.goruntule')): ?>
    <tr><th scope="row">Ürün</th><td class="sayi"><?= e($sayilar['urun']) ?></td></tr>
    <tr><th scope="row">Kritik stok seviyesinde ürün</th><td class="sayi"><?= can('stok.goruntule') ? '<a href="' . e(url('/stok?kritik=1')) . '">' . e($sayilar['kritik']) . '</a>' : '' ?></td></tr>
    <?php endif; ?>
    <?php if (can('cari.duzenle')): ?>
    <tr><th scope="row">Karar bekleyen aktarım satırı</th><td class="sayi"><?= e($sayilar['bekleyen']) ?></td></tr>
    <?php endif; ?>
  </tbody>
</table>
