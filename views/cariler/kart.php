<?php
use App\Services\Hesap;
$g = fn(int $k) => Hesap::goster($k);
$sembol = $c['para_birimi'] === 'EUR' ? '€' : ($c['para_birimi'] === 'USD' ? '$' : '₺');
$risk = (int)$c['risk_limitsiz'] ? 'Limitsiz' : ($c['risk_limiti'] === null ? 'Tanımsız (her sipariş patron onayı ister)' : tl($c['risk_limiti']) . ' ' . $sembol);
$maruz = $bakiye + $acik_siparis;
$musteri = $c['tip'] === 'musteri';
?>
<p class="giris-metin"><span class="kod"><?= e($c['kod']) ?></span> · <?= $c['tip'] === 'musteri' ? 'Müşteri' : 'Tedarikçi' ?> · <?= $c['durum'] === 'engelli' ? '<span class="rozet engelli">Sipariş girilemez</span>' : 'Aktif' ?></p>
<?php if ($c['uyari_notu']): ?><p class="hata"><?= e($c['uyari_notu']) ?></p><?php endif; ?>
<div class="aksiyonlar">
  <a class="ikincil" href="<?= e(url('/cariler/ekstre?id=' . (int)$c['id'])) ?>">Ekstre</a>
  <?php if (can('cari.duzenle')): ?><a class="ikincil" href="<?= e(url('/cariler/duzenle?id=' . (int)$c['id'])) ?>">Düzenle</a><?php endif; ?>
  <?php if (can('tahsilat.gir') && $c['tip'] === 'musteri'): ?><a class="ikincil" href="<?= e(url('/tahsilatlar/yeni?cari=' . (int)$c['id'])) ?>">Tahsilat gir</a><?php endif; ?>
</div>
<div class="kartlar">
  <div class="kart<?= $musteri && $bakiye > 0 ? ' uyari' : '' ?>"><span class="kart-ad"><?= $musteri ? 'Bakiye (' . ($bakiye >= 0 ? 'bize borçlu' : 'avans / alacaklı') . ')' : 'Bakiye (' . ($bakiye <= 0 ? 'tedarikçiye borcumuz' : 'tedarikçide alacağımız var') . ')' ?></span><strong><?= e($g($bakiye)) ?> <?= e($sembol) ?></strong>
    <?php if ($tl_karsiligi !== null): ?><small>≈ <?= e($g($tl_karsiligi)) ?> ₺ (kur <?= e(number_format($kur['kur4'] / 10000, 4, ',', '.')) ?>, <?= e(date('d.m.Y', strtotime($kur['tarih']))) ?>, <?= e($kur['kaynak']) ?>)</small><?php endif; ?></div>
  <?php if ($musteri): ?>
  <div class="kart"><span class="kart-ad">Faturalanmamış açık sipariş</span><strong><?= e($g($acik_siparis)) ?> <?= e($sembol) ?></strong></div>
  <div class="kart"><span class="kart-ad">Risk limiti</span><strong><?= e($risk) ?></strong><small>Kullanım: <?= e($g($maruz)) ?> <?= e($sembol) ?></small></div>
  <?php endif; ?>
</div>
<?php if ($c['para_birimi'] !== 'TRY' && can('cari.duzenle')): ?>
<div class="aksiyonlar kur-form">
  <form method="post" action="<?= e(url('/kur/guncelle')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button class="ikincil" type="submit">Kuru TCMB'den güncelle</button></form>
  <form method="post" action="<?= e(url('/kur/elle')) ?>" class="satir"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><input type="hidden" name="kod" value="<?= e($c['para_birimi']) ?>">
    <label for="kur">Kuru elle gir (1 <?= e($c['para_birimi']) ?> = ? ₺)</label><input id="kur" name="kur" inputmode="decimal" maxlength="12" class="dar" placeholder="36,6789"><button class="ikincil kucuk" type="submit">Kaydet</button></form>
</div>
<?php if (!$kur): ?><p class="not">Bu para birimi için kayıtlı kur yok; TL karşılığı gösterilemiyor.</p><?php endif; ?>
<?php endif; ?>

