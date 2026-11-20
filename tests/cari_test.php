<?php
declare(strict_types=1);
/** Cari kartı, ekstre, yaşlandırma ve kur testleri (*_test veritabanı). */
require dirname(__DIR__) . '/src/autoload.php';
require_once dirname(__DIR__) . '/src/Norm.php';
use App\Core\{Config, Db};
use App\Services\{CariKart, Hesap, IsHatasi, Kur, Satis};

if (!str_ends_with(Config::env('DB_NAME', 'demirtas'), '_test')) { fwrite(STDERR, "Yalnızca *_test.\n"); exit(2); }
$pass = 0; $fail = 0;
function esit(string $ad, mixed $a, mixed $b): void { global $pass, $fail; if ($a === $b) { $pass++; return; } $fail++; echo "BAŞARISIZ: $ad [" . var_export($a, true) . ' != ' . var_export($b, true) . "]\n"; }
function hata(string $ad, callable $f, string $parca = ''): void {
    global $pass, $fail;
    try { $f(); $fail++; echo "BAŞARISIZ: $ad (hata beklendi)\n"; }
    catch (IsHatasi $e) { if ($parca === '' || str_contains($e->getMessage(), $parca)) $pass++; else { $fail++; echo "BAŞARISIZ: $ad [mesaj: {$e->getMessage()}]\n"; } }
}
foreach (['tahsilat_dagitim', 'tahsilatlar', 'fatura_kalemleri', 'irsaliye_kalemleri', 'irsaliyeler', 'faturalar', 'siparis_kalemleri', 'siparisler', 'belge_sayaclari', 'cari_hareketler', 'stok_hareketleri', 'urun_birim_cevrim', 'urunler', 'cariler', 'kurlar'] as $t) Db::run("DELETE FROM $t");
$patron = ['id' => null, 'kullanici_adi' => 'p', 'rol' => 'patron'];
$muh = ['id' => null, 'kullanici_adi' => 'm', 'rol' => 'muhasebe'];

// ---- Kur ----
$ornek = '<?xml version="1.0" encoding="UTF-8"?><Tarih_Date Tarih="20.11.2026" Date="11/20/2026" Bulten_No="2026/225"><Currency CrossOrder="0" Kod="USD" CurrencyCode="USD"><Isim>ABD DOLARI</Isim><ForexBuying>34.1000</ForexBuying><ForexSelling>34.2345</ForexSelling></Currency><Currency CrossOrder="9" Kod="EUR" CurrencyCode="EUR"><Isim>EURO</Isim><ForexBuying>36.5000</ForexBuying><ForexSelling>36.6789</ForexSelling></Currency><Currency Kod="JPY" CurrencyCode="JPY"><ForexSelling>0.2300</ForexSelling></Currency></Tarih_Date>';
$p = Kur::tcmbParse($ornek);
esit('TCMB tarihi', $p['tarih'], '2026-11-20');
esit('EUR satış kuru (kuruş/10000)', $p['kurlar']['EUR'], 366789);
esit('USD satış kuru', $p['kurlar']['USD'], 342345);
esit('JPY alınmaz', isset($p['kurlar']['JPY']), false);
hata('bozuk XML', fn() => Kur::tcmbParse('<html>hata</html>'), 'beklenen biçimde');
hata('boş yanıt', fn() => Kur::tcmbParse(''), 'beklenen biçimde');
esit('tl karşılığı 100,00 € x 36,6789', Kur::tlKarsiligi(10000, 366789), 366789);
esit('tl karşılığı yarım kuruş yukarı', Kur::tlKarsiligi(1, 5000), 1); // 0,01 € x 0,50 = 0,005 → 1 kuruş
esit('tl karşılığı negatif', Kur::tlKarsiligi(-10000, 366789), -366789);
esit('kur yokken null', Kur::son('EUR'), null);
$tarih = Kur::tcmbGuncelle($patron, fn() => $ornek);
esit('güncelleme tarihi', $tarih, '2026-11-20');
esit('kur kaydedildi', Kur::son('EUR', '2026-11-20')['kur4'], 366789);
esit('önceki tarihte kur yok', Kur::son('EUR', '2026-11-19'), null);
esit('sonraki tarihte son kur geçerli', Kur::son('EUR', '2026-11-25')['tarih'], '2026-11-20');
Kur::kaydet('2026-11-20', 'EUR', 370000, 'elle', $patron);
esit('aynı gün üzerine yazılır, kaynak elle', [Kur::son('EUR', '2026-11-20')['kur4'], Kur::son('EUR', '2026-11-20')['kaynak']], [370000, 'elle']);
hata('ağ hatası mesajı', fn() => Kur::tcmbGuncelle($patron, function () { throw new IsHatasi('TCMB\'ye ulaşılamadı. Kuru elle girebilirsiniz.'); }), 'elle');
hata('geçersiz kur', fn() => Kur::kaydet('2026-11-20', 'EUR', 0, 'elle', $patron), 'geçersiz');
hata('desteklenmeyen para', fn() => Kur::kaydet('2026-11-20', 'JPY', 100, 'elle', $patron), 'Desteklenmeyen');

