<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
session_start();
require 'config/database.php';
require 'includes/due-workbench-functions.php';
$checks = 0;
function udcCheck(bool $ok, string $name): void { global $checks; if (!$ok) throw new RuntimeException('FAIL: ' . $name); $checks++; echo 'PASS: ' . $name . PHP_EOL; }

$tables = ['companies','users','company_admin_assignments','nonconformities','corrective_actions','complaints','equipment'];
foreach ($tables as $t) {
    $s = $pdo->query("SHOW CREATE TABLE $t")->fetch(PDO::FETCH_NUM)[1];
    $s = preg_replace('/(,\n)?\s*CONSTRAINT[^\n]+/', '', $s);
    $pdo->exec(str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $s));
}
$pdo->exec("INSERT INTO companies(id,company_name,active) VALUES(99921,'UD A',1)");
$pdo->exec("INSERT INTO users(id,username,password_hash,full_name,role,company_id,active) VALUES(99921,'u','x','U','system_admin',NULL,1)");
$pdo->exec("INSERT INTO nonconformities(id,company_id,audit_id,source,title,severity,status,due_date,active) VALUES (99921,99921,0,'audit','NC','major','open','2099-01-01',1)");
// Kullaniciya atanmis geciken duzeltici faaliyet.
$pdo->exec("INSERT INTO corrective_actions(id,nonconformity_id,action_type,action_text,due_date,status,active,responsible_user_id) VALUES (99921,99921,'corrective','My Fix','2020-01-01','in_progress',1,99921),(99922,99921,'preventive','Others','2020-01-01','in_progress',1,99923)");
// Kullaniciya atanmis geciken sikayet.
$pdo->exec("INSERT INTO complaints(id,company_id,source,severity,status,received_date,subject,due_date,active,responsible_user_id) VALUES (99921,99921,'customer','major','new','2020-01-01','My Compl','2020-01-02',1,99921),(99922,99921,'customer','major','new','2020-01-01','Another','2020-01-02',1,99923)");
// Kullaniciya atanmis geciken kalibrasyon.
$pdo->exec("INSERT INTO equipment(id,company_id,name,asset_code,next_calibration_date,status,active,responsible_user_id) VALUES(99921,99921,'Cihaz','EQ-1','2020-01-01','operational',1,99921)");

$items = qmsUserOverdueAssignments($pdo, 99921);
$labels = array_column($items, 'label');
udcCheck(count($items) === 3, 'User sees their 3 assigned overdue items');
udcCheck(in_array('Düzeltici Faaliyet', $labels, true) && in_array('Şikayet', $labels, true) && in_array('Kalibrasyon', $labels, true), 'Each overdue type appears for the responsible user');
// Bir baska kullaniciya atanan islem icin bos doner.
$itemsOther = qmsUserOverdueAssignments($pdo, 99922);
udcCheck(count($itemsOther) === 0, 'Other user sees none of the first users items');
udcCheck(isset($items[0]['due']) && isset($items[0]['link']), 'Each item carries due date and link');

echo "\nCompleted $checks user-overdue checks using temporary tables.\n";
