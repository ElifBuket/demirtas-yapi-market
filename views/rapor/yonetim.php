<?php
use App\Services\Hesap;
$g = fn(int $k) => Hesap::goster($k);
$maks = max(1, ...array_column($nakit['gunler'], 'toplam'));
$W = 720; $H = 180; $n = count($nakit['gunler']); $bw = $W / $n;
?>
<section aria-labelledby="ozet-b">
  <h2 id="ozet-b">Bugün (<?= e(date('d.m.Y', strtotime($bugun))) ?>)</h2>
  <div class="kartlar">
    <div class="kart"><span class="kart-ad">Bugünkü satış</span><strong><?= e($g($o['gunluk_satis'])) ?> ₺</strong></div>
    <div class="kart"><span class="kart-ad">Bu ayki satış</span><strong><?= e($g($o['aylik_satis'])) ?> ₺</strong></div>
    <div class="kart"><span class="kart-ad">Toplam alacak (TL müşteriler)</span><strong><?= e($g($o['alacak'])) ?> ₺</strong><?php if ($o['alacak_eur'] > 0): ?><small>+ <?= e($g($o['alacak_eur'])) ?> € (ayrı)</small><?php endif; ?></div>
    <div class="kart"><span class="kart-ad">Tedarikçilere borç</span><strong><?= e($g($o['borc'])) ?> ₺</strong></div>
    <div class="kart"><span class="kart-ad">Portföydeki çek/senet</span><strong><?= e($g($o['portfoy_cek'])) ?> ₺</strong></div>
    <div class="kart"><span class="kart-ad">Stok değeri (alış fiyatıyla)</span><strong><?= e($g($o['stok_degeri'])) ?> ₺</strong></div>
    <div class="kart<?= $o['kritik_stok'] > 0 ? ' uyari' : '' ?>"><span class="kart-ad">Kritik stok</span><strong><a href="<?= e(url('/stok?kritik=1')) ?>"><?= e($o['kritik_stok']) ?> ürün</a></strong></div>
    <div class="kart<?= $o['onay_bekleyen'] > 0 ? ' uyari' : '' ?>"><span class="kart-ad">Onay bekleyen sipariş</span><strong><a href="<?= e(url('/siparisler?durum=onay_bekliyor')) ?>"><?= e($o['onay_bekleyen']) ?></a></strong></div>
  </div>
  <?php if ($o['avans'] > 0): ?><p class="not">Alacak toplamına dahil değil: müşterilerin avans bakiyesi <?= e($g($o['avans'])) ?> ₺.</p><?php endif; ?>
</section>

<section aria-labelledby="yas-b">
  <h2 id="yas-b">Fatura vade yaşlandırması</h2>
  <table class="cetvel dar">
    <thead><tr><th>Durum</th><th class="sayi">Tutar (₺)</th></tr></thead>
    <tbody>
      <tr><td>Vadesi gelmemiş</td><td class="sayi"><?= e($g($y['vadesi_gelmemis'])) ?></td></tr>
      <tr><td>0–30 gün gecikmiş</td><td class="sayi"><?= e($g($y['g0_30'])) ?></td></tr>
      <tr><td>31–60 gün gecikmiş</td><td class="sayi"><?= e($g($y['g31_60'])) ?></td></tr>
      <tr><td>61–90 gün gecikmiş</td><td class="sayi"><?= e($g($y['g61_90'])) ?></td></tr>
      <tr><td>90 günden fazla gecikmiş</td><td class="sayi"><?= e($g($y['g90_ustu'])) ?></td></tr>
    </tbody>
    <tfoot><tr><th scope="row">Açık fatura toplamı</th><td class="sayi"><?= e($g($y['fatura_toplam'])) ?></td></tr></tfoot>
  </table>
  <p class="not">Eski sistemden aktarılan devir bakiyelerinin (<?= e($g($devir)) ?> ₺ borçlu açılış) fatura/vade bilgisi olmadığı için bu tabloya katılmaz; vadesiz alacak olarak izlenir.</p>
</section>

