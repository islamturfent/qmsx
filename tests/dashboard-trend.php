<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
session_start();
require 'config/database.php';
require 'includes/dashboard-functions.php';
$checks = 0;
function dashCheck(bool $ok, string $name): void { global $checks; if (!$ok) throw new RuntimeException('FAIL: ' . $name); $checks++; echo 'PASS: ' . $name . PHP_EOL; }

// Gecici tablolar (FK'siz): trend/ozet dogrudan toplu sorgularla calisir.
$pdo->exec('CREATE TEMPORARY TABLE companies (id int primary key, company_name varchar(255) not null, active tinyint not null default 1)');
$pdo->exec('CREATE TEMPORARY TABLE users (id int primary key, username varchar(100) not null, full_name varchar(255), role varchar(30), company_id int, active tinyint not null default 1)');
$pdo->exec('CREATE TEMPORARY TABLE company_admin_assignments (company_id int, admin_user_id int, active tinyint not null default 1)');
$pdo->exec('CREATE TEMPORARY TABLE audits (id int primary key, company_id int not null, title varchar(180), status varchar(50), active tinyint not null default 1, created_at datetime default current_timestamp)');
$pdo->exec('CREATE TEMPORARY TABLE nonconformities (id int primary key, company_id int not null, audit_id int not null, title varchar(255), status varchar(30) default "open", severity varchar(30), active tinyint not null default 1, created_at datetime)');
$pdo->exec('CREATE TEMPORARY TABLE corrective_actions (id int primary key, nonconformity_id int not null, status varchar(50), due_date date, created_at datetime, completed_at datetime, active tinyint not null default 1)');
$pdo->exec('CREATE TEMPORARY TABLE trainings (id int primary key, company_id int not null, title varchar(255), status varchar(30), active tinyint not null default 1, completed_date date, created_at datetime)');
$pdo->exec('CREATE TEMPORARY TABLE complaints (id int primary key, company_id int not null, status varchar(30), active tinyint not null default 1, created_at datetime)');
$pdo->exec('CREATE TEMPORARY TABLE supplier_evaluation_schedule (id int primary key, supplier_id int, company_id int not null, status varchar(24), due_date date, active tinyint not null default 1)');
$pdo->exec('CREATE TEMPORARY TABLE quality_plans (id int primary key, company_id int not null, plan_year int not null, active tinyint not null default 1)');
$pdo->exec('CREATE TEMPORARY TABLE quality_plan_items (id int primary key, plan_id int not null, progress int not null default 0, active tinyint not null default 1)');
$pdo->exec('CREATE TEMPORARY TABLE improvements (id int primary key, company_id int not null, title varchar(190), status varchar(24), benefit_type varchar(30), impact varchar(20), priority varchar(20), active tinyint not null default 1, created_at datetime)');
$pdo->exec('CREATE TEMPORARY TABLE contracts (id int primary key, company_id int not null, contract_name varchar(190), contract_type varchar(20), start_date date, end_date date, renewal_date date, status varchar(20), active tinyint not null default 1)');

$now = date('Y-m-d H:i:s');
$today = date('Y-m-d');

$pdo->exec("INSERT INTO companies(id, company_name, active) VALUES (970001,'Dash Tenant A',1),(970002,'Dash Tenant B',1)");
// Kullanici A (company_user, A sirketine bagli) ve sistem admini (A atamali).
$pdo->exec("INSERT INTO users(id, username, full_name, role, company_id, active) VALUES "
    . "(970101,'dash_user','Dash User','company_user',970001,1),"
    . "(970102,'dash_admin','Dash Admin','system_admin',NULL,1)");
$pdo->exec('INSERT INTO company_admin_assignments(company_id, admin_user_id, active) VALUES (970001,970102,1)');

// A sirketinde 2 denetim, B'de 1; A'da 2 uygunsuzluk, B'de 1.
$pdo->exec("INSERT INTO audits(id, company_id, title, status, active, created_at) VALUES "
    . "(1,970001,'A d1','done',1,'$now'),(2,970001,'A d2','done',1,'$now'),(3,970002,'B d1','done',1,'$now')");
$pdo->exec("INSERT INTO nonconformities(id, company_id, audit_id, title, status, severity, active, created_at) VALUES "
    . "(1,970001,1,'A u1','open','major',1,'$now'),(2,970001,2,'A u2','open','minor',1,'$now'),(3,970002,3,'B u1','open','major',1,'$now')");

// Aksiyon: A'da biri tamamlanmis, biri gecikmis acik.
$pdo->exec("INSERT INTO corrective_actions(id, nonconformity_id, status, due_date, created_at, completed_at, active) VALUES "
    . "(1,1,'completed','$today','$now','$now',1),"
    . "(2,2,'open','2020-01-01','$now',NULL,1)");

// Egitim: A'da tamamlanmis 1, B'de tamamlanmis 1.
$pdo->exec("INSERT INTO trainings(id, company_id, title, status, active, completed_date, created_at) VALUES "
    . "(1,970001,'A e1','completed',1,'$today','$now'),(2,970002,'B e1','completed',1,'$today','$now')");

