<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
session_start();
require 'config/database.php';
require 'includes/capa-functions.php';
require 'includes/csrf.php';
$checks = 0;
function capaCheck(bool $ok, string $name): void { global $checks; if (!$ok) throw new RuntimeException('FAIL: ' . $name); $checks++; echo 'PASS: ' . $name . PHP_EOL; }
function capaCount(PDO $pdo): int { return (int) $pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn(); }

// Gercek tablolarin semasi gecici tablolara kopyalanir: kiraci izolasyonu ve
// bildirim kurallari gercek veriye dokunmadan sinanir.
foreach (['companies', 'users', 'company_admin_assignments', 'audits', 'nonconformities', 'corrective_actions', 'corrective_action_evidence', 'notifications'] as $table) {
    $schema = $pdo->query("SHOW CREATE TABLE $table")->fetch(PDO::FETCH_NUM)[1];
    $schema = preg_replace('/(,\n)?\s*CONSTRAINT[^\n]+/', '', $schema);
    $pdo->exec(str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $schema));
}

$pdo->exec("INSERT INTO companies(id, company_name, active) VALUES(930001,'Capa Tenant A',1),(930002,'Capa Tenant B',1)");
$pdo->exec("INSERT INTO users(id, username, password_hash, full_name, role, company_id, active) VALUES
    (930001,'capa-admin','x','Capa Admin','system_admin',NULL,1),
    (930002,'capa-root','x','Capa Root','super_admin',NULL,1),
    (930003,'capa-company','x','Capa Company User','company_user',930001,1),
    (930004,'capa-other-admin','x','Capa Other Admin','system_admin',NULL,1),
    (930005,'capa-passive','x','Capa Passive Admin','system_admin',NULL,0)");
$pdo->exec("INSERT INTO company_admin_assignments(company_id, admin_user_id, active) VALUES(930001, 930001, 1),(930002, 930004, 1),(930001, 930005, 1)");
$pdo->exec("INSERT INTO audits(id, company_id, title, status, active) VALUES(930001, 930001, 'Capa audit A', 'planned', 1),(930002, 930002, 'Capa audit B', 'planned', 1)");
$pdo->exec("INSERT INTO nonconformities(id, company_id, audit_id, title, severity, status, active) VALUES
    (930001, 930001, 930001, 'Capa NC A', 'major', 'open', 1),
    (930002, 930002, 930002, 'Capa NC B', 'major', 'open', 1)");
$pdo->exec("INSERT INTO corrective_actions(id, nonconformity_id, action_text, status, responsible_user_id, active) VALUES
    (930001, 930001, 'Capa action A', 'planned', 930003, 1),
    (930002, 930001, 'Capa closed action', 'closed', NULL, 0),
    (930003, 930002, 'Capa action B', 'planned', NULL, 1)");
$pdo->exec("INSERT INTO corrective_action_evidence(id, corrective_action_id, original_file_name, stored_file_name, mime_type, file_size, uploaded_by, active) VALUES
    (930001, 930001, 'kanit.pdf', 'aaa111.pdf', 'application/pdf', 1024, 930001, 1),
    (930002, 930001, 'silinmis.pdf', 'bbb222.pdf', 'application/pdf', 1024, 930001, 0),
    (930003, 930003, 'diger.pdf', 'ccc333.pdf', 'application/pdf', 1024, 930004, 1)");

// ---- Kapsam: id degistirilerek baska kiraciya gecilemez.
capaCheck((int) qmsCorrectiveActionFind($pdo, 930001, 930001, 'system_admin')['company_id'] === 930001, 'Assigned admin reads own tenant action');
capaCheck(qmsCorrectiveActionFind($pdo, 930003, 930001, 'system_admin') === [], 'Assigned admin cannot read another tenant action');
capaCheck((int) qmsCorrectiveActionFind($pdo, 930001, 930003, 'company_user')['id'] === 930001, 'Company user reads own company action');
capaCheck(qmsCorrectiveActionFind($pdo, 930003, 930003, 'company_user') === [], 'Company user cannot read another company action');
capaCheck((int) qmsCorrectiveActionFind($pdo, 930003, 930002, 'super_admin')['id'] === 930003, 'Super admin reads any action');
capaCheck(qmsCorrectiveActionFind($pdo, 930002, 930002, 'super_admin') === [], 'Inactive action is not readable');
capaCheck((string) qmsCorrectiveActionFind($pdo, 930001, 930001, 'system_admin')['nonconformity_title'] === 'Capa NC A', 'Action read joins its nonconformity');

capaCheck((int) qmsNonconformityFind($pdo, 930001, 930001, 'system_admin')['company_id'] === 930001, 'Nonconformity read honours the tenant scope');
capaCheck(qmsNonconformityFind($pdo, 930002, 930001, 'system_admin') === [], 'Nonconformity of another tenant is hidden');

// ---- Kanit dosyalari.
capaCheck((int) qmsCapaEvidenceFind($pdo, 930001, 930003, 'company_user')['id'] === 930001, 'Company user can read own evidence');
capaCheck(qmsCapaEvidenceFind($pdo, 930002, 930001, 'system_admin') === [], 'Soft-deleted evidence is not readable');
capaCheck(qmsCapaEvidenceFind($pdo, 930003, 930001, 'system_admin') === [], 'Evidence of another tenant is not readable');
capaCheck(count(qmsCapaEvidenceList($pdo, 930001)) === 1, 'Evidence list returns active files only');
capaCheck(count(qmsCapaEvidenceList($pdo, 930003)) === 1, 'Evidence list is limited to one action');
capaCheck(array_key_exists('uploaded_by_name', qmsCapaEvidenceList($pdo, 930001)[0]), 'Evidence list carries the uploader name');
$traversalPath = qmsCapaEvidencePath('../../config/database.php');
capaCheck(basename($traversalPath) === 'database.php' && strpos($traversalPath, 'evidence') !== false, 'Evidence path strips directory traversal');

// ---- Durum zaman damgalari.
$openAction = ['completed_at' => null, 'closed_at' => null];
$timestamps = qmsCapaStatusTimestamps($openAction, 'completed');
capaCheck($timestamps['completed_at'] !== null && $timestamps['closed_at'] === null, 'Completion sets only the completion date');
$alreadyCompleted = ['completed_at' => '2026-01-01 10:00:00', 'closed_at' => null];
capaCheck(qmsCapaStatusTimestamps($alreadyCompleted, 'completed')['completed_at'] === '2026-01-01 10:00:00', 'Completion date is preserved on re-save');
$closedTimestamps = qmsCapaStatusTimestamps($alreadyCompleted, 'closed');
capaCheck($closedTimestamps['completed_at'] === '2026-01-01 10:00:00' && $closedTimestamps['closed_at'] !== null, 'Closing keeps the completion date and adds the closure date');
$reopened = qmsCapaStatusTimestamps($closedTimestamps, 'in_progress');
capaCheck($reopened['completed_at'] === null && $reopened['closed_at'] === null, 'Reopening clears both dates');

// ---- Bildirim kurallari.
$baseContext = [
    'company_id' => 930001,
    'action_text' => 'Capa action A',
    'link' => 'corrective-action-detail.php?id=930001',
    'previous_status' => 'planned',
    'new_status' => 'planned',
    'previous_responsible_user_id' => 0,
    'responsible_user_id' => 930003,
    'actor_user_id' => 930001
];
qmsCapaNotifyStatusChange($pdo, $baseContext);
capaCheck((int) $pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id = 930003 AND notification_type = 'corrective_action_assigned'")->fetchColumn() === 1, 'Assignment notifies the responsible user');

$pdo->exec('DELETE FROM notifications');
$selfAssigned = array_merge($baseContext, ['responsible_user_id' => 930001, 'actor_user_id' => 930001]);
qmsCapaNotifyStatusChange($pdo, $selfAssigned);
capaCheck(capaCount($pdo) === 0, 'A user is not notified for their own assignment');

$unchanged = array_merge($baseContext, ['previous_responsible_user_id' => 930003]);
qmsCapaNotifyStatusChange($pdo, $unchanged);
capaCheck(capaCount($pdo) === 0, 'An unchanged responsible user is not notified again');

$pdo->exec('DELETE FROM notifications');
qmsCapaNotifyStatusChange($pdo, array_merge($baseContext, [
    'previous_responsible_user_id' => 930003,
    'previous_status' => 'in_progress',
    'new_status' => 'verification',
    'actor_user_id' => 930003
]));
capaCheck((int) $pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id = 930001 AND notification_type = 'corrective_action_verification'")->fetchColumn() === 1, 'Verification request reaches the assigned system admin');

// Islem yapan kisi ayni zamanda sirket adminiyse kendisine bildirim gitmez.
$pdo->exec('DELETE FROM notifications');
qmsCapaNotifyStatusChange($pdo, array_merge($baseContext, [
    'previous_responsible_user_id' => 930003,
    'previous_status' => 'in_progress',
    'new_status' => 'verification',
    'actor_user_id' => 930001
]));
capaCheck(capaCount($pdo) === 0, 'The acting admin is not notified by their own request');
$pdo->exec('DELETE FROM notifications');
qmsCapaNotifyStatusChange($pdo, array_merge($baseContext, [
    'previous_responsible_user_id' => 930003,
    'previous_status' => 'in_progress',
    'new_status' => 'verification',
    'actor_user_id' => 930003
]));
capaCheck((int) $pdo->query('SELECT COUNT(*) FROM notifications WHERE user_id = 930001')->fetchColumn() === 1, 'Exactly one notification is written per rule');
capaCheck((int) $pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id = 930004")->fetchColumn() === 0, 'Admin of another tenant is not notified');
capaCheck((int) $pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id = 930002")->fetchColumn() === 0, 'Super admin is not notified about tenant records');
capaCheck((int) $pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id = 930005")->fetchColumn() === 0, 'Passive admin is not notified');

$pdo->exec('DELETE FROM notifications');
qmsCapaNotifyStatusChange($pdo, array_merge($baseContext, [
    'previous_responsible_user_id' => 930003,
    'previous_status' => 'completed',
    'new_status' => 'closed',
    'actor_user_id' => 930001
]));
capaCheck((int) $pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id = 930003 AND notification_type = 'corrective_action_closed'")->fetchColumn() === 1, 'Closure notifies the responsible user');

$pdo->exec('DELETE FROM notifications');
qmsCapaNotifyStatusChange($pdo, array_merge($baseContext, [
    'previous_responsible_user_id' => 930003,
    'previous_status' => 'closed',
    'new_status' => 'closed'
]));
capaCheck(capaCount($pdo) === 0, 'Re-saving a closed action sends no duplicate notification');

// ---- Bildirim yardimcilari.
$pdo->exec('DELETE FROM notifications');
qmsNotify($pdo, 0, 'corrective_action_assigned', 'Gecersiz', 'Gecersiz kullanici');
capaCheck(capaCount($pdo) === 0, 'Notification without a user is ignored');
qmsNotify($pdo, 930003, 'corrective_action_assigned', 'Baslik', 'Mesaj', 'corrective-action-detail.php?id=930001');
capaCheck((int) $pdo->query('SELECT is_read FROM notifications WHERE user_id = 930003')->fetchColumn() === 0, 'New notification starts unread');

// ---- Sorumlu kullanici listesi.
$responsibleIds = array_map('intval', array_column(qmsCompanyResponsibleOptions($pdo, 930001), 'id'));
capaCheck(in_array(930003, $responsibleIds, true) && in_array(930001, $responsibleIds, true), 'Responsible options cover company users and assigned admins');
capaCheck(!in_array(930004, $responsibleIds, true), 'Responsible options exclude admins of other tenants');
capaCheck(!in_array(930002, $responsibleIds, true), 'Responsible options exclude super admins');
capaCheck(!in_array(930005, $responsibleIds, true), 'Responsible options exclude passive accounts');
capaCheck(qmsCompanyResponsibleOptions($pdo, 0) === [], 'Responsible options for an unknown company are empty');

// ---- Durum sozlugu ve CSRF kapsamlari.
capaCheck(array_keys(qmsCapaStatusLabels()) === QMS_CAPA_STATUSES, 'Status labels match the documented flow');
capaCheck(count(qmsCapaStatusI18nKeys()) === count(QMS_CAPA_STATUSES), 'Every status has an i18n key');
capaCheck(qmsCsrfToken('capa-a') !== qmsCsrfToken('capa-b'), 'CSRF tokens are scoped per screen');
capaCheck(qmsCsrfToken('capa-a') === qmsCsrfToken('capa-a'), 'CSRF token is stable within a session');

capaCheck((int) $pdo->query('SELECT COUNT(*) FROM corrective_actions')->fetchColumn() === 3, 'Temporary table holds only the fixtures');

session_destroy();
echo "Completed $checks CAPA checks using temporary tables." . PHP_EOL;
