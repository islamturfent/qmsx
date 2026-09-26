<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
session_start();
require 'config/database.php';
require 'includes/supplier-eval-schedule-functions.php';
$checks = 0;
function seCheck(bool $ok, string $name): void { global $checks; if (!$ok) throw new RuntimeException('FAIL: ' . $name); $checks++; echo 'PASS: ' . $name . PHP_EOL; }

$tables = ['companies','users','company_admin_assignments','suppliers','supplier_evaluation_schedule'];
foreach ($tables as $t) {
    $s = $pdo->query("SHOW CREATE TABLE $t")->fetch(PDO::FETCH_NUM)[1];
    $s = preg_replace('/(,\n)?\s*CONSTRAINT[^\n]+/', '', $s);
    $pdo->exec(str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $s));
}
$pdo->exec("INSERT INTO companies(id,company_name,active) VALUES(99888,'SE A',1),(99889,'SE B',1)");
$pdo->exec("INSERT INTO users(id,username,password_hash,full_name,role,company_id,active) VALUES(99888,'se','x','SE','super_admin',NULL,1)");
$pdo->exec("INSERT INTO suppliers(id,company_id,name,status,active) VALUES(99880,99888,'Tedarikçi A','approved',1),(99881,99888,'Tedarikçi B','approved',1)");

// Geçmiş tarihli planli degerlendirme -> overdue.
$id = qmsSupplierEvalScheduleAdd($pdo, ['supplier_id'=>99880,'cycle_label'=>'Q1 2026','due_date'=>'2020-01-01','status'=>'planned','result'=>'','notes'=>''], 99888, 'super_admin');
seCheck($id !== null && $id > 0, 'Schedule added');
$list = qmsSupplierEvalScheduleList($pdo, 99888, 'super_admin');
seCheck(count($list) === 1, 'Schedule listed');
seCheck($list[0]['eff_status'] === 'overdue', 'Overdue computed for past planned date');
seCheck((int) $list[0]['company_id'] === 99888, 'Company id inherited from supplier');
seCheck(qmsSupplierEvalScheduleFind($pdo, (int)$id, 99888, 'super_admin')['cycle_label'] === 'Q1 2026', 'Schedule fetched by id');

// Dogrulama redleri.
seCheck(qmsSupplierEvalScheduleAdd($pdo, ['supplier_id'=>0,'cycle_label'=>'x','due_date'=>'','status'=>'planned','result'=>'','notes'=>''], 99888, 'super_admin') === null, 'Blank supplier rejected');
$pdo->exec("INSERT INTO suppliers(id,company_id,name,status,active) VALUES(99882,99889,'Tedarikçi C','approved',1)");
seCheck(qmsSupplierEvalScheduleAdd($pdo, ['supplier_id'=>99882,'cycle_label'=>'y','due_date'=>'','status'=>'planned','result'=>'','notes'=>''], 99888, 'system_admin') === null, 'Cross-company supplier rejected');

// Guncelle: done.
seCheck(qmsSupplierEvalScheduleUpdate($pdo, (int)$id, ['cycle_label'=>'Q1 2026','due_date'=>'2020-01-01','status'=>'done','result'=>'Başarılı','notes'=>''], 99888, 'super_admin') === true, 'Schedule updated');
seCheck(qmsSupplierEvalScheduleList($pdo, 99888, 'super_admin')[0]['eff_status'] === 'done', 'Done status after update');
seCheck(count(qmsSupplierEvalScheduleList($pdo, 99888, 'super_admin', 'overdue')) === 0, 'Overdue filter empty after done');

// Planned future -> not overdue.
$id2 = qmsSupplierEvalScheduleAdd($pdo, ['supplier_id'=>99880,'cycle_label'=>'Q2 2026','due_date'=>'2099-01-01','status'=>'planned','result'=>'','notes'=>''], 99888, 'super_admin');
seCheck(qmsSupplierEvalScheduleList($pdo, 99888, 'super_admin', 'planned')[0]['eff_status'] === 'planned', 'Future planned not overdue');

// Kapsam: atanan admin kendi sirketini gorur.
$pdo->exec("INSERT INTO company_admin_assignments(company_id,admin_user_id,active) VALUES(99888,99888,1)");
seCheck(count(qmsSupplierEvalScheduleList($pdo, 99888, 'system_admin')) === 2, 'Assigned admin sees own schedules');
seCheck(qmsSupplierEvalScheduleDelete($pdo, (int)$id, 99888, 'system_admin') === true, 'Schedule deletable in scope');
seCheck(count(qmsSupplierEvalScheduleList($pdo, 99888, 'super_admin')) === 1, 'Deleted schedule hidden');

echo "\nCompleted $checks supplier-evaluation checks using temporary tables.\n";
