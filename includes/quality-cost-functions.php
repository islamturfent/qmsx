<?php

declare(strict_types=1);

/**
 * Kalite maliyeti (COQ) modulu yardimcilari.
 *
 * Onleme / degerlendirme / ic hata / dis hata maliyetlerinin kaydi ve donemsel
 * toplami. Kapsam tek kaynaktan gelir (includes/access.php).
 */

require_once __DIR__ . '/access.php';

/** COQ maliyet kategorileri. */
const QMS_COST_TYPES = ['prevention', 'appraisal', 'internal_failure', 'external_failure'];

/** @return array<string, string> */
function qmsCostTypeLabels(): array
{
    return [
        'prevention' => 'Önleme',
        'appraisal' => 'Değerlendirme',
        'internal_failure' => 'İç Hata',
        'external_failure' => 'Dış Hata',
    ];
}

/** @return array<string, string> */
function qmsCostTypeI18nKeys(): array
{
    return [
        'prevention' => 'costTypePreventionLabel',
        'appraisal' => 'costTypeAppraisalLabel',
        'internal_failure' => 'costTypeInternalFailureLabel',
        'external_failure' => 'costTypeExternalFailureLabel',
    ];
}

/**
 * Kapsam icindeki maliyet kayitlari (en yeniden eskiye) + opsiyonel filtre.
 *
 * @return array<int, array<string, mixed>>
 */
function qmsQualityCostList(PDO $pdo, int $userId, string $role, string $type = '', string $from = '', string $to = ''): array
{
    $scope = qmsCompanyScope('c.company_id', qmsVisibleCompanyIds($pdo, $userId, $role));
    $sql = 'SELECT c.*, companies.company_name, users.full_name AS creator_name
            FROM quality_costs c
            INNER JOIN companies ON companies.id = c.company_id
            LEFT JOIN users ON users.id = c.created_by
            WHERE c.active = 1' . $scope['sql'];
    $params = $scope['params'];

    if ($type !== '' && in_array($type, QMS_COST_TYPES, true)) {
        $sql .= ' AND c.cost_type = ?';
        $params[] = $type;
    }
    if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
        $sql .= ' AND c.incurred_on >= ?';
        $params[] = $from;
    }
    if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        $sql .= ' AND c.incurred_on <= ?';
        $params[] = $to;
    }
    $sql .= ' ORDER BY c.incurred_on DESC, c.id DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Kapsam icindeki maliyet ozeti (kategori bazinda + toplam).
 *
 * @return array<string, float|int> total, prevention, appraisal, internal_failure,
 *         external_failure, failure_total, entry_count
 */
function qmsQualityCostSummary(PDO $pdo, int $userId, string $role, string $from = '', string $to = ''): array
{
    $list = qmsQualityCostList($pdo, $userId, $role, '', $from, $to);
    $sums = ['prevention' => 0.0, 'appraisal' => 0.0, 'internal_failure' => 0.0, 'external_failure' => 0.0];
    foreach ($list as $item) {
        $type = $item['cost_type'];
        if (isset($sums[$type])) {
            $sums[$type] += (float) $item['amount'];
        }
    }
    return [
        'total' => round(array_sum($sums), 2),
        'prevention' => round($sums['prevention'], 2),
        'appraisal' => round($sums['appraisal'], 2),
        'internal_failure' => round($sums['internal_failure'], 2),
        'external_failure' => round($sums['external_failure'], 2),
        'failure_total' => round($sums['internal_failure'] + $sums['external_failure'], 2),
        'entry_count' => count($list),
    ];
}

/**
 * Yeni maliyet kaydi ekler (sirket kapsam icinde olmali).
 *
 * @param array{
 *     company_id: int, cost_type: string, title: string, amount: string,
 *     incurred_on: string, notes: string
 * } $data
 * @return int|null Yeni kayit id'si; dogrulama basarisizsa null.
 */
