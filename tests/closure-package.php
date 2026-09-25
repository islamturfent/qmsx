<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
session_start();
require 'config/database.php';
require 'includes/closure-package-functions.php';
$checks = 0;
function clCheck(bool $ok, string $name): void { global $checks; if (!$ok) throw new RuntimeException('FAIL: ' . $name); $checks++; echo 'PASS: ' . $name . PHP_EOL; }

$tables = ['companies','users','company_admin_assignments','nonconformities','corrective_actions','corrective_action_evidence'];
foreach ($tables as $t) {
    $s = $pdo->query("SHOW CREATE TABLE $t")->fetch(PDO::FETCH_NUM)[1];
    $s = preg_replace('/(,\n)?\s*CONSTRAINT[^\n]+/', '', $s);
    $pdo->exec(str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $s));
}
$pdo->exec("INSERT INTO companies(id,company_name,active) VALUES(99990,'CL A',1),(99991,'CL B',1)");
$pdo->exec("INSERT INTO users(id,username,password_hash,full_name,role,company_id,active) VALUES(99990,'cl','x','CL','super_admin',NULL,1)");
$pdo->exec("INSERT INTO nonconformities(id,company_id,audit_id,source,title,description,root_cause,severity,status,responsible_person,due_date,active) VALUES
  (99990,99990,0,'audit','NC A','desc','kök neden','major','closed','Ali','2026-01-01',1)");
$pdo->exec("INSERT INTO nonconformities(id,company_id,audit_id,source,title,severity,status,active) VALUES (99991,99991,0,'audit','NC B','minor','open',1)");
$pdo->exec("INSERT INTO corrective_actions(id,nonconformity_id,action_type,action_text,responsible_person,status,verifier_name,verification_note,active) VALUES
  (99990,99990,'corrective','Düzelt','Ali','closed','Veli','doğrulandı',1)");
$pdo->exec("INSERT INTO corrective_action_evidence(id,corrective_action_id,original_file_name,stored_file_name,mime_type,file_size,note,uploaded_by,active) VALUES
  (99990,99990,'kanit.pdf','x.pdf','application/pdf',100,'kanıt notu',99990,1)");

$pkg = qmsClosurePackageData($pdo, 99990, 99990, 'super_admin');
clCheck($pkg !== [] && $pkg['nc_title'] === 'NC A', 'Package includes the nonconformity title');
clCheck($pkg['nc_root_cause'] === 'kök neden', 'Root cause captured');
clCheck(count($pkg['actions']) === 1, 'One corrective action bundled');
clCheck($pkg['actions'][0]['type'] === 'Düzeltici', 'Action type label resolved');
clCheck($pkg['actions'][0]['verification_note'] === 'doğrulandı', 'Verification note captured');
clCheck(count($pkg['actions'][0]['evidence']) === 1 && $pkg['actions'][0]['evidence'][0]['name'] === 'kanit.pdf', 'Evidence file bundled');

// Kapsam: farkli sirketin uygunsuzlugu bos doner (system_admin 99990 yalniz A'ya atanmis degil; super admin tumunu gorur -> kapsam kontrolu icin admin kuralim).
clCheck(qmsClosurePackageData($pdo, 99991, 99990, 'super_admin') !== [], 'Super admin still sees company B (unrestricted)');
$pdo->exec("INSERT INTO company_admin_assignments(company_id,admin_user_id,active) VALUES(99990,99990,1)");
clCheck(qmsClosurePackageData($pdo, 99991, 99990, 'system_admin') === [], 'Assigned admin cannot open another company closure package');

echo "\nCompleted $checks closure-package checks using temporary tables.\n";
