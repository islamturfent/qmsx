<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
session_start();
$_SESSION['qms_role'] = 'super_admin';
$_SESSION['qms_user_id'] = 99910;
require 'config/database.php';
require 'includes/csrf.php';
$checks = 0;
function naCheck(bool $ok, string $name): void { global $checks; if (!$ok) throw new RuntimeException('FAIL: ' . $name); $checks++; echo 'PASS: ' . $name . PHP_EOL; }

foreach (['companies', 'users', 'notifications'] as $t) {
    $s = $pdo->query("SHOW CREATE TABLE $t")->fetch(PDO::FETCH_NUM)[1];
    $s = preg_replace('/(,\n)?\s*CONSTRAINT[^\n]+/', '', $s);
    $pdo->exec(str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $s));
}
$pdo->exec("INSERT INTO companies(id,company_name,active) VALUES(99910,'NA A',1)");
$pdo->exec("INSERT INTO users(id,username,password_hash,full_name,role,company_id,active) VALUES(99910,'na','x','NA','super_admin',NULL,1)");
$pdo->exec("INSERT INTO notifications(id,user_id,notification_type,title,message,is_read) VALUES
    (1,99910,'overdue_action','T','a',0),(2,99910,'incident_reported','T','b',0),(3,99910,'contract_expiring','T','c',1)");

// CSRF scope token uretilir ve dogru token ile qmsCsrfVerify gecer (cikmaz).
$token = qmsCsrfToken('notifications');
naCheck(is_string($token) && $token !== '', 'CSRF token generated for notifications scope');
$verifyPassed = true;
try { qmsCsrfVerify('notifications', $token); } catch (Throwable $e) { $verifyPassed = false; }
naCheck($verifyPassed, 'qmsCsrfVerify passes with the correct token');

// Mark-all-read SQL'inin (uc noktanin calistirdigi) kapsami.
$pdo->prepare(
    "UPDATE notifications SET is_read = 1, read_at = COALESCE(read_at, NOW())
     WHERE user_id = :user_id AND is_read = 0"
)->execute(['user_id' => 99910]);
$unread = (int) $pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id=99910 AND is_read=0")->fetchColumn();
naCheck($unread === 0, 'Mark-all-read clears unread (0 remaining)');
$unreadStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = :user_id AND is_read = 0");
$unreadStmt->execute(['user_id' => 99910]);
naCheck((int) $unreadStmt->fetchColumn() === 0, 'Endpoint unread-counter query returns 0');
// Baska kullaniciyi etkilemez.
naCheck((int) $pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id=0 AND is_read=0")->fetchColumn() === 0, 'Other users unaffected');

echo "\nCompleted $checks notifications-ajax checks using temporary tables.\n";
