<?php

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Performans indexleri (idempotent).
 * Yalnizca eksik olan indexler eklenir; mevcut veriye dokunmaz.
 */
require dirname(__DIR__) . '/config/database.php';

$indexes = [
    // Geciken isler / is merkezi sorgulari: status + due tarihi birlikte.
    ['corrective_actions', 'idx_ca_status_due', '(status, due_date)'],
    ['nonconformities', 'idx_nc_status_due', '(status, due_date)'],
    ['complaints', 'idx_complaints_status_due', '(status, due_date)'],
    ['documents', 'idx_documents_review_status', '(review_date, status)'],
    ['trainings', 'idx_trainings_planned_status', '(planned_date, status)'],
    ['external_audit_findings', 'idx_ef_status_due', '(status, due_date)'],
    // COQ aylik trend / rapor: sirket + tarih.
    ['quality_costs', 'idx_qc_company_incurred', '(company_id, incurred_on)'],
    // CAPA uygunsuzluk bazinda faaliyet listesi.
    ['corrective_actions', 'idx_ca_nc_status', '(nonconformity_id, status)'],
];

$added = 0;
$skipped = 0;
$checked = [];

foreach ($indexes as [$table, $index, $columns]) {
    if (isset($checked[$table][$index])) {
        continue;
    }
    $exists = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.statistics
         WHERE table_schema = DATABASE() AND table_name = '" . $table . "' AND index_name = '" . $index . "'"
    )->fetchColumn();
    $checked[$table][$index] = true;
    if ($exists > 0) {
        $skipped++;
        continue;
    }
    // Uygulanacak kolonlar tabloda varsa index ekle.
    $hasColumns = true;
    $cols = preg_replace('/[()]/', '', $columns);
    foreach (explode(',', $cols) as $col) {
        $c = trim($col);
        $colExists = (int) $pdo->query(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = '" . $table . "' AND column_name = '" . $c . "'"
        )->fetchColumn();
        if ($colExists === 0) {
            $hasColumns = false;
            break;
        }
    }
    if (!$hasColumns) {
        $skipped++;
        continue;
    }
    $pdo->exec('ALTER TABLE ' . $table . ' ADD INDEX ' . $index . ' ' . $columns);
    $added++;
    echo "Added index $index on $table\n";
}

echo "Index migration complete: $added added, $skipped already present/skipped.\n";
