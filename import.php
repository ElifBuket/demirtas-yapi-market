<?php
declare(strict_types=1);
/**
 * Kullanım:
 *   php import.php <excel.xlsx> [--kur] [--rapor-klasoru=rapor]
 * Ortam değişkenleri: DB_HOST, DB_NAME, DB_USER, DB_PASS (varsayılan: 127.0.0.1 / demirtas / root / boş)
 *   --kur   şemayı (schema.sql) önce çalıştırır
 */
require_once __DIR__ . '/src/XlsxReader.php';
require_once __DIR__ . '/src/Importer.php';

$args = array_slice($argv, 1);
$file = null; $kur = false; $reportDir = __DIR__ . '/rapor';
foreach ($args as $a) {
    if ($a === '--kur') $kur = true;
    elseif (str_starts_with($a, '--rapor-klasoru=')) $reportDir = substr($a, 16);
    else $file = $a;
}
if ($file === null || !is_file($file)) {
    fwrite(STDERR, "Kullanım: php import.php <excel.xlsx> [--kur] [--rapor-klasoru=rapor]\n");
    exit(2);
}

$dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', getenv('DB_HOST') ?: '127.0.0.1', getenv('DB_NAME') ?: 'demirtas');
$db = new PDO($dsn, getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

if ($kur) {
    $db->exec(file_get_contents(__DIR__ . '/schema.sql'));
    echo "Şema kuruldu.\n";
}

@mkdir($reportDir, 0775, true);
$book = XlsxReader::read($file);
$report = new Report();

$db->prepare('INSERT INTO aktarim_calismalari (baslangic, dosya) VALUES (NOW(), ?)')->execute([basename($file)]);
$calisma = (int)$db->lastInsertId();

$summary = (new Importer($db, $report))->run($book);

$report->persist($db, $calisma);
$db->prepare('UPDATE aktarim_calismalari SET bitis = NOW(), ozet = ? WHERE id = ?')->execute([json_encode($summary, JSON_UNESCAPED_UNICODE), $calisma]);
$report->writeCsv($reportDir . '/aktarim_raporu.csv');
$report->writeSummary($reportDir . '/aktarim_ozeti.md', $summary);

echo "\n=== AKTARIM ÖZETİ ===\n";
foreach ($summary as $sayfa => $s) {
    echo "\n[$sayfa]\n";
    foreach ($s as $k => $v) echo sprintf("  %-40s %s\n", $k, $v);
}
echo "\nRapor: $reportDir/aktarim_raporu.csv ve aktarim_ozeti.md\n";
