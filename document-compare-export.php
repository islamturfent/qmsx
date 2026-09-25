<?php

declare(strict_types=1);

session_start();

if (!isset($_SESSION['qms_logged_in']) || $_SESSION['qms_logged_in'] !== true) {
    http_response_code(403);
    exit('Yetkisiz erişim.');
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/access.php';
require_once __DIR__ . '/includes/document-compare-functions.php';

$userId = (int) ($_SESSION['qms_user_id'] ?? 0);
$role = qmsCurrentRole();
$docId = (int) ($_GET['document'] ?? 0);
$aId = (int) ($_GET['a'] ?? 0);
$bId = (int) ($_GET['b'] ?? 0);
$format = (string) ($_GET['format'] ?? 'csv');
if (!in_array($format, ['csv', 'pdf'], true)) {
    $format = 'csv';
}

if ($docId <= 0 || $aId <= 0 || $bId <= 0 || $aId === $bId) {
    http_response_code(400);
    exit('Geçersiz karşılaştırma parametreleri.');
}

// Kapsam icinde belge + revizyonlari getir.
$scope = qmsCompanyScope('companies.id', qmsVisibleCompanyIds($pdo, $userId, $role));
$docStmt = $pdo->prepare(
    "SELECT documents.*, companies.company_name
     FROM documents INNER JOIN companies ON companies.id = documents.company_id
     WHERE documents.id = ? AND documents.active = 1" . $scope['sql'] . " LIMIT 1"
);
$docStmt->execute(array_merge([$docId], $scope['params']));
$doc = $docStmt->fetch(PDO::FETCH_ASSOC);
if (!$doc) {
    http_response_code(404);
    exit('Dokümana erişim yetkiniz yok.');
}

$vStmt = $pdo->prepare(
    "SELECT document_versions.* FROM document_versions
     WHERE document_versions.document_id = ? AND document_versions.id IN (?, ?)"
);
$vStmt->execute([$docId, $aId, $bId]);
$versionA = null;
$versionB = null;
foreach ($vStmt->fetchAll(PDO::FETCH_ASSOC) as $v) {
    if ((int) $v['id'] === $aId) $versionA = $v;
    if ((int) $v['id'] === $bId) $versionB = $v;
}
if (!$versionA || !$versionB) {
    http_response_code(404);
    exit('Revizyon bulunamadı.');
}

$bodyA = qmsCompareVersionBody((string) $versionA['stored_file_name']);
$bodyB = qmsCompareVersionBody((string) $versionB['stored_file_name']);
$diffOps = qmsDiffLines(qmsCompareTextToLines($bodyA['text']), qmsCompareTextToLines($bodyB['text']));
$summary = qmsDiffSummary($diffOps);
$typeLabel = ['same' => 'Değişmedi', 'add' => 'Eklendi', 'del' => 'Silindi'];

if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="compare-' . rawurlencode($doc['document_code']) . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM
    fputcsv($out, ['Doküman', $doc['document_code'] . ' — ' . $doc['title']]);
    fputcsv($out, ['Şirket', $doc['company_name']]);
    fputcsv($out, ['Revizyon A', $versionA['revision_number']]);
    fputcsv($out, ['Revizyon B', $versionB['revision_number']]);
    fputcsv($out, ['Fark (satır)', $summary['changed']]);
    fputcsv($out, ['Eklendi', $summary['add']]);
    fputcsv($out, ['Silindi', $summary['del']]);
    fputcsv($out, []);
    fputcsv($out, ['Durum', 'İçerik']);
    foreach ($diffOps as $op) {
        fputcsv($out, [$typeLabel[$op['type']] ?? $op['type'], $op['text']]);
    }
    fclose($out);
    exit;
}

// PDF ozeti.
require_once __DIR__ . '/lib/dompdf/autoload.inc.php';
use Dompdf\Dompdf;
use Dompdf\Options;

$escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$diffRows = '';
if (!$diffOps) {
    $diffRows = '<tr><td class="muted" colspan="2">İki revizyonun metni aynı veya karşılaştırma için çok büyük.</td></tr>';
}
foreach ($diffOps as $op) {
    $diffRows .= '<tr class="diff-' . $escape($op['type']) . '"><td class="type">' . $escape($typeLabel[$op['type']] ?? $op['type']) . '</td><td>' . $escape($op['text']) . '</td></tr>';
}

$html = '<!doctype html><html lang="tr"><head><meta charset="UTF-8"><style>
    body { font-family: "DejaVu Sans", sans-serif; font-size: 12px; color: #344054; }
    h1 { font-size: 20px; margin: 0 0 4px; }
    h2 { font-size: 15px; margin: 22px 0 8px; border-bottom: 1px solid #e4e7ec; padding-bottom: 6px; }
    .meta { color: #667085; font-size: 11px; margin-bottom: 6px; }
    .summary p { margin: 4px 0; }
    table { border-collapse: collapse; width: 100%; }
    .data th { background: #f2f4f7; text-align: left; padding: 7px 8px; font-size: 11px; }
    .data td { border: 1px solid #e4e7ec; padding: 6px 8px; font-size: 11px; vertical-align: top; }
    td.type { width: 90px; font-weight: 600; }
    .diff-add { background: #ecfdf3; color: #027a48; }
    .diff-del { background: #fef3f2; color: #b42318; }
    .diff-same, .muted { color: #667085; }
    .footer { margin-top: 24px; color: #98a2b3; font-size: 10px; }
</style></head><body>
    <h1>Doküman Versiyon Karşılaştırma Özeti</h1>
    <div class="meta"><strong>' . $escape($doc['document_code']) . ' — ' . $escape($doc['title']) . '</strong> · '
    . $escape($doc['company_name']) . ' · Oluşturulma: ' . date('d.m.Y H:i') . '</div>
    <div class="summary">
        <p><strong>Revizyon A:</strong> ' . $escape($versionA['revision_number']) . (trim((string) $versionA['change_note']) !== '' ? ' — ' . $escape($versionA['change_note']) : '') . '</p>
        <p><strong>Revizyon B:</strong> ' . $escape($versionB['revision_number']) . (trim((string) $versionB['change_note']) !== '' ? ' — ' . $escape($versionB['change_note']) : '') . '</p>
        <p><strong>Farklı satır:</strong> ' . $summary['changed'] . ' (eklenen: ' . $summary['add'] . ', silinen: ' . $summary['del'] . ')</p>
    </div>
    <h2>Satır Düzeyi Fark</h2>
    <table class="data"><thead><tr><th>Durum</th><th>İçerik</th></tr></thead><tbody>' . $diffRows . '</tbody></table>
    <div class="footer">QMS tarafından yetkili kullanıcı için oluşturulmuştur.</div>
</body></html>';

$options = new Options();
$options->set('defaultFont', 'DejaVu Sans');
$options->set('isRemoteEnabled', false);
$options->setChroot(__DIR__);
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="compare-' . rawurlencode($doc['document_code']) . '.pdf"');
echo $dompdf->output();
