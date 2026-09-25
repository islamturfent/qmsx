<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
session_start();
require 'config/database.php';
require 'includes/due-workbench-functions.php';
$checks = 0;
function mcCheck(bool $ok, string $name): void { global $checks; if (!$ok) throw new RuntimeException('FAIL: ' . $name); $checks++; echo 'PASS: ' . $name . PHP_EOL; }

$tables = ['companies','users','company_admin_assignments','nonconformities','corrective_actions','complaints','equipment'];
foreach ($tables as $t) {
    $s = $pdo->query("SHOW CREATE TABLE $t")->fetch(PDO::FETCH_NUM)[1];
    $s = preg_replace('/(,\n)?\s*CONSTRAINT[^\n]+/', '', $s);
    $pdo->exec(str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $s));
}
$pdo->exec("INSERT INTO companies(id,company_name,active) VALUES(99996,'MA A',1)");
$pdo->exec("INSERT INTO nonconformities(id,company_id,audit_id,source,title,severity,status,due_date,active) VALUES (99996,99996,0,'audit','NC','major','open','2099-01-01',1)");
// Kullaniciya atanmis acik duzeltici faaliyet (biri gecikmis).
$pdo->exec("INSERT INTO corrective_actions(id,nonconformity_id,action_type,action_text,due_date,status,active,responsible_user_id) VALUES (99996,99996,'corrective','A1','2020-01-01','in_progress',1,777),(99997,99996,'corrective','A2','2099-01-01','in_progress',1,777),(99998,99996,'corrective','A3','2099-01-01','closed',1,777)");
// Atanmis acik sikayet + kapali olan dahil degil.
$pdo->exec("INSERT INTO complaints(id,company_id,source,severity,status,received_date,subject,due_date,active,responsible_user_id) VALUES (99996,99996,'customer','major','new','2020-01-01','C1','2099-01-01',1,777),(99997,99996,'customer','major','closed','2020-01-01','C2','2099-01-01',1,777)");
// Atanmis acik ekipman kalibrasyonu.
$pdo->exec("INSERT INTO equipment(id,company_id,name,asset_code,next_calibration_date,status,active,responsible_user_id) VALUES(99996,99996,'E1','EQ-1','2099-01-01','operational',1,777)");

$s = qmsMyAssignments($pdo, 777);
mcCheck($s['actions']['count'] === 2, 'Only open actions counted (closed excluded)');
mcCheck($s['complaints']['count'] === 1, 'Only open complaint counted');
mcCheck($s['equipment']['count'] === 1, 'Open equipment calibration counted');
$overdueActions = 0;
foreach ($s['actions']['rows'] as $a) { if ($a['overdue']) { $overdueActions++; } }
mcCheck($overdueActions === 1, 'Past-due action flagged as overdue');
// Baska kullanici bos doner.
$sOther = qmsMyAssignments($pdo, 888);
mcCheck($sOther['actions']['count'] === 0 && $sOther['complaints']['count'] === 0, 'Another user sees none');

echo "\nCompleted $checks my-assignments checks using temporary tables.\n";
