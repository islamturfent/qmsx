<?php

declare(strict_types=1);

/**
 * Kök Neden Analizi (RCA) modulu yardimcilari.
 *
 * Olay / uygunsuzluk / iyilestirme firsati icin yapilandirilmis kök neden
 * oturumu (5-Neden JSON olarak saklanir). Kapsam tek kaynaktan gelir
 * (includes/access.php).
 */

require_once __DIR__ . '/access.php';

const QMS_RCA_STATUSES = ['draft', 'review', 'completed', 'closed'];
const QMS_RCA_SOURCE_TYPES = ['nonconformity', 'incident', 'improvement'];

/** @return array<string, string> */
function qmsRcaStatusLabels(): array
{
    return [
        'draft' => 'Taslak',
        'review' => 'İncelemede',
        'completed' => 'Tamamlandı',
        'closed' => 'Kapalı',
    ];
}

/** @return array<string, string> */
function qmsRcaStatusI18nKeys(): array
{
    return [
        'draft' => 'rcaStatusDraftLabel',
        'review' => 'rcaStatusReviewLabel',
        'completed' => 'rcaStatusCompletedLabel',
        'closed' => 'rcaStatusClosedLabel',
    ];
}

/** @return array<string, string> */
function qmsRcaSourceTypeLabels(): array
{
    return [
        'nonconformity' => 'Uygunsuzluk',
        'incident' => 'Olay',
        'improvement' => 'İyileştirme Fırsatı',
    ];
}

/** @return array<string, string> */
function qmsRcaSourceTypeI18nKeys(): array
{
    return [
        'nonconformity' => 'rcaSourceNonconformityLabel',
        'incident' => 'rcaSourceIncidentLabel',
        'improvement' => 'rcaSourceImprovementLabel',
    ];
}

/** Kapsam içindeki RCA kayitlari (en yeniden eskiye). */
function qmsRcaList(PDO $pdo, int $userId, string $role): array
{
    $scope = qmsCompanyScope('r.company_id', qmsVisibleCompanyIds($pdo, $userId, $role));
    $stmt = $pdo->prepare(
        'SELECT r.*, c.company_name
         FROM rca_analyses r
         INNER JOIN companies c ON c.id = r.company_id
         WHERE r.active = 1' . $scope['sql'] . '
         ORDER BY r.id DESC'
    );
    $stmt->execute($scope['params']);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Kapsam içindeki tek RCA; bulunamazsa bos dizi. */
function qmsRcaFind(PDO $pdo, int $id, int $userId, string $role): array
{
    $scope = qmsCompanyScope('company_id', qmsVisibleCompanyIds($pdo, $userId, $role));
    $stmt = $pdo->prepare('SELECT * FROM rca_analyses WHERE id = ? AND active = 1' . $scope['sql'] . ' LIMIT 1');
    $stmt->execute(array_merge([$id], $scope['params']));
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

/** Yeni RCA ekler; @return int|null */
function qmsRcaAdd(PDO $pdo, array $data, int $userId, string $role): ?int
{
    $companyId = (int) ($data['company_id'] ?? 0);
    $scope = qmsCompanyScope('companies.id', qmsVisibleCompanyIds($pdo, $userId, $role));
    $chk = $pdo->prepare('SELECT companies.id FROM companies WHERE companies.id = ? AND companies.active = 1' . $scope['sql'] . ' LIMIT 1');
    $chk->execute(array_merge([$companyId], $scope['params']));
    if (!$chk->fetchColumn()) {
        return null;
    }
    $title = trim((string) ($data['title'] ?? ''));
    if ($title === '') {
        return null;
    }
    $ins = $pdo->prepare(
        'INSERT INTO rca_analyses
            (company_id, source_type, source_id, title, description, five_why, root_cause,
             corrective_action_text, owner, status, created_by, active)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,1)'
    );
    $ins->execute([
        $companyId,
        in_array((string) ($data['source_type'] ?? 'nonconformity'), QMS_RCA_SOURCE_TYPES, true) ? (string) $data['source_type'] : 'nonconformity',
        (int) ($data['source_id'] ?? 0) > 0 ? (int) $data['source_id'] : null,
        mb_substr($title, 0, 255),
        trim((string) ($data['description'] ?? '')) !== '' ? mb_substr((string) $data['description'], 0, 4000) : null,
        trim((string) ($data['five_why'] ?? '')) !== '' ? mb_substr((string) $data['five_why'], 0, 4000) : null,
        trim((string) ($data['root_cause'] ?? '')) !== '' ? mb_substr((string) $data['root_cause'], 0, 4000) : null,
        trim((string) ($data['corrective_action_text'] ?? '')) !== '' ? mb_substr((string) $data['corrective_action_text'], 0, 4000) : null,
        trim((string) ($data['owner'] ?? '')) !== '' ? mb_substr(trim((string) $data['owner']), 0, 180) : null,
        in_array((string) ($data['status'] ?? 'draft'), QMS_RCA_STATUSES, true) ? (string) $data['status'] : 'draft',
        $userId ?: null,
    ]);
    return (int) $pdo->lastInsertId();
}

/** RCA'yi gunceller (kapsam içinde olmali). */
function qmsRcaUpdate(PDO $pdo, int $id, array $data, int $userId, string $role): bool
{
    if (!qmsRcaFind($pdo, $id, $userId, $role)) {
        return false;
    }
    $title = trim((string) ($data['title'] ?? ''));
    if ($title === '') {
        return false;
    }
    $pdo->prepare(
        'UPDATE rca_analyses SET
            source_type=?, source_id=?, title=?, description=?, five_why=?, root_cause=?,
            corrective_action_text=?, owner=?, status=?, updated_at=CURRENT_TIMESTAMP
         WHERE id=?'
    )->execute([
        in_array((string) ($data['source_type'] ?? 'nonconformity'), QMS_RCA_SOURCE_TYPES, true) ? (string) $data['source_type'] : 'nonconformity',
        (int) ($data['source_id'] ?? 0) > 0 ? (int) $data['source_id'] : null,
        mb_substr($title, 0, 255),
        trim((string) ($data['description'] ?? '')) !== '' ? mb_substr((string) $data['description'], 0, 4000) : null,
        trim((string) ($data['five_why'] ?? '')) !== '' ? mb_substr((string) $data['five_why'], 0, 4000) : null,
        trim((string) ($data['root_cause'] ?? '')) !== '' ? mb_substr((string) $data['root_cause'], 0, 4000) : null,
        trim((string) ($data['corrective_action_text'] ?? '')) !== '' ? mb_substr((string) $data['corrective_action_text'], 0, 4000) : null,
        trim((string) ($data['owner'] ?? '')) !== '' ? mb_substr(trim((string) $data['owner']), 0, 180) : null,
        in_array((string) ($data['status'] ?? 'draft'), QMS_RCA_STATUSES, true) ? (string) $data['status'] : 'draft',
        $id,
    ]);
    return true;
}

/** RCA'yi siler (aktif=0), kapsam içinde olmali. */
function qmsRcaDelete(PDO $pdo, int $id, int $userId, string $role): bool
{
    $scope = qmsCompanyScope('company_id', qmsVisibleCompanyIds($pdo, $userId, $role));
    $stmt = $pdo->prepare('UPDATE rca_analyses SET active = 0 WHERE id = ?' . $scope['sql']);
    $stmt->execute(array_merge([$id], $scope['params']));
    return $stmt->rowCount() > 0;
}

/**
 * Kaynak turune gore secilebilir kayitlar (kapsam dahilinde).
 *
 * @return array<int, array{id:int, label:string}>
 */
function qmsRcaSourceOptions(PDO $pdo, string $sourceType, int $userId, string $role): array
{
    $scope = qmsCompanyScope('company_id', qmsVisibleCompanyIds($pdo, $userId, $role));
    $rows = [];
    if ($sourceType === 'nonconformity') {
        $stmt = $pdo->prepare('SELECT id, title FROM nonconformities WHERE active = 1' . $scope['sql'] . ' ORDER BY id DESC');
        $stmt->execute($scope['params']);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[] = ['id' => (int) $r['id'], 'label' => '#' . (int) $r['id'] . ' ' . (string) $r['title']];
        }
    } elseif ($sourceType === 'incident') {
        $stmt = $pdo->prepare('SELECT id, title FROM incidents WHERE active = 1' . $scope['sql'] . ' ORDER BY id DESC');
        $stmt->execute($scope['params']);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[] = ['id' => (int) $r['id'], 'label' => '#' . (int) $r['id'] . ' ' . (string) $r['title']];
        }
    } elseif ($sourceType === 'improvement') {
        $stmt = $pdo->prepare('SELECT id, title FROM improvements WHERE active = 1' . $scope['sql'] . ' ORDER BY id DESC');
        $stmt->execute($scope['params']);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[] = ['id' => (int) $r['id'], 'label' => '#' . (int) $r['id'] . ' ' . (string) $r['title']];
        }
    }
    return $rows;
}

