<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
session_start();
require 'config/database.php';
require 'includes/complaint-functions.php';
$checks = 0;
function complaintCheck(bool $ok, string $name): void { global $checks; if (!$ok) throw new RuntimeException('FAIL: ' . $name); $checks++; echo 'PASS: ' . $name . PHP_EOL; }
function complaintCount(PDO $pdo): int { return (int) $pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn(); }

// Gercek tablolarin semasi gecici tablolara kopyalanir: kiraci izolasyonu ve
// bildirim kurallari gercek veriye dokunmadan sinanir.
foreach (['companies', 'users', 'company_admin_assignments', 'audits', 'nonconformities', 'corrective_actions', 'complaints', 'notifications'] as $table) {
    $schema = $pdo->query("SHOW CREATE TABLE $table")->fetch(PDO::FETCH_NUM)[1];
    $schema = preg_replace('/(,\n)?\s*CONSTRAINT[^\n]+/', '', $schema);
    $pdo->exec(str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $schema));
}

$pdo->exec("INSERT INTO companies(id, company_name, active) VALUES(950001,'Complaint Tenant A',1),(950002,'Complaint Tenant B',1)");
$pdo->exec("INSERT INTO users(id, username, password_hash, full_name, role, company_id, active) VALUES
    (950001,'complaint-admin','x','Complaint Admin','system_admin',NULL,1),
    (950002,'complaint-root','x','Complaint Root','super_admin',NULL,1),
    (950003,'complaint-user','x','Complaint Company User','company_user',950001,1),
    (950004,'complaint-other','x','Complaint Other Admin','system_admin',NULL,1),
    (950005,'complaint-passive','x','Complaint Passive Admin','system_admin',NULL,0)");
$pdo->exec("INSERT INTO company_admin_assignments(company_id, admin_user_id, active) VALUES(950001, 950001, 1),(950002, 950004, 1),(950001, 950005, 1)");
$pdo->exec("INSERT INTO audits(id, company_id, title, status, active) VALUES(950001, 950001, 'Complaint audit A', 'planned', 1)");
$pdo->exec("INSERT INTO nonconformities(id, company_id, audit_id, title, severity, status, active) VALUES
    (950001, 950001, 950001, 'Complaint NC A', 'major', 'open', 1),
    (950002, 950001, 950001, 'Closed NC A', 'minor', 'closed', 0)");
$pdo->exec("INSERT INTO corrective_actions(id, nonconformity_id, action_text, status, active) VALUES(950001, 950001, 'NC A action', 'planned', 1)");
$pdo->exec("INSERT INTO complaints(id, company_id, complaint_code, subject, source, severity, status, received_date, nonconformity_id, active) VALUES
    (950001, 950001, 'SK-001', 'Tenant A complaint', 'customer', 'critical', 'new', '2026-05-01', 950001, 1),
    (950002, 950001, 'SK-002', 'Closed complaint', 'employee', 'minor', 'closed', '2026-04-01', NULL, 0),
    (950003, 950002, 'SK-003', 'Tenant B complaint', 'customer', 'major', 'in_review', '2026-05-02', NULL, 1)");

// ---- Alan dogrulama yardimcilari.
complaintCheck(qmsComplaintDate('2026-05-01') === '2026-05-01' && qmsComplaintDate('01.05.2026') === null, 'Dates require ISO format');
complaintCheck(qmsComplaintText('  Fazla bosluk  ', 100) === 'Fazla bosluk' && mb_strlen(qmsComplaintText(str_repeat('a', 300), 255)) === 255, 'Text is trimmed and truncated');

// ---- Durum yardimcilari.
complaintCheck(qmsComplaintIsOpen('new') && qmsComplaintIsOpen('in_review') && qmsComplaintIsOpen('action_planned'), 'Working statuses count as open');
complaintCheck(!qmsComplaintIsOpen('closed') && !qmsComplaintIsOpen('resolved') && !qmsComplaintIsOpen('rejected'), 'Final statuses do not count as open');

// ---- Kapanis tarihi.
complaintCheck(qmsComplaintClosedDate(['closed_date' => null], 'closed') !== null, 'Closing sets the closure date');
complaintCheck(qmsComplaintClosedDate(['closed_date' => '2026-01-01'], 'closed') === '2026-01-01', 'Closure date is preserved on re-save');
complaintCheck(qmsComplaintClosedDate(['closed_date' => '2026-01-01'], 'in_review') === null, 'Reopening clears the closure date');
complaintCheck(qmsComplaintClosedDate(['closed_date' => null], 'resolved') === null, 'Resolution does not set the closure date');

// ---- Kapsam: id degistirilerek baska kiraciya gecilemez.
complaintCheck((int) qmsComplaintFind($pdo, 950001, 950001, 'system_admin')['company_id'] === 950001, 'Assigned admin reads own tenant complaint');
complaintCheck(qmsComplaintFind($pdo, 950003, 950001, 'system_admin') === [], 'Assigned admin cannot read another tenant complaint');
complaintCheck((int) qmsComplaintFind($pdo, 950001, 950003, 'company_user')['id'] === 950001, 'Company user reads own company complaint');
complaintCheck(qmsComplaintFind($pdo, 950003, 950003, 'company_user') === [], 'Company user cannot read another company complaint');
complaintCheck((int) qmsComplaintFind($pdo, 950003, 950002, 'super_admin')['id'] === 950003, 'Super admin reads any complaint');
complaintCheck(qmsComplaintFind($pdo, 950002, 950002, 'super_admin') === [], 'Inactive complaint is not readable');
$found = qmsComplaintFind($pdo, 950001, 950001, 'system_admin');
complaintCheck((string) $found['nonconformity_title'] === 'Complaint NC A', 'Complaint read joins the linked nonconformity');

// ---- Liste.
$list = qmsComplaintList($pdo, 950001, 'system_admin');
complaintCheck(count($list) === 1 && (int) $list[0]['id'] === 950001, 'Complaint list is limited to the visible tenant');
complaintCheck((int) $list[0]['linked_actions'] === 1, 'Complaint list counts the corrective actions behind the link');
complaintCheck(count(qmsComplaintList($pdo, 950002, 'super_admin')) === 2, 'Super admin list sees every active complaint');
$pdo->exec('UPDATE company_admin_assignments SET active = 0');
complaintCheck(qmsComplaintList($pdo, 950001, 'system_admin') === [], 'Revoked assignment removes access');
$pdo->exec('UPDATE company_admin_assignments SET active = 1 WHERE company_id = 950001');

// ---- Uygunsuzluk baglama secenekleri.
$options = qmsComplaintNonconformityOptions($pdo, 950001);
complaintCheck(count($options) === 1 && (int) $options[0]['id'] === 950001, 'Nonconformity options are limited to the company and skip inactive records');
complaintCheck((string) $options[0]['audit_title'] === 'Complaint audit A', 'Nonconformity options carry the audit title');
complaintCheck(qmsComplaintNonconformityOptions($pdo, 950002) === [], 'Nonconformity options for a company without records are empty');
complaintCheck(qmsComplaintNonconformityOptions($pdo, 0) === [], 'Nonconformity options for an unknown company are empty');

// ---- Bildirim kurallari.
$baseContext = [
    'company_id' => 950001,
    'subject' => 'Tenant A complaint',
    'link' => 'complaint-detail.php?id=950001',
    'previous_status' => 'new',
    'new_status' => 'new',
    'previous_severity' => 'major',
    'severity' => 'major',
    'previous_responsible_user_id' => 0,
    'responsible_user_id' => 950003,
    'actor_user_id' => 950001
];
complaintCheck(qmsComplaintNotify($pdo, $baseContext) === 1, 'Assignment writes one notification');
complaintCheck((int) $pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id = 950003 AND notification_type = 'complaint_assigned'")->fetchColumn() === 1, 'Assignment notification type is stored');

$pdo->exec('DELETE FROM notifications');
complaintCheck(qmsComplaintNotify($pdo, array_merge($baseContext, ['responsible_user_id' => 950001, 'actor_user_id' => 950001])) === 0, 'A user is not notified for their own assignment');
complaintCheck(qmsComplaintNotify($pdo, array_merge($baseContext, ['previous_responsible_user_id' => 950003])) === 0, 'An unchanged responsible user is not notified again');

// Kritik onem, islem yapan kisi admin olmadiginda yonetime haber verir.
$criticalContext = array_merge($baseContext, [
    'previous_responsible_user_id' => 950003,
    'actor_user_id' => 950003,
    'severity' => 'critical'
]);
$pdo->exec('DELETE FROM notifications');
complaintCheck(qmsComplaintNotify($pdo, $criticalContext) === 1, 'Critical severity escalates to the assigned admin');
complaintCheck((int) $pdo->query("SELECT COUNT(*) FROM notifications WHERE notification_type = 'complaint_critical' AND user_id = 950001")->fetchColumn() === 1, 'Critical notification reaches the assigned admin');
complaintCheck((int) $pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id = 950004")->fetchColumn() === 0, 'Admin of another tenant is not notified');
complaintCheck((int) $pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id = 950005")->fetchColumn() === 0, 'Passive admin is not notified');
complaintCheck((int) $pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id = 950002")->fetchColumn() === 0, 'Super admin is not notified about tenant records');
$pdo->exec('DELETE FROM notifications');
complaintCheck(qmsComplaintNotify($pdo, array_merge($criticalContext, ['previous_severity' => 'critical'])) === 0, 'An unchanged critical severity does not notify again');
complaintCheck((int) $pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id = 950001 AND notification_type = 'complaint_critical'")->fetchColumn() === 0, 'The acting admin is not notified by their own escalation');

$pdo->exec('DELETE FROM notifications');
complaintCheck(qmsComplaintNotify($pdo, array_merge($criticalContext, ['previous_severity' => 'critical', 'severity' => 'major'])) === 0, 'Ordinary severity writes no escalation notification');
$pdo->exec('DELETE FROM notifications');
qmsComplaintNotify($pdo, array_merge($baseContext, [
    'previous_responsible_user_id' => 950003,
    'previous_status' => 'resolved',
    'new_status' => 'closed'
]));
complaintCheck((int) $pdo->query("SELECT COUNT(*) FROM notifications WHERE notification_type = 'complaint_closed' AND user_id = 950003")->fetchColumn() === 1, 'Closure notifies the responsible user');
$pdo->exec('DELETE FROM notifications');
qmsComplaintNotify($pdo, array_merge($baseContext, [
    'previous_responsible_user_id' => 950003,
    'previous_status' => 'closed',
    'new_status' => 'closed'
]));
complaintCheck(complaintCount($pdo) === 0, 'Re-saving a closed complaint sends no duplicate notification');

// ---- Sozlukler ve sabitler.
complaintCheck(array_keys(qmsComplaintStatusLabels()) === QMS_COMPLAINT_STATUSES, 'Status labels match the documented flow');
complaintCheck(array_keys(qmsComplaintSeverityLabels()) === QMS_COMPLAINT_SEVERITIES, 'Severity labels match the documented scale');
complaintCheck(array_keys(qmsComplaintSourceLabels()) === QMS_COMPLAINT_SOURCES, 'Source labels match the documented list');
complaintCheck(array_keys(qmsComplaintChannelLabels()) === QMS_COMPLAINT_CHANNELS, 'Channel labels match the documented list');
complaintCheck(
    count(qmsComplaintStatusI18nKeys()) === count(QMS_COMPLAINT_STATUSES)
        && count(qmsComplaintSeverityI18nKeys()) === count(QMS_COMPLAINT_SEVERITIES)
        && count(qmsComplaintSourceI18nKeys()) === count(QMS_COMPLAINT_SOURCES)
        && count(qmsComplaintChannelI18nKeys()) === count(QMS_COMPLAINT_CHANNELS),
    'Every status, severity, source and channel has an i18n key'
);
complaintCheck(qmsComplaintSeverityI18nKeys() === qmsSeverityI18nKeys() && QMS_COMPLAINT_SEVERITIES === QMS_SEVERITIES, 'Complaint severity reuses the shared vocabulary');

// ---- Bildirim arayuz haritasi.
complaintCheck(qmsNotificationMeta('complaint_assigned')['group'] === 'complaint', 'Complaint types map to the complaint group');
complaintCheck(qmsNotificationMeta('complaint_critical')['group'] === 'complaint' && qmsNotificationMeta('complaint_closed')['group'] === 'complaint', 'Every complaint type maps to the complaint group');
complaintCheck(isset(qmsNotificationGroupLabels()['complaint'], qmsNotificationGroupI18nKeys()['complaint']), 'The complaint group has a label and an i18n key');

complaintCheck((int) $pdo->query('SELECT COUNT(*) FROM complaints')->fetchColumn() === 3, 'Temporary table holds only the fixtures');

session_destroy();
echo "Completed $checks complaint checks using temporary tables." . PHP_EOL;
