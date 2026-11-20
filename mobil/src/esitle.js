// Kuyruğu sunucuya gönderir. Aynı anda ikinci çalıştırma engellenir (kilit). Yanıt gelmezse hiçbir şey kaybolmaz:
// girdiler kuyrukta kalır ve AYNI anahtarla tekrar denenir; sunucu çift kayıt oluşturmaz.
import { siparisGonder, ApiHatasi } from './api';
import { kuyrukOku, kuyrukYaz } from './depo';
import { govdeHazirla, sonuclariUygula, gecicihata, beklenenler } from './queue';

let calisiyor = false;

export async function kuyruguGonder(oturum) {
  if (calisiyor) return { atlandi: true };
  calisiyor = true;
  try {
    let kuyruk = await kuyrukOku();
    let gonderilen = 0, hatali = 0;
    while (beklenenler(kuyruk).length > 0) {
      const govde = govdeHazirla(kuyruk);
      const anahtarlar = govde.siparisler.map((s) => s.istemci_anahtar);
      try {
        const y = await siparisGonder(oturum.sunucu, oturum.token, govde);
        kuyruk = sonuclariUygula(kuyruk, y.sonuclar || []);
        await kuyrukYaz(kuyruk);
        const sonuc = (y.sonuclar || []);
        gonderilen += sonuc.filter((s) => s.durum === 'olustu' || s.durum === 'zaten_var').length;
        hatali += sonuc.filter((s) => s.durum === 'hata' && s.kalici).length;
        // ilerleme yoksa (hepsi geçici hata) sonsuz döngüye girme
        if (!sonuc.some((s) => s.durum === 'olustu' || s.durum === 'zaten_var' || (s.durum === 'hata' && s.kalici))) break;
      } catch (e) {
        if (e instanceof ApiHatasi && e.kod === 401) return { oturumGecersiz: true, gonderilen, hatali };
        kuyruk = gecicihata(kuyruk, anahtarlar, e.message);
        await kuyrukYaz(kuyruk);
        return { cevrimdisi: true, gonderilen, hatali };
      }
    }
    return { gonderilen, hatali };
  } finally { calisiyor = false; }
}
