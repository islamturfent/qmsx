<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
session_start();
require 'config/database.php';
require 'includes/quality-cost-functions.php';
$checks = 0;
function qcCheck(bool $ok, string $name): void { global $checks; if (!$ok) throw new RuntimeException('FAIL: ' . $name); $checks++; echo 'PASS: ' . $name . PHP_EOL; }

// Gercek tablolarin semasi gecici tablolara kopyalanir; FK'lar cikarilir.
foreach (['companies','users','company_admin_assignments','quality_costs'] as $table) {
    $schema = $pdo->query("SHOW CREATE TABLE $table")->fetch(PDO::FETCH_NUM)[1];
    $schema = preg_replace('/(,\n)?\s*CONSTRAINT[^\n]+/', '', $schema);
    $pdo->exec(str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $schema));
}

$pdo->exec("INSERT INTO companies(id, company_name, active) VALUES(99201,'QC A',1),(99202,'QC B',1)");
$pdo->exec("INSERT INTO users(id, username, password_hash, full_name, role, company_id, active) VALUES
    (99201,'qc-admin','x','QC Admin','system_admin',NULL,1),
    (99202,'qc-root','x','QC Root','super_admin',NULL,1),
    (99204,'qc-other','x','QC Other','system_admin',NULL,1)");
$pdo->exec("INSERT INTO company_admin_assignments(company_id, admin_user_id, active) VALUES(99201,99201,1),(99202,99204,1)");
$pdo->exec("INSERT INTO quality_costs(id, company_id, cost_type, title, amount, incurred_on, active) VALUES
    (99201,99201,'prevention','Egitim',1000.00,'2026-05-01',1),
    (99202,99201,'appraisal','Olcme',500.00,'2026-06-01',1),
    (99203,99201,'internal_failure','Hurda',300.00,'2026-07-01',1),
    (99204,99202,'external_failure','Iade',400.00,'2026-05-01',1)");

// ---- Kapsamli liste.
$list = qmsQualityCostList($pdo, 99201, 'system_admin');
qcCheck(count($list) === 3, 'Assigned admin lists only own-company costs');
qcCheck(count(qmsQualityCostList($pdo, 99202, 'super_admin')) === 4, 'Super admin lists every cost');

// ---- Ozet (kategori toplami + hata maliyeti).
$summary = qmsQualityCostSummary($pdo, 99201, 'system_admin');
qcCheck((float) $summary['prevention'] === 1000.0, 'Prevention total is correct');
qcCheck((float) $summary['appraisal'] === 500.0, 'Appraisal total is correct');
qcCheck((float) $summary['internal_failure'] === 300.0, 'Internal failure total is correct');
qcCheck((float) $summary['failure_total'] === 300.0, 'Failure total is the sum of failure categories');
qcCheck((float) $summary['total'] === 1800.0, 'Total is the sum of all categories');
qcCheck((int) $summary['entry_count'] === 3, 'Summary counts entries');

// ---- Tarih/type filtreleri.
qcCheck(count(qmsQualityCostList($pdo, 99201, 'system_admin', 'prevention')) === 1, 'Type filter narrows to one');
qcCheck(count(qmsQualityCostList($pdo, 99201, 'system_admin', '', '2026-06-01', '2026-12-31')) === 2, 'Date range filter works');

// ---- Ekleme.
$newId = qmsQualityCostAdd($pdo, ['company_id' => 99201, 'cost_type' => 'external_failure', 'title' => 'Iade', 'amount' => '250.50', 'incurred_on' => '2026-08-01', 'notes' => 'x'], 99201, 'system_admin');
qcCheck($newId !== null && $newId > 0, 'Valid cost is added');
$afterTotal = qmsQualityCostSummary($pdo, 99201, 'system_admin');
qcCheck((float) $afterTotal['external_failure'] === 250.5, 'Added failure cost is reflected in the summary');

// ---- Dogrulama redleri.
qcCheck(qmsQualityCostAdd($pdo, ['company_id' => 99202, 'cost_type' => 'prevention', 'title' => 'X', 'amount' => '10', 'incurred_on' => '2026-05-01'], 99201, 'system_admin') === null, 'Another tenant company is rejected');
qcCheck(qmsQualityCostAdd($pdo, ['company_id' => 99201, 'cost_type' => 'prevention', 'title' => '', 'amount' => '10', 'incurred_on' => '2026-05-01'], 99201, 'system_admin') === null, 'Blank title is rejected');
qcCheck(qmsQualityCostAdd($pdo, ['company_id' => 99201, 'cost_type' => 'bad', 'title' => 'X', 'amount' => '10', 'incurred_on' => '2026-05-01'], 99201, 'system_admin') === null, 'Invalid type is rejected');
qcCheck(qmsQualityCostAdd($pdo, ['company_id' => 99201, 'cost_type' => 'prevention', 'title' => 'X', 'amount' => '-5', 'incurred_on' => '2026-05-01'], 99201, 'system_admin') === null, 'Negative amount is rejected');

// ---- Silme (kapsam).
qcCheck(qmsQualityCostDelete($pdo, 99201, 99201, 'system_admin') === true, 'Own cost is deletable');
qcCheck(qmsQualityCostDelete($pdo, 99204, 99201, 'system_admin') === false, 'Another tenant cost cannot be deleted');
qcCheck(count(qmsQualityCostList($pdo, 99201, 'system_admin')) === 3, 'Deleting soft-removes the record');

echo "\nCompleted $checks quality-cost checks using temporary tables.\n";
