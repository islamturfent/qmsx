<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/config/database.php';
$has = (int) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_name = 'login_attempts'"
)->fetchColumn();
if ((int) $has > 0) { echo "login_attempts already present; nothing to do.\n"; exit(0); }
$sql = file_get_contents(dirname(__DIR__) . '/migrations/20261009-login-attempts.sql');
$sql = preg_replace('/^\s*--.*$/m', '', $sql);
foreach (explode(';', $sql) as $stmt) if (trim($stmt) !== '') $pdo->exec($stmt);
echo "login_attempts table ready.\n";
