<?php

declare(strict_types=1);

/**
 * Tedarikci yonetimi modulu yardimcilari.
 *
 * Durum/risk/karar listeleri, alan dogrulama, kapsamli okuma ve bildirim
 * kurallari tek yerde tutulur; sayfalar kendi kopyalarini yazmaz. Kapsam tek
 * kaynaktan gelir (includes/access.php).
 */

require_once __DIR__ . '/access.php';
require_once __DIR__ . '/notifications.php';

/** Onay durumu akisi: aday -> onayli -> askida -> cikarildi. */
const QMS_SUPPLIER_STATUSES = ['candidate', 'approved', 'suspended', 'removed'];

/** Tedarikci risk sinifi. */
const QMS_SUPPLIER_RISK_CLASSES = ['low', 'medium', 'high'];

/** Degerlendirme karari. */
const QMS_SUPPLIER_EVALUATION_RESULTS = ['acceptable', 'conditional', 'unacceptable'];

/** @return array<string, string> */
function qmsSupplierStatusLabels(): array
{
    return [
        'candidate' => 'Aday',
        'approved' => 'Onaylı',
        'suspended' => 'Askıda',
        'removed' => 'Çıkarıldı',
    ];
}

/** @return array<string, string> */
function qmsSupplierStatusI18nKeys(): array
{
    return [
        'candidate' => 'supplierStatusCandidateLabel',
        'approved' => 'supplierStatusApprovedLabel',
        'suspended' => 'supplierStatusSuspendedLabel',
        'removed' => 'supplierStatusRemovedLabel',
    ];
}

/** @return array<string, string> */
function qmsSupplierRiskLabels(): array
{
    return [
        'low' => 'Düşük',
        'medium' => 'Orta',
        'high' => 'Yüksek',
    ];
}

/** @return array<string, string> */
function qmsSupplierRiskI18nKeys(): array
{
    return [
        'low' => 'supplierRiskLowLabel',
        'medium' => 'supplierRiskMediumLabel',
        'high' => 'supplierRiskHighLabel',
    ];
}

/** @return array<string, string> */
function qmsSupplierResultLabels(): array
{
    return [
        'acceptable' => 'Kabul Edilebilir',
        'conditional' => 'Şartlı',
        'unacceptable' => 'Kabul Edilemez',
    ];
}

/** @return array<string, string> */
function qmsSupplierResultI18nKeys(): array
{
    return [
        'acceptable' => 'supplierResultAcceptableLabel',
        'conditional' => 'supplierResultConditionalLabel',
        'unacceptable' => 'supplierResultUnacceptableLabel',
    ];
}

function qmsSupplierText(mixed $value, int $max): string
{
    return mb_substr(trim((string) $value), 0, $max);
}

/** Gecerli bir tarih (Y-m-d) degilse null. */
function qmsSupplierDate(mixed $value): ?string
{
    $value = trim((string) $value);

    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
}

/** Puan: bos ise null, gecersiz veya 0-100 disiysa null. */
function qmsSupplierScore(mixed $value): ?float
{
    $value = str_replace(',', '.', trim((string) $value));

    if ($value === '' || !is_numeric($value)) {
        return null;
    }

    $score = (float) $value;

    return $score >= 0 && $score <= 100 ? round($score, 2) : null;
}

/**
 * Bir degerlendirmenin toplam puani: verilen alt puanlarin ortalamasi.
 *
 * @param array<string, mixed> $evaluation
 */
function qmsSupplierEvaluationTotal(array $evaluation): ?float
{
    $scores = [];

    foreach (['quality_score', 'delivery_score', 'service_score'] as $column) {
        if (isset($evaluation[$column]) && $evaluation[$column] !== null && $evaluation[$column] !== '') {
            $scores[] = (float) $evaluation[$column];
        }
    }

    return $scores === [] ? null : round(array_sum($scores) / count($scores), 2);
}

/**
 * Degerlendirme ozeti: adet, ortalama puan, son degerlendirme.
 *
 * @param array<int, array<string, mixed>> $evaluations
 * @return array{count: int, average: ?float, latest: ?float, latest_date: ?string, latest_result: ?string}
 */
function qmsSupplierEvaluationSummary(array $evaluations): array
{
    $totals = [];
    $latest = null;
    $latestDate = null;
    $latestResult = null;

    foreach ($evaluations as $evaluation) {
        $total = qmsSupplierEvaluationTotal($evaluation);
        if ($total !== null) {
            $totals[] = $total;
        }
        $date = (string) ($evaluation['evaluated_on'] ?? '');
        if ($latestDate === null || $date > $latestDate) {
            $latestDate = $date !== '' ? $date : null;
            $latest = $total;
            $latestResult = (string) ($evaluation['result'] ?? '');
        }
    }

    return [
        'count' => count($evaluations),
        'average' => $totals === [] ? null : round(array_sum($totals) / count($totals), 2),
        'latest' => $latest,
        'latest_date' => $latestDate,
        'latest_result' => $latestResult,
    ];
}

/**
 * Kapsam icindeki tek tedarikci kaydi.
 *
 * Kayit id ile tek basina okunmaz: kapsam cumlesi sorgunun parcasidir, boylece
 * id degistirilerek baska sirketin tedarikcisi acilamaz.
 *
 * @return array<string, mixed> Bos dizi: kayit yok veya kapsam disi.
 */
