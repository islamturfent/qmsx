<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
session_start();
require 'config/database.php';
require 'includes/complaint-functions.php';
$checks = 0;
function ncCheck(bool $ok, string $name): void { global $checks; if (!$ok) throw new RuntimeException('FAIL: ' . $name); $checks++; echo 'PASS: ' . $name . PHP_EOL; }

// Gercek tablolarin semasi gecici tablolara kopyalanir; FK'lar cikarilir.
foreach (['companies', 'users', 'company_admin_assignments', 'audits', 'nonconformities', 'corrective_actions', 'complaints', 'notifications'] as $table) {
    $schema = $pdo->query("SHOW CREATE TABLE $table")->fetch(PDO::FETCH_NUM)[1];
    $schema = preg_replace('/(,\n)?\s*CONSTRAINT[^\n]+/', '', $schema);
    $pdo->exec(str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $schema));
}

$pdo->exec("INSERT INTO companies(id, company_name, active) VALUES(941001,'NC Tenant A',1),(941002,'NC Tenant B',1)");
$pdo->exec("INSERT INTO audits(id, company_id, title, status, active) VALUES(941001, 941001, 'NC audit A', 'planned', 1)");
$pdo->exec("INSERT INTO nonconformities(id, company_id, audit_id, title, severity, status, active) VALUES
    (941001, 941001, 941001, 'NC A', 'major', 'open', 1)");
$pdo->exec("INSERT INTO complaints(id, company_id, complaint_code, subject, source, severity, status, received_date, nonconformity_id, active) VALUES
    (941001, 941001, 'SK-1', 'Linked complaint', 'customer', 'major', 'new', '2026-05-01', 941001, 1),
    (941002, 941002, 'SK-2', 'Unlinked complaint', 'customer', 'critical', 'in_review', '2026-05-02', NULL, 1)");

$linked = ['id' => 941001, 'company_id' => 941001, 'subject' => 'Linked complaint', 'description' => 'd', 'severity' => 'major', 'nonconformity_id' => 941001];
$unlinked = ['id' => 941002, 'company_id' => 941002, 'subject' => 'Unlinked complaint', 'description' => 'A field complaint', 'severity' => 'critical', 'nonconformity_id' => 0];

$before = (int) $pdo->query('SELECT COUNT(*) FROM nonconformities')->fetchColumn();

// 1) Bagli olmayan sikayetten yeni NC olusur ve baglanir.
$nc = qmsComplaintCreateNonconformity($pdo, $unlinked, 9000);
ncCheck($nc !== null && $nc > 0, 'Unlinked complaint creates a nonconformity');
$row = $pdo->query("SELECT * FROM nonconformities WHERE id = " . (int) $nc)->fetch(PDO::FETCH_ASSOC);
ncCheck((int) $row['audit_id'] === 0 && $row['audit_id'] === null, 'Complaint-created NC has a NULL audit id');
ncCheck($row['source'] === 'complaint', 'Complaint-created NC is marked with source complaint');
ncCheck($row['title'] === 'Unlinked complaint', 'NC title mirrors the complaint subject');
ncCheck($row['severity'] === 'critical', 'NC inherits the complaint severity');
ncCheck($row['status'] === 'open' && (int) $row['active'] === 1, 'NC is created open and active');
ncCheck((int) $row['company_id'] === 941002, 'NC belongs to the complaint company');
$link = (int) $pdo->query('SELECT nonconformity_id FROM complaints WHERE id = 941002')->fetchColumn();
ncCheck($link === (int) $nc, 'Complaint is linked to the newly created NC');

// 2) Zaten bagli bir sikayet yeni kayit acamaz.
$nc2 = qmsComplaintCreateNonconformity($pdo, $linked, 9000);
ncCheck($nc2 === null, 'Already-linked complaint does not create a second NC');
$after = (int) $pdo->query('SELECT COUNT(*) FROM nonconformities')->fetchColumn();
ncCheck($after === $before + 1, 'No extra NC row is created for a linked complaint');

// 3) Gecersiz / bos girdiler islem yapmaz.
ncCheck(qmsComplaintCreateNonconformity($pdo, ['id' => 0, 'company_id' => 941002, 'subject' => 'x', 'severity' => 'major'], 9000) === null, 'Invalid complaint id is rejected');
ncCheck(qmsComplaintCreateNonconformity($pdo, ['id' => 941001, 'company_id' => 941001, 'subject' => '', 'severity' => 'major', 'nonconformity_id' => 0], 9000) === null, 'Blank subject creates nothing');
$finalCount = (int) $pdo->query('SELECT COUNT(*) FROM nonconformities')->fetchColumn();
ncCheck($finalCount === $before + 1, 'Rejected attempts leave the NC set unchanged');

echo "\nCompleted $checks nonconformity-from-complaint checks using temporary tables.\n";
