<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
session_start();
require 'config/database.php';
require 'includes/instrument-functions.php';
$checks = 0;
function insCheck(bool $ok, string $name): void { global $checks; if (!$ok) throw new RuntimeException('FAIL: ' . $name); $checks++; echo 'PASS: ' . $name . PHP_EOL; }

$tables = ['companies','users','company_admin_assignments','instruments'];
foreach ($tables as $t) {
    $s = $pdo->query("SHOW CREATE TABLE $t")->fetch(PDO::FETCH_NUM)[1];
    $s = preg_replace('/(,\n)?\s*CONSTRAINT[^\n]+/', '', $s);
    $pdo->exec(str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $s));
}
$pdo->exec("INSERT INTO companies(id,company_name,active) VALUES(99898,'INS A',1),(99899,'INS B',1)");
$pdo->exec("INSERT INTO users(id,username,password_hash,full_name,role,company_id,active) VALUES(99898,'ins','x','INS','super_admin',NULL,1)");

$id = qmsInstrumentAdd($pdo, ['company_id'=>99898,'instrument_code'=>'M-001','name'=>'Kumpas','instrument_type'=>'Uzunluk','location'=>'Laboratuvar','interval_months'=>6,'last_calibration_date'=>'2026-01-01','next_calibration_date'=>'2026-07-01','responsible'=>'Metroloji','status'=>'active','notes'=>''], 99898, 'super_admin');
insCheck($id !== null && $id > 0, 'Instrument added');
insCheck(count(qmsInstrumentList($pdo, 99898, 'super_admin')) === 1, 'Instrument listed');
insCheck(qmsInstrumentFind($pdo, (int)$id, 99898, 'super_admin')['name'] === 'Kumpas', 'Instrument fetched by id');

// Dogrulama redleri.
insCheck(qmsInstrumentAdd($pdo, ['company_id'=>0,'name'=>'x','status'=>'active'], 99898, 'super_admin') === null, 'Blank company rejected');
insCheck(qmsInstrumentAdd($pdo, ['company_id'=>99899,'name'=>'y','status'=>'active'], 99898, 'system_admin') === null, 'Cross-company rejected');
insCheck(qmsInstrumentAdd($pdo, ['company_id'=>99898,'name'=>'','status'=>'active'], 99898, 'super_admin') === null, 'Blank name rejected');

// Hizli kalibre et: son = bugun, sonraki = bugun+interval ay.
insCheck(qmsInstrumentCalibrate($pdo, (int)$id, 99898, 'super_admin') === true, 'Calibrate succeeds');
$after = qmsInstrumentFind($pdo, (int)$id, 99898, 'super_admin');
insCheck((string) $after['last_calibration_date'] === date('Y-m-d'), 'Last calibration set to today');
$expectedNext = date('Y-m-d', strtotime('+6 months'));
insCheck((string) $after['next_calibration_date'] === $expectedNext, 'Next calibration = today + interval months');

insCheck(qmsInstrumentUpdate($pdo, (int)$id, ['instrument_code'=>'M-002','name'=>'Kumpas v2','instrument_type'=>'','location'=>'','interval_months'=>12,'last_calibration_date'=>'','next_calibration_date'=>'','responsible'=>'','status'=>'out_of_service','notes'=>''], 99898, 'super_admin') === true, 'Instrument updated');
insCheck(qmsInstrumentFind($pdo, (int)$id, 99898, 'super_admin')['status'] === 'out_of_service', 'Status persisted to out_of_service');

// Kapsam: atanan admin kendi sirketini gorur.
$pdo->exec("INSERT INTO company_admin_assignments(company_id,admin_user_id,active) VALUES(99898,99898,1)");
insCheck(count(qmsInstrumentList($pdo, 99898, 'system_admin')) === 1, 'Assigned admin sees own instruments');
insCheck(qmsInstrumentDelete($pdo, (int)$id, 99898, 'system_admin') === true, 'Instrument deletable in scope');
insCheck(count(qmsInstrumentList($pdo, 99898, 'super_admin')) === 0, 'Deleted instrument hidden');

echo "\nCompleted $checks instrument checks using temporary tables.\n";
