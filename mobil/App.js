import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { ActivityIndicator, Alert, FlatList, Pressable, SafeAreaView, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';
import { StatusBar } from 'expo-status-bar';
import NetInfo from '@react-native-community/netinfo';
import * as Crypto from 'expo-crypto';
import * as Api from './src/api';
import * as Depo from './src/depo';
import * as Q from './src/queue';
import { kuyruguGonder } from './src/esitle';

const R = { cimento: '#E9EBEC', lacivert: '#17222D', sari: '#F2B705', tugla: '#A63D2A', yesil: '#2E7A57', beyaz: '#fff' };
const bugun = () => { const d = new Date(); const p = (n) => String(n).padStart(2, '0'); return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`; };
const DURUM = { bekliyor: 'Gönderilmeyi bekliyor', gonderildi: 'Gönderildi', hata: 'Hata — düzeltin' };

function Dugme({ ad, onPress, renk = R.lacivert, devre }) {
  return <Pressable onPress={onPress} disabled={devre} style={[s.dugme, { backgroundColor: devre ? '#999' : renk }]}><Text style={[s.dugmeYazi, renk === R.sari && { color: R.lacivert }]}>{ad}</Text></Pressable>;
}

function Giris({ onGiris }) {
  const [sunucu, setSunucu] = useState('https://');
  const [ka, setKa] = useState(''); const [pw, setPw] = useState(''); const [mesgul, setMesgul] = useState(false); const [hata, setHata] = useState('');
  async function gir() {
    setMesgul(true); setHata('');
    try {
      const y = await Api.giris(sunucu.trim(), ka.trim(), pw);
      if (!y.token || !y.kullanici) throw new Error('Sunucu geçerli bir giriş yanıtı vermedi.');
      await onGiris({ sunucu: sunucu.trim(), token: y.token, kullanici: y.kullanici });
    } catch (e) { setHata(e.message); } finally { setMesgul(false); }
  }
  return (
    <ScrollView contentContainerStyle={s.merkez} keyboardShouldPersistTaps="handled">
      <Text style={s.baslik}>DEMİRTAŞ YAPI MARKET</Text><Text style={s.alt}>Saha satış uygulaması</Text>
      <Text style={s.etiket}>Sunucu adresi</Text><TextInput style={s.girdi} value={sunucu} onChangeText={setSunucu} autoCapitalize="none" autoCorrect={false} keyboardType="url" />
      <Text style={s.etiket}>Kullanıcı adı</Text><TextInput style={s.girdi} value={ka} onChangeText={setKa} autoCapitalize="none" autoCorrect={false} />
      <Text style={s.etiket}>Parola</Text><TextInput style={s.girdi} value={pw} onChangeText={setPw} secureTextEntry />
      {!!hata && <Text style={s.hata}>{hata}</Text>}
      <Dugme ad={mesgul ? 'Giriş yapılıyor…' : 'Giriş yap'} onPress={gir} devre={mesgul || !ka || !pw} renk={R.sari} />
    </ScrollView>
  );
}

function YeniSiparis({ onbellek, kuyrukEkle }) {
  const [cari, setCari] = useState(null); const [ara, setAra] = useState(''); const [uAra, setUAra] = useState('');
  const [kalemler, setKalemler] = useState([]);
  const musteriler = useMemo(() => onbellek.musteriler.filter((m) => (m.unvan + m.kod).toLocaleLowerCase('tr').includes(ara.toLocaleLowerCase('tr'))).slice(0, 30), [onbellek, ara]);
  const urunler = useMemo(() => uAra.length < 2 ? [] : onbellek.urunler.filter((u) => (u.ad + u.stok_kodu).toLocaleLowerCase('tr').includes(uAra.toLocaleLowerCase('tr'))).slice(0, 20), [onbellek, uAra]);
  function ekle(u) { setKalemler((k) => Q.kalemleriBirlestir([...k, { urun_id: u.id, ad: u.ad, miktar: '1', birim: '' }])); setUAra(''); }
  function miktarDegis(i, v) { setKalemler((k) => k.map((x, j) => (j === i ? { ...x, miktar: v } : x))); }
  async function kaydet() {
    try {
      const anahtar = Q.yeniAnahtar(await Crypto.getRandomBytesAsync(16)); // anahtar bir kez üretilir
      await kuyrukEkle(Q.girdiOlustur({ anahtar, cari, kalemler, tarih: bugun(), simdi: Date.now() }));
      setCari(null); setKalemler([]); setAra('');
      Alert.alert('Sipariş kuyruğa alındı', 'İnternet varsa hemen, yoksa bağlantı gelince otomatik gönderilir.');
    } catch (e) { Alert.alert('Kaydedilemedi', e.message); }
  }
  if (!cari) return (
    <View style={s.sayfa}>
      <Text style={s.etiket}>Müşteri seçin ({onbellek.musteriler.length} kayıt)</Text>
      <TextInput style={s.girdi} value={ara} onChangeText={setAra} placeholder="Ünvan veya kod ara" />
      <FlatList data={musteriler} keyExtractor={(m) => String(m.id)} keyboardShouldPersistTaps="handled" renderItem={({ item }) => (
        <Pressable style={s.satir} onPress={() => setCari(item)}>
          <Text style={s.satirBaslik}>{item.unvan}</Text>
          <Text style={s.satirAlt}>{item.kod} · {item.il || '—'} · Bakiye {item.bakiye} TL{item.durum !== 'aktif' ? ' · ' + item.durum.toUpperCase() : ''}</Text>
          {!!item.uyari_notu && <Text style={s.uyari}>{item.uyari_notu}</Text>}
        </Pressable>)} />
    </View>
  );
  return (
    <ScrollView style={s.sayfa} keyboardShouldPersistTaps="handled">
      <Text style={s.satirBaslik}>{cari.unvan}</Text>
      <Text style={s.satirAlt}>Bakiye {cari.bakiye} TL · Risk: {cari.risk}</Text>
      <Pressable onPress={() => setCari(null)}><Text style={s.baglanti}>Müşteriyi değiştir</Text></Pressable>
      <Text style={s.etiket}>Ürün ara (en az 2 harf)</Text>
      <TextInput style={s.girdi} value={uAra} onChangeText={setUAra} placeholder="Ürün adı veya stok kodu" />
      {urunler.map((u) => (<Pressable key={u.id} style={s.satir} onPress={() => ekle(u)}><Text style={s.satirBaslik}>{u.ad}</Text><Text style={s.satirAlt}>{u.stok_kodu} · {u.fiyat} TL/{u.birim} + %{u.kdv} KDV · Stok {u.stok}</Text></Pressable>))}
      <Text style={s.etiket}>Kalemler</Text>
      {kalemler.length === 0 && <Text style={s.satirAlt}>Henüz ürün eklenmedi.</Text>}
      {kalemler.map((k, i) => (
        <View key={k.urun_id + ':' + i} style={s.kalem}>
          <Text style={[s.satirBaslik, { flex: 1 }]}>{k.ad}</Text>
          <TextInput style={[s.girdi, { width: 80, marginBottom: 0 }]} value={k.miktar} onChangeText={(v) => miktarDegis(i, v)} keyboardType="decimal-pad" />
          <Pressable onPress={() => setKalemler((x) => x.filter((_, j) => j !== i))}><Text style={[s.baglanti, { color: R.tugla, marginLeft: 12 }]}>Sil</Text></Pressable>
        </View>))}
      <Text style={s.satirAlt}>Fiyat ve toplam sunucuda güncel liste fiyatıyla hesaplanır.</Text>
      <Dugme ad="Siparişi kaydet" onPress={kaydet} devre={kalemler.length === 0} renk={R.sari} />
    </ScrollView>
  );
}

function KuyrukEkrani({ kuyruk, gonder, gonderiyor, hataYeniden, temizle }) {
  return (
    <View style={s.sayfa}>
      <Dugme ad={gonderiyor ? 'Gönderiliyor…' : 'Şimdi gönder'} onPress={gonder} devre={gonderiyor} />
      <FlatList data={[...kuyruk].reverse()} keyExtractor={(g) => g.istemci_anahtar} ListEmptyComponent={<Text style={s.satirAlt}>Kuyruk boş.</Text>} renderItem={({ item }) => (
        <View style={s.satir}>
          <Text style={s.satirBaslik}>{item.cari_unvan}</Text>
          <Text style={[s.satirAlt, item.durum === 'hata' && { color: R.tugla }, item.durum === 'gonderildi' && { color: R.yesil }]}>
            {DURUM[item.durum]}{item.belge_no ? ` · ${item.belge_no}` : ''}{item.deneme ? ` · ${item.deneme} deneme` : ''}
          </Text>
          <Text style={s.satirAlt}>{item.kalemler.length} kalem · {item.tarih}</Text>
          {!!item.mesaj && <Text style={s.uyari}>{item.mesaj}</Text>}
          {item.durum === 'hata' && <Pressable onPress={() => hataYeniden(item.istemci_anahtar)}><Text style={s.baglanti}>Yeniden dene</Text></Pressable>}
        </View>)} />
      <Pressable onPress={temizle}><Text style={s.baglanti}>Gönderilenleri listeden temizle</Text></Pressable>
    </View>
  );
}

export default function App() {
  const [hazir, setHazir] = useState(false); const [oturum, setOturum] = useState(null);
  const [onbellek, setOnbellek] = useState({ musteriler: [], urunler: [], zaman: null });
  const [kuyruk, setKuyruk] = useState([]); const [sekme, setSekme] = useState('yeni');
  const [cevrimici, setCevrimici] = useState(true); const [gonderiyor, setGonderiyor] = useState(false); const [bilgi, setBilgi] = useState('');
  const oturumRef = useRef(null); oturumRef.current = oturum;

  useEffect(() => { (async () => { setOturum(await Depo.oturumOku()); setOnbellek(await Depo.onbellekOku()); setKuyruk(await Depo.kuyrukOku()); setHazir(true); })(); }, []);

  const oturumuKapat = useCallback(async (mesaj) => { await Depo.oturumSil(); setOturum(null); if (mesaj) Alert.alert('Oturum', mesaj); }, []);

  const esitle = useCallback(async () => {
    const o = oturumRef.current; if (!o) return;
    setGonderiyor(true);
    try {
      const r = await kuyruguGonder(o);
      setKuyruk(await Depo.kuyrukOku());
      if (r.oturumGecersiz) { await oturumuKapat('Oturum süresi doldu. Bekleyen siparişler cihazda saklanıyor; yeniden giriş yapınca gönderilir.'); return; }
      if (r.cevrimdisi) setBilgi('Sunucuya ulaşılamadı; siparişler cihazda bekliyor.');
      else {
        try { const y = await Api.senkron(o.sunucu, o.token); const yeni = { musteriler: y.musteriler, urunler: y.urunler, zaman: y.sunucu_zamani }; setOnbellek(yeni); await Depo.onbellekYaz(yeni); setBilgi(''); }
        catch (e) { if (e.kod === 401) await oturumuKapat('Oturum geçersiz.'); }
      }
    } finally { setGonderiyor(false); }
  }, [oturumuKapat]);

  // Bağlantı gelince otomatik gönder
  useEffect(() => {
    const abone = NetInfo.addEventListener((d) => { const var_ = !!d.isConnected && d.isInternetReachable !== false; setCevrimici(var_); if (var_) esitle(); });
    return () => abone();
  }, [esitle]);
  useEffect(() => { if (oturum) esitle(); }, [oturum, esitle]);

  const kuyrukEkle = useCallback(async (g) => {
    const yeni = [...(await Depo.kuyrukOku()), g]; await Depo.kuyrukYaz(yeni); setKuyruk(yeni); esitle();
  }, [esitle]);
  const guncelle = async (yeni) => { await Depo.kuyrukYaz(yeni); setKuyruk(yeni); };

  if (!hazir) return <View style={s.merkez}><ActivityIndicator /></View>;
  const bekleyen = Q.beklenenler(kuyruk).length;
  return (
    <SafeAreaView style={s.kok}>
      <StatusBar style="light" />
      <View style={s.ust}>
        <Text style={s.ustYazi}>Demirtaş Satış</Text>
        <Text style={[s.ustAlt, !cevrimici && { color: R.sari }]}>{oturum ? `${oturum.kullanici.ad_soyad} · ` : ''}{cevrimici ? 'Çevrimiçi' : 'ÇEVRİMDIŞI'}</Text>
      </View>
      {!oturum ? <Giris onGiris={async (o) => { await Depo.oturumYaz(o); setOturum(o); }} /> : (
        <>
          {!!bilgi && <Text style={s.bilgi}>{bilgi}</Text>}
          {onbellek.zaman === null && <Text style={s.bilgi}>Müşteri/ürün listesi henüz indirilmedi; internete bağlanın.</Text>}
          <View style={{ flex: 1 }}>
            {sekme === 'yeni'
              ? <YeniSiparis onbellek={onbellek} kuyrukEkle={kuyrukEkle} />
              : <KuyrukEkrani kuyruk={kuyruk} gonder={esitle} gonderiyor={gonderiyor} hataYeniden={(a) => guncelle(Q.hatayiYenidenKuyrugaAl(kuyruk, a)).then(esitle)} temizle={() => guncelle(Q.temizle(kuyruk))} />}
          </View>
          <View style={s.sekmeler}>
            <Pressable style={[s.sekme, sekme === 'yeni' && s.sekmeAktif]} onPress={() => setSekme('yeni')}><Text style={s.sekmeYazi}>Yeni sipariş</Text></Pressable>
            <Pressable style={[s.sekme, sekme === 'kuyruk' && s.sekmeAktif]} onPress={() => setSekme('kuyruk')}><Text style={s.sekmeYazi}>Kuyruk{bekleyen ? ` (${bekleyen})` : ''}</Text></Pressable>
            <Pressable style={s.sekme} onPress={() => Alert.alert('Çıkış', bekleyen ? `${bekleyen} gönderilmemiş sipariş cihazda kalır.` : 'Çıkış yapılsın mı?', [{ text: 'Vazgeç' }, { text: 'Çıkış', onPress: async () => { try { await Api.cikis(oturum.sunucu, oturum.token); } catch (e) { /* çevrimdışıysa yerel çıkış */ } oturumuKapat(); } }])}><Text style={s.sekmeYazi}>Çıkış</Text></Pressable>
          </View>
        </>)}
    </SafeAreaView>
  );
}

const s = StyleSheet.create({
  kok: { flex: 1, backgroundColor: R.cimento }, sayfa: { flex: 1, padding: 16 }, merkez: { flexGrow: 1, justifyContent: 'center', padding: 24 },
  ust: { backgroundColor: R.lacivert, padding: 16, paddingTop: 36 }, ustYazi: { color: R.beyaz, fontSize: 20, fontWeight: '700' }, ustAlt: { color: '#cbd2d8', fontSize: 13 },
  baslik: { fontSize: 24, fontWeight: '800', color: R.lacivert }, alt: { color: '#555', marginBottom: 24 },
  etiket: { fontWeight: '600', color: R.lacivert, marginTop: 12, marginBottom: 4 }, girdi: { backgroundColor: R.beyaz, borderWidth: 1, borderColor: '#b9c0c6', padding: 10, fontSize: 16, marginBottom: 8 },
  dugme: { padding: 14, alignItems: 'center', marginVertical: 12 }, dugmeYazi: { color: R.beyaz, fontWeight: '700', fontSize: 16 },
  hata: { color: R.tugla, marginVertical: 8 }, bilgi: { backgroundColor: R.sari, padding: 8, color: R.lacivert },
  satir: { backgroundColor: R.beyaz, padding: 12, marginBottom: 8, borderLeftWidth: 4, borderLeftColor: R.sari }, satirBaslik: { fontWeight: '700', color: R.lacivert, fontSize: 15 },
  satirAlt: { color: '#555', fontSize: 13, marginTop: 2 }, uyari: { color: R.tugla, fontSize: 13, marginTop: 4 }, baglanti: { color: R.lacivert, textDecorationLine: 'underline', marginVertical: 6 },
  kalem: { flexDirection: 'row', alignItems: 'center', backgroundColor: R.beyaz, padding: 8, marginBottom: 6 },
  sekmeler: { flexDirection: 'row', backgroundColor: R.lacivert }, sekme: { flex: 1, padding: 14, alignItems: 'center' }, sekmeAktif: { borderTopWidth: 3, borderTopColor: R.sari }, sekmeYazi: { color: R.beyaz, fontWeight: '600' },
});
