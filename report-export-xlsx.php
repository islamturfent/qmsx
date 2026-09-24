<?php

declare(strict_types=1);

session_start();

if (!isset($_SESSION['qms_logged_in']) || $_SESSION['qms_logged_in'] !== true) {
    http_response_code(403);
    exit('Yetkisiz erişim.');
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/report-export-data.php';
require_once __DIR__ . '/includes/xlsx-writer.php';

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
];

$summaryRows = [
    [['value' => 'QMS Yönetim Raporu', 'style' => 1]],
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
