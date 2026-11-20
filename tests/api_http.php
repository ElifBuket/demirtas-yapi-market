<?php
declare(strict_types=1);
/**
 * Mobil API uçtan uca testi. Yalnızca *_test veritabanı.
 *   DB_NAME=demirtas_test BASE_URL=http://127.0.0.1:8082 PW_PATRON=.. PW_DEPO=.. PW_SATISCI=.. php tests/api_http.php
 */
require dirname(__DIR__) . '/src/autoload.php';
use App\Core\{Config, Db};

if (!str_ends_with(Config::env('DB_NAME', 'demirtas'), '_test')) { fwrite(STDERR, "Yalnızca *_test veritabanı.\n"); exit(2); }
$base = rtrim(getenv('BASE_URL') ?: 'http://127.0.0.1:8082', '/');
$pass = 0; $fail = 0;
function ok(string $ad, bool $k, string $d = ''): void { global $pass, $fail; if ($k) { $pass++; return; } $fail++; echo "BAŞARISIZ: $ad" . ($d !== '' ? " [$d]" : '') . "\n"; }
function api(string $m, string $yol, ?array $govde = null, ?string $jeton = null): array {
    global $base;
    $ch = curl_init($base . $yol);
    $h = ['Content-Type: application/json']; if ($jeton) $h[] = "Authorization: Bearer $jeton";
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $m, CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => 30]);
    if ($govde !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($govde));
    $y = (string)curl_exec($ch); $k = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
    return ['kod' => $k, 'j' => json_decode($y, true) ?? [], 'ham' => $y];
}
function anahtar(): string { return bin2hex(random_bytes(16)); }

foreach (['tahsilat_dagitim', 'tahsilatlar', 'fatura_kalemleri', 'irsaliye_kalemleri', 'irsaliyeler', 'faturalar', 'siparis_kalemleri', 'siparisler', 'belge_sayaclari', 'cari_hareketler', 'stok_hareketleri', 'urun_birim_cevrim', 'urunler', 'cariler'] as $t) Db::run("DELETE FROM $t");
Db::run('DELETE FROM api_tokenlari'); Db::run('DELETE FROM giris_denemeleri');
$sk = (int)Db::value("SELECT satisci_id FROM kullanicilar WHERE kullanici_adi = 'satisci'");
$bk = (int)Db::value('SELECT id FROM satiscilar WHERE id <> ? LIMIT 1', [$sk]);
$c1 = Db::insert("INSERT INTO cariler (kod, unvan, tip, risk_limiti, satisci_id) VALUES ('A1','Benim Müşterim','musteri','100000.00',?)", [$sk]);
$c2 = Db::insert("INSERT INTO cariler (kod, unvan, tip, risk_limitsiz, satisci_id) VALUES ('A2','Başkasının','musteri',1,?)", [$bk]);
$u1 = Db::insert("INSERT INTO urunler (stok_kodu, ad, kategori, temel_birim, kdv_orani, satis_fiyati) VALUES ('M-1','Mobil Ürün','T','adet',20,'100.0000')");
$u2 = Db::insert("INSERT INTO urunler (stok_kodu, ad, kategori, temel_birim, kdv_orani, satis_fiyati) VALUES ('M-2','Fiyatsız','T','adet',20,NULL)");

// kimlik
ok('jetonsuz senkron 401', api('GET', '/api/v1/senkron')['kod'] === 401);
ok('yanlış parola 401', api('POST', '/api/v1/giris', ['kullanici_adi' => 'satisci', 'parola' => 'yanlis'])['kod'] === 401);
ok('bozuk JSON 400', api('POST', '/api/v1/giris', null)['kod'] === 400);
$g = api('POST', '/api/v1/giris', ['kullanici_adi' => 'satisci', 'parola' => getenv('PW_SATISCI'), 'cihaz' => 'test']);
ok('satışçı girişi 200 + jeton', $g['kod'] === 200 && preg_match('/^dym_[0-9a-f]{64}$/', $g['j']['token'] ?? '') === 1, $g['ham']);
$T = $g['j']['token'] ?? '';
ok('jeton düz metin saklanmaz', (int)Db::value('SELECT COUNT(*) FROM api_tokenlari WHERE token_hash = ?', [$T]) === 0 && (int)Db::value('SELECT COUNT(*) FROM api_tokenlari WHERE token_hash = ?', [hash('sha256', $T)]) === 1);
$d = api('POST', '/api/v1/giris', ['kullanici_adi' => 'depo', 'parola' => getenv('PW_DEPO')]);
ok('depo rolü mobilde 403', $d['kod'] === 403);
ok('rastgele jeton 401', api('GET', '/api/v1/senkron', null, 'dym_' . str_repeat('a', 64))['kod'] === 401);

