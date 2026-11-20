import { botSorusunuCoz } from './botkoruma.js';

let botCerezi = null; // ücretsiz hostingin bot koruması çözüldüyse çerez (bellekte; uygulama açılışında yeniden çözülür)

// Sunucu iletişimi. Sunucu adresi ve jeton cihazda saklanır; parola SAKLANMAZ.
export class ApiHatasi extends Error {
  constructor(mesaj, kod) { super(mesaj); this.kod = kod; }
}

async function istek(sunucu, yontem, yol, govde, jeton, tekrar = true) {
  const url = sunucu.replace(/\/+$/, '') + yol + (!tekrar && botCerezi ? (yol.includes('?') ? '&' : '?') + 'i=1' : '');
  const ctrl = new AbortController();
  const zamanAsimi = setTimeout(() => ctrl.abort(), 20000);
  let y;
  try {
    y = await fetch(url, {
      method: yontem, signal: ctrl.signal,
      headers: { 'Content-Type': 'application/json', ...(jeton ? { Authorization: `Bearer ${jeton}` } : {}), ...(botCerezi ? { Cookie: botCerezi } : {}) },
      body: govde === undefined ? undefined : JSON.stringify(govde),
    });
  } catch (e) {
    throw new ApiHatasi('Sunucuya ulaşılamadı.', 0); // ağ yok / zaman aşımı
  } finally { clearTimeout(zamanAsimi); }
  const metin = await y.text();
  let j = null;
  try { j = JSON.parse(metin); } catch (e) { /* JSON değil */ }
  if (j === null || typeof j !== 'object') {
    // Hosting bot koruması sayfası ise bir kez çöz ve isteği tekrarla (aynı istek: sunucuda kopya oluşmaz, idempotency anahtarı var).
    const cerez = tekrar ? botSorusunuCoz(metin) : null;
    if (cerez) { botCerezi = cerez; return istek(sunucu, yontem, yol, govde, jeton, false); }
    const ozet = metin.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 80);
    throw new ApiHatasi(`Sunucu beklenmeyen yanıt verdi (HTTP ${y.status}). ${ozet}`, y.status || 0);
  }
  if (!y.ok) throw new ApiHatasi(j.hata || `Sunucu hatası (${y.status}).`, y.status);
  return j;
}

export const giris = (sunucu, kullanici_adi, parola) => istek(sunucu, 'POST', '/api/v1/giris', { kullanici_adi, parola, cihaz: 'android' });
export const cikis = (sunucu, jeton) => istek(sunucu, 'POST', '/api/v1/cikis', {}, jeton);
export const senkron = (sunucu, jeton) => istek(sunucu, 'GET', '/api/v1/senkron', undefined, jeton);
export const siparisGonder = (sunucu, jeton, govde) => istek(sunucu, 'POST', '/api/v1/siparisler', govde, jeton);
export const sunucuSiparisleri = (sunucu, jeton) => istek(sunucu, 'GET', '/api/v1/siparisler', undefined, jeton);
