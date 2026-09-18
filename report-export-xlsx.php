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

$temporaryPath = tempnam(sys_get_temp_dir(), 'qms-report-');
if ($temporaryPath === false) {
    throw new RuntimeException('Geçici dosya oluşturulamadı.');
}

try {
    createXlsxFile([
        ['name' => 'Yönetici Özeti', 'xml' => xlsxWorksheet($summaryRows, [38, 24], ['A1:B1'])],
        ['name' => 'Şirket Performansı', 'xml' => xlsxWorksheet($companyRows, [32, 14, 18, 22, 14, 18])],
        ['name' => 'Aylık Trend', 'xml' => xlsxWorksheet($trendRows, [16, 16, 20])],
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
