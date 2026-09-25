<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
session_start();
require 'config/database.php';
require_once 'config/mail.php';
require_once 'includes/notifications.php';
require_once 'includes/mailer.php';
$checks = 0;
function mlCheck(bool $ok, string $name): void { global $checks; if (!$ok) throw new RuntimeException('FAIL: ' . $name); $checks++; echo 'PASS: ' . $name . PHP_EOL; }

// Varsayilan yapilandirma: e-posta kapali.
$cfg = qmsMailConfig();
mlCheck(isset($cfg['enabled'], $cfg['host'], $cfg['port'], $cfg['from_email'], $cfg['base_url']), 'Mail config exposes expected keys');
mlCheck($cfg['enabled'] === false, 'Mail is disabled by default');

// Kapali / hostsuz durumlarda gonderme asla aga baglanmaz, false doner.
mlCheck(qmsMailSend('a@b.c', 'A', 'S', '<p>x</p>') === false, 'Disabled mail returns false without network');
putenv('QMS_MAIL_ENABLED=1');
$cfg2 = qmsMailConfig();
mlCheck($cfg2['enabled'] === true, 'Env override enables mail');
mlCheck(qmsMailSend('a@b.c', 'A', 'S', '<p>x</p>') === false, 'Enabled but empty host returns false without network');
putenv('QMS_MAIL_ENABLED'); // temizle

// E-posta icerigi uretici.
$content = qmsMailNotificationContent('Konu', 'İçerik mesajı', 'https://example.com/x');
mlCheck($content['subject'] === 'Konu', 'Notification subject passes through');
mlCheck(strpos($content['html'], 'Kaydı Aç') !== false && strpos($content['html'], 'https://example.com/x') !== false, 'HTML body contains message and link button');
mlCheck(strpos($content['plain'], 'https://example.com/x') !== false, 'Plain text includes the link');

// #4: markali sablon - kategori rozeti + uygulama linki (base_url).
$branded = qmsMailNotificationContent('Konu', 'Mesaj', 'https://example.com/x', 'Düzeltici Faaliyet');
mlCheck(strpos($branded['html'], 'Düzeltici Faaliyet') !== false, 'Branded template shows category pill');
mlCheck(strpos($branded['html'], 'Uygulamayı Aç') !== false && strpos($branded['html'], 'http://localhost/qmsx/') !== false, 'Branded template footer links to the app');

// qmsNotify e-posta kapaliyken bildirimi yine yazar (cokmesin):
// gercek tabloda temiz test kaydi acar, sonunda sileriz.
$pdo->exec("DELETE FROM notifications WHERE notification_type = 'test_mailer'");
qmsNotify($pdo, 1, 'test_mailer', 'Test', 'Mesaj', 'https://x');
$count = (int) $pdo->query("SELECT COUNT(*) FROM notifications WHERE notification_type = 'test_mailer'")->fetchColumn();
mlCheck($count === 1, 'qmsNotify still writes the notification when mail is disabled');
$pdo->exec("DELETE FROM notifications WHERE notification_type = 'test_mailer'");

echo "\nCompleted $checks mailer checks.\n";
