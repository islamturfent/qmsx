<?php

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * SMTP test e-postasi gonderir.
 * Kullanim: php scripts/test-mail.php alici@ornek.com
 * Yapilandirma: config/mail.php veya storage/mail/settings.json (mail-settings.php sayfasi).
 */

require_once dirname(__DIR__) . '/config/mail.php';
require_once dirname(__DIR__) . '/includes/mailer.php';

$to = $argv[1] ?? '';
if ($to === '') {
    fwrite(STDERR, "Kullanim: php scripts/test-mail.php alici@ornek.com\n");
    exit(2);
}

$cfg = qmsMailConfig();
echo 'E-posta ' . ($cfg['enabled'] ? 'AÇIK' : 'KAPALI') . " | host=" . ($cfg['host'] !== '' ? $cfg['host'] . ':' . $cfg['port'] : '—') . "\n";
if (!$cfg['enabled'] || $cfg['host'] === '') {
    fwrite(STDERR, "SMTP yapilandirilmamis. Once mail-settings.php sayfasindan ayarlayin\n");
    exit(1);
}

$content = qmsMailNotificationContent('QMS SMTP Testi', 'Bu mesaj SMTP uzerinden gonderildi. Tebrikler, e-posta calisiyor.', 'https://localhost/qmsx/');
$sent = qmsMailSend($to, null, $content['subject'], $content['html'], $content['plain']);
echo $sent ? "Gonderildi: $to\n" : "Gonderilemedi: $to (smtp ayarlarini kontrol edin)\n";
exit($sent ? 0 : 1);
