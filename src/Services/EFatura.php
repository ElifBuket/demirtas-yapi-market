<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\{Config, Db};

/**
 * Faturadan UBL-TR 1.2 (UBL 2.1 tabanlı) e-Fatura XML'i üretir.
 * Kullanılan eşleme (özet):
 *  - Fatura numarası "FTR-2026-000001" → GİB biçimi "FTR2026000000001" (3 harf + yıl + 9 hane)
 *  - UUID = faturalar.ettn; para birimi TRY (EUR faturada kur bilgisi olmadığından üretilmez)
 *  - Satır iskontosu + genel iskontonun satıra düşen payı tek "AllowanceCharge" olarak satırda; satır tutarı (LineExtensionAmount) iskonto sonrası matrahtır
 *  - KDV: oran başına grup, satır KDV'leri grup KDV'sine kuruşu kuruşuna dağıtılır (en büyük kalan yöntemi)
 * Üretilen XML mali mühürle imzalanmamıştır (UBLExtensions yeri boş); imzalama/gönderim entegratör işidir.
 * Resmî GİB şema/schematron doğrulaması bu ortamda çalıştırılmadı; yalnızca yapı ve aritmetik testleri vardır.
 */
final class EFatura
{
    private const NS = [
        'inv' => 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2',
        'cac' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2',
        'cbc' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
        'ext' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2',
        'ds' => 'http://www.w3.org/2000/09/xmldsig#',
        'xsi' => 'http://www.w3.org/2001/XMLSchema-instance',
    ];
    /** Birim → UN/ECE Rec.20 kodu. Listede olmayan birim hata verir (sessizce C62'ye çevirmeyiz). */
    public const BIRIM_KODU = [
        'adet' => 'C62', 'paket' => 'PA', 'koli' => 'BX', 'kutu' => 'BX', 'torba' => 'BG', 'kova' => 'C62', 'rulo' => 'RO',
        'boy' => 'C62', 'makara' => 'C62', 'kg' => 'KGM', 'ton' => 'TNE', 'm' => 'MTR', 'm2' => 'MTK',
    ];

    private \DOMDocument $d;

    public static function firma(): array
    {
        return [
            'unvan' => Config::env('FIRMA_UNVAN', 'DEMİRTAŞ YAPI MARKET (DENEME)'),
            'vkn' => Config::env('FIRMA_VKN', '1234567890'),
            'vergi_dairesi' => Config::env('FIRMA_VERGI_DAIRESI', 'Merkez'),
            'adres' => Config::env('FIRMA_ADRES', 'Organize Sanayi Bölgesi'),
            'ilce' => Config::env('FIRMA_ILCE', 'Merkez'),
            'il' => Config::env('FIRMA_IL', 'Sivas'),
            'eposta' => Config::env('FIRMA_EPOSTA', ''),
        ];
    }

    /** GİB fatura numarası biçimi */
    public static function gibNo(string $belgeNo): string
    {
        if (!preg_match('/^([A-Z]{3})-(\d{4})-(\d{1,9})$/', $belgeNo, $m)) throw new IsHatasi('Fatura numarası GİB biçimine çevrilemedi: ' . $belgeNo);
        return $m[1] . $m[2] . str_pad($m[3], 9, '0', STR_PAD_LEFT);
    }

    /** Satırlardaki KDV'yi grup KDV'sine tam dağıtır. @param list<int> $matrahlar @return list<int> */
    public static function kdvDagit(array $matrahlar, int $grupKdv): array
    {
        $toplam = array_sum($matrahlar);
        if ($toplam <= 0 || $grupKdv <= 0) return array_fill(0, count($matrahlar), 0);
        $pay = []; $kalan = [];
        foreach ($matrahlar as $i => $m) { $pay[$i] = intdiv($grupKdv * $m, $toplam); $kalan[$i] = ($grupKdv * $m) % $toplam; }
        $eksik = $grupKdv - array_sum($pay);
        $idx = array_keys($kalan);
        usort($idx, fn($a, $b) => $kalan[$b] <=> $kalan[$a] ?: $a <=> $b);
        for ($k = 0; $k < $eksik; $k++) $pay[$idx[$k]]++;
        return $pay;
    }

