<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
session_start();
require 'config/database.php';
require 'includes/performance-functions.php';
$checks = 0;
function perfCheck(bool $ok, string $name): void { global $checks; if (!$ok) throw new RuntimeException('FAIL: ' . $name); $checks++; echo 'PASS: ' . $name . PHP_EOL; }
function perfCount(PDO $pdo): int { return (int) $pdo->query('SELECT COUNT(*) FROM performance_targets')->fetchColumn(); }

// Semasi gecici tabloya: hedef kaydi/kapsami gercek veriye dokunmadan sinanir.
foreach (['companies', 'performance_targets'] as $table) {
    $schema = $pdo->query("SHOW CREATE TABLE $table")->fetch(PDO::FETCH_NUM)[1];
    $schema = preg_replace('/(,\n)?\s*CONSTRAINT[^\n]+/', '', $schema);
    $pdo->exec(str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $schema));
}
$pdo->exec("INSERT INTO companies(id, company_name, active) VALUES(960001,'Perf Tenant A',1),(960002,'Perf Tenant B',1)");

// ---- KPI sozlugu.
$kpis = qmsPerformanceKpis();
$kpiKeys = qmsPerformanceKpiKeys();
perfCheck(count($kpis) === 8, 'Eight KPIs are targetable');
perfCheck(count($kpis) === count(array_unique($kpiKeys)), 'KPI keys are unique');
perfCheck(count(array_unique(array_column($kpis, 'label_key'))) === count($kpis), 'Every KPI has a distinct i18n label key');
perfCheck(array_keys(qmsPerformanceKpiLabels()) === $kpiKeys, 'Export labels cover every KPI key');
$hasIconAndDirection = true;
foreach ($kpis as $definition) {
    if (!array_key_exists('icon', $definition) || !array_key_exists('higher_better', $definition)) {
        $hasIconAndDirection = false;
    }
}
perfCheck($hasIconAndDirection, 'Every KPI carries an icon and a direction');

// ---- Hedef degerlendirme (hedefte mi).
perfCheck(qmsPerformanceOnTrack(80.0, 90.0, true) === true, 'Higher-is-better meets the target');
perfCheck(qmsPerformanceOnTrack(80.0, 70.0, true) === false, 'Higher-is-better misses the target');
perfCheck(qmsPerformanceOnTrack(5.0, 3.0, false) === true, 'Lower-is-better meets the target');
perfCheck(qmsPerformanceOnTrack(5.0, 8.0, false) === false, 'Lower-is-better misses the target');
perfCheck(qmsPerformanceOnTrack(80.0, 80.0, true) === true, 'A tie counts as on-track');
perfCheck(qmsPerformanceOnTrack(null, 90.0, true) === null, 'No target gives no verdict');
perfCheck(qmsPerformanceOnTrack(80.0, null, true) === null, 'No actual value gives no verdict');

// ---- Hedef kaydi.
perfCheck(qmsPerformanceTargets($pdo, 960001, 2026) === [], 'New company has no targets');
qmsPerformanceTargetSave($pdo, 960001, 'action_completion_rate', 2026, 90.0, 'Yillik hedef', 1);
perfCheck(perfCount($pdo) === 1, 'Saving a target stores one row');
$targets = qmsPerformanceTargets($pdo, 960001, 2026);
perfCheck(isset($targets['action_completion_rate']) && $targets['action_completion_rate']['target_value'] === 90.0, 'Target is readable by key');

$rejected = false;
try { qmsPerformanceTargetSave($pdo, 960001, 'bilinmeyen_kpi', 2026, 1.0, null, 1); } catch (InvalidArgumentException $e) { $rejected = true; }
perfCheck($rejected, 'Unknown KPI key is rejected');

$rejected = false;
try { qmsPerformanceTargetSave($pdo, 960001, 'audit_count', 2026, -5.0, null, 1); } catch (InvalidArgumentException $e) { $rejected = true; }
perfCheck($rejected, 'Negative target value is rejected');

// Upsert: ayni sirket + KPI + yil, yeni satir acmadan gunceller.
qmsPerformanceTargetSave($pdo, 960001, 'action_completion_rate', 2026, 95.0, 'Guncellendi', 1);
perfCheck(perfCount($pdo) === 1, 'Re-saving the same target updates without duplicating');
perfCheck(qmsPerformanceTargets($pdo, 960001, 2026)['action_completion_rate']['target_value'] === 95.0, 'Updated target value is stored');

// Kapsam: hedefler sirkete baglidir, siztiri olmaz.
qmsPerformanceTargetSave($pdo, 960002, 'audit_count', 2026, 12.0, null, 1);
perfCheck(qmsPerformanceTargets($pdo, 960001, 2026) === ['action_completion_rate' => ['id' => 1, 'target_value' => 95.0, 'note' => 'Guncellendi']], 'Targets are scoped to their company');
perfCheck(perfCount($pdo) === 2, 'Both companies keep their own targets');

// Yil bazli izolasyon.
qmsPerformanceTargetSave($pdo, 960001, 'action_completion_rate', 2027, 90.0, null, 1);
perfCheck(count(qmsPerformanceTargets($pdo, 960001, 2026)) === 1 && count(qmsPerformanceTargets($pdo, 960001, 2027)) === 1, 'Targets are isolated by year');

perfCheck((int) $pdo->query('SELECT COUNT(*) FROM performance_targets')->fetchColumn() === 3, 'Temporary table holds only the fixtures');

session_destroy();
echo "Completed $checks performance checks using temporary tables." . PHP_EOL;
