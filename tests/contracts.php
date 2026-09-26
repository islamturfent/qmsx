<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
session_start();
require 'config/database.php';
require 'includes/contract-functions.php';
$checks = 0;
function conCheck(bool $ok, string $name): void { global $checks; if (!$ok) throw new RuntimeException('FAIL: ' . $name); $checks++; echo 'PASS: ' . $name . PHP_EOL; }

$tables = ['companies','users','company_admin_assignments','contracts'];
foreach ($tables as $t) {
    $s = $pdo->query("SHOW CREATE TABLE $t")->fetch(PDO::FETCH_NUM)[1];
    $s = preg_replace('/(,\n)?\s*CONSTRAINT[^\n]+/', '', $s);
    $pdo->exec(str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $s));
}
$pdo->exec("INSERT INTO companies(id,company_name,active) VALUES(99894,'CON A',1),(99895,'CON B',1)");
$pdo->exec("INSERT INTO users(id,username,password_hash,full_name,role,company_id,active) VALUES(99894,'con','x','CON','super_admin',NULL,1)");

$id = qmsContractAdd($pdo, ['company_id'=>99894,'contract_code'=>'C-001','contract_name'=>'Bakım Sözleşmesi','party_name'=>'ACME','contract_type'=>'supplier','start_date'=>'2026-01-01','end_date'=>'2026-06-30','renewal_date'=>'2026-05-01','value_amount'=>50000.50,'currency'=>'TRY','status'=>'active','notes'=>'yıllık'], 99894, 'super_admin');
conCheck($id !== null && $id > 0, 'Contract added');
conCheck(count(qmsContractList($pdo, 99894, 'super_admin')) === 1, 'Contract listed');
conCheck(qmsContractFind($pdo, (int)$id, 99894, 'super_admin')['contract_name'] === 'Bakım Sözleşmesi', 'Contract fetched by id');

// Dogrulama redleri.
conCheck(qmsContractAdd($pdo, ['company_id'=>0,'contract_name'=>'x','contract_type'=>'customer','status'=>'active'], 99894, 'super_admin') === null, 'Blank company rejected');
conCheck(qmsContractAdd($pdo, ['company_id'=>99895,'contract_name'=>'y','contract_type'=>'customer','status'=>'active'], 99894, 'system_admin') === null, 'Cross-company rejected');
conCheck(qmsContractAdd($pdo, ['company_id'=>99894,'contract_name'=>'','contract_type'=>'customer','status'=>'active'], 99894, 'super_admin') === null, 'Blank name rejected');

conCheck(qmsContractUpdate($pdo, (int)$id, ['contract_code'=>'C-002','contract_name'=>'Bakım v2','party_name'=>'','contract_type'=>'customer','start_date'=>'','end_date'=>'','renewal_date'=>'','value_amount'=>'','currency'=>'USD','status'=>'terminated','notes'=>''], 99894, 'super_admin') === true, 'Contract updated');
conCheck(qmsContractFind($pdo, (int)$id, 99894, 'super_admin')['status'] === 'terminated', 'Status persisted to terminated');
conCheck(qmsContractFind($pdo, (int)$id, 99894, 'super_admin')['currency'] === 'USD', 'Currency updated');

// Kapsam: atanan admin kendi sirketini gorur.
$pdo->exec("INSERT INTO company_admin_assignments(company_id,admin_user_id,active) VALUES(99894,99894,1)");
conCheck(count(qmsContractList($pdo, 99894, 'system_admin')) === 1, 'Assigned admin sees own contracts');
conCheck(qmsContractDelete($pdo, (int)$id, 99894, 'system_admin') === true, 'Contract deletable in scope');
conCheck(count(qmsContractList($pdo, 99894, 'super_admin')) === 0, 'Deleted contract hidden');

echo "\nCompleted $checks contract checks using temporary tables.\n";
