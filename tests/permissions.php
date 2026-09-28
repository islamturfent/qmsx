<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
session_start();
require 'config/database.php';
require 'includes/permissions.php';
$checks = 0;
function pCheck(bool $ok, string $name): void { global $checks; if (!$ok) throw new RuntimeException('FAIL: ' . $name); $checks++; echo 'PASS: ' . $name . PHP_EOL; }

// Varsayilan davranis dogrulansin diye canli override'lar gecici silinir (sonra geri yazilir).
$existingRows = $pdo->query('SELECT action, role, allowed FROM permission_overrides')->fetchAll(PDO::FETCH_ASSOC);
$existing = [];
foreach ($existingRows as $r) { $existing[(string) $r['action']][(string) $r['role']] = (bool) $r['allowed']; }
$pdo->exec('DELETE FROM permission_overrides');
qmsPermissionOverrides($pdo, true);

$permissions = qmsPermissions();
$actionLabels = qmsPermissionActionLabels();
$roleLabels = qmsPermissionRoleLabels();

// Harita tutarli: her eylem izinli bir rol listesine sahip, her rol tanimli.
$wellFormed = true;
foreach ($permissions as $action => $roles) {
    if ($roles === [] || !isset($actionLabels[$action])) { $wellFormed = false; }
    foreach ($roles as $role) {
        if (!isset($roleLabels[$role])) { $wellFormed = false; }
    }
}
pCheck($wellFormed, 'Every action maps to a non-empty, known role set with a label');

// Tum roller haritada gorunur.
$allRoles = array_keys($roleLabels);
$rolesUsed = array_unique(array_values(array_merge(...array_values($permissions))));
pCheck(array_diff($allRoles, $rolesUsed) === [], 'Every role is used by at least one action');

// qmsCan yetki dogrulamalari.
pCheck(qmsCan('super_admin', 'admin.admins') === true, 'Super admin manages accounts');
pCheck(qmsCan('super_admin', 'permissions.view') === true, 'Super admin views the RBAC registry');
pCheck(qmsCan('system_admin', 'permissions.view') === false, 'System admin cannot view the RBAC registry');
pCheck(qmsCan('system_admin', 'audit_trail.view') === true, 'System admin views audit trail');
pCheck(qmsCan('system_admin', 'admin.companies') === false, 'System admin cannot manage companies');
pCheck(qmsCan('company_user', 'operations.view') === true, 'Company user can use operational modules');
pCheck(qmsCan('company_user', 'admin.assignments') === false, 'Company user cannot manage assignments');
pCheck(qmsCan('auditor', 'my_audits.view') === true, 'Auditor sees their own audits');
pCheck(qmsCan('auditor', 'reports.view') === false, 'Auditor cannot see the reports surface');
pCheck(qmsCan('auditor', 'search.view') === false, 'Auditor cannot search operational modules');
pCheck(qmsCan('system_admin', 'auditors.manage') === true, 'System admin manages auditors');

// Bilinmeyen eylem / rol guvenli sekilde reddedilir.
pCheck(qmsCan('super_admin', 'bilinmeyen_eylem') === false, 'Unknown action denies everyone');
pCheck(qmsCan('varolmayan_rol', 'reports.view') === false, 'Unknown role is denied');

// Matris, eylem ve rol dogrultusunda uretilir.
$matrix = qmsPermissionMatrix();
pCheck(count($matrix) === count($permissions), 'Matrix covers every action');
pCheck($matrix['permissions.view']['super_admin'] === true && $matrix['permissions.view']['company_user'] === false, 'Matrix marks super admin only for the registry');

// Yeni sol menü yüzey eylemleri ve varsayilan roller.
$newActions = [
    'my_assignments.view' => ['super_admin', 'system_admin', 'company_user'],
    'overdue.view' => ['super_admin', 'system_admin', 'company_user'],
    'checklist_templates.view' => ['super_admin', 'system_admin'],
    'external_audits.view' => ['super_admin', 'system_admin'],
    'approvals.manage' => ['super_admin', 'system_admin'],
    'document_reviews.manage' => ['super_admin', 'system_admin'],
    'document_approvals.manage' => ['super_admin', 'system_admin'],
    'training_templates.manage' => ['super_admin', 'system_admin'],
    'competency_matrix.view' => ['super_admin', 'system_admin'],
    'admin.office' => ['super_admin'],
    'admin.mail' => ['super_admin'],
];
$newOk = true;
foreach ($newActions as $a => $roles) {
    $got = $permissions[$a] ?? null;
    if ($got !== $roles) { $newOk = false; }
}
pCheck($newOk, 'New RBAC surface actions carry the expected default role sets');
pCheck(!isset($permissions['search.view']), 'search.view replaced by operations.view');

// --- Override kaydetme / uygulama akisi (mevcut override'lar en basta kaydedildi ve sonra geri yazilir) ---
qmsPermissionSaveOverrides($pdo, [
    'operations.view' => ['system_admin' => false],
    'approvals.manage' => ['company_user' => true],
    'reports.view' => ['auditor' => true],
]);
$o = qmsPermissionOverrides($pdo, true);
pCheck(($o['operations.view']['system_admin'] ?? null) === false, 'Override disables operations.view for system_admin');
pCheck(($o['approvals.manage']['company_user'] ?? null) === true, 'Override enables approvals.manage for company_user');
pCheck(($o['reports.view']['auditor'] ?? null) === true, 'Override enables reports.view for auditor');
pCheck(qmsCan('system_admin', 'operations.view') === false, 'qmsCan honours disabled override');
pCheck(qmsCan('company_user', 'approvals.manage') === true, 'qmsCan honours enabled override');
pCheck(qmsCan('auditor', 'reports.view') === true, 'Granted auditor override takes effect on reports');
pCheck(qmsCan('super_admin', 'operations.view') === true, 'Super admin is not restricted by overrides');

// Test override'larini geri al; onceki durumu geri yaz.
$pdo->exec('DELETE FROM permission_overrides');
$ins = $pdo->prepare('INSERT INTO permission_overrides (action, role, allowed) VALUES (?, ?, ?)');
foreach ($existing as $action => $byRole) {
    foreach ($byRole as $role => $allowed) { $ins->execute([$action, $role, $allowed ? 1 : 0]); }
}
qmsPermissionOverrides($pdo, true); // cache'i tazele
echo "Completed $checks permissions checks." . PHP_EOL;