    /** XML üretmeden önce engelleyici eksikleri listeler (boşsa üretilebilir). */
    public static function eksikler(int $faturaId): array
    {
        $f = Db::one('SELECT f.*, c.unvan, c.vkn, c.vkn_gecerli, c.il FROM faturalar f JOIN cariler c ON c.id = f.cari_id WHERE f.id = ?', [$faturaId]);
        if (!$f) return ['Fatura bulunamadı.'];
        $e = [];
        if ($f['para_birimi'] !== 'TRY') $e[] = 'Yalnızca TRY faturalar için XML üretilir (' . $f['para_birimi'] . ' için döviz kuru bilgisi sistemde yok).';
        if (!$f['vkn'] || !(int)$f['vkn_gecerli'] || !in_array(strlen((string)$f['vkn']), [10, 11], true)) $e[] = 'Müşterinin VKN/TCKN bilgisi eksik veya geçersiz (' . ($f['vkn'] ?: 'boş') . '): önce cari kartı düzeltilmeli.';
        if (!$f['il']) $e[] = 'Müşterinin il bilgisi boş.';
        $firma = self::firma();
        if (!preg_match('/^\d{10}$/', $firma['vkn'])) $e[] = 'Firma VKN ortam değişkeni (FIRMA_VKN) 10 haneli olmalı.';
        foreach (Db::all('SELECT DISTINCT u.temel_birim FROM fatura_kalemleri fk JOIN urunler u ON u.id = fk.urun_id WHERE fk.fatura_id = ?', [$faturaId]) as $b) {
            if (!isset(self::BIRIM_KODU[$b['temel_birim']])) $e[] = 'Birim için UN/ECE kodu tanımlı değil: ' . $b['temel_birim'];
        }
        return $e;
    }

    public static function uret(int $faturaId): string
    {
        $e = self::eksikler($faturaId);
        if ($e) throw new IsHatasi(implode(' ', $e));
        return (new self())->olustur($faturaId);
    }

    // ---------- XML yardımcıları ----------
    private function el(string $qname, ?string $text = null, array $attr = []): \DOMElement
    {
        [$pre] = explode(':', $qname);
        $n = $this->d->createElementNS(self::NS[$pre], $qname);
        if ($text !== null) $n->appendChild($this->d->createTextNode($text));
        foreach ($attr as $k => $v) $n->setAttribute($k, $v);
        return $n;
    }

    private function ekle(\DOMElement $ata, \DOMElement ...$cocuklar): \DOMElement
    {
        foreach ($cocuklar as $c) $ata->appendChild($c);
        return $ata;
    }

    private function tutar(string $qname, int $kurus, string $cur = 'TRY'): \DOMElement { return $this->el($qname, Hesap::dec($kurus), ['currencyID' => $cur]); }

    private function taraf(string $unvan, string $vkn, string $vd, string $adres, string $ilce, string $il, ?string $eposta, bool $tckn): \DOMElement
    {
        $p = $this->el('cac:Party');
        $this->ekle($p, $this->ekle($this->el('cac:PartyIdentification'), $this->el('cbc:ID', $vkn, ['schemeID' => $tckn ? 'TCKN' : 'VKN'])));
        if ($tckn) {
            // Gerçek kişi: ad/soyad ayrıştırması son sözcük = soyad varsayımıyla yapılır
            $parca = preg_split('/\s+/u', trim($unvan)); $soyad = array_pop($parca); $ad = implode(' ', $parca) ?: $soyad;
            $this->ekle($p, $this->ekle($this->el('cac:PartyName'), $this->el('cbc:Name', $unvan)));
        } else {
            $this->ekle($p, $this->ekle($this->el('cac:PartyName'), $this->el('cbc:Name', $unvan)));
        }
        $adresEl = $this->el('cac:PostalAddress');
        if ($adres !== '') $this->ekle($adresEl, $this->el('cbc:StreetName', $adres));
        $this->ekle($adresEl, $this->el('cbc:CitySubdivisionName', $ilce ?: 'Merkez'), $this->el('cbc:CityName', $il), $this->ekle($this->el('cac:Country'), $this->el('cbc:Name', 'Türkiye')));
        $this->ekle($p, $adresEl);
        $this->ekle($p, $this->ekle($this->el('cac:PartyTaxScheme'), $this->ekle($this->el('cac:TaxScheme'), $this->el('cbc:Name', $vd))));
        if ($eposta) $this->ekle($p, $this->ekle($this->el('cac:Contact'), $this->el('cbc:ElectronicMail', $eposta)));
        if ($tckn) $this->ekle($p, $this->ekle($this->el('cac:Person'), $this->el('cbc:FirstName', $ad), $this->el('cbc:FamilyName', $soyad)));
        return $p;
    }

