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
 * Bir eylemi verilen rol gerceklestirebilir mi?
 */
function qmsCan(string $role, string $action): bool
{
    return in_array($role, qmsPermissions()[$action] ?? [], true);
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
