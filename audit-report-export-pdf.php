<?php

declare(strict_types=1);

session_start();

if (!isset($_SESSION['qms_logged_in']) || $_SESSION['qms_logged_in'] !== true) {
    http_response_code(403);
    exit('Yetkisiz erişim.');
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/audit-report-functions.php';
require_once __DIR__ . '/lib/dompdf/autoload.inc.php';

use Dompdf\Dompdf;
use Dompdf\Options;

$reportId = (int) ($_GET['id'] ?? 0);
$userId = (int) ($_SESSION['qms_user_id'] ?? 0);

$scope = qmsAuditRecordScope($pdo, $userId, 'audits.id', 'audits.company_id');
$reportStmt = $pdo->prepare(
    'SELECT audit_reports.*, audits.title AS audit_title, audits.audit_type, audits.planned_date,
            audits.status AS audit_status, companies.company_name
     FROM audit_reports
     INNER JOIN audits ON audits.id = audit_reports.audit_id
     INNER JOIN companies ON companies.id = audit_reports.company_id
     WHERE audit_reports.id = ? AND audit_reports.active = 1' . $scope['sql'] . ' LIMIT 1'
);
$reportStmt->execute(array_merge([$reportId], $scope['params']));
$report = $reportStmt->fetch(PDO::FETCH_ASSOC);
if (!$report) {
    http_response_code(404);
    exit('Rapor bulunamadı.');
}

$escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$section = static fn(string $title, string $body): string => $body === ''
    ? ''
    : '<h2>' . $escape($title) . '</h2><p>' . nl2br($escape($body)) . '</p>';

$snapshot = json_decode((string) ($report['source_snapshot'] ?? 'null'), true);
$checklist = $snapshot['checklist'] ?? null;

$html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #1d2939; }
    h1 { font-size: 20px; margin: 0 0 2px; }
    .meta { color: #667085; font-size: 11px; margin-bottom: 18px; }
    h2 { font-size: 14px; border-bottom: 1px solid #e4e7ec; padding-bottom: 4px; margin: 18px 0 8px; }
    p { line-height: 1.5; margin: 0 0 10px; white-space: pre-wrap; }
    table.data { width: 100%; border-collapse: collapse; margin: 8px 0; }
    table.data th, table.data td { border: 1px solid #d0d5dd; padding: 6px 8px; text-align: left; font-size: 11px; }
    table.data th { background: #f2f4f7; }
    .footer { margin-top: 24px; font-size: 10px; color: #98a2b3; text-align: center; }
    .badge { font-size: 11px; color: #344054; }
    </style></head><body>
    <h1>' . $escape($report['title']) . '</h1>
    <div class="meta">' . $escape($report['company_name']) . ' · Denetim: ' . $escape($report['audit_title'])
        . (($report['audit_type'] ?? '') !== '' ? ' · Tür: ' . $escape($report['audit_type']) : '')
        . (($report['report_date'] ?? '') !== '' ? ' · Rapor: ' . $escape($report['report_date']) : '')
        . ($report['status'] === 'final' ? ' · Kesinleşmiş' : ' · Taslak')
        . '</div>'
    . ($checklist
        ? '<table class="data"><tr>'
            . '<th>Madde</th><th>Uygun</th><th>Uygun Değil</th><th>Uygulanamaz</th><th>Bekliyor</th>'
            . '<th>Uygunsuzluk</th>'
            . '</tr><tr>'
            . '<td>' . (int) ($checklist['total'] ?? 0) . '</td>'
            . '<td>' . (int) ($checklist['compliant'] ?? 0) . '</td>'
            . '<td>' . (int) ($checklist['noncompliant'] ?? 0) . '</td>'
            . '<td>' . (int) ($checklist['not_applicable'] ?? 0) . '</td>'
            . '<td>' . (int) ($checklist['pending'] ?? 0) . '</td>'
            . '<td>' . (int) ($snapshot['nonconformity_count'] ?? 0) . '</td>'
            . '</tr></table>'
        : '')
    . $section('Kapsam', (string) $report['scope_text'])
    . $section('Yöntem', (string) $report['methodology_text'])
    . $section('Bulgular', (string) $report['findings_text'])
    . $section('Uygunsuzluk Özeti', (string) $report['nonconformity_summary'])
    . $section('Sonuç', (string) $report['conclusion'])
    . $section('Öneriler', (string) $report['recommendations'])
    . '<div class="footer">QMS · ' . $escape($report['company_name']) . ' · Yetkili kullanıcı için oluşturulmuştur.</div>'
    . '</body></html>';

$options = new Options();
$options->set('defaultFont', 'DejaVu Sans');
$options->set('isRemoteEnabled', false);
$options->setChroot(__DIR__);
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$dompdf->stream('denetim-raporu-' . $reportId . '.pdf', ['Attachment' => true]);
