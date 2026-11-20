// InfinityFree gibi ücretsiz hostinglerin "bot koruması": ilk istekte JSON yerine, tarayıcının çözdüğü küçük bir
// JavaScript sınavı döner (toNumbers("..") ×3 → AES-128-CBC çözülür → __test çerezi). Tarayıcı bunu kendiliğinden yapar,
// bir mobil uygulama yapmaz; bu dosya aynı hesabı yapıp isteği çerezle tekrarlamamızı sağlar. Yalnızca KENDİ sunucumuza
// yapılan isteklerde ve yanıt bu sayfaysa devreye girer; sınav yoksa hiçbir şey yapmaz.
import aesjs from 'aes-js';

const hexten = (h) => { const b = []; for (let i = 0; i + 1 < h.length; i += 2) b.push(parseInt(h.slice(i, i + 2), 16)); return b; };

/** @returns {string|null} "__test=<hex>" ya da koruma sayfası değilse null */
export function botSorusunuCoz(metin) {
  if (typeof metin !== 'string' || !metin.includes('toNumbers(')) return null;
  const v = [...metin.matchAll(/toNumbers\("([0-9a-fA-F]+)"\)/g)].map((m) => m[1]);
  if (v.length < 3) return null;
  const [anahtar, iv, sifreli] = v.map(hexten);
  if (anahtar.length !== 16 || iv.length !== 16 || sifreli.length % 16 !== 0 || !sifreli.length) return null;
  const acik = new aesjs.ModeOfOperation.cbc(anahtar, iv).decrypt(Uint8Array.from(sifreli));
  return '__test=' + aesjs.utils.hex.fromBytes(acik);
}