// senkron: yalnızca kendi müşterileri, fiyatsız ürün yok
$s = api('GET', '/api/v1/senkron', null, $T);
$ids = array_column($s['j']['musteriler'] ?? [], 'id');
ok('senkron 200', $s['kod'] === 200);
ok('satışçı yalnızca kendi müşterisini görür', $ids === [$c1], json_encode($ids));
ok('fiyatsız ürün listede yok', array_column($s['j']['urunler'] ?? [], 'id') === [$u1]);

// sipariş: oluşturma, fiyatı sunucu belirler
$k1 = anahtar();
$p = api('POST', '/api/v1/siparisler', ['siparisler' => [['istemci_anahtar' => $k1, 'cari_id' => $c1, 'tarih' => date('Y-m-d'), 'kalemler' => [['urun_id' => $u1, 'miktar' => '3', 'fiyat' => '1']]]]], $T);
$r = $p['j']['sonuclar'][0] ?? [];
ok('sipariş oluştu', ($r['durum'] ?? '') === 'olustu', $p['ham']);
ok('fiyat sunucudan (3x100+KDV=360,00), istemci fiyatı yok sayıldı', ($r['toplam'] ?? '') === '360.00', json_encode($r));
// tekrar gönder (ağ kesintisi senaryosu)
$p2 = api('POST', '/api/v1/siparisler', ['siparisler' => [['istemci_anahtar' => $k1, 'cari_id' => $c1, 'kalemler' => [['urun_id' => $u1, 'miktar' => '3']]]]], $T);
ok('aynı anahtar → zaten_var, aynı belge no', ($p2['j']['sonuclar'][0]['durum'] ?? '') === 'zaten_var' && ($p2['j']['sonuclar'][0]['belge_no'] ?? '') === ($r['belge_no'] ?? 'x'));
ok('yinelenen sipariş oluşmadı', (int)Db::value('SELECT COUNT(*) FROM siparisler WHERE cari_id = ?', [$c1]) === 1);

// paralel aynı anahtar
$k2 = anahtar();
$mh = curl_multi_init(); $hs = [];
for ($i = 0; $i < 6; $i++) {
    $ch = curl_init($base . '/api/v1/siparisler');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json', "Authorization: Bearer $T"],
        CURLOPT_POSTFIELDS => json_encode(['siparisler' => [['istemci_anahtar' => $k2, 'cari_id' => $c1, 'kalemler' => [['urun_id' => $u1, 'miktar' => '1']]]]])]);
    curl_multi_add_handle($mh, $ch); $hs[] = $ch;
}
do { curl_multi_exec($mh, $run); curl_multi_select($mh, 0.2); } while ($run > 0);
$durumlar = []; foreach ($hs as $ch) { $j = json_decode((string)curl_multi_getcontent($ch), true); $durumlar[] = $j['sonuclar'][0]['durum'] ?? 'yok'; }
ok('paralel 6 istek → tek sipariş', (int)Db::value('SELECT COUNT(*) FROM siparisler WHERE kaynak_key = ?', ['siparis:' . $k2]) === 1, json_encode($durumlar));
ok('paralelde hiçbiri hata dönmedi', !in_array('hata', $durumlar, true) && !in_array('yok', $durumlar, true), json_encode($durumlar));
ok('tam olarak bir "olustu"', count(array_keys($durumlar, 'olustu', true)) === 1, json_encode($durumlar));

