<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
session_start();
require 'config/database.php';
require 'includes/personnel-functions.php';
$checks = 0;
function pfCheck(bool $ok, string $name): void { global $checks; if (!$ok) throw new RuntimeException('FAIL: ' . $name); $checks++; echo 'PASS: ' . $name . PHP_EOL; }

// Gercek tablolarin semasi gecici tablolara kopyalanir; FK'lar cikarilir.
foreach (['companies','users','company_admin_assignments','staff_members','staff_competencies'] as $table) {
    $schema = $pdo->query("SHOW CREATE TABLE $table")->fetch(PDO::FETCH_NUM)[1];
    $schema = preg_replace('/(,\n)?\s*CONSTRAINT[^\n]+/', '', $schema);
    $pdo->exec(str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $schema));
}

$pdo->exec("INSERT INTO companies(id, company_name, active) VALUES(99001,'PF A',1),(99002,'PF B',1)");
$pdo->exec("INSERT INTO users(id, username, password_hash, full_name, role, company_id, active) VALUES
    (99001,'pf-admin','x','PF Admin','system_admin',NULL,1),
    (99002,'pf-root','x','PF Root','super_admin',NULL,1),
    (99004,'pf-other','x','PF Other','system_admin',NULL,1)");
$pdo->exec("INSERT INTO company_admin_assignments(company_id, admin_user_id, active) VALUES(99001,99001,1),(99002,99004,1)");
$pdo->exec("INSERT INTO staff_members(id, company_id, first_name, last_name, employee_code, active) VALUES
    (99001,99001,'Ali','Veli','P-01',1),
    (99002,99002,'Ayse','Fatma','P-02',1)");

// ---- Durum turetim.
$today = '2026-06-15';
pfCheck(qmsCompetencyStatus(null, $today) === 'not_scheduled', 'Null next date is not scheduled');
pfCheck(qmsCompetencyStatus('2020-01-01', $today) === 'expired', 'Past date is expired');
pfCheck(qmsCompetencyStatus('2026-06-20', $today) === 'due_soon', 'Date within the window is due soon');
pfCheck(qmsCompetencyStatus('2035-01-01', $today) === 'ok', 'Far future date is ok');

// ---- Kapsamli liste / bulma.
$list = qmsPersonnelList($pdo, 99001, 'system_admin');
pfCheck(count($list) === 1 && (int) $list[0]['id'] === 99001, 'Assigned admin lists only own-company personnel');
pfCheck(count(qmsPersonnelList($pdo, 99002, 'super_admin')) === 2, 'Super admin lists every personnel');
pfCheck((int) qmsPersonnelFind($pdo, 99001, 99001, 'system_admin')['id'] === 99001, 'Assigned admin reads own staff');
pfCheck(qmsPersonnelFind($pdo, 99002, 99001, 'system_admin') === [], 'Assigned admin cannot read another tenant staff');

// ---- Yetkinlik ekleme.
$cid = qmsPersonnelAddCompetency($pdo, 99001, [
    'competency_name' => 'Kalite Denetimi', 'level' => 4, 'achieved_date' => '2026-01-01',
    'next_assessment_date' => '2027-01-01', 'notes' => 'uzman',
], 99001, 'system_admin');
pfCheck($cid !== null && $cid > 0, 'Valid competency is added');
$comps = qmsPersonnelCompetencies($pdo, 99001);
pfCheck(count($comps) === 1 && $comps[0]['level'] === 4, 'Competency is listed with level');
pfCheck((int) qmsPersonnelFind($pdo, 99001, 99001, 'system_admin')['competency_count'] === 1, 'Staff competency count reflects the add');

// ---- Dogrulama redleri.
pfCheck(qmsPersonnelAddCompetency($pdo, 99001, ['competency_name' => '', 'level' => 3], 99001, 'system_admin') === null, 'Blank competency name is rejected');
pfCheck(qmsPersonnelAddCompetency($pdo, 99001, ['competency_name' => 'X', 'level' => 9], 99001, 'system_admin') === null, 'Out-of-range level is rejected');
pfCheck(qmsPersonnelAddCompetency($pdo, 99001, ['competency_name' => 'X', 'level' => 3, 'next_assessment_date' => 'bad'], 99001, 'system_admin') === null, 'Invalid date is rejected');

// ---- Cross-tenant add / remove.
pfCheck(qmsPersonnelAddCompetency($pdo, 99002, ['competency_name' => 'X', 'level' => 3], 99001, 'system_admin') === null, 'Another tenant staff cannot gain a competency');
pfCheck(qmsPersonnelRemoveCompetency($pdo, 99001, $cid, 99001, 'system_admin') === true, 'Competency is removable');
pfCheck(qmsPersonnelRemoveCompetency($pdo, 99001, 999999, 99001, 'system_admin') === false, 'Unknown competency cannot be removed');

echo "\nCompleted $checks personnel checks using temporary tables.\n";