/**
 * RCA'i kaynak uygunsuzluga bagliysa duzeltici faaliyet açar (CAPA).
 *
 * @return int|null Yeni faaliyet id'si; kaynak uygunsuzluk degilse veya
 *                  basarisizlikta null.
 */
function qmsRcaOpenCapa(PDO $pdo, int $rcaId, int $userId, string $role): ?int
{
    $rca = qmsRcaFind($pdo, $rcaId, $userId, $role);
    if (!$rca || $rca['source_type'] !== 'nonconformity' || !$rca['source_id']) {
        return null;
    }
    require_once __DIR__ . '/capa-functions.php';
    $nc = qmsNonconformityFind($pdo, (int) $rca['source_id'], $userId, $role);
    if (!$nc) {
        return null;
    }
    $actionText = 'RCA sonucu düzeltici faaliyet: ' . mb_substr(trim((string) ($rca['corrective_action_text'] ?? (string) $rca['title'])), 0, 2000);
    $ins = $pdo->prepare(
        'INSERT INTO corrective_actions (nonconformity_id, action_type, action_text, status, active)
         VALUES (?, \'corrective\', ?, \'planned\', 1)'
    );
    $ins->execute([(int) $nc['id'], $actionText]);
    $newActionId = (int) $pdo->lastInsertId();

    $link = 'corrective-action-detail.php?id=' . $newActionId;
    require_once __DIR__ . '/notifications.php';
    qmsNotifyCompanyAdmins($pdo, (int) $nc['company_id'], 'capa_opened_from_incident', 'RCA sonucu CAPA açıldı', 'RCA: ' . (string) $rca['title'] . ' — ' . $actionText, $link, $userId);

    require_once __DIR__ . '/audit-log-functions.php';
    qmsAuditLog($pdo, (int) $nc['company_id'], $userId, 'corrective_action', $newActionId, 'create', 'RCA sonucu düzeltici faaliyet açıldı: ' . (string) $rca['title']);

    return $newActionId;
}