    private function olustur(int $faturaId): string
    {
        $f = Db::one('SELECT f.*, c.unvan, c.vkn, c.vergi_dairesi, c.il, c.ilce, c.eposta FROM faturalar f JOIN cariler c ON c.id = f.cari_id WHERE f.id = ?', [$faturaId]);
        $kal = Db::all('SELECT fk.*, u.stok_kodu, u.ad, u.temel_birim FROM fatura_kalemleri fk JOIN urunler u ON u.id = fk.urun_id WHERE fk.fatura_id = ? ORDER BY fk.sira', [$faturaId]);
        $irs = Db::all('SELECT belge_no, tarih FROM irsaliyeler WHERE fatura_id = ? ORDER BY id', [$faturaId]);
        $firma = self::firma();
        $cur = 'TRY';

        // Tutarlar (kuruş) ve KDV dağıtımı
        $matrah = array_map(fn($k) => Hesap::kurus((string)$k['matrah']), $kal);
        $gruplar = [];
        foreach ($kal as $i => $k) $gruplar[(int)$k['kdv_orani']][] = $i;
        ksort($gruplar);
        $satirKdv = []; $grupBilgi = [];
        foreach ($gruplar as $oran => $idxler) {
            $gm = array_sum(array_map(fn($i) => $matrah[$i], $idxler));
            $gk = Hesap::bolRound($gm * $oran, 100);
            $grupBilgi[$oran] = ['matrah' => $gm, 'kdv' => $gk];
            foreach (self::kdvDagit(array_map(fn($i) => $matrah[$i], $idxler), $gk) as $j => $v) $satirKdv[$idxler[$j]] = $v;
        }
        $kdvToplam = array_sum(array_column($grupBilgi, 'kdv'));
        $matrahToplam = array_sum($matrah);
        if ($matrahToplam !== Hesap::kurus((string)$f['matrah']) || $kdvToplam !== Hesap::kurus((string)$f['kdv']) || $matrahToplam + $kdvToplam !== Hesap::kurus((string)$f['toplam'])) {
            throw new IsHatasi('Fatura satırları ile başlık toplamları tutmuyor; XML üretilmedi (veri bütünlüğü hatası).');
        }

        $this->d = new \DOMDocument('1.0', 'UTF-8');
        $this->d->formatOutput = true;
        $root = $this->d->createElementNS(self::NS['inv'], 'Invoice');
        foreach (['cac', 'cbc', 'ext', 'ds', 'xsi'] as $p) $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:' . $p, self::NS[$p]);
        $root->setAttributeNS(self::NS['xsi'], 'xsi:schemaLocation', 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2 UBL-Invoice-2.1.xsd');
        $this->d->appendChild($root);

        // Mali mühür/imza yeri: GİB örneklerindeki gibi bir yer tutucu (imza entegratör/imzalama aracı tarafından bu elemanın yerine konur).
        $this->ekle($root, $this->ekle($this->el('ext:UBLExtensions'), $this->ekle($this->el('ext:UBLExtension'), $this->ekle($this->el('ext:ExtensionContent'), $this->d->createElementNS('urn:harc:imza-yeri', 'harc:imza-yeri')))));
        $this->ekle($root,
            $this->el('cbc:UBLVersionID', '2.1'), $this->el('cbc:CustomizationID', 'TR1.2'), $this->el('cbc:ProfileID', 'TICARIFATURA'),
            $this->el('cbc:ID', self::gibNo($f['belge_no'])), $this->el('cbc:CopyIndicator', 'false'), $this->el('cbc:UUID', $f['ettn']),
            $this->el('cbc:IssueDate', $f['tarih']), $this->el('cbc:IssueTime', substr((string)$f['olusturma'], 11, 8) ?: '00:00:00'),
            $this->el('cbc:InvoiceTypeCode', 'SATIS'),
            $this->el('cbc:Note', 'Yalnızca ' . self::yaziyla($matrahToplam + $kdvToplam) . ' (' . Hesap::goster($matrahToplam + $kdvToplam) . ' TL)'),
            $this->el('cbc:DocumentCurrencyCode', $cur), $this->el('cbc:LineCountNumeric', (string)count($kal)));
        foreach ($irs as $r) {
            $this->ekle($root, $this->ekle($this->el('cac:DespatchDocumentReference'), $this->el('cbc:ID', $r['belge_no']), $this->el('cbc:IssueDate', $r['tarih'])));
        }
        // İmza referansı (imzalama entegratörde yapılır)
        $this->ekle($root, $this->ekle($this->el('cac:Signature'),
            $this->el('cbc:ID', $firma['vkn'], ['schemeID' => 'VKN_TCKN']),
            $this->ekle($this->el('cac:SignatoryParty'),
                $this->ekle($this->el('cac:PartyIdentification'), $this->el('cbc:ID', $firma['vkn'], ['schemeID' => 'VKN'])),
                $this->ekle($this->el('cac:PostalAddress'), $this->el('cbc:CitySubdivisionName', $firma['ilce']), $this->el('cbc:CityName', $firma['il']), $this->ekle($this->el('cac:Country'), $this->el('cbc:Name', 'Türkiye')))),
            $this->ekle($this->el('cac:DigitalSignatureAttachment'), $this->ekle($this->el('cac:ExternalReference'), $this->el('cbc:URI', '#Signature')))));
        $this->ekle($root, $this->ekle($this->el('cac:AccountingSupplierParty'), $this->taraf($firma['unvan'], $firma['vkn'], $firma['vergi_dairesi'], $firma['adres'], $firma['ilce'], $firma['il'], $firma['eposta'] ?: null, false)));
        $this->ekle($root, $this->ekle($this->el('cac:AccountingCustomerParty'), $this->taraf($f['unvan'], (string)$f['vkn'], (string)($f['vergi_dairesi'] ?: ''), '', (string)($f['ilce'] ?: ''), (string)$f['il'], $f['eposta'] ?: null, strlen((string)$f['vkn']) === 11)));
        $this->ekle($root, $this->ekle($this->el('cac:PaymentTerms'), $this->el('cbc:Note', 'Vade: ' . $f['vade_tarihi']), $this->el('cbc:PaymentDueDate', $f['vade_tarihi'])));

        $tt = $this->el('cac:TaxTotal');
        $this->ekle($tt, $this->tutar('cbc:TaxAmount', $kdvToplam, $cur));
        foreach ($grupBilgi as $oran => $g) {
            $this->ekle($tt, $this->ekle($this->el('cac:TaxSubtotal'),
                $this->tutar('cbc:TaxableAmount', $g['matrah'], $cur), $this->tutar('cbc:TaxAmount', $g['kdv'], $cur), $this->el('cbc:Percent', (string)$oran),
                $this->ekle($this->el('cac:TaxCategory'), $this->ekle($this->el('cac:TaxScheme'), $this->el('cbc:Name', 'KDV'), $this->el('cbc:TaxTypeCode', '0015')))));
        }
        $this->ekle($root, $tt);
        $this->ekle($root, $this->ekle($this->el('cac:LegalMonetaryTotal'),
            $this->tutar('cbc:LineExtensionAmount', $matrahToplam, $cur), $this->tutar('cbc:TaxExclusiveAmount', $matrahToplam, $cur),
            $this->tutar('cbc:TaxInclusiveAmount', $matrahToplam + $kdvToplam, $cur), $this->tutar('cbc:PayableAmount', $matrahToplam + $kdvToplam, $cur)));

        foreach ($kal as $i => $k) {
            $brut = Hesap::kurus((string)$k['brut']);
            $isk = Hesap::kurus((string)$k['iskonto']) + Hesap::kurus((string)$k['genel_iskonto']);
            $satir = $this->el('cac:InvoiceLine');
            $this->ekle($satir, $this->el('cbc:ID', (string)($i + 1)),
                $this->el('cbc:InvoicedQuantity', rtrim(rtrim(Birim::fmt(Birim::fromDecimal((string)$k['miktar'])), '0'), '.') , ['unitCode' => self::BIRIM_KODU[$k['temel_birim']]]),
                $this->tutar('cbc:LineExtensionAmount', $matrah[$i], $cur));
            if ($isk > 0) {
                $this->ekle($satir, $this->ekle($this->el('cac:AllowanceCharge'), $this->el('cbc:ChargeIndicator', 'false'), $this->el('cbc:AllowanceChargeReason', 'İskonto'),
                    $this->tutar('cbc:Amount', $isk, $cur), $this->tutar('cbc:BaseAmount', $brut, $cur)));
            }
            $oran = (int)$k['kdv_orani'];
            $this->ekle($satir, $this->ekle($this->el('cac:TaxTotal'), $this->tutar('cbc:TaxAmount', $satirKdv[$i], $cur),
                $this->ekle($this->el('cac:TaxSubtotal'), $this->tutar('cbc:TaxableAmount', $matrah[$i], $cur), $this->tutar('cbc:TaxAmount', $satirKdv[$i], $cur), $this->el('cbc:Percent', (string)$oran),
                    $this->ekle($this->el('cac:TaxCategory'), $this->ekle($this->el('cac:TaxScheme'), $this->el('cbc:Name', 'KDV'), $this->el('cbc:TaxTypeCode', '0015'))))));
            $this->ekle($satir, $this->ekle($this->el('cac:Item'), $this->el('cbc:Name', $k['ad']), $this->ekle($this->el('cac:SellersItemIdentification'), $this->el('cbc:ID', $k['stok_kodu']))));
            $this->ekle($satir, $this->ekle($this->el('cac:Price'), $this->el('cbc:PriceAmount', self::fiyatMetni((string)$k['fiyat']), ['currencyID' => $cur])));
            $this->ekle($root, $satir);
        }
        return $this->d->saveXML();
    }

    private static function fiyatMetni(string $dec): string
    {
        [$t, $o] = explode('.', $dec . '.'); $o = rtrim($o, '0');
        return $t . '.' . str_pad($o, 2, '0');
    }

    /** 1234,50 → "BİNİKİYÜZOTUZDÖRT TL ELLİ KR" (yazıyla tutar notu) */
    public static function yaziyla(int $kurus): string
    {
        $buyuk = fn(string $t) => mb_strtoupper(strtr($t, ['i' => 'İ', 'ı' => 'I']), 'UTF-8');
        $s = $buyuk(self::sayiYazi(intdiv($kurus, 100))) . ' TL';
        if ($kurus % 100 > 0) $s .= ' ' . $buyuk(self::sayiYazi($kurus % 100)) . ' KR';
        return $s;
    }

    private static function sayiYazi(int $n): string
    {
        if ($n === 0) return 'sıfır';
        $bir = ['', 'bir', 'iki', 'üç', 'dört', 'beş', 'altı', 'yedi', 'sekiz', 'dokuz']; $on = ['', 'on', 'yirmi', 'otuz', 'kırk', 'elli', 'altmış', 'yetmiş', 'seksen', 'doksan'];
        $grup = ['', 'bin', 'milyon', 'milyar']; $out = '';
        $g = 0;
        while ($n > 0) {
            $p = $n % 1000; $n = intdiv($n, 1000);
            if ($p > 0) {
                $y = intdiv($p, 100); $o = intdiv($p % 100, 10); $b = $p % 10;
                $t = ($y > 1 ? $bir[$y] : '') . ($y > 0 ? 'yüz' : '') . $on[$o] . $bir[$b];
                if ($g === 1 && $p === 1) $t = '';
                $out = $t . $grup[$g] . $out;
            }
            $g++;
        }
        return $out;
    }
}
