<div class="asistan-not" role="note">
  <strong>Bu asistan yalnızca okur.</strong> Kayıt ekleyemez, değiştiremez, silemez; sipariş, fatura veya tahsilat oluşturamaz. Yetkinizin olmadığı bilgiyi de göstermez.
  Cevap kaynağı: <?= $llm ? 'yapay zekâ (Claude) — ancak yalnızca salt okunur araçlarla' : 'anahtar kelime eşleştirme (yapay zekâ anahtarı tanımlı değil)' ?>.
</div>
<form method="post" action="<?= e(url('/asistan')) ?>" class="form-blok genis">
  <?= csrf_field() ?>
  <label for="soru">Sorunuz</label>
  <div class="satir"><input id="soru" name="soru" maxlength="500" required autocomplete="off" placeholder="Örn: Kritik stokta ne var?" style="flex:1 1 24rem"><button class="ana-dugme" type="submit">Sor</button></div>
  <p class="not">Örnekler: “Kritik stokta ne var?”, “Geciken faturalar hangileri?”, “Bugünkü satış ne kadar?”, “Çimento stoğu kaç?”, “Yıldız İnşaat bakiyesi ne kadar?”, “S-2026-000001 siparişi hangi durumda?”</p>
</form>
<?php foreach ($gecmis as $g): $y = $g['yanit']; ?>
<section class="sohbet-kayit">
  <p class="soru"><strong>Siz:</strong> <?= e($g['soru']) ?></p>
  <div class="yanit">
    <?php if ($y['metin'] !== ''): ?><p><?= nl2br(e($y['metin'])) ?></p><?php endif; ?>
    <?php if ($y['tablo']): ?>
    <div class="tablo-kap"><table class="cetvel dar">
      <thead><tr><?php foreach ($y['tablo']['basliklar'] as $b): ?><th><?= e(str_replace('_', ' ', $b)) ?></th><?php endforeach; ?></tr></thead>
      <tbody><?php foreach ($y['tablo']['satirlar'] as $s): ?><tr><?php foreach ($s as $h): ?><td><?= e($h) ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
    <p class="not">Kaynak: <?= $y['kaynak'] === 'yapay_zeka' ? 'yapay zekâ' : 'anahtar kelime eşleştirme' ?><?= $y['araclar'] ? ' · kullanılan okuma aracı: ' . e(implode(', ', $y['araclar'])) : '' ?></p>
  </div>
</section>
<?php endforeach; ?>
