<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\{Audit, Db};

require_once dirname(__DIR__) . '/Norm.php';

/** Cari kartı güncelleme, ekstre ve cari bazlı yaşlandırma. */
final class CariKart
{
    /**
     * @param array<string,mixed> $g form girdisi (unvan, vkn, vergi_dairesi, telefon, eposta, il, ilce, satisci_id, durum, uyari_notu, risk_mod, risk_limiti)
     * @return list<string> değişen alanlar (boşsa değişiklik yok)
     */
    public static function guncelle(int $id, array $g, array $kul): array
    {
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $c = Db::one('SELECT * FROM cariler WHERE id = ? FOR UPDATE', [$id]);
            if (!$c) throw new IsHatasi('Cari bulunamadı.');
            $yeni = [];
            $unvan = trim((string)($g['unvan'] ?? ''));
            if ($unvan === '' || mb_strlen($unvan) > 200) throw new IsHatasi('Ünvan zorunlu (en çok 200 karakter).');
            $yeni['unvan'] = $unvan;

            $vknHam = trim((string)($g['vkn'] ?? ''));
            if ($vknHam === '') { $yeni['vkn'] = null; $yeni['vkn_gecerli'] = 0; }
            else {
                $v = \Norm::vkn($vknHam);
                if (!$v['gecerli']) throw new IsHatasi('VKN/TCKN geçersiz: ' . $v['gerekce'] . '. 10 haneli vergi no veya 11 haneli TCKN girin.');
                $yeni['vkn'] = $v['vkn']; $yeni['vkn_gecerli'] = 1;
                $dup = Db::value('SELECT kod FROM cariler WHERE vkn = ? AND tip = ? AND id <> ? LIMIT 1', [$v['vkn'], $c['tip'], $id]);
                if ($dup) throw new IsHatasi('Bu VKN/TCKN başka bir cari kartında kayıtlı (' . $dup . ').');
            }
            $yeni['vergi_dairesi'] = self::metin($g['vergi_dairesi'] ?? '', 80);
            $tel = trim((string)($g['telefon'] ?? ''));
            if ($tel === '') $yeni['telefon'] = null; else { $t = \Norm::phone($tel); if ($t['tel'] === null) throw new IsHatasi('Telefon geçersiz. Örnek: 0532 111 22 33'); $yeni['telefon'] = $t['tel']; }
            $ep = trim((string)($g['eposta'] ?? ''));
            if ($ep === '') $yeni['eposta'] = null; else { $e = \Norm::email($ep); if ($e['eposta'] === null) throw new IsHatasi('E-posta adresi geçersiz.'); $yeni['eposta'] = $e['eposta']; }
            $yeni['il'] = self::metin($g['il'] ?? '', 40);
            $yeni['ilce'] = self::metin($g['ilce'] ?? '', 60);
            $sid = (int)($g['satisci_id'] ?? 0);
            if ($sid > 0 && Db::value('SELECT 1 FROM satiscilar WHERE id = ?', [$sid]) === null) throw new IsHatasi('Satışçı bulunamadı.');
            $yeni['satisci_id'] = $sid > 0 ? $sid : null;
            $durum = (string)($g['durum'] ?? '');
            if (!in_array($durum, ['aktif', 'engelli'], true)) throw new IsHatasi('Durum geçersiz.');
            $yeni['durum'] = $durum;
            $yeni['uyari_notu'] = self::metin($g['uyari_notu'] ?? '', 255);

            // Risk limiti: yalnızca patron değiştirebilir
            $mod = (string)($g['risk_mod'] ?? '');
            if (!in_array($mod, ['limitli', 'limitsiz', 'tanimsiz'], true)) throw new IsHatasi('Risk limiti seçimi geçersiz.');
            $limit = null;
            if ($mod === 'limitli') {
                $f4 = Hesap::fiyatParse(trim((string)($g['risk_limiti'] ?? '')));
                if ($f4 === null || $f4 % 100 !== 0 || $f4 === 0) throw new IsHatasi('Risk limiti geçersiz. Örnek: 150000 veya 150000,50.');
                $limit = Hesap::dec(intdiv($f4, 100));
            }
            $yeni['risk_limitsiz'] = $mod === 'limitsiz' ? 1 : 0;
            $yeni['risk_limiti'] = $limit;
            $eskiRisk = [(int)$c['risk_limitsiz'], $c['risk_limiti'] === null ? null : Hesap::dec(Hesap::kurus((string)$c['risk_limiti']))];
            if ([$yeni['risk_limitsiz'], $yeni['risk_limiti']] !== $eskiRisk && ($kul['rol'] ?? '') !== 'patron') {
                throw new IsHatasi('Risk limitini yalnızca patron değiştirebilir.');
            }

            $eski = []; $yeniFark = []; $degisen = [];
            foreach ($yeni as $alan => $deger) {
                $mevcut = $c[$alan];
                if ($alan === 'risk_limiti') $mevcut = $mevcut === null ? null : Hesap::dec(Hesap::kurus((string)$mevcut));
                if ($alan === 'satisci_id') $mevcut = $mevcut === null ? null : (int)$mevcut;
                if (in_array($alan, ['vkn_gecerli', 'risk_limitsiz'], true)) $mevcut = (int)$mevcut;
                if ($mevcut !== $deger) { $eski[$alan] = $mevcut; $yeniFark[$alan] = $deger; $degisen[] = $alan; }
            }
            if ($degisen) {
                $set = implode(', ', array_map(fn($a) => "$a = ?", array_keys($yeniFark)));
                Db::run("UPDATE cariler SET $set WHERE id = ?", array_merge(array_values($yeniFark), [$id]));
                Audit::log('degistir', 'cariler', (string)$id, $eski, $yeniFark, 'Cari kartı güncellendi: ' . $c['kod'], $kul);
            }
            $pdo->commit();
            return $degisen;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    private static function metin(mixed $v, int $max): ?string
    {
        $v = trim((string)$v);
        if ($v === '') return null;
        if (mb_strlen($v) > $max) throw new IsHatasi("Metin çok uzun (en çok $max karakter).");
        return $v;
    }

    /**
     * Ekstre: açılış bakiyesi (bas öncesi) + dönem hareketleri + yürüyen bakiye. Bakiye = borç − alacak (kuruş).
     * @return array{acilis:int, satirlar:list<array>, borc:int, alacak:int, kapanis:int}
     */
    public static function ekstre(int $cariId, string $bas, string $bit): array
    {
        $acilis = Hesap::kurus((string)Db::value('SELECT COALESCE(SUM(borc) - SUM(alacak), 0) FROM cari_hareketler WHERE cari_id = ? AND tarih < ?', [$cariId, $bas]));
        $bak = $acilis; $tb = 0; $ta = 0; $out = [];
        foreach (Db::all('SELECT id, tarih, tur, borc, alacak, aciklama FROM cari_hareketler WHERE cari_id = ? AND tarih >= ? AND tarih <= ? ORDER BY tarih, id', [$cariId, $bas, $bit]) as $h) {
            $b = Hesap::kurus((string)$h['borc']); $a = Hesap::kurus((string)$h['alacak']);
            $bak += $b - $a; $tb += $b; $ta += $a;
            $out[] = ['tarih' => $h['tarih'], 'tur' => $h['tur'], 'aciklama' => $h['aciklama'], 'borc' => $b, 'alacak' => $a, 'bakiye' => $bak];
        }
        return ['acilis' => $acilis, 'satirlar' => $out, 'borc' => $tb, 'alacak' => $ta, 'kapanis' => $bak];
    }

    /** Cari bazlı açık fatura yaşlandırması. @return array<string,int> */
    public static function yaslandirma(int $cariId, string $bugun): array
    {
        $k = ['vadesi_gelmemis' => 0, 'g0_30' => 0, 'g31_60' => 0, 'g61_90' => 0, 'g90_ustu' => 0];
        foreach (Db::all('SELECT f.vade_tarihi, f.toplam - COALESCE((SELECT SUM(d.tutar) FROM tahsilat_dagitim d WHERE d.fatura_id = f.id),0) AS kalan FROM faturalar f WHERE f.cari_id = ?', [$cariId]) as $f) {
            $kalan = Hesap::kurus((string)$f['kalan']);
            if ($kalan <= 0) continue;
            $gec = (int)((strtotime($bugun) - strtotime($f['vade_tarihi'])) / 86400);
            $k[$gec <= 0 ? 'vadesi_gelmemis' : ($gec <= 30 ? 'g0_30' : ($gec <= 60 ? 'g31_60' : ($gec <= 90 ? 'g61_90' : 'g90_ustu')))] += $kalan;
        }
        return $k;
    }
}
