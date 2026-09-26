<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
session_start();
require 'config/database.php';
require 'includes/process-functions.php';
$checks = 0;
function prcCheck(bool $ok, string $name): void { global $checks; if (!$ok) throw new RuntimeException('FAIL: ' . $name); $checks++; echo 'PASS: ' . $name . PHP_EOL; }

$tables = ['companies','users','company_admin_assignments','processes'];
foreach ($tables as $t) {
    $s = $pdo->query("SHOW CREATE TABLE $t")->fetch(PDO::FETCH_NUM)[1];
    $s = preg_replace('/(,\n)?\s*CONSTRAINT[^\n]+/', '', $s);
    $pdo->exec(str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $s));
}
$pdo->exec("INSERT INTO companies(id,company_name,active) VALUES(99892,'PRC A',1),(99893,'PRC B',1)");
$pdo->exec("INSERT INTO users(id,username,password_hash,full_name,role,company_id,active) VALUES(99892,'prc','x','PRC','super_admin',NULL,1)");

$id = qmsProcessAdd($pdo, ['company_id'=>99892,'process_code'=>'P.01','process_name'=>'Satınalma','department'=>'Satınalma','owner_name'=>'Ayşe','objective'=>'Tedarikçi yönetimi','inputs'=>'Talep','outputs'=>'Sipariş','kpi'=>'Tedarik süresi','review_date'=>'2026-12-31','status'=>'active'], 99892, 'super_admin');
prcCheck($id !== null && $id > 0, 'Process added');
prcCheck(count(qmsProcessList($pdo, 99892, 'super_admin')) === 1, 'Process listed');
prcCheck(qmsProcessFind($pdo, (int)$id, 99892, 'super_admin')['process_name'] === 'Satınalma', 'Process fetched by id');

// Dogrulama redleri.
prcCheck(qmsProcessAdd($pdo, ['company_id'=>0,'process_name'=>'x','status'=>'active'], 99892, 'super_admin') === null, 'Blank company rejected');
prcCheck(qmsProcessAdd($pdo, ['company_id'=>99893,'process_name'=>'y','status'=>'active'], 99892, 'system_admin') === null, 'Cross-company rejected');
prcCheck(qmsProcessAdd($pdo, ['company_id'=>99892,'process_name'=>'','status'=>'active'], 99892, 'super_admin') === null, 'Blank name rejected');

prcCheck(qmsProcessUpdate($pdo, (int)$id, ['process_code'=>'P.02','process_name'=>'Satınalma v2','department'=>'','owner_name'=>'','objective'=>'','inputs'=>'','outputs'=>'','kpi'=>'','review_date'=>'','status'=>'paused'], 99892, 'super_admin') === true, 'Process updated');
prcCheck(qmsProcessFind($pdo, (int)$id, 99892, 'super_admin')['status'] === 'paused', 'Status persisted to paused');

// Kapsam: atanan admin kendi sirketini gorur.
$pdo->exec("INSERT INTO company_admin_assignments(company_id,admin_user_id,active) VALUES(99892,99892,1)");
prcCheck(count(qmsProcessList($pdo, 99892, 'system_admin')) === 1, 'Assigned admin sees own processes');
prcCheck(qmsProcessDelete($pdo, (int)$id, 99892, 'system_admin') === true, 'Process deletable in scope');
prcCheck(count(qmsProcessList($pdo, 99892, 'super_admin')) === 0, 'Deleted process hidden');

echo "\nCompleted $checks process checks using temporary tables.\n";
