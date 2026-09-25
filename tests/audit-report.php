<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
session_start();
require 'config/database.php';
require 'includes/audit-report-functions.php';
$checks = 0;
function arCheck(bool $ok, string $name): void { global $checks; if (!$ok) throw new RuntimeException('FAIL: ' . $name); $checks++; echo 'PASS: ' . $name . PHP_EOL; }

// ---- Kuralli uretici mantigi (DB'siz).
$empty = qmsGenerateAuditReportText([
    'audit' => ['company_name' => 'A Şirketi', 'title' => 'Yıllık Denetim', 'audit_type' => 'İç Denetim', 'planned_date' => '2026-03-10'],
    'auditors' => ['Ali', 'Veli'],
    'checklist' => ['total' => 4, 'compliant' => 4, 'noncompliant' => 0, 'not_applicable' => 0, 'pending' => 0],
    'nonconformities' => [],
    'severity_counts' => ['critical' => 0, 'major' => 0, 'minor' => 0],
    'open_nonconformities' => 0,
    'noncompliant_items' => [],
]);
arCheck(strpos($empty['scope_text'], 'A Şirketi') !== false, 'Scope names the company');
arCheck(strpos($empty['scope_text'], 'Ali, Veli') !== false, 'Scope lists the auditors');
arCheck(strpos($empty['conclusion'], 'uygun bulunmuştur') !== false, 'Clean audit reaches a compliant conclusion');
arCheck(strpos($empty['recommendations'], 'yönetim gözden geçirmesi') !== false, 'Recommendations close the review loop');

$critical = qmsGenerateAuditReportText([
    'audit' => ['company_name' => 'A', 'title' => 'D', 'audit_type' => '', 'planned_date' => ''],
    'auditors' => [],
    'checklist' => ['total' => 3, 'compliant' => 1, 'noncompliant' => 2, 'not_applicable' => 0, 'pending' => 0],
    'nonconformities' => [['severity' => 'critical'], ['severity' => 'major']],
    'severity_counts' => ['critical' => 1, 'major' => 1, 'minor' => 0],
    'open_nonconformities' => 2,
    'noncompliant_items' => [['item_text' => 'X'], ['item_text' => 'Y']],
]);
arCheck(strpos($critical['conclusion'], 'Kritik uygunsuzluklar tespit edilmiştir') !== false, 'Critical severity drives a critical conclusion');
arCheck(strpos($critical['recommendations'], 'derhâl düzeltici faaliyet') !== false, 'Critical adds an immediate-action recommendation');
arCheck(strpos($critical['nonconformity_summary'], '(1 kritik, 1 majör)') !== false, 'Severity counts appear in the summary');
arCheck(strpos($critical['findings_text'], 'X; Y') !== false, 'Noncompliant items are listed in findings');

$manyNc = qmsGenerateAuditReportText([
    'audit' => ['company_name' => 'A', 'title' => 'D', 'audit_type' => '', 'planned_date' => ''],
    'auditors' => [],
    'checklist' => ['total' => 5, 'compliant' => 2, 'noncompliant' => 3, 'not_applicable' => 0, 'pending' => 0],
    'nonconformities' => [['severity' => 'minor'], ['severity' => 'minor'], ['severity' => 'major']],
    'severity_counts' => ['critical' => 0, 'major' => 1, 'minor' => 2],
    'open_nonconformities' => 3,
    'noncompliant_items' => [],
]);
arCheck(strpos($manyNc['conclusion'], 'önemli sayıda uygunsuzluk') !== false, 'Many NCs drive a material-concern conclusion');

// Normalizasyon: bos alanlar null'a doner.
$norm = qmsAuditReportNormalize(['title' => ' Başlık ', 'conclusion' => '', 'findings_text' => '  x  ']);
arCheck($norm['title'] === 'Başlık', 'Normalize trims and keeps the title');
arCheck($norm['conclusion'] === null, 'Empty text becomes null');
arCheck($norm['findings_text'] === 'x', 'Normalize trims body text');

// ---- Gecici tablolar (tencere verisine dokunmaz).
$tableSchemas = ['audit_reports', 'audit_log'];
foreach ($tableSchemas as $table) {
    $schema = $pdo->query("SHOW CREATE TABLE $table")->fetch(PDO::FETCH_NUM)[1];
    $schema = preg_replace('/(,\n)?\s*CONSTRAINT[^\n]+/', '', $schema);
    $pdo->exec(str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $schema));
}

