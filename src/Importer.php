<?php
declare(strict_types=1);

require_once __DIR__ . '/Norm.php';
require_once __DIR__ . '/Report.php';

/**
 * Eski Excel kayıtlarını (Cariler, Ürünler) yeni şemaya aktarır.
 * - Sessiz düzeltme yok: her değişiklik Report'a yazılır.
 * - Yeniden çalıştırılabilir: import_key / kaynak_key UNIQUE, mevcut kayıt tekrar eklenmez.
 * - Tüm yazma işlemi tek transaction içindedir; hata olursa hiçbir şey yazılmaz.
 */
final class Importer
{
    private const S_CARI = 'Cariler';
    private const S_URUN = 'Ürünler';

    public function __construct(
        private PDO $db,
        private Report $r,
        private string $acilisTarihi = '2025-12-31',
    ) {}

    /** @param array<string,list<array{row:int,data:array<string,mixed>}>> $book */
    public function run(array $book): array
    {
        $this->db->beginTransaction();
        try {
            $summary = [];
            $summary[self::S_CARI] = $this->importCariler($book[self::S_CARI] ?? []);
            $summary[self::S_URUN] = $this->importUrunler($book[self::S_URUN] ?? []);
            $this->db->commit();
            return $summary;
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    // =====================================================================
    //  CARİLER
    // =====================================================================

    private function importCariler(array $rows): array
    {
        $S = self::S_CARI;
        $recs = [];
        foreach ($rows as $row) {
            $recs[] = $this->prepareCari($row['row'], $row['data']);
        }

        // 1) Aynı VKN+tip => mükerrer grup; tek kayıt esas alınır
        $groups = [];
        foreach ($recs as $i => $c) $groups[$c['key']][] = $i;
        $skip = [];
        foreach ($groups as $key => $idxs) {
            if (count($idxs) < 2) continue;
            $primary = $this->pickPrimary($recs, $idxs);
            foreach ($idxs as $i) {
                if ($i === $primary) continue;
                $skip[$i] = true;
                $c = $recs[$i];
                $p = $recs[$primary];
                $kayipBakiye = $c['bakiye_kurus'] !== null && $c['bakiye_kurus'] !== 0;
                $pBos = $p['bakiye_kurus'] === null || $p['bakiye_kurus'] === 0;
                $oncelik = ($kayipBakiye && $pBos) ? 'yuksek' : ($kayipBakiye ? 'yuksek' : 'orta');
                $this->r->add($S, $c['row'], $c['kod_ham'] ?? $c['unvan'], Report::AKTARILMADI, $oncelik, 'mükerrer kayıt',
                    $c['unvan'] . ' | bakiye: ' . ($c['bakiye_ham'] ?? 'boş') . ' ' . ($c['ba'] ?? ''),
                    'esas kayıt: ' . ($p['kod_ham'] ?? ('satır ' . $p['row'])) . ' | bakiye: ' . ($p['bakiye_ham'] ?? 'boş'),
                    'Aynı VKN/TCKN ve tipte iki kayıt var. Kodlu/esas kayıt aktarıldı, bu kayıt ATLANDI; bakiyeler TOPLANMADI.'
                    . ($kayipBakiye ? ' DİKKAT: atlanan kaydın bakiyesi sisteme yansımadı, müşteriye sorulacak.' : ''));
                $this->r->count($S, 'mukerrer_atlanan');
            }
        }

        // 2) Aynı cari kodu farklı firmalara verilmişse: tip öneki uyanı koru, diğeri yeni kod alsın
        $byCode = [];
        foreach ($recs as $i => $c) {
            if (isset($skip[$i]) || $c['kod_ham'] === null) continue;
            $byCode[$c['kod_ham']][] = $i;
        }
        foreach ($byCode as $kod => $idxs) {
            if (count($idxs) < 2) continue;
            $keeper = $idxs[0];
            foreach ($idxs as $i) {
                $prefixOk = str_starts_with($kod, $recs[$i]['tip'] === 'musteri' ? '120.' : '320.');
                $keeperOk = str_starts_with($kod, $recs[$keeper]['tip'] === 'musteri' ? '120.' : '320.');
                if ($prefixOk && !$keeperOk) $keeper = $i;
            }
            foreach ($idxs as $i) {
                if ($i === $keeper) continue;
                $recs[$i]['kod_cakisma'] = true;
                $this->r->add($S, $recs[$i]['row'], $kod, Report::DUZELTILDI, 'yuksek', 'Cari Kodu', $kod, '(yeni kod atanacak)',
                    'Aynı cari kodu iki farklı firmada kullanılmış (' . $recs[$keeper]['unvan'] . ' / ' . $recs[$i]['unvan'] . '). Kod, ' . $recs[$keeper]['unvan'] . ' kaydında bırakıldı; bu firmaya yeni kod verildi.');
            }
        }

        // 3) Kod tahsisi için kullanılan kodlar
        $used = [];
        foreach ($this->db->query('SELECT kod FROM cariler') as $x) $used[$x['kod']] = true;
        foreach ($recs as $i => $c) {
            if (!isset($skip[$i]) && $c['kod_ham'] !== null && empty($c['kod_cakisma'])) $used[$c['kod_ham']] = true;
        }
        $maxNo = ['120.01.' => 0, '320.01.' => 0];
        foreach (array_keys($used) as $k) {
            foreach ($maxNo as $pre => $_) {
                if (str_starts_with($k, $pre)) $maxNo[$pre] = max($maxNo[$pre], (int)substr($k, strlen($pre)));
            }
        }

        // 4) Yazma
        $supAll = [];
        $sat = [];
        foreach ($this->db->query('SELECT id, ad FROM satiscilar') as $x) $sat[$x['ad']] = (int)$x['id'];

        $insC = $this->db->prepare('INSERT INTO cariler (kod,import_key,unvan,tip,vkn,vkn_gecerli,vergi_dairesi,telefon,eposta,il,ilce,risk_limiti,risk_limitsiz,para_birimi,satisci_id,satisci_ham,durum,uyari_notu,ham_not,son_islem_tarihi) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $insH = $this->db->prepare('INSERT IGNORE INTO cari_hareketler (cari_id,tarih,tur,borc,alacak,aciklama,kaynak_key) VALUES (?,?,?,?,?,?,?)');
        $exist = $this->db->prepare('SELECT id FROM cariler WHERE import_key = ?');

        $eklenen = 0;
        $mevcut = 0;
        $taken = [];
        foreach ($this->db->query('SELECT kod FROM cariler') as $x) $taken[$x['kod']] = true;
        foreach ($recs as $i => $c) {
            if (isset($skip[$i])) continue;
            $exist->execute([$c['key']]);
            if ($exist->fetchColumn() !== false) {
                $this->r->add($S, $c['row'], $c['kod_ham'] ?? $c['unvan'], Report::MEVCUT, 'dusuk', null, null, null, 'Kayıt daha önce aktarılmış (import_key eşleşti), tekrar eklenmedi.');
                $mevcut++;
                continue;
            }
            $kod = $c['kod_ham'];
            if ($kod === null || !empty($c['kod_cakisma']) || isset($taken[$kod])) {
                $pre = $c['tip'] === 'musteri' ? '120.01.' : '320.01.';
                $maxNo[$pre]++;
                $kod = $pre . str_pad((string)$maxNo[$pre], 3, '0', STR_PAD_LEFT);
                if ($c['kod_ham'] === null) {
                    $this->r->add($S, $c['row'], $kod, Report::DUZELTILDI, 'orta', 'Cari Kodu', null, $kod, 'Cari kodu boştu; tipe göre sıradaki kod atandı.');
                }
            }
            $taken[$kod] = true;
            $c['kod'] = $kod;
            $satId = $c['satisci_ad'] !== null ? ($sat[$c['satisci_ad']] ?? null) : null;

            $insC->execute([$kod, $c['key'], $c['unvan'], $c['tip'], $c['vkn'], $c['vkn_gecerli'] ? 1 : 0, $c['vergi_dairesi'], $c['telefon'], $c['eposta'],
                $c['il'], $c['ilce'], $c['risk_kurus'] === null ? null : Norm::kurusToStr($c['risk_kurus']), $c['risk_limitsiz'] ? 1 : 0, $c['para'], $satId, $c['satisci_ham'],
                $c['durum'], $c['uyari'], $c['ham_not'], $c['son_islem']]);
            $cariId = (int)$this->db->lastInsertId();
            $eklenen++;

            if ($c['hareket'] !== null) {
                [$borc, $alacak] = $c['hareket'];
                $insH->execute([$cariId, $this->acilisTarihi, 'acilis', Norm::kurusToStr($borc), Norm::kurusToStr($alacak), 'Açılış bakiyesi (eski Excel kaydı)', 'acilis:' . $c['key']]);
            }
        }

        $toplam = count($rows);
        return [
            'Excel satırı' => $toplam,
            'Aktarılan (yeni eklenen)' => $eklenen,
            'Zaten mevcut (atlandı)' => $mevcut,
            'Mükerrer olduğu için atlanan' => $this->r->counter($S, 'mukerrer_atlanan'),
            'Anlamlı düzeltme yapılan satır (orta/yüksek öncelik)' => $this->r->distinctRows($S, [Report::DUZELTILDI], ['orta', 'yuksek']),
            'Yalnızca biçim düzeltmesi yapılan satır (düşük öncelik)' => $this->r->distinctRows($S, [Report::DUZELTILDI], ['dusuk']),
            'Karar bekleyen satır' => $this->r->distinctRows($S, [Report::KARAR_BEKLIYOR]),
            'Engelli (sipariş girilemez) cari' => (int)$this->db->query("SELECT COUNT(*) FROM cariler WHERE durum='engelli'")->fetchColumn(),
        ];
    }

    /** Bir Excel satırını normalleştirir; her düzeltmeyi rapora yazar. */
    private function prepareCari(int $row, array $d): array
    {
        $S = self::S_CARI;
        $ham = fn(string $k) => Norm::clean($d[$k] instanceof Num ? $d[$k]->raw : ($d[$k] instanceof DateTimeInterface ? $d[$k]->format('Y-m-d') : $d[$k]));

        $kodHam = Norm::clean($d['Cari Kodu']);
        $unvanHam = (string)($d['Ünvan'] ?? '');
        $unvan = Norm::clean($unvanHam) ?? '(ünvansız)';
        $anahtar = $kodHam ?? $unvan;
        $add = fn(string $durum, string $oncelik, string $alan, ?string $eski, ?string $yeni, string $gerekce) =>
            $this->r->add($S, $row, $anahtar, $durum, $oncelik, $alan, $eski, $yeni, $gerekce);

        if ($unvanHam !== $unvan && trim($unvanHam) !== '') {
            $add(Report::DUZELTILDI, 'dusuk', 'Ünvan', $unvanHam, $unvan, 'Fazla boşluklar temizlendi.');
        }

        $tip = Norm::tip($d['Tipi']);
        if ($tip === null) {
            // tip bilinmiyorsa kodun önekinden çıkar
            $tip = ($kodHam !== null && str_starts_with($kodHam, '320')) ? 'tedarikci' : 'musteri';
            $add(Report::DUZELTILDI, 'yuksek', 'Tipi', $ham('Tipi'), $tip, 'Tip anlaşılamadı; cari kodu önekine göre belirlendi.');
        } elseif ($ham('Tipi') !== $tip) {
            $add(Report::DUZELTILDI, 'dusuk', 'Tipi', $ham('Tipi'), $tip, 'Tip yazımı standartlaştırıldı.');
        }
        if ($kodHam !== null) {
            $beklenen = $tip === 'musteri' ? '120.' : '320.';
            if (!str_starts_with($kodHam, $beklenen)) {
                $add(Report::UYARI, 'orta', 'Cari Kodu', $kodHam, $kodHam, "Cari kodu öneki tip ($tip) ile uyumsuz; kod olduğu gibi bırakıldı.");
            }
        }

        // VKN
        $v = Norm::vkn($d['VKN / TCKN']);
        if ($v['durum'] === 'duzeltildi') $add(Report::DUZELTILDI, 'orta', 'VKN / TCKN', $ham('VKN / TCKN'), $v['vkn'], $v['gerekce']);
        elseif ($v['durum'] === 'gecersiz') $add(Report::UYARI, 'orta', 'VKN / TCKN', $ham('VKN / TCKN'), $v['vkn'], $v['gerekce'] . ' Değer olduğu gibi saklandı; e-Fatura öncesi düzeltilmeli.');
        elseif ($v['durum'] === 'bos') $add(Report::UYARI, 'orta', 'VKN / TCKN', $ham('VKN / TCKN'), null, $v['gerekce']);

        $key = $v['vkn'] !== null ? 'vkn:' . $v['vkn'] . '|' . $tip : 'unvan:' . Norm::ascii($unvan) . '|' . $tip;

        // İletişim
        $p = Norm::phone($d['Telefon']);
        if ($p['durum'] === 'duzeltildi') $add(Report::DUZELTILDI, 'dusuk', 'Telefon', $ham('Telefon'), $p['tel'], $p['gerekce']);
        elseif ($p['durum'] === 'gecersiz') $add(Report::DUZELTILDI, 'orta', 'Telefon', $ham('Telefon'), null, $p['gerekce'] . ' Boş bırakıldı.');
        $e = Norm::email($d['E-posta']);
        if ($e['durum'] === 'duzeltildi') $add(Report::DUZELTILDI, 'orta', 'E-posta', $ham('E-posta'), $e['eposta'], $e['gerekce']);
        elseif ($e['durum'] === 'gecersiz') $add(Report::DUZELTILDI, 'orta', 'E-posta', $ham('E-posta'), null, $e['gerekce']);

        $ilHam = Norm::clean($d['İl']);
        $il = Norm::city($ilHam);
        if ($ilHam !== $il) $add(Report::DUZELTILDI, 'dusuk', 'İl', $ilHam, $il, 'İl yazımı standartlaştırıldı.');
        $ilceHam = Norm::clean($d['İlçe']);
        $ilce = Norm::district($ilceHam);
        if ($ilceHam !== $ilce) $add(Report::DUZELTILDI, 'dusuk', 'İlçe', $ilceHam, $ilce, 'İlçe yazımı standartlaştırıldı.');

        // Döviz / bakiye
        $para = Norm::currency($d['Döviz']);
        $dovizHam = $ham('Döviz');
        if ($para === 'EUR' && $dovizHam !== 'EUR') $add(Report::DUZELTILDI, 'dusuk', 'Döviz', $dovizHam, 'EUR', 'Döviz kodu standartlaştırıldı.');
        if ($para === 'EUR') $add(Report::UYARI, 'orta', 'Döviz', $dovizHam, 'EUR', 'Dövizli cari: açılış bakiyesi EUR olarak açıldı, TL\'ye çevrilmedi (müşteriye soruldu).');

        $m = Norm::parseMoney($d['Açılış Bakiyesi']);
        $ba = Norm::borcAlacak($d['B/A']);
        $bakiyeKurus = $m['kurus'];
        $hareket = null;
        if ($m['durum'] === 'gecersiz') {
            $add(Report::KARAR_BEKLIYOR, 'yuksek', 'Açılış Bakiyesi', $ham('Açılış Bakiyesi'), null, $m['gerekce'] . ' Bakiye AKTARILMADI; cari kartı bakiyesiz açıldı.');
            $bakiyeKurus = null;
        } elseif ($bakiyeKurus !== null && $bakiyeKurus !== 0) {
            if ($m['durum'] === 'duzeltildi') $add(Report::DUZELTILDI, $m['oncelik'], 'Açılış Bakiyesi', $ham('Açılış Bakiyesi'), Norm::kurusToStr($bakiyeKurus), $m['gerekce']);
            if ($m['not'] !== null) $add(Report::UYARI, 'orta', 'Açılış Bakiyesi', $ham('Açılış Bakiyesi'), null, 'Bakiye yanında not vardı: "' . $m['not'] . '" (ham_not alanına eklendi).');
            if ($ba === null) {
                $add(Report::KARAR_BEKLIYOR, 'yuksek', 'B/A', $ham('B/A'), null, 'Bakiye (' . Norm::kurusToStr($bakiyeKurus) . ' ' . $para . ') var ama B/A (borç/alacak) bilgisi boş ya da "-". Bakiye AKTARILMADI; cari bakiyesiz açıldı.');
            } else {
                $abs = abs($bakiyeKurus);
                $borc = ($ba === 'B') === ($bakiyeKurus > 0);
                if ($bakiyeKurus < 0) {
                    $add(Report::UYARI, 'yuksek', 'Açılış Bakiyesi', $ham('Açılış Bakiyesi'), Norm::kurusToStr($bakiyeKurus), 'Negatif tutar: yön ters çevrilerek ' . ($borc ? 'BORÇ' : 'ALACAK') . ' olarak yorumlandı (ör. müşteri avansı). Doğrulanmalı.');
                }
                if (($tip === 'musteri' && $ba === 'A') || ($tip === 'tedarikci' && $ba === 'B')) {
                    $add(Report::UYARI, 'orta', 'B/A', $ham('B/A'), $ba, 'Beklenmeyen yön: ' . ($tip === 'musteri' ? 'müşteri alacaklı (biz ona borçluyuz)' : 'tedarikçi borçlu (bize borçlu)') . '. Olduğu gibi aktarıldı.');
                }
                $hareket = $borc ? [$abs, 0] : [0, $abs];
            }
        }

        // Risk limiti
        $risk = Norm::parseRisk($d['Risk Limiti']);
        if ($risk['durum'] === 'gecersiz') {
            $add(Report::KARAR_BEKLIYOR, 'orta', 'Risk Limiti', $ham('Risk Limiti'), null, $risk['gerekce'] . ' Limit tanımsız bırakıldı.');
        } elseif ($risk['durum'] === 'bos') {
            if ($tip === 'musteri') $add(Report::UYARI, 'dusuk', 'Risk Limiti', null, null, $risk['gerekce']);
        } elseif ($risk['durum'] === 'duzeltildi') {
            $add(Report::DUZELTILDI, 'dusuk', 'Risk Limiti', $ham('Risk Limiti'), $risk['limitsiz'] ? 'limitsiz' : Norm::kurusToStr((int)$risk['kurus']), $risk['gerekce']);
        }

        // Satışçı
        $sHam = Norm::clean($d['Satışçı']);
        $s = Norm::satisci($sHam);
        if ($s['ayrildi']) {
            $add(Report::UYARI, 'orta', 'Satışçı', $sHam, null, 'Ayrılmış satışçı; müşteri atanmamış bırakıldı (yeni satışçı belirlenmeli).');
        } elseif ($sHam !== null && $s['ad'] === null) {
            $add(Report::UYARI, 'orta', 'Satışçı', $sHam, null, 'Satışçı tanınamadı, atanmadı.');
        } elseif ($s['ad'] !== null && $sHam !== $s['ad']) {
            $add(Report::DUZELTILDI, 'dusuk', 'Satışçı', $sHam, $s['ad'], 'Satışçı adı standartlaştırıldı.');
        }

        // Not => iş kuralı
        $not = Norm::clean($d['Not']);
        $na = Norm::ascii($not);
        $durum = 'aktif';
        $uyari = null;
        if ($not !== null) {
            if (str_contains($na, 'konkordato') || str_contains($na, 'kapandi') || str_contains($na, 'kapali hesap')) {
                $durum = 'engelli';
                $uyari = $not;
                $add(Report::UYARI, 'yuksek', 'Not', $not, 'durum=engelli', 'Nottaki ifade iş kuralı olarak yorumlandı: bu cariye sipariş girişi ENGELLENECEK.');
            } elseif (str_contains($na, 'pesin') || str_contains($na, 'cek ile')) {
                $uyari = $not;
                $add(Report::UYARI, 'orta', 'Not', $not, 'uyari_notu', 'Çalışma şekli notu sipariş ekranında UYARI olarak gösterilecek (engellemez).');
            }
        }
        $notlar = array_filter([$not, $m['not'] ?? null]);

        // Son işlem tarihi
        $t = Norm::date($d['Son İşlem Tarihi'], $this->acilisTarihi);
        if ($t['durum'] === 'gecersiz') $add(Report::DUZELTILDI, 'dusuk', 'Son İşlem Tarihi', $ham('Son İşlem Tarihi'), null, $t['gerekce']);

        return [
            'row' => $row, 'key' => $key, 'kod_ham' => $kodHam, 'unvan' => $unvan, 'tip' => $tip,
            'vkn' => $v['vkn'], 'vkn_gecerli' => $v['gecerli'], 'vergi_dairesi' => Norm::clean($d['Vergi Dairesi']),
            'telefon' => $p['tel'], 'eposta' => $e['eposta'], 'il' => $il, 'ilce' => $ilce,
            'risk_kurus' => $risk['kurus'], 'risk_limitsiz' => $risk['limitsiz'], 'para' => $para,
            'satisci_ad' => $s['ad'], 'satisci_ham' => $sHam, 'durum' => $durum, 'uyari' => $uyari,
            'ham_not' => $notlar ? implode(' | ', $notlar) : null, 'son_islem' => $t['tarih'],
            'bakiye_kurus' => $bakiyeKurus, 'bakiye_ham' => $ham('Açılış Bakiyesi'), 'ba' => $ba, 'hareket' => $hareket,
        ];
    }

    /** Mükerrer grupta esas kaydı seçer: 120.01/320.01 kodlu > diğer kodlu > ilk satır. */
    private function pickPrimary(array $recs, array $idxs): int
    {
        $best = $idxs[0];
        $score = fn(array $c) => $c['kod_ham'] === null ? 0 : (preg_match('/^(120|320)\.01\./', $c['kod_ham']) ? 2 : 1);
        foreach ($idxs as $i) {
            if ($score($recs[$i]) > $score($recs[$best])) $best = $i;
        }
        return $best;
    }

    // =====================================================================
    //  ÜRÜNLER
    // =====================================================================

    private function importUrunler(array $rows): array
    {
        $S = self::S_URUN;
        $recs = [];
        foreach ($rows as $row) $recs[] = $this->prepareUrun($row['row'], $row['data']);

        // Stok kodu çakışmaları: aynı kod farklı ürünlerde. Kategori önekiyle uyuşan kodu korur.
        $byCode = [];
        foreach ($recs as $i => $u) if ($u['kod_ham'] !== null) $byCode[$u['kod_ham']][] = $i;
        $needNew = [];
        foreach ($byCode as $kod => $idxs) {
            if (count($idxs) < 2) continue;
            $keeper = $idxs[0];
            foreach ($idxs as $i) {
                $ok = ($recs[$i]['prefix'] ?? '') === substr($kod, 0, 3);
                $keeperOk = ($recs[$keeper]['prefix'] ?? '') === substr($kod, 0, 3);
                if ($ok && !$keeperOk) $keeper = $i;
            }
            foreach ($idxs as $i) {
                if ($i === $keeper) continue;
                $needNew[$i] = true;
                $this->r->add($S, $recs[$i]['row'], $kod, Report::DUZELTILDI, 'yuksek', 'Stok Kodu', $kod, '(yeni kod atanacak)',
                    'Aynı stok kodu farklı ürünlerde kullanılmış ("' . $recs[$keeper]['ad'] . '" / "' . $recs[$i]['ad'] . '"). Kod "' . $recs[$keeper]['ad'] . '" ürününde bırakıldı, bu ürüne yeni kod verildi.');
            }
        }
        foreach ($recs as $i => $u) if ($u['kod_ham'] === null) $needNew[$i] = true;

        $used = [];
        foreach ($this->db->query('SELECT stok_kodu FROM urunler') as $x) $used[$x['stok_kodu']] = true;
        foreach ($recs as $i => $u) if (!isset($needNew[$i]) && $u['kod_ham'] !== null) $used[$u['kod_ham']] = true;
        $maxNo = [];
        foreach (array_keys($used) as $k) {
            if (preg_match('/^([A-Z]{3})-(\d+)$/', $k, $m)) $maxNo[$m[1]] = max($maxNo[$m[1]] ?? 0, (int)$m[2]);
        }

        // tedarikçi eşlemesi için tedarikçi cari adları
        $sup = [];
        foreach ($this->db->query("SELECT id, unvan FROM cariler WHERE tip='tedarikci'") as $x) {
            $sup[preg_replace('/[^a-z0-9]/', '', Norm::ascii($x['unvan']))] = (int)$x['id'];
        }

        $depo = [];
        foreach ($this->db->query('SELECT id, kod FROM depolar') as $x) $depo[$x['kod']] = (int)$x['id'];

        $insU = $this->db->prepare('INSERT INTO urunler (stok_kodu,import_key,ad,kategori,temel_birim,alis_fiyati,satis_fiyati,kdv_orani,kritik_stok,barkod,tedarikci_cari_id,tedarikci_ham) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
        $insC = $this->db->prepare('INSERT IGNORE INTO urun_birim_cevrim (urun_id,alt_birim,pay,payda) VALUES (?,?,?,?)');
        $insS = $this->db->prepare('INSERT IGNORE INTO stok_hareketleri (urun_id,depo_id,tarih,tur,miktar,aciklama,kaynak_key) VALUES (?,?,?,?,?,?,?)');
        $exist = $this->db->prepare('SELECT id FROM urunler WHERE import_key = ?');

        $usedBarcodes = [];
        foreach ($this->db->query('SELECT barkod FROM urunler WHERE barkod IS NOT NULL') as $x) $usedBarcodes[$x['barkod']] = true;

        $eklenen = 0; $mevcut = 0; $tedEslesen = 0;
        foreach ($recs as $i => $u) {
            // import_key: ad + kategori (kod değişebilir, ad Excel'de benzersiz)
            $exist->execute([$u['key']]);
            if ($exist->fetchColumn() !== false) {
                $this->r->add($S, $u['row'], $u['kod_ham'] ?? $u['ad'], Report::MEVCUT, 'dusuk', null, null, null, 'Ürün daha önce aktarılmış (import_key eşleşti), tekrar eklenmedi.');
                $mevcut++;
                continue;
            }
            $kod = $u['kod_ham'];
            if (isset($needNew[$i])) {
                $pre = $u['prefix'] ?? 'GNL';
                $maxNo[$pre] = ($maxNo[$pre] ?? 0) + 1;
                $kod = sprintf('%s-%04d', $pre, $maxNo[$pre]);
                if ($u['kod_ham'] === null) {
                    $this->r->add($S, $u['row'], $kod, Report::DUZELTILDI, 'orta', 'Stok Kodu', null, $kod, 'Stok kodu boştu; kategoriye göre sıradaki kod atandı.');
                }
            }
            $barkod = $u['barkod'];
            if ($barkod !== null && isset($usedBarcodes[$barkod])) {
                $this->r->add($S, $u['row'], $kod, Report::DUZELTILDI, 'orta', 'Barkod', $barkod, null, 'Barkod başka bir üründe zaten var; bu üründe boş bırakıldı.');
                $barkod = null;
            }
            if ($barkod !== null) $usedBarcodes[$barkod] = true;

            $tedId = null;
            if ($u['tedarikci_ham'] !== null) {
                $k = preg_replace('/[^a-z0-9]/', '', Norm::ascii($u['tedarikci_ham']));
                $tedId = $sup[$k] ?? null;
                if ($tedId !== null) $tedEslesen++;
            }

            $insU->execute([$kod, $u['key'], $u['ad'], $u['kategori'], $u['birim'], $u['alis'], $u['satis'], $u['kdv'], $u['kritik'], $barkod, $tedId, $u['tedarikci_ham']]);
            $urunId = (int)$this->db->lastInsertId();
            $eklenen++;

            if ($u['cevrim'] !== null) {
                $insC->execute([$urunId, $u['cevrim']['alt'], $u['cevrim']['pay'], $u['cevrim']['payda']]);
            }
            foreach (['MERKEZ' => $u['stok_merkez'], 'OSB' => $u['stok_osb']] as $dk => $miktar) {
                if ($miktar !== null && $miktar !== '0.000') {
                    $insS->execute([$urunId, $depo[$dk], $this->acilisTarihi, 'acilis', $miktar, 'Açılış stoğu (eski Excel kaydı; sayım tarihi belirsiz)', 'acilis:' . $u['key'] . ':' . $dk]);
                }
            }
        }
        $this->r->count($S, 'tedarikci_eslesen', $tedEslesen);

        return [
            'Excel satırı' => count($rows),
            'Aktarılan (yeni eklenen)' => $eklenen,
            'Zaten mevcut (atlandı)' => $mevcut,
            'Anlamlı düzeltme yapılan satır (orta/yüksek öncelik)' => $this->r->distinctRows($S, [Report::DUZELTILDI], ['orta', 'yuksek']),
            'Yalnızca biçim düzeltmesi yapılan satır (düşük öncelik)' => $this->r->distinctRows($S, [Report::DUZELTILDI], ['dusuk']),
            'Dikkat gerektiren (uyarı) satır' => $this->r->distinctRows($S, [Report::UYARI]),
            'Tedarikçi cari kartıyla eşleşen' => $tedEslesen . ' / ' . $eklenen . ' (kalanı ham metin olarak saklandı)',
        ];
    }

    private function prepareUrun(int $row, array $d): array
    {
        $S = self::S_URUN;
        $ham = fn(string $k) => Norm::clean($d[$k] instanceof Num ? $d[$k]->raw : $d[$k]);
        $adHam = (string)($d['Ürün Adı'] ?? '');
        $ad = Norm::clean($adHam) ?? '(adsız)';
        $kodHam = Norm::clean($d['Stok Kodu']);
        $kodHam = $kodHam !== null ? strtoupper($kodHam) : null;
        $anahtar = $kodHam ?? $ad;
        $add = fn(string $durum, string $oncelik, string $alan, ?string $eski, ?string $yeni, string $gerekce) =>
            $this->r->add($S, $row, $anahtar, $durum, $oncelik, $alan, $eski, $yeni, $gerekce);

        if ($adHam !== $ad && trim($adHam) !== '') $add(Report::DUZELTILDI, 'dusuk', 'Ürün Adı', $adHam, $ad, 'Fazla boşluklar temizlendi.');

        $kat = Norm::category($d['Kategori']) ?? 'Diğer';
        if ($ham('Kategori') !== $kat) $add(Report::DUZELTILDI, 'dusuk', 'Kategori', $ham('Kategori'), $kat, 'Kategori yazımı standartlaştırıldı.');
        $prefix = Norm::CATEGORY_PREFIX[$kat] ?? null;

        // Birim
        $birim = Norm::unit($d['Birim']);
        if ($birim === null) {
            $birim = 'adet';
            $add(Report::DUZELTILDI, 'yuksek', 'Birim', $ham('Birim'), 'adet', 'Birim tanınamadı, "adet" varsayıldı.');
        } elseif ($ham('Birim') !== $birim) {
            $add(Report::DUZELTILDI, 'dusuk', 'Birim', $ham('Birim'), $birim, 'Birim yazımı standartlaştırıldı.');
        }

        // Çevrim
        $cevHam = $ham('Birim Çevrim');
        $cevrim = null;
        if ($cevHam !== null) {
            $cevrim = Norm::conversion($cevHam, $birim);
            if ($cevrim === null) {
                // "torba" gibi bozuk çevrim: ürün adındaki "50 kg" ifadesinden çıkarmayı dene
                if (preg_match('/(\d+(?:[.,]\d+)?)\s*kg/iu', $ad, $m) && $birim === 'torba') {
                    $cevrim = Norm::conversion('1 torba = ' . $m[1] . ' kg', 'torba');
                    $add(Report::DUZELTILDI, 'orta', 'Birim Çevrim', $cevHam, '1 torba = ' . $m[1] . ' kg', 'Çevrim bozuktu; ürün adındaki ağırlıktan türetildi. Doğrulanmalı.');
                } else {
                    $add(Report::UYARI, 'yuksek', 'Birim Çevrim', $cevHam, null, 'Çevrim ifadesi temel birimle ("' . $birim . '") eşleşmedi veya çözümlenemedi; çevrim kaydedilmedi.');
                }
            }
        } elseif ($birim === 'torba') {
            if (preg_match('/(\d+(?:[.,]\d+)?)\s*kg/iu', $ad, $m)) {
                $cevrim = Norm::conversion('1 torba = ' . $m[1] . ' kg', 'torba');
                $add(Report::DUZELTILDI, 'orta', 'Birim Çevrim', null, '1 torba = ' . $m[1] . ' kg', 'Çevrim boştu; ürün adındaki ağırlıktan türetildi. Doğrulanmalı.');
            }
        }

        // KDV
        $kdv = Norm::kdv($d['KDV']);
        if ($kdv['durum'] === 'duzeltildi') {
            $sup = str_contains($kdv['gerekce'], 'ŞÜPHELİ') || str_contains($kdv['gerekce'], 'Geçersiz');
            $add(Report::DUZELTILDI, $sup ? 'yuksek' : 'dusuk', 'KDV', $ham('KDV'), '%' . $kdv['oran'], $kdv['gerekce']);
        }

        // Fiyatlar (KDV hariç; "KDV dahil" yazan satırlar bölünür)
        $fiyat = function (string $alan) use ($d, $kdv, $add, $ham): ?string {
            $m = Norm::price($d[$alan]);
            if ($m['durum'] === 'bos') {
                $add(Report::UYARI, 'yuksek', $alan, null, null, "$alan boş; ürün fiyatsız aktarıldı, fiyat girilmeden satılamaz.");
                return null;
            }
            if ($m['durum'] === 'gecersiz' || $m['kurus'] === null) {
                $add(Report::KARAR_BEKLIYOR, 'yuksek', $alan, $ham($alan), null, $m['gerekce'] . ' Fiyat aktarılmadı.');
                return null;
            }
            if ($m['kdv_dahil']) {
                $yeni = Norm::kdvDahilToHaric($m['kurus'], $kdv['oran']);
                $add(Report::DUZELTILDI, 'orta', $alan, $ham($alan), $yeni, 'Fiyat "KDV dahil" yazıyordu; sistem KDV hariç tuttuğu için %' . $kdv['oran'] . ' KDV\'ye bölündü.');
                return $yeni;
            }
            if ($m['durum'] === 'duzeltildi') $add(Report::DUZELTILDI, $m['oncelik'], $alan, $ham($alan), Norm::kurusToStr($m['kurus']), $m['gerekce']);
            return Norm::kurusToP4Str($m['kurus']);
        };
        $alis = $fiyat('Alış Fiyatı');
        $satis = $fiyat('Satış Fiyatı');
        if ($alis !== null && $satis !== null && (float)$satis < (float)$alis) {
            $add(Report::UYARI, 'yuksek', 'Satış Fiyatı', $satis, $satis, 'ŞÜPHELİ FİYAT: satış (' . $satis . ') alış fiyatının (' . $alis . ') altında. Olduğu gibi aktarıldı; doğrulanmalı.');
        }

        // Stoklar
        $sm = Norm::stock($d['Merkez Depo'], $birim);
        $so = Norm::stock($d['OSB Depo'], $birim);
        foreach (['Merkez Depo' => $sm, 'OSB Depo' => $so] as $alan => $s) {
            if ($s['durum'] === 'duzeltildi') $add(Report::DUZELTILDI, $s['oncelik'], $alan, $ham($alan), $s['miktar'], $s['gerekce']);
        }

        // Kritik stok
        $kritik = null;
        $kr = $d['Kritik Stok'];
        if ($kr !== null) {
            $kk = Norm::clean($kr instanceof Num ? $kr->raw : $kr);
            if ($kk !== null && preg_match('/^\d+([.,]\d+)?$/', $kk)) $kritik = str_replace(',', '.', $kk);
            elseif ($kk !== null) $add(Report::DUZELTILDI, 'dusuk', 'Kritik Stok', $kk, null, 'Kritik stok sayıya çevrilemedi, boş bırakıldı.');
        }

        // Barkod
        $b = Norm::barcode($d['Barkod']);
        if ($b['durum'] === 'gecersiz') $add(Report::DUZELTILDI, 'orta', 'Barkod', $ham('Barkod'), null, $b['gerekce']);

        return [
            'row' => $row, 'key' => 'urun:' . Norm::ascii($ad), 'kod_ham' => $kodHam, 'ad' => $ad, 'kategori' => $kat, 'prefix' => $prefix,
            'birim' => $birim, 'cevrim' => $cevrim, 'kdv' => $kdv['oran'], 'alis' => $alis, 'satis' => $satis, 'kritik' => $kritik,
            'barkod' => $b['barkod'], 'tedarikci_ham' => Norm::clean($d['Tedarikçi']),
            'stok_merkez' => $sm['miktar'], 'stok_osb' => $so['miktar'],
        ];
    }
}