// ---- Cari kartı ----
$sat = (int)Db::value('SELECT id FROM satiscilar LIMIT 1');
$gecerli = []; for ($n = 1000000000; $n < 1000002000 && count($gecerli) < 2; $n++) { if (Norm::vknOk((string)$n)) $gecerli[] = (string)$n; }
[$gv, $gv2] = $gecerli;
$a = Db::insert("INSERT INTO cariler (kod, unvan, tip, vkn, vkn_gecerli, risk_limiti, il) VALUES ('K1','Eski Ünvan','musteri','1234567890',1,'1000.00','Sivas')");
$b = Db::insert("INSERT INTO cariler (kod, unvan, tip, vkn, vkn_gecerli, risk_limitsiz) VALUES ('K2','İkinci','musteri','" . $gv2 . "',1,1)");
$form = ['unvan' => 'Yeni Ünvan Ltd.', 'vkn' => '', 'vergi_dairesi' => 'Merkez', 'telefon' => '0532 111 22 33', 'eposta' => 'A@B.com', 'il' => 'Sivas', 'ilce' => 'Merkez', 'satisci_id' => $sat, 'durum' => 'aktif', 'uyari_notu' => '', 'risk_mod' => 'limitli', 'risk_limiti' => '1000'];
// geçerli bir VKN üret: 10 haneli, algoritmadan geçen
$ch = CariKart::guncelle($a, $form, $muh);
esit('VKN silindi + ünvan/telefon/e-posta vb. değişti', in_array('unvan', $ch) && in_array('telefon', $ch) && in_array('eposta', $ch) && in_array('vkn', $ch), true);
$c = Db::one('SELECT * FROM cariler WHERE id = ?', [$a]);
esit('telefon standartlaştı', $c['telefon'], '05321112233');
esit('e-posta küçük harf', $c['eposta'], 'a@b.com');
esit('vkn_gecerli 0', (int)$c['vkn_gecerli'], 0);
esit('risk limiti aynı kaldı (muhasebe değiştirmedi)', Hesap::kurus((string)$c['risk_limiti']), 100000);
esit('değişiklik yok → boş liste', CariKart::guncelle($a, $form, $muh), []);
esit('denetim kaydı yazıldı', (int)Db::value("SELECT COUNT(*) FROM islem_kaydi WHERE tablo = 'cariler' AND kayit_id = ? AND eylem = 'degistir'", [(string)$a]), 1);
$ik = Db::one("SELECT eski_deger, yeni_deger FROM islem_kaydi WHERE tablo = 'cariler' AND kayit_id = ? ORDER BY id DESC LIMIT 1", [(string)$a]);
esit('denetimde eski ve yeni değer var', str_contains($ik['eski_deger'], 'Eski') && str_contains($ik['yeni_deger'], 'Yeni'), true);
$f2 = $form; $f2['vkn'] = '1234567891';
hata('geçersiz VKN', fn() => CariKart::guncelle($a, $f2, $muh), 'geçersiz');
$f2['vkn'] = $gv2;
hata('başka cariyle aynı VKN', fn() => CariKart::guncelle($a, $f2, $muh), 'başka bir cari');
$f2['vkn'] = $gv; CariKart::guncelle($a, $f2, $muh);
esit('geçerli VKN kaydedildi', [Db::value('SELECT vkn FROM cariler WHERE id = ?', [$a]), (int)Db::value('SELECT vkn_gecerli FROM cariler WHERE id = ?', [$a])], [$gv, 1]);
$f3 = $form; $f3['vkn'] = $gv; $f3['risk_limiti'] = '5000';
hata('muhasebe risk limitini değiştiremez', fn() => CariKart::guncelle($a, $f3, $muh), 'patron');
esit('reddedilince hiçbir alan değişmedi', Hesap::kurus((string)Db::value('SELECT risk_limiti FROM cariler WHERE id = ?', [$a])), 100000);
CariKart::guncelle($a, $f3, $patron);
esit('patron risk limitini değiştirdi', Hesap::kurus((string)Db::value('SELECT risk_limiti FROM cariler WHERE id = ?', [$a])), 500000);
$f3['risk_mod'] = 'limitsiz'; CariKart::guncelle($a, $f3, $patron);
esit('limitsiz', [(int)Db::value('SELECT risk_limitsiz FROM cariler WHERE id = ?', [$a]), Db::value('SELECT risk_limiti FROM cariler WHERE id = ?', [$a])], [1, null]);
$f3['risk_mod'] = 'tanimsiz'; CariKart::guncelle($a, $f3, $patron);
esit('tanımsız', [(int)Db::value('SELECT risk_limitsiz FROM cariler WHERE id = ?', [$a]), Db::value('SELECT risk_limiti FROM cariler WHERE id = ?', [$a])], [0, null]);
$f3['risk_mod'] = 'limitli'; $f3['risk_limiti'] = 'abc';
hata('geçersiz limit', fn() => CariKart::guncelle($a, $f3, $patron), 'Risk limiti geçersiz');
$f3['risk_limiti'] = '1000,555';
hata('3 ondalıklı limit', fn() => CariKart::guncelle($a, $f3, $patron), 'Risk limiti geçersiz');
$f3['risk_limiti'] = '1000'; $f3['telefon'] = '12';
hata('geçersiz telefon', fn() => CariKart::guncelle($a, $f3, $patron), 'Telefon');
$f3['telefon'] = ''; $f3['eposta'] = 'yanlis';
hata('geçersiz e-posta', fn() => CariKart::guncelle($a, $f3, $patron), 'E-posta');
$f3['eposta'] = ''; $f3['unvan'] = '  ';
hata('boş ünvan', fn() => CariKart::guncelle($a, $f3, $patron), 'Ünvan');
$f3['unvan'] = 'X'; $f3['satisci_id'] = 9999;
hata('olmayan satışçı', fn() => CariKart::guncelle($a, $f3, $patron), 'Satışçı');
$f3['satisci_id'] = $sat; $f3['durum'] = 'yok';
hata('geçersiz durum', fn() => CariKart::guncelle($a, $f3, $patron), 'Durum');
hata('olmayan cari', fn() => CariKart::guncelle(99999, $form, $patron), 'bulunamadı');
$f3['durum'] = 'engelli'; CariKart::guncelle($a, $f3, $patron);
esit('engelli yapıldı → sipariş girilemez', Db::value('SELECT durum FROM cariler WHERE id = ?', [$a]), 'engelli');
hata('engelli cariye sipariş', fn() => Satis::siparisOlustur($a, 1, '2026-06-01', [['urun_id' => 1, 'miktar' => 1000, 'fiyat4' => 100, 'iskonto_bp' => 0]], 0, bin2hex(random_bytes(16)), $patron), 'engelli');

