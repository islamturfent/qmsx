<?php

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Periyodik yonetim raporu e-postasi (cron / zamanlanmis gorev).
 *
 * Kullanim (CLI): php scripts/send-daily-report.php
 *
 * Geciken isler + denetci is yuku + KPI'larin ozetini bir PDF olarak uretir ve
 * tum sistem adminlerine e-posta ile eklenti olarak gonderir. E-posta etkin
 * degilse "e-posta kapali" mesajiyla cikar; PDF yine de uretilir (kayit icin).
 *
 * Not: E-posta linkleri mutlak URL icin mail ayarlarinda base_url kullanilir.
 */

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/config/mail.php';
require_once dirname(__DIR__) . '/includes/access.php';
require_once dirname(__DIR__) . '/includes/due-workbench-functions.php';
require_once dirname(__DIR__) . '/includes/report-export-data.php';
require_once dirname(__DIR__) . '/includes/notifications.php';
require_once dirname(__DIR__) . '/includes/mailer.php';

$userId = 1; // yalnizca kapsam/rapor icin; super admin oldugunu varsayariz
$isSuper = true;
$today = date('Y-m-d');
$dateStamp = date('d.m.Y H:i');

// Kapsam: super admin - tumunu gorur.
$sections = qmsOverdueWorkbench($pdo, $userId, 'super_admin');
$totalOverdue = 0;
foreach ($sections as $sec) { $totalOverdue += $sec['count']; }
$auditorWorkload = qmsAuditorWorkload($pdo, $userId, 'super_admin');
$report = buildReportExportData($pdo, $userId, true, []);
$metrics = $report['metrics'];

$sectionTitles = [
    'actions' => 'Düzeltici Faaliyet', 'nonconformities' => 'Uygunsuzluk', 'trainings' => 'Eğitim',
    'equipment' => 'Ekipman Kalibrasyonu', 'findings' => 'Dış Denetim Bulgusu',
    'documents' => 'Doküman Gözden Geçirme', 'complaints' => 'Şikayet',
];
$severityLabels = ['minor' => 'Küçük', 'major' => 'Büyük', 'critical' => 'Kritik'];
$esc = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

$moduleTables = '';
foreach ($sections as $k => $sec) {
    $rows = '';
    foreach ($sec['rows'] as $r) {
        $rows .= '<tr><td>' . $esc($r['company']) . '</td><td>' . $esc($r['title']) . '</td><td>'
            . $esc(($k === 'findings' ? ($severityLabels[$r['extra']] ?? $r['extra']) : $r['extra'])) . '</td><td>'
            . $esc($r['due']) . '</td></tr>';
    }
    if ($rows === '') { $rows = '<tr><td class="muted" colspan="4">Kayıt yok.</td></tr>'; }
    $moduleTables .= '<h2>' . $esc(($sectionTitles[$k] ?? $k)) . ' (' . $sec['count'] . ')</h2>'
        . '<table class="data"><thead><tr><th>Şirket</th><th>Kayıt</th><th>Detay</th><th>Termin</th></tr></thead><tbody>' . $rows . '</tbody></table>';
}

$wlRows = '';
foreach ($auditorWorkload as $aw) {
    $wlRows .= '<tr><td>' . $esc($aw['name']) . '</td><td>' . $esc($aw['company']) . '</td><td>' . $aw['assigned_audits']
        . '</td><td>' . $aw['open_nonconformities'] . '</td><td>' . $aw['open_actions'] . '</td><td>' . $aw['total_open'] . '</td></tr>';
}
if ($wlRows === '') { $wlRows = '<tr><td class="muted" colspan="6">Denetçi yok.</td></tr>'; }

$summaryCounts = '';
foreach ($sections as $k => $sec) {
    $summaryCounts .= '<tr><td>' . $esc($sectionTitles[$k] ?? $k) . '</td><td>' . $sec['count'] . '</td></tr>';
}

$kpiCells = '';
foreach (['audit_count', 'nonconformity_rate', 'action_completion_rate', 'average_close_days', 'training_completion_rate', 'complaint_open_count', 'quality_cost_total'] as $kpi) {
    $val = $metrics[$kpi] ?? '-';
    if (is_float($val) || is_int($val)) { $val = number_format((float) $val, ($kpi === 'nonconformity_rate' || $kpi === 'action_completion_rate' || $kpi === 'training_completion_rate') ? 1 : 0, ',', '.'); }
    $kpiCells .= '<td class="metric"><span>' . $esc($kpi) . '</span><strong>' . $esc((string) $val) . '</strong></td>';
}

