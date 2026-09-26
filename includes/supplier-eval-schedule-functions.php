<?php

declare(strict_types=1);

/**
 * Tedarikçi değerlendirme takvimi modulu yardimcilari.
 *
 * Tek seferlik puanlarin (supplier_evaluations) yaninda, tedarikçiler icin
 * periyodik (döngüsel) degerlendirme randevularini izler: planlanan degerlendirme,
 * tarihi ve durumu. "Vadesi gecti" goreli olarak due_date'ten turetilir.
 */

require_once __DIR__ . '/access.php';

const QMS_SUPPLIER_EVAL_STATUSES = ['planned', 'done', 'skipped'];
const QMS_SUPPLIER_EVAL_FILTERS = ['planned', 'done', 'skipped', 'overdue'];

/**
 * Kapsam içindeki degerlendirme takvimi kayitlari.
 * `eff_status` goreli durumu yansitir (planned ise ve tarih gectiyse 'overdue').
 *
 * @param string $statusFilter '' | planned | done | skipped | overdue
 */
function qmsSupplierEvalScheduleList(PDO $pdo, int $userId, string $role, string $statusFilter = ''): array
{
    $scope = qmsCompanyScope('se.company_id', qmsVisibleCompanyIds($pdo, $userId, $role));
    $sql = 'SELECT se.*, sp.name AS supplier_name, sp.supplier_code,
                   CASE WHEN se.status = "planned" AND se.due_date IS NOT NULL AND se.due_date < CURDATE()
                        THEN "overdue" ELSE se.status END AS eff_status
            FROM supplier_evaluation_schedule se
            INNER JOIN suppliers sp ON sp.id = se.supplier_id
            WHERE se.active = 1' . $scope['sql'];
    $params = $scope['params'];
    $filter = (string) $statusFilter;
    if (in_array($filter, QMS_SUPPLIER_EVAL_FILTERS, true)) {
        if ($filter === 'overdue') {
            $sql .= ' AND se.status = "planned" AND se.due_date IS NOT NULL AND se.due_date < CURDATE()';
        } else {
            $sql .= ' AND se.status = ?';
            $params[] = $filter;
        }
    }
    $sql .= ' ORDER BY (se.due_date IS NULL) ASC, se.due_date ASC, se.id DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Kapsam içindeki tek kayit; bulunamazsa bos dizi. */
function qmsSupplierEvalScheduleFind(PDO $pdo, int $id, int $userId, string $role): array
{
    $scope = qmsCompanyScope('se.company_id', qmsVisibleCompanyIds($pdo, $userId, $role));
    $stmt = $pdo->prepare(
        'SELECT se.*, sp.name AS supplier_name, sp.supplier_code
         FROM supplier_evaluation_schedule se
         INNER JOIN suppliers sp ON sp.id = se.supplier_id
         WHERE se.id = ? AND se.active = 1' . $scope['sql'] . ' LIMIT 1'
    );
    $stmt->execute(array_merge([$id], $scope['params']));
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Degerlendirme randevusu ekler. Supplier kapsam içinde olmali; company_id
 * tedarikcinin sirketinden alinir. @return int|null
 */
function qmsSupplierEvalScheduleAdd(PDO $pdo, array $data, int $userId, string $role): ?int
{
    $supplierId = (int) ($data['supplier_id'] ?? 0);
    $scope = qmsCompanyScope('sp.company_id', qmsVisibleCompanyIds($pdo, $userId, $role));
    $stmt = $pdo->prepare('SELECT sp.company_id FROM suppliers sp WHERE sp.id = ? AND sp.active = 1' . $scope['sql'] . ' LIMIT 1');
    $stmt->execute(array_merge([$supplierId], $scope['params']));
    $companyId = (int) $stmt->fetchColumn();
    if (!$companyId) {
        return null;
    }
    $cycle = trim((string) ($data['cycle_label'] ?? ''));
    if ($cycle === '') {
        return null;
    }
    $status = (string) ($data['status'] ?? 'planned');
    if (!in_array($status, QMS_SUPPLIER_EVAL_STATUSES, true)) {
        $status = 'planned';
    }
    $ins = $pdo->prepare('INSERT INTO supplier_evaluation_schedule
        (supplier_id, company_id, cycle_label, due_date, status, result, notes, active, created_by)
        VALUES (?,?,?,?,?,?,?,1,?)');
    $ins->execute([
        $supplierId,
        $companyId,
        mb_substr($cycle, 0, 80),
        preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($data['due_date'] ?? '')) ? (string) $data['due_date'] : null,
        $status,
        trim((string) ($data['result'] ?? '')) !== '' ? mb_substr(trim((string) $data['result']), 0, 100) : null,
        trim((string) ($data['notes'] ?? '')) !== '' ? mb_substr((string) $data['notes'], 0, 4000) : null,
        $userId ?: null,
    ]);
    return (int) $pdo->lastInsertId();
}

/** Randevuyu gunceller (kapsam içinde olmali). */
function qmsSupplierEvalScheduleUpdate(PDO $pdo, int $id, array $data, int $userId, string $role): bool
{
    if (!qmsSupplierEvalScheduleFind($pdo, $id, $userId, $role)) {
        return false;
    }
    $cycle = trim((string) ($data['cycle_label'] ?? ''));
    if ($cycle === '') {
        return false;
    }
    $status = (string) ($data['status'] ?? 'planned');
    if (!in_array($status, QMS_SUPPLIER_EVAL_STATUSES, true)) {
        $status = 'planned';
    }
    $pdo->prepare('UPDATE supplier_evaluation_schedule SET cycle_label=?, due_date=?, status=?, result=?, notes=? WHERE id=?')->execute([
        mb_substr($cycle, 0, 80),
        preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($data['due_date'] ?? '')) ? (string) $data['due_date'] : null,
        $status,
        trim((string) ($data['result'] ?? '')) !== '' ? mb_substr(trim((string) $data['result']), 0, 100) : null,
        trim((string) ($data['notes'] ?? '')) !== '' ? mb_substr((string) $data['notes'], 0, 4000) : null,
        $id,
    ]);
    return true;
}

/** Randevuyu siler (aktif=0), kapsam içinde olmali. */
function qmsSupplierEvalScheduleDelete(PDO $pdo, int $id, int $userId, string $role): bool
{
    $scope = qmsCompanyScope('company_id', qmsVisibleCompanyIds($pdo, $userId, $role));
    $stmt = $pdo->prepare('UPDATE supplier_evaluation_schedule SET active = 0 WHERE id = ?' . $scope['sql']);
    $stmt->execute(array_merge([$id], $scope['params']));
    return $stmt->rowCount() > 0;
}

/** Kapsam içindeki vadesi gecti (planli + gecmis tarihli) randevu sayisi. */
function qmsSupplierEvalScheduleOverdueCount(PDO $pdo, int $userId, string $role): int
{
    $scope = qmsCompanyScope('company_id', qmsVisibleCompanyIds($pdo, $userId, $role));
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM supplier_evaluation_schedule
                           WHERE active = 1 AND status = "planned" AND due_date IS NOT NULL AND due_date < CURDATE()' . $scope['sql']);
    $stmt->execute($scope['params']);
    return (int) $stmt->fetchColumn();
}

/** Durum etiketi metni (TR); eff durumu da kabul eder. */
function qmsSupplierEvalStatusLabel(string $status): string
{
    return [
        'planned' => 'Planlandı',
        'done' => 'Yapıldı',
        'skipped' => 'Atlandı',
        'overdue' => 'Vadesi Geçti',
    ][$status] ?? $status;
}
