<?php

declare(strict_types=1);

session_start();

if (!isset($_SESSION['qms_logged_in']) || $_SESSION['qms_logged_in'] !== true) {
    http_response_code(403);
    exit('Yetkisiz erişim.');
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/access.php';
require_once __DIR__ . '/includes/due-workbench-functions.php';

$userId = (int) ($_SESSION['qms_user_id'] ?? 0);
if (qmsIsAuditor()) {
    http_response_code(403);
    exit('Denetçi rolü bu raporu dışa aktaramaz.');
}
$role = qmsCurrentRole();
$format = (string) ($_GET['format'] ?? 'xlsx');
if (!in_array($format, ['xlsx', 'pdf'], true)) {
    $format = 'xlsx';
}

$sections = qmsOverdueWorkbench($pdo, $userId, $role);
$totalOverdue = 0;
foreach ($sections as $sec) {
    $totalOverdue += $sec['count'];
}
$auditorWorkload = qmsAuditorWorkload($pdo, $userId, $role);

$sectionTitles = [
    'actions' => 'Düzeltici Faaliyet',
    'nonconformities' => 'Uygunsuzluk',
    'trainings' => 'Eğitim',
    'equipment' => 'Ekipman Kalibrasyonu',
    'findings' => 'Dış Denetim Bulgusu',
    'documents' => 'Doküman Gözden Geçirme',
    'complaints' => 'Şikayet',
];
$severityLabels = ['minor' => 'Küçük', 'major' => 'Büyük', 'critical' => 'Kritik'];
$dateStamp = date('d.m.Y H:i');
$esc = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

if ($format === 'xlsx') {
    require_once __DIR__ . '/includes/xlsx-writer.php';

    $sheetMeta = [
        'actions' => ['Düzeltici Faaliyet', 4],
        'nonconformities' => ['Uygunsuzluk', 4],
        'trainings' => ['Eğitim', 4],
        'equipment' => ['Kalibrasyon', 4],
        'findings' => ['Bulgular', 4],
        'documents' => ['Doküman GGR', 4],
        'complaints' => ['Şikayet', 4],
    ];

    $summaryRows = [
        [['value' => 'QMS Vadesi Gelen / Geciken isler raporu', 'style' => 1]],
        ['Oluşturulma', $dateStamp],
        ['Toplam geciken', $totalOverdue],
        ['Denetçi sayısı', count($auditorWorkload)],
        [],
        [['value' => 'Modül', 'style' => 2], ['value' => 'Geciken', 'style' => 2]],
    ];
    foreach ($sections as $k => $sec) {
        $summaryRows[] = [$sectionTitles[$k] ?? $k, $sec['count']];
    }

    $sheets = [['name' => 'Özet', 'xml' => xlsxWorksheet($summaryRows, [34, 16])]];
    foreach ($sections as $k => $sec) {
        $cols = ['Şirket', 'Kayıt', 'Detay', 'Termin'];
        $rows = [[['value' => 'Şirket', 'style' => 2], ['value' => 'Kayıt', 'style' => 2], ['value' => 'Detay', 'style' => 2], ['value' => 'Termin', 'style' => 2]]];
        foreach ($sec['rows'] as $r) {
            $rows[] = [
                ['value' => $r['company'], 'style' => 3],
                ['value' => $r['title'], 'style' => 3],
                ['value' => ($k === 'findings' ? ($severityLabels[$r['extra']] ?? $r['extra']) : $r['extra']), 'style' => 3],
                ['value' => $r['due'], 'style' => 3],
            ];
        }
        if (count($rows) === 1) {
            $rows[] = [['value' => '— Kayıt yok —', 'style' => 3]];
        }
        $sheets[] = ['name' => $sheetMeta[$k][0], 'xml' => xlsxWorksheet($rows, [30, 44, 22, 14])];
        unset($cols);
    }

    $workloadRows = [[
        ['value' => 'Denetçi', 'style' => 2],
        ['value' => 'Şirket', 'style' => 2],
        ['value' => 'Atanmış Denetim', 'style' => 2],
        ['value' => 'Açık Uygunsuzluk', 'style' => 2],
        ['value' => 'Açık Faaliyet', 'style' => 2],
        ['value' => 'Toplam', 'style' => 2],
    ]];
    foreach ($auditorWorkload as $aw) {
        $workloadRows[] = [
            ['value' => $aw['name'], 'style' => 3],
            ['value' => $aw['company'], 'style' => 3],
            ['value' => $aw['assigned_audits'], 'style' => 3],
            ['value' => $aw['open_nonconformities'], 'style' => 3],
            ['value' => $aw['open_actions'], 'style' => 3],
            ['value' => $aw['total_open'], 'style' => 3],
        ];
    }
    if (count($workloadRows) === 1) {
        $workloadRows[] = [['value' => '— Denetçi yok —', 'style' => 3]];
    }
    $sheets[] = ['name' => 'Denetçi İş Yükü', 'xml' => xlsxWorksheet($workloadRows, [24, 24, 16, 16, 14, 12])];

    $temporaryPath = tempnam(sys_get_temp_dir(), 'qms-overdue-');
    if ($temporaryPath === false) {
        throw new RuntimeException('Geçici dosya oluşturulamadı.');
    }
    try {
        createXlsxFile($sheets, $temporaryPath);
        $filename = 'qms-geciken-isler-' . date('Y-m-d') . '.xlsx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($temporaryPath));
        header('Cache-Control: private, no-store, max-age=0');
        readfile($temporaryPath);
    } finally {
        @unlink($temporaryPath);
    }
    exit;
}

// PDF ozeti.
require_once __DIR__ . '/lib/dompdf/autoload.inc.php';
use Dompdf\Dompdf;
use Dompdf\Options;

$moduleTables = '';
foreach ($sections as $k => $sec) {
    $rowsHtml = '';
    foreach ($sec['rows'] as $r) {
        $rowsHtml .= '<tr><td>' . $esc($r['company']) . '</td><td>' . $esc($r['title']) . '</td><td>'
            . $esc(($k === 'findings' ? ($severityLabels[$r['extra']] ?? $r['extra']) : $r['extra'])) . '</td><td>'
            . $esc($r['due']) . '</td></tr>';
    }
    if ($rowsHtml === '') {
        $rowsHtml = '<tr><td class="muted" colspan="4">Kayıt yok.</td></tr>';
    }
    $moduleTables .= '<h2>' . $esc($sectionTitles[$k] ?? $k) . ' (' . $sec['count'] . ')</h2>'
        . '<table class="data"><thead><tr><th>Şirket</th><th>Kayıt</th><th>Detay</th><th>Termin</th></tr></thead><tbody>'
        . $rowsHtml . '</tbody></table>';
}

$workloadRowsHtml = '';
foreach ($auditorWorkload as $aw) {
    $workloadRowsHtml .= '<tr><td>' . $esc($aw['name']) . '</td><td>' . $esc($aw['company']) . '</td><td>'
        . $aw['assigned_audits'] . '</td><td>' . $aw['open_nonconformities'] . '</td><td>'
        . $aw['open_actions'] . '</td><td>' . $aw['total_open'] . '</td></tr>';
}
if ($workloadRowsHtml === '') {
    $workloadRowsHtml = '<tr><td class="muted" colspan="6">Denetçi yok.</td></tr>';
}

$summaryCounts = '';
foreach ($sections as $k => $sec) {
    $summaryCounts .= '<tr><td>' . $esc($sectionTitles[$k] ?? $k) . '</td><td>' . $sec['count'] . '</td></tr>';
}

$html = '<!doctype html><html lang="tr"><head><meta charset="UTF-8"><style>
body { font-family: "DejaVu Sans", sans-serif; font-size: 12px; color: #344054; }
h1 { font-size: 20px; margin: 0 0 4px; } h2 { font-size: 15px; margin: 20px 0 8px; border-bottom: 1px solid #e4e7ec; padding-bottom: 6px; }
.meta { color: #667085; font-size: 11px; margin-bottom: 8px; } table { border-collapse: collapse; width: 100%; }
.data th { background: #f2f4f7; text-align: left; padding: 7px 8px; font-size: 11px; }
.data td { border: 1px solid #e4e7ec; padding: 6px 8px; font-size: 11px; }
.summary td { padding: 4px 10px; } .muted { color: #667085; } .footer { margin-top: 24px; color: #98a2b3; font-size: 10px; }
</style></head><body>
<h1>QMS Vadesi Gelen / Geciken İşler</h1>
<div class="meta">Oluşturulma: ' . $dateStamp . ' · Toplam geciken: ' . $totalOverdue . '</div>
<h2>Modül Özeti</h2>
<table class="summary"><tr><th style="text-align:left">Modül</th><th style="text-align:right">Geciken</th></tr>' . $summaryCounts . '</table>'
. $moduleTables
. '<h2>Denetçi İş Yükü</h2><table class="data"><thead><tr><th>Denetçi</th><th>Şirket</th><th>Atanmış Denetim</th><th>Açık Uygunsuzluk</th><th>Açık Faaliyet</th><th>Toplam</th></tr></thead><tbody>' . $workloadRowsHtml . '</tbody></table>'
. '<div class="footer">QMS tarafından yetkili kullanıcı için oluşturulmuştur.</div></body></html>';

$options = new Options();
$options->set('defaultFont', 'DejaVu Sans');
$options->set('isRemoteEnabled', false);
$options->setChroot(__DIR__);
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="qms-geciken-isler-' . date('Y-m-d') . '.pdf"');
echo $dompdf->output();
