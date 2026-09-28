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

$userId = (int) ($_SESSION['qms_user_id'] ?? 0);
if (!qmsCanSession('my_audits.view')) {
    http_response_code(403);
    exit('Yetkisiz erişim.');
}

$format = (string) ($_GET['format'] ?? 'xlsx');
if (!in_array($format, ['xlsx', 'pdf'], true)) {
    $format = 'xlsx';
}
$filter = (string) ($_GET['filter'] ?? 'all');
if (!in_array($filter, ['all', 'pending', 'completed', 'overdue'], true)) {
    $filter = 'all';
}

$auditsStmt = $pdo->prepare(
    "SELECT audits.id, audits.title, audits.audit_type, audits.planned_date, audits.status,
            companies.company_name,
            (SELECT COUNT(*) FROM audit_checklist_items
              WHERE audit_checklist_items.audit_id = audits.id AND audit_checklist_items.active = 1) AS checklist_total,
            (SELECT COUNT(*) FROM audit_checklist_items
              WHERE audit_checklist_items.audit_id = audits.id AND audit_checklist_items.active = 1
                AND audit_checklist_items.result_status <> 'pending') AS checklist_done
     FROM audits
     INNER JOIN companies ON companies.id = audits.company_id
     INNER JOIN audit_auditors ON audit_auditors.audit_id = audits.id
     INNER JOIN auditors ON auditors.id = audit_auditors.auditor_id
     WHERE auditors.user_id = :user_id AND auditors.active = 1 AND audits.active = 1
     ORDER BY audits.planned_date IS NULL, audits.planned_date ASC, audits.id DESC"
);
$auditsStmt->execute(['user_id' => $userId]);
$audits = $auditsStmt->fetchAll(PDO::FETCH_ASSOC);

$today = date('Y-m-d');
$statusLabels = ['planned' => 'Planlandı', 'in_progress' => 'Devam Ediyor', 'done' => 'Tamamlandı'];

$rows = [];
foreach ($audits as $a) {
    $total = (int) $a['checklist_total'];
    $done = (int) $a['checklist_done'];
    $overdue = $done < $total && !empty($a['planned_date']) && $a['planned_date'] < $today;
    $completed = $total > 0 && $done >= $total;
    $pending = $done < $total;
    if ($filter === 'pending' && !$pending) { continue; }
    if ($filter === 'completed' && !$completed) { continue; }
    if ($filter === 'overdue' && !$overdue) { continue; }

    $rows[] = [
        'company' => (string) $a['company_name'],
        'title' => (string) $a['title'],
        'type' => (string) ($a['audit_type'] ?: '-'),
        'date' => (string) ($a['planned_date'] ?: '-'),
        'status' => $statusLabels[$a['status']] ?? $a['status'],
        'done' => $done,
        'total' => $total,
        'overdue' => $overdue,
    ];
}

$dateStamp = date('d.m.Y H:i');
$esc = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$filterLabels = ['all' => 'Tümü', 'pending' => 'Devam Eden', 'completed' => 'Tamamlanan', 'overdue' => 'Geciken'];

if ($format === 'xlsx') {
    require_once __DIR__ . '/includes/xlsx-writer.php';
    $summaryRows = [
        [['value' => 'QuAmi Denetimlerim', 'style' => 1]],
        ['Oluşturulma', $dateStamp],
        ['Filtre', $filterLabels[$filter]],
        ['Kayıt sayısı', count($rows)],
        [],
        [['value' => 'Şirket', 'style' => 2], ['value' => 'Denetim', 'style' => 2], ['value' => 'Tür', 'style' => 2], ['value' => 'Tarih', 'style' => 2], ['value' => 'Durum', 'style' => 2], ['value' => 'Kontrol Listesi', 'style' => 2], ['value' => 'Gecikti', 'style' => 2]],
    ];
    foreach ($rows as $r) {
        $summaryRows[] = [
            ['value' => $r['company'], 'style' => 3],
            ['value' => $r['title'], 'style' => 3],
            ['value' => $r['type'], 'style' => 3],
            ['value' => $r['date'], 'style' => 3],
            ['value' => $r['status'], 'style' => 3],
            ['value' => $r['done'] . '/' . $r['total'], 'style' => 3],
            ['value' => $r['overdue'] ? 'Evet' : 'Hayır', 'style' => 3],
        ];
    }
    if (count($rows) === 0) {
        $summaryRows[] = [['value' => '— Kayıt yok —', 'style' => 3]];
    }
    $sheets = [['name' => 'Denetimlerim', 'xml' => xlsxWorksheet($summaryRows, [24, 40, 16, 14, 16, 16, 12])]];

    $temporaryPath = tempnam(sys_get_temp_dir(), 'qms-my-audits-');
    if ($temporaryPath === false) {
        throw new RuntimeException('Geçici dosya oluşturulamadı.');
    }
    try {
        createXlsxFile($sheets, $temporaryPath);
        $filename = 'qms-denetimlerim-' . $filter . '-' . date('Y-m-d') . '.xlsx';
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

$rowsHtml = '';
foreach ($rows as $r) {
    $rowsHtml .= '<tr><td>' . $esc($r['company']) . '</td><td>' . $esc($r['title']) . '</td><td>'
        . $esc($r['type']) . '</td><td>' . $esc($r['date']) . '</td><td>' . $esc($r['status']) . '</td><td>'
        . $r['done'] . '/' . $r['total'] . '</td><td>' . ($r['overdue'] ? 'Evet' : 'Hayır') . '</td></tr>';
}
if ($rowsHtml === '') {
    $rowsHtml = '<tr><td class="muted" colspan="7">Kayıt yok.</td></tr>';
}

$html = '<!doctype html><html lang="tr"><head><meta charset="UTF-8"><style>
body { font-family: "DejaVu Sans", sans-serif; font-size: 12px; color: #344054; }
h1 { font-size: 20px; margin: 0 0 4px; } .meta { color: #667085; font-size: 11px; margin-bottom: 12px; }
table { border-collapse: collapse; width: 100%; } .data th { background: #f2f4f7; text-align: left; padding: 7px 8px; font-size: 11px; }
.data td { border: 1px solid #e4e7ec; padding: 6px 8px; font-size: 11px; } .muted { color: #667085; }
.footer { margin-top: 24px; color: #98a2b3; font-size: 10px; }
</style></head><body>
<h1>QuAmi Denetimlerim</h1>
<div class="meta">Oluşturulma: ' . $dateStamp . ' · Filtre: ' . $esc($filterLabels[$filter]) . ' · Kayıt: ' . count($rows) . '</div>
<table class="data"><thead><tr><th>Şirket</th><th>Denetim</th><th>Tür</th><th>Tarih</th><th>Durum</th><th>Kontrol Listesi</th><th>Gecikti</th></tr></thead><tbody>'
. $rowsHtml . '</tbody></table>'
. '<div class="footer">QuAmi tarafından yetkili kullanıcı için oluşturulmuştur.</div></body></html>';

$options = new Options();
$options->set('defaultFont', 'DejaVu Sans');
$options->set('isRemoteEnabled', false);
$options->setChroot(__DIR__);
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="qms-denetimlerim-' . $filter . '-' . date('Y-m-d') . '.pdf"');
echo $dompdf->output();