$base = rtrim((string) (qmsMailConfig()['base_url'] ?? ''), '/');
$overdueLink = $base !== '' ? $base . '/overdue.php' : 'overdue.php';

$html = '<!doctype html><html lang="tr"><head><meta charset="UTF-8"><style>
body { font-family: "DejaVu Sans", sans-serif; font-size: 12px; color: #344054; }
h1 { font-size: 20px; margin: 0 0 4px; } h2 { font-size: 15px; margin: 18px 0 8px; border-bottom: 1px solid #e4e7ec; padding-bottom: 6px; }
.meta { color: #667085; font-size: 11px; margin-bottom: 8px; } table { border-collapse: collapse; width: 100%; }
.data th { background: #f2f4f7; text-align: left; padding: 6px 8px; font-size: 11px; }
.data td { border: 1px solid #e4e7ec; padding: 5px 8px; font-size: 11px; }
.metrics td { padding: 6px 8px; font-size: 11px; } .muted { color: #667085; } .footer { margin-top: 24px; color: #98a2b3; font-size: 10px; }
</style></head><body>
<h1>QuAmi Günlük Yönetim Raporu</h1>
<div class="meta">' . $dateStamp . ' · Toplam geciken: ' . $totalOverdue . '</div>
<table class="metrics"><tr>' . $kpiCells . '</tr></table>
<h2>Geciken İşler Özeti</h2>
<table class="data"><thead><tr><th>Modül</th><th>Geciken</th></tr></thead><tbody>' . $summaryCounts . '</tbody></table>'
. $moduleTables
. '<h2>Denetçi İş Yükü</h2><table class="data"><thead><tr><th>Denetçi</th><th>Şirket</th><th>Atanmış Denetim</th><th>Açık Uygunsuzluk</th><th>Açık Faaliyet</th><th>Toplam</th></tr></thead><tbody>' . $wlRows . '</tbody></table>'
. '<div class="footer">Detay: ' . $esc($overdueLink) . ' · QuAmi otomatik raporu</div></body></html>';

// Dompdf ile PDF uret (temp dosyaya yazmadan once bellekten de alabiliriz; dosya ek olarak gonderilecek).
require_once dirname(__DIR__) . '/lib/dompdf/autoload.inc.php';
use Dompdf\Dompdf;
use Dompdf\Options;
$options = new Options();
$options->set('defaultFont', 'DejaVu Sans');
$options->set('isRemoteEnabled', false);
$options->setChroot(dirname(__DIR__));
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

$tmp = tempnam(sys_get_temp_dir(), 'qms-report-');
if ($tmp === false) { throw new RuntimeException('Geçici dosya oluşturulamadı.'); }
try {
    file_put_contents($tmp, $dompdf->output());
    $filename = 'qms-gunluk-rapor-' . date('Y-m-d') . '.pdf';
    $attachment = ['name' => $filename, 'path' => $tmp, 'mime' => 'application/pdf'];

    // Alticilar: aktif sistem adminleri + super adminler.
    $stmt = $pdo->prepare(
        "SELECT id, email, full_name FROM users WHERE active = 1 AND role IN ('system_admin','super_admin') AND email <> ''"
    );
    $stmt->execute();
    $recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $cfg = qmsMailConfig();
    if (!empty($recipients) && $cfg['enabled'] && $cfg['host'] !== '') {
        $sent = 0;
        foreach ($recipients as $r) {
            $ok = qmsMailSend(
                (string) $r['email'],
                (string) ($r['full_name'] ?? '') !== '' ? (string) $r['full_name'] : null,
                'QuAmi Günlük Yönetim Raporu (' . date('d.m.Y') . ')',
                '<p>Günlük rapor ektedir. Geciken işler ve denetçi iş yükü özeti.</p><p><a href="' . $esc($overdueLink) . '">Tümünü gör</a></p>',
                'Günlük rapor ektedir. Tümünü gör: ' . $overdueLink,
                [$attachment]
            );
            if ($ok) { $sent++; }
        }
        echo "Rapor PDF'i olusturuldu, $sent/" . count($recipients) . " admin'e e-posta gonderildi.\n";
    } else {
        echo "E-posta kapali veya admin yok; PDF uretildi: $filename (" . filesize($tmp) . " bayt).\n";
    }
} finally {
    @unlink($tmp);
}