function qmsQualityCostAdd(PDO $pdo, array $data, int $userId, string $role): ?int
{
    $companyId = (int) ($data['company_id'] ?? 0);
    $scope = qmsCompanyScope('companies.id', qmsVisibleCompanyIds($pdo, $userId, $role));
    $stmt = $pdo->prepare('SELECT companies.id FROM companies WHERE companies.id = ? AND companies.active = 1' . $scope['sql'] . ' LIMIT 1');
    $stmt->execute(array_merge([$companyId], $scope['params']));
    if (!$stmt->fetchColumn()) {
        return null;
    }

    $type = (string) ($data['cost_type'] ?? '');
    $title = trim((string) ($data['title'] ?? ''));
    $amount = (float) ($data['amount'] ?? 0);
    $incurredOn = trim((string) ($data['incurred_on'] ?? ''));
    if ($title === '' || !in_array($type, QMS_COST_TYPES, true) || $amount < 0) {
        return null;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $incurredOn) !== 1) {
        return null;
    }

    $insert = $pdo->prepare(
        'INSERT INTO quality_costs
            (company_id, cost_type, title, amount, incurred_on, notes, active, created_by)
         VALUES (?,?,?,?,?,?,1,?)'
    );
    $insert->execute([
        $companyId,
        $type,
        mb_substr($title, 0, 255),
        round($amount, 2),
        $incurredOn,
        trim((string) ($data['notes'] ?? '')) !== '' ? mb_substr(trim((string) $data['notes']), 0, 4000) : null,
        $userId ?: null,
    ]);

    return (int) $pdo->lastInsertId();
}

/**
 * Aylik COQ trendi (kategori bazinda toplamlarla).
 *
 * Secilen yil icindeki 12 ay icin her kategorinin toplamini ve toplam maliyeti
 * dondurur. Opsiyonel sirket filtrelesi desteklenir.
 *
 * @return array<int, array<string, float>> 1..12 -> {prevention, appraisal,
 *         internal_failure, external_failure, total}
 */
function qmsQualityCostMonthlyTrend(PDO $pdo, int $userId, string $role, int $year, int $companyId = 0): array
{
    $scope = qmsCompanyScope('c.company_id', qmsVisibleCompanyIds($pdo, $userId, $role));
    $params = $scope['params'];
    $sql = 'SELECT c.cost_type, c.amount, MONTH(c.incurred_on) AS m
            FROM quality_costs c
            WHERE c.active = 1' . $scope['sql'] . '
              AND YEAR(c.incurred_on) = ?';
    $params[] = $year;

    if ($companyId > 0) {
        $sql .= ' AND c.company_id = ?';
        $params[] = $companyId;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $init = array_fill(1, 12, ['prevention' => 0.0, 'appraisal' => 0.0, 'internal_failure' => 0.0, 'external_failure' => 0.0, 'total' => 0.0]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $m = max(1, min(12, (int) $row['m']));
        $type = (string) $row['cost_type'];
        if (!isset($init[$m][$type])) {
            continue;
        }
        $v = (float) $row['amount'];
        $init[$m][$type] += $v;
        $init[$m]['total'] += $v;
    }
    foreach ($init as $m => $values) {
        foreach (['prevention', 'appraisal', 'internal_failure', 'external_failure', 'total'] as $k) {
            $init[$m][$k] = round($values[$k], 2);
        }
    }
    return $init;
}

/**
 * Bir maliyet kaydini siler (aktif = 0), kapsam icinde olmalidir.
 */
function qmsQualityCostDelete(PDO $pdo, int $costId, int $userId, string $role): bool
{
    if ($costId <= 0) {
        return false;
    }
    $scope = qmsCompanyScope('company_id', qmsVisibleCompanyIds($pdo, $userId, $role));
    $stmt = $pdo->prepare('UPDATE quality_costs SET active = 0 WHERE id = ?' . $scope['sql']);
    $stmt->execute(array_merge([$costId], $scope['params']));
    return $stmt->rowCount() > 0;
}
