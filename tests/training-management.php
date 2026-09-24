<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
session_start();
require 'config/database.php';
require 'includes/training-functions.php';
$checks = 0;
function trainingCheck(bool $ok, string $name): void { global $checks; if (!$ok) throw new RuntimeException('FAIL: ' . $name); $checks++; echo 'PASS: ' . $name . PHP_EOL; }

// Gercek tablolarin semasi gecici tablolara kopyalanir; kiraci izolasyonu ve
// yabanci anahtar kurallari boylece gercek veriye dokunmadan sinanir.
foreach (['companies', 'users', 'company_admin_assignments', 'auditors', 'audits', 'audit_auditors', 'trainings', 'training_participants'] as $table) {
    $schema = $pdo->query("SHOW CREATE TABLE $table")->fetch(PDO::FETCH_NUM)[1];
    $schema = preg_replace('/(,\n)?\s*CONSTRAINT[^\n]+/', '', $schema);
    $pdo->exec(str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $schema));
}

$pdo->exec("INSERT INTO companies(id, company_name, active) VALUES(920001,'Tenant A',1),(920002,'Tenant B',1)");
$pdo->exec("INSERT INTO users(id, username, password_hash, full_name, role, company_id, active) VALUES
    (920001,'training-admin','x','Training Admin','system_admin',NULL,1),
    (920002,'training-root','x','Training Root','super_admin',NULL,1),
    (920003,'training-company','x','Training Company User','company_user',920001,1),
    (920004,'training-auditor','x','Training Auditor','auditor',NULL,1),
    (920005,'training-other','x','Training Other User','company_user',920002,1)");
$pdo->exec("INSERT INTO company_admin_assignments(company_id, admin_user_id, active) VALUES(920001, 920001, 1)");
$pdo->exec("INSERT INTO auditors(id, company_id, first_name, last_name, email, telefon, role, user_id, active) VALUES(920001, 920002, 'Tenant', 'Auditor', 'tenant.auditor@test', '000', 'Denetçi', 920004, 1)");
$pdo->exec("INSERT INTO audits(id, company_id, title, status, active) VALUES(920001, 920002, 'Tenant B audit', 'planned', 1)");
$pdo->exec("INSERT INTO audit_auditors(audit_id, auditor_id) VALUES(920001, 920001)");
$pdo->exec("INSERT INTO trainings(id, company_id, title, status, planned_date, duration_hours, active) VALUES
    (920001, 920001, 'Tenant A training', 'planned', '2026-10-01', 8, 1),
    (920002, 920002, 'Tenant B training', 'completed', '2026-09-01', 4, 1),
    (920003, 920001, 'Archived training', 'cancelled', NULL, NULL, 0)");

// Alan dogrulama yardimcilari.
trainingCheck(qmsTrainingDuration('7,5') === 7.5, 'Duration accepts comma decimals');
trainingCheck(qmsTrainingDuration('') === null && qmsTrainingDuration('abc') === null && qmsTrainingDuration('-1') === null, 'Duration rejects empty and invalid values');
trainingCheck(qmsTrainingDuration('2000') === null, 'Duration rejects out-of-range values');
trainingCheck(qmsTrainingScore('85,5') === 85.5 && qmsTrainingScore('101') === null, 'Score is bounded to 0-100');
trainingCheck(qmsTrainingDate('2026-10-01') === '2026-10-01' && qmsTrainingDate('01.10.2026') === null, 'Dates require ISO format');
trainingCheck(qmsTrainingText('  Fazla bosluk  ', 100) === 'Fazla bosluk' && mb_strlen(qmsTrainingText(str_repeat('a', 300), 255)) === 255, 'Text is trimmed and truncated');

// Kapsam: id degistirilerek baska kiraciya gecilemez.
trainingCheck((int) qmsTrainingFind($pdo, 920001, 920001, 'system_admin')['company_id'] === 920001, 'Assigned admin reads own tenant training');
trainingCheck(qmsTrainingFind($pdo, 920002, 920001, 'system_admin') === [], 'Assigned admin cannot read another tenant training');
trainingCheck((int) qmsTrainingFind($pdo, 920001, 920003, 'company_user')['id'] === 920001, 'Company user reads own company training');
trainingCheck(qmsTrainingFind($pdo, 920002, 920003, 'company_user') === [], 'Company user cannot read another company training');
trainingCheck((int) qmsTrainingFind($pdo, 920002, 920002, 'super_admin')['id'] === 920002, 'Super admin reads any training');
trainingCheck(qmsTrainingFind($pdo, 920003, 920002, 'super_admin') === [], 'Inactive training is not readable');
trainingCheck((int) qmsTrainingFind($pdo, 920002, 920004, 'auditor')['id'] === 920002, 'Auditor reads the company of an assigned audit');
trainingCheck(qmsTrainingFind($pdo, 920001, 920004, 'auditor') === [], 'Auditor cannot read a company without an assigned audit');

$list = qmsTrainingList($pdo, 920001, 'system_admin');
trainingCheck(count($list) === 1 && (int) $list[0]['id'] === 920001, 'Training list is limited to the visible tenant');
trainingCheck(count(qmsTrainingList($pdo, 920002, 'super_admin')) === 2, 'Super admin list sees every active training');
$pdo->exec('UPDATE company_admin_assignments SET active = 0');
trainingCheck(qmsTrainingList($pdo, 920001, 'system_admin') === [], 'Revoked assignment removes access');

// Katilimci yonetimi.
$pdo->prepare('INSERT INTO training_participants(training_id, user_id, status, score) VALUES(920001, 920003, ?, ?)')
    ->execute(['completed', 85]);
$pdo->prepare('INSERT INTO training_participants(training_id, user_id, status) VALUES(920001, 920001, ?)')
    ->execute(['assigned']);
$participants = qmsTrainingParticipants($pdo, 920001);
trainingCheck(count($participants) === 2, 'Participants are listed for one training only');
trainingCheck(isset($participants[0]['full_name']), 'Participant list joins the user record');

$summary = qmsTrainingParticipantSummary($participants);
trainingCheck($summary['total'] === 2 && $summary['completed'] === 1 && $summary['rate'] === 50.0, 'Participant summary counts completion rate');
trainingCheck(qmsTrainingParticipantSummary([]) === ['total' => 0, 'completed' => 0, 'attended' => 0, 'rate' => 0.0], 'Empty participant summary is safe');

$duplicateRejected = false;
try {
    $pdo->prepare('INSERT INTO training_participants(training_id, user_id, status) VALUES(920001, 920003, ?)')->execute(['assigned']);
} catch (PDOException $error) {
    $duplicateRejected = true;
}
trainingCheck($duplicateRejected, 'A user cannot be added twice to the same training');

trainingCheck(
    array_keys(qmsTrainingStatusLabels()) === QMS_TRAINING_STATUSES,
    'Status labels match the documented flow'
);
trainingCheck(
    count(qmsTrainingStatusI18nKeys()) === count(QMS_TRAINING_STATUSES)
        && count(qmsTrainingParticipantStatusI18nKeys()) === count(QMS_TRAINING_PARTICIPANT_STATUSES),
    'Every status has an i18n key'
);
trainingCheck((int) $pdo->query('SELECT COUNT(*) FROM trainings')->fetchColumn() === 3, 'Temporary table holds only the fixtures');

session_destroy();
echo "Completed $checks training checks using temporary tables." . PHP_EOL;
