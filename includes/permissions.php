<?php

declare(strict_types=1);

/**
 * Merkezi rol / izin (RBAC) servisi.
 *
 * Rol ve izin tek kaynaktan yonetilir; sayfalar kendi rol kontrolu yazmaz.
 * QMS_PERMISSIONS: eylem -> izin verilen roller. Yeni bir eylem/esleme burada
 * tanimlanir, gorunurluk qmsCan() uzerinden sorulur. Knowhow gereksinimi 51.
 */

require_once __DIR__ . '/access.php';

/**
 * Eylem -> izin verilen roller.
 *
 * @return array<string, array<int, string>>
 */
function qmsPermissions(): array
{
    return [
        'dashboard.view' => ['super_admin', 'system_admin', 'company_user'],
        'reports.view' => ['super_admin', 'system_admin', 'company_user'],
        'report.export' => ['super_admin', 'system_admin', 'company_user'],
        'operations.view' => ['super_admin', 'system_admin', 'company_user'],
        'search.view' => ['super_admin', 'system_admin', 'company_user'],
        'auditors.manage' => ['super_admin', 'system_admin'],
        'audit_programs.manage' => ['super_admin', 'system_admin'],
        'audit_trail.view' => ['super_admin', 'system_admin'],
        'permissions.view' => ['super_admin'],
        'admin.companies' => ['super_admin'],
        'admin.admins' => ['super_admin'],
        'admin.assignments' => ['super_admin'],
        'my_audits.view' => ['auditor'],
        'notifications.view' => ['super_admin', 'system_admin', 'company_user', 'auditor'],
        'profile.edit' => ['super_admin', 'system_admin', 'company_user', 'auditor'],
    ];
}

/**
 * DB'de saklanan izin override'lari (yukari cikarilir).
 *
 * Tablo yoksa / henuz hic kayit yoksa bos dizi doner; varsayilan qmsPermissions()
 * kaynagi gecerli kalir. Super admin rolu override edilmez, hep varsayilan
 * kaynaktan gelir (kendini kilitleyemez).
 *
 * @return array<string, array<string, bool>> action -> role -> allowed
 */
function qmsPermissionOverrides(?PDO $pdo = null): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = [];
    $pdo = $pdo ?? ($GLOBALS['pdo'] ?? null);
    if ($pdo instanceof PDO) {
        try {
            $rows = $pdo->query('SELECT action, role, allowed FROM permission_overrides')->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $cache[$row['action']][$row['role']] = (bool) $row['allowed'];
            }
        } catch (Throwable $e) {
            // Tablo henuz kurulmamissa varsayilan izinler gecerli kalir.
        }
    }
    return $cache;
}

/**
 * Bir eylemi verilen rol gerceklestirebilir mi?
 */
function qmsCan(string $role, string $action): bool
{
    // Super admin rolu override ile kisitlanmaz; hep varsayilan kaynaktan gelir.
    if ($role === 'super_admin') {
        return in_array($role, qmsPermissions()[$action] ?? [], true);
    }
    $overrides = qmsPermissionOverrides();
    if (isset($overrides[$action][$role])) {
        return (bool) $overrides[$action][$role];
    }
    return in_array($role, qmsPermissions()[$action] ?? [], true);
}

/**
 * Verilen eylem x rol ikililerinin izin durumunu DB'ye yazar (override).
 *
 * @param array<string, array<string, bool>> $sets action -> role -> allowed
 */
function qmsPermissionSaveOverrides(PDO $pdo, array $sets): void
{
    $del = $pdo->prepare('DELETE FROM permission_overrides WHERE action = ? AND role = ?');
    $ins = $pdo->prepare('INSERT INTO permission_overrides (action, role, allowed) VALUES (?, ?, ?)');
    $pdo->beginTransaction();
    try {
        foreach ($sets as $action => $byRole) {
            foreach ($byRole as $role => $allowed) {
                $del->execute([$action, $role]);
                $ins->execute([$action, $role, $allowed ? 1 : 0]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Kullanici oturum roluyle bir eylemi gerceklestirebilir mi? */
function qmsCanSession(string $action): bool
{
    return qmsCan(qmsCurrentRole(), $action);
}

/**
 * Oturum rolu eyleme yetkili degilse erisimi reddeder (403) ve cikar.
 */
function qmsRequirePermission(string $action): void
{
    if (!qmsCanSession($action)) {
        http_response_code(403);
        exit('Yetkisiz erişim.');
    }
}

/** @return array<string, string> Eylem -> gorunen etiket. */
function qmsPermissionActionLabels(): array
{
    return [
        'dashboard.view' => 'Panel görünümü',
        'reports.view' => 'Raporlama ve KPI',
        'report.export' => 'Excel/PDF rapor dışa aktarma',
        'operations.view' => 'Operasyon modülleri',
        'search.view' => 'Arama ve benzer vaka',
        'auditors.manage' => 'Denetçi yönetimi',
        'audit_programs.manage' => 'Denetim programı yönetimi',
        'audit_trail.view' => 'Denetim izi görünümü',
        'permissions.view' => 'İzin kaydı (RBAC) görünümü',
        'admin.companies' => 'Şirket yönetimi',
        'admin.admins' => 'Kullanıcı hesabı yönetimi',
        'admin.assignments' => 'Admin atama yönetimi',
        'my_audits.view' => 'Kendi denetimleri',
        'notifications.view' => 'Bildirim merkezi',
        'profile.edit' => 'Profil düzenleme',
    ];
}

/** @return array<string, string> Rol -> gorunen etiket. */
function qmsPermissionRoleLabels(): array
{
    return [
        'super_admin' => 'Süper Admin',
        'system_admin' => 'Sistem Admini',
        'company_user' => 'Şirket Kullanıcısı',
        'auditor' => 'Denetçi',
    ];
}

/**
 * Izin matrisi (eylem -> roller: izin var mi) - izin kaydı sayfasi icin.
 *
 * @return array<string, array<string, bool>>
 */
function qmsPermissionMatrix(): array
{
    $roles = array_keys(qmsPermissionRoleLabels());
    $matrix = [];
    foreach (array_keys(qmsPermissions()) as $action) {
        $matrix[$action] = [];
        foreach ($roles as $role) {
            $matrix[$action][$role] = qmsCan($role, $action);
        }
    }
    return $matrix;
}
