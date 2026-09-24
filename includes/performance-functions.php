<?php

declare(strict_types=1);

/**
 * Performans yonetimi modulu yardimcilari.
 *
 * Guncel (gerceklesen) KPI degeri rapor motorundan okunur (includes/
 * report-export-data.php); bu tablo yalnizca hedef ve yil bilgisini tasir.
 * Boylece iki ayri KPI hesabi birbirinden sapmaz - tek kaynak rapor motorudur.
 */

require_once __DIR__ . '/access.php';

/**
 * Hedeflenebilir KPI listesi. Anahtar, rapor motorundaki metrik anahtariyla
 * birebir aynidir; boylece gercek deger tek kaynaktan okunur.
 *
 * @return array<string, array{label_key: string, unit: string, higher_better: bool}>
 */
function qmsPerformanceKpis(): array
{
    return [
        'audit_count' => ['label_key' => 'auditCountKpi', 'unit' => '', 'higher_better' => true, 'icon' => 'check'],
        'nonconformity_rate' => ['label_key' => 'nonconformityRateKpi', 'unit' => '%', 'higher_better' => false, 'icon' => 'alert'],
        'action_completion_rate' => ['label_key' => 'actionCompletionKpi', 'unit' => '%', 'higher_better' => true, 'icon' => 'trend'],
        'average_close_days' => ['label_key' => 'averageClosureKpi', 'unit' => ' gün', 'higher_better' => false, 'icon' => 'clock'],
        'review_due_documents' => ['label_key' => 'reviewDueDocumentsLabel', 'unit' => '', 'higher_better' => false, 'icon' => 'documents'],
        'training_completion_rate' => ['label_key' => 'trainingCompletionKpi', 'unit' => '%', 'higher_better' => true, 'icon' => 'training'],
        'supplier_average_score' => ['label_key' => 'supplierScoreKpi', 'unit' => '', 'higher_better' => true, 'icon' => 'suppliers'],
        'complaint_open_count' => ['label_key' => 'complaintOpenKpi', 'unit' => '', 'higher_better' => false, 'icon' => 'complaints'],
    ];
}

/** Gecerli KPI anahtarlari (hedef kaydederken dogrulama icin). */
function qmsPerformanceKpiKeys(): array
{
    return array_keys(qmsPerformanceKpis());
}

/**
 * Cikti (Excel/PDF) icin Turkce KPI etiketleri.
 *
 * @return array<string, string>
 */
function qmsPerformanceKpiLabels(): array
{
    return [
        'audit_count' => 'Denetim Sayısı',
        'nonconformity_rate' => 'Denetim Başına Uygunsuzluk (%)',
        'action_completion_rate' => 'Aksiyon Tamamlama (%)',
        'average_close_days' => 'Ortalama Kapanma (gün)',
        'review_due_documents' => 'Gözden Geçirilecek Doküman',
        'training_completion_rate' => 'Eğitim Tamamlama (%)',
        'supplier_average_score' => 'Tedarikçi Ortalama Puanı',
        'complaint_open_count' => 'Açık Şikayet',
    ];
}

/**
 * Bir sirketin yil icin hedefleri.
 *
 * @return array<string, array{id: int, target_value: float, note: ?string}>
 */
function qmsPerformanceTargets(PDO $pdo, int $companyId, int $year): array
{
    $stmt = $pdo->prepare(
        'SELECT id, kpi_key, target_value, note
         FROM performance_targets
         WHERE company_id = :company_id AND target_year = :year'
    );
    $stmt->execute(['company_id' => $companyId, 'year' => $year]);
    $targets = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $targets[$row['kpi_key']] = [
            'id' => (int) $row['id'],
            'target_value' => (float) $row['target_value'],
            'note' => $row['note'],
        ];
    }

    return $targets;
}

/**
 * Hedef ekler veya gunceller (sirket + KPI + yil tektir; kaydetmek uzerine yazar).
 *
 * @throws InvalidArgumentException Gecersiz KPI anahtari veya hedef degeri.
 */
function qmsPerformanceTargetSave(PDO $pdo, int $companyId, string $kpiKey, int $year, float $targetValue, ?string $note, int $userId): void
{
    if (!in_array($kpiKey, qmsPerformanceKpiKeys(), true)) {
        throw new InvalidArgumentException('Geçersiz KPI anahtarı.');
    }

    if ($targetValue < 0 || $targetValue > 999999) {
        throw new InvalidArgumentException('Hedef değer geçerli değil.');
    }

    $stmt = $pdo->prepare(
        'INSERT INTO performance_targets (company_id, kpi_key, target_value, target_year, note, created_by, updated_by)
         VALUES (:company_id, :kpi_key, :target_value, :target_year, :note, :created_by, :updated_by)
         ON DUPLICATE KEY UPDATE target_value = VALUES(target_value), note = VALUES(note), updated_by = VALUES(updated_by)'
    );
    $stmt->execute([
        'company_id' => $companyId,
        'kpi_key' => $kpiKey,
        'target_value' => $targetValue,
        'target_year' => $year,
        'note' => $note,
        'created_by' => $userId ?: null,
        'updated_by' => $userId ?: null,
    ]);
}

/**
 * Gercek degerin hedefi karsilayip karsilamadigi.
 *
 * @param bool $higherBetter true ise buyuk olan daha iyidir.
 * @return bool|null null: hedef yok veya gercek deger yok.
 */
function qmsPerformanceOnTrack(?float $target, ?float $actual, bool $higherBetter): ?bool
{
    if ($target === null || $actual === null) {
        return null;
    }

    return $higherBetter ? $actual >= $target : $actual <= $target;
}
