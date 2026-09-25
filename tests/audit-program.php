<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
session_start();
require 'config/database.php';
require 'includes/audit-program-functions.php';
$checks = 0;
function apCheck(bool $ok, string $name): void { global $checks; if (!$ok) throw new RuntimeException('FAIL: ' . $name); $checks++; echo 'PASS: ' . $name . PHP_EOL; }

// Durum etiketleri tum akisi kapsar.
$labels = qmsAuditProgramStatusLabels();
apCheck(array_keys($labels) === QMS_AUDIT_PROGRAM_STATUSES, 'Status labels cover the documented flow');
apCheck(in_array('draft', QMS_AUDIT_PROGRAM_STATUSES, true) && in_array('completed', QMS_AUDIT_PROGRAM_STATUSES, true), 'Flow runs draft -> active -> completed');

// Gecici tablolar (FK'siz).
foreach (['audit_programs', 'audit_program_audits'] as $table) {
    $schema = $pdo->query("SHOW CREATE TABLE $table")->fetch(PDO::FETCH_NUM)[1];
    $schema = preg_replace('/(,\n)?\s*CONSTRAINT[^\n]+/', '', $schema);
    $pdo->exec(str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $schema));
}
$pdo->exec('CREATE TEMPORARY TABLE companies (id int primary key, company_name varchar(255) not null, active tinyint not null default 1)');
$pdo->exec('CREATE TEMPORARY TABLE users (id int primary key, username varchar(100) not null, full_name varchar(255), company_id int, role varchar(30), active tinyint not null default 1)');
$pdo->exec('CREATE TEMPORARY TABLE company_admin_assignments (company_id int, admin_user_id int, active tinyint not null default 1)');
$pdo->exec('CREATE TEMPORARY TABLE audits (id int primary key, company_id int not null, title varchar(180), audit_type varchar(100), status varchar(50), planned_date date, active tinyint not null default 1, created_at datetime default current_timestamp)');

$pdo->exec("INSERT INTO companies(id, company_name, active) VALUES (770001,'Prog A',1),(770002,'Prog B',1)");
$pdo->exec("INSERT INTO users(id, username, full_name, role, active) VALUES (770101,'prog_admin','Prog Admin','system_admin',1),(770102,'prog_b_admin','Prog B Admin','system_admin',1)");
$pdo->exec('INSERT INTO company_admin_assignments(company_id, admin_user_id, active) VALUES (770001,770101,1),(770002,770102,1)');
$pdo->exec("INSERT INTO audits(id, company_id, title, status, active) VALUES (1,770001,'A d1','planned',1),(2,770002,'B d1','planned',1),(3,770001,'A d2','done',1)");
$pdo->exec("INSERT INTO audit_programs(id, company_id, title, year, status, active) VALUES (1,770001,'2026 Program',2026,'draft',1)");

// Kapsam: super admin gorur.
$prog = qmsAuditProgramFind($pdo, 1, 0, 'super_admin');
apCheck(isset($prog['id']) && (int) $prog['id'] === 1, 'Super admin reads the program');
apCheck((int) $prog['company_id'] === 770001, 'Program is tied to its company');

// Kapsam: A admini gorur, B admini goremez.
apCheck((int) (qmsAuditProgramFind($pdo, 1, 770101, 'system_admin')['id'] ?? 0) === 1, 'Assigned admin of A reads the program');
apCheck(qmsAuditProgramFind($pdo, 1, 770102, 'system_admin') === [], 'Assigned admin of B cannot read program A');

// Baglama: ayni sirket olur, baska sirket olmaz.
apCheck(qmsAuditProgramLinkAudit($pdo, 1, 1, 770001, 770101, 'system_admin') === true, 'Audit of the same company can be linked');
apCheck((int) $pdo->query('SELECT COUNT(*) FROM audit_program_audits WHERE program_id = 1')->fetchColumn() === 1, 'Linked audit is stored');
apCheck(qmsAuditProgramLinkAudit($pdo, 1, 2, 770001, 770101, 'system_admin') === false, 'Foreign-company audit is rejected');
apCheck(qmsAuditProgramLinkAudit($pdo, 1, 3, 770001, 770101, 'system_admin') === true, 'Another same-company audit links');
apCheck((int) $pdo->query('SELECT COUNT(*) FROM audit_program_audits WHERE program_id = 1')->fetchColumn() === 2, 'Two audits linked');

// Liste: denetim sayisi hesaplanir.
$list = qmsAuditProgramList($pdo, 770101, 'system_admin');
apCheck((int) $list[0]['audit_count'] === 2, 'List counts linked audits');
apCheck((int) $list[0]['done_count'] === 1, 'List counts completed (done) audits');

// Bagli denetimler ve cikarma.
$linked = qmsAuditProgramAudits($pdo, 1);
apCheck(count($linked) === 2, 'Linked audits are listed');
qmsAuditProgramUnlinkAudit($pdo, 1, 1);
apCheck((int) $pdo->query('SELECT COUNT(*) FROM audit_program_audits WHERE program_id = 1 AND audit_id = 1')->fetchColumn() === 0, 'Unlink removes the audit link');

apCheck((int) $pdo->query('SELECT COUNT(*) FROM audit_program_audits')->fetchColumn() === 1, 'Temporary table holds only the fixtures');

session_destroy();
echo "Completed $checks audit-program checks using temporary tables." . PHP_EOL;
