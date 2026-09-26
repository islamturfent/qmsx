<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
session_start();
require 'config/database.php';
require 'includes/report-export-data.php';
$checks = 0;
function rdcCheck(bool $ok, string $name): void { global $checks; if (!$ok) throw new RuntimeException('FAIL: ' . $name); $checks++; echo 'PASS: ' . $name . PHP_EOL; }

// Rapor ureticisinin dokundugu tablolarin semasi gecici tablolara kopyalanir;
// FK'lar cikarilir. Sadece gercek kayitlar (companies/users/quality_costs) eklenir.
$tables = ['approval_runs','audit_program_audits','audit_programs','audits','calibrations','companies','complaints','corrective_actions','document_copies','documents','equipment','external_audit_findings','external_audits','internal_survey_responses','internal_surveys','management_review_items','management_reviews','nonconformities','performance_targets','quality_costs','risks','satisfaction_responses','staff_competencies','staff_members','supplier_evaluations','suppliers','training_participants','trainings','users'];
foreach ($tables as $table) {
    $schema = $pdo->query("SHOW CREATE TABLE $table")->fetch(PDO::FETCH_NUM)[1];
    $schema = preg_replace('/(,\n)?\s*CONSTRAINT[^\n]+/', '', $schema);
    $pdo->exec(str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $schema));
}

$pdo->exec("INSERT INTO companies(id, company_name, active) VALUES(99101,'R A',1)");
$pdo->exec("INSERT INTO users(id, username, password_hash, full_name, role, company_id, active) VALUES(99101,'r-admin','x','RA','super_admin',NULL,1)");
$pdo->exec("INSERT INTO quality_costs(id, company_id, cost_type, title, amount, incurred_on, active) VALUES
    (99101,99101,'prevention','a',1000.00,'2026-05-15',1),
    (99102,99101,'appraisal','b',500.00,'2026-05-20',1),
    (99103,99101,'internal_failure','c',300.00,'2026-06-05',1),
    (99104,99101,'external_failure','d',200.00,'2026-06-10',1)");
$pdo->exec("INSERT INTO internal_surveys(id, company_id, title, description, published, active) VALUES(99101,99101,'İç Anket 2026','x',1,1)");
$pdo->exec("INSERT INTO internal_survey_responses(id, survey_id, question_id, user_id, rating, answer_text, submitted_at) VALUES(99101,99101,99101,99101,4,NULL,'2026-06-01 10:00:00')");

$_SESSION['qms_role'] = 'super_admin';
$_SESSION['qms_user_id'] = 99101;
$report = buildReportExportData($pdo, 99101, true, ['start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'company_id' => 99101]);

rdcCheck(isset($report['quality_cost_trend']) && count($report['quality_cost_trend']) === 12, 'Trend has 12 monthly entries for the year');
$may = null; $jun = null;
foreach ($report['quality_cost_trend'] as $row) {
    if ($row['label'] === '05/2026') $may = $row;
    if ($row['label'] === '06/2026') $jun = $row;
}
rdcCheck($may !== null && (float) $may['prevention'] === 1000.0, 'May prevention total = 1000');
rdcCheck($may !== null && (float) $may['appraisal'] === 500.0, 'May appraisal total = 500');
rdcCheck($may !== null && (float) $may['total'] === 1500.0, 'May total = 1500');
rdcCheck($jun !== null && (float) $jun['internal_failure'] === 300.0, 'Jun internal failure = 300');
rdcCheck($jun !== null && (float) $jun['external_failure'] === 200.0, 'Jun external failure = 200');
rdcCheck($jun !== null && (float) $jun['total'] === 500.0, 'Jun total = 500');
rdcCheck((float) $report['quality_cost_trend'][0]['total'] === 0.0, 'Empty month reports zero total');

// KPI'lar bozulmamali.
rdcCheck(round((float) $report['metrics']['quality_cost_total'], 2) === 2000.0, 'Total COQ metric = 2000');
rdcCheck(isset($report['internal_survey_list']) && count($report['internal_survey_list']) === 1, 'Internal survey list has one survey');
rdcCheck((int) $report['internal_survey_list'][0]['respondents'] === 1, 'Internal survey respondents = 1');
rdcCheck(round((float) $report['metrics']['internal_survey_avg'], 1) === 4.0, 'Internal survey avg = 4.0');

echo "\nCompleted $checks report-export checks using temporary tables.\n";
