import AsyncStorage from '@react-native-async-storage/async-storage';
const A = { oturum: 'dy.oturum', onbellek: 'dy.onbellek', kuyruk: 'dy.kuyruk' };
async function oku(k, varsayilan) { try { const v = await AsyncStorage.getItem(k); return v ? JSON.parse(v) : varsayilan; } catch (e) { return varsayilan; } }
async function yaz(k, v) { await AsyncStorage.setItem(k, JSON.stringify(v)); }
export const oturumOku = async () => {
  const o = await oku(A.oturum, null);
  // Bozuk/eksik kayıtlı oturum (ör. eski sürümden kalan) uygulamayı çökertmesin.
  if (o && o.sunucu && o.token && o.kullanici && o.kullanici.ad_soyad) return o;
  if (o) await AsyncStorage.removeItem(A.oturum);
  return null;
};
export const oturumYaz = (o) => yaz(A.oturum, o);
export const oturumSil = () => AsyncStorage.removeItem(A.oturum);
export const onbellekOku = () => oku(A.onbellek, { musteriler: [], urunler: [], zaman: null });
export const onbellekYaz = (o) => yaz(A.onbellek, o);
export const kuyrukOku = () => oku(A.kuyruk, []);
export const kuyrukYaz = (k) => yaz(A.kuyruk, k);
