<?php use App\Services\Hesap; $g = fn(int $k) => Hesap::goster($k); $d = fn(string $t) => date('d.m.Y', strtotime($t)); ?>
<form method="get" action="<?= e(url('/cariler/ekstre')) ?>" class="filtre yazdirma-gizle">
  <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
  <label for="bas">Başlangıç</label><input id="bas" name="bas" type="date" value="<?= e($bas) ?>">
  <label for="bit">Bitiş</label><input id="bit" name="bit" type="date" value="<?= e($bit) ?>">
  <button class="ana-dugme kucuk" type="submit">Göster</button>
  <button class="ikincil kucuk" type="button" onclick="window.print()">Yazdır / PDF</button>
</form>
<h2 class="yazdirma-baslik">Cari ekstre</h2>
<p><strong><?= e($c['kod']) ?> · <?= e($c['unvan']) ?></strong><?= $c['vkn'] ? ' · VKN ' . e($c['vkn']) : '' ?><br><?= e($d($bas)) ?> – <?= e($d($bit)) ?> · Para birimi: <?= e($c['para_birimi']) ?></p>
<div class="tablo-kap"><table class="cetvel">
  <thead><tr><th>Tarih</th><th>Tür</th><th>Açıklama</th><th class="sayi">Borç</th><th class="sayi">Alacak</th><th class="sayi">Bakiye</th></tr></thead>
  <tbody>
    <tr><td colspan="5"><em>Devreden bakiye (<?= e($d($bas)) ?> öncesi)</em></td><td class="sayi"><?= e($g($e['acilis'])) ?></td></tr>
    <?php foreach ($e['satirlar'] as $s): ?>
    <tr><td><?= e($d($s['tarih'])) ?></td><td><?= e($s['tur']) ?></td><td><?= e($s['aciklama']) ?></td>
      <td class="sayi"><?= $s['borc'] ? e($g($s['borc'])) : '' ?></td><td class="sayi"><?= $s['alacak'] ? e($g($s['alacak'])) : '' ?></td><td class="sayi"><?= e($g($s['bakiye'])) ?></td></tr>
    <?php endforeach; ?>
  </tbody>
  <tfoot>
    <tr><th colspan="3" scope="row">Dönem toplamı</th><td class="sayi"><?= e($g($e['borc'])) ?></td><td class="sayi"><?= e($g($e['alacak'])) ?></td><td></td></tr>
    <tr><th colspan="5" scope="row">Kapanış bakiyesi (<?= $e['kapanis'] >= 0 ? 'müşteri borçlu' : 'alacaklı' ?>)</th><td class="sayi"><strong><?= e($g($e['kapanis'])) ?></strong></td></tr>
  </tfoot>
</table></div>
<?php if (!$e['satirlar']): ?><p class="bos-durum">Bu dönemde hareket yok.</p><?php endif; ?>
