// Sunucu iletişimi. Sunucu adresi ve jeton cihazda saklanır; parola SAKLANMAZ.
export class ApiHatasi extends Error {
  constructor(mesaj, kod) { super(mesaj); this.kod = kod; }
}

async function istek(sunucu, yontem, yol, govde, jeton) {
  const url = sunucu.replace(/\/+$/, '') + yol;
  const ctrl = new AbortController();
  const zamanAsimi = setTimeout(() => ctrl.abort(), 20000);
  let y;
  try {
    y = await fetch(url, {
      method: yontem, signal: ctrl.signal,
      headers: { 'Content-Type': 'application/json', ...(jeton ? { Authorization: `Bearer ${jeton}` } : {}) },
      body: govde === undefined ? undefined : JSON.stringify(govde),
    });
  } catch (e) {
    throw new ApiHatasi('Sunucuya ulaşılamadı.', 0); // ağ yok / zaman aşımı
  } finally { clearTimeout(zamanAsimi); }
  let j = {};
  try { j = await y.json(); } catch (e) { /* JSON olmayabilir */ }
  if (!y.ok) throw new ApiHatasi(j.hata || `Sunucu hatası (${y.status}).`, y.status);
  return j;
}

export const giris = (sunucu, kullanici_adi, parola) => istek(sunucu, 'POST', '/api/v1/giris', { kullanici_adi, parola, cihaz: 'android' });
export const cikis = (sunucu, jeton) => istek(sunucu, 'POST', '/api/v1/cikis', {}, jeton);
export const senkron = (sunucu, jeton) => istek(sunucu, 'GET', '/api/v1/senkron', undefined, jeton);
export const siparisGonder = (sunucu, jeton, govde) => istek(sunucu, 'POST', '/api/v1/siparisler', govde, jeton);
export const sunucuSiparisleri = (sunucu, jeton) => istek(sunucu, 'GET', '/api/v1/siparisler', undefined, jeton);
