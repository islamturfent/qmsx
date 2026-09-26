<?php

declare(strict_types=1);

/**
 * Yıllık kalite planı modulu yardimcilari.
 *
 * Şirket başına yıl başına bir plan; plan altında hedef kalemleri (kategori,
 * hedef, sorumlu, tarih, durum, ilerleme yüzdesi). Kapsam tek kaynaktan gelir
 * (includes/access.php).
 */

require_once __DIR__ . '/access.php';

const QMS_PLAN_STATUSES = ['not_started', 'in_progress', 'completed', 'on_hold'];

/** Kapsam içindeki planlar (yıla göre; en yeniden eskiye) + kalem ozeti. */
function qmsQualityPlanList(PDO $pdo, int $userId, string $role): array
{
    $scope = qmsCompanyScope('p.company_id', qmsVisibleCompanyIds($pdo, $userId, $role));
    $stmt = $pdo->prepare(
        'SELECT p.*, c.company_name,
                (SELECT COUNT(*) FROM quality_plan_items i
                  WHERE i.plan_id = p.id AND i.active = 1) AS item_total,
                (SELECT COUNT(*) FROM quality_plan_items i
                  WHERE i.plan_id = p.id AND i.active = 1 AND i.status = "completed") AS item_completed,
                (SELECT ROUND(AVG(i.progress),0) FROM quality_plan_items i
                  WHERE i.plan_id = p.id AND i.active = 1) AS avg_progress
         FROM quality_plans p
         INNER JOIN companies c ON c.id = p.company_id
         WHERE p.active = 1' . $scope['sql'] . '
         ORDER BY p.plan_year DESC, p.id DESC'
    );
    $stmt->execute($scope['params']);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Kapsam içindeki tek plan; bulunamazsa bos dizi. */
function qmsQualityPlanFind(PDO $pdo, int $planId, int $userId, string $role): array
{
    $scope = qmsCompanyScope('p.company_id', qmsVisibleCompanyIds($pdo, $userId, $role));
    $stmt = $pdo->prepare(
        'SELECT p.*, c.company_name
         FROM quality_plans p
         INNER JOIN companies c ON c.id = p.company_id
         WHERE p.id = ? AND p.active = 1' . $scope['sql'] . ' LIMIT 1'
    );
    $stmt->execute(array_merge([$planId], $scope['params']));
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Yeni plan ekler. Aynı şirket+yıl tekrarı reddedilir.
 * @return int|null
 */
function qmsQualityPlanAdd(PDO $pdo, array $data, int $userId, string $role): ?int
{
    $companyId = (int) ($data['company_id'] ?? 0);
    $planYear = (int) ($data['plan_year'] ?? 0);
    $scope = qmsCompanyScope('companies.id', qmsVisibleCompanyIds($pdo, $userId, $role));
    $stmt = $pdo->prepare('SELECT companies.id FROM companies WHERE companies.id = ? AND companies.active = 1' . $scope['sql'] . ' LIMIT 1');
    $stmt->execute(array_merge([$companyId], $scope['params']));
    if (!$stmt->fetchColumn() || $planYear < 2000 || $planYear > 2100) {
        return null;
    }
    $title = trim((string) ($data['title'] ?? ''));
    if ($title === '') {
        return null;
    }
    $exists = $pdo->prepare('SELECT COUNT(*) FROM quality_plans WHERE company_id = ? AND plan_year = ? AND active = 1');
    $exists->execute([$companyId, $planYear]);
    if ((int) $exists->fetchColumn() > 0) {
        return null;
    }
    $ins = $pdo->prepare('INSERT INTO quality_plans (company_id, plan_year, title, description, active, created_by) VALUES (?,?,?,?,1,?)');
    $ins->execute([
        $companyId,
        $planYear,
        mb_substr($title, 0, 190),
        trim((string) ($data['description'] ?? '')) !== '' ? mb_substr((string) $data['description'], 0, 4000) : null,
        $userId ?: null,
    ]);
    return (int) $pdo->lastInsertId();
}

/** Planı gunceller (kapsam içinde olmali). */
function qmsQualityPlanUpdate(PDO $pdo, int $planId, array $data, int $userId, string $role): bool
{
    $plan = qmsQualityPlanFind($pdo, $planId, $userId, $role);
    if (!$plan) {
        return false;
    }
    $title = trim((string) ($data['title'] ?? ''));
    if ($title === '') {
        return false;
    }
    $pdo->prepare('UPDATE quality_plans SET title=?, description=? WHERE id=?')->execute([
        mb_substr($title, 0, 190),
        trim((string) ($data['description'] ?? '')) !== '' ? mb_substr((string) $data['description'], 0, 4000) : null,
        $planId,
    ]);
    return true;
}

/** Planı siler (aktif=0), kapsam içinde olmali. */
function qmsQualityPlanDelete(PDO $pdo, int $planId, int $userId, string $role): bool
{
    $scope = qmsCompanyScope('company_id', qmsVisibleCompanyIds($pdo, $userId, $role));
    $stmt = $pdo->prepare('UPDATE quality_plans SET active = 0 WHERE id = ?' . $scope['sql']);
    $stmt->execute(array_merge([$planId], $scope['params']));
    return $stmt->rowCount() > 0;
}

/** Planın aktif kalemleri (sirayla). */
function qmsQualityPlanItems(PDO $pdo, int $planId): array
{
    $stmt = $pdo->prepare(
        'SELECT id, category, objective, target, responsible, due_date, status, progress, sort_order
         FROM quality_plan_items
         WHERE plan_id = ? AND active = 1
         ORDER BY sort_order ASC, id ASC'
    );
    $stmt->execute([$planId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Kalem ekler (plan kapsam içinde olmali). @return int|null */
function qmsQualityPlanAddItem(PDO $pdo, int $planId, array $data, int $userId, string $role): ?int
{
    if (!qmsQualityPlanFind($pdo, $planId, $userId, $role)) {
        return null;
    }
    $objective = trim((string) ($data['objective'] ?? ''));
    if ($objective === '') {
        return null;
    }
    $status = (string) ($data['status'] ?? 'not_started');
    if (!in_array($status, QMS_PLAN_STATUSES, true)) {
        $status = 'not_started';
    }
    $progress = max(0, min(100, (int) ($data['progress'] ?? 0)));
    $max = $pdo->prepare('SELECT COALESCE(MAX(sort_order),0) FROM quality_plan_items WHERE plan_id = ?');
    $max->execute([$planId]);
    $sort = (int) $max->fetchColumn() + 1;

    $ins = $pdo->prepare('INSERT INTO quality_plan_items
        (plan_id, category, objective, target, responsible, due_date, status, progress, active, sort_order)
        VALUES (?,?,?,?,?,?,?,?,1,?)');
    $ins->execute([
        $planId,
        trim((string) ($data['category'] ?? '')) !== '' ? mb_substr(trim((string) $data['category']), 0, 120) : null,
        mb_substr($objective, 0, 500),
        trim((string) ($data['target'] ?? '')) !== '' ? mb_substr(trim((string) $data['target']), 0, 500) : null,
        trim((string) ($data['responsible'] ?? '')) !== '' ? mb_substr(trim((string) $data['responsible']), 0, 180) : null,
        preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($data['due_date'] ?? '')) ? (string) $data['due_date'] : null,
        $status,
        $progress,
        $sort,
    ]);
    return (int) $pdo->lastInsertId();
}

/** Kalemi gunceller (planı kapsam içinde olmali). */
function qmsQualityPlanUpdateItem(PDO $pdo, int $itemId, array $data, int $userId, string $role): bool
{
    $stmt = $pdo->prepare('SELECT plan_id FROM quality_plan_items WHERE id = ? AND active = 1');
    $stmt->execute([$itemId]);
    $planId = (int) $stmt->fetchColumn();
    if (!$planId || !qmsQualityPlanFind($pdo, $planId, $userId, $role)) {
        return false;
    }
    $objective = trim((string) ($data['objective'] ?? ''));
    if ($objective === '') {
        return false;
    }
    $status = (string) ($data['status'] ?? 'not_started');
    if (!in_array($status, QMS_PLAN_STATUSES, true)) {
        $status = 'not_started';
    }
    $progress = max(0, min(100, (int) ($data['progress'] ?? 0)));
    $pdo->prepare('UPDATE quality_plan_items SET category=?, objective=?, target=?, responsible=?, due_date=?, status=?, progress=? WHERE id=?')->execute([
        trim((string) ($data['category'] ?? '')) !== '' ? mb_substr(trim((string) $data['category']), 0, 120) : null,
        mb_substr($objective, 0, 500),
        trim((string) ($data['target'] ?? '')) !== '' ? mb_substr(trim((string) $data['target']), 0, 500) : null,
        trim((string) ($data['responsible'] ?? '')) !== '' ? mb_substr(trim((string) $data['responsible']), 0, 180) : null,
        preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($data['due_date'] ?? '')) ? (string) $data['due_date'] : null,
        $status,
        $progress,
        $itemId,
    ]);
    return true;
}

/** Kalemi siler (aktif=0), kapsam içinde olmali. */
function qmsQualityPlanDeleteItem(PDO $pdo, int $itemId, int $userId, string $role): bool
{
    $stmt = $pdo->prepare('SELECT plan_id FROM quality_plan_items WHERE id = ? AND active = 1');
    $stmt->execute([$itemId]);
    $planId = (int) $stmt->fetchColumn();
    if (!$planId || !qmsQualityPlanFind($pdo, $planId, $userId, $role)) {
        return false;
    }
    $pdo->prepare('UPDATE quality_plan_items SET active = 0 WHERE id = ?')->execute([$itemId]);
    return true;
}

/** Durum etiketi metni (TR). */
function qmsPlanStatusLabel(string $status): string
{
    return [
        'not_started' => 'Başlamadı',
        'in_progress' => 'Devam Ediyor',
        'completed' => 'Tamamlandı',
        'on_hold' => 'Beklemede',
    ][$status] ?? $status;
}
