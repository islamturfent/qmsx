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
 *   company_user : yalniz kendi sirketi (users.company_id), kisitli yazma
 *   auditor      : yalniz atandigi denetimler (audit_auditors)
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
 * DIKKAT - bu tuzak iki kez gercek hata uretti:
 * `null` "kisitlama yok" demektir, `[]` ise "hicbir sey goremez". Bu yuzden
 * sonucu ASLA `?? []` ile sarmalamayin; super adminin tum gorunurlugunu
 * sifirlar. Dogrudan qmsCompanyScope() / qmsAuditRecordScope() fonksiyonlarina
 * verin, onlar null'i dogru yorumlar.
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
             INNER JOIN audit_auditors ON audit_auditors.audit_id = audits.id
             INNER JOIN auditors ON auditors.id = audit_auditors.auditor_id
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
         INNER JOIN audit_auditors ON audit_auditors.audit_id = audits.id
         INNER JOIN auditors ON auditors.id = audit_auditors.auditor_id
         WHERE auditors.user_id = :user_id AND auditors.active = 1 AND audits.active = 1'
    );
    $stmt->execute(['user_id' => $userId]);

    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Kayit bazli kapsam sorgularinda kullanilacak rol.
 *
 * Cagri super admin disi geldiginde oturumdaki rol kullanilir; rol bu kumeye
 * girmiyorsa (test betikleri, arka plan isleri) sistem admini varsayilir.
 */
function qmsScopedRole(): string
{
    $role = (string) ($_SESSION['qms_role'] ?? '');

    return in_array($role, ['system_admin', 'company_user', 'auditor'], true) ? $role : 'system_admin';
}

/**
 * Denetime bagli kayitlar icin kapsam (denetimler, uygunsuzluklar).
 *
 * Denetci yalnizca atandigi denetimlerin kayitlarini gorur; diger roller
 * sirket kapsamiyla sinirlanir. Boylece bir denetci, ayni sirketteki baska bir
 * denetimi id degistirerek acamaz.
 *
 * @return array{sql: string, params: array<int, int>}
 */
function qmsAuditRecordScope(PDO $pdo, int $userId, string $auditColumn, string $companyColumn): array
{
    if (qmsIsAuditor()) {
        $auditIds = qmsVisibleAuditIds($pdo, $userId) ?? [];

        if ($auditIds === []) {
            return ['sql' => ' AND 1 = 0', 'params' => []];
        }

        return [
            'sql' => " AND $auditColumn IN (" . implode(',', array_fill(0, count($auditIds), '?')) . ')',
            'params' => $auditIds
        ];
    }

    return qmsCompanyScope($companyColumn, qmsVisibleCompanyIds($pdo, $userId, qmsCurrentRole()));
}

/**
 * Rolun giris sonrasi acilis sayfasi.
 */
function qmsLandingPage(string $role): string
{
    // Denetci kendi denetim listesine duser; yonetim sayfalari ona kapali.
    return $role === 'auditor' ? 'my-audits.php' : 'dashboard.php';
}

/**
 * Bir sirketin kayitli uygunsuzluklari (baglama secenekleri icin).
 *
 * Sikayet ve yonetimin gozden gecirmesi gibi moduller uygunsuzluga baglanir;
 * secenek listesi tek kaynaktan gelir. Denetim basligi da getirilir.
 *
 * @return array<int, array<string, mixed>>
 */
function qmsCompanyNonconformityOptions(PDO $pdo, int $companyId): array
{
    if ($companyId <= 0) {
        return [];
    }

    $stmt = $pdo->prepare(
        'SELECT nonconformities.id, nonconformities.title, nonconformities.status,
                nonconformities.severity, audits.title AS audit_title
         FROM nonconformities
         LEFT JOIN audits ON audits.id = nonconformities.audit_id
         WHERE nonconformities.company_id = :company_id AND nonconformities.active = 1
         ORDER BY nonconformities.id DESC'
    );
    $stmt->execute(['company_id' => $companyId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
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

// RBAC servisi: bu dosyayi yukleyen sayfalar qmsRequirePermission()/qmsCan()
// kullanabilir. (permissions.php de access.php'yi yukler; require_once sayesinde
// dongu guvenlidir.)
require_once __DIR__ . '/permissions.php';
