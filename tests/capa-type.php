<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
session_start();
require 'config/database.php';
require 'includes/capa-functions.php';
require 'includes/due-workbench-functions.php';
$checks = 0;
function ctCheck(bool $ok, string $name): void { global $checks; if (!$ok) throw new RuntimeException('FAIL: ' . $name); $checks++; echo 'PASS: ' . $name . PHP_EOL; }

foreach (['companies','users','company_admin_assignments','nonconformities','corrective_actions'] as $t) {
    $s = $pdo->query("SHOW CREATE TABLE $t")->fetch(PDO::FETCH_NUM)[1];
    $s = preg_replace('/(,\n)?\s*CONSTRAINT[^\n]+/', '', $s);
    $pdo->exec(str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $s));
}

// Migration sonucu action_type kolonu semada olmali (suite ilk adim bir sey
// yapmaz; migration'u onceden calistirmak testi besler).
$cols = array_column($pdo->query('SHOW COLUMNS FROM corrective_actions')->fetchAll(PDO::FETCH_ASSOC), 'Field');
ctCheck(in_array('action_type', $cols, true), 'corrective_actions.action_type column exists (migration applied)');

// Tur sozlugu.
$labels = qmsCapaTypeLabels();
ctCheck($labels['corrective'] === 'Düzeltici' && $labels['preventive'] === 'Önleyici', 'CAPA type labels map correctly');
ctCheck(count(qmsCapaTypeI18nKeys()) === 2, 'CAPA type i18n keys exist');

// Olusturma sayfasi gibi ekleme (action_type dahil) calisir ve okunur.
$pdo->exec("INSERT INTO companies(id, company_name, active) VALUES(99901,'CT A',1)");
$pdo->exec("INSERT INTO users(id, username, password_hash, full_name, role, company_id, active) VALUES(99901,'ct','x','CT','super_admin',NULL,1)");
$pdo->exec("INSERT INTO nonconformities(id, company_id, source, title, severity, status, due_date, active) VALUES (99901,99901,'audit','NC','major','open','2099-01-01',1)");
$pdo->exec("INSERT INTO corrective_actions(id, nonconformity_id, action_type, action_text, due_date, status, active) VALUES (99901,99901,'preventive','Prevent A','2099-01-01','planned',1)");
$read = $pdo->query("SELECT action_type FROM corrective_actions WHERE id = 99901")->fetch(PDO::FETCH_ASSOC);
ctCheck(($read['action_type'] ?? '') === 'preventive', 'Preventive action type persists through insert and read');

// Rozet sayisi duzeltici faaliyetler icindir.
$pdo->exec("INSERT INTO corrective_actions(id, nonconformity_id, action_type, action_text, due_date, status, active) VALUES (99902,99901,'corrective','Late Fix','2020-01-01','in_progress',1)");
$overdue = qmsOverdueActionCount($pdo, 99901, 'super_admin');
ctCheck($overdue === 1, 'Overdue action count for the CAPA badge = 1 (only the late open action)');

echo "\nCompleted $checks capa-type checks using temporary tables.\n";
