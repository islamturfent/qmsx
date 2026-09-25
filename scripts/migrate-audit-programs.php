<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/config/database.php';
$sql = file_get_contents(dirname(__DIR__) . '/migrations/20260930-audit-programs.sql');
// Yorum satirlari once temizlenir: aciklamadaki noktali virgul ifadeleri bolerdi.
$sql = preg_replace('/^\s*--.*$/m', '', $sql);
foreach (explode(';', $sql) as $statement) if (trim($statement) !== '') $pdo->exec($statement);
echo "Audit program tables ready. Existing records were not modified.\n";
