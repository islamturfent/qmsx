<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
session_start();
require 'config/database.php';
require 'includes/search-functions.php';
$checks = 0;
function seCheck(bool $ok, string $name): void { global $checks; if (!$ok) throw new RuntimeException('FAIL: ' . $name); $checks++; echo 'PASS: ' . $name . PHP_EOL; }

// Anahtar kelime bolucu.
seCheck(qmsSearchKeywords('  Kalibrasyon Tartı   ') === ['kalibrasyon', 'tartı'], 'Keywords are lowercased, trimmed and split');
seCheck(qmsSearchKeywords('ab') === [], 'Terms shorter than 3 chars are dropped');

// Gecici tablolar (minimal, FK'siz).
$pdo->exec('CREATE TEMPORARY TABLE companies (id int primary key, company_name varchar(255) not null, active tinyint not null default 1)');
$pdo->exec('CREATE TEMPORARY TABLE users (id int primary key, username varchar(100) not null, full_name varchar(255), company_id int, role varchar(30), active tinyint not null default 1)');
$pdo->exec('CREATE TEMPORARY TABLE company_admin_assignments (company_id int, admin_user_id int, active tinyint not null default 1)');
$pdo->exec('CREATE TEMPORARY TABLE nonconformities (id int primary key, company_id int not null, audit_id int not null, title varchar(255), description text, active tinyint not null default 1)');
$pdo->exec('CREATE TEMPORARY TABLE complaints (id int primary key, company_id int not null, subject varchar(255), description text, active tinyint not null default 1)');
$pdo->exec('CREATE TEMPORARY TABLE risks (id int primary key, company_id int not null, title varchar(255), description text, active tinyint not null default 1)');
$pdo->exec('CREATE TEMPORARY TABLE corrective_actions (id int primary key, nonconformity_id int not null, action_text varchar(255), active tinyint not null default 1)');
$pdo->exec('CREATE TEMPORARY TABLE documents (id int primary key, company_id int not null, title varchar(255), description text, active tinyint not null default 1)');
$pdo->exec('CREATE TEMPORARY TABLE suppliers (id int primary key, company_id int not null, name varchar(255), category varchar(100), active tinyint not null default 1)');
$pdo->exec('CREATE TEMPORARY TABLE trainings (id int primary key, company_id int not null, title varchar(255), description text, active tinyint not null default 1)');
$pdo->exec('CREATE TEMPORARY TABLE equipment (id int primary key, company_id int not null, name varchar(255), category varchar(100), active tinyint not null default 1)');

$pdo->exec("INSERT INTO companies(id, company_name, active) VALUES (790001,'Search A',1),(790002,'Search B',1)");
$pdo->exec("INSERT INTO users(id, username, full_name, role, active) VALUES (790101,'s_admin','S Admin','system_admin',1),(790102,'s_b_admin','S B Admin','system_admin',1)");
$pdo->exec('INSERT INTO company_admin_assignments(company_id, admin_user_id, active) VALUES (790001,790101,1),(790002,790102,1)');

$pdo->exec("INSERT INTO nonconformities(id, company_id, audit_id, title, description, active) VALUES "
    . "(1,790001,1,'Tartı uygunsuzluğu','Tartı kalibrasyonu gecikmiş',1)");
$pdo->exec("INSERT INTO complaints(id, company_id, subject, description, active) VALUES (1,790001,'Tartı şikayeti','kalibrasyon eksikliği',1)");
$pdo->exec("INSERT INTO risks(id, company_id, title, description, active) VALUES (1,790001,'Kalibrasyon riski','ölçüm hatası',1)");
$pdo->exec("INSERT INTO corrective_actions(id, nonconformity_id, action_text, active) VALUES (1,1,'Tartı kalibrasyonu gecikmiş faaliyet',1),(2,1,'Termometre değişimi',1)");
$pdo->exec("INSERT INTO equipment(id, company_id, name, category, active) VALUES (1,790001,'Tartı','Ölçüm',1)");

// Tek tip arama + tenant izolasyonu.
$rows = qmsSearchType($pdo, 'nonconformity', 790101, 'system_admin', ['kalibrasyon'], null, 5);
seCheck(count($rows) === 1 && (int) $rows[0]['id'] === 1, 'Nonconformity search matches by keyword');
$rowsB = qmsSearchType($pdo, 'nonconformity', 790102, 'system_admin', ['kalibrasyon'], null, 5);
seCheck(count($rowsB) === 0, 'Other tenant sees no match (scope isolation)');

// Join'li tip (duzeltici faaliyet, sirketi uygunsuzluktan alir).
$caRows = qmsSearchType($pdo, 'corrective_action', 790101, 'system_admin', ['kalibrasyon'], null, 5);
seCheck(count($caRows) === 1 && (int) $caRows[0]['id'] === 1, 'Corrective action searched through its nonconformity company');

// Genel arama birden cok tipi kapsar.
$all = qmsSearchRecords($pdo, 790101, 'system_admin', 'kalibrasyon tartı', null, 5);
$types = array_unique(array_column($all, 'type'));
seCheck(in_array('nonconformity', $types, true) && in_array('complaint', $types, true) && in_array('equipment', $types, true), 'General search covers multiple record types');
seCheck((int) $all[0]['score'] >= (int) $all[count($all) - 1]['score'], 'Results are ordered by score');

// Tip filtresi.
$ncsOnly = qmsSearchRecords($pdo, 790101, 'system_admin', 'kalibrasyon', 'nonconformity', 5);
seCheck(array_unique(array_column($ncsOnly, 'type')) === ['nonconformity'], 'Type filter restricts the search');

// Benzer vaka: anahtar kelimeleri paylasan, dislanan kayit olmadan.
$similar = qmsSimilarCases($pdo, 790101, 'system_admin', 'kalibrasyon tartı', 'nonconformity', 1, 10);
seCheck($similar !== [], 'Similar cases returned');
$hasComplaint = false;
foreach ($similar as $row) { if ($row['type'] === 'complaint') $hasComplaint = true; }
seCheck($hasComplaint, 'Similar cases find related complaints sharing keywords');

seCheck((int) $pdo->query('SELECT COUNT(*) FROM equipment')->fetchColumn() === 1, 'Temporary table holds only the fixtures');

session_destroy();
echo "Completed $checks search checks using temporary tables." . PHP_EOL;
