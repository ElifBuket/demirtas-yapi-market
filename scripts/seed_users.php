<?php
declare(strict_types=1);
/**
 * Her rol için bir deneme kullanıcısı oluşturur/günceller.
 *   php scripts/seed_users.php            => rastgele güçlü parolalar üretir ve ekrana yazar (bir kez)
 * Parolalar koda gömülmez; teslimde bu çıktıyı e-postaya yazarsınız.
 */
require dirname(__DIR__) . '/src/autoload.php';

use App\Core\{Auth, Db, Roles};

$hesaplar = [
    ['patron',   'Patron Demirtaş',  Roles::PATRON,  null],
    ['muhasebe', 'Muhasebe Kullanıcı', Roles::MUHASEBE, null],
    ['depo',     'Depo Kullanıcı',   Roles::DEPO,    null],
    ['satisci',  'Emre Doğan',       Roles::SATISCI, 'Emre Doğan'],
];

function rastgeleParola(): string
{
    $harf = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $p = '';
    for ($i = 0; $i < 14; $i++) $p .= $harf[random_int(0, strlen($harf) - 1)];
    return $p;
}

echo "Kullanıcı adı / Rol / Parola\n";
foreach ($hesaplar as [$ka, $ad, $rol, $satisciAd]) {
    $satisciId = $satisciAd ? Db::value('SELECT id FROM satiscilar WHERE ad = ?', [$satisciAd]) : null;
    $parola = rastgeleParola();
    $hash = Auth::hashPassword($parola);
    $var = Db::value('SELECT id FROM kullanicilar WHERE kullanici_adi = ?', [$ka]);
    if ($var) {
        Db::run('UPDATE kullanicilar SET ad_soyad=?, rol=?, satisci_id=?, parola_hash=?, aktif=1 WHERE id=?', [$ad, $rol, $satisciId, $hash, $var]);
    } else {
        Db::insert('INSERT INTO kullanicilar (kullanici_adi, ad_soyad, rol, satisci_id, parola_hash) VALUES (?,?,?,?,?)', [$ka, $ad, $rol, $satisciId, $hash]);
    }
    echo sprintf("%-10s %-9s %s\n", $ka, $rol, $parola);
}