<h2>Bilgiler</h2>
<table class="cetvel dar"><tbody>
  <tr><th scope="row">Ünvan</th><td><?= e($c['unvan']) ?></td></tr>
  <tr><th scope="row">VKN / TCKN</th><td><?= $c['vkn'] ? e($c['vkn']) . ((int)$c['vkn_gecerli'] ? '' : ' <span class="rozet engelli">Geçersiz</span>') : '<span class="rozet engelli">Eksik</span> (e-Fatura için gerekli)' ?></td></tr>
  <tr><th scope="row">Vergi dairesi</th><td><?= e($c['vergi_dairesi']) ?></td></tr>
  <tr><th scope="row">Telefon</th><td><?= e($c['telefon']) ?></td></tr>
  <tr><th scope="row">E-posta</th><td><?= e($c['eposta']) ?></td></tr>
  <tr><th scope="row">İl / ilçe</th><td><?= e(trim($c['il'] . ' / ' . $c['ilce'], ' /')) ?></td></tr>
  <tr><th scope="row">Satışçı</th><td><?= e($c['satisci'] ?: ($c['satisci_ham'] ? $c['satisci_ham'] . ' (atanmamış)' : 'Atanmamış')) ?></td></tr>
  <tr><th scope="row">Para birimi</th><td><?= e($c['para_birimi']) ?></td></tr>
  <?php if ($c['ham_not']): ?><tr><th scope="row">Eski sistem notu</th><td><?= e($c['ham_not']) ?></td></tr><?php endif; ?>
</tbody></table>

<?php if ($musteri): ?>
<h2>Açık fatura yaşlandırması</h2>
<table class="cetvel dar"><tbody>
  <tr><td>Vadesi gelmemiş</td><td class="sayi"><?= e($g($yas['vadesi_gelmemis'])) ?></td></tr>
  <tr><td>0–30 gün gecikmiş</td><td class="sayi"><?= e($g($yas['g0_30'])) ?></td></tr>
  <tr><td>31–60 gün</td><td class="sayi"><?= e($g($yas['g31_60'])) ?></td></tr>
  <tr><td>61–90 gün</td><td class="sayi"><?= e($g($yas['g61_90'])) ?></td></tr>
  <tr><td>90 günden fazla</td><td class="sayi"><?= e($g($yas['g90_ustu'])) ?></td></tr>
</tbody></table>
<p class="not">Yalnızca sistemde kesilen faturalar. Eski sistemden gelen devir bakiyesinin vade bilgisi yoktur.</p>
<?php endif; ?>

<h2>Son hareketler</h2>
<?php if (!$hareketler): ?><p class="bos-durum">Hareket yok.</p><?php else: ?>
<div class="tablo-kap"><table class="cetvel">
  <thead><tr><th>Tarih</th><th>Tür</th><th>Açıklama</th><th class="sayi">Borç</th><th class="sayi">Alacak</th></tr></thead>
  <tbody><?php foreach ($hareketler as $h): ?>
    <tr><td><?= e(date('d.m.Y', strtotime($h['tarih']))) ?></td><td><?= e($h['tur']) ?></td><td><?= e($h['aciklama']) ?></td><td class="sayi"><?= (float)$h['borc'] ? e(tl($h['borc'])) : '' ?></td><td class="sayi"><?= (float)$h['alacak'] ? e(tl($h['alacak'])) : '' ?></td></tr>
  <?php endforeach; ?></tbody>
</table></div>
<?php endif; ?>
<?php if ($siparisler): ?>
<h2>Son siparişler</h2>
<table class="cetvel dar"><tbody><?php foreach ($siparisler as $s): ?>
  <tr><td class="kod"><a href="<?= e(url('/siparisler/goster?id=' . (int)$s['id'])) ?>"><?= e($s['belge_no']) ?></a></td><td><?= e(date('d.m.Y', strtotime($s['tarih']))) ?></td><td><?= e(durum_ad($s['durum'])) ?></td><td class="sayi"><?= e(tl($s['toplam'])) ?></td></tr>
<?php endforeach; ?></tbody></table>
<?php endif; ?>
