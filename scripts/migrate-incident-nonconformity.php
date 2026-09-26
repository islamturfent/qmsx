<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/config/database.php';
$has = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'nonconformities' AND column_name = 'incident_id'")->fetchColumn();
if ($has > 0) { echo "nonconformities.incident_id already present; nothing to do.\n"; exit(0); }
$sql = file_get_contents(dirname(__DIR__) . '/migrations/20261007-incident-nonconformity.sql');
$sql = preg_replace('/^\s*--.*$/m', '', $sql);
foreach (explode(';', $sql) as $stmt) if (trim($stmt) !== '') $pdo->exec($stmt);
echo "nonconformities.incident_id column ready.\n";
