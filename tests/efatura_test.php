<?php
declare(strict_types=1);
/** e-Fatura (UBL-TR) üretim testleri: yapı + aritmetik tutarlılık. Resmî GİB şema doğrulaması DEĞİLDİR. *_test veritabanı. */
require dirname(__DIR__) . '/src/autoload.php';
use App\Core\{Config, Db};
use App\Services\{EFatura, Hesap, IsHatasi, Satis};

if (!str_ends_with(Config::env('DB_NAME', 'demirtas'), '_test')) { fwrite(STDERR, "Yalnızca *_test.\n"); exit(2); }
$pass = 0; $fail = 0;
function esit(string $ad, mixed $a, mixed $b): void { global $pass, $fail; if ($a === $b) { $pass++; return; } $fail++; echo "BAŞARISIZ: $ad [" . var_export($a, true) . ' != ' . var_export($b, true) . "]\n"; }
foreach (['tahsilat_dagitim', 'tahsilatlar', 'fatura_kalemleri', 'irsaliye_kalemleri', 'irsaliyeler', 'faturalar', 'siparis_kalemleri', 'siparisler', 'belge_sayaclari', 'cari_hareketler', 'stok_hareketleri', 'urun_birim_cevrim', 'urunler', 'cariler'] as $t) Db::run("DELETE FROM $t");

// Saf fonksiyonlar
esit('GİB no', EFatura::gibNo('FTR-2026-000001'), 'FTR2026000000001');
try { EFatura::gibNo('X-1'); esit('hatalı no reddi', true, false); } catch (IsHatasi) { esit('hatalı no reddi', true, true); }
esit('yazıyla 1234,50', EFatura::yaziyla(123450), 'BİNİKİYÜZOTUZDÖRT TL ELLİ KR');
esit('yazıyla 100', EFatura::yaziyla(10000), 'YÜZ TL');
esit('yazıyla 1000', EFatura::yaziyla(100000), 'BİN TL');
esit('yazıyla 2000', EFatura::yaziyla(200000), 'İKİBİN TL');
esit('yazıyla 0,05', EFatura::yaziyla(5), 'SIFIR TL BEŞ KR');
mt_srand(7); $kotu = 0;
for ($n = 0; $n < 500; $n++) {
    $m = []; for ($i = 0, $k = mt_rand(1, 7); $i < $k; $i++) $m[] = mt_rand(1, 900000);
    $g = Hesap::bolRound(array_sum($m) * [1, 10, 20][mt_rand(0, 2)], 100);
    $d = EFatura::kdvDagit($m, $g);
    if (array_sum($d) !== $g || count($d) !== count($m) || min($d) < 0) $kotu++;
}
esit('KDV dağıtımı 500 rastgele grupta tam', $kotu, 0);

// Fatura fixture: iki KDV oranı, satır + genel iskonto, kesirli miktar
$kul = ['id' => null, 'rol' => 'patron'];
$ok = Db::insert("INSERT INTO cariler (kod, unvan, tip, vkn, vkn_gecerli, vergi_dairesi, il, ilce, risk_limitsiz) VALUES ('E1','Örnek & <Yapı> Ltd. Şti.','musteri','1234567890',1,'Merkez VD','Sivas','Merkez',1)");
$bad = Db::insert("INSERT INTO cariler (kod, unvan, tip, vkn, vkn_gecerli, il, risk_limitsiz) VALUES ('E2','VKN Hatalı','musteri','123456789',0,'Sivas',1)");
$eur = Db::insert("INSERT INTO cariler (kod, unvan, tip, vkn, vkn_gecerli, il, para_birimi, risk_limitsiz) VALUES ('E3','Euro','musteri','1234567890',1,'Sivas','EUR',1)");
$u1 = Db::insert("INSERT INTO urunler (stok_kodu, ad, kategori, temel_birim, kdv_orani) VALUES ('E-1','Çimento & Katkı \"Özel\"','T','torba',20)");
$u2 = Db::insert("INSERT INTO urunler (stok_kodu, ad, kategori, temel_birim, kdv_orani) VALUES ('E-2','Demir','T','kg',10)");
$u3 = Db::insert("INSERT INTO urunler (stok_kodu, ad, kategori, temel_birim, kdv_orani) VALUES ('E-3','Boru','T','adet',20)");
$u4 = Db::insert("INSERT INTO urunler (stok_kodu, ad, kategori, temel_birim, kdv_orani) VALUES ('E-4','Tuhaf Birim','T','çuval',20)");
foreach ([$u1, $u2, $u3, $u4] as $u) Db::run("INSERT INTO stok_hareketleri (urun_id, depo_id, tarih, tur, miktar) VALUES (?,1,'2026-01-01','acilis',1000)", [$u]);
function fat(int $cari, array $sat, int $genel): int {
    global $kul;
    $s = Satis::siparisOlustur($cari, 1, '2026-06-10', $sat, $genel, bin2hex(random_bytes(16)), $kul);
    $kid = Db::all('SELECT id, miktar FROM siparis_kalemleri WHERE siparis_id = ? ORDER BY sira', [$s['id']]);
    $m = []; foreach ($kid as $k) $m[(int)$k['id']] = \App\Services\Birim::fromDecimal((string)$k['miktar']);
    $i = Satis::irsaliyeKes($s['id'], $m, '2026-06-10', bin2hex(random_bytes(16)), $kul);
    return Satis::faturaKes([$i['id']], 30, '2026-06-10', bin2hex(random_bytes(16)), $kul)['id'];
}
$fid = fat($ok, [
    ['urun_id' => $u1, 'miktar' => 7000, 'fiyat4' => 1234567, 'iskonto_bp' => 1250],
    ['urun_id' => $u2, 'miktar' => 12345, 'fiyat4' => 78900, 'iskonto_bp' => 0],
    ['urun_id' => $u3, 'miktar' => 3000, 'fiyat4' => 333333, 'iskonto_bp' => 500],
], 333);

