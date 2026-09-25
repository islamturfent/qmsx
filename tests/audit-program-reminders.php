<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
session_start();
require_once 'config/database.php';
require_once 'includes/notifications.php';
$checks = 0;
function arcCheck(bool $ok, string $name): void { global $checks; if (!$ok) throw new RuntimeException('FAIL: ' . $name); $checks++; echo 'PASS: ' . $name . PHP_EOL; }

$tables = ['companies','users','company_admin_assignments','audit_programs','audit_program_audits','audits','notifications'];
foreach ($tables as $t) {
    $s = $pdo->query("SHOW CREATE TABLE $t")->fetch(PDO::FETCH_NUM)[1];
    $s = preg_replace('/(,\n)?\s*CONSTRAINT[^\n]+/', '', $s);
    $pdo->exec(str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $s));
}
$pdo->exec("INSERT INTO companies(id,company_name,active) VALUES(99981,'AP A',1)");
$pdo->exec("INSERT INTO users(id,username,password_hash,full_name,role,company_id,active) VALUES(99981,'ap','x','AP','system_admin',NULL,1),(99982,'ap2','x','AP2','super_admin',NULL,1)");
$pdo->exec("INSERT INTO company_admin_assignments(company_id,admin_user_id,active) VALUES(99981,99981,1)");
$year = (int) date('Y');
// Guncel yil aktif program, denetimsiz -> reminder.
$pdo->exec("INSERT INTO audit_programs(id,company_id,title,year,status,active) VALUES(99981,99981,'P1',$year,'active',1)");
// Gecmis yil, hala draft -> due.
$pdo->exec("INSERT INTO audit_programs(id,company_id,title,year,status,active) VALUES(99982,99981,'P0',".($year-1).",'draft',1)");
// Guncel yil tamamlanmis -> hatirlatma yok.
$pdo->exec("INSERT INTO audit_programs(id,company_id,title,year,status,active) VALUES(99983,99981,'P2',$year,'completed',1)");

// Ilk calistirma.
ob_start(); include __DIR__ . '/../scripts/audit-program-reminders.php'; $out1 = ob_get_clean();
$rem = (int) $pdo->query("SELECT COUNT(*) FROM notifications WHERE notification_type='audit_program_reminder'")->fetchColumn();
$due = (int) $pdo->query("SELECT COUNT(*) FROM notifications WHERE notification_type='audit_program_due'")->fetchColumn();
arcCheck($rem === 1, 'Active program without audits triggers one reminder');
arcCheck($due === 1, 'Past-year draft program triggers one closure reminder');
arcCheck(strpos($out1, 'reminder: 1') !== false && strpos($out1, 'due: 1') !== false, 'Script reports both stats');

// Idempotent ikinci calistirma.
ob_start(); include __DIR__ . '/../scripts/audit-program-reminders.php'; $out2 = ob_get_clean();
arcCheck((int) $pdo->query("SELECT COUNT(*) FROM notifications WHERE notification_type='audit_program_reminder'")->fetchColumn() === 1, 'Second run does not duplicate reminder');
arcCheck(strpos($out2, 'generated: 0') !== false, 'Second run generates nothing');

$pdo->exec("DELETE FROM notifications WHERE notification_type IN ('audit_program_reminder','audit_program_due')");
echo "\nCompleted $checks audit-program-reminders checks using temporary tables.\n";
