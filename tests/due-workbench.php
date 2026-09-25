<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
session_start();
require 'config/database.php';
require 'includes/due-workbench-functions.php';
$checks = 0;
function odcCheck(bool $ok, string $name): void { global $checks; if (!$ok) throw new RuntimeException('FAIL: ' . $name); $checks++; echo 'PASS: ' . $name . PHP_EOL; }

$tables = ['nonconformities','corrective_actions','trainings','equipment','external_audits','external_audit_findings','documents','complaints','companies','users','company_admin_assignments'];
foreach ($tables as $t) {
    $s = $pdo->query("SHOW CREATE TABLE $t")->fetch(PDO::FETCH_NUM)[1];
    $s = preg_replace('/(,\n)?\s*CONSTRAINT[^\n]+/', '', $s);
    $pdo->exec(str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $s));
}
$pdo->exec("INSERT INTO companies(id,company_name,active) VALUES(99801,'OD A',1)");
$pdo->exec("INSERT INTO users(id,username,password_hash,full_name,role,company_id,active) VALUES(99801,'o','x','O','super_admin',NULL,1)");

// Geciken duzeltici faaliyet (sirket uygunsuzluk uzerinden) + kapali olan sayilmaz.
$pdo->exec("INSERT INTO nonconformities(id,company_id,source,title,severity,status,due_date,active) VALUES (99801,99801,'audit','NC1','major','open','2020-01-01',1)");
$pdo->exec("INSERT INTO corrective_actions(id,nonconformity_id,action_text,responsible_person,due_date,status,active) VALUES (99801,99801,'Fix it','Ali','2020-01-01','in_progress',1),(99802,99801,'Closed late','B','2020-01-01','closed',1)");

// Geciken kalibrasyon + gelecek tarihli olan sayilmaz.
$pdo->exec("INSERT INTO equipment(id,company_id,name,asset_code,next_calibration_date,status,active) VALUES(99801,99801,'Cihaz','EQ-1','2020-01-01','operational',1),(99802,99801,'Cihaz2','EQ-2','2099-01-01','operational',1)");

// Geciken sikayet.
$pdo->exec("INSERT INTO complaints(id,company_id,source,severity,status,received_date,subject,due_date,active) VALUES(99801,99801,'customer','major','new','2020-01-01','Subj','2020-01-02',1)");

$w = qmsOverdueWorkbench($pdo, 99801, 'super_admin');
odcCheck($w['actions']['count'] === 1, 'Overdue actions counts only the open late action');
odcCheck($w['nonconformities']['count'] === 1, 'Overdue nonconformity counted');
odcCheck($w['equipment']['count'] === 1, 'Overdue equipment excludes future calibration');
odcCheck($w['complaints']['count'] === 1, 'Overdue complaint counted');
$total = 0; foreach ($w as $s) { $total += $s['count']; }
odcCheck($total === 4, 'Total overdue across sections = 4');
odcCheck($w['trainings']['count'] === 0 && $w['documents']['count'] === 0, 'Empty sections report zero');

// Kapsam: atanan admin yalnizca kendi sirketini gorur.
$pdo->exec("INSERT INTO companies(id,company_name,active) VALUES(99810,'OD B',1)");
$pdo->exec("INSERT INTO nonconformities(id,company_id,source,title,severity,status,due_date,active) VALUES (99810,99810,'audit','NC-B','minor','open','2020-01-01',1)");
$pdo->exec("INSERT INTO company_admin_assignments(company_id, admin_user_id, active) VALUES(99810,99801,1)");
$pdo->exec("INSERT INTO company_admin_assignments(company_id, admin_user_id, active) VALUES(99801,99801,1)");
$w2 = qmsOverdueWorkbench($pdo, 99801, 'system_admin');
$total2 = 0; foreach ($w2 as $s) { $total2 += $s['count']; }
odcCheck($total2 === 5, 'Assigned admin sees both companies (4 + 1)');

echo "\nCompleted $checks overdue-workbench checks using temporary tables.\n";