// toplu: kısmi hata
$toplu = api('POST', '/api/v1/siparisler', ['siparisler' => [
    ['istemci_anahtar' => anahtar(), 'cari_id' => $c1, 'kalemler' => [['urun_id' => $u1, 'miktar' => '2']]],
    ['istemci_anahtar' => anahtar(), 'cari_id' => $c2, 'kalemler' => [['urun_id' => $u1, 'miktar' => '2']]],   // başkasının müşterisi
    ['istemci_anahtar' => anahtar(), 'cari_id' => $c1, 'kalemler' => [['urun_id' => $u2, 'miktar' => '2']]],   // fiyatsız
    ['istemci_anahtar' => anahtar(), 'cari_id' => $c1, 'kalemler' => [['urun_id' => $u1, 'miktar' => '-5']]],  // negatif
    ['istemci_anahtar' => 'kotu', 'cari_id' => $c1, 'kalemler' => [['urun_id' => $u1, 'miktar' => '1']]],
    ['istemci_anahtar' => anahtar(), 'cari_id' => $c1, 'kalemler' => []],
], 'x' => 1], $T);
$d = array_column($toplu['j']['sonuclar'] ?? [], 'durum');
ok('toplu: 1 başarılı, 5 hata (kısmi başarısızlık diğerlerini bozmaz)', $d === ['olustu', 'hata', 'hata', 'hata', 'hata', 'hata'], json_encode($d));
ok('başkasının müşterisine sipariş oluşmadı', (int)Db::value('SELECT COUNT(*) FROM siparisler WHERE cari_id = ?', [$c2]) === 0);
ok('hata kalıcı işaretli', ($toplu['j']['sonuclar'][1]['kalici'] ?? false) === true);
ok('51 sipariş reddedilir (422)', api('POST', '/api/v1/siparisler', ['siparisler' => array_fill(0, 51, ['istemci_anahtar' => anahtar()])], $T)['kod'] === 422);

// geçmiş tarih / gelecek tarih bugüne çekilir
$kg = anahtar();
api('POST', '/api/v1/siparisler', ['siparisler' => [['istemci_anahtar' => $kg, 'cari_id' => $c1, 'tarih' => '2099-01-01', 'kalemler' => [['urun_id' => $u1, 'miktar' => '1']]]]], $T);
ok('gelecek tarih bugüne çekildi', Db::value('SELECT tarih FROM siparisler WHERE kaynak_key = ?', ['siparis:' . $kg]) === date('Y-m-d'));

$l = api('GET', '/api/v1/siparisler', null, $T);
ok('sipariş listesi', $l['kod'] === 200 && count($l['j']['siparisler'] ?? []) >= 3);

// başka kullanıcının anahtarı
$pg = api('POST', '/api/v1/giris', ['kullanici_adi' => 'patron', 'parola' => getenv('PW_PATRON')]);
$PT = $pg['j']['token'] ?? '';
$pp = api('POST', '/api/v1/siparisler', ['siparisler' => [['istemci_anahtar' => $k1, 'cari_id' => $c1, 'kalemler' => [['urun_id' => $u1, 'miktar' => '1']]]]], $PT);
ok('başkasının anahtarı yeniden kullanılamaz', ($pp['j']['sonuclar'][0]['durum'] ?? '') === 'hata');
ok('patron tüm müşterileri görür', count(api('GET', '/api/v1/senkron', null, $PT)['j']['musteriler'] ?? []) === 2);

// pasif kullanıcı → jeton anında geçersiz
Db::run("UPDATE kullanicilar SET aktif = 0 WHERE kullanici_adi = 'satisci'");
ok('pasif kullanıcının jetonu 401', api('GET', '/api/v1/senkron', null, $T)['kod'] === 401);
Db::run("UPDATE kullanicilar SET aktif = 1 WHERE kullanici_adi = 'satisci'");
ok('aktif edilince jeton yine çalışır', api('GET', '/api/v1/senkron', null, $T)['kod'] === 200);
// süre dolumu
Db::run('UPDATE api_tokenlari SET bitis = NOW() - INTERVAL 1 MINUTE WHERE token_hash = ?', [hash('sha256', $T)]);
ok('süresi dolan jeton 401', api('GET', '/api/v1/senkron', null, $T)['kod'] === 401);
// çıkış
ok('çıkış 200', api('POST', '/api/v1/cikis', [], $PT)['kod'] === 200);
ok('çıkıştan sonra jeton 401', api('GET', '/api/v1/senkron', null, $PT)['kod'] === 401);
ok('bilinmeyen yol 404', api('GET', '/api/v1/yok', null, api('POST', '/api/v1/giris', ['kullanici_adi' => 'patron', 'parola' => getenv('PW_PATRON')])['j']['token'] ?? '')['kod'] === 404);

echo "API testleri: $pass geçti, $fail başarısız\n";
exit($fail ? 1 : 0);
