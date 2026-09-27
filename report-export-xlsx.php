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
require_once __DIR__ . '/includes/xlsx-writer.php';

// Rapor disa aktarma izni tek kaynaktan gelir (RBAC servisi).
qmsRequirePermission('report.export');

$report = buildReportExportData(
    $pdo,
    (int) ($_SESSION['qms_user_id'] ?? 0),
    ($_SESSION['qms_role'] ?? '') === 'super_admin',
    $_GET
);

$metricLabels = [
    'audit_count' => 'Denetim Sayısı',
    'nonconformity_rate' => 'Denetim Başına Uygunsuzluk (%)',
    'action_completion_rate' => 'Aksiyon Tamamlama (%)',
    'overdue_actions' => 'Geciken Aksiyonlar',
    'average_close_days' => 'Ortalama Kapanma (gün)',
    'review_due_documents' => 'Gözden Geçirilecek Dokümanlar',
    'training_count' => 'Eğitim Sayısı',
    'training_completion_rate' => 'Eğitim Tamamlama (%)',
    'supplier_count' => 'Tedarikçi Sayısı',
    'supplier_average_score' => 'Tedarikçi Ortalama Puanı',
    'complaint_count' => 'Şikayet Sayısı',
    'complaint_open_count' => 'Açık Şikayet',
    'review_count' => 'Gözden Geçirme Sayısı',
    'audit_program_count' => 'Denetim Programı',
    'equipment_count' => 'Ekipman Sayısı',
    'equipment_overdue' => 'Süresi Geçmiş Ekipman',
    'satisfaction_count' => 'Memnuniyet Yanıtı',
    'satisfaction_avg' => 'Ortalama Memnuniyet (1-5)',
    'personnel_count' => 'Personel Sayısı',
    'personnel_expired' => 'Vadesi Geçmiş Yetkinlik',
    'external_audit_count' => 'Dış Denetim Sayısı',
    'external_audit_open' => 'Açık Dış Denetim Bulgusu',
    'quality_cost_total' => 'Toplam COQ (₺)',
    'quality_cost_failure' => 'Hata Maliyeti (₺)',
    'copy_count' => 'Dağıtılan Kopya Sayısı',
    'copy_returned' => 'İade Edilen Kopya',
    'approval_run_count' => 'Onay Akışı Sayısı',
    'approval_run_approved' => 'Onaylanan Akış',
    'approval_run_pending' => 'Devam Eden Akış',
    'internal_survey_count' => 'İç Anket Sayısı',
    'internal_survey_respondents' => 'İç Anket Katılımcı',
    'internal_survey_avg' => 'Ortalama İç Memnuniyet',
    'improvement_count' => 'İyileştirme Fırsatı',
    'improvement_open' => 'Açık Fırsat',
    'improvement_implemented' => 'Uygulanan Fırsat',
    'contract_count' => 'Sözleşme Sayısı',
    'contract_active' => 'Aktif Sözleşme',
    'contract_expiring' => 'Süresi Dolan Sözleşme',
    'instrument_count' => 'Ölçü Aleti Sayısı',
    'instrument_active' => 'Aktif Ölçü Aleti',
    'instrument_overdue' => 'Kalibrasyonu Geçen Alet',
    'incident_count' => 'Olay Sayısı',
    'incident_open' => 'Açık Olay',
    'incident_critical' => 'Kritik Olay',
    'delivery_count' => 'Teslimat Kaydı',
    'delivery_ontime_rate' => 'Zamanında Teslim (%)',
    'delivery_rejected' => 'Reddedilen Miktar',
    'calibration_count' => 'Kalibrasyon Kaydı',
    'calibration_fail' => 'Başarısız Kalibrasyon',
    'audit_trail_count' => 'Denetim İzi Kaydı',
];

$summaryRows = [
    [['value' => 'QuAmi Yönetim Raporu', 'style' => 1]],
    ['Şirket', $report['company_name']],
    ['Rapor dönemi', $report['start_date'] . ' - ' . $report['end_date']],
    ['Oluşturulma', date('d.m.Y H:i')],
    [],
    [
        ['value' => 'KPI', 'style' => 2],
        ['value' => 'Değer', 'style' => 2],
    ],
];
foreach ($metricLabels as $key => $label) {
    $summaryRows[] = [
        ['value' => $label, 'style' => 3],
        ['value' => $report['metrics'][$key], 'style' => 3],
    ];
}
$summaryRows[] = [];
$summaryRows[] = [
    ['value' => 'Doküman Durumu', 'style' => 2],
    ['value' => 'Adet', 'style' => 2],
];
$statusLabels = [
    'draft' => 'Taslak',
    'review' => 'İncelemede',
    'approved' => 'Onaylandı',
    'published' => 'Yayınlandı',
    'archived' => 'Arşivlendi',
];
foreach ($report['document_statuses'] as $status => $count) {
    $summaryRows[] = [
        ['value' => $statusLabels[$status] ?? ucfirst($status), 'style' => 3],
        ['value' => $count, 'style' => 3],
    ];
}

