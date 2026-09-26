<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
session_start();
require 'config/database.php';
require 'includes/quality-plan-functions.php';
$checks = 0;
function qpCheck(bool $ok, string $name): void { global $checks; if (!$ok) throw new RuntimeException('FAIL: ' . $name); $checks++; echo 'PASS: ' . $name . PHP_EOL; }

$tables = ['companies','users','company_admin_assignments','quality_plans','quality_plan_items'];
foreach ($tables as $t) {
    $s = $pdo->query("SHOW CREATE TABLE $t")->fetch(PDO::FETCH_NUM)[1];
    $s = preg_replace('/(,\n)?\s*CONSTRAINT[^\n]+/', '', $s);
    $pdo->exec(str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $s));
}
$pdo->exec("INSERT INTO companies(id,company_name,active) VALUES(99886,'QP A',1),(99887,'QP B',1)");
$pdo->exec("INSERT INTO users(id,username,password_hash,full_name,role,company_id,active) VALUES(99886,'qp','x','QP','super_admin',NULL,1)");

$pid = qmsQualityPlanAdd($pdo, ['company_id'=>99886,'plan_year'=>2026,'title'=>'Kalite Planı 2026','description'=>'Yıllık hedefler'], 99886, 'super_admin');
qpCheck($pid !== null && $pid > 0, 'Plan added');
qpCheck(count(qmsQualityPlanList($pdo, 99886, 'super_admin')) === 1, 'Plan listed');
qpCheck(qmsQualityPlanFind($pdo, (int)$pid, 99886, 'super_admin')['plan_year'] == 2026, 'Plan fetched by id');

// Dogrulama ve tekrar.
qpCheck(qmsQualityPlanAdd($pdo, ['company_id'=>99886,'plan_year'=>2026,'title'=>'Tekrar','description'=>null], 99886, 'super_admin') === null, 'Duplicate company+year rejected');
qpCheck(qmsQualityPlanAdd($pdo, ['company_id'=>0,'plan_year'=>2026,'title'=>'x','description'=>null], 99886, 'super_admin') === null, 'Blank company rejected');
qpCheck(qmsQualityPlanAdd($pdo, ['company_id'=>99887,'plan_year'=>2026,'title'=>'y','description'=>null], 99886, 'system_admin') === null, 'Cross-company rejected');
qpCheck(qmsQualityPlanAdd($pdo, ['company_id'=>99886,'plan_year'=>0,'title'=>'y','description'=>null], 99886, 'super_admin') === null, 'Invalid year rejected');

// Kalemler.
$i1 = qmsQualityPlanAddItem($pdo, (int)$pid, ['category'=>'Süreç','objective'=>'Hata oranını %2 altına indir','target'=>'%2','responsible'=>'Kalite Ekibi','due_date'=>'2026-06-30','status'=>'in_progress','progress'=>40], 99886, 'super_admin');
$i2 = qmsQualityPlanAddItem($pdo, (int)$pid, ['category'=>'Eğitim','objective'=>'Eğitim tamamlanması','target'=>'%100','responsible'=>'','due_date'=>'','status'=>'not_started','progress'=>0], 99886, 'super_admin');
qpCheck($i1 !== null && $i2 !== null, 'Items added');
qpCheck(count(qmsQualityPlanItems($pdo, (int)$pid)) === 2, 'Items listed');
qpCheck(qmsQualityPlanAddItem($pdo, (int)$pid, ['objective'=>'','status'=>'bad','progress'=>999], 99886, 'super_admin') === null, 'Blank objective rejected');
qpCheck(qmsQualityPlanUpdateItem($pdo, (int)$i1, ['category'=>'Süreç','objective'=>'Hata oranını %1 altına indir','target'=>'%1','responsible'=>'Kalite','due_date'=>'2026-06-30','status'=>'completed','progress'=>100], 99886, 'super_admin') === true, 'Item updated');
qpCheck((int) qmsQualityPlanList($pdo, 99886, 'super_admin')[0]['item_completed'] === 1, 'Completion count after update');

// Kapsam.
$pdo->exec("INSERT INTO company_admin_assignments(company_id,admin_user_id,active) VALUES(99886,99886,1)");
qpCheck(count(qmsQualityPlanList($pdo, 99886, 'system_admin')) === 1, 'Assigned admin sees own plans');
qpCheck(qmsQualityPlanUpdate($pdo, (int)$pid, ['title'=>'QP 2026 v2','description'=>''], 99886, 'system_admin') === true, 'Plan updatable in scope');
qpCheck(qmsQualityPlanDeleteItem($pdo, (int)$i2, 99886, 'system_admin') === true, 'Item deletable in scope');
qpCheck(count(qmsQualityPlanItems($pdo, (int)$pid)) === 1, 'Deleted item hidden');
qpCheck(qmsQualityPlanDelete($pdo, (int)$pid, 99886, 'system_admin') === true, 'Plan deletable in scope');
qpCheck(count(qmsQualityPlanList($pdo, 99886, 'super_admin')) === 0, 'Deleted plan hidden');

echo "\nCompleted $checks quality-plan checks using temporary tables.\n";
