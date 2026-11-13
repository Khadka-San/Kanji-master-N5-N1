
<?php
require_once __DIR__ . '/config/database.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    $pdo->query('SELECT 1');
    echo "SUCCESS: PHP connected to MySQL!";
    echo "\nDatabase: kanji_master";
} catch (Throwable $e) {
    http_response_code(500);
    echo "Connection test failed.";
    error_log($e->getMessage());
}
