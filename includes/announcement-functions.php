<?php

declare(strict_types=1);

/**
 * Duyuru merkezi yardimcilari (sirket kapsamli).
 */

require_once __DIR__ . '/access.php';

/** Kapsam icindeki duyurular (en yeniden eskiye). */
function qmsAnnouncementList(PDO $pdo, int $userId, string $role, bool $publishedOnly = false): array
{
    $scope = qmsCompanyScope('a.company_id', qmsVisibleCompanyIds($pdo, $userId, $role));
    $sql = 'SELECT a.*, u.full_name AS creator_name FROM announcements a LEFT JOIN users u ON u.id = a.created_by
            WHERE a.active = 1' . $scope['sql'];
    if ($publishedOnly) {
        $sql .= ' AND a.published = 1';
    }
    $sql .= ' ORDER BY a.created_at DESC, a.id DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($scope['params']);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Tek duyuru (kapsam icinde). */
function qmsAnnouncementFind(PDO $pdo, int $id, int $userId, string $role): array
{
    $scope = qmsCompanyScope('company_id', qmsVisibleCompanyIds($pdo, $userId, $role));
    $stmt = $pdo->prepare('SELECT * FROM announcements WHERE id = ? AND active = 1' . $scope['sql'] . ' LIMIT 1');
    $stmt->execute(array_merge([$id], $scope['params']));
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

/** Yeni duyuru ekler. @return int|null */
function qmsAnnouncementAdd(PDO $pdo, array $data, int $userId, string $role): ?int
{
    $companyId = (int) ($data['company_id'] ?? 0);
    $scope = qmsCompanyScope('companies.id', qmsVisibleCompanyIds($pdo, $userId, $role));
    $stmt = $pdo->prepare('SELECT companies.id FROM companies WHERE companies.id = ? AND companies.active = 1' . $scope['sql'] . ' LIMIT 1');
    $stmt->execute(array_merge([$companyId], $scope['params']));
    if (!$stmt->fetchColumn()) {
        return null;
    }
    $title = trim((string) ($data['title'] ?? ''));
    if ($title === '') {
        return null;
    }
    $insert = $pdo->prepare('INSERT INTO announcements (company_id, title, body, published, active, created_by) VALUES (?,?,?,?,1,?)');
    $insert->execute([
        $companyId,
        mb_substr($title, 0, 180),
        trim((string) ($data['body'] ?? '')) !== '' ? (string) $data['body'] : null,
        !empty($data['published']) ? 1 : 0,
        $userId ?: null,
    ]);
    return (int) $pdo->lastInsertId();
}

/** Duyuruyu gunceller (kapsam icinde olmali). */
function qmsAnnouncementUpdate(PDO $pdo, int $id, array $data, int $userId, string $role): bool
{
    if (!qmsAnnouncementFind($pdo, $id, $userId, $role)) {
        return false;
    }
    $title = trim((string) ($data['title'] ?? ''));
    if ($title === '') {
        return false;
    }
    $pdo->prepare('UPDATE announcements SET title=?, body=?, published=? WHERE id=?')->execute([
        mb_substr($title, 0, 180),
        trim((string) ($data['body'] ?? '')) !== '' ? (string) $data['body'] : null,
        !empty($data['published']) ? 1 : 0,
        $id,
    ]);
    return true;
}

/** Duyuruyu siler (aktif=0), kapsam icinde olmali. */
function qmsAnnouncementDelete(PDO $pdo, int $id, int $userId, string $role): bool
{
    $scope = qmsCompanyScope('company_id', qmsVisibleCompanyIds($pdo, $userId, $role));
    $stmt = $pdo->prepare('UPDATE announcements SET active = 0 WHERE id = ?' . $scope['sql']);
    $stmt->execute(array_merge([$id], $scope['params']));
    return $stmt->rowCount() > 0;
}
