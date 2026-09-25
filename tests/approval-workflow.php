<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
session_start();
require 'config/database.php';
require 'includes/approval-workflow-functions.php';
$checks = 0;
function awCheck(bool $ok, string $name): void { global $checks; if (!$ok) throw new RuntimeException('FAIL: ' . $name); $checks++; echo 'PASS: ' . $name . PHP_EOL; }

// Gercek tablolarin semasi gecici tablolara kopyalanir; FK'lar cikarilir.
foreach (['companies','users','company_admin_assignments','approval_runs','approval_run_steps'] as $table) {
    $schema = $pdo->query("SHOW CREATE TABLE $table")->fetch(PDO::FETCH_NUM)[1];
    $schema = preg_replace('/(,\n)?\s*CONSTRAINT[^\n]+/', '', $schema);
    $pdo->exec(str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $schema));
}

$pdo->exec("INSERT INTO companies(id, company_name, active) VALUES(99401,'AW A',1),(99402,'AW B',1)");
$pdo->exec("INSERT INTO users(id, username, password_hash, full_name, role, company_id, active) VALUES
    (99401,'aw-admin1','x','AW Admin1','system_admin',NULL,1),
    (99402,'aw-root','x','AW Root','super_admin',NULL,1),
    (99403,'aw-user','x','AW User','company_user',99401,1),
    (99404,'aw-admin2','x','AW Admin2','system_admin',NULL,1)");
$pdo->exec("INSERT INTO company_admin_assignments(company_id, admin_user_id, active) VALUES(99401,99401,1),(99402,99404,1),(99401,99404,1)");

// ---- Imzalayan secenekleri: sadece sistem + super admin.
$options = qmsApprovalApproverOptions($pdo);
$ids = array_map('intval', array_column($options, 'id'));
awCheck(in_array(99401, $ids, true) && in_array(99404, $ids, true) && in_array(99402, $ids, true), 'Approver options include system/super admins');
awCheck(!in_array(99403, $ids, true), 'Approver options exclude company users');

// ---- Akis olusturma.
$runId = qmsApprovalCreateRun($pdo, [
    'company_id' => 99401, 'subject' => 'Sözleşme onayı', 'entity_type' => 'contract', 'entity_id' => 77,
    'steps' => [['step_name' => 'Teknik', 'approver_user_id' => 99401], ['step_name' => 'Mali', 'approver_user_id' => 99404]],
], 99402, 'super_admin');
awCheck($runId !== null && $runId > 0, 'Valid run is created');
$steps = qmsApprovalSteps($pdo, (int) $runId);
awCheck(count($steps) === 2 && (int) $steps[0]['step_order'] === 1, 'Two ordered steps are stored');

// ---- Kapsam gecersiz sirket / eksik adim reddeder.
awCheck(qmsApprovalCreateRun($pdo, ['company_id' => 99402, 'subject' => 'X', 'steps' => [['step_name' => 'S', 'approver_user_id' => 99401]]], 99401, 'system_admin') === null, 'Out-of-scope company is rejected');
awCheck(qmsApprovalCreateRun($pdo, ['company_id' => 99401, 'subject' => 'X', 'steps' => []], 99401, 'system_admin') === null, 'Empty steps are rejected');
awCheck(qmsApprovalCreateRun($pdo, ['company_id' => 99401, 'subject' => '', 'steps' => [['step_name' => 'S', 'approver_user_id' => 99401]]], 99401, 'system_admin') === null, 'Blank subject is rejected');

// ---- Liste/find kapsami.
awCheck(count(qmsApprovalRunList($pdo, 99401, 'system_admin')) === 1, 'Assigned admin lists only own-company runs');
awCheck(count(qmsApprovalRunList($pdo, 99402, 'super_admin')) === 1, 'Super admin lists the run');
awCheck((int) qmsApprovalRunFind($pdo, (int) $runId, 99401, 'system_admin')['id'] === (int) $runId, 'Assigned admin reads own run');

// ---- Akis durumu + mevcut adim.
awCheck(qmsApprovalRunFind($pdo, (int) $runId, 99402, 'super_admin')['status'] === 'in_progress', 'New run starts in progress');
$current = qmsApprovalCurrentStep($pdo, (int) $runId);
awCheck((int) $current['approver_user_id'] === 99401, 'Current step is the first pending approver');

// ---- Sadece siradaki imzalayan imzalayabilir; onay akisi ilerler.
awCheck(qmsApprovalSign($pdo, (int) $runId, (int) $steps[1]['id'], 'approved', '', 99401, 'system_admin') === false, 'Cannot sign a non-current step');
awCheck(qmsApprovalSign($pdo, (int) $runId, (int) $steps[0]['id'], 'approved', '', 99404, 'system_admin') === false, 'Non-assigned user cannot sign');
awCheck(qmsApprovalSign($pdo, (int) $runId, (int) $steps[0]['id'], 'approved', 'ok', 99401, 'system_admin') === true, 'Assigned approver signs the current step');
awCheck(qmsApprovalRunFind($pdo, (int) $runId, 99402, 'super_admin')['status'] === 'in_progress', 'Run stays in progress after first signature');
awCheck((int) qmsApprovalCurrentStep($pdo, (int) $runId)['id'] === (int) $steps[1]['id'], 'Current step advances to the second');

awCheck(qmsApprovalSign($pdo, (int) $runId, (int) $steps[1]['id'], 'approved', 'onay', 99404, 'system_admin') === true, 'Second approver signs');
awCheck(qmsApprovalRunFind($pdo, (int) $runId, 99402, 'super_admin')['status'] === 'approved', 'Run is approved when all steps are signed');

// ---- Red durumu.
$run2 = qmsApprovalCreateRun($pdo, ['company_id' => 99401, 'subject' => 'Red test', 'steps' => [['step_name' => 'S1', 'approver_user_id' => 99401]]], 99402, 'super_admin');
$s2 = qmsApprovalSteps($pdo, (int) $run2);
awCheck(qmsApprovalSign($pdo, (int) $run2, (int) $s2[0]['id'], 'rejected', 'olmaz', 99401, 'system_admin') === true, 'A signer can reject');
awCheck(qmsApprovalRunFind($pdo, (int) $run2, 99402, 'super_admin')['status'] === 'rejected', 'Run is rejected when any step is rejected');

echo "\nCompleted $checks approval-workflow checks using temporary tables.\n";