<section aria-labelledby="nakit-b">
  <h2 id="nakit-b">Önümüzdeki 30 gün: beklenen tahsilat</h2>
  <svg viewBox="0 0 <?= $W ?> <?= $H + 24 ?>" role="img" aria-label="30 günlük beklenen tahsilat grafiği" class="grafik">
    <line x1="0" y1="<?= $H ?>" x2="<?= $W ?>" y2="<?= $H ?>" class="eksen"/>
    <?php foreach ($nakit['gunler'] as $i => $d):
      $hf = (int)round($H * $d['fatura'] / $maks); $hc = (int)round($H * $d['cek'] / $maks); $x = $i * $bw + 2; ?>
      <g>
        <title><?= e(date('d.m.Y', strtotime($d['tarih'])) . ': fatura ' . $g($d['fatura']) . ' ₺, çek/senet ' . $g($d['cek']) . ' ₺') ?></title>
        <rect x="<?= round($x, 1) ?>" y="<?= $H - $hf ?>" width="<?= round($bw - 4, 1) ?>" height="<?= $hf ?>" class="cubuk-fatura"/>
        <rect x="<?= round($x, 1) ?>" y="<?= $H - $hf - $hc ?>" width="<?= round($bw - 4, 1) ?>" height="<?= $hc ?>" class="cubuk-cek"/>
      </g>
      <?php if ($i % 5 === 0): ?><text x="<?= round($x, 1) ?>" y="<?= $H + 16 ?>" class="etiket"><?= e(date('d.m', strtotime($d['tarih']))) ?></text><?php endif; ?>
    <?php endforeach; ?>
  </svg>
  <p class="lejant"><span class="nokta fatura"></span> Fatura vadesi <span class="nokta cek"></span> Çek/senet vadesi</p>
  <table class="cetvel dar">
    <tbody>
      <tr><th scope="row">30 günde beklenen toplam giriş</th><td class="sayi"><?= e($g($nakit['toplam_giris'])) ?> ₺</td></tr>
      <tr><th scope="row">Vadesi geçmiş, tahsil edilmemiş faturalar</th><td class="sayi"><?= e($g($nakit['gecikmis'])) ?> ₺</td></tr>
      <tr><th scope="row">30 günden sonra vadesi gelen faturalar</th><td class="sayi"><?= e($g($nakit['ufuk_disi'])) ?> ₺</td></tr>
    </tbody>
  </table>
  <p class="not">Bu tablo yalnızca beklenen tahsilatı gösterir. Tedarikçi ödemelerinin vade bilgisi sistemde olmadığı için çıkışlar hesaba katılmadı; net nakit akışı değildir. Devir bakiyeleri de vadesiz olduğundan dahil değildir.</p>
  <?php if ($gecikmis_cekler): ?>
    <h3>Vadesi geçmiş çek/senetler (takip edin)</h3>
    <table class="cetvel dar"><tbody>
      <?php foreach ($gecikmis_cekler as $c): ?><tr><td class="kod"><?= e($c['belge_no']) ?> · <?= e($c['evrak_no']) ?></td><td><?= e($c['unvan']) ?></td><td><?= e(date('d.m.Y', strtotime($c['vade_tarihi']))) ?></td><td class="sayi"><?= e(tl($c['tutar'])) ?></td></tr><?php endforeach; ?>
    </tbody></table>
  <?php endif; ?>
</section>

<div class="iki-sutun">
  <section aria-labelledby="bor-b">
    <h2 id="bor-b">En çok borcu olan 5 müşteri</h2>
    <?php if (!$borclular): ?><p class="bos-durum">Borçlu müşteri yok.</p><?php else: ?>
    <table class="cetvel"><tbody><?php foreach ($borclular as $b): ?><tr><td><?= e($b['unvan']) ?></td><td class="sayi"><?= e(tl($b['bakiye'])) ?></td></tr><?php endforeach; ?></tbody></table>
    <?php endif; ?>
  </section>
  <section aria-labelledby="al-b">
    <h2 id="al-b">Son 30 günde en çok alan 5 müşteri</h2>
    <?php if (!$alanlar): ?><p class="bos-durum">Son 30 günde fatura yok.</p><?php else: ?>
    <table class="cetvel"><tbody><?php foreach ($alanlar as $b): ?><tr><td><?= e($b['unvan']) ?></td><td class="sayi"><?= e(tl($b['toplam'])) ?></td></tr><?php endforeach; ?></tbody></table>
    <?php endif; ?>
  </section>
</div>
