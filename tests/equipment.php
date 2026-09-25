<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
session_start();
require 'config/database.php';
require 'includes/equipment-functions.php';
$checks = 0;
function eqCheck(bool $ok, string $name): void { global $checks; if (!$ok) throw new RuntimeException('FAIL: ' . $name); $checks++; echo 'PASS: ' . $name . PHP_EOL; }

// Kalibrasyon durumu sonraki termin tarihinden turetilir.
$today = date('Y-m-d');
$past = date('Y-m-d', strtotime('-10 days'));
$dueSoon = date('Y-m-d', strtotime('+10 days'));
$far = date('Y-m-d', strtotime('+90 days'));
eqCheck(qmsEquipmentCalibrationStatus(['next_calibration_date' => '']) === 'not_scheduled', 'No next date means not scheduled');
eqCheck(qmsEquipmentCalibrationStatus(['next_calibration_date' => $past]) === 'overdue', 'A past next date is overdue');
eqCheck(qmsEquipmentCalibrationStatus(['next_calibration_date' => $dueSoon]) === 'due_soon', 'A near next date is due soon');
eqCheck(qmsEquipmentCalibrationStatus(['next_calibration_date' => $far]) === 'calibrated', 'A far next date is calibrated');

// Gecici tablolar (FK'siz).
foreach (['equipment', 'calibrations'] as $table) {
    $schema = $pdo->query("SHOW CREATE TABLE $table")->fetch(PDO::FETCH_NUM)[1];
    $schema = preg_replace('/(,\n)?\s*CONSTRAINT[^\n]+/', '', $schema);
    $pdo->exec(str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $schema));
}
$pdo->exec('CREATE TEMPORARY TABLE companies (id int primary key, company_name varchar(255) not null, active tinyint not null default 1)');
$pdo->exec('CREATE TEMPORARY TABLE users (id int primary key, username varchar(100) not null, full_name varchar(255), company_id int, role varchar(30), active tinyint not null default 1)');
$pdo->exec('CREATE TEMPORARY TABLE company_admin_assignments (company_id int, admin_user_id int, active tinyint not null default 1)');

$pdo->exec("INSERT INTO companies(id, company_name, active) VALUES (780001,'Eq A',1),(780002,'Eq B',1)");
$pdo->exec("INSERT INTO users(id, username, full_name, role, active) VALUES (780101,'eq_admin','Eq Admin','system_admin',1),(780102,'eq_b_admin','Eq B Admin','system_admin',1)");
$pdo->exec('INSERT INTO company_admin_assignments(company_id, admin_user_id, active) VALUES (780001,780101,1),(780002,780102,1)');
$pdo->exec("INSERT INTO equipment(id, company_id, name, status, next_calibration_date, active) VALUES "
    . "(1,780001,'Tartı','operational','$far',1),(2,780002,'Termometre','operational','$past',1),(3,780001,'Manometre','out_of_service','$far',1)");

// Kapsam: super admin hepsini, A admini A sirketini gorur.
eqCheck((int) (qmsEquipmentFind($pdo, 1, 0, 'super_admin')['id'] ?? 0) === 1, 'Super admin reads equipment');
eqCheck((int) (qmsEquipmentFind($pdo, 1, 780101, 'system_admin')['id'] ?? 0) === 1, 'Assigned admin of A reads its equipment');
eqCheck(qmsEquipmentFind($pdo, 1, 780102, 'system_admin') === [], 'Assigned admin of B cannot read equipment A');

// Liste durum turetir.
$list = qmsEquipmentList($pdo, 0, 'super_admin');
$byId = [];
foreach ($list as $row) { $byId[(int) $row['id']] = $row['cal_status']; }
eqCheck($byId[1] === 'calibrated', 'List derives calibrated status');
eqCheck($byId[2] === 'overdue', 'List derives overdue status');
eqCheck($byId[3] === 'calibrated', 'Out-of-service equipment still derives calibration status');

// Kalibrasyon tarihleri ekipmanin son/sonraki terminini gunceller (detay sayfasindaki gibi).
$x = $pdo->prepare("UPDATE equipment SET last_calibration_date = ?, next_calibration_date = ? WHERE id = ?");
$x->execute([$past, $dueSoon, 1]);
eqCheck(qmsEquipmentCalibrationStatus(qmsEquipmentFind($pdo, 1, 0, 'super_admin')) === 'due_soon', 'Updated next date changes the derived status');

eqCheck((int) $pdo->query('SELECT COUNT(*) FROM equipment')->fetchColumn() === 3, 'Temporary table holds only the fixtures');

session_destroy();
echo "Completed $checks equipment checks using temporary tables." . PHP_EOL;
