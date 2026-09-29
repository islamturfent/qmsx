<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/config/database.php';
$sql = file_get_contents(dirname(__DIR__) . '/migrations/20261012-doc-copy-confirmation.sql');
$sql = preg_replace('/^\s*--.*$/m', '', $sql);
foreach (explode(';', $sql) as $stmt) if (trim($stmt) !== '') $pdo->exec($stmt);
echo "document_copies confirmation columns ready.\n";