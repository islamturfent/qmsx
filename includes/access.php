<?php

declare(strict_types=1);

/**
 * Merkezi erisim katmani.
 *
 * Rol bazli gorunurluk kurallari tek yerde tutulur; sayfalar kendi EXISTS
 * cumleciklerini yazmaz. Kurallar:
 *
 *   super_admin  : tum sirketler, tum moduller
 *   system_admin : yalniz atandigi sirketler (company_admin_assignments)
 *   company_user : yalniz kendi sirketi (users.company_id), salt okunur
 *   auditor      : yalniz atandigi denetimler (audits.auditor_id)
 */

/** Oturumdaki rol. */
function qmsCurrentRole(): string
{
    return (string) ($_SESSION['qms_role'] ?? '');
}

function qmsIsSuperAdmin(): bool
{
    return qmsCurrentRole() === 'super_admin';
}

function qmsIsCompanyUser(): bool
{
    return qmsCurrentRole() === 'company_user';
}

function qmsIsAuditor(): bool
{
    return qmsCurrentRole() === 'auditor';
}

/**
 * Kullanicinin gorebildigi sirketler.
 *
 * null  : kisitlama yok (super admin)
 * []    : hicbir sirket goremez
 * [1,2] : yalniz bu sirketler
 *
 * @return int[]|null
 */
function qmsVisibleCompanyIds(PDO $pdo, int $userId, string $role): ?array
{
    if ($role === 'super_admin') {
        return null;
    }

    if ($role === 'company_user') {
        $stmt = $pdo->prepare('SELECT company_id FROM users WHERE id = :id AND active = 1 LIMIT 1');
        $stmt->execute(['id' => $userId]);
        $companyId = $stmt->fetchColumn();
        return $companyId === false || $companyId === null ? [] : [(int) $companyId];
    }

    if ($role === 'auditor') {
        // Denetci yalnizca atandigi denetimlerin sirketlerini gorur.
        $stmt = $pdo->prepare(
            'SELECT DISTINCT audits.company_id
             FROM audits
             INNER JOIN auditors ON auditors.id = audits.auditor_id
             WHERE auditors.user_id = :user_id AND auditors.active = 1 AND audits.active = 1'
        );
        $stmt->execute(['user_id' => $userId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    // system_admin: atandigi sirketler
    $stmt = $pdo->prepare(
        'SELECT company_id FROM company_admin_assignments WHERE admin_user_id = :user_id AND active = 1'
    );
    $stmt->execute(['user_id' => $userId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Sirket kapsamli sorgular icin hazir SQL parcasi ve parametreleri.
 *
 * @param int[]|null $companyIds
 * @return array{sql: string, params: array<int, int>}
 */
function qmsCompanyScope(string $column, ?array $companyIds): array
{
    if ($companyIds === null) {
        return ['sql' => '', 'params' => []];
    }

    if ($companyIds === []) {
        return ['sql' => ' AND 1 = 0', 'params' => []];
    }

    $placeholders = implode(',', array_fill(0, count($companyIds), '?'));

    return ['sql' => " AND $column IN ($placeholders)", 'params' => array_values($companyIds)];
}

/**
 * Denetci hesabinin gorebildigi denetim kayitlari. Diger roller icin null
 * (kisitlama yok) doner; kapsam sirket uzerinden uygulanir.
 *
 * @return int[]|null
 */
function qmsVisibleAuditIds(PDO $pdo, int $userId): ?array
{
    if (!qmsIsAuditor()) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT audits.id
         FROM audits
         INNER JOIN auditors ON auditors.id = audits.auditor_id
         WHERE auditors.user_id = :user_id AND auditors.active = 1 AND audits.active = 1'
    );
    $stmt->execute(['user_id' => $userId]);

    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Kullanicinin sirkete erisimi var mi.
 *
 * @param int[]|null $companyIds
 */
function qmsCanAccessCompany(?array $companyIds, int $companyId): bool
{
    if ($companyIds === null) {
        return true;
    }

    return in_array($companyId, $companyIds, true);
}
