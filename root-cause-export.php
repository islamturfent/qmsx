<?php
declare(strict_types=1);

session_start();

if (!isset($_SESSION['qms_logged_in']) || $_SESSION['qms_logged_in'] !== true) {
    http_response_code(403);
    exit('Yetkisiz erişim.');
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/access.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/root-cause-functions.php';
if (!qmsCanSession('operations.view')) {
    http_response_code(403);
    exit('Yetkisiz erişim.');
}

$userId = (int) ($_SESSION['qms_user_id'] ?? 0);
$role = qmsCurrentRole();

$format = (string) ($_GET['format'] ?? 'xlsx');
if (!in_array($format, ['xlsx', 'pdf'], true)) {
    $format = 'xlsx';
}
$selectedCompanyId = (int) ($_GET['company_id'] ?? 0);

$list = qmsRootCauseNcList($pdo, $userId, $role, $selectedCompanyId);
$dateStamp = date('d.m.Y H:i');
$esc = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

if ($format === 'xlsx') {
    require_once __DIR__ . '/includes/xlsx-writer.php';
    $rows = [
        [['value' => 'QuAmi Kök Neden Analizi', 'style' => 1]],
        ['Oluşturulma', $dateStamp],
        ['Kayıt sayısı', count($list)],
        [],
        [['value' => 'Şirket', 'style' => 2], ['value' => 'Uygunsuzluk', 'style' => 2], ['value' => 'Şiddet', 'style' => 2], ['value' => 'NC Durumu', 'style' => 2], ['value' => 'Analiz', 'style' => 2]],
    ];
    foreach ($list as $r) {
        $analysis = !empty($r['rc_id']) ? (($r['rc_status'] ?? '') === 'done' ? 'Tamam' : 'Devam ediyor') : 'Yok';
        $rows[] = [
            ['value' => $r['company_name'], 'style' => 3],
            ['value' => $r['title'], 'style' => 3],
            ['value' => $r['severity'], 'style' => 3],
            ['value' => $r['nc_status'], 'style' => 3],
            ['value' => $analysis, 'style' => 3],
        ];
    }
    if (count($list) === 0) {
        $rows[] = [['value' => '— Kayıt yok —', 'style' => 3]];
    }
    $sheets = [['name' => 'Analiz', 'xml' => xlsxWorksheet($rows, [24, 50, 16, 18, 18])]];

    $tmp = tempnam(sys_get_temp_dir(), 'qms-rootcause-');
    if ($tmp === false) {
        throw new RuntimeException('Geçici dosya oluşturulamadı.');
    }
    try {
        createXlsxFile($sheets, $tmp);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="qms-kok-neden-' . date('Y-m-d') . '.xlsx"');
        header('Content-Length: ' . filesize($tmp));
        header('Cache-Control: private, no-store, max-age=0');
        readfile($tmp);
    } finally {
        @unlink($tmp);
    }
    exit;
}

require_once __DIR__ . '/lib/dompdf/autoload.inc.php';
use Dompdf\Dompdf;
use Dompdf\Options;

$rowsHtml = '';
foreach ($list as $r) {
    $analysis = !empty($r['rc_id']) ? (($r['rc_status'] ?? '') === 'done' ? 'Tamam' : 'Devam ediyor') : 'Yok';
    $rowsHtml .= '<tr><td>' . $esc($r['company_name']) . '</td><td>' . $esc($r['title']) . '</td><td>'
        . $esc($r['severity']) . '</td><td>' . $esc($r['nc_status']) . '</td><td>' . $esc($analysis) . '</td></tr>';
}
if ($rowsHtml === '') {
    $rowsHtml = '<tr><td class="muted" colspan="5">Kayıt yok.</td></tr>';
}

$html = '<!doctype html><html lang="tr"><head><meta charset="UTF-8"><style>
body { font-family: "DejaVu Sans", sans-serif; font-size: 12px; color: #344054; }
h1 { font-size: 20px; margin: 0 0 4px; } .meta { color: #667085; font-size: 11px; margin-bottom: 12px; }
table { border-collapse: collapse; width: 100%; } .data th { background: #f2f4f7; text-align: left; padding: 7px 8px; font-size: 11px; }
.data td { border: 1px solid #e4e7ec; padding: 6px 8px; font-size: 11px; } .muted { color: #667085; }
.footer { margin-top: 24px; color: #98a2b3; font-size: 10px; }
</style></head><body>
<h1>QuAmi Kök Neden Analizi</h1>
<div class="meta">Oluşturulma: ' . $dateStamp . ' · Kayıt: ' . count($list) . '</div>
<table class="data"><thead><tr><th>Şirket</th><th>Uygunsuzluk</th><th>Şiddet</th><th>NC Durumu</th><th>Analiz</th></tr></thead><tbody>'
. $rowsHtml . '</tbody></table><div class="footer">QuAmi tarafından oluşturulmuştur.</div></body></html>';

$options = new Options();
$options->set('defaultFont', 'DejaVu Sans');
$options->set('isRemoteEnabled', false);
$options->setChroot(__DIR__);
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'landscape');
$dompdf->render();
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="qms-kok-neden-' . date('Y-m-d') . '.pdf"');
echo $dompdf->output();