$companyRows = [[
    ['value' => 'Şirket', 'style' => 2],
    ['value' => 'Denetimler', 'style' => 2],
    ['value' => 'Uygunsuzluklar', 'style' => 2],
    ['value' => 'Düzeltici Faaliyetler', 'style' => 2],
    ['value' => 'Tamamlanan', 'style' => 2],
    ['value' => 'Tamamlama (%)', 'style' => 2],
    ['value' => 'Eğitimler', 'style' => 2],
    ['value' => 'Tamamlanan Eğitim', 'style' => 2],
    ['value' => 'Tedarikçiler', 'style' => 2],
    ['value' => 'Onaylı Tedarikçi', 'style' => 2],
    ['value' => 'Şikayetler', 'style' => 2],
    ['value' => 'Açık Şikayet', 'style' => 2],
    ['value' => 'Gözden Geçirmeler', 'style' => 2],
    ['value' => 'GGR Aksiyonu', 'style' => 2],
    ['value' => 'Denetim Programları', 'style' => 2],
    ['value' => 'Aktif Program', 'style' => 2],
    ['value' => 'Ekipmanlar', 'style' => 2],
    ['value' => 'Geçmiş', 'style' => 2],
]];
foreach ($report['company_performance'] as $row) {
    $rate = $row['actions'] > 0 ? round(($row['completed'] / $row['actions']) * 100, 1) : 0;
    $companyRows[] = [
        ['value' => $row['name'], 'style' => 3],
        ['value' => $row['audits'], 'style' => 3],
        ['value' => $row['nonconformities'], 'style' => 3],
        ['value' => $row['actions'], 'style' => 3],
        ['value' => $row['completed'], 'style' => 3],
        ['value' => $rate, 'style' => 3],
        ['value' => $row['trainings'], 'style' => 3],
        ['value' => $row['trainings_completed'], 'style' => 3],
        ['value' => $row['suppliers'], 'style' => 3],
        ['value' => $row['suppliers_approved'], 'style' => 3],
        ['value' => $row['complaints'], 'style' => 3],
        ['value' => $row['complaints_open'], 'style' => 3],
        ['value' => $row['reviews'], 'style' => 3],
        ['value' => $row['reviews_actions'], 'style' => 3],
        ['value' => $row['audit_programs'], 'style' => 3],
        ['value' => $row['audit_programs_active'], 'style' => 3],
        ['value' => $row['equipment'], 'style' => 3],
        ['value' => $row['equipment_overdue'], 'style' => 3],
    ];
}

$trendRows = [[
    ['value' => 'Ay', 'style' => 2],
    ['value' => 'Denetimler', 'style' => 2],
    ['value' => 'Uygunsuzluklar', 'style' => 2],
]];
foreach ($report['months'] as $month) {
    $trendRows[] = [
        ['value' => $month['label'], 'style' => 3],
        ['value' => $month['audits'], 'style' => 3],
        ['value' => $month['nonconformities'], 'style' => 3],
    ];
}

// Detay sayfalari: KPI'larin yaninda kayit dokumu.
$severityLabels = ['minor' => 'Küçük', 'major' => 'Büyük', 'critical' => 'Kritik'];
$nonconformityStatusLabels = ['open' => 'Açık', 'in_progress' => 'Devam Ediyor', 'verification' => 'Doğrulama', 'closed' => 'Kapalı'];
$actionStatusLabels = ['planned' => 'Planlandı', 'in_progress' => 'Çalışılıyor', 'verification' => 'Doğrulama', 'completed' => 'Tamamlandı', 'closed' => 'Kapalı'];
$riskStatusLabels = ['open' => 'Açık', 'monitoring' => 'İzlemede', 'treated' => 'Önlem Uygulandı', 'closed' => 'Kapalı'];
$trainingStatusLabels = ['planned' => 'Planlandı', 'in_progress' => 'Devam Ediyor', 'completed' => 'Tamamlandı', 'cancelled' => 'İptal Edildi'];

$auditRows = [[
    ['value' => 'Şirket', 'style' => 2],
    ['value' => 'Denetim', 'style' => 2],
    ['value' => 'Tür', 'style' => 2],
    ['value' => 'Durum', 'style' => 2],
    ['value' => 'Planlanan Tarih', 'style' => 2],
]];
foreach ($report['audit_list'] as $row) {
    $auditRows[] = [
        ['value' => $row['company_name'], 'style' => 3],
        ['value' => $row['title'], 'style' => 3],
        ['value' => $row['audit_type'] ?: '-', 'style' => 3],
        ['value' => $row['status'], 'style' => 3],
        ['value' => $row['planned_date'] ?: '-', 'style' => 3],
    ];
}

