<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
session_start();
require 'config/database.php';
require 'includes/incident-functions.php';
$checks = 0;
function incCheck(bool $ok, string $name): void { global $checks; if (!$ok) throw new RuntimeException('FAIL: ' . $name); $checks++; echo 'PASS: ' . $name . PHP_EOL; }

$tables = ['companies','users','company_admin_assignments','incidents'];
foreach ($tables as $t) {
    $s = $pdo->query("SHOW CREATE TABLE $t")->fetch(PDO::FETCH_NUM)[1];
    $s = preg_replace('/(,\n)?\s*CONSTRAINT[^\n]+/', '', $s);
    $pdo->exec(str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $s));
}
$pdo->exec("INSERT INTO companies(id,company_name,active) VALUES(99896,'INC A',1),(99897,'INC B',1)");
$pdo->exec("INSERT INTO users(id,username,password_hash,full_name,role,company_id,active) VALUES(99896,'inc','x','INC','super_admin',NULL,1)");

$id = qmsIncidentAdd($pdo, ['company_id'=>99896,'incident_code'=>'I-001','title'=>'Ramak kala','description'=>'Baret kullanılmadı','location'=>'Üretim','incident_type'=>'near_miss','severity'=>'high','reported_at'=>'2026-09-01','responsible'=>'Vardiya Amiri','status'=>'open','notes'=>''], 99896, 'super_admin');
incCheck($id !== null && $id > 0, 'Incident added');
incCheck(count(qmsIncidentList($pdo, 99896, 'super_admin')) === 1, 'Incident listed');
incCheck(qmsIncidentFind($pdo, (int)$id, 99896, 'super_admin')['title'] === 'Ramak kala', 'Incident fetched by id');

// Dogrulama redleri.
incCheck(qmsIncidentAdd($pdo, ['company_id'=>0,'title'=>'x','incident_type'=>'other','severity'=>'low','status'=>'open'], 99896, 'super_admin') === null, 'Blank company rejected');
incCheck(qmsIncidentAdd($pdo, ['company_id'=>99897,'title'=>'y','incident_type'=>'other','severity'=>'low','status'=>'open'], 99896, 'system_admin') === null, 'Cross-company rejected');
incCheck(qmsIncidentAdd($pdo, ['company_id'=>99896,'title'=>'','incident_type'=>'other','severity'=>'low','status'=>'open'], 99896, 'super_admin') === null, 'Blank title rejected');

incCheck(qmsIncidentUpdate($pdo, (int)$id, ['incident_code'=>'I-002','title'=>'Ramak kala v2','description'=>'','location'=>'','incident_type'=>'accident','severity'=>'critical','reported_at'=>'','responsible'=>'','status'=>'investigation','notes'=>''], 99896, 'super_admin') === true, 'Incident updated');
incCheck(qmsIncidentFind($pdo, (int)$id, 99896, 'super_admin')['status'] === 'investigation', 'Status persisted to investigation');
incCheck(qmsIncidentFind($pdo, (int)$id, 99896, 'super_admin')['severity'] === 'critical', 'Severity persisted to critical');

// Kapsam: atanan admin kendi sirketini gorur.
$pdo->exec("INSERT INTO company_admin_assignments(company_id,admin_user_id,active) VALUES(99896,99896,1)");
incCheck(count(qmsIncidentList($pdo, 99896, 'system_admin')) === 1, 'Assigned admin sees own incidents');
incCheck(qmsIncidentDelete($pdo, (int)$id, 99896, 'system_admin') === true, 'Incident deletable in scope');
incCheck(count(qmsIncidentList($pdo, 99896, 'super_admin')) === 0, 'Deleted incident hidden');

echo "\nCompleted $checks incident checks using temporary tables.\n";
