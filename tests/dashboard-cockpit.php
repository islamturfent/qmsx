<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
session_start();
require 'config/database.php';
require 'includes/dashboard-functions.php';
$checks = 0;
function dccCheck(bool $ok, string $name): void { global $checks; if (!$ok) throw new RuntimeException('FAIL: ' . $name); $checks++; echo 'PASS: ' . $name . PHP_EOL; }

// Yonetim kokpitinin okudugu tablolarin semasi gecici tablolara kopyalanir.
$tables = ['approval_runs','audit_program_audits','audit_programs','audits','calibrations','companies','complaints','corrective_actions','document_copies','documents','equipment','external_audit_findings','external_audits','management_review_items','management_reviews','nonconformities','performance_targets','quality_costs','risks','satisfaction_responses','staff_competencies','staff_members','supplier_evaluations','suppliers','training_participants','trainings','users'];
foreach ($tables as $table) {
    $schema = $pdo->query("SHOW CREATE TABLE $table")->fetch(PDO::FETCH_NUM)[1];
    $schema = preg_replace('/(,\n)?\s*CONSTRAINT[^\n]+/', '', $schema);
    $pdo->exec(str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $schema));
}

$pdo->exec("INSERT INTO companies(id, company_name, active) VALUES(99601,'CP A',1)");
$pdo->exec("INSERT INTO users(id, username, password_hash, full_name, role, company_id, active) VALUES(99601,'c','x','C','super_admin',NULL,1)");
$cur = date('Y-m-d');
$year = (int) date('Y');
$pdo->exec("INSERT INTO quality_costs(id, company_id, cost_type, title, amount, incurred_on, active) VALUES (99601,99601,'prevention','a',1000.00,'$cur',1),(99602,99601,'appraisal','b',500.00,'$cur',1)");
$pdo->exec("INSERT INTO performance_targets(id, company_id, kpi_key, target_value, target_year, created_by) VALUES (99601,99601,'action_completion_rate',0,$year,99601)");

$_SESSION['qms_role'] = 'super_admin';
$_SESSION['qms_user_id'] = 99601;

// ---- Dashboard trendi artik COQ kategori toplamlarini tasir.
$trend = qmsDashboardTrend($pdo, 99601, 'super_admin', 12);
$key = date('Y-m');
dccCheck(isset($trend[$key]), 'Trend has a bucket for the current month');
dccCheck((float) $trend[$key]['prevention'] === 1000.0, 'Current month prevention total = 1000');
dccCheck((float) $trend[$key]['appraisal'] === 500.0, 'Current month appraisal total = 500');
dccCheck((float) $trend[$key]['cost_total'] === 1500.0, 'Current month COQ total = 1500');
dccCheck(isset($trend[$key]['audits']) && $trend[$key]['audits'] === 0, 'COQ addition does not disturb other trend series');

// ---- Kokpit KPI matrisi.
$matrix = qmsCockpitKpiMatrix($pdo, 99601, true, $year);
dccCheck(count($matrix) === 1 && $matrix[0]['name'] === 'CP A', 'Cockpit lists the company with a target');
$row = $matrix[0]['rows']['action_completion_rate'] ?? null;
dccCheck($row !== null && $row['target'] === 0.0, 'KPI row carries the target');
dccCheck($row['on_track'] === true, 'Low target is on track (actual >= 0)');
dccCheck($matrix[0]['on_track_count'] === 1 && $matrix[0]['all_on_track'] === true, 'On-track bookkeeping is correct');

// ---- Hedefsiz sirket matriste yer almaz.
$pdo->exec("INSERT INTO companies(id, company_name, active) VALUES(99602,'CP B',1)");
$matrix2 = qmsCockpitKpiMatrix($pdo, 99601, true, $year);
dccCheck(count($matrix2) === 1 && $matrix2[0]['id'] == 99601, 'Company without targets is excluded');

echo "\nCompleted $checks cockpit checks using temporary tables.\n";