$nonconformityRows = [[
    ['value' => 'Şirket', 'style' => 2],
    ['value' => 'Uygunsuzluk', 'style' => 2],
    ['value' => 'Önem', 'style' => 2],
    ['value' => 'Durum', 'style' => 2],
    ['value' => 'Sorumlu', 'style' => 2],
    ['value' => 'Termin', 'style' => 2],
]];
foreach ($report['nonconformity_list'] as $row) {
    $nonconformityRows[] = [
        ['value' => $row['company_name'], 'style' => 3],
        ['value' => $row['title'], 'style' => 3],
        ['value' => $severityLabels[$row['severity']] ?? $row['severity'], 'style' => 3],
        ['value' => $nonconformityStatusLabels[$row['status']] ?? $row['status'], 'style' => 3],
        ['value' => $row['responsible_person'] ?: '-', 'style' => 3],
        ['value' => $row['due_date'] ?: '-', 'style' => 3],
    ];
}

$actionRows = [[
    ['value' => 'Şirket', 'style' => 2],
    ['value' => 'Faaliyet', 'style' => 2],
    ['value' => 'Sorumlu', 'style' => 2],
    ['value' => 'Durum', 'style' => 2],
    ['value' => 'Termin', 'style' => 2],
    ['value' => 'Kapanış', 'style' => 2],
]];
foreach ($report['action_list'] as $row) {
    $actionRows[] = [
        ['value' => $row['company_name'], 'style' => 3],
        ['value' => $row['action_text'], 'style' => 3],
        ['value' => $row['responsible_person'] ?: '-', 'style' => 3],
        ['value' => $actionStatusLabels[$row['status']] ?? $row['status'], 'style' => 3],
        ['value' => $row['due_date'] ?: '-', 'style' => 3],
        ['value' => $row['completed_at'] ?: '-', 'style' => 3],
    ];
}

$riskRows = [[
    ['value' => 'Şirket', 'style' => 2],
    ['value' => 'Risk', 'style' => 2],
    ['value' => 'Kategori', 'style' => 2],
    ['value' => 'Başlangıç', 'style' => 2],
    ['value' => 'Kalan', 'style' => 2],
    ['value' => 'Seviye', 'style' => 2],
    ['value' => 'Durum', 'style' => 2],
    ['value' => 'Termin', 'style' => 2],
]];
foreach ($report['risk_list'] as $row) {
    $riskRows[] = [
        ['value' => $row['company_name'], 'style' => 3],
        ['value' => $row['title'], 'style' => 3],
        ['value' => $row['category'] ?: '-', 'style' => 3],
        ['value' => $row['initial_score'] ?? '-', 'style' => 3],
        ['value' => $row['residual_score'] ?? '-', 'style' => 3],
        ['value' => $row['level'], 'style' => 3],
        ['value' => $riskStatusLabels[$row['status']] ?? $row['status'], 'style' => 3],
        ['value' => $row['due_date'] ?: '-', 'style' => 3],
    ];
}

$trainingRows = [[
    ['value' => 'Şirket', 'style' => 2],
    ['value' => 'Eğitim', 'style' => 2],
    ['value' => 'Kategori', 'style' => 2],
    ['value' => 'Sağlayıcı', 'style' => 2],
    ['value' => 'Durum', 'style' => 2],
    ['value' => 'Planlanan', 'style' => 2],
    ['value' => 'Tamamlanma', 'style' => 2],
    ['value' => 'Katılımcı', 'style' => 2],
]];
foreach ($report['training_list'] as $row) {
    $trainingRows[] = [
        ['value' => $row['company_name'], 'style' => 3],
        ['value' => $row['title'], 'style' => 3],
        ['value' => $row['category'] ?: '-', 'style' => 3],
        ['value' => $row['provider'] ?: '-', 'style' => 3],
        ['value' => $trainingStatusLabels[$row['status']] ?? $row['status'], 'style' => 3],
        ['value' => $row['planned_date'] ?: '-', 'style' => 3],
        ['value' => $row['completed_date'] ?: '-', 'style' => 3],
        ['value' => $row['participants_completed'] . '/' . $row['participants'], 'style' => 3],
    ];
}

