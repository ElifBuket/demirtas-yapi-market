<?php
declare(strict_types=1);
/**
 * Canlı sunucuya HTTP ile saldırı/yetki testi.
 *   BASE_URL=http://127.0.0.1:8081 PW_PATRON=.. PW_MUHASEBE=.. PW_DEPO=.. PW_SATISCI=.. php tests/guvenlik_http.php
 * Testler veritabanına geçici kayıt ekler ve sonunda temizler.
 */
require dirname(__DIR__) . '/src/autoload.php';

use App\Core\{Auth, Db};

$base = rtrim(getenv('BASE_URL') ?: 'http://127.0.0.1:8081', '/');
$pw = ['patron' => getenv('PW_PATRON'), 'muhasebe' => getenv('PW_MUHASEBE'), 'depo' => getenv('PW_DEPO'), 'satisci' => getenv('PW_SATISCI')];

$pass = 0; $fail = 0;
function ok(string $ad, bool $kosul, string $ayrinti = ''): void {
    global $pass, $fail;
    if ($kosul) { $pass++; return; }
    $fail++; echo "BAŞARISIZ: $ad" . ($ayrinti !== '' ? "  [$ayrinti]" : '') . "\n";
}

final class Istemci {
    public string $jar;
    public function __construct(private string $base) { $this->jar = tempnam(sys_get_temp_dir(), 'jar'); }
    /** @return array{kod:int, govde:string, basliklar:string, konum:?string} */
    public function istek(string $yontem, string $yol, array $form = [], array $basliklar = []): array {
        $ch = curl_init($this->base . $yol);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEFILE => $this->jar, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_CUSTOMREQUEST => $yontem,
            CURLOPT_HTTPHEADER => $basliklar, CURLOPT_TIMEOUT => 20,
        ]);
        if ($yontem === 'POST') curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form));
        $yanit = (string)curl_exec($ch);
        $kod = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $hs = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        $h = substr($yanit, 0, $hs);
        preg_match('/^Location:\s*(\S+)/mi', $h, $m);
        return ['kod' => $kod, 'govde' => substr($yanit, $hs), 'basliklar' => $h, 'konum' => $m[1] ?? null];
    }
    public function csrf(string $yol = '/giris'): string {
        $r = $this->istek('GET', $yol);
        preg_match('/name="_csrf" value="([0-9a-f]+)"/', $r['govde'], $m);
        return $m[1] ?? '';
    }
    public function giris(string $ka, string $parola): array {
        return $this->istek('POST', '/giris', ['_csrf' => $this->csrf(), 'kullanici_adi' => $ka, 'parola' => $parola]);
    }
    public function oturumCerezi(): string {
        preg_match('/DYM_OTURUM\s+(\S+)/', (string)file_get_contents($this->jar), $m);
        return $m[1] ?? '';
    }
}

// Başlangıç temizliği (önceki koşulların izleri)
Db::run('DELETE FROM giris_denemeleri');

// --- 1. Giriş yapmadan erişim ---
$c = new Istemci($base);
foreach (['/', '/cariler', '/denetim'] as $yol) {
    $r = $c->istek('GET', $yol);
    ok("anonim $yol girişe yönlendirilir", $r['kod'] === 303 && str_contains((string)$r['konum'], '/giris'), "kod={$r['kod']}");
}

// --- 2. Güvenlik başlıkları + çerez bayrakları ---
$r = (new Istemci($base))->istek('GET', '/giris'); // taze istemci: çerez ilk yanıtta gönderilir
ok('X-Frame-Options', stripos($r['basliklar'], 'X-Frame-Options: DENY') !== false);
ok('X-Content-Type-Options', stripos($r['basliklar'], 'X-Content-Type-Options: nosniff') !== false);
ok('Content-Security-Policy', stripos($r['basliklar'], 'Content-Security-Policy:') !== false);
ok('X-Powered-By gizli', stripos($r['basliklar'], 'X-Powered-By') === false);
ok('çerez HttpOnly', (bool)preg_match('/Set-Cookie: DYM_OTURUM=[^;]+;.*HttpOnly/i', $r['basliklar']), $r['basliklar']);
ok('çerez SameSite', (bool)preg_match('/Set-Cookie: DYM_OTURUM=[^;]+;.*SameSite=Lax/i', $r['basliklar']));

