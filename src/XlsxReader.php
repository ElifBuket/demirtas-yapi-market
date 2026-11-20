<?php
declare(strict_types=1);

require_once __DIR__ . '/Num.php';

/**
 * Bağımlılıksız .xlsx okuyucu (ZipArchive + SimpleXML).
 * Hücre değerleri: metin => string, sayı => Num, tarih => DateTimeImmutable, boş => null.
 * Sayıları float'a çevirmeyiz (yuvarlama hatası olmasın diye ham metin olarak taşıyoruz).
 */
final class XlsxReader
{
    /** @return array<string, list<array{row:int, data:array<string,mixed>}>> sayfa adı => satırlar (başlık satırı anahtar olur) */
    public static function read(string $path): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException("Excel dosyası açılamadı: $path");
        }
        $shared = self::sharedStrings($zip);
        $dateXf = self::dateStyles($zip);

        $wb = simplexml_load_string($zip->getFromName('xl/workbook.xml'));
        $rels = simplexml_load_string($zip->getFromName('xl/_rels/workbook.xml.rels'));
        $targets = [];
        foreach ($rels->Relationship as $rel) {
            $t = (string)$rel['Target'];
            $targets[(string)$rel['Id']] = str_starts_with($t, '/') ? ltrim($t, '/') : 'xl/' . $t;
        }

        $out = [];
        foreach ($wb->sheets->sheet as $sheet) {
            $rid = (string)$sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            $xml = simplexml_load_string($zip->getFromName($targets[$rid]));
            $rows = [];
            foreach ($xml->sheetData->row as $row) {
                $cells = [];
                foreach ($row->c as $c) {
                    $cells[self::colIndex((string)$c['r'])] = self::cellValue($c, $shared, $dateXf);
                }
                $rows[(int)$row['r']] = $cells;
            }
            $out[(string)$sheet['name']] = self::withHeaders($rows);
        }
        $zip->close();
        return $out;
    }

    private static function withHeaders(array $rows): array
    {
        if (!$rows) return [];
        ksort($rows);
        $firstKey = array_key_first($rows);
        $headers = [];
        foreach ($rows[$firstKey] as $i => $v) {
            $headers[$i] = trim((string)$v);
        }
        $result = [];
        foreach ($rows as $rowNo => $cells) {
            if ($rowNo === $firstKey) continue;
            $data = [];
            foreach ($headers as $i => $h) {
                $data[$h] = $cells[$i] ?? null;
            }
            // tamamen boş satırları atla
            if (count(array_filter($data, fn($v) => $v !== null && $v !== '')) === 0) continue;
            $result[] = ['row' => $rowNo, 'data' => $data];
        }
        return $result;
    }

    private static function cellValue(SimpleXMLElement $c, array $shared, array $dateXf): mixed
    {
        $t = (string)$c['t'];
        if ($t === 'inlineStr') {
            $txt = self::richText($c->is);
            return $txt === '' ? null : $txt;
        }
        if (!isset($c->v)) return null;
        $v = (string)$c->v;
        if ($v === '') return null;
        return match ($t) {
            's' => $shared[(int)$v] ?? null,
            'str', 'e' => $v,
            'b' => $v === '1' ? 'DOĞRU' : 'YANLIŞ',
            default => isset($dateXf[(int)$c['s']])
                ? (new DateTimeImmutable('1899-12-30'))->modify('+' . (int)floor((float)$v) . ' days')
                : new Num($v),
        };
    }

    /** <t> veya <r><t>...</t></r> parçalarını birleştirir (XPath yok: varsayılan ad alanı sorun çıkarır). */
    private static function richText(?SimpleXMLElement $node): string
    {
        if ($node === null) return '';
        $txt = isset($node->t) ? (string)$node->t : '';
        if (isset($node->r)) {
            foreach ($node->r as $run) {
                if (isset($run->t)) $txt .= (string)$run->t;
            }
        }
        return $txt;
    }

    private static function sharedStrings(ZipArchive $zip): array
    {
        $raw = $zip->getFromName('xl/sharedStrings.xml');
        if ($raw === false) return [];
        $xml = simplexml_load_string($raw);
        $out = [];
        foreach ($xml->si as $si) {
            $out[] = self::richText($si);
        }
        return $out;
    }

    /** @return array<int,true> tarih formatlı stil indeksleri */
    private static function dateStyles(ZipArchive $zip): array
    {
        $raw = $zip->getFromName('xl/styles.xml');
        if ($raw === false) return [];
        $xml = simplexml_load_string($raw);
        $custom = [];
        if (isset($xml->numFmts)) {
            foreach ($xml->numFmts->numFmt as $nf) $custom[(int)$nf['numFmtId']] = (string)$nf['formatCode'];
        }
        $isDate = function (int $id) use ($custom): bool {
            if (($id >= 14 && $id <= 22) || ($id >= 45 && $id <= 47)) return true;
            if (!isset($custom[$id])) return false;
            $code = preg_replace('/"[^"]*"|\[[^\]]*\]|\\\\./', '', $custom[$id]);
            return (bool)preg_match('/[dmyhs]/i', $code);
        };
        $out = [];
        $i = 0;
        foreach ($xml->cellXfs->xf as $xf) {
            if ($isDate((int)$xf['numFmtId'])) $out[$i] = true;
            $i++;
        }
        return $out;
    }

    private static function colIndex(string $ref): int
    {
        preg_match('/^([A-Z]+)/', $ref, $m);
        $n = 0;
        foreach (str_split($m[1]) as $ch) $n = $n * 26 + (ord($ch) - 64);
        return $n - 1;
    }
}