$supplierRows = [[
    ['value' => 'Şirket', 'style' => 2],
    ['value' => 'Tedarikçi', 'style' => 2],
    ['value' => 'Kod', 'style' => 2],
    ['value' => 'Kategori', 'style' => 2],
    ['value' => 'Risk', 'style' => 2],
    ['value' => 'Durum', 'style' => 2],
    ['value' => 'Onay Tarihi', 'style' => 2],
    ['value' => 'Puan', 'style' => 2],
    ['value' => 'Son Değerlendirme', 'style' => 2],
]];
foreach ($report['supplier_list'] as $row) {
    $supplierRows[] = [
        ['value' => $row['company_name'], 'style' => 3],
        ['value' => $row['name'], 'style' => 3],
        ['value' => $row['supplier_code'] ?: '-', 'style' => 3],
        ['value' => $row['category'] ?: '-', 'style' => 3],
        ['value' => $row['risk_class'], 'style' => 3],
        ['value' => $row['status'], 'style' => 3],
        ['value' => $row['approved_date'] ?: '-', 'style' => 3],
        ['value' => $row['score'] ?? '-', 'style' => 3],
        ['value' => $row['last_evaluation'] ?: '-', 'style' => 3],
    ];
}

$complaintRows = [[
    ['value' => 'Şirket', 'style' => 2],
    ['value' => 'Numara', 'style' => 2],
    ['value' => 'Konu', 'style' => 2],
    ['value' => 'Kaynak', 'style' => 2],
    ['value' => 'Önem', 'style' => 2],
    ['value' => 'Durum', 'style' => 2],
    ['value' => 'Alınma', 'style' => 2],
    ['value' => 'Termin', 'style' => 2],
    ['value' => 'Kapanış', 'style' => 2],
    ['value' => 'Uygunsuzluk', 'style' => 2],
]];
foreach ($report['complaint_list'] as $row) {
    $complaintRows[] = [
        ['value' => $row['company_name'], 'style' => 3],
        ['value' => $row['complaint_code'] ?: '-', 'style' => 3],
        ['value' => $row['subject'], 'style' => 3],
        ['value' => $row['source'], 'style' => 3],
        ['value' => $row['severity'], 'style' => 3],
        ['value' => $row['status'], 'style' => 3],
        ['value' => $row['received_date'] ?: '-', 'style' => 3],
        ['value' => $row['due_date'] ?: '-', 'style' => 3],
        ['value' => $row['closed_date'] ?: '-', 'style' => 3],
        ['value' => $row['linked_nonconformity'], 'style' => 3],
    ];
}

$performanceRows = [[
    ['value' => 'Şirket', 'style' => 2],
    ['value' => 'KPI', 'style' => 2],
    ['value' => 'Hedef', 'style' => 2],
    ['value' => 'Yıl', 'style' => 2],
    ['value' => 'Not', 'style' => 2],
]];
foreach ($report['performance_target_list'] as $row) {
    $performanceRows[] = [
        ['value' => $row['company_name'], 'style' => 3],
        ['value' => $row['kpi'], 'style' => 3],
        ['value' => $row['target_value'], 'style' => 3],
        ['value' => $row['target_year'], 'style' => 3],
        ['value' => $row['note'] ?: '-', 'style' => 3],
    ];
}

$reviewRows = [[
    ['value' => 'Şirket', 'style' => 2],
    ['value' => 'Başlık', 'style' => 2],
    ['value' => 'Toplantı', 'style' => 2],
    ['value' => 'Dönem', 'style' => 2],
    ['value' => 'Durum', 'style' => 2],
    ['value' => 'Kalem', 'style' => 2],
    ['value' => 'Aksiyon', 'style' => 2],
    ['value' => 'Sonraki', 'style' => 2],
]];
foreach ($report['review_list'] as $row) {
    $reviewRows[] = [
        ['value' => $row['company_name'], 'style' => 3],
        ['value' => $row['title'], 'style' => 3],
        ['value' => $row['review_date'], 'style' => 3],
        ['value' => $row['period'], 'style' => 3],
        ['value' => $row['status'], 'style' => 3],
        ['value' => $row['items'], 'style' => 3],
        ['value' => $row['actions'], 'style' => 3],
        ['value' => $row['next_review_date'] ?: '-', 'style' => 3],
    ];
}

$auditProgramRows = [[
    ['value' => 'Şirket', 'style' => 2],
    ['value' => 'Program', 'style' => 2],
    ['value' => 'Yıl', 'style' => 2],
    ['value' => 'Durum', 'style' => 2],
    ['value' => 'Bağlı Denetim', 'style' => 2],
    ['value' => 'Onay', 'style' => 2],
]];
foreach ($report['audit_program_list'] as $row) {
    $auditProgramRows[] = [
        ['value' => $row['company_name'], 'style' => 3],
        ['value' => $row['title'], 'style' => 3],
        ['value' => $row['year'], 'style' => 3],
        ['value' => $row['status'], 'style' => 3],
        ['value' => $row['linked_audits'], 'style' => 3],
        ['value' => $row['approved_date'] ?: '-', 'style' => 3],
    ];
}

