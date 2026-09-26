<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
session_start();
$_SESSION['qms_role'] = 'super_admin';
$_SESSION['qms_user_id'] = 99910;
require 'config/database.php';
require 'includes/audit-log-functions.php';
$checks = 0;
function atCheck(bool $ok, string $name): void { global $checks; if (!$ok) throw new RuntimeException('FAIL: ' . $name); $checks++; echo 'PASS: ' . $name . PHP_EOL; }

foreach (['companies', 'users', 'audit_log'] as $t) {
    $s = $pdo->query("SHOW CREATE TABLE $t")->fetch(PDO::FETCH_NUM)[1];
    $s = preg_replace('/(,\n)?\s*CONSTRAINT[^\n]+/', '', $s);
    $pdo->exec(str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $s));
}
$pdo->exec("INSERT INTO companies(id,company_name,active) VALUES(99910,'AT A',1),(99911,'AT B',1)");
$pdo->exec("INSERT INTO users(id,username,password_hash,full_name,role,company_id,active) VALUES(99910,'at','x','ACTOR','super_admin',NULL,1)");
$pdo->exec("INSERT INTO audit_log(company_id,actor_user_id,entity_type,entity_id,action,summary,ip_address) VALUES
    (99910,99910,'document',1,'create','doc olustu','127.0.0.1'),
    (99910,99910,'document',1,'update','doc guncellendi','127.0.0.1'),
    (99910,99910,'risk',2,'create','risk olustu','127.0.0.1'),
    (99911,99910,'complaint',3,'create','sikayet olustu','127.0.0.1')");

// Salt-ekle kayit yazma yardimcisi da dogrulanir.
qmsAuditLog($pdo, 99910, 99910, 'document', 9, 'create', 'yardimci kayit', ['k' => 'v'], '127.0.0.1');
$total = (int) $pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();
atCheck($total === 5, 'qmsAuditLog wrote an append-only record');

$rows = qmsAuditLogList($pdo, 99910, 'super_admin', []);
$agg = qmsAuditLogAggregate($rows);
atCheck($agg['total'] === 5, 'Aggregate total = 5');
atCheck($agg['entity']['document'] === 3, 'document entity count = 3');
atCheck($agg['entity']['risk'] === 1 && $agg['entity']['complaint'] === 1, 'risk/complaint entity counts = 1');
atCheck($agg['action']['create'] === 4 && $agg['action']['update'] === 1, 'create/update action counts');

// Filtre: kayit turu.
$filtered = qmsAuditLogList($pdo, 99910, 'super_admin', ['entity_type' => 'document']);
$fagg = qmsAuditLogAggregate($filtered);
atCheck($fagg['total'] === 3, 'Entity filter narrows to document = 3');
$fagg2 = qmsAuditLogAggregate(qmsAuditLogList($pdo, 99910, 'super_admin', ['action' => 'create']));
atCheck($fagg2['total'] === 4, 'Action filter narrows to create = 4');

// Tarih araligi filtreleri yalnizca onayli (YYYY-MM-DD) degerlerle uygulanir.
$fagg3 = qmsAuditLogAggregate(qmsAuditLogList($pdo, 99910, 'super_admin', ['from' => '2026-01-01', 'to' => '2099-12-31']));
atCheck($fagg3['total'] === 5, 'Valid date range returns all = 5');

echo "\nCompleted $checks audit-trail-report checks using temporary tables.\n";
