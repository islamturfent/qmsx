<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
session_start();
require 'config/database.php';
require 'includes/external-audit-functions.php';
$checks = 0;
function eaCheck(bool $ok, string $name): void { global $checks; if (!$ok) throw new RuntimeException('FAIL: ' . $name); $checks++; echo 'PASS: ' . $name . PHP_EOL; }

// Gercek tablolarin semasi gecici tablolara kopyalanir; FK'lar cikarilir.
foreach (['companies','users','company_admin_assignments','external_audits','external_audit_findings'] as $table) {
    $schema = $pdo->query("SHOW CREATE TABLE $table")->fetch(PDO::FETCH_NUM)[1];
    $schema = preg_replace('/(,\n)?\s*CONSTRAINT[^\n]+/', '', $schema);
    $pdo->exec(str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $schema));
}

$pdo->exec("INSERT INTO companies(id, company_name, active) VALUES(99101,'EA A',1),(99102,'EA B',1)");
$pdo->exec("INSERT INTO users(id, username, password_hash, full_name, role, company_id, active) VALUES
    (99101,'ea-admin','x','EA Admin','system_admin',NULL,1),
    (99102,'ea-root','x','EA Root','super_admin',NULL,1),
    (99104,'ea-other','x','EA Other','system_admin',NULL,1)");
$pdo->exec("INSERT INTO company_admin_assignments(company_id, admin_user_id, active) VALUES(99101,99101,1),(99102,99104,1)");
$pdo->exec("INSERT INTO external_audits(id, company_id, audit_type, title, status, audit_date, active) VALUES
    (99101,99101,'certification','Cert A','conducted','2026-05-01',1),
    (99102,99102,'customer','Cust B','planned','2026-07-01',1)");

// ---- Gecikme turetim.
$today = '2026-06-15';
eaCheck(qmsFindingIsOverdue('closed', '2020-01-01', $today) === false, 'Closed finding is not overdue');
eaCheck(qmsFindingIsOverdue('open', '2020-01-01', $today) === true, 'Open finding past due is overdue');
eaCheck(qmsFindingIsOverdue('in_progress', '2020-01-01', $today) === true, 'In-progress finding past due is overdue');
eaCheck(qmsFindingIsOverdue('open', '2035-01-01', $today) === false, 'Open finding within due is not overdue');
eaCheck(qmsFindingIsOverdue('open', null, $today) === false, 'Finding without due date is not overdue');

// ---- Kapsam.
$list = qmsExternalAuditList($pdo, 99101, 'system_admin');
eaCheck(count($list) === 1 && (int) $list[0]['id'] === 99101, 'Assigned admin lists only own-company audits');
eaCheck(count(qmsExternalAuditList($pdo, 99102, 'super_admin')) === 2, 'Super admin lists every audit');
eaCheck((int) qmsExternalAuditFind($pdo, 99101, 99101, 'system_admin')['id'] === 99101, 'Assigned admin reads own audit');
eaCheck(qmsExternalAuditFind($pdo, 99102, 99101, 'system_admin') === [], 'Assigned admin cannot read another tenant audit');

// ---- Bulgu ekleme.
$fid = qmsExternalAddFinding($pdo, 99101, ['finding_text' => 'Bulgu A', 'category' => 'major', 'due_date' => '2026-05-20', 'notes' => 'x'], 99101, 'system_admin');
eaCheck($fid !== null && $fid > 0, 'Valid finding is added');
$findings = qmsExternalFindings($pdo, 99101);
eaCheck(count($findings) === 1 && $findings[0]['overdue'] === true, 'Finding is listed with overdue flag');
eaCheck(qmsExternalAddFinding($pdo, 99101, ['finding_text' => '', 'category' => 'major'], 99101, 'system_admin') === null, 'Blank finding text is rejected');
eaCheck(qmsExternalAddFinding($pdo, 99101, ['finding_text' => 'X', 'category' => 'bad'], 99101, 'system_admin') === null, 'Invalid category is rejected');
eaCheck(qmsExternalAddFinding($pdo, 99102, ['finding_text' => 'X', 'category' => 'minor'], 99101, 'system_admin') === null, 'Another tenant audit cannot gain a finding');

// ---- Durum guncelleme (kapama).
eaCheck(qmsExternalUpdateFindingStatus($pdo, 99101, $fid, 'closed', 99101, 'system_admin') === true, 'Finding can be closed');
$closedDate = $pdo->query('SELECT closed_date FROM external_audit_findings WHERE id = ' . (int) $fid)->fetchColumn();
eaCheck((string) $closedDate === date('Y-m-d'), 'Closing sets the closure date');
eaCheck(qmsExternalFindings($pdo, 99101)[0]['overdue'] === false, 'Closed finding is no longer overdue');
eaCheck(qmsExternalUpdateFindingStatus($pdo, 99101, $fid, 'open', 99101, 'system_admin') === true, 'Finding can be reopened');
$closedAfter = $pdo->query('SELECT closed_date FROM external_audit_findings WHERE id = ' . (int) $fid)->fetchColumn();
eaCheck($closedAfter === null, 'Reopening clears the closure date');
eaCheck(qmsExternalUpdateFindingStatus($pdo, 99101, 999999, 'closed', 99101, 'system_admin') === false, 'Unknown finding cannot be updated');

echo "\nCompleted $checks external-audit checks using temporary tables.\n";