esit('eksik yok', EFatura::eksikler($fid), []);
$xml = EFatura::uret($fid);
$d = new DOMDocument(); esit('XML iyi biçimli', $d->loadXML($xml), true);
// İsteğe bağlı: GİB'in resmi UBL-TR 1.2.1 paketindeki XSD'ye karşı doğrulama (EFATURA_XSD=…/xsdrt/maindoc/UBL-Invoice-2.1.xsd).
if (($xsd = Config::env('EFATURA_XSD', '')) !== '' && is_file($xsd)) {
    libxml_use_internal_errors(true);
    $gecerli = $d->schemaValidate($xsd);
    if (!$gecerli) foreach (libxml_get_errors() as $e) fwrite(STDERR, 'XSD: ' . trim($e->message) . "\n");
    libxml_clear_errors();
    esit('GİB UBL-TR 1.2.1 XSD ile geçerli', $gecerli, true);
}
if (($dump = Config::env('EFATURA_DUMP', '')) !== '') file_put_contents($dump, $xml);
$x = new DOMXPath($d);
foreach (['inv' => 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2', 'cac' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2', 'cbc' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2'] as $p => $u) $x->registerNamespace($p, $u);
$v = fn(string $q) => trim((string)$x->evaluate("string($q)"));
$f = Db::one('SELECT * FROM faturalar WHERE id = ?', [$fid]);
esit('kök eleman', $d->documentElement->localName, 'Invoice');
esit('UBL sürümü', $v('/inv:Invoice/cbc:UBLVersionID'), '2.1');
esit('özelleştirme', $v('/inv:Invoice/cbc:CustomizationID'), 'TR1.2');
esit('numara 16 hane GİB biçimi', (bool)preg_match('/^[A-Z]{3}\d{4}\d{9}$/', $v('/inv:Invoice/cbc:ID')), true);
esit('UUID = ETTN', $v('/inv:Invoice/cbc:UUID'), $f['ettn']);
esit('tarih', $v('/inv:Invoice/cbc:IssueDate'), '2026-06-10');
esit('satır sayısı', $v('/inv:Invoice/cbc:LineCountNumeric'), '3');
esit('3 InvoiceLine', (int)$x->evaluate('count(/inv:Invoice/cac:InvoiceLine)'), 3);
esit('müşteri VKN', $v('/inv:Invoice/cac:AccountingCustomerParty/cac:Party/cac:PartyIdentification/cbc:ID'), '1234567890');
esit('müşteri unvanı kaçırıldı ve geri okunur', $v('/inv:Invoice/cac:AccountingCustomerParty/cac:Party/cac:PartyName/cbc:Name'), 'Örnek & <Yapı> Ltd. Şti.');
esit('ham XML\'de & kaçırılmış', str_contains($xml, 'Örnek &amp; &lt;Yapı&gt;'), true);
esit('irsaliye referansı', $v('/inv:Invoice/cac:DespatchDocumentReference/cbc:ID') !== '', true);
esit('ödenecek = fatura toplamı', $v('/inv:Invoice/cac:LegalMonetaryTotal/cbc:PayableAmount'), Hesap::dec(Hesap::kurus((string)$f['toplam'])));
esit('matrah = fatura matrahı', $v('/inv:Invoice/cac:LegalMonetaryTotal/cbc:TaxExclusiveAmount'), Hesap::dec(Hesap::kurus((string)$f['matrah'])));
esit('toplam KDV', $v('/inv:Invoice/cac:TaxTotal/cbc:TaxAmount'), Hesap::dec(Hesap::kurus((string)$f['kdv'])));
$k = fn(string $q) => Hesap::kurus(trim((string)$x->evaluate("string($q)")));
$topla = function (string $q) use ($x): int { $t = 0; foreach ($x->query($q) as $n) $t += Hesap::kurus(trim($n->textContent)); return $t; };
esit('Σ satır tutarı = LineExtensionAmount', $topla('/inv:Invoice/cac:InvoiceLine/cbc:LineExtensionAmount'), $k('/inv:Invoice/cac:LegalMonetaryTotal/cbc:LineExtensionAmount'));
esit('Σ satır KDV = toplam KDV', $topla('/inv:Invoice/cac:InvoiceLine/cac:TaxTotal/cbc:TaxAmount'), $k('/inv:Invoice/cac:TaxTotal/cbc:TaxAmount'));
esit('Σ grup KDV = toplam KDV', $topla('/inv:Invoice/cac:TaxTotal/cac:TaxSubtotal/cbc:TaxAmount'), $k('/inv:Invoice/cac:TaxTotal/cbc:TaxAmount'));
esit('Σ grup matrah = matrah', $topla('/inv:Invoice/cac:TaxTotal/cac:TaxSubtotal/cbc:TaxableAmount'), $k('/inv:Invoice/cac:LegalMonetaryTotal/cbc:TaxExclusiveAmount'));
esit('matrah + KDV = ödenecek', $k('/inv:Invoice/cac:LegalMonetaryTotal/cbc:TaxExclusiveAmount') + $k('/inv:Invoice/cac:TaxTotal/cbc:TaxAmount'), $k('/inv:Invoice/cac:LegalMonetaryTotal/cbc:PayableAmount'));
esit('2 KDV grubu (%10 ve %20)', (int)$x->evaluate('count(/inv:Invoice/cac:TaxTotal/cac:TaxSubtotal)'), 2);
// Satır bazında: brüt (BaseAmount) − iskonto = satır tutarı
$kotu = 0;
foreach ($x->query('/inv:Invoice/cac:InvoiceLine') as $sat) {
    $le = Hesap::kurus($x->evaluate('string(cbc:LineExtensionAmount)', $sat));
    $isk = $x->evaluate('count(cac:AllowanceCharge)', $sat) ? Hesap::kurus($x->evaluate('string(cac:AllowanceCharge/cbc:Amount)', $sat)) : 0;
    $base = $x->evaluate('count(cac:AllowanceCharge)', $sat) ? Hesap::kurus($x->evaluate('string(cac:AllowanceCharge/cbc:BaseAmount)', $sat)) : $le;
    if ($base - $isk !== $le) $kotu++;
}
esit('her satırda brüt − iskonto = satır tutarı', $kotu, 0);
esit('birim kodları', [$v('/inv:Invoice/cac:InvoiceLine[1]/cbc:InvoicedQuantity/@unitCode'), $v('/inv:Invoice/cac:InvoiceLine[2]/cbc:InvoicedQuantity/@unitCode'), $v('/inv:Invoice/cac:InvoiceLine[3]/cbc:InvoicedQuantity/@unitCode')], ['BG', 'KGM', 'C62']);
esit('kesirli miktar korunur', $v('/inv:Invoice/cac:InvoiceLine[2]/cbc:InvoicedQuantity'), '12.345');
esit('tam miktar ondalıksız', $v('/inv:Invoice/cac:InvoiceLine[1]/cbc:InvoicedQuantity'), '7');
esit('XML\'de float biçimi yok (e-notasyon)', (bool)preg_match('/\d[eE][+-]?\d/', preg_replace('/UUID>[^<]*</', '', $xml)), false);

// Engelleyiciler
$e = EFatura::eksikler(fat($bad, [['urun_id' => $u3, 'miktar' => 1000, 'fiyat4' => 100000, 'iskonto_bp' => 0]], 0));
esit('geçersiz VKN engeller', count($e) === 1 && str_contains($e[0], 'VKN'), true);
$e = EFatura::eksikler(fat($eur, [['urun_id' => $u3, 'miktar' => 1000, 'fiyat4' => 100000, 'iskonto_bp' => 0]], 0));
esit('EUR fatura engellenir', count($e) === 1 && str_contains($e[0], 'TRY'), true);
$f4 = fat($ok, [['urun_id' => $u4, 'miktar' => 1000, 'fiyat4' => 100000, 'iskonto_bp' => 0]], 0);
esit('tanımsız birim engellenir', str_contains(implode(' ', EFatura::eksikler($f4)), 'çuval'), true);
try { EFatura::uret($f4); esit('uret hata fırlatır', true, false); } catch (IsHatasi) { esit('uret hata fırlatır', true, true); }
// Bütünlük: başlık bozulursa üretme
Db::run("UPDATE faturalar SET kdv = kdv + 0.01 WHERE id = ?", [$fid]);
try { EFatura::uret($fid); esit('bozuk toplam reddi', true, false); } catch (IsHatasi $e2) { esit('bozuk toplam reddi', str_contains($e2->getMessage(), 'tutmuyor'), true); }

echo "\ne-Fatura testleri: $pass geçti, $fail başarısız\n";
exit($fail ? 1 : 0);
