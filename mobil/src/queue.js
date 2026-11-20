// Çevrimdışı sipariş kuyruğu — saf mantık (React / depolama bağımlılığı yok, node --test ile test edilir).
// Durumlar: bekliyor → (gönderiliyor) → gonderildi | hata (kalıcı) ; geçici hata "bekliyor" kalır, deneme sayısı artar.

export const SURUM = 1;

export function yeniAnahtar(rastgeleBayt) {
  // rastgeleBayt: 16 baytlık Uint8Array (expo-crypto'dan). 32 haneli onaltılık metin.
  if (!rastgeleBayt || rastgeleBayt.length !== 16) throw new Error('16 bayt gerekli');
  return Array.from(rastgeleBayt, (b) => b.toString(16).padStart(2, '0')).join('');
}

export function kalemleriBirlestir(kalemler) {
  const m = new Map();
  for (const k of kalemler) {
    const mevcut = m.get(k.urun_id);
    if (mevcut && mevcut.birim === k.birim) mevcut.miktar = topla(mevcut.miktar, k.miktar);
    else m.set(mevcut ? `${k.urun_id}:${k.birim}` : k.urun_id, { ...k });
  }
  return [...m.values()];
}

function topla(a, b) {
  const x = Math.round(parseFloat(String(a).replace(',', '.')) * 1000);
  const y = Math.round(parseFloat(String(b).replace(',', '.')) * 1000);
  return String((x + y) / 1000);
}

export function miktarGecerli(metin) {
  const s = String(metin ?? '').trim().replace(',', '.');
  return /^\d+(\.\d{1,3})?$/.test(s) && parseFloat(s) > 0;
}

/** Yeni kuyruk girdisi. Anahtar BURADA bir kez üretilir ve yeniden denemelerde asla değişmez. */
export function girdiOlustur({ anahtar, cari, kalemler, tarih, simdi }) {
  if (!anahtar) throw new Error('anahtar gerekli');
  if (!cari) throw new Error('müşteri gerekli');
  if (!kalemler || kalemler.length === 0) throw new Error('en az bir kalem gerekli');
  for (const k of kalemler) if (!miktarGecerli(k.miktar)) throw new Error('geçersiz miktar');
  return {
    istemci_anahtar: anahtar, cari_id: cari.id, cari_unvan: cari.unvan, tarih,
    kalemler: kalemler.map((k) => ({ urun_id: k.urun_id, ad: k.ad, miktar: String(k.miktar).replace(',', '.'), birim: k.birim || '' })),
    durum: 'bekliyor', deneme: 0, olusturma: simdi, mesaj: null, belge_no: null,
  };
}

export function beklenenler(kuyruk) { return kuyruk.filter((g) => g.durum === 'bekliyor'); }

/** Sunucuya gidecek gövde (en çok 50). Fiyat GÖNDERİLMEZ; sunucu belirler. */
export function govdeHazirla(kuyruk, limit = 50) {
  return {
    siparisler: beklenenler(kuyruk).slice(0, limit).map((g) => ({
      istemci_anahtar: g.istemci_anahtar, cari_id: g.cari_id, tarih: g.tarih,
      kalemler: g.kalemler.map((k) => ({ urun_id: k.urun_id, miktar: k.miktar, birim: k.birim })),
    })),
  };
}

/** Sunucu yanıtını kuyruğa uygular. Yeni kuyruk döner (girdi değiştirilmez). */
export function sonuclariUygula(kuyruk, sonuclar) {
  const harita = new Map(sonuclar.map((s) => [s.istemci_anahtar, s]));
  return kuyruk.map((g) => {
    const s = harita.get(g.istemci_anahtar);
    if (!s) return g;
    if (s.durum === 'olustu' || s.durum === 'zaten_var') return { ...g, durum: 'gonderildi', belge_no: s.belge_no, mesaj: s.mesaj || null };
    if (s.durum === 'hata' && s.kalici) return { ...g, durum: 'hata', mesaj: s.mesaj };
    return { ...g, deneme: g.deneme + 1, mesaj: s.mesaj || null }; // geçici hata → bekliyor kalır
  });
}

/** Ağ/sunucu hatasında (yanıt alınamadı): girdiler bekliyor kalır, yalnızca deneme sayısı artar. */
export function gecicihata(kuyruk, anahtarlar, mesaj) {
  const k = new Set(anahtarlar);
  return kuyruk.map((g) => (k.has(g.istemci_anahtar) ? { ...g, deneme: g.deneme + 1, mesaj } : g));
}

export function temizle(kuyruk) { return kuyruk.filter((g) => g.durum !== 'gonderildi'); }
export function hatayiYenidenKuyrugaAl(kuyruk, anahtar) {
  return kuyruk.map((g) => (g.istemci_anahtar === anahtar && g.durum === 'hata' ? { ...g, durum: 'bekliyor', mesaj: null } : g));
}