// --- 3. CSRF ---
$r = $c->istek('POST', '/giris', ['kullanici_adi' => 'patron', 'parola' => (string)$pw['patron']]);
ok('CSRF jetonsuz giriş reddedilir (419)', $r['kod'] === 419, "kod={$r['kod']}");
$r = $c->istek('POST', '/giris', ['_csrf' => str_repeat('a', 64), 'kullanici_adi' => 'patron', 'parola' => (string)$pw['patron']]);
ok('Sahte CSRF jetonu reddedilir (419)', $r['kod'] === 419);
$tok = $c->csrf();
$r = $c->istek('POST', '/giris', ['_csrf' => $tok, 'kullanici_adi' => 'patron', 'parola' => (string)$pw['patron']], ['Origin: http://evil.example']);
ok('Yabancı Origin reddedilir (419)', $r['kod'] === 419, "kod={$r['kod']}");

// --- 4. Hatalı giriş ---
$c2 = new Istemci($base);
$r = $c2->giris('patron', 'yanlis-parola');
ok('yanlış parola 200 + hata mesajı', $r['kod'] === 200 && str_contains($r['govde'], 'hatalı'));
$r2 = $c2->giris('yok.kullanici', 'x');
ok('olmayan kullanıcı aynı mesaj', str_contains($r2['govde'], 'Kullanıcı adı veya parola hatalı'));
$r = $c2->istek('GET', '/cariler');
ok('hatalı girişten sonra oturum açılmadı', $r['kod'] === 303);

// --- 5. Rol matrisi (sunucu tarafı) ---
$beklenen = [
    'patron'   => ['/yonetim' => 200, '/' => 200, '/cariler' => 200, '/denetim' => 200, '/stok' => 200, '/stok/transfer?urun=1' => 200, '/stok/sayim?urun=1' => 200],
    'muhasebe' => ['/yonetim' => 200, '/' => 200, '/cariler' => 200, '/denetim' => 403, '/stok' => 200, '/stok/transfer?urun=1' => 403, '/stok/sayim?urun=1' => 403],
    'depo'     => ['/yonetim' => 403, '/' => 200, '/cariler' => 403, '/denetim' => 403, '/stok' => 200, '/stok/transfer?urun=1' => 200, '/stok/sayim?urun=1' => 200],
    'satisci'  => ['/yonetim' => 403, '/' => 200, '/cariler' => 200, '/denetim' => 403, '/stok' => 200, '/stok/transfer?urun=1' => 403, '/stok/sayim?urun=1' => 403],
];
$toplamlar = [];
foreach ($beklenen as $rol => $yollar) {
    $k = new Istemci($base);
    $onceki = $k->csrf();
    $cerezOnce = $k->oturumCerezi();
    $r = $k->giris($rol, (string)$pw[$rol]);
    ok("$rol giriş başarılı (303)", $r['kod'] === 303, "kod={$r['kod']}");
    ok("$rol giriş sonrası oturum kimliği yenilendi", $k->oturumCerezi() !== '' && $k->oturumCerezi() !== $cerezOnce);
    foreach ($yollar as $yol => $kod) {
        $x = $k->istek('GET', $yol);
        ok("$rol GET $yol => $kod", $x['kod'] === $kod, "kod={$x['kod']}");
        if ($yol === '/cariler' && $kod === 200) {
            preg_match('/(\d+) kayıt, sayfa/', $x['govde'], $m);
            $toplamlar[$rol] = (int)($m[1] ?? -1);
            if ($rol === 'satisci') ok('satışçı başka satışçıların müşterisini görmez', !str_contains($x['govde'], 'Mehmet Kaya') && !str_contains($x['govde'], 'Serkan Öztürk') && !str_contains($x['govde'], 'Ahmet Yılmaz'));
        }
    }
}
$dbSatisci = (int)Db::value("SELECT COUNT(*) FROM cariler c JOIN kullanicilar k ON k.satisci_id = c.satisci_id WHERE k.kullanici_adi = 'satisci'");
ok('satışçının gördüğü sayı = kendi müşteri sayısı', ($toplamlar['satisci'] ?? -1) === $dbSatisci, "ekran={$toplamlar['satisci']} db=$dbSatisci");
ok('patron tüm carileri görür', ($toplamlar['patron'] ?? 0) === (int)Db::value('SELECT COUNT(*) FROM cariler'));