$equipmentRows = [[
    ['value' => 'Şirket', 'style' => 2],
    ['value' => 'Ekipman', 'style' => 2],
    ['value' => 'Kod', 'style' => 2],
    ['value' => 'Kategori', 'style' => 2],
    ['value' => 'Sonraki Kalibrasyon', 'style' => 2],
    ['value' => 'Durum', 'style' => 2],
    ['value' => 'Kalibrasyon', 'style' => 2],
]];
foreach ($report['equipment_list'] as $row) {
    $equipmentRows[] = [
        ['value' => $row['company_name'], 'style' => 3],
        ['value' => $row['name'], 'style' => 3],
        ['value' => $row['asset_code'] ?: '-', 'style' => 3],
        ['value' => $row['category'] ?: '-', 'style' => 3],
        ['value' => $row['next_calibration_date'] ?: '-', 'style' => 3],
        ['value' => $row['cal_status'], 'style' => 3],
        ['value' => $row['calibration_count'], 'style' => 3],
    ];
}

$satisfactionRows = [[
    ['value' => 'Şirket', 'style' => 2],
    ['value' => 'Müşteri', 'style' => 2],
    ['value' => 'Tarih', 'style' => 2],
    ['value' => 'Puan (1-5)', 'style' => 2],
    ['value' => 'Yorum', 'style' => 2],
]];
foreach ($report['satisfaction_list'] as $row) {
    $satisfactionRows[] = [
        ['value' => $row['company_name'], 'style' => 3],
        ['value' => $row['customer_name'], 'style' => 3],
        ['value' => $row['responded_at'], 'style' => 3],
        ['value' => $row['overall_score'], 'style' => 3],
        ['value' => $row['comment'], 'style' => 3],
    ];
}

$personnelRows = [[
    ['value' => 'Şirket', 'style' => 2],
    ['value' => 'Personel', 'style' => 2],
    ['value' => 'Kod', 'style' => 2],
    ['value' => 'Bölüm', 'style' => 2],
    ['value' => 'Pozisyon', 'style' => 2],
    ['value' => 'Yetkinlik', 'style' => 2],
]];
foreach ($report['personnel_list'] as $row) {
    $personnelRows[] = [
        ['value' => $row['company_name'], 'style' => 3],
        ['value' => $row['name'], 'style' => 3],
        ['value' => $row['employee_code'], 'style' => 3],
        ['value' => $row['department'], 'style' => 3],
        ['value' => $row['position'], 'style' => 3],
        ['value' => $row['competency_count'], 'style' => 3],
    ];
}

$externalAuditRows = [[
    ['value' => 'Şirket', 'style' => 2],
    ['value' => 'Denetim', 'style' => 2],
    ['value' => 'Kaynak', 'style' => 2],
    ['value' => 'Kuruluş', 'style' => 2],
    ['value' => 'Tarih', 'style' => 2],
    ['value' => 'Durum', 'style' => 2],
    ['value' => 'Açık Bulgu', 'style' => 2],
]];
foreach ($report['external_audit_list'] as $row) {
    $externalAuditRows[] = [
        ['value' => $row['company_name'], 'style' => 3],
        ['value' => $row['title'], 'style' => 3],
        ['value' => $row['audit_type'], 'style' => 3],
        ['value' => $row['audited_by'], 'style' => 3],
        ['value' => $row['audit_date'], 'style' => 3],
        ['value' => $row['status'], 'style' => 3],
        ['value' => $row['open_findings'], 'style' => 3],
    ];
}

$qualityCostRows = [[
    ['value' => 'Şirket', 'style' => 2],
    ['value' => 'Başlık', 'style' => 2],
    ['value' => 'Kategori', 'style' => 2],
    ['value' => 'Tutar (₺)', 'style' => 2],
    ['value' => 'Tarih', 'style' => 2],
]];
foreach ($report['quality_cost_list'] as $row) {
    $qualityCostRows[] = [
        ['value' => $row['company_name'], 'style' => 3],
        ['value' => $row['title'], 'style' => 3],
        ['value' => $row['cost_type'], 'style' => 3],
        ['value' => $row['amount'], 'style' => 3],
        ['value' => $row['incurred_on'], 'style' => 3],
    ];
}

