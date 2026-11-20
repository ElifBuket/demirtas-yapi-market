import test from 'node:test';
import assert from 'node:assert/strict';
import * as Q from '../src/queue.js';

const cari = { id: 7, unvan: 'Test Ltd.' };
const kalem = [{ urun_id: 1, ad: 'Çimento', miktar: '2,5', birim: '' }];
const mk = (a) => Q.girdiOlustur({ anahtar: a, cari, kalemler: kalem, tarih: '2026-06-01', simdi: 1 });

test('anahtar 32 haneli onaltılık', () => {
  assert.match(Q.yeniAnahtar(new Uint8Array(16).fill(255)), /^[0-9a-f]{32}$/);
  assert.throws(() => Q.yeniAnahtar(new Uint8Array(8)));
});
test('girdi: miktar virgülü noktaya çevrilir, durum bekliyor', () => {
  const g = mk('a'.repeat(32));
  assert.equal(g.kalemler[0].miktar, '2.5'); assert.equal(g.durum, 'bekliyor');
});
test('girdi doğrulama', () => {
  assert.throws(() => Q.girdiOlustur({ anahtar: 'x', cari: null, kalemler: kalem, tarih: 't', simdi: 1 }));
  assert.throws(() => Q.girdiOlustur({ anahtar: 'x', cari, kalemler: [], tarih: 't', simdi: 1 }));
  assert.throws(() => Q.girdiOlustur({ anahtar: 'x', cari, kalemler: [{ urun_id: 1, miktar: '0' }], tarih: 't', simdi: 1 }));
  assert.throws(() => Q.girdiOlustur({ anahtar: 'x', cari, kalemler: [{ urun_id: 1, miktar: '-1' }], tarih: 't', simdi: 1 }));
  assert.throws(() => Q.girdiOlustur({ anahtar: 'x', cari, kalemler: [{ urun_id: 1, miktar: 'abc' }], tarih: 't', simdi: 1 }));
});
test('miktarGecerli', () => {
  for (const s of ['1', '2,5', '0.001', '10']) assert.ok(Q.miktarGecerli(s), s);
  for (const s of ['', '0', '1.2345', '-3', '1e3', null]) assert.ok(!Q.miktarGecerli(s), String(s));
});
test('gövdede fiyat yok', () => {
  const b = Q.govdeHazirla([mk('a'.repeat(32))]);
  assert.ok(!JSON.stringify(b).includes('fiyat'));
  assert.equal(b.siparisler.length, 1);
});
test('gövde en çok 50 sipariş ve yalnızca bekleyenler', () => {
  const k = Array.from({ length: 60 }, (_, i) => mk(i.toString(16).padStart(32, '0')));
  k[0] = { ...k[0], durum: 'gonderildi' };
  const b = Q.govdeHazirla(k);
  assert.equal(b.siparisler.length, 50);
  assert.ok(!b.siparisler.some((s) => s.istemci_anahtar === k[0].istemci_anahtar));
});
test('sonuç: olustu / zaten_var → gonderildi', () => {
  const a = 'a'.repeat(32), b = 'b'.repeat(32);
  const y = Q.sonuclariUygula([mk(a), mk(b)], [{ istemci_anahtar: a, durum: 'olustu', belge_no: 'S-1' }, { istemci_anahtar: b, durum: 'zaten_var', belge_no: 'S-2' }]);
  assert.deepEqual(y.map((g) => [g.durum, g.belge_no]), [['gonderildi', 'S-1'], ['gonderildi', 'S-2']]);
});
test('sonuç: kalıcı hata → hata; geçici hata → bekliyor + deneme+1', () => {
  const a = 'a'.repeat(32), b = 'b'.repeat(32);
  const y = Q.sonuclariUygula([mk(a), mk(b)], [{ istemci_anahtar: a, durum: 'hata', kalici: true, mesaj: 'Müşteri yok' }, { istemci_anahtar: b, durum: 'hata', kalici: false, mesaj: 'Sunucu' }]);
  assert.equal(y[0].durum, 'hata'); assert.equal(y[0].mesaj, 'Müşteri yok');
  assert.equal(y[1].durum, 'bekliyor'); assert.equal(y[1].deneme, 1);
});
test('anahtar yeniden denemelerde değişmez', () => {
  const a = 'c'.repeat(32);
  let k = [mk(a)];
  k = Q.gecicihata(k, [a], 'ağ yok'); k = Q.gecicihata(k, [a], 'ağ yok');
  assert.equal(k[0].istemci_anahtar, a); assert.equal(k[0].deneme, 2); assert.equal(k[0].durum, 'bekliyor');
  assert.equal(Q.govdeHazirla(k).siparisler[0].istemci_anahtar, a);
});
test('girdi değiştirilmez (saf)', () => {
  const a = 'a'.repeat(32); const k = [mk(a)]; const kopya = JSON.stringify(k);
  Q.sonuclariUygula(k, [{ istemci_anahtar: a, durum: 'olustu', belge_no: 'S-1' }]); Q.gecicihata(k, [a], 'x');
  assert.equal(JSON.stringify(k), kopya);
});
test('temizle yalnızca gönderilenleri siler; hata yeniden kuyruğa alınır', () => {
  const a = 'a'.repeat(32), b = 'b'.repeat(32);
  let k = Q.sonuclariUygula([mk(a), mk(b)], [{ istemci_anahtar: a, durum: 'olustu', belge_no: 'S' }, { istemci_anahtar: b, durum: 'hata', kalici: true, mesaj: 'x' }]);
  k = Q.temizle(k); assert.equal(k.length, 1);
  k = Q.hatayiYenidenKuyrugaAl(k, b); assert.equal(k[0].durum, 'bekliyor');
});
test('aynı ürün iki kez eklenirse birleşir', () => {
  const r = Q.kalemleriBirlestir([{ urun_id: 1, miktar: '1.5', birim: '' }, { urun_id: 1, miktar: '2', birim: '' }, { urun_id: 2, miktar: '1', birim: '' }]);
  assert.equal(r.length, 2); assert.equal(r[0].miktar, '3.5');
});
