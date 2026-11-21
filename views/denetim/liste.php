<?php $adlar = ['giris' => 'Giriş', 'giris_basarisiz' => 'Başarısız giriş', 'cikis' => 'Çıkış', 'ekle' => 'Ekleme', 'degistir' => 'Değiştirme', 'sil' => 'Silme', 'yetkisiz' => 'Yetkisiz deneme']; ?>
<form method="get" action="<?= e(url('/denetim')) ?>" class="filtre">
  <label for="eylem">Eylem</label>
  <select id="eylem" name="eylem">
    <option value="">Tümü</option>
    <?php foreach ($eylemler as $x): ?>
      <option value="<?= e($x) ?>"<?= $eylem === $x ? ' selected' : '' ?>><?= e($adlar[$x]) ?></option>
    <?php endforeach; ?>
  </select>
  <button class="ana-dugme kucuk" type="submit">Filtrele</button>
</form>
<p class="sonuc-ozet" id="zincir-durum">
  <?php if ($zincir['durum'] === 'tamam'): ?>
    <span class="rozet">Zincir doğrulandı</span> <?= e($zincir['mesaj']) ?> Kayıtlar sonradan değiştirilmemiş.
  <?php elseif ($zincir['durum'] === 'bozuk'): ?>
    <span class="rozet engelli">Zincir bozuk</span> <?= e($zincir['mesaj']) ?>
  <?php else: ?>
    <span class="rozet">Zincir yok</span> <?= e($zincir['mesaj']) ?>
  <?php endif; ?>
  Son 200 kayıt gösteriliyor.
</p>
<?php if (!$satirlar): ?>
  <p class="bos-durum">Henüz kayıt yok. Giriş, çıkış ve veri değişiklikleri burada görünür.</p>
<?php else: ?>
<div class="tablo-kap">
<table class="cetvel">
  <thead><tr><th>Zaman</th><th>Kullanıcı</th><th>Rol</th><th>Eylem</th><th>Kayıt</th><th>Açıklama</th><th>IP</th></tr></thead>
  <tbody>
  <?php foreach ($satirlar as $s): ?>
    <tr>
      <td class="kod"><?= e(substr((string)$s['zaman'], 0, 19)) ?></td>
      <td><?= e($s['kullanici_adi'] ?? '—') ?></td>
      <td><?= e($s['rol'] ?? '') ?></td>
      <td><span class="rozet <?= in_array($s['eylem'], ['giris_basarisiz', 'yetkisiz'], true) ? 'engelli' : '' ?>"><?= e($adlar[$s['eylem']] ?? $s['eylem']) ?></span></td>
      <td class="kod"><?= e(trim(($s['tablo'] ?? '') . ' ' . ($s['kayit_id'] ?? ''))) ?></td>
      <td><?= e($s['aciklama']) ?></td>
      <td class="kod"><?= e($s['ip']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