// ---- Ekstre ve yaşlandırma ----
$e = Db::insert("INSERT INTO cariler (kod, unvan, tip, risk_limitsiz) VALUES ('K3','Ekstre','musteri',1)");
$h = fn($t, $tur, $b, $al) => Db::run('INSERT INTO cari_hareketler (cari_id, tarih, tur, borc, alacak) VALUES (?,?,?,?,?)', [$e, $t, $tur, $b, $al]);
$h('2025-12-31', 'acilis', '1000.00', '0'); $h('2026-01-10', 'fatura', '500.50', '0'); $h('2026-01-20', 'tahsilat', '0', '300.25'); $h('2026-02-05', 'fatura', '99.99', '0');
$x = CariKart::ekstre($e, '2026-01-01', '2026-01-31');
esit('açılış bakiyesi (bas öncesi)', $x['acilis'], 100000);
esit('dönemde 2 hareket', count($x['satirlar']), 2);
esit('yürüyen bakiye 1. satır', $x['satirlar'][0]['bakiye'], 150050);
esit('yürüyen bakiye 2. satır', $x['satirlar'][1]['bakiye'], 120025);
esit('dönem borç/alacak', [$x['borc'], $x['alacak']], [50050, 30025]);
esit('kapanış = açılış + borç − alacak', $x['kapanis'], $x['acilis'] + $x['borc'] - $x['alacak']);
$tam = CariKart::ekstre($e, '2000-01-01', '2099-12-31');
esit('tüm dönem kapanışı = cari bakiyesi', $tam['kapanis'], Satis::bakiye($e));
esit('tüm dönem açılışı 0', $tam['acilis'], 0);
esit('dönem dışı boş ekstre açılış=kapanış', CariKart::ekstre($e, '2030-01-01', '2030-12-31')['kapanis'], Satis::bakiye($e));

echo "\nCari/Kur testleri: $pass geçti, $fail başarısız\n";
exit($fail ? 1 : 0);
