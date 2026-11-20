<?php
declare(strict_types=1);

/** Aktarım raporu: her düzeltme, atlanan ve karar bekleyen satır gerekçesiyle burada toplanır. */
final class Report
{
    /** durum değerleri */
    public const DUZELTILDI = 'duzeltildi';        // aktarıldı, ama değiştirilerek
    public const AKTARILMADI = 'aktarilmadi';      // satır/kayıt sisteme alınmadı
    public const KARAR_BEKLIYOR = 'karar_bekliyor';// müşteriden cevap gerekli
    public const UYARI = 'uyari';                  // aktarıldı, dikkat edilmeli
    public const MEVCUT = 'zaten_mevcut';          // ikinci çalıştırmada atlandı

    /** @var list<array<string,mixed>> */
    private array $rows = [];
    /** @var array<string,array<string,int>> */
    private array $sayac = [];

    public function add(string $sayfa, int $satir, ?string $anahtar, string $durum, string $oncelik, ?string $alan, ?string $eski, ?string $yeni, string $gerekce): void
    {
        $this->rows[] = compact('sayfa', 'satir', 'anahtar', 'durum', 'oncelik', 'alan', 'eski', 'yeni', 'gerekce');
    }

    public function count(string $sayfa, string $anahtar, int $n = 1): void
    {
        $this->sayac[$sayfa][$anahtar] = ($this->sayac[$sayfa][$anahtar] ?? 0) + $n;
    }

    public function counter(string $sayfa, string $anahtar): int
    {
        return $this->sayac[$sayfa][$anahtar] ?? 0;
    }

    public function rows(): array { return $this->rows; }

    /** Belirli sayfa için, verilen durumlardaki farklı satır sayısı. */
    public function distinctRows(string $sayfa, array $durumlar, ?array $oncelikler = null): int
    {
        $set = [];
        foreach ($this->rows as $r) {
            if ($r['sayfa'] !== $sayfa || !in_array($r['durum'], $durumlar, true)) continue;
            if ($oncelikler !== null && !in_array($r['oncelik'], $oncelikler, true)) continue;
            $set[$r['satir']] = true;
        }
        return count($set);
    }

    public function writeCsv(string $path): void
    {
        $order = ['yuksek' => 0, 'orta' => 1, 'dusuk' => 2];
        $rows = $this->rows;
        usort($rows, fn($a, $b) => [$order[$a['oncelik']] ?? 3, $a['sayfa'], $a['satir']] <=> [$order[$b['oncelik']] ?? 3, $b['sayfa'], $b['satir']]);
        $fh = fopen($path, 'w');
        fwrite($fh, "\xEF\xBB\xBF"); // Excel UTF-8
        fputcsv($fh, ['Sayfa', 'Excel satırı', 'Kayıt (kod/ad)', 'Durum', 'Öncelik', 'Alan', 'Eski değer', 'Yeni değer', 'Gerekçe'], ';');
        foreach ($rows as $r) {
            fputcsv($fh, [$r['sayfa'], $r['satir'], $r['anahtar'], $r['durum'], $r['oncelik'], $r['alan'], $r['eski'], $r['yeni'], $r['gerekce']], ';');
        }
        fclose($fh);
    }

    public function persist(PDO $db, int $calismaId): void
    {
        $st = $db->prepare('INSERT INTO aktarim_rapor (calisma_id,sayfa,satir,anahtar,durum,oncelik,alan,eski_deger,yeni_deger,gerekce) VALUES (?,?,?,?,?,?,?,?,?,?)');
        foreach ($this->rows as $r) {
            $st->execute([$calismaId, $r['sayfa'], $r['satir'], $r['anahtar'], $r['durum'], $r['oncelik'], $r['alan'],
                $r['eski'] === null ? null : mb_substr((string)$r['eski'], 0, 255), $r['yeni'] === null ? null : mb_substr((string)$r['yeni'], 0, 255), mb_substr($r['gerekce'], 0, 500)]);
        }
    }

    public function writeSummary(string $path, array $summary): void
    {
        $md = "# Veri Aktarım Raporu\n\nÇalışma zamanı: " . date('Y-m-d H:i:s') . "\n\n";
        foreach ($summary as $sayfa => $s) {
            $md .= "## $sayfa\n\n";
            foreach ($s as $k => $v) $md .= "- $k: **$v**\n";
            $md .= "\n";
        }
        $byPri = [];
        foreach ($this->rows as $r) {
            $byPri[$r['oncelik']][$r['durum']] = ($byPri[$r['oncelik']][$r['durum']] ?? 0) + 1;
        }
        $md .= "## Rapor satırları (öncelik × durum)\n\n| Öncelik | Durum | Adet |\n|---|---|---|\n";
        foreach (['yuksek', 'orta', 'dusuk'] as $p) {
            foreach ($byPri[$p] ?? [] as $d => $n) $md .= "| $p | $d | $n |\n";
        }
        $md .= "\nAyrıntı: `aktarim_raporu.csv` (öncelik sırasıyla, her satır gerekçeli).\n";
        file_put_contents($path, $md);
    }
}
