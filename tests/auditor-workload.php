<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
session_start();
require 'config/database.php';
require 'includes/due-workbench-functions.php';
$checks = 0;
function adcCheck(bool $ok, string $name): void { global $checks; if (!$ok) throw new RuntimeException('FAIL: ' . $name); $checks++; echo 'PASS: ' . $name . PHP_EOL; }

$tables = ['companies','users','company_admin_assignments','auditors','audits','audit_auditors','nonconformities','corrective_actions'];
foreach ($tables as $t) {
    $s = $pdo->query("SHOW CREATE TABLE $t")->fetch(PDO::FETCH_NUM)[1];
    $s = preg_replace('/(,\n)?\s*CONSTRAINT[^\n]+/', '', $s);
    $pdo->exec(str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $s));
}
$pdo->exec("INSERT INTO companies(id,company_name,active) VALUES(99911,'WD A',1)");
$pdo->exec("INSERT INTO users(id,username,password_hash,full_name,role,company_id,active) VALUES(99911,'w','x','W','super_admin',NULL,1)");
$pdo->exec("INSERT INTO auditors(id,company_id,first_name,last_name,active) VALUES(99911,99911,'Ali','Veli',1)");
$pdo->exec("INSERT INTO audits(id,company_id,title,status,active) VALUES (99911,99911,'Denetim 1','in_progress',1),(99912,99911,'Denetim 2','completed',1)");
$pdo->exec("INSERT INTO audit_auditors(id,audit_id,auditor_id) VALUES (99911,99911,99911),(99912,99912,99911)");
// Denetim 1 -> acik uygunsuzluk + acik faaliyet
$pdo->exec("INSERT INTO nonconformities(id,company_id,audit_id,source,title,severity,status,due_date,active) VALUES (99911,99911,99911,'audit','NC1','major','open','2099-01-01',1),(99912,99911,99911,'audit','NC2','minor','closed','2099-01-01',1)");
$pdo->exec("INSERT INTO corrective_actions(id,nonconformity_id,action_type,action_text,status,active) VALUES (99911,99911,'corrective','A1','in_progress',1),(99912,99911,'corrective','A2','closed',1)");

$w = qmsAuditorWorkload($pdo, 99911, 'super_admin');
adcCheck(count($w) === 1 && $w[0]['name'] === 'Ali Veli', 'Workload lists the auditor');
adcCheck($w[0]['assigned_audits'] === 2, 'Both assigned audits counted (active)');
adcCheck($w[0]['open_nonconformities'] === 1, 'Only the open nonconformity counted (closed NC excluded)');
adcCheck($w[0]['open_actions'] === 1, 'Only the open action counted (closed action excluded)');
adcCheck($w[0]['total_open'] === 2, 'Total open = NC + actions');

// Kapsam: baska sirketin denetcisi gorunmez.
$pdo->exec("INSERT INTO companies(id,company_name,active) VALUES(99912,'WD B',1)");
$pdo->exec("INSERT INTO auditors(id,company_id,first_name,last_name,active) VALUES(99912,99912,'Baska','Denetci',1)");
$w2 = qmsAuditorWorkload($pdo, 99911, 'system_admin');
adcCheck(count($w2) === 0, 'Assigned admin sees no auditors from another company');
$pdo->exec("INSERT INTO company_admin_assignments(company_id, admin_user_id, active) VALUES(99912,99911,1),(99911,99911,1)");
$w3 = qmsAuditorWorkload($pdo, 99911, 'system_admin');
adcCheck(count($w3) === 2, 'Assigned to both companies, both auditors appear');

echo "\nCompleted $checks auditor-workload checks using temporary tables.\n";
