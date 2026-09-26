<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
session_start();
$_SESSION['qms_role'] = 'super_admin';
$_SESSION['qms_user_id'] = 99910;
require 'config/database.php';
require 'includes/instrument-functions.php';
require 'includes/instrument-calibration-functions.php';
require 'includes/report-export-data.php';
$checks = 0;
function icCheck(bool $ok, string $name): void { global $checks; if (!$ok) throw new RuntimeException('FAIL: ' . $name); $checks++; echo 'PASS: ' . $name . PHP_EOL; }

foreach (['companies', 'users', 'company_admin_assignments', 'instruments', 'instrument_calibrations'] as $t) {
    $s = $pdo->query("SHOW CREATE TABLE $t")->fetch(PDO::FETCH_NUM)[1];
    $s = preg_replace('/(,\n)?\s*CONSTRAINT[^\n]+/', '', $s);
    $pdo->exec(str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $s));
}
$pdo->exec("INSERT INTO companies(id,company_name,active) VALUES(99910,'IC A',1),(99911,'IC B',1)");
$pdo->exec("INSERT INTO users(id,username,password_hash,full_name,role,company_id,active) VALUES(99910,'ic','x','IC','super_admin',NULL,1),(99911,'icadm','x','IC A','system_admin',99910,1)");

$instrumentId = qmsInstrumentAdd($pdo, ['company_id' => 99910, 'name' => 'Kumpas', 'instrument_code' => 'K-01', 'interval_months' => 12, 'status' => 'active'], 99910, 'super_admin');
icCheck($instrumentId !== null, 'Instrument created');

// Dosyasiz basari kaydi: aletin son/sonraki kalibrasyon tarihini gunceller.
$id = qmsInstCalibAdd($pdo, ['instrument_id' => $instrumentId, 'calibration_date' => '2026-10-01', 'due_date' => '2027-10-01', 'result' => 'pass', 'lab_name' => 'Lab X', 'cert_number' => 'C-1001', 'performed_by' => 'Ali'], [], 99910, 'super_admin');
icCheck($id !== null && $id > 0, 'Calibration record added');
$inst = qmsInstrumentFind($pdo, (int) $instrumentId, 99910, 'super_admin');
icCheck((string) $inst['last_calibration_date'] === '2026-10-01' && (string) $inst['next_calibration_date'] === '2027-10-01', 'Instrument calibration dates updated');
icCheck(count(qmsInstCalibList($pdo, 99910, 'super_admin')) === 1, 'Calibration listed');
icCheck(count(qmsInstCalibList($pdo, 99910, 'super_admin', (int) $instrumentId)) === 1, 'Instrument filter matches');
icCheck(count(qmsInstCalibList($pdo, 99910, 'super_admin', 0, 'fail')) === 0, 'Pass record excluded from fail filter');

// Basarisiz kayit eklenir ve 'fail' filtreleri calisir.
$failId = qmsInstCalibAdd($pdo, ['instrument_id' => $instrumentId, 'calibration_date' => '2026-09-01', 'due_date' => '2027-09-01', 'result' => 'fail', 'cert_number' => 'C-1002'], [], 99910, 'super_admin');
icCheck($failId !== null, 'Fail calibration record added');
icCheck(count(qmsInstCalibList($pdo, 99910, 'super_admin', 0, 'fail')) === 1, 'Fail filter matches one record');
icCheck(qmsInstCalibResultLabel('fail') === 'Başarısız', 'Result label for fail');

// Hatali dogrulama.
icCheck(qmsInstCalibAdd($pdo, ['instrument_id' => $instrumentId, 'calibration_date' => ''], [], 99910, 'super_admin') === null, 'Calibration date required');
icCheck(qmsInstCalibAdd($pdo, ['instrument_id' => 999900, 'calibration_date' => '2026-10-01'], [], 99910, 'super_admin') === null, 'Unknown instrument rejected');
// Geçersiz dosya: hatali upload null doner ve kayit olusturulmaz.
$bad = ['error' => UPLOAD_ERR_PARTIAL, 'name' => 'x.pdf', 'size' => 5, 'tmp_name' => ''];
icCheck(qmsInstCalibAdd($pdo, ['instrument_id' => $instrumentId, 'calibration_date' => '2026-12-01'], $bad, 99910, 'super_admin') === null, 'Invalid file upload rejected');

// Kapsam: atanmis admin baska sirketin aletine kayit ekleyemez.
$pdo->exec("INSERT INTO instruments(id,company_id,name,status,active) VALUES(99999,99911,'Ozel Alet','active',1)");
icCheck(qmsInstCalibAdd($pdo, ['instrument_id' => 99999, 'calibration_date' => '2026-10-01'], [], 99911, 'system_admin') === null, 'Cross-company calibration rejected');

// Sertifika dosyasi yokken indirme bos doner.
icCheck(qmsInstCalibDownload($pdo, (int) $id, 99910, 'super_admin') === [], 'No certificate -> download empty');

// Silme (aktif=0) listeyi temizler ve sertifika dosyasi temizlenir.
icCheck(qmsInstCalibDelete($pdo, (int) $failId, 99910, 'super_admin') === true, 'Calibration record deleted');

// Rapor entegrasyonu: calibration_list + metrikler.
$report = buildReportExportData($pdo, 99910, true, ['start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
icCheck(isset($report['calibration_list']) && count($report['calibration_list']) === 1, 'Report calibration_list reflects remaining active record');
icCheck(isset($report['metrics']['calibration_fail']), 'Report has calibration_fail metric');

echo "\nCompleted $checks instrument-calibration checks using temporary tables.\n";