$qualityCostTrendRows = [[
    ['value' => 'Ay', 'style' => 2],
    ['value' => 'Önleme', 'style' => 2],
    ['value' => 'Değerlendirme', 'style' => 2],
    ['value' => 'İç Hata', 'style' => 2],
    ['value' => 'Dış Hata', 'style' => 2],
    ['value' => 'Toplam (₺)', 'style' => 2],
]];
foreach ($report['quality_cost_trend'] as $row) {
    $qualityCostTrendRows[] = [
        ['value' => $row['label'], 'style' => 3],
        ['value' => $row['prevention'], 'style' => 3],
        ['value' => $row['appraisal'], 'style' => 3],
        ['value' => $row['internal_failure'], 'style' => 3],
        ['value' => $row['external_failure'], 'style' => 3],
        ['value' => $row['total'], 'style' => 3],
    ];
}

$copyRows = [[
    ['value' => 'Şirket', 'style' => 2],
    ['value' => 'Doküman', 'style' => 2],
    ['value' => 'Kopya No', 'style' => 2],
    ['value' => 'Alıcı', 'style' => 2],
    ['value' => 'Durum', 'style' => 2],
    ['value' => 'Dağıtım', 'style' => 2],
]];
foreach ($report['copy_list'] as $row) {
    $copyRows[] = [
        ['value' => $row['company_name'], 'style' => 3],
        ['value' => $row['document_code'] . ' — ' . $row['document_title'], 'style' => 3],
        ['value' => $row['copy_no'], 'style' => 3],
        ['value' => $row['recipient_name'], 'style' => 3],
        ['value' => $row['status'], 'style' => 3],
        ['value' => $row['distributed_on'], 'style' => 3],
    ];
}

$approvalRunRows = [[
    ['value' => 'Şirket', 'style' => 2],
    ['value' => 'Konu', 'style' => 2],
    ['value' => 'Durum', 'style' => 2],
    ['value' => 'Oluşturulma', 'style' => 2],
]];
foreach ($report['approval_run_list'] as $row) {
    $approvalRunRows[] = [
        ['value' => $row['company_name'], 'style' => 3],
        ['value' => $row['subject'], 'style' => 3],
        ['value' => $row['status'], 'style' => 3],
        ['value' => $row['created_at'], 'style' => 3],
    ];
}

$internalSurveyRows = [[
    ['value' => 'Şirket', 'style' => 2],
    ['value' => 'Anket', 'style' => 2],
    ['value' => 'Katılımcı', 'style' => 2],
    ['value' => 'Yanıt', 'style' => 2],
    ['value' => 'Ortalama', 'style' => 2],
]];
foreach ($report['internal_survey_list'] as $row) {
    $internalSurveyRows[] = [
        ['value' => $row['company_name'], 'style' => 3],
        ['value' => $row['title'], 'style' => 3],
        ['value' => $row['respondents'], 'style' => 3],
        ['value' => $row['answers'], 'style' => 3],
        ['value' => ($row['avg_rating'] !== null ? $row['avg_rating'] . '/5' : '-'), 'style' => 3],
    ];
}

$improvementRows = [[
    ['value' => 'Şirket', 'style' => 2],
    ['value' => 'Öneri', 'style' => 2],
    ['value' => 'Kategori', 'style' => 2],
    ['value' => 'Fayda', 'style' => 2],
    ['value' => 'Etki', 'style' => 2],
    ['value' => 'Durum', 'style' => 2],
    ['value' => 'Hedef', 'style' => 2],
]];
foreach ($report['improvement_list'] as $row) {
    $improvementRows[] = [
        ['value' => $row['company_name'], 'style' => 3],
        ['value' => $row['title'], 'style' => 3],
        ['value' => ($row['category'] ?? ''), 'style' => 3],
        ['value' => $row['benefit_type'], 'style' => 3],
        ['value' => $row['impact'], 'style' => 3],
        ['value' => $row['status_label'], 'style' => 3],
        ['value' => ($row['target_date'] ?? ''), 'style' => 3],
    ];
}

$contractRows = [[
    ['value' => 'Şirket', 'style' => 2],
    ['value' => 'Sözleşme', 'style' => 2],
    ['value' => 'Tür', 'style' => 2],
    ['value' => 'Karşı Taraf', 'style' => 2],
    ['value' => 'Bitiş', 'style' => 2],
    ['value' => 'Durum', 'style' => 2],
]];
foreach ($report['contract_list'] as $row) {
    $contractRows[] = [
        ['value' => $row['company_name'], 'style' => 3],
        ['value' => $row['contract_name'], 'style' => 3],
        ['value' => $row['type_label'], 'style' => 3],
        ['value' => ($row['party_name'] ?? ''), 'style' => 3],
        ['value' => ($row['end_date'] ?? ''), 'style' => 3],
        ['value' => $row['status_label'], 'style' => 3],
    ];
}

