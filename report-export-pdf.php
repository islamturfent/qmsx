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
require_once __DIR__ . '/includes/report-export-data.php';
require_once __DIR__ . '/lib/dompdf/autoload.inc.php';

use Dompdf\Dompdf;
use Dompdf\Options;

// Rapor disa aktarma izni tek kaynaktan gelir (RBAC servisi).
qmsRequirePermission('report.export');

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
        . $row['suppliers'] . '</td><td>' . $row['suppliers_approved'] . '</td><td>'
        . $row['complaints'] . '</td><td>' . $row['complaints_open'] . '</td><td>'
        . $row['reviews'] . '</td><td>' . $row['reviews_actions'] . '</td><td>'
        . $row['audit_programs'] . '</td><td>' . $row['audit_programs_active'] . '</td><td>'
        . $row['equipment'] . '</td><td>' . $row['equipment_overdue'] . '</td></tr>';
}
if ($companyRows === '') {
    $companyRows = '<tr><td colspan="17">Seçilen dönem için şirket verisi bulunmuyor.</td></tr>';
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

$complaintRows = '';
foreach ($report['complaint_list'] as $row) {
    $complaintRows .= '<tr><td>' . $escape($row['company_name']) . '</td><td>' . $escape($row['complaint_code'] ?: '-') . '</td><td>'
        . $escape($row['subject']) . '</td><td>' . $escape($row['source']) . '</td><td>'
        . $escape($row['severity']) . '</td><td>' . $escape($row['status']) . '</td><td>'
        . $escape($row['received_date'] ?: '-') . '</td><td>' . $escape($row['due_date'] ?: '-') . '</td><td>'
        . $escape($row['closed_date'] ?: '-') . '</td><td>' . $escape($row['linked_nonconformity']) . '</td></tr>';
}

$performanceRows = '';
foreach ($report['performance_target_list'] as $row) {
    $performanceRows .= '<tr><td>' . $escape($row['company_name']) . '</td><td>' . $escape($row['kpi']) . '</td><td>'
        . $escape((string) $row['target_value']) . '</td><td>' . $escape((string) $row['target_year']) . '</td><td>'
        . $escape($row['note'] ?: '-') . '</td></tr>';
}

$reviewRows = '';
foreach ($report['review_list'] as $row) {
    $reviewRows .= '<tr><td>' . $escape($row['company_name']) . '</td><td>' . $escape($row['title']) . '</td><td>'
        . $escape($row['review_date']) . '</td><td>' . $escape($row['period']) . '</td><td>'
        . $escape($row['status']) . '</td><td>' . $row['items'] . '</td><td>' . $row['actions'] . '</td><td>'
        . $escape($row['next_review_date'] ?: '-') . '</td></tr>';
}

$auditProgramRows = '';
foreach ($report['audit_program_list'] as $row) {
    $auditProgramRows .= '<tr><td>' . $escape($row['company_name']) . '</td><td>' . $escape($row['title']) . '</td><td>'
        . $escape((string) $row['year']) . '</td><td>' . $escape($row['status']) . '</td><td>'
        . $row['linked_audits'] . '</td><td>' . $escape($row['approved_date'] ?: '-') . '</td></tr>';
}

$equipmentRows = '';
foreach ($report['equipment_list'] as $row) {
    $equipmentRows .= '<tr><td>' . $escape($row['company_name']) . '</td><td>' . $escape($row['name']) . '</td><td>'
        . $escape($row['asset_code'] ?: '-') . '</td><td>' . $escape($row['category'] ?: '-') . '</td><td>'
        . $escape($row['next_calibration_date'] ?: '-') . '</td><td>' . $escape($row['cal_status']) . '</td><td>'
        . $row['calibration_count'] . '</td></tr>';
}

$satisfactionRows = '';
foreach ($report['satisfaction_list'] as $row) {
    $satisfactionRows .= '<tr><td>' . $escape($row['company_name']) . '</td><td>' . $escape($row['customer_name']) . '</td><td>'
        . $escape($row['responded_at']) . '</td><td>' . $row['overall_score'] . '</td><td>' . $escape($row['comment']) . '</td></tr>';
}

$personnelRows = '';
foreach ($report['personnel_list'] as $row) {
    $personnelRows .= '<tr><td>' . $escape($row['company_name']) . '</td><td>' . $escape($row['name']) . '</td><td>'
        . $escape($row['employee_code']) . '</td><td>' . $escape($row['department']) . '</td><td>'
        . $escape($row['position']) . '</td><td>' . $row['competency_count'] . '</td></tr>';
}

$externalAuditRows = '';
foreach ($report['external_audit_list'] as $row) {
    $externalAuditRows .= '<tr><td>' . $escape($row['company_name']) . '</td><td>' . $escape($row['title']) . '</td><td>'
        . $escape($row['audit_type']) . '</td><td>' . $escape($row['audited_by']) . '</td><td>'
        . $escape($row['audit_date']) . '</td><td>' . $escape($row['status']) . '</td><td>'
        . $row['open_findings'] . '</td></tr>';
}

$qualityCostRows = '';
foreach ($report['quality_cost_list'] as $row) {
    $qualityCostRows .= '<tr><td>' . $escape($row['company_name']) . '</td><td>' . $escape($row['title']) . '</td><td>'
        . $escape($row['cost_type']) . '</td><td>' . number_format((float) $row['amount'], 2, ',', '.') . '</td><td>'
        . $escape($row['incurred_on']) . '</td></tr>';
}

$qualityCostTrendRows = '';
foreach ($report['quality_cost_trend'] as $row) {
    $qualityCostTrendRows .= '<tr><td>' . $escape($row['label']) . '</td><td>'
        . number_format((float) $row['prevention'], 2, ',', '.') . '</td><td>'
        . number_format((float) $row['appraisal'], 2, ',', '.') . '</td><td>'
        . number_format((float) $row['internal_failure'], 2, ',', '.') . '</td><td>'
        . number_format((float) $row['external_failure'], 2, ',', '.') . '</td><td>'
        . number_format((float) $row['total'], 2, ',', '.') . '</td></tr>';
}

$copyRows = '';
foreach ($report['copy_list'] as $row) {
    $copyRows .= '<tr><td>' . $escape($row['company_name']) . '</td><td>' . $escape($row['document_code'] . ' — ' . $row['document_title']) . '</td><td>'
        . $escape($row['copy_no']) . '</td><td>' . $escape($row['recipient_name']) . '</td><td>'
        . $escape($row['status']) . '</td><td>' . $escape($row['distributed_on']) . '</td></tr>';
}

$approvalRunRows = '';
foreach ($report['approval_run_list'] as $row) {
    $approvalRunRows .= '<tr><td>' . $escape($row['company_name']) . '</td><td>' . $escape($row['subject']) . '</td><td>'
        . $escape($row['status']) . '</td><td>' . $escape($row['created_at']) . '</td></tr>';
}

$internalSurveyRows = '';
foreach ($report['internal_survey_list'] as $row) {
    $internalSurveyRows .= '<tr><td>' . $escape($row['company_name']) . '</td><td>' . $escape($row['title']) . '</td><td>'
        . $row['respondents'] . '</td><td>' . $row['answers'] . '</td><td>'
        . ($row['avg_rating'] !== null ? $row['avg_rating'] . '/5' : '-') . '</td></tr>';
}

$improvementRows = '';
foreach ($report['improvement_list'] as $row) {
    $improvementRows .= '<tr><td>' . $escape($row['company_name']) . '</td><td>' . $escape($row['title']) . '</td><td>'
        . $escape($row['category'] ?? '') . '</td><td>' . $escape($row['benefit_type']) . '</td><td>'
        . $escape($row['impact']) . '</td><td>' . $escape($row['status_label']) . '</td><td>'
        . $escape($row['target_date'] ?? '') . '</td></tr>';
}

$contractRows = '';
foreach ($report['contract_list'] as $row) {
    $contractRows .= '<tr><td>' . $escape($row['company_name']) . '</td><td>' . $escape($row['contract_name']) . '</td><td>'
        . $escape($row['type_label']) . '</td><td>' . $escape($row['party_name'] ?? '') . '</td><td>'
        . $escape($row['end_date'] ?? '') . '</td><td>' . $escape($row['status_label']) . '</td></tr>';
}

$instrumentRows = '';
foreach ($report['instrument_list'] as $row) {
    $instrumentRows .= '<tr><td>' . $escape($row['company_name']) . '</td><td>' . $escape($row['name']) . '</td><td>'
        . $escape($row['instrument_type'] ?? '') . '</td><td>' . $escape($row['location'] ?? '') . '</td><td>'
        . $escape($row['next_calibration_date'] ?? '') . '</td><td>' . $escape($row['status_label']) . '</td></tr>';
}

$incidentRows = '';
foreach ($report['incident_list'] as $row) {
    $incidentRows .= '<tr><td>' . $escape($row['company_name']) . '</td><td>' . $escape($row['title']) . '</td><td>'
        . $escape($row['type_label']) . '</td><td>' . $escape($row['severity_label']) . '</td><td>'
        . $escape($row['reported_at'] ?? '') . '</td><td>' . $escape($row['status_label']) . '</td></tr>';
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
      <td class="metric"><span>Şikayet Sayısı</span><strong>' . $metrics['complaint_count'] . '</strong></td>
    </tr><tr>
      <td class="metric"><span>Açık Şikayet</span><strong>' . $metrics['complaint_open_count'] . '</strong></td>
      <td class="metric"><span>Gözden Geçirme</span><strong>' . $metrics['review_count'] . '</strong></td>
    </tr><tr>
      <td class="metric"><span>Denetim Programı</span><strong>' . $metrics['audit_program_count'] . '</strong></td>
      <td class="metric"><span>Aktif Program</span><strong>' . $metrics['audit_program_active'] . '</strong></td>
    </tr><tr>
      <td class="metric"><span>Ekipman Sayısı</span><strong>' . $metrics['equipment_count'] . '</strong></td>
      <td class="metric"><span>Süresi Geçmiş Ekipman</span><strong>' . $metrics['equipment_overdue'] . '</strong></td>
    </tr><tr>
      <td class="metric"><span>Ortalama Memnuniyet</span><strong>' . $metrics['satisfaction_avg'] . '</strong></td>
      <td class="metric"><span>Memnuniyet Yanıtı</span><strong>' . $metrics['satisfaction_count'] . '</strong></td>
    </tr><tr>
      <td class="metric"><span>Personel Sayısı</span><strong>' . $metrics['personnel_count'] . '</strong></td>
      <td class="metric"><span>Vadesi Geçmiş Yetkinlik</span><strong>' . $metrics['personnel_expired'] . '</strong></td>
    </tr><tr>
      <td class="metric"><span>Dış Denetim Sayısı</span><strong>' . $metrics['external_audit_count'] . '</strong></td>
      <td class="metric"><span>Açık Dış Denetim Bulgusu</span><strong>' . $metrics['external_audit_open'] . '</strong></td>
    </tr><tr>
      <td class="metric"><span>Toplam COQ (₺)</span><strong>' . number_format((float) $metrics['quality_cost_total'], 2, ',', '.') . '</strong></td>
      <td class="metric"><span>Hata Maliyeti (₺)</span><strong>' . number_format((float) $metrics['quality_cost_failure'], 2, ',', '.') . '</strong></td>
    </tr><tr>
      <td class="metric"><span>Dağıtılan Kopya Sayısı</span><strong>' . $metrics['copy_count'] . '</strong></td>
      <td class="metric"><span>İade Edilen Kopya</span><strong>' . $metrics['copy_returned'] . '</strong></td>
    </tr><tr>
      <td class="metric"><span>Onay Akışı Sayısı</span><strong>' . $metrics['approval_run_count'] . '</strong></td>
      <td class="metric"><span>Onaylanan Akış</span><strong>' . $metrics['approval_run_approved'] . '</strong></td>
    </tr><tr>
      <td class="metric"><span>İç Anket</span><strong>' . $metrics['internal_survey_count'] . '</strong></td>
      <td class="metric"><span>Ort. İç Memnuniyet</span><strong>' . ($metrics['internal_survey_avg'] ?? '-') . '</strong></td>
    </tr><tr>
      <td class="metric"><span>İyileştirme Fırsatı</span><strong>' . $metrics['improvement_count'] . '</strong></td>
      <td class="metric"><span>Uygulanan</span><strong>' . $metrics['improvement_implemented'] . '</strong></td>
    </tr><tr>
      <td class="metric"><span>Sözleşme</span><strong>' . $metrics['contract_count'] . '</strong></td>
      <td class="metric"><span>Süresi Doluyor</span><strong>' . $metrics['contract_expiring'] . '</strong></td>
    </tr><tr>
      <td class="metric"><span>Ölçü Aleti</span><strong>' . $metrics['instrument_count'] . '</strong></td>
      <td class="metric"><span>Kalib. Geçen</span><strong>' . $metrics['instrument_overdue'] . '</strong></td>
    </tr><tr>
      <td class="metric"><span>Olay</span><strong>' . $metrics['incident_count'] . '</strong></td>
      <td class="metric"><span>Açık Olay</span><strong>' . $metrics['incident_open'] . '</strong></td>
    </tr></table>
    <h2>Şirket Performansı</h2>
    <table class="data"><thead><tr><th>Şirket</th><th>Denetimler</th><th>Uygunsuzluklar</th><th>Faaliyetler</th><th>Tamamlama</th><th>Eğitimler</th><th>Tamamlanan Eğitim</th><th>Tedarikçiler</th><th>Onaylı Tedarikçi</th><th>Şikayetler</th><th>Açık Şikayet</th><th>Gözden Geçirmeler</th><th>GGR Aksiyonu</th><th>Denetim Programı</th><th>Aktif</th><th>Ekipman</th><th>Geçmiş</th></tr></thead><tbody>'
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
    . $detailSection('Şikayetler', '<th>Şirket</th><th>Numara</th><th>Konu</th><th>Kaynak</th><th>Önem</th><th>Durum</th><th>Alınma</th><th>Termin</th><th>Kapanış</th><th>Uygunsuzluk</th>', $complaintRows)
    . $detailSection('Performans Hedefleri', '<th>Şirket</th><th>KPI</th><th>Hedef</th><th>Yıl</th><th>Not</th>', $performanceRows)
    . $detailSection('Gözden Geçirmeler', '<th>Şirket</th><th>Başlık</th><th>Toplantı</th><th>Dönem</th><th>Durum</th><th>Kalem</th><th>Aksiyon</th><th>Sonraki</th>', $reviewRows)
    . $detailSection('Denetim Programları', '<th>Şirket</th><th>Program</th><th>Yıl</th><th>Durum</th><th>Bağlı</th><th>Onay</th>', $auditProgramRows)
    . $detailSection('Ekipman', '<th>Şirket</th><th>Ekipman</th><th>Kod</th><th>Kategori</th><th>Sonraki Kalibrasyon</th><th>Durum</th><th>Kalibrasyon</th>', $equipmentRows)
    . $detailSection('Memnuniyet', '<th>Şirket</th><th>Müşteri</th><th>Tarih</th><th>Puan</th><th>Yorum</th>', $satisfactionRows)
    . $detailSection('Personel', '<th>Şirket</th><th>Personel</th><th>Kod</th><th>Bölüm</th><th>Pozisyon</th><th>Yetkinlik</th>', $personnelRows)
    . $detailSection('Dış Denetimler', '<th>Şirket</th><th>Denetim</th><th>Kaynak</th><th>Kuruluş</th><th>Tarih</th><th>Durum</th><th>Açık Bulgu</th>', $externalAuditRows)
    . $detailSection('Kalite Maliyeti', '<th>Şirket</th><th>Başlık</th><th>Kategori</th><th>Tutar (₺)</th><th>Tarih</th>', $qualityCostRows)
    . $detailSection('COQ Trendi', '<th>Ay</th><th>Önleme</th><th>Değerlendirme</th><th>İç Hata</th><th>Dış Hata</th><th>Toplam (₺)</th>', $qualityCostTrendRows)
    . $detailSection('Dağıtım', '<th>Şirket</th><th>Doküman</th><th>Kopya No</th><th>Alıcı</th><th>Durum</th><th>Dağıtım</th>', $copyRows)
    . $detailSection('Onay Akışları', '<th>Şirket</th><th>Konu</th><th>Durum</th><th>Oluşturulma</th>', $approvalRunRows)
    . $detailSection('İç Memnuniyet Anketi', '<th>Şirket</th><th>Anket</th><th>Katılımcı</th><th>Yanıt</th><th>Ortalama</th>', $internalSurveyRows)
    . $detailSection('İyileştirme Fırsatları', '<th>Şirket</th><th>Öneri</th><th>Kategori</th><th>Fayda</th><th>Etki</th><th>Durum</th><th>Hedef</th>', $improvementRows)
    . $detailSection('Sözleşmeler', '<th>Şirket</th><th>Sözleşme</th><th>Tür</th><th>Karşı Taraf</th><th>Bitiş</th><th>Durum</th>', $contractRows)
    . $detailSection('Ölçü Aletleri', '<th>Şirket</th><th>Alet</th><th>Tip</th><th>Konum</th><th>Sonraki Kalib.</th><th>Durum</th>', $instrumentRows)
    . $detailSection('Olay Raporlama', '<th>Şirket</th><th>Olay</th><th>Tür</th><th>Şiddet</th><th>Tarih</th><th>Durum</th>', $incidentRows)
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