$reportId = qmsSaveAuditReport($pdo, 550001, 550001, ['title' => 'Rapor', 'status' => 'bilinmeyen', 'findings_text' => 'İlk'], 7);
arCheck((string) $pdo->query("SELECT status FROM audit_reports WHERE audit_id = 550001")->fetchColumn() === 'draft', 'Unknown status is coerced to draft');
arCheck((int) $pdo->query("SELECT COUNT(*) FROM audit_reports WHERE audit_id = 550001")->fetchColumn() === 1, 'Saving stores one report row');
$reportId2 = qmsSaveAuditReport($pdo, 550001, 550001, ['title' => 'Rapor', 'status' => 'final', 'findings_text' => 'Güncel'], 7);
arCheck($reportId === $reportId2, 'Re-saving updates the same row (upsert)');
arCheck((int) $pdo->query("SELECT COUNT(*) FROM audit_reports WHERE audit_id = 550001")->fetchColumn() === 1, 'Upsert does not duplicate');
arCheck((string) $pdo->query("SELECT findings_text FROM audit_reports WHERE audit_id = 550001")->fetchColumn() === 'Güncel', 'Updated text is stored');
arCheck((string) $pdo->query("SELECT status FROM audit_reports WHERE audit_id = 550001")->fetchColumn() === 'final', 'Final status is preserved');

qmsAuditReportFinalize($pdo, $reportId, 7);
arCheck((string) $pdo->query("SELECT status FROM audit_reports WHERE id = $reportId")->fetchColumn() === 'final', 'Finalize sets final status');
arCheck((int) $pdo->query("SELECT approved_by FROM audit_reports WHERE id = $reportId")->fetchColumn() === 7, 'Finalize records the approver');

arCheck((int) $pdo->query('SELECT COUNT(*) FROM audit_reports')->fetchColumn() === 1, 'Temporary table holds only the fixtures');

// ---- Snapshot toplamlari.
$pdo->exec('CREATE TEMPORARY TABLE companies (id int primary key, company_name varchar(255) not null, active tinyint not null default 1)');
$pdo->exec('CREATE TEMPORARY TABLE users (id int primary key, full_name varchar(255), company_id int, active tinyint not null default 1)');
$pdo->exec('CREATE TEMPORARY TABLE auditors (id int primary key, company_id int, user_id int, active tinyint not null default 1)');
$pdo->exec('CREATE TEMPORARY TABLE audit_auditors (audit_id int, auditor_id int)');
$pdo->exec('CREATE TEMPORARY TABLE audits (id int primary key, company_id int not null, title varchar(180), audit_type varchar(100), planned_date date, status varchar(50), active tinyint not null default 1, created_at datetime default current_timestamp)');
$pdo->exec('CREATE TEMPORARY TABLE audit_checklist_items (id int primary key, audit_id int not null, item_text varchar(255), requirement_ref varchar(120), result_status varchar(30), notes text, active tinyint not null default 1)');
$pdo->exec('CREATE TEMPORARY TABLE nonconformities (id int primary key, company_id int not null, audit_id int not null, title varchar(255), severity varchar(30), status varchar(30), active tinyint not null default 1)');

$pdo->exec("INSERT INTO companies(id, company_name, active) VALUES (550002,'Snap Co',1)");
$pdo->exec("INSERT INTO audits(id, company_id, title, audit_type, status, active) VALUES (550002,550002,'Snap Denetim','İç', 'done',1)");
$pdo->exec("INSERT INTO users(id, full_name, company_id, active) VALUES (101,'Denetçi A',550002,1),(102,'Denetçi B',550002,1)");
$pdo->exec("INSERT INTO auditors(id, company_id, user_id, active) VALUES (9001,550002,101,1),(9002,550002,102,1)");
$pdo->exec("INSERT INTO audit_auditors(audit_id, auditor_id) VALUES (550002,9001),(550002,9002)");
$pdo->exec("INSERT INTO audit_checklist_items(id, audit_id, item_text, result_status, active) VALUES "
    . "(1,550002,'Madde 1','compliant',1),(2,550002,'Madde 2','noncompliant',1),(3,550002,'Madde 3','not_applicable',1)");
$pdo->exec("INSERT INTO nonconformities(id, company_id, audit_id, title, severity, status, active) VALUES "
    . "(1,550002,550002,'U1','critical','open',1),(2,550002,550002,'U2','minor','closed',1)");

$snap = qmsAuditReportSnapshot($pdo, 550002);
arCheck($snap['checklist']['total'] === 3, 'Snapshot counts checklist items');
arCheck($snap['checklist']['noncompliant'] === 1, 'Snapshot counts noncompliant items');
arCheck($snap['severity_counts']['critical'] === 1, 'Snapshot counts critical severity');
arCheck($snap['open_nonconformities'] === 1, 'Snapshot counts only open nonconformities');
arCheck(in_array('Denetçi A', $snap['auditors'], true) && in_array('Denetçi B', $snap['auditors'], true), 'Snapshot lists auditor names');

session_destroy();
echo "Completed $checks audit-report checks using temporary tables." . PHP_EOL;
