<?php if ($hata): ?><p class="hata" role="alert"><?= e($hata) ?></p><?php endif; ?>
<p>Fiyat boş bırakılırsa ürünün liste fiyatı (KDV hariç) kullanılır. Fiyat ve miktar ürünün temel birimi üzerindendir; farklı birim seçerseniz miktar temel birime çevrilir.</p>
<form method="post" action="<?= e(url('/siparisler/yeni')) ?>" class="form-blok genis">
  <?= csrf_field() ?>
  <input type="hidden" name="anahtar" value="<?= e($anahtar) ?>">
  <label for="cari">Müşteri</label>
  <select id="cari" name="cari_id" required>
    <option value="">Seçin…</option>
    <?php foreach ($cariler as $c): ?><option value="<?= (int)$c['id'] ?>"<?= (int)($deger['cari'] ?? 0) === (int)$c['id'] ? ' selected' : '' ?>><?= e($c['kod'] . ' · ' . $c['unvan']) ?></option><?php endforeach; ?>
  </select>
  <label for="depo">Çıkış deposu</label>
  <select id="depo" name="depo_id">
    <?php foreach ($depolar as $d): ?><option value="<?= (int)$d['id'] ?>"<?= (int)($deger['depo'] ?? 1) === (int)$d['id'] ? ' selected' : '' ?>><?= e($d['ad']) ?></option><?php endforeach; ?>
  </select>
  <datalist id="urunler">
    <?php foreach ($urunler as $u): ?><option value="<?= e($u['stok_kodu']) ?>"><?= e($u['ad'] . ' (' . $u['temel_birim'] . ')') ?></option><?php endforeach; ?>
  </datalist>
  <div class="tablo-kap"><table class="cetvel">
    <thead><tr><th>#</th><th>Stok kodu</th><th>Miktar</th><th>Birim</th><th>Fiyat (KDV hariç)</th><th>İskonto %</th></tr></thead>
    <tbody>
    <?php for ($i = 0; $i < $satir_sayisi; $i++): ?>
      <tr>
        <td><?= $i + 1 ?></td>
        <td><input name="kod[]" list="urunler" value="<?= e($deger['kod'][$i] ?? '') ?>" maxlength="20" autocomplete="off" aria-label="Stok kodu satır <?= $i + 1 ?>"></td>
        <td><input name="miktar[]" inputmode="decimal" value="<?= e($deger['miktar'][$i] ?? '') ?>" maxlength="14" class="dar" aria-label="Miktar satır <?= $i + 1 ?>"></td>
        <td><input name="birim[]" value="<?= e($deger['birim'][$i] ?? '') ?>" maxlength="10" class="dar" placeholder="temel" aria-label="Birim satır <?= $i + 1 ?>"></td>
        <td><input name="fiyat[]" inputmode="decimal" value="<?= e($deger['fiyat'][$i] ?? '') ?>" maxlength="14" class="dar" placeholder="liste" aria-label="Fiyat satır <?= $i + 1 ?>"></td>
        <td><input name="iskonto[]" inputmode="decimal" value="<?= e($deger['iskonto'][$i] ?? '') ?>" maxlength="6" class="dar" aria-label="İskonto satır <?= $i + 1 ?>"></td>
      </tr>
    <?php endfor; ?>
    </tbody>
  </table></div>
  <label for="genel">Genel iskonto % (isteğe bağlı)</label>
  <input id="genel" name="genel_iskonto" inputmode="decimal" value="<?= e($deger['genel'] ?? '') ?>" maxlength="6">
  <div class="satir"><button class="ana-dugme" type="submit">Siparişi kaydet</button><a class="ikincil" href="<?= e(url('/siparisler')) ?>">Vazgeç</a></div>
</form>