// --- 6. SQL injection ---
$p = new Istemci($base); $p->giris('patron', (string)$pw['patron']);
foreach (["' OR '1'='1", "'; DROP TABLE cariler; --", "\" OR 1=1 --", "x' UNION SELECT parola_hash FROM kullanicilar -- "] as $yuk) {
    $x = $p->istek('GET', '/cariler?q=' . rawurlencode($yuk));
    preg_match('/(\d+) kayıt, sayfa/', $x['govde'], $m);
    ok('SQLi yükü sonuç sızdırmaz: ' . substr($yuk, 0, 18), $x['kod'] === 200 && ($m[1] ?? '') === '0' && !str_contains($x['govde'], '$argon2') && !str_contains($x['govde'], '$2y$'), "kod={$x['kod']} n=" . ($m[1] ?? '?'));
}
ok('cariler tablosu hâlâ duruyor', (int)Db::value('SELECT COUNT(*) FROM cariler') > 0);
$x = $p->istek('GET', '/cariler?q=%25');
preg_match('/(\d+) kayıt, sayfa/', $x['govde'], $m);
ok('LIKE joker karakteri % kaçırılır (hepsini getirmez)', ($m[1] ?? '') === '0', 'n=' . ($m[1] ?? '?'));
$x = $p->istek('GET', '/cariler?sayfa=' . rawurlencode("1 OR 1=1") . '&tip=' . rawurlencode("x' OR 1=1"));
ok('sayfa/tip parametresi enjekte edilemez', $x['kod'] === 200);

// --- 7. XSS (saklı) ---
$xssAd = '<script>alert(1)</script><img src=x onerror=alert(2)>';
$xssId = Db::insert("INSERT INTO cariler (kod, unvan, tip, para_birimi) VALUES ('999.99.999', ?, 'musteri', 'TRY')", [$xssAd]);
$x = $p->istek('GET', '/cariler?q=' . rawurlencode('999.99.999'));
ok('XSS: script etiketi kaçırıldı', !str_contains($x['govde'], '<script>alert(1)') && str_contains($x['govde'], '&lt;script&gt;alert(1)&lt;/script&gt;'));
ok('XSS: img onerror etiketi kaçırıldı', !str_contains($x['govde'], '<img src=x') && str_contains($x['govde'], '&lt;img src=x onerror=alert(2)&gt;'));
$x = $p->istek('GET', '/cariler?q=' . rawurlencode('"><script>alert(3)</script>'));
ok('XSS: yansıyan arama değeri kaçırıldı', !str_contains($x['govde'], '"><script>alert(3)') && str_contains($x['govde'], '&quot;&gt;&lt;script&gt;'));
$x = $p->istek('POST', '/giris', ['_csrf' => $p->csrf('/cariler'), 'kullanici_adi' => '"><script>alert(4)</script>', 'parola' => 'x']);
ok('XSS: giriş formundaki kullanıcı adı kaçırıldı', !str_contains($x['govde'], '"><script>alert(4)'));
Db::run('DELETE FROM cariler WHERE id = ?', [$xssId]);

// --- 8. Yöntem ve çıkış ---
$x = $p->istek('GET', '/cikis');
ok('GET /cikis çalışmaz (405)', $x['kod'] === 405, "kod={$x['kod']}");
$x = $p->istek('POST', '/cikis');
ok('CSRF jetonsuz çıkış reddedilir', $x['kod'] === 419);
$x = $p->istek('POST', '/cikis', ['_csrf' => $p->csrf('/')]);
ok('çıkış başarılı (303)', $x['kod'] === 303);
$x = $p->istek('GET', '/cariler');
ok('çıkıştan sonra erişim yok', $x['kod'] === 303);
$x = $p->istek('GET', '/olmayan-sayfa');
ok('404 sayfası', $x['kod'] === 404 || $x['kod'] === 303);

