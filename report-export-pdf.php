<?php

declare(strict_types=1);

session_start();

if (!isset($_SESSION['qms_logged_in']) || $_SESSION['qms_logged_in'] !== true) {
    http_response_code(403);
    exit('Yetkisiz erişim.');
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/report-export-data.php';
require_once __DIR__ . '/lib/dompdf/autoload.inc.php';

use Dompdf\Dompdf;
use Dompdf\Options;

$report = buildReportExportData(
    $pdo,
    (int) ($_SESSION['qms_user_id'] ?? 0),
    ($_SESSION['qms_role'] ?? '') === 'super_admin',
    $_GET
);

$escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$statusLabels = [
    'draft' => 'Taslak',
    'review' => 'İncelemede',
    'approved' => 'Onaylandı',
    'published' => 'Yayınlandı',
    'archived' => 'Arşivlendi',
];

$companyRows = '';
foreach ($report['company_performance'] as $row) {
    $rate = $row['actions'] > 0 ? round(($row['completed'] / $row['actions']) * 100, 1) : 0;
    $companyRows .= '<tr><td>' . $escape($row['name']) . '</td><td>' . $row['audits'] . '</td><td>'
        . $row['nonconformities'] . '</td><td>' . $row['actions'] . '</td><td>' . $rate . '%</td><td>'
        . $row['trainings'] . '</td><td>' . $row['trainings_completed'] . '</td><td>'
        . $row['suppliers'] . '</td><td>' . $row['suppliers_approved'] . '</td></tr>';
}
if ($companyRows === '') {
    $companyRows = '<tr><td colspan="9">Seçilen dönem için şirket verisi bulunmuyor.</td></tr>';
}

$statusRows = '';
foreach ($report['document_statuses'] as $status => $count) {
    $statusRows .= '<tr><td>' . $escape($statusLabels[$status] ?? ucfirst($status)) . '</td><td>' . $count . '</td></tr>';
}

$trendRows = '';
foreach ($report['months'] as $month) {
    $trendRows .= '<tr><td>' . $escape($month['label']) . '</td><td>' . $month['audits'] . '</td><td>'
        . $month['nonconformities'] . '</td></tr>';
}

// Detay tablolari.
$severityLabels = ['minor' => 'Küçük', 'major' => 'Büyük', 'critical' => 'Kritik'];
$statusLabels = [
    'open' => 'Açık', 'in_progress' => 'Devam Ediyor', 'verification' => 'Doğrulama',
    'closed' => 'Kapalı', 'planned' => 'Planlandı', 'monitoring' => 'İzlemede',
    'treated' => 'Önlem Uygulandı', 'completed' => 'Tamamlandı', 'cancelled' => 'İptal Edildi',
];

$auditRows = '';
foreach ($report['audit_list'] as $row) {
    $auditRows .= '<tr><td>' . $escape($row['company_name']) . '</td><td>' . $escape($row['title']) . '</td><td>'
        . $escape($row['audit_type'] ?: '-') . '</td><td>' . $escape($row['status']) . '</td><td>'
        . $escape($row['planned_date'] ?: '-') . '</td></tr>';
}

$nonconformityRows = '';
foreach ($report['nonconformity_list'] as $row) {
    $nonconformityRows .= '<tr><td>' . $escape($row['company_name']) . '</td><td>' . $escape($row['title']) . '</td><td>'
        . $escape($severityLabels[$row['severity']] ?? $row['severity']) . '</td><td>'
        . $escape($statusLabels[$row['status']] ?? $row['status']) . '</td><td>'
        . $escape($row['responsible_person'] ?: '-') . '</td><td>' . $escape($row['due_date'] ?: '-') . '</td></tr>';
}

$actionRows = '';
foreach ($report['action_list'] as $row) {
    $actionRows .= '<tr><td>' . $escape($row['company_name']) . '</td><td>' . $escape($row['action_text']) . '</td><td>'
        . $escape($row['responsible_person'] ?: '-') . '</td><td>'
        . $escape($statusLabels[$row['status']] ?? $row['status']) . '</td><td>'
        . $escape($row['due_date'] ?: '-') . '</td><td>' . $escape($row['completed_at'] ?: '-') . '</td></tr>';
}

$riskRows = '';
foreach ($report['risk_list'] as $row) {
    $riskRows .= '<tr><td>' . $escape($row['company_name']) . '</td><td>' . $escape($row['title']) . '</td><td>'
        . $escape($row['category'] ?: '-') . '</td><td>' . $escape((string) ($row['initial_score'] ?? '-')) . '</td><td>'
        . $escape((string) ($row['residual_score'] ?? '-')) . '</td><td>' . $escape($row['level']) . '</td><td>'
        . $escape($statusLabels[$row['status']] ?? $row['status']) . '</td><td>'
        . $escape($row['due_date'] ?: '-') . '</td></tr>';
}

$trainingRows = '';
foreach ($report['training_list'] as $row) {
    $trainingRows .= '<tr><td>' . $escape($row['company_name']) . '</td><td>' . $escape($row['title']) . '</td><td>'
        . $escape($row['category'] ?: '-') . '</td><td>' . $escape($row['provider'] ?: '-') . '</td><td>'
        . $escape($statusLabels[$row['status']] ?? $row['status']) . '</td><td>'
        . $escape($row['planned_date'] ?: '-') . '</td><td>' . $escape($row['completed_date'] ?: '-') . '</td><td>'
        . $row['participants_completed'] . '/' . $row['participants'] . '</td></tr>';
}

$supplierRows = '';
foreach ($report['supplier_list'] as $row) {
    $supplierRows .= '<tr><td>' . $escape($row['company_name']) . '</td><td>' . $escape($row['name']) . '</td><td>'
        . $escape($row['supplier_code'] ?: '-') . '</td><td>' . $escape($row['category'] ?: '-') . '</td><td>'
        . $escape($row['risk_class']) . '</td><td>' . $escape($row['status']) . '</td><td>'
        . $escape($row['approved_date'] ?: '-') . '</td><td>' . $escape((string) ($row['score'] ?? '-')) . '</td><td>'
        . $escape($row['last_evaluation'] ?: '-') . '</td></tr>';
}

$detailSection = static function (string $title, string $headers, string $rows): string {
    if ($rows === '') {
        return '<h2>' . $title . '</h2><p class="meta">Bu dönemde kayıt bulunmuyor.</p>';
    }
    return '<h2>' . $title . '</h2><table class="data"><thead><tr>' . $headers . '</tr></thead><tbody>' . $rows . '</tbody></table>';
};

$metrics = $report['metrics'];
$html = '<!DOCTYPE html><html lang="tr"><head><meta charset="UTF-8"><style>
    @page { margin: 28px 34px; }
    body { font-family: DejaVu Sans, sans-serif; color: #101828; font-size: 10px; }
    h1 { margin: 0 0 4px; color: #101828; font-size: 22px; }
    h2 { margin: 20px 0 8px; color: #101828; font-size: 13px; }
    .meta { color: #667085; margin-bottom: 18px; }
    .meta strong { color: #101828; }
    .metrics { width: 100%; border-collapse: separate; border-spacing: 6px; margin-left: -6px; }
    .metric { width: 33.33%; border: 1px solid #e4e7ec; background: #f9fafb; padding: 10px; vertical-align: top; }
    .metric span { color: #667085; font-size: 8px; text-transform: uppercase; }
    .metric strong { display: block; margin-top: 5px; color: #465fff; font-size: 18px; }
    table.data { width: 100%; border-collapse: collapse; }
    table.data th { background: #465fff; color: #fff; padding: 7px; text-align: left; }
    table.data td { border: 1px solid #e4e7ec; padding: 7px; }
    .two-column { width: 100%; border-collapse: separate; border-spacing: 12px 0; margin-left: -12px; }
    .two-column td { width: 50%; vertical-align: top; }
    .footer { margin-top: 18px; color: #98a2b3; font-size: 8px; text-align: right; }
    </style></head><body>
    <h1>QMS Yönetim Raporu</h1>
    <div class="meta"><strong>Şirket:</strong> ' . $escape($report['company_name'])
    . ' &nbsp; | &nbsp; <strong>Dönem:</strong> ' . $escape($report['start_date']) . ' - ' . $escape($report['end_date'])
    . ' &nbsp; | &nbsp; <strong>Oluşturulma:</strong> ' . date('d.m.Y H:i') . '</div>
    <table class="metrics"><tr>
      <td class="metric"><span>Denetim Sayısı</span><strong>' . $metrics['audit_count'] . '</strong></td>
      <td class="metric"><span>Denetim Başına Uygunsuzluk</span><strong>' . $metrics['nonconformity_rate'] . '%</strong></td>
      <td class="metric"><span>Aksiyon Tamamlama</span><strong>' . $metrics['action_completion_rate'] . '%</strong></td>
    </tr><tr>
      <td class="metric"><span>Geciken Aksiyonlar</span><strong>' . $metrics['overdue_actions'] . '</strong></td>
      <td class="metric"><span>Ortalama Kapanma</span><strong>' . $metrics['average_close_days'] . ' gün</strong></td>
      <td class="metric"><span>Gözden Geçirilecek Dokümanlar</span><strong>' . $metrics['review_due_documents'] . '</strong></td>
    </tr><tr>
      <td class="metric"><span>Eğitim Sayısı</span><strong>' . $metrics['training_count'] . '</strong></td>
      <td class="metric"><span>Eğitim Tamamlama</span><strong>' . $metrics['training_completion_rate'] . '%</strong></td>
      <td class="metric"><span>Tedarikçi Sayısı</span><strong>' . $metrics['supplier_count'] . '</strong></td>
    </tr><tr>
      <td class="metric"><span>Tedarikçi Ortalama Puanı</span><strong>' . ($metrics['supplier_average_score'] ?? '-') . '</strong></td>
    </tr></table>
    <h2>Şirket Performansı</h2>
    <table class="data"><thead><tr><th>Şirket</th><th>Denetimler</th><th>Uygunsuzluklar</th><th>Faaliyetler</th><th>Tamamlama</th><th>Eğitimler</th><th>Tamamlanan Eğitim</th><th>Tedarikçiler</th><th>Onaylı Tedarikçi</th></tr></thead><tbody>'
    . $companyRows . '</tbody></table>
    <table class="two-column"><tr><td><h2>Aylık Trend</h2><table class="data"><thead><tr><th>Ay</th><th>Denetim</th><th>Uygunsuzluk</th></tr></thead><tbody>'
    . $trendRows . '</tbody></table></td><td><h2>Doküman Durumları</h2><table class="data"><thead><tr><th>Durum</th><th>Adet</th></tr></thead><tbody>'
    . $statusRows . '</tbody></table></td></tr></table>'
    . $detailSection('Denetimler', '<th>Şirket</th><th>Denetim</th><th>Tür</th><th>Durum</th><th>Planlanan</th>', $auditRows)
    . $detailSection('Uygunsuzluklar', '<th>Şirket</th><th>Uygunsuzluk</th><th>Önem</th><th>Durum</th><th>Sorumlu</th><th>Termin</th>', $nonconformityRows)
    . $detailSection('Düzeltici Faaliyetler', '<th>Şirket</th><th>Faaliyet</th><th>Sorumlu</th><th>Durum</th><th>Termin</th><th>Kapanış</th>', $actionRows)
    . $detailSection('Risk Kaydı', '<th>Şirket</th><th>Risk</th><th>Kategori</th><th>Başlangıç</th><th>Kalan</th><th>Seviye</th><th>Durum</th><th>Termin</th>', $riskRows)
    . $detailSection('Eğitimler', '<th>Şirket</th><th>Eğitim</th><th>Kategori</th><th>Sağlayıcı</th><th>Durum</th><th>Planlanan</th><th>Tamamlanma</th><th>Katılımcı</th>', $trainingRows)
    . $detailSection('Tedarikçiler', '<th>Şirket</th><th>Tedarikçi</th><th>Kod</th><th>Kategori</th><th>Risk</th><th>Durum</th><th>Onay Tarihi</th><th>Puan</th><th>Son Değerlendirme</th>', $supplierRows)
    . '<div class="footer">QMS tarafından yetkili kullanıcı için oluşturulmuştur.</div>
    </body></html>';

$options = new Options();
$options->set('defaultFont', 'DejaVu Sans');
$options->set('isRemoteEnabled', false);
$options->setChroot(__DIR__);
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'landscape');
$dompdf->render();
$dompdf->stream('qms-yonetim-raporu-' . date('Y-m-d') . '.pdf', ['Attachment' => true]);
