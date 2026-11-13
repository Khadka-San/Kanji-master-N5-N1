<?php
// One-time JLPT N1-N5 kanji importer for the existing MAMP app.
// Upload this file and jlpt_kanji_import.csv to C:\\MAMP\\htdocs\\japanese-kanji-master\\
// Run http://localhost/japanese-kanji-master/import_jlpt_kanji.php once, then delete both files.
// This script inserts missing (kanji_character, jlpt_level) pairs and does not overwrite existing records.
set_time_limit(0);
$host = '127.0.0.1';
$db   = 'kanji_master';
$user = 'root';
$pass = 'root';
$csvFile = __DIR__ . DIRECTORY_SEPARATOR . 'jlpt_kanji_import.csv';
if (!file_exists($csvFile)) { die('CSV file not found beside this importer.'); }
try {
    $pdo = new PDO('mysql:host='.$host.';dbname='.$db.';charset=utf8mb4', $user, $pass, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
    $check = $pdo->prepare('SELECT id FROM kanji WHERE kanji_character = ? AND jlpt_level = ? LIMIT 1');
    $insert = $pdo->prepare('INSERT INTO kanji (kanji_character, jlpt_level, meaning, on_reading, kun_reading, stroke_count, stroke_data_path, attribution) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $handle = fopen($csvFile, 'r');
    if (!$handle) { die('Could not open CSV file.'); }
    $headers = fgetcsv($handle);
    $inserted = 0; $skipped = 0; $bad = 0;
    $pdo->beginTransaction();
    while (($row = fgetcsv($handle)) !== false) {
        if (count($row) < 8 || trim($row[0]) === '' || !preg_match('/^N[1-5]$/', trim($row[1]))) { $bad++; continue; }
        $kanji = trim($row[0]); $level = trim($row[1]);
        $check->execute(array($kanji, $level));
        if ($check->fetch(PDO::FETCH_ASSOC)) { $skipped++; continue; }
        $strokePath = trim($row[6]) === '' ? null : trim($row[6]);
        $insert->execute(array($kanji, $level, $row[2], $row[3], $row[4], (int)$row[5], $strokePath, $row[7]));
        $inserted++;
    }
    fclose($handle); $pdo->commit();
    echo '<h2>JLPT kanji import finished</h2>';
    echo '<p>Inserted: '.(int)$inserted.'</p><p>Already existed and skipped: '.(int)$skipped.'</p><p>Invalid rows skipped: '.(int)$bad.'</p>';
    echo '<p>For safety, delete import_jlpt_kanji.php and jlpt_kanji_import.csv from your project folder now.</p>';
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
    http_response_code(500);
    echo 'Import failed: '.htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
}
?>