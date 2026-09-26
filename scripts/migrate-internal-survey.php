<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/config/database.php';
$tables = ['internal_surveys','internal_survey_questions','internal_survey_responses'];
$all = 0;
foreach ($tables as $t) {
    $has = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '$t'")->fetchColumn();
    if ($has > 0) { echo "$t already present; nothing to do.\n"; $all++; continue; }
    $sql = file_get_contents(dirname(__DIR__) . '/migrations/20261001-internal-survey.sql');
    preg_match('/CREATE TABLE ' . preg_quote($t, '/') . '[\s\S]*?;\s*(?=CREATE TABLE|$)/', $sql, $m);
    if (!$m) { echo "Could not locate $t statement.\n"; exit(1); }
    foreach (explode(';', $m[0]) as $stmt) if (trim($stmt) !== '') $pdo->exec($stmt);
    echo "$t table ready.\n";
}
if ($all === count($tables)) exit(0);
echo "internal survey tables ready.\n";
