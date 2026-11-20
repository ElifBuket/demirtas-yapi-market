<?php
declare(strict_types=1);
/**
 * SENTETİK demo hareketi üretir (tanıtım ekran görüntüleri / demo ortamı için). Yalnızca *_demo veritabanında çalışır.
 *   mysql -e "CREATE DATABASE demirtas_demo ..." ; mysqldump demirtas | mysql demirtas_demo ; DB_NAME=demirtas_demo php scripts/demo_veri.php
 * Üretilen tüm siparişler gerçek servis katmanından (Satis::*) geçer; iş kuralları atlanmaz.
 */
require dirname(__DIR__) . '/src/autoload.php';
use App\Core\{Config, Db};
use App\Services\{Hesap, IsHatasi, Satis};

if (!str_ends_with(Config::env('DB_NAME', ''), '_demo')) { fwrite(STDERR, "Yalnızca *_demo veritabanı.\n"); exit(2); }
mt_srand(20261120);
$kul = Db::one("SELECT id, kullanici_adi, ad_soyad, rol, satisci_id FROM kullanicilar WHERE rol = 'patron' LIMIT 1");
$k = fn() => bin2hex(random_bytes(16));
$musteriler = Db::all("SELECT id FROM cariler WHERE tip = 'musteri' AND durum = 'aktif' AND (risk_limitsiz = 1 OR risk_limiti >= 20000) ORDER BY id LIMIT 40");
$urunler = Db::all("SELECT u.id, u.satis_fiyati FROM urunler u WHERE u.aktif = 1 AND u.satis_fiyati > 5 AND (SELECT SUM(miktar) FROM stok_hareketleri h WHERE h.urun_id = u.id) > 300 ORDER BY u.id LIMIT 60");
$say = ['sip' => 0, 'fat' => 0, 'tah' => 0, 'hata' => 0];
for ($gun = 44; $gun >= 0; $gun--) {
    $tarih = date('Y-m-d', strtotime("-$gun days"));
    if (in_array(date('N', strtotime($tarih)), ['7'], true)) continue;
    $adet = mt_rand(2, 6);
    for ($i = 0; $i < $adet; $i++) {
        try {
            $c = $musteriler[mt_rand(0, count($musteriler) - 1)]['id'];
            $satirlar = [];
            foreach ((array)array_rand($urunler, mt_rand(1, 4)) as $ix) {
                $ix = (int)$ix; $u = $urunler[$ix];
                $satirlar[] = ['urun_id' => (int)$u['id'], 'miktar' => mt_rand(5, 60) * 1000, 'fiyat4' => Hesap::fiyatDb((string)$u['satis_fiyati']), 'iskonto_bp' => [0, 0, 200, 500][mt_rand(0, 3)]];
            }
            $s = Satis::siparisOlustur((int)$c, 1, $tarih, $satirlar, 0, $k(), $kul);
            $say['sip']++;
            if ($s['durum'] === 'onay_bekliyor') { if (mt_rand(0, 3) > 0) Satis::siparisOnayla($s['id'], $kul); else continue; }
            $kal = Db::all('SELECT id, miktar FROM siparis_kalemleri WHERE siparis_id = ? ORDER BY sira', [$s['id']]);
            $m = []; foreach ($kal as $x) $m[(int)$x['id']] = (int)round((float)$x['miktar'] * 1000);
            $irs = Satis::irsaliyeKes($s['id'], $m, $tarih, $k(), $kul);
            if (mt_rand(0, 9) < 8) {
                $f = Satis::faturaKes([$irs['id']], [0, 30, 30, 45, 60][mt_rand(0, 4)], $tarih, $k(), $kul); $say['fat']++;
                if (mt_rand(0, 9) < 6) {
                    $tt = date('Y-m-d', min(time(), strtotime($tarih . ' +' . mt_rand(3, 25) . ' days')));
                    $tutar = (int)round(Hesap::kurus((string)Db::value('SELECT toplam FROM faturalar WHERE id = ?', [$f['id']])) * [1, 1, 0.5][mt_rand(0, 2)]);
                    $yontem = ['nakit', 'havale', 'cek'][mt_rand(0, 2)];
                    $vade = $yontem === 'cek' ? date('Y-m-d', strtotime($tt . ' +' . mt_rand(20, 75) . ' days')) : null;
                    Satis::tahsilatGir((int)$c, $tutar, $yontem, $tt, $vade, $yontem === 'cek' ? 'CK-' . mt_rand(100000, 999999) : null, null, $k(), $kul); $say['tah']++;
                }
            }
        } catch (IsHatasi $e) { $say['hata']++; $say['e'][$e->getMessage()] = ($say['e'][$e->getMessage()] ?? 0) + 1; }
    }
}
echo json_encode($say), "\n";
