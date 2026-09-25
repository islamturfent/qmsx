<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
session_start();
require 'config/database.php';
require 'includes/notifications.php';
$checks = 0;
function nocCheck(bool $ok, string $name): void { global $checks; if (!$ok) throw new RuntimeException('FAIL: ' . $name); $checks++; echo 'PASS: ' . $name . PHP_EOL; }

$tables = ['companies','users','company_admin_assignments','nonconformities','corrective_actions','trainings','equipment','external_audits','external_audit_findings','documents','complaints','notifications'];
foreach ($tables as $t) {
    $s = $pdo->query("SHOW CREATE TABLE $t")->fetch(PDO::FETCH_NUM)[1];
    $s = preg_replace('/(,\n)?\s*CONSTRAINT[^\n]+/', '', $s);
    $pdo->exec(str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $s));
}
$pdo->exec("INSERT INTO companies(id,company_name,active) VALUES(99941,'NO A',1)");
$pdo->exec("INSERT INTO users(id,username,password_hash,full_name,role,company_id,active) VALUES(99941,'no','x','NO','system_admin',NULL,1)");
$pdo->exec("INSERT INTO company_admin_assignments(company_id,admin_user_id,active) VALUES(99941,99941,1)");
// Bu kullanici hem sorumlu hem admin; geciken duzeltici faaliyet sorumlu kullaniciya gider.
$pdo->exec("INSERT INTO nonconformities(id,company_id,audit_id,source,title,severity,status,due_date,active) VALUES (99941,99941,0,'audit','NC','major','open','2099-01-01',1)");
$pdo->exec("INSERT INTO corrective_actions(id,nonconformity_id,action_type,action_text,responsible_user_id,due_date,status,active) VALUES (99941,99941,'corrective','Late Fix',99941,'2020-01-01','in_progress',1)");

// Ilk calistirma: bildirim uretilir.
ob_start();
include __DIR__ . '/../scripts/notify-overdue.php';
$out1 = ob_get_clean();
$n = (int) $pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id=99941 AND notification_type='overdue_action'")->fetchColumn();
nocCheck($n === 1, 'First run creates one overdue_action notification');
nocCheck(strpos($out1, 'Overdue notifications generated: 1') !== false, 'First run reports 1 generated');

// Ikinci calistirma: idempotent, yeni bildirim uretilmez.
ob_start();
include __DIR__ . '/../scripts/notify-overdue.php';
$out2 = ob_get_clean();
$n2 = (int) $pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id=99941 AND notification_type='overdue_action'")->fetchColumn();
nocCheck($n2 === 1, 'Second run does not duplicate the notification (idempotent)');
nocCheck(strpos($out2, 'Overdue notifications generated: 0') !== false, 'Second run reports 0 generated');

echo "\nCompleted $checks notify-overdue checks using temporary tables.\n";