// --- 9. Pasife alınan kullanıcı açık oturumla da giremez ---
$d = new Istemci($base); $d->giris('depo', (string)$pw['depo']);
ok('depo oturumu açık', $d->istek('GET', '/')['kod'] === 200);
Db::run("UPDATE kullanicilar SET aktif = 0 WHERE kullanici_adi = 'depo'");
ok('pasife alınan kullanıcı hemen yetkisini kaybeder', $d->istek('GET', '/')['kod'] === 303);
Db::run("UPDATE kullanicilar SET aktif = 1 WHERE kullanici_adi = 'depo'");

// --- 10. Parola saklama ---
$hash = (string)Db::value("SELECT parola_hash FROM kullanicilar WHERE kullanici_adi = 'patron'");
ok('parola hash olarak saklanıyor', str_starts_with($hash, '$argon2id$') || str_starts_with($hash, '$2y$'), substr($hash, 0, 10));
ok('düz parola veritabanında yok', $hash !== $pw['patron'] && !str_contains($hash, (string)$pw['patron']));
ok('password_verify doğru çalışıyor', password_verify((string)$pw['patron'], $hash));

// --- 11. Kaba kuvvet engeli ---
Db::run('DELETE FROM giris_denemeleri');
$b = new Istemci($base);
$son = '';
for ($i = 1; $i <= 6; $i++) { $son = $b->giris('depo', 'yanlis' . $i)['govde']; }
ok('5 hatalı denemeden sonra giriş engellenir', str_contains($son, 'Çok fazla hatalı deneme'));
$r = $b->giris('depo', (string)$pw['depo']);
ok('engel varken doğru parola da kabul edilmez', $r['kod'] === 200 && str_contains($r['govde'], 'Çok fazla'), "kod={$r['kod']}");
Db::run('DELETE FROM giris_denemeleri');

// --- 12. İşlem kaydı ---
foreach (['giris', 'giris_basarisiz', 'cikis', 'yetkisiz'] as $eylem) {
    ok("işlem kaydında '$eylem' var", (int)Db::value('SELECT COUNT(*) FROM islem_kaydi WHERE eylem = ?', [$eylem]) > 0);
}
$yetkisiz = Db::one("SELECT kullanici_adi, rol, aciklama FROM islem_kaydi WHERE eylem = 'yetkisiz' ORDER BY id DESC LIMIT 1");
ok('yetkisiz deneme kullanıcı ve yolla kaydedildi', $yetkisiz !== null && $yetkisiz['kullanici_adi'] !== null && str_contains((string)$yetkisiz['aciklama'], '/'), json_encode($yetkisiz));
$patlar = true;
try { Db::run('UPDATE islem_kaydi SET aciklama = ? WHERE id = (SELECT id FROM (SELECT MIN(id) id FROM islem_kaydi) t)', ['oynandi']); $patlar = false; } catch (PDOException) {}
ok('işlem kaydı değiştirilemez (UPDATE engelli)', $patlar);
$patlar = true;
try { Db::run('DELETE FROM islem_kaydi LIMIT 1'); $patlar = false; } catch (PDOException) {}
ok('işlem kaydı silinemez (DELETE engelli)', $patlar);
ok('parola işlem kaydına yazılmadı', (int)Db::value("SELECT COUNT(*) FROM islem_kaydi WHERE aciklama LIKE ? OR yeni_deger LIKE ?", ['%' . $pw['patron'] . '%', '%' . $pw['patron'] . '%']) === 0);

