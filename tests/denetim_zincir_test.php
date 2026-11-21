<?php
declare(strict_types=1);
/** İşlem kaydı hash zinciri testleri (*_test veritabanı). */
require dirname(__DIR__) . '/src/autoload.php';
use App\Core\{Audit, Config, Db};

if (!str_ends_with(Config::env('DB_NAME', 'demirtas'), '_test')) { fwrite(STDERR, "Yalnızca *_test.\n"); exit(2); }
$pass = 0; $fail = 0;
function esit(string $ad, mixed $a, mixed $b): void { global $pass, $fail; if ($a === $b) { $pass++; return; } $fail++; echo "BAŞARISIZ: $ad [" . var_export($a, true) . ' != ' . var_export($b, true) . "]\n"; }

/** Tetikleyiciler değiştirmeyi engellediği için kurcalama simülasyonunda geçici olarak kaldırılır, sonra geri konur. */
function kurcala(callable $f): void {
    Db::pdo()->exec('DROP TRIGGER IF EXISTS islem_kaydi_degistirilemez');
    Db::pdo()->exec('DROP TRIGGER IF EXISTS islem_kaydi_silinemez');
    $f();
    Db::pdo()->exec("CREATE TRIGGER islem_kaydi_degistirilemez BEFORE UPDATE ON islem_kaydi FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'islem_kaydi degistirilemez'");
    Db::pdo()->exec("CREATE TRIGGER islem_kaydi_silinemez BEFORE DELETE ON islem_kaydi FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'islem_kaydi silinemez'");
}
function temizle(): void { kurcala(fn() => Db::run('DELETE FROM islem_kaydi')); }

$u = ['id' => null, 'kullanici_adi' => 'test', 'rol' => 'patron'];

// 1) boş tablo
temizle();
esit('boş tabloda zincir yok', Audit::dogrula()['durum'], 'zincir_yok');

// 2) zincirden ÖNCEKİ eski (hash'siz) kayıt zinciri bozmaz
Db::run("INSERT INTO islem_kaydi (eylem, aciklama) VALUES ('giris', 'eski kayıt')");
for ($i = 1; $i <= 5; $i++) {
    Audit::log('ekle', 'cariler', $i, null, ['unvan' => "Firma $i", 'bakiye' => 10.5 * $i, 'ic' => ['z' => 1, 'a' => 2]], "kayıt $i", $u);
}
Audit::log('degistir', 'cariler', 3, ['unvan' => 'Çğüşİ ö / "tırnak"'], ['unvan' => 'Yeni'], 'türkçe ve özel karakter', $u);
$r = Audit::dogrula();
esit('5+1 kayıt doğrulanır', $r['durum'], 'tamam');
esit('zincirli sayısı', $r['zincirli'], 6);
esit('eski hash’siz kayıt sayılmaz', $r['toplam'], 7);

// 3) ilk zincirli kaydın önceki_hash'i boş, sonrakiler bir öncekine bağlı
$h = Db::all('SELECT onceki_hash, hash FROM islem_kaydi WHERE hash IS NOT NULL ORDER BY id');
esit('ilk kaydın önceki hash’i boş', $h[0]['onceki_hash'], '');
esit('ikinci kayıt ilkine bağlı', $h[1]['onceki_hash'], $h[0]['hash']);
esit('hash 64 karakter', strlen($h[0]['hash']), 64);

// 4) içerik değiştirilirse yakalanır
$orta = (int)Db::value("SELECT id FROM islem_kaydi WHERE kayit_id = '3' AND eylem = 'ekle'");
kurcala(fn() => Db::run("UPDATE islem_kaydi SET aciklama = 'oynandı' WHERE id = ?", [$orta]));
$r = Audit::dogrula();
esit('değiştirilen kayıt yakalanır', $r['durum'], 'bozuk');
esit('bozuk kayıt doğru bulunur', $r['bozuk_id'], $orta);
kurcala(fn() => Db::run("UPDATE islem_kaydi SET aciklama = 'kayıt 3' WHERE id = ?", [$orta]));
esit('geri alınınca zincir yeniden tamam', Audit::dogrula()['durum'], 'tamam');

// 5) kullanıcı adı / eylem de korunur
kurcala(fn() => Db::run("UPDATE islem_kaydi SET kullanici_adi = 'baskasi' WHERE id = ?", [$orta]));
esit('kullanıcı adı değişikliği yakalanır', Audit::dogrula()['durum'], 'bozuk');
kurcala(fn() => Db::run("UPDATE islem_kaydi SET kullanici_adi = 'test' WHERE id = ?", [$orta]));

// 6) ortadan kayıt silinirse yakalanır
kurcala(fn() => Db::run('DELETE FROM islem_kaydi WHERE id = ?', [$orta]));
$r = Audit::dogrula();
esit('ortadan silme yakalanır', $r['durum'], 'bozuk');

// 7) baştan (zincirin ilk kaydı) silinirse yakalanır
temizle();
for ($i = 1; $i <= 3; $i++) Audit::log('ekle', 'urunler', $i, null, ['k' => $i], 'x', $u);
esit('yeni zincir tamam', Audit::dogrula()['durum'], 'tamam');
$ilk = (int)Db::value('SELECT MIN(id) FROM islem_kaydi');
kurcala(fn() => Db::run('DELETE FROM islem_kaydi WHERE id = ?', [$ilk]));
esit('baştan silme yakalanır', Audit::dogrula()['durum'], 'bozuk');

// 8) araya sahte kayıt eklenirse (hash'siz ya da yanlış hash'li) yakalanır
temizle();
for ($i = 1; $i <= 3; $i++) Audit::log('ekle', 'urunler', $i, null, null, 'x', $u);
Db::run("INSERT INTO islem_kaydi (eylem, aciklama) VALUES ('sil', 'sahte')");
esit('zincir ortasında/sonunda hash’siz kayıt yakalanır', Audit::dogrula()['durum'], 'bozuk');
temizle();
for ($i = 1; $i <= 3; $i++) Audit::log('ekle', 'urunler', $i, null, null, 'x', $u);
Db::run("INSERT INTO islem_kaydi (eylem, aciklama, onceki_hash, hash) VALUES ('sil', 'sahte', ?, ?)", [str_repeat('a', 64), str_repeat('b', 64)]);
esit('sahte hash’li kayıt yakalanır', Audit::dogrula()['durum'], 'bozuk');

// 9) kayıt hâlâ değiştirilemez/silinemez (tetikleyici varsa) ve parola sızmaz
temizle();
Audit::log('giris', null, null, null, null, 'x', $u);
$patlar = true;
try { Db::run("UPDATE islem_kaydi SET aciklama = 'x'"); $patlar = false; } catch (PDOException) {}
esit('UPDATE engelli (tetikleyici)', $patlar, true);

echo "denetim_zincir_test: $pass geçti, $fail başarısız\n";
exit($fail > 0 ? 1 : 0);
