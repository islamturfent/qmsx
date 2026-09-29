<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/config/database.php';
$sql = file_get_contents(dirname(__DIR__) . '/migrations/20261011-nc-root-cause.sql');
$sql = preg_replace('/^\s*--.*$/m', '', $sql);
foreach (explode(';', $sql) as $stmt) if (trim($stmt) !== '') $pdo->exec($stmt);
echo "nc_root_cause table ready.\n";
