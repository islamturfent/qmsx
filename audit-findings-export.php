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
$statusFilter = (string) ($_GET['status'] ?? 'open');
if (!in_array($statusFilter, ['all', 'open', 'closed'], true)) {
    $statusFilter = 'open';
}
$search = trim((string) ($_GET['q'] ?? ''));

$companyScope = qmsCompanyScope('co.id', qmsVisibleCompanyIds($pdo, $userId, $role));
$params = $companyScope['params'];
$where = "cit.active = 1 AND cit.result_status = 'noncompliant'" . $companyScope['sql'];
if ($selectedCompanyId > 0) {
    $where .= ' AND a.company_id = ?';
    $params[] = $selectedCompanyId;
}
if ($statusFilter === 'open') {
    $where .= " AND (nc.id IS NULL OR nc.status <> 'closed')";
} elseif ($statusFilter === 'closed') {
    $where .= " AND nc.status = 'closed'";
}
if ($search !== '') {
    $where .= ' AND (cit.item_text LIKE ? OR co.company_name LIKE ? OR a.title LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like);
}

$sql = "SELECT cit.item_text, cit.requirement_ref, a.title AS audit_title, a.planned_date,
            co.company_name, nc.status AS nc_status,
            (SELECT COUNT(*) FROM corrective_actions ca WHERE ca.nonconformity_id = nc.id AND ca.active = 1) AS capa_count
     FROM audit_checklist_items cit
     INNER JOIN audits a ON a.id = cit.audit_id
     INNER JOIN companies co ON co.id = a.company_id
     LEFT JOIN nonconformities nc ON nc.checklist_item_id = cit.id AND nc.active = 1
     WHERE " . $where . "
     ORDER BY a.planned_date IS NULL, a.planned_date DESC, cit.id DESC";
$findingsStmt = $pdo->prepare($sql);
$findingsStmt->execute($params);
$findings = $findingsStmt->fetchAll(PDO::FETCH_ASSOC);

$dateStamp = date('d.m.Y H:i');
$esc = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$statusLabels = ['open' => 'Açık', 'all' => 'Tümü', 'closed' => 'Kapalı'];

if ($format === 'xlsx') {
    require_once __DIR__ . '/includes/xlsx-writer.php';
    $rows = [
        [['value' => 'QuAmi Denetim Bulguları', 'style' => 1]],
        ['Oluşturulma', $dateStamp],
        ['Filtre', $statusLabels[$statusFilter]],
        ['Kayıt sayısı', count($findings)],
        [],
        [['value' => 'Şirket', 'style' => 2], ['value' => 'Denetim', 'style' => 2], ['value' => 'Bulgu', 'style' => 2], ['value' => 'Referans', 'style' => 2], ['value' => 'NC Durumu', 'style' => 2], ['value' => 'CAPA', 'style' => 2]],
    ];
    foreach ($findings as $r) {
        $rows[] = [
            ['value' => $r['company_name'], 'style' => 3],
            ['value' => $r['audit_title'], 'style' => 3],
            ['value' => $r['item_text'], 'style' => 3],
            ['value' => $r['requirement_ref'] ?: '-', 'style' => 3],
            ['value' => $r['nc_status'] ?: 'bulgu', 'style' => 3],
            ['value' => (int) $r['capa_count'] . ' faaliyet', 'style' => 3],
        ];
    }
    if (count($findings) === 0) {
        $rows[] = [['value' => '— Kayıt yok —', 'style' => 3]];
    }
    $sheets = [['name' => 'Bulgular', 'xml' => xlsxWorksheet($rows, [24, 34, 50, 16, 16, 14])]];

    $tmp = tempnam(sys_get_temp_dir(), 'qms-findings-');
    if ($tmp === false) {
        throw new RuntimeException('Geçici dosya oluşturulamadı.');
    }
    try {
        createXlsxFile($sheets, $tmp);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="qms-denetim-bulgulari-' . $statusFilter . '-' . date('Y-m-d') . '.xlsx"');
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
foreach ($findings as $r) {
    $rowsHtml .= '<tr><td>' . $esc($r['company_name']) . '</td><td>' . $esc($r['audit_title']) . '</td><td>'
        . $esc($r['item_text']) . '</td><td>' . $esc($r['requirement_ref'] ?: '-') . '</td><td>'
        . $esc($r['nc_status'] ?: 'bulgu') . '</td><td>' . (int) $r['capa_count'] . '</td></tr>';
}
if ($rowsHtml === '') {
    $rowsHtml = '<tr><td class="muted" colspan="6">Kayıt yok.</td></tr>';
}

$html = '<!doctype html><html lang="tr"><head><meta charset="UTF-8"><style>
body { font-family: "DejaVu Sans", sans-serif; font-size: 12px; color: #344054; }
h1 { font-size: 20px; margin: 0 0 4px; } .meta { color: #667085; font-size: 11px; margin-bottom: 12px; }
table { border-collapse: collapse; width: 100%; } .data th { background: #f2f4f7; text-align: left; padding: 7px 8px; font-size: 11px; }
.data td { border: 1px solid #e4e7ec; padding: 6px 8px; font-size: 11px; } .muted { color: #667085; }
.footer { margin-top: 24px; color: #98a2b3; font-size: 10px; }
</style></head><body>
<h1>QuAmi Denetim Bulguları</h1>
<div class="meta">Oluşturulma: ' . $dateStamp . ' · Filtre: ' . $esc($statusLabels[$statusFilter]) . ' · Kayıt: ' . count($findings) . '</div>
<table class="data"><thead><tr><th>Şirket</th><th>Denetim</th><th>Bulgu</th><th>Referans</th><th>NC Durumu</th><th>CAPA</th></tr></thead><tbody>'
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
header('Content-Disposition: attachment; filename="qms-denetim-bulgulari-' . $statusFilter . '-' . date('Y-m-d') . '.pdf"');
echo $dompdf->output();
