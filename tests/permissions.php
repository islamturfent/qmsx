<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
session_start();
require 'config/database.php';
require 'includes/permissions.php';
$checks = 0;
function pCheck(bool $ok, string $name): void { global $checks; if (!$ok) throw new RuntimeException('FAIL: ' . $name); $checks++; echo 'PASS: ' . $name . PHP_EOL; }

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

echo "Completed $checks permissions checks." . PHP_EOL;