$instrumentRows = [[
    ['value' => 'Şirket', 'style' => 2],
    ['value' => 'Alet', 'style' => 2],
    ['value' => 'Tip', 'style' => 2],
    ['value' => 'Konum', 'style' => 2],
    ['value' => 'Sonraki Kalib.', 'style' => 2],
    ['value' => 'Durum', 'style' => 2],
]];
foreach ($report['instrument_list'] as $row) {
    $instrumentRows[] = [
        ['value' => $row['company_name'], 'style' => 3],
        ['value' => $row['name'], 'style' => 3],
        ['value' => ($row['instrument_type'] ?? ''), 'style' => 3],
        ['value' => ($row['location'] ?? ''), 'style' => 3],
        ['value' => ($row['next_calibration_date'] ?? ''), 'style' => 3],
        ['value' => $row['status_label'], 'style' => 3],
    ];
}

$incidentRows = [[
    ['value' => 'Şirket', 'style' => 2],
    ['value' => 'Olay', 'style' => 2],
    ['value' => 'Tür', 'style' => 2],
    ['value' => 'Şiddet', 'style' => 2],
    ['value' => 'Tarih', 'style' => 2],
    ['value' => 'Durum', 'style' => 2],
]];
foreach ($report['incident_list'] as $row) {
    $incidentRows[] = [
        ['value' => $row['company_name'], 'style' => 3],
        ['value' => $row['title'], 'style' => 3],
        ['value' => $row['type_label'], 'style' => 3],
        ['value' => $row['severity_label'], 'style' => 3],
        ['value' => ($row['reported_at'] ?? ''), 'style' => 3],
        ['value' => $row['status_label'], 'style' => 3],
    ];
}

$deliveryRows = [[
    ['value' => 'Şirket', 'style' => 2],
    ['value' => 'Müşteri', 'style' => 2],
    ['value' => 'Dönem', 'style' => 2],
    ['value' => 'Sipariş', 'style' => 2],
    ['value' => 'Zamanında', 'style' => 2],
    ['value' => 'Zamanında (%)', 'style' => 2],
    ['value' => 'Reddedilen', 'style' => 2],
    ['value' => 'Teslim Edilen', 'style' => 2],
]];
foreach ($report['delivery_list'] as $row) {
    $deliveryRows[] = [
        ['value' => $row['company_name'], 'style' => 3],
        ['value' => $row['customer_name'], 'style' => 3],
        ['value' => ($row['period'] ?? ''), 'style' => 3],
        ['value' => $row['orders_total'], 'style' => 3],
        ['value' => $row['on_time_orders'], 'style' => 3],
        ['value' => $row['on_time_rate'], 'style' => 3],
        ['value' => $row['quantity_rejected'], 'style' => 3],
        ['value' => $row['quantity_delivered'], 'style' => 3],
    ];
}

$calibrationRows = [[
    ['value' => 'Şirket', 'style' => 2],
    ['value' => 'Alet', 'style' => 2],
    ['value' => 'Sonuç', 'style' => 2],
    ['value' => 'Tarih', 'style' => 2],
    ['value' => 'Sonraki', 'style' => 2],
    ['value' => 'Laboratuvar', 'style' => 2],
    ['value' => 'Sertifika No', 'style' => 2],
]];
foreach ($report['calibration_list'] as $row) {
    $calibrationRows[] = [
        ['value' => $row['company_name'], 'style' => 3],
        ['value' => $row['instrument_name'], 'style' => 3],
        ['value' => $row['result_label'], 'style' => 3],
        ['value' => ($row['calibration_date'] ?? ''), 'style' => 3],
        ['value' => ($row['due_date'] ?? ''), 'style' => 3],
        ['value' => ($row['lab_name'] ?? ''), 'style' => 3],
        ['value' => ($row['cert_number'] ?? ''), 'style' => 3],
    ];
}

$auditTrailRows = [[
    ['value' => 'Şirket', 'style' => 2],
    ['value' => 'Kişi', 'style' => 2],
    ['value' => 'Kayıt Türü', 'style' => 2],
    ['value' => 'İşlem', 'style' => 2],
    ['value' => 'Özet', 'style' => 2],
    ['value' => 'Tarih', 'style' => 2],
]];
foreach ($report['audit_trail_list'] as $row) {
    $auditTrailRows[] = [
        ['value' => $row['company_name'], 'style' => 3],
        ['value' => $row['actor_name'], 'style' => 3],
        ['value' => $row['entity_label'], 'style' => 3],
        ['value' => $row['action_label'], 'style' => 3],
        ['value' => ($row['summary'] ?? ''), 'style' => 3],
        ['value' => ($row['created_at'] ?? ''), 'style' => 3],
    ];
}

$temporaryPath = tempnam(sys_get_temp_dir(), 'qms-report-');
if ($temporaryPath === false) {
    throw new RuntimeException('Geçici dosya oluşturulamadı.');
}

