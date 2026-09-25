<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
session_start();
require 'config/database.php';
require_once 'includes/notifications.php';
$checks = 0;
function npCheck(bool $ok, string $name): void { global $checks; if (!$ok) throw new RuntimeException('FAIL: ' . $name); $checks++; echo 'PASS: ' . $name . PHP_EOL; }

foreach (['users', 'notification_preferences', 'notifications'] as $t) {
    $s = $pdo->query("SHOW CREATE TABLE $t")->fetch(PDO::FETCH_NUM)[1];
    $s = preg_replace('/(,\n)?\s*CONSTRAINT[^\n]+/', '', $s);
    $pdo->exec(str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $s));
}

// Varsayilan: kayit yoksa eposta acik, tum kategoriler.
$default = qmsMailPrefs($pdo, 1);
npCheck($default['email_enabled'] === true && $default['categories'] === null, 'Default prefs: enabled, all categories');

// E-postayi kapat.
qmsMailPrefsSave($pdo, 7, false, null);
$p = qmsMailPrefs($pdo, 7);
npCheck($p['email_enabled'] === false && $p['categories'] === null, 'Saving enabled=false persists');

// Belirli kategoriler secin.
qmsMailPrefsSave($pdo, 7, true, ['capa', 'complaint']);
$p2 = qmsMailPrefs($pdo, 7);
npCheck($p2['email_enabled'] === true, 'Re-enabling email persists');
npCheck($p2['categories'] === ['capa', 'complaint'], 'Category list persists as chosen');

// Baska kullanici etkilenmez.
npCheck(qmsMailPrefs($pdo, 8)['email_enabled'] === true, 'Other user unaffected');

// Prefs-aware gonderim: eposta kapaliyken hizli false doner (sorgu/ag yok).
npCheck(qmsMailNotifyUserPrefsAware($pdo, 7, 'overdue_action', 'x', 'y', 'z') === false, 'Prefs-aware send short-circuits when mail disabled');

// qmsNotify yine de bildirimi yazar (mail kapali).
$pdo->exec("DELETE FROM notifications WHERE notification_type='test_prefs'");
qmsNotify($pdo, 7, 'test_prefs', 'T', 'M', 'z');
npCheck((int) $pdo->query("SELECT COUNT(*) FROM notifications WHERE notification_type='test_prefs'")->fetchColumn() === 1, 'qmsNotify still writes notification');
$pdo->exec("DELETE FROM notifications WHERE notification_type='test_prefs'");

echo "\nCompleted $checks notification-preferences checks using temporary tables.\n";