// --- Stok: uçtan uca transfer, çift gönderim, yetki, XSS ---
$d = new Istemci($base);
$d->giris('depo', $pw['depo']);
$urunId = (int)Db::value("SELECT id FROM urunler WHERE aktif = 1 AND temel_birim = 'adet' ORDER BY id LIMIT 1");
$sayfa = $d->istek('GET', "/stok/transfer?urun=$urunId")['govde'];
preg_match('/name="_csrf" value="([0-9a-f]+)"/', $sayfa, $mc); preg_match('/name="anahtar" value="([0-9a-f]{32})"/', $sayfa, $ma);
ok('transfer formunda csrf ve anahtar var', !empty($mc[1]) && !empty($ma[1]));
$once = (int)Db::value('SELECT COUNT(*) FROM stok_hareketleri WHERE urun_id = ?', [$urunId]);
$topOnce = (string)Db::value('SELECT SUM(miktar) FROM stok_hareketleri WHERE urun_id = ?', [$urunId]);
$merkezOnce = (string)Db::value('SELECT COALESCE(SUM(miktar),0) FROM stok_hareketleri WHERE urun_id = ? AND depo_id = 1', [$urunId]);
$form = ['_csrf' => $mc[1], 'urun_id' => $urunId, 'anahtar' => $ma[1], 'kaynak_depo' => 1, 'hedef_depo' => 2, 'birim' => 'adet', 'miktar' => '1', 'aciklama' => '<script>x</script>'];
if ((float)$merkezOnce >= 1) {
    $r1 = $d->istek('POST', '/stok/transfer', $form);
    ok('transfer başarılı → yönlendirme', $r1['kod'] === 303, "kod={$r1['kod']}");
    $r2 = $d->istek('POST', '/stok/transfer', $form); // aynı anahtar: çift tıklama
    ok('çift gönderim reddedilir', $r2['kod'] === 422 && str_contains($r2['govde'], 'zaten kaydedildi'), "kod={$r2['kod']}");
    ok('tam 2 hareket eklendi', (int)Db::value('SELECT COUNT(*) FROM stok_hareketleri WHERE urun_id = ?', [$urunId]) === $once + 2);
    ok('transfer toplam stoğu değiştirmedi', (string)Db::value('SELECT SUM(miktar) FROM stok_hareketleri WHERE urun_id = ?', [$urunId]) === $topOnce);
    $h = $d->istek('GET', "/stok/hareket?urun=$urunId")['govde'];
    ok('açıklamadaki script kaçırılmış', !str_contains($h, '<script>x</script>') && str_contains($h, '&lt;script&gt;'));
    $a = Db::one("SELECT eylem, tablo, kullanici_adi FROM islem_kaydi WHERE tablo = 'stok_hareketleri' ORDER BY id DESC LIMIT 1");
    ok('transfer işlem kaydına yazıldı', $a !== null && $a['kullanici_adi'] === 'depo', json_encode($a));
    Db::run("DELETE FROM stok_hareketleri WHERE kaynak_key LIKE ?", ['islem:' . $ma[1] . ':%']); // temizlik
} else { echo "UYARI: ürün $urunId merkez stoğu 0, transfer testi atlandı\n"; }
$r = $d->istek('POST', '/stok/transfer', array_merge($form, ['_csrf' => 'sahte', 'anahtar' => bin2hex(random_bytes(16))]));
ok('stok transfer: sahte CSRF reddedilir', $r['kod'] === 419, "kod={$r['kod']}");
$f2 = array_merge($form, ['_csrf' => $mc[1], 'anahtar' => bin2hex(random_bytes(16)), 'miktar' => '999999999']);
$r = $d->istek('POST', '/stok/transfer', $f2);
ok('yetersiz stok reddedilir', $r['kod'] === 422 && str_contains($r['govde'], 'yeterli stok'), "kod={$r['kod']}");
$m = new Istemci($base); $m->giris('muhasebe', $pw['muhasebe']);
$tok = $m->csrf('/stok');
$r = $m->istek('POST', '/stok/transfer', array_merge($form, ['_csrf' => $tok, 'anahtar' => bin2hex(random_bytes(16))]));
ok('muhasebe transfer POST yapamaz (403)', $r['kod'] === 403, "kod={$r['kod']}");
$r = $d->istek('GET', '/stok?q=' . urlencode("' OR 1=1 --") . '&kritik=1&sayfa=-5&tarih=2099-01-01');
ok('SQL enjeksiyonu / saçma parametre 500 vermez', $r['kod'] === 200, "kod={$r['kod']}");
$r = $d->istek('GET', '/stok/hareket?urun=999999');
ok('olmayan ürün 404', $r['kod'] === 404, "kod={$r['kod']}");

echo "\n$pass geçti, $fail başarısız\n";
exit($fail > 0 ? 1 : 0);
