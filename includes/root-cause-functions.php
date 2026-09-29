<?php

declare(strict_types=1);

/**
 * Kok neden analizi (5 Neden / fishbone) icin yardimcilar.
 *
 * Analiz bir uygunsuzluga baglidir; her uygunsuzluk icin tek kayit (upsert).
 * Kapsam, uygunsuzlugun sirketi uzerinden islenir (qmsNonconformityFind / qmsCompanyScope).
 */

require_once __DIR__ . '/access.php';

/** Bir uygunsuzlugun kok neden analizi kaydi (varsa). @return array<string,mixed> */
function qmsRootCauseFind(PDO $pdo, int $nonconformityId): array
{
    $stmt = $pdo->prepare('SELECT * FROM nc_root_cause WHERE nonconformity_id = ? AND active = 1 LIMIT 1');
    $stmt->execute([$nonconformityId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Analizi ekler veya gunceller (upsert).
 *
 * @param array<string,mixed> $d why1..why5, root_cause, corrective_action, preventive_action, status
 */
function qmsRootCauseSave(PDO $pdo, int $nonconformityId, array $d, int $userId): void
{
    $fields = ['why1', 'why2', 'why3', 'why4', 'why5', 'root_cause', 'corrective_action', 'preventive_action'];
    $data = [];
    foreach ($fields as $f) {
        $v = isset($d[$f]) ? trim((string) $d[$f]) : '';
        $data[$f] = $v !== '' ? $v : null;
    }
    $status = (string) ($d['status'] ?? 'open');
    if (!in_array($status, ['open', 'done'], true)) {
        $status = 'open';
    }
    // Kok neden doluysa otomatik tamamlanmis sayilir.
    if ($data['root_cause'] !== null) {
        $status = 'done';
    }

    $existing = qmsRootCauseFind($pdo, $nonconformityId);
    if ($existing) {
        $sql = 'UPDATE nc_root_cause SET why1=:why1, why2=:why2, why3=:why3, why4=:why4, why5=:why5,
                root_cause=:root_cause, corrective_action=:corrective_action,
                preventive_action=:preventive_action, status=:status, updated_by=:updated_by
                WHERE id=:id';
        $pdo->prepare($sql)->execute(array_merge($data, [
            'status' => $status, 'updated_by' => $userId ?: null, 'id' => (int) $existing['id'],
        ]));
        return;
    }

    $pdo->prepare('INSERT INTO nc_root_cause
        (nonconformity_id, why1, why2, why3, why4, why5, root_cause, corrective_action,
         preventive_action, status, created_by, updated_by, active)
        VALUES (:nonconformity_id, :why1, :why2, :why3, :why4, :why5, :root_cause,
                :corrective_action, :preventive_action, :status, :created_by, :updated_by, 1)')
        ->execute(array_merge([
            'nonconformity_id' => $nonconformityId,
            'created_by' => $userId ?: null,
        ], $data, [
            'status' => $status, 'updated_by' => $userId ?: null,
        ]));
}

/**
 * Analiz izlenecek uygunsuzluk listesi (analiz durumu ile).
 *
 * @return array<int, array<string,mixed>>
 */
function qmsRootCauseNcList(PDO $pdo, int $userId, string $role, int $companyId): array
{
    $scope = qmsCompanyScope('n.company_id', qmsVisibleCompanyIds($pdo, $userId, $role));
    $params = $scope['params'];
    $where = 'n.active = 1' . $scope['sql'];
    if ($companyId > 0) {
        $where .= ' AND n.company_id = ?';
        $params[] = $companyId;
    }

    $stmt = $pdo->prepare(
        'SELECT n.id, n.title, n.severity, n.status AS nc_status, co.company_name,
                rc.id AS rc_id, rc.status AS rc_status
         FROM nonconformities n
         INNER JOIN companies co ON co.id = n.company_id
         LEFT JOIN nc_root_cause rc ON rc.nonconformity_id = n.id AND rc.active = 1
         WHERE ' . $where . '
         ORDER BY n.created_at DESC, n.id DESC'
    );
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