try {
    createXlsxFile([
        ['name' => 'Yönetici Özeti', 'xml' => xlsxWorksheet($summaryRows, [38, 24], ['A1:B1'])],
        ['name' => 'Şirket Performansı', 'xml' => xlsxWorksheet($companyRows, [32, 14, 18, 22, 14, 18, 16, 18, 16, 18, 14, 14])],
        ['name' => 'Aylık Trend', 'xml' => xlsxWorksheet($trendRows, [16, 16, 20])],
        ['name' => 'Denetimler', 'xml' => xlsxWorksheet($auditRows, [28, 34, 18, 16, 18])],
        ['name' => 'Uygunsuzluklar', 'xml' => xlsxWorksheet($nonconformityRows, [28, 34, 16, 18, 22, 16])],
        ['name' => 'Düzeltici Faaliyetler', 'xml' => xlsxWorksheet($actionRows, [28, 40, 22, 18, 16, 18])],
        ['name' => 'Risk Kaydı', 'xml' => xlsxWorksheet($riskRows, [28, 34, 20, 14, 14, 16, 18, 16])],
        ['name' => 'Eğitimler', 'xml' => xlsxWorksheet($trainingRows, [28, 34, 20, 24, 16, 16, 16, 14])],
        ['name' => 'Tedarikçiler', 'xml' => xlsxWorksheet($supplierRows, [28, 30, 14, 20, 12, 14, 16, 12, 18])],
        ['name' => 'Şikayetler', 'xml' => xlsxWorksheet($complaintRows, [28, 14, 34, 14, 12, 16, 14, 14, 14, 14])],
        ['name' => 'Hedefler', 'xml' => xlsxWorksheet($performanceRows, [28, 26, 12, 10, 26])],
        ['name' => 'Gözden Geçirmeler', 'xml' => xlsxWorksheet($reviewRows, [28, 34, 14, 24, 14, 10, 10, 14])],
        ['name' => 'Denetim Programları', 'xml' => xlsxWorksheet($auditProgramRows, [28, 34, 10, 16, 12, 12])],
        ['name' => 'Ekipman', 'xml' => xlsxWorksheet($equipmentRows, [28, 30, 14, 16, 20, 16, 12])],
        ['name' => 'Memnuniyet', 'xml' => xlsxWorksheet($satisfactionRows, [28, 24, 14, 14, 40])],
        ['name' => 'Personel', 'xml' => xlsxWorksheet($personnelRows, [28, 26, 14, 20, 24, 12])],
        ['name' => 'Dış Denetimler', 'xml' => xlsxWorksheet($externalAuditRows, [28, 34, 18, 20, 14, 14, 12])],
        ['name' => 'Kalite Maliyeti', 'xml' => xlsxWorksheet($qualityCostRows, [28, 34, 18, 14, 14])],
        ['name' => 'COQ Trendi', 'xml' => xlsxWorksheet($qualityCostTrendRows, [16, 14, 14, 14, 14, 16])],
        ['name' => 'Dağıtım', 'xml' => xlsxWorksheet($copyRows, [28, 40, 12, 20, 14, 14])],
        ['name' => 'Onay Akışları', 'xml' => xlsxWorksheet($approvalRunRows, [28, 40, 16, 20])],
        ['name' => 'İç Anket', 'xml' => xlsxWorksheet($internalSurveyRows, [28, 40, 12, 12, 16])],
        ['name' => 'İyileştirme', 'xml' => xlsxWorksheet($improvementRows, [28, 40, 18, 14, 12, 16, 14])],
        ['name' => 'Sözleşmeler', 'xml' => xlsxWorksheet($contractRows, [28, 40, 14, 20, 14, 16])],
        ['name' => 'Ölçü Aletleri', 'xml' => xlsxWorksheet($instrumentRows, [28, 34, 18, 20, 16, 16])],
        ['name' => 'Olaylar', 'xml' => xlsxWorksheet($incidentRows, [28, 40, 16, 14, 14, 16])],
        ['name' => 'Teslimat', 'xml' => xlsxWorksheet($deliveryRows, [28, 28, 12, 12, 14, 16, 16, 18])],
        ['name' => 'Kalibrasyonlar', 'xml' => xlsxWorksheet($calibrationRows, [28, 28, 18, 14, 14, 14, 24, 18, 20])],
        ['name' => 'Denetim İzi', 'xml' => xlsxWorksheet($auditTrailRows, [28, 22, 24, 18, 50, 20])],
    ], $temporaryPath);

    $filename = 'qms-yonetim-raporu-' . date('Y-m-d') . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($temporaryPath));
    header('Cache-Control: private, no-store, max-age=0');
    readfile($temporaryPath);
} finally {
    @unlink($temporaryPath);
}