// Sikayet: A'da 1 acik, B'de 1.
$pdo->exec("INSERT INTO complaints(id, company_id, status, active, created_at) VALUES "
    . "(1,970001,'new',1,'$now'),(2,970002,'new',1,'$now')");

// Tedarikci degerlendirme: A'da gecikmis bir planli randevu.
$pdo->exec("INSERT INTO supplier_evaluation_schedule(id, supplier_id, company_id, status, due_date, active) VALUES "
    . "(1,1,970001,'planned','2020-01-01',1)");
// Kalite plani: A'da cari yil icin 2 kalem (ilerleme 50 + 100 -> %75).
$pdo->exec("INSERT INTO quality_plans(id, company_id, plan_year, active) VALUES (1,970001," . (int) date('Y') . ",1)");
$pdo->exec("INSERT INTO quality_plan_items(id, plan_id, progress, active) VALUES (1,1,50,1),(2,1,100,1)");
// Iyilestirme firsatlari: A'da 1 acik + 1 uygulanan.
$pdo->exec("INSERT INTO improvements(id, company_id, title, status, benefit_type, impact, priority, active, created_at) VALUES "
    . "(1,970001,'Oneri A','submitted','quality','high','high',1,'$now'),"
    . "(2,970001,'Uygulanan A','implemented','efficiency','medium','normal',1,'$now')");
// Suresi yaklasan aktif sozlesme (60 gun icinde).
$endIn60 = date('Y-m-d', strtotime('+40 days'));
$pdo->exec("INSERT INTO contracts(id, company_id, contract_name, contract_type, start_date, end_date, renewal_date, status, active) VALUES "
    . "(1,970001,'Bakım','supplier','2020-01-01','$endIn60',NULL,'active',1)");

// ---- Super admin: kisitlamasiz (null kapsam).
$trend = qmsDashboardTrend($pdo, 0, 'super_admin');
dashCheck(count($trend) === 12, 'Trend returns 12 monthly buckets');
$last = $trend[array_key_last($trend)];
dashCheck($last['audits'] === 3, 'Super admin sees audits from both tenants this month');
dashCheck($last['nonconformities'] === 3, 'Super admin sees nonconformities from both tenants');
dashCheck($last['actions_completed'] === 1, 'Completed actions are bucketed by completion month');
dashCheck($last['trainings_completed'] === 2, 'Completed trainings bucketed from both tenants');
dashCheck($last['complaints'] === 2, 'Complaints bucketed from both tenants');
dashCheck(array_key_first($trend) === date('Y-m', strtotime('-11 months')), 'First bucket is eleven months ago');

$summary = qmsDashboardSummary($pdo, 0, 'super_admin', $trend);
dashCheck(is_string($summary['headline']) && $summary['headline'] !== '', 'Summary has a headline');
$texts = implode(' ', array_column($summary['points'], 'text'));
dashCheck(strpos($texts, '3 denetim') !== false, 'Summary reports three audits');
dashCheck(strpos($texts, '1 gecikmiş aksiyon') !== false, 'Summary flags the overdue action');
$tones = array_column($summary['points'], 'tone');
dashCheck(in_array('warning', $tones, true), 'Overdue action yields a warning tone');
dashCheck(strpos($texts, 'tedarikçi değerlendirmesi gecikmiş') !== false, 'Summary flags overdue supplier evaluation');
dashCheck(strpos($texts, '%75 ilerlemede') !== false, 'Summary reports 75% quality plan progress');
dashCheck(strpos($texts, 'açık iyileştirme fırsatı var') !== false, 'Summary flags open improvement');
dashCheck(strpos($texts, 'iyileştirme fırsatı uygulandı') !== false, 'Summary reports implemented improvement');
dashCheck(strpos($texts, 'sözleşmenin süresi yaklaşıyor') !== false, 'Summary flags expiring contract');

// ---- Kapsam: company_user yalnizca kendi sirketini gorur.
$trendUser = qmsDashboardTrend($pdo, 970101, 'company_user');
$lastUser = $trendUser[array_key_last($trendUser)];
dashCheck($lastUser['audits'] === 2, 'Company user sees only its own audits');
dashCheck($lastUser['nonconformities'] === 2, 'Company user sees only its own nonconformities');
dashCheck($lastUser['complaints'] === 1, 'Company user sees only its own complaints');

// ---- Kapsam: sistem admini yalniz atandigi sirketi gorur.
$trendAdmin = qmsDashboardTrend($pdo, 970102, 'system_admin');
$lastAdmin = $trendAdmin[array_key_last($trendAdmin)];
dashCheck($lastAdmin['audits'] === 2, 'System admin sees only assigned-company audits');
$summaryAdmin = qmsDashboardSummary($pdo, 970102, 'system_admin', $trendAdmin);
$adminTexts = implode(' ', array_column($summaryAdmin['points'], 'text'));
dashCheck(strpos($adminTexts, '2 uygunsuzluk') !== false, 'System admin summary counts only its own nonconformities');

dashCheck((int) $pdo->query('SELECT COUNT(*) FROM complaints')->fetchColumn() === 2, 'Temporary table holds only the fixtures');

session_destroy();
echo "Completed $checks dashboard checks using temporary tables." . PHP_EOL;
