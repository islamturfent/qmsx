<?php

/**
 * Bağımlılıksız minimal SMTP e-posta istermi (XAMPP/Windows uyumlu).
 *
 * PHPMailer gibi bir kutuphane kullanmaz; SMTP'yi TCP soket uzerinden konusur.
 * Yapilandirma config/mail.php'den gelir. E-posta gonderilemezse istisna
 * FIRLATILMAZ; false doner (bildirim akisini bozmaz).
 */

require_once __DIR__ . '/../config/mail.php';

/** SMTP e-postasi gonderir. Basariliysa true, degilse false (sessiz). */
function qmsMailSend(string $to, ?string $toName, string $subject, string $htmlBody, string $plainText = ''): bool
{
    $cfg = qmsMailConfig();
    if (!$cfg['enabled'] || $cfg['host'] === '' || $cfg['from_email'] === '') {
        return false;
    }
    try {
        $fp = @stream_socket_client(
            'tcp://' . $cfg['host'] . ':' . (int) $cfg['port'],
            $errno,
            $errstr,
            (int) $cfg['timeout']
        );
        if (!$fp) {
            return false;
        }
        stream_set_timeout($fp, (int) $cfg['timeout']);

        $read = static function ($fp) {
            $line = '';
            while (($chunk = @fgets($fp, 515)) !== false) {
                $line = $chunk;
                if (strlen($chunk) >= 4 && $chunk[3] !== '-') {
                    break;
                }
            }
            return rtrim($line, "\r\n");
        };
        $write = static function ($fp, string $cmd) {
            @fwrite($fp, $cmd . "\r\n");
        };

        $write($fp, 'EHLO qmsx');
        $read($fp);

        if ($cfg['encryption'] === 'tls') {
            $write($fp, 'STARTTLS');
            $read($fp);
            $crypto = @stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if (!$crypto) {
                @fclose($fp);
                return false;
            }
            $write($fp, 'EHLO qmsx');
            $read($fp);
        }

        if ($cfg['username'] !== '') {
            $write($fp, 'AUTH LOGIN');
            $read($fp);
            $write($fp, base64_encode($cfg['username']));
            $read($fp);
            $write($fp, base64_encode($cfg['password'] ?? ''));
            if (strpos($read($fp), '2') !== 0) {
                @fclose($fp);
                return false;
            }
        }

        $from = $cfg['from_email'];
        $write($fp, 'MAIL FROM:<' . $from . '>');
        $read($fp);
        $write($fp, 'RCPT TO:<' . $to . '>');
        $read($fp);
        $write($fp, 'DATA');
        $read($fp);

        $boundary = 'qms_' . bin2hex(random_bytes(10));
        $fromNameEnc = '=?UTF-8?B?' . base64_encode($cfg['from_name']) . '?=';
        $subjectEnc = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $toDisplay = $toName ? '=?UTF-8?B?' . base64_encode($toName) . '?= <' . $to . '>' : $to;
        $plain = $plainText !== '' ? $plainText : trim((string) preg_replace('/<[^>]+>/', ' ', $htmlBody));

        $headers = "From: " . $fromNameEnc . " <" . $from . ">\r\n"
            . "To: " . $toDisplay . "\r\n"
            . "Subject: " . $subjectEnc . "\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: multipart/alternative; boundary=\"" . $boundary . "\"\r\n"
            . "Date: " . date('r') . "\r\n";

        $body = "--" . $boundary . "\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($plain)) . "\r\n"
            . "--" . $boundary . "\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($htmlBody)) . "\r\n"
            . "--" . $boundary . "--\r\n";

        @fwrite($fp, $headers . "\r\n" . $body . "\r\n.\r\n");
        $read($fp);
        $write($fp, 'QUIT');
        @fclose($fp);
        return true;
    } catch (Throwable $e) {
        if (isset($fp) && is_resource($fp)) {
            @fclose($fp);
        }
        return false;
    }
}

/**
 * Bildirim icin hazir e-posta govdesi (basit HTML + duz metin).
 *
 * @return array{subject: string, html: string, plain: string}
 */
function qmsMailNotificationContent(string $title, string $message, ?string $linkUrl): array
{
    $html = '<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:24px;font-family:Arial,Helvetica,sans-serif">'
        . '<tr><td><div style="max-width:520px;margin:0 auto;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e4e7ec">'
        . '<div style="background:#466cf5;color:#ffffff;padding:18px 24px;font-size:16px;font-weight:bold">QMS Bildirimi</div>'
        . '<div style="padding:24px"><h2 style="color:#101828;margin:0 0 8px;font-size:18px">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h2>'
        . '<p style="color:#475467;font-size:14px;line-height:1.5">' . nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8')) . '</p>';
    if ($linkUrl) {
        $html .= '<p style="margin-top:20px"><a href="' . htmlspecialchars($linkUrl, ENT_QUOTES, 'UTF-8') . '" style="background:#466cf5;color:#ffffff;text-decoration:none;padding:10px 18px;border-radius:8px;display:inline-block">Kaydı Aç</a></p>';
    }
    $html .= '</div></div></td></tr></table>';
    return ['subject' => $title, 'html' => $html, 'plain' => $title . "\n\n" . $message . ($linkUrl ? "\n\nKaydı aç: " . $linkUrl : '')];
}
