<?php

declare(strict_types=1);

session_start();

if (!isset($_SESSION['qms_logged_in']) || $_SESSION['qms_logged_in'] !== true) {
    http_response_code(403);
    exit('Yetkisiz erişim.');
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/access.php';
require_once __DIR__ . '/includes/closure-package-functions.php';
require_once __DIR__ . '/includes/audit-log-functions.php';

$userId = (int) ($_SESSION['qms_user_id'] ?? 0);
$role = qmsCurrentRole();
$ncId = (int) ($_GET['nonconformity_id'] ?? 0);

$package = $ncId > 0 ? qmsClosurePackageData($pdo, $ncId, $userId, $role) : [];
if (!$package) {
    http_response_code(404);
    exit('Uygunsuzluğa erişim yetkiniz yok.');
}

require_once __DIR__ . '/lib/dompdf/autoload.inc.php';
use Dompdf\Dompdf;
use Dompdf\Options;

$esc = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$rows = static fn(string $label, string $value): string =>
    '<tr><th>' . $esc($label) . '</th><td>' . $esc($value) . '</td></tr>';

$actionHtml = '';
foreach ($package['actions'] as $a) {
    $actionHtml .= '<h3>' . $esc($a['type']) . ' Faaliyet · ' . $esc($a['status']) . '</h3>'
        . '<table class="data small"><tbody>'
        . $rows('Açıklama', $a['action_text'])
        . $rows('Sorumlu', $a['responsible_person'])
        . $rows('Termin', $a['due_date'])
        . $rows('Tamamlanma', $a['completed_at'])
        . $rows('Kapanış', $a['closed_at'])
        . $rows('Doğrulayan', $a['verifier_name'])
        . $rows('Doğrulama Notu', $a['verification_note'])
        . $rows('Kanıt Notu', $a['evidence_note'])
        . '</tbody></table>';
    if ($a['evidence']) {
        $actionHtml .= '<p class="sub" style="margin-top:6px">Kanıt dosyaları:</p>';
        foreach ($a['evidence'] as $ev) {
            $actionHtml .= '<p class="sub">— ' . $esc($ev['name'])
                . ($ev['note'] !== '' ? ' · ' . $esc($ev['note']) : '')
                . ' · ' . $esc($ev['uploaded_by']) . '</p>';
        }
    }
}
if ($actionHtml === '') {
    $actionHtml = '<p class="muted">Bu uygunsuzluk için faaliyet kaydı bulunmuyor.</p>';
}

$html = '<!doctype html><html lang="tr"><head><meta charset="UTF-8"><style>
body { font-family: "DejaVu Sans", sans-serif; font-size: 12px; color: #1d2939; }
h1 { font-size: 20px; margin: 0 0 4px; } h2 { font-size: 16px; margin: 20px 0 8px; border-bottom: 2px solid #466cf5; padding-bottom: 6px; color:#101828; }
h3 { font-size: 13px; margin: 14px 0 6px; color:#101828; }
.meta { color:#667085; font-size:11px; margin-bottom:10px; } table { border-collapse: collapse; width: 100%; }
.data th { background:#f2f4f7; text-align:left; padding:6px 8px; width:150px; font-size:11px; }
.data th { font-weight:600; } .data.small td { padding:6px 8px; border:1px solid #e4e7ec; font-size:11px; }
.data th { border:1px solid #e4e7ec; } p.sub { color:#475467; font-size:11px; margin:2px 0; } .muted { color:#98a2b3; }
.verdict { margin-top:16px; border:1px solid #e4e7ec; border-left:4px solid #466cf5; padding:12px 14px; border-radius:8px; }
.footer { margin-top:20px; color:#98a2b3; font-size:10px; }
</style></head><body>
<h1>Uygunsuzluk Kapanış Paketi</h1>
<div class="meta">' . $package['company'] . ' · Oluşturulma: ' . date('d.m.Y H:i') . '</div>
<h2>Uygunsuzluk</h2>
<table class="data small"><tbody>'
. $rows('Başlık', $package['nc_title'])
. $rows('Açıklama', $package['nc_description'])
. $rows('Kök Neden', $package['nc_root_cause'])
. $rows('Önem', $package['severity'])
. $rows('Durum', $package['status'])
. $rows('Kaynak', $package['source'])
. $rows('Sorumlu', $package['responsible_person'])
. $rows('Termin', $package['due_date'])
. '</tbody></table>'
. '<h2>Düzeltici / Önleyici Faaliyetler</h2>' . $actionHtml
. '<div class="verdict"><strong>Sonuç:</strong> Bu paket, uygunsuzluğun kök nedeni ile planlanan/uygulanan faaliyetleri ve kanıt belgelerini içerir; kapanış için doğrulama kaydı ektedir.</div>'
. '<div class="footer">QMS tarafından yetkili kullanıcı için oluşturuldu. Kapanış paketi denetim kanıtı olarak saklanır.</div></body></html>';

$options = new Options();
$options->set('defaultFont', 'DejaVu Sans');
$options->set('isRemoteEnabled', false);
$options->setChroot(__DIR__);
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

// Denetim izi: kapanis paketi olusturuldu.
qmsAuditLog(
    $pdo,
    null,
    $userId,
    'nonconformity',
    $package['nc_id'],
    'closure_package_exported',
    'Uygunsuzluk kapanış paketi (PDF) oluşturuldu: ' . $package['nc_title'],
    ['company' => $package['company'], 'action_count' => count($package['actions'])]
);

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="kapanis-paketi-' . $package['nc_id'] . '-' . date('Y-m-d') . '.pdf"');
echo $dompdf->output();
