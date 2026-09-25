<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
session_start();
require 'config/database.php';
require 'includes/search-functions.php';
$checks = 0;
function seCheck(bool $ok, string $name): void { global $checks; if (!$ok) throw new RuntimeException('FAIL: ' . $name); $checks++; echo 'PASS: ' . $name . PHP_EOL; }

$tables = ['companies','users','company_admin_assignments','nonconformities','complaints','risks','corrective_actions','documents','suppliers','trainings','equipment','staff_members','management_reviews','external_audits','external_audit_findings'];
foreach ($tables as $t) {
    $s = $pdo->query("SHOW CREATE TABLE $t")->fetch(PDO::FETCH_NUM)[1];
    $s = preg_replace('/(,\n)?\s*CONSTRAINT[^\n]+/', '', $s);
    $pdo->exec(str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $s));
}
$pdo->exec("INSERT INTO companies(id,company_name,active) VALUES(99995,'S A',1)");
$pdo->exec("INSERT INTO users(id,username,password_hash,full_name,role,company_id,active) VALUES(99995,'s','x','S','super_admin',NULL,1)");
$pdo->exec("INSERT INTO staff_members(id,company_id,first_name,last_name,position,department,employee_code,active) VALUES(99995,99995,'Ayşe','Yılmaz','Kalite Müdürü','Kalite','P-1',1)");
$pdo->exec("INSERT INTO management_reviews(id,company_id,title,scope_notes,status,active) VALUES(99995,99995,'Yıllık Yönetim Gözden Geçirmesi','Kapsam','done',1)");
$pdo->exec("INSERT INTO external_audits(id,company_id,audit_type,title,status,active) VALUES(99995,99995,'dis','Denetim','in_progress',1)");
$pdo->exec("INSERT INTO external_audit_findings(id,external_audit_id,finding_text,category,severity,status,active) VALUES(99995,99995,'Kalite bulgusu bulundu','nc','major','open',1)");

$kw = qmsSearchKeywords('gözden geçirme');
seCheck(in_array('gözden', $kw, true) && in_array('geçirme', $kw, true), 'Keywords tokenize the query');
seCheck(count(qmsSearchType($pdo, 'review', 99995, 'super_admin', ['gözden'])) >= 1, 'Review is searchable by keyword');
seCheck(count(qmsSearchType($pdo, 'personnel', 99995, 'super_admin', ['kalite'])) >= 1, 'Personnel is searchable by position');
seCheck(count(qmsSearchType($pdo, 'finding', 99995, 'super_admin', ['bulgusu'])) >= 1, 'Finding is searchable by text');
seCheck(count(qmsSearchType($pdo, 'finding', 99995, 'super_admin', ['kalite'])) >= 1, 'Finding is searchable via joined notes/text');

// Genel arama da yeni tipleri kapsar.
$all = qmsSearchRecords($pdo, 99995, 'super_admin', 'kalite');
$types = array_column($all, 'type');
seCheck(in_array('finding', $types, true) || in_array('personnel', $types, true), 'Global search includes the new record types');

echo "\nCompleted $checks search-enhanced checks using temporary tables.\n";
