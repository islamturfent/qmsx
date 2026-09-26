<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
session_start();
$_SESSION['qms_role'] = 'super_admin';
$_SESSION['qms_user_id'] = 99910;
require 'config/database.php';
require 'includes/report-export-data.php';
$checks = 0;
function ateCheck(bool $ok, string $name): void { global $checks; if (!$ok) throw new RuntimeException('FAIL: ' . $name); $checks++; echo 'PASS: ' . $name . PHP_EOL; }

foreach (['companies', 'users', 'audit_log'] as $t) {
    $s = $pdo->query("SHOW CREATE TABLE $t")->fetch(PDO::FETCH_NUM)[1];
    $s = preg_replace('/(,\n)?\s*CONSTRAINT[^\n]+/', '', $s);
    $pdo->exec(str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $s));
}
$pdo->exec("INSERT INTO companies(id,company_name,active) VALUES(99910,'ATE A',1),(99911,'ATE B',1)");
$pdo->exec("INSERT INTO users(id,username,password_hash,full_name,role,company_id,active) VALUES(99910,'ate','x','ATE','super_admin',NULL,1)");
$pdo->exec("INSERT INTO audit_log(company_id,actor_user_id,entity_type,entity_id,action,summary,created_at) VALUES
    (99910,99910,'document',1,'create','doc','2026-09-01 10:00:00'),
    (99910,99910,'document',1,'update','doc2','2026-09-02 10:00:00'),
    (99910,99910,'risk',2,'create','risk','2026-09-03 10:00:00'),
    (99911,99910,'complaint',3,'create','sikayet','2026-09-04 10:00:00')");

$report = buildReportExportData($pdo, 99910, true, ['start_date' => '2026-09-01', 'end_date' => '2026-09-30']);
ateCheck(isset($report['audit_trail_list']) && count($report['audit_trail_list']) === 4, 'audit_trail_list has 4 records');
ateCheck($report['metrics']['audit_trail_count'] === 4, 'audit_trail_count metric = 4');
ateCheck(isset($report['audit_trail_entity']['document']) && $report['audit_trail_entity']['document'] === 2, 'audit_trail_entity.document = 2');
ateCheck(isset($report['audit_trail_action']['create']) && $report['audit_trail_action']['create'] === 3, 'audit_trail_action.create = 3');
// Kayit turu ve islem etiketleri donusturuldu.
$first = $report['audit_trail_list'][0]; // id DESC -> en yeni (complaint)
ateCheck($first['entity_label'] === 'Şikayet' && $first['action_label'] === 'Oluşturma', 'Labels translated for first record');

// Secili sirket filtresinde yalniz o sirketin kayitlari.
$reportB = buildReportExportData($pdo, 99910, true, ['company_id' => 99911, 'start_date' => '2026-09-01', 'end_date' => '2026-09-30']);
ateCheck($reportB['metrics']['audit_trail_count'] === 1, 'Company filter restricts audit trail to 1 record');

echo "\nCompleted $checks audit-trail-export checks using temporary tables.\n";
