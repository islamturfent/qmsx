<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
session_start();
require 'config/database.php';
require 'includes/supplier-functions.php';
$checks = 0;
function supplierCheck(bool $ok, string $name): void { global $checks; if (!$ok) throw new RuntimeException('FAIL: ' . $name); $checks++; echo 'PASS: ' . $name . PHP_EOL; }
function supplierCount(PDO $pdo): int { return (int) $pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn(); }

// Gercek tablolarin semasi gecici tablolara kopyalanir: kiraci izolasyonu ve
// bildirim kurallari gercek veriye dokunmadan sinanir.
foreach (['companies', 'users', 'company_admin_assignments', 'suppliers', 'supplier_evaluations', 'notifications'] as $table) {
    $schema = $pdo->query("SHOW CREATE TABLE $table")->fetch(PDO::FETCH_NUM)[1];
    $schema = preg_replace('/(,\n)?\s*CONSTRAINT[^\n]+/', '', $schema);
    $pdo->exec(str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $schema));
}

$pdo->exec("INSERT INTO companies(id, company_name, active) VALUES(940001,'Supplier Tenant A',1),(940002,'Supplier Tenant B',1)");
$pdo->exec("INSERT INTO users(id, username, password_hash, full_name, role, company_id, active) VALUES
    (940001,'supplier-admin','x','Supplier Admin','system_admin',NULL,1),
    (940002,'supplier-root','x','Supplier Root','super_admin',NULL,1),
    (940003,'supplier-company','x','Supplier Company User','company_user',940001,1),
    (940004,'supplier-other','x','Supplier Other Admin','system_admin',NULL,1),
    (940005,'supplier-passive','x','Supplier Passive Admin','system_admin',NULL,0)");
$pdo->exec("INSERT INTO company_admin_assignments(company_id, admin_user_id, active) VALUES(940001, 940001, 1),(940002, 940004, 1),(940001, 940005, 1)");
$pdo->exec("INSERT INTO suppliers(id, company_id, name, supplier_code, risk_class, status, active) VALUES
    (940001, 940001, 'Tenant A supplier', 'TED-001', 'high', 'approved', 1),
    (940002, 940001, 'Inactive supplier', 'TED-002', 'low', 'candidate', 0),
    (940003, 940002, 'Tenant B supplier', 'TED-003', 'medium', 'candidate', 1)");
$pdo->exec("INSERT INTO supplier_evaluations(id, supplier_id, evaluated_on, quality_score, delivery_score, service_score, result) VALUES
    (940001, 940001, '2026-02-10', 80, 90, 70, 'acceptable'),
    (940002, 940001, '2026-05-20', 60, 50, NULL, 'unacceptable'),
    (940003, 940003, '2026-03-01', 70, 70, 70, 'acceptable')");

// ---- Alan dogrulama yardimcilari.
supplierCheck(qmsSupplierScore('85,5') === 85.5 && qmsSupplierScore('') === null, 'Score accepts comma decimals and empty values');
supplierCheck(qmsSupplierScore('101') === null && qmsSupplierScore('-5') === null, 'Score is bounded to 0-100');
supplierCheck(qmsSupplierDate('2026-05-20') === '2026-05-20' && qmsSupplierDate('20.05.2026') === null, 'Dates require ISO format');
supplierCheck(qmsSupplierText('  Fazla bosluk  ', 100) === 'Fazla bosluk' && mb_strlen(qmsSupplierText(str_repeat('a', 300), 200)) === 200, 'Text is trimmed and truncated');

// ---- Degerlendirme hesaplari.
supplierCheck(qmsSupplierEvaluationTotal(['quality_score' => 80, 'delivery_score' => 90, 'service_score' => 70]) === 80.0, 'Evaluation total averages the three scores');
supplierCheck(qmsSupplierEvaluationTotal(['quality_score' => 80, 'delivery_score' => null, 'service_score' => null]) === 80.0, 'Evaluation total ignores missing scores');
supplierCheck(qmsSupplierEvaluationTotal(['quality_score' => null, 'delivery_score' => null, 'service_score' => null]) === null, 'Evaluation without scores has no total');

$evaluations = qmsSupplierEvaluations($pdo, 940001);
supplierCheck(count($evaluations) === 2, 'Evaluations are limited to one supplier');
supplierCheck((int) $evaluations[0]['id'] === 940002, 'Evaluations are ordered newest first');
$summary = qmsSupplierEvaluationSummary($evaluations);
supplierCheck($summary['count'] === 2 && $summary['average'] === 67.5, 'Summary averages every evaluation total');
supplierCheck($summary['latest'] === 55.0 && $summary['latest_date'] === '2026-05-20', 'Summary reports the latest evaluation');
supplierCheck($summary['latest_result'] === 'unacceptable', 'Summary reports the latest decision');
supplierCheck(qmsSupplierEvaluationSummary([]) === ['count' => 0, 'average' => null, 'latest' => null, 'latest_date' => null, 'latest_result' => null], 'Empty evaluation summary is safe');
supplierCheck(array_key_exists('evaluator_full_name', $evaluations[0]), 'Evaluation list joins the evaluating user');

// ---- Kapsam: id degistirilerek baska kiraciya gecilemez.
supplierCheck((int) qmsSupplierFind($pdo, 940001, 940001, 'system_admin')['company_id'] === 940001, 'Assigned admin reads own tenant supplier');
supplierCheck(qmsSupplierFind($pdo, 940003, 940001, 'system_admin') === [], 'Assigned admin cannot read another tenant supplier');
supplierCheck((int) qmsSupplierFind($pdo, 940001, 940003, 'company_user')['id'] === 940001, 'Company user reads own company supplier');
supplierCheck(qmsSupplierFind($pdo, 940003, 940003, 'company_user') === [], 'Company user cannot read another company supplier');
supplierCheck((int) qmsSupplierFind($pdo, 940003, 940002, 'super_admin')['id'] === 940003, 'Super admin reads any supplier');
supplierCheck(qmsSupplierFind($pdo, 940002, 940002, 'super_admin') === [], 'Inactive supplier is not readable');
supplierCheck(count(qmsSupplierList($pdo, 940001, 'system_admin')) === 1, 'Supplier list is limited to the visible tenant');
supplierCheck(count(qmsSupplierList($pdo, 940002, 'super_admin')) === 2, 'Super admin list sees every active supplier');
$pdo->exec('UPDATE company_admin_assignments SET active = 0');
supplierCheck(qmsSupplierList($pdo, 940001, 'system_admin') === [], 'Revoked assignment removes access');
$pdo->exec('UPDATE company_admin_assignments SET active = 1 WHERE company_id = 940001');

// ---- Degerlendirme kaydi kapsami.
supplierCheck((int) qmsSupplierEvaluationFind($pdo, 940001, 940001, 'system_admin')['supplier_id'] === 940001, 'Assigned admin reads own evaluation');
supplierCheck(qmsSupplierEvaluationFind($pdo, 940003, 940001, 'system_admin') === [], 'Evaluation of another tenant is not readable');
supplierCheck(qmsSupplierEvaluationFind($pdo, 940001, 940004, 'system_admin') === [], 'Evaluation is hidden from an admin of another tenant');

// ---- Form dogrulamasi.
supplierCheck(qmsSupplierEvaluationError(['evaluated_on' => '2026-01-01', 'quality_score' => '80', 'delivery_score' => '', 'service_score' => '', 'result' => 'acceptable']) === '', 'Valid evaluation form passes');
supplierCheck(qmsSupplierEvaluationError(['evaluated_on' => '', 'result' => 'acceptable']) !== '', 'Missing date is rejected');
supplierCheck(qmsSupplierEvaluationError(['evaluated_on' => '2026-01-01', 'result' => 'unknown']) !== '', 'Unknown decision is rejected');
supplierCheck(qmsSupplierEvaluationError(['evaluated_on' => '2026-01-01', 'quality_score' => '150', 'result' => 'acceptable']) !== '', 'Out-of-range score is rejected');

// ---- Bildirim kurallari: durum degisimi.
$statusContext = [
    'company_id' => 940001,
    'name' => 'Tenant A supplier',
    'link' => 'supplier-detail.php?id=940001',
    'previous_status' => 'candidate',
    'new_status' => 'approved',
    'actor_user_id' => 940003
];
supplierCheck(qmsSupplierNotifyStatusChange($pdo, $statusContext) === 1, 'Approval notifies the assigned admin once');
supplierCheck((int) $pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id = 940001 AND notification_type = 'supplier_approved'")->fetchColumn() === 1, 'Approval notification type is stored');
supplierCheck((int) $pdo->query('SELECT user_id FROM notifications ORDER BY id LIMIT 1')->fetchColumn() === 940001, 'Notification goes to the assigned admin');

$pdo->exec('DELETE FROM notifications');
qmsSupplierNotifyStatusChange($pdo, array_merge($statusContext, ['previous_status' => 'approved']));
supplierCheck(supplierCount($pdo) === 0, 'Re-saving the same status sends no notification');
qmsSupplierNotifyStatusChange($pdo, array_merge($statusContext, ['new_status' => 'candidate']));
supplierCheck(supplierCount($pdo) === 0, 'Returning to candidate sends no notification');

$pdo->exec('DELETE FROM notifications');
qmsSupplierNotifyStatusChange($pdo, array_merge($statusContext, ['new_status' => 'suspended']));
supplierCheck((int) $pdo->query("SELECT COUNT(*) FROM notifications WHERE notification_type = 'supplier_suspended'")->fetchColumn() === 1, 'Suspension notifies the assigned admin');

$pdo->exec('DELETE FROM notifications');
qmsSupplierNotifyStatusChange($pdo, array_merge($statusContext, ['actor_user_id' => 940001]));
supplierCheck(supplierCount($pdo) === 0, 'The acting admin is not notified by their own decision');
qmsSupplierNotifyStatusChange($pdo, array_merge($statusContext, ['actor_user_id' => 940003, 'company_id' => 940002]));
supplierCheck((int) $pdo->query('SELECT COUNT(*) FROM notifications WHERE user_id = 940005')->fetchColumn() === 0, 'Passive admin is not notified');
supplierCheck((int) $pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id IN (SELECT id FROM users WHERE role = 'super_admin')")->fetchColumn() === 0, 'Super admin is not notified about tenant records');

// ---- Bildirim kurallari: degerlendirme karari.
$pdo->exec('DELETE FROM notifications');
$evaluationContext = [
    'company_id' => 940001,
    'name' => 'Tenant A supplier',
    'link' => 'supplier-detail.php?id=940001',
    'result' => 'acceptable',
    'actor_user_id' => 940003
];
supplierCheck(qmsSupplierNotifyEvaluation($pdo, $evaluationContext) === 0 && supplierCount($pdo) === 0, 'Acceptable decision stays silent');
supplierCheck(qmsSupplierNotifyEvaluation($pdo, array_merge($evaluationContext, ['result' => 'unacceptable'])) === 1, 'Unacceptable decision notifies the assigned admin');
supplierCheck((int) $pdo->query("SELECT COUNT(*) FROM notifications WHERE notification_type = 'supplier_evaluation_unacceptable'")->fetchColumn() === 1, 'Evaluation notification type is stored');

// ---- Sozlukler ve sabitler.
supplierCheck(array_keys(qmsSupplierStatusLabels()) === QMS_SUPPLIER_STATUSES, 'Status labels match the documented flow');
supplierCheck(array_keys(qmsSupplierRiskLabels()) === QMS_SUPPLIER_RISK_CLASSES, 'Risk labels match the documented classes');
supplierCheck(array_keys(qmsSupplierResultLabels()) === QMS_SUPPLIER_EVALUATION_RESULTS, 'Decision labels match the documented results');
supplierCheck(
    count(qmsSupplierStatusI18nKeys()) === count(QMS_SUPPLIER_STATUSES)
        && count(qmsSupplierRiskI18nKeys()) === count(QMS_SUPPLIER_RISK_CLASSES)
        && count(qmsSupplierResultI18nKeys()) === count(QMS_SUPPLIER_EVALUATION_RESULTS),
    'Every status, risk class and decision has an i18n key'
);
supplierCheck((int) $pdo->query('SELECT COUNT(*) FROM suppliers')->fetchColumn() === 3, 'Temporary table holds only the fixtures');

session_destroy();
echo "Completed $checks supplier checks using temporary tables." . PHP_EOL;
