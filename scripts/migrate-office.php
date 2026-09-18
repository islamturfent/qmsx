<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/config/database.php';
$sql = file_get_contents(dirname(__DIR__) . '/migrations/20260917-office.sql');
foreach (explode(';', $sql) as $statement) if (trim($statement) !== '') $pdo->exec($statement);
echo "Office tables ready. Existing documents were not modified.\n";
