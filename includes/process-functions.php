<?php

declare(strict_types=1);

/**
 * Süreç Envanteri / Proses Yönetimi modulu yardimcilari.
 *
 * Şirketin süreçlerini envanter olarak tutar: kod, ad, departman, sahip,
 * amaç, girdiler/çıktılar, KPI ve gözden geçirme tarihi.
 */

require_once __DIR__ . '/access.php';

const QMS_PROCESS_STATUSES = ['active', 'paused'];

/** Kapsam içindeki süreçler (ad bazında sıralı). */
function qmsProcessList(PDO $pdo, int $userId, string $role): array
{
    $scope = qmsCompanyScope('p.company_id', qmsVisibleCompanyIds($pdo, $userId, $role));
    $stmt = $pdo->prepare(
        'SELECT p.*, c.company_name
         FROM processes p
         INNER JOIN companies c ON c.id = p.company_id
         WHERE p.active = 1' . $scope['sql'] . '
         ORDER BY p.process_name ASC, p.id ASC'
    );
    $stmt->execute($scope['params']);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Kapsam içindeki tek süreç; bulunamazsa bos dizi. */
function qmsProcessFind(PDO $pdo, int $id, int $userId, string $role): array
{
    $scope = qmsCompanyScope('company_id', qmsVisibleCompanyIds($pdo, $userId, $role));
    $stmt = $pdo->prepare('SELECT * FROM processes WHERE id = ? AND active = 1' . $scope['sql'] . ' LIMIT 1');
    $stmt->execute(array_merge([$id], $scope['params']));
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

/** Yeni süreç ekler; @return int|null */
function qmsProcessAdd(PDO $pdo, array $data, int $userId, string $role): ?int
{
    $companyId = (int) ($data['company_id'] ?? 0);
    $scope = qmsCompanyScope('companies.id', qmsVisibleCompanyIds($pdo, $userId, $role));
    $stmt = $pdo->prepare('SELECT companies.id FROM companies WHERE companies.id = ? AND companies.active = 1' . $scope['sql'] . ' LIMIT 1');
    $stmt->execute(array_merge([$companyId], $scope['params']));
    if (!$stmt->fetchColumn()) {
        return null;
    }
    $name = trim((string) ($data['process_name'] ?? ''));
    if ($name === '') {
        return null;
    }
    $status = (string) ($data['status'] ?? 'active');
    if (!in_array($status, QMS_PROCESS_STATUSES, true)) {
        $status = 'active';
    }
    $ins = $pdo->prepare('INSERT INTO processes
        (company_id, process_code, process_name, department, owner_name, objective, inputs, outputs, kpi, review_date, status, active, created_by)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,1,?)');
    $ins->execute([
        $companyId,
        trim((string) ($data['process_code'] ?? '')) !== '' ? mb_substr(trim((string) $data['process_code']), 0, 40) : null,
        mb_substr($name, 0, 190),
        trim((string) ($data['department'] ?? '')) !== '' ? mb_substr(trim((string) $data['department']), 0, 120) : null,
        trim((string) ($data['owner_name'] ?? '')) !== '' ? mb_substr(trim((string) $data['owner_name']), 0, 180) : null,
        trim((string) ($data['objective'] ?? '')) !== '' ? mb_substr((string) $data['objective'], 0, 4000) : null,
        trim((string) ($data['inputs'] ?? '')) !== '' ? mb_substr((string) $data['inputs'], 0, 4000) : null,
        trim((string) ($data['outputs'] ?? '')) !== '' ? mb_substr((string) $data['outputs'], 0, 4000) : null,
        trim((string) ($data['kpi'] ?? '')) !== '' ? mb_substr((string) $data['kpi'], 0, 500) : null,
        preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($data['review_date'] ?? '')) ? (string) $data['review_date'] : null,
        $status,
        $userId ?: null,
    ]);
    return (int) $pdo->lastInsertId();
}

/** Süreci gunceller (kapsam içinde olmali). */
function qmsProcessUpdate(PDO $pdo, int $id, array $data, int $userId, string $role): bool
{
    if (!qmsProcessFind($pdo, $id, $userId, $role)) {
        return false;
    }
    $name = trim((string) ($data['process_name'] ?? ''));
    if ($name === '') {
        return false;
    }
    $status = (string) ($data['status'] ?? 'active');
    if (!in_array($status, QMS_PROCESS_STATUSES, true)) {
        $status = 'active';
    }
    $pdo->prepare('UPDATE processes SET
        process_code=?, process_name=?, department=?, owner_name=?, objective=?, inputs=?, outputs=?, kpi=?, review_date=?, status=? WHERE id=?')->execute([
        trim((string) ($data['process_code'] ?? '')) !== '' ? mb_substr(trim((string) $data['process_code']), 0, 40) : null,
        mb_substr($name, 0, 190),
        trim((string) ($data['department'] ?? '')) !== '' ? mb_substr(trim((string) $data['department']), 0, 120) : null,
        trim((string) ($data['owner_name'] ?? '')) !== '' ? mb_substr(trim((string) $data['owner_name']), 0, 180) : null,
        trim((string) ($data['objective'] ?? '')) !== '' ? mb_substr((string) $data['objective'], 0, 4000) : null,
        trim((string) ($data['inputs'] ?? '')) !== '' ? mb_substr((string) $data['inputs'], 0, 4000) : null,
        trim((string) ($data['outputs'] ?? '')) !== '' ? mb_substr((string) $data['outputs'], 0, 4000) : null,
        trim((string) ($data['kpi'] ?? '')) !== '' ? mb_substr((string) $data['kpi'], 0, 500) : null,
        preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($data['review_date'] ?? '')) ? (string) $data['review_date'] : null,
        $status,
        $id,
    ]);
    return true;
}

/** Süreci siler (aktif=0), kapsam içinde olmali. */
function qmsProcessDelete(PDO $pdo, int $id, int $userId, string $role): bool
{
    $scope = qmsCompanyScope('company_id', qmsVisibleCompanyIds($pdo, $userId, $role));
    $stmt = $pdo->prepare('UPDATE processes SET active = 0 WHERE id = ?' . $scope['sql']);
    $stmt->execute(array_merge([$id], $scope['params']));
    return $stmt->rowCount() > 0;
}

/** Durum etiketi metni (TR). */
function qmsProcessStatusLabel(string $status): string
{
    return ['active' => 'Aktif', 'paused' => 'Askıda'][$status] ?? $status;
}
