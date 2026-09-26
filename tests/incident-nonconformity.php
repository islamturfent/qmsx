<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
session_start();
require 'config/database.php';
require 'includes/incident-functions.php';
$checks = 0;
function incNcCheck(bool $ok, string $name): void { global $checks; if (!$ok) throw new RuntimeException('FAIL: ' . $name); $checks++; echo 'PASS: ' . $name . PHP_EOL; }

$tables = ['companies','users','company_admin_assignments','incidents','nonconformities'];
foreach ($tables as $t) {
    $s = $pdo->query("SHOW CREATE TABLE $t")->fetch(PDO::FETCH_NUM)[1];
    $s = preg_replace('/(,\n)?\s*CONSTRAINT[^\n]+/', '', $s);
    $pdo->exec(str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $s));
}
$pdo->exec("INSERT INTO companies(id,company_name,active) VALUES(99912,'INCN A',1)");
$pdo->exec("INSERT INTO users(id,username,password_hash,full_name,role,company_id,active) VALUES(99912,'incn','x','INCN','super_admin',NULL,1)");
$pdo->exec("INSERT INTO incidents(id, company_id, title, description, incident_type, severity, status, active) VALUES(99912,99912,'Ramak kala','Baret yok','near_miss','high','open',1)");

incNcCheck(qmsIncidentLinkedNonconformity($pdo, 99912) === 0, 'No linked nonconformity yet');

$nc = qmsIncidentCreateNonconformity($pdo, 99912, 99912, 'super_admin');
incNcCheck($nc !== null && $nc > 0, 'Nonconformity created from incident');
incNcCheck(qmsIncidentLinkedNonconformity($pdo, 99912) === (int) $nc, 'Linked nonconformity id matches');

// Idempotent: tekrar olusturmaz, ayni id doner.
incNcCheck(qmsIncidentCreateNonconformity($pdo, 99912, 99912, 'super_admin') === (int) $nc, 'Second create returns existing nonconformity');

$ncRow = $pdo->query("SELECT * FROM nonconformities WHERE id = " . (int) $nc)->fetch(PDO::FETCH_ASSOC);
incNcCheck((string) $ncRow['source'] === 'incident', 'Nonconformity source is incident');
incNcCheck((int) $ncRow['incident_id'] === 99912, 'Nonconformity links incident id');
incNcCheck((string) $ncRow['company_id'] === '99912', 'Nonconformity inherits company');

echo "\nCompleted $checks incident-nonconformity checks using temporary tables.\n";