function qmsSupplierFind(PDO $pdo, int $supplierId, int $userId, string $role): array
{
    $scope = qmsCompanyScope('suppliers.company_id', qmsVisibleCompanyIds($pdo, $userId, $role));

    $stmt = $pdo->prepare(
        'SELECT suppliers.*, companies.company_name
         FROM suppliers
         INNER JOIN companies ON companies.id = suppliers.company_id
         WHERE suppliers.id = ? AND suppliers.active = 1' . $scope['sql'] . ' LIMIT 1'
    );
    $stmt->execute(array_merge([$supplierId], $scope['params']));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: [];
}

/**
 * Kapsam icindeki tedarikci kayitlari.
 *
 * @return array<int, array<string, mixed>>
 */
function qmsSupplierList(PDO $pdo, int $userId, string $role): array
{
    $scope = qmsCompanyScope('suppliers.company_id', qmsVisibleCompanyIds($pdo, $userId, $role));

    $stmt = $pdo->prepare(
        'SELECT suppliers.*, companies.company_name
         FROM suppliers
         INNER JOIN companies ON companies.id = suppliers.company_id
         WHERE suppliers.active = 1' . $scope['sql'] . '
         ORDER BY suppliers.name'
    );
    $stmt->execute($scope['params']);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Bir tedarikcinin degerlendirmeleri (en yeniden eskiye).
 *
 * @return array<int, array<string, mixed>>
 */
function qmsSupplierEvaluations(PDO $pdo, int $supplierId): array
{
    $stmt = $pdo->prepare(
        'SELECT supplier_evaluations.*, users.full_name AS evaluator_full_name
         FROM supplier_evaluations
         LEFT JOIN users ON users.id = supplier_evaluations.evaluator_user_id
         WHERE supplier_evaluations.supplier_id = :supplier_id
         ORDER BY supplier_evaluations.evaluated_on DESC, supplier_evaluations.id DESC'
    );
    $stmt->execute(['supplier_id' => $supplierId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Kapsam icindeki tek degerlendirme kaydi (duzenleme ve silme icin).
 *
 * @return array<string, mixed>
 */
function qmsSupplierEvaluationFind(PDO $pdo, int $evaluationId, int $userId, string $role): array
{
    $scope = qmsCompanyScope('suppliers.company_id', qmsVisibleCompanyIds($pdo, $userId, $role));

    $stmt = $pdo->prepare(
        'SELECT supplier_evaluations.*, suppliers.company_id
         FROM supplier_evaluations
         INNER JOIN suppliers ON suppliers.id = supplier_evaluations.supplier_id
         WHERE supplier_evaluations.id = ? AND suppliers.active = 1' . $scope['sql'] . ' LIMIT 1'
    );
    $stmt->execute(array_merge([$evaluationId], $scope['params']));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: [];
}

/**
 * Degerlendirme formunun dogrulamasi. Bos dize: kayit edilebilir.
 *
 * @param array{evaluated_on: string, quality_score: string, delivery_score: string, service_score: string, result: string} $data
 */
function qmsSupplierEvaluationError(array $data): string
{
    if (qmsSupplierDate($data['evaluated_on'] ?? '') === null) {
        return 'Geçerli bir değerlendirme tarihi girin.';
    }

    if (!in_array($data['result'] ?? '', QMS_SUPPLIER_EVALUATION_RESULTS, true)) {
        return 'Geçerli bir karar seçin.';
    }

    foreach (['quality_score', 'delivery_score', 'service_score'] as $field) {
        $raw = $data[$field] ?? '';
        if ($raw !== '' && qmsSupplierScore($raw) === null) {
            return 'Puanlar 0 ile 100 arasında olmalıdır.';
        }
    }

    return '';
}

/**
 * "Kabul edilemez" karari sirketin atanmis sistem adminlerine bildirilir.
 *
 * @param array{company_id: int, name: string, link: string, result: string, actor_user_id: int} $context
 * @return int Bildirim yazilan kullanici sayisi.
 */
function qmsSupplierNotifyEvaluation(PDO $pdo, array $context): int
{
    if (($context['result'] ?? '') !== 'unacceptable') {
        return 0;
    }

    return qmsNotifyCompanyAdmins(
        $pdo,
        (int) ($context['company_id'] ?? 0),
        'supplier_evaluation_unacceptable',
        'Tedarikçi değerlendirmesi kabul edilemez',
        (string) ($context['name'] ?? ''),
        (string) ($context['link'] ?? ''),
        (int) ($context['actor_user_id'] ?? 0)
    );
}

/**
 * Tedarikci durumu degistiginde gonderilen bildirim.
 *
 * Onay ve askiya alma yonetimin bilmesi gereken kararlardir; sirketin atanmis
 * sistem adminlerine bildirilir. Islem yapan kullanici kendisine bildirim almaz.
 *
 * @param array{company_id: int, name: string, link: string, previous_status: string, new_status: string, actor_user_id: int} $context
 * @return int Bildirim yazilan kullanici sayisi.
 */
function qmsSupplierNotifyStatusChange(PDO $pdo, array $context): int
{
    $newStatus = (string) ($context['new_status'] ?? '');
    $previousStatus = (string) ($context['previous_status'] ?? '');

    if ($newStatus === $previousStatus || !in_array($newStatus, ['approved', 'suspended'], true)) {
        return 0;
    }

    $titles = [
        'approved' => 'Tedarikçi onaylandı',
        'suspended' => 'Tedarikçi askıya alındı',
    ];
    $types = [
        'approved' => 'supplier_approved',
        'suspended' => 'supplier_suspended',
    ];

    return qmsNotifyCompanyAdmins(
        $pdo,
        (int) ($context['company_id'] ?? 0),
        $types[$newStatus],
        $titles[$newStatus],
        (string) ($context['name'] ?? ''),
        (string) ($context['link'] ?? ''),
        (int) ($context['actor_user_id'] ?? 0)
    );
}
