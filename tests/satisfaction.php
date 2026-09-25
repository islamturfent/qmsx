<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
session_start();
require 'config/database.php';
require 'includes/satisfaction-functions.php';
$checks = 0;
function sfCheck(bool $ok, string $name): void { global $checks; if (!$ok) throw new RuntimeException('FAIL: ' . $name); $checks++; echo 'PASS: ' . $name . PHP_EOL; }

// Gercek tablolarin semasi gecici tablolara kopyalanir; FK'lar cikarilir.
foreach (['companies','users','company_admin_assignments','satisfaction_surveys','satisfaction_questions','satisfaction_responses','satisfaction_response_answers'] as $table) {
    $schema = $pdo->query("SHOW CREATE TABLE $table")->fetch(PDO::FETCH_NUM)[1];
    $schema = preg_replace('/(,\n)?\s*CONSTRAINT[^\n]+/', '', $schema);
    $pdo->exec(str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $schema));
}

$pdo->exec("INSERT INTO companies(id, company_name, active) VALUES(98001,'SF A',1),(98002,'SF B',1)");
$pdo->exec("INSERT INTO users(id, username, password_hash, full_name, role, company_id, active) VALUES
    (98001,'sf-admin','x','SF Admin','system_admin',NULL,1),
    (98002,'sf-root','x','SF Root','super_admin',NULL,1),
    (98004,'sf-other','x','SF Other','system_admin',NULL,1)");
$pdo->exec("INSERT INTO company_admin_assignments(company_id, admin_user_id, active) VALUES(98001,98001,1),(98002,98004,1)");
$pdo->exec("INSERT INTO satisfaction_surveys(id, company_id, title, active) VALUES
    (98001,98001,'Survey A',1),(98002,98002,'Survey B',1)");
$pdo->exec("INSERT INTO satisfaction_questions(id, survey_id, question_text, sort_order, active) VALUES
    (98011,98001,'Quality',1,1),
    (98012,98001,'Delivery',2,1),
    (98013,98002,'OQ',1,1)");

// ---- Kapsamli liste / bulma.
$list = qmsSatisfactionSurveyList($pdo, 98001, 'system_admin');
sfCheck(count($list) === 1 && (int) $list[0]['id'] === 98001, 'Assigned admin lists only own-company surveys');
sfCheck(count(qmsSatisfactionSurveyList($pdo, 98002, 'super_admin')) === 2, 'Super admin lists every survey');
sfCheck((int) qmsSatisfactionSurveyFind($pdo, 98001, 98001, 'system_admin')['id'] === 98001, 'Assigned admin reads own survey');
sfCheck(qmsSatisfactionSurveyFind($pdo, 98002, 98001, 'system_admin') === [], 'Assigned admin cannot read another tenant survey');

// ---- Sorular.
sfCheck(count(qmsSatisfactionQuestions($pdo, 98001)) === 2, 'Survey questions are read');

// ---- Yanit kaydi: puan ortalamasi + soru dogrulama.
$respId = qmsSatisfactionRecordResponse($pdo, 98001, [
    'customer_name' => 'Acme', 'customer_contact' => 'x', 'responded_at' => '2026-06-20', 'comment' => 'ok',
    'answers' => [98011 => 5, 98012 => 3, 98013 => 1, 99999 => 4], // 98013 diger anketin sorusu, 99999 yok => yok sayilir
], 98001, 'system_admin');
sfCheck($respId !== null && $respId > 0, 'Valid response is recorded');
$overall = (int) $pdo->query('SELECT overall_score FROM satisfaction_responses WHERE id = ' . (int) $respId)->fetchColumn();
sfCheck($overall === 4, 'Overall score is the average of valid question ratings (4)');
$answerCount = (int) $pdo->query('SELECT COUNT(*) FROM satisfaction_response_answers WHERE response_id = ' . (int) $respId)->fetchColumn();
sfCheck($answerCount === 2, 'Only the survey own question ratings are stored');
$avg = (float) qmsSatisfactionSurveyFind($pdo, 98001, 98001, 'system_admin')['avg_score'];
sfCheck($avg === 4.0, 'Survey average reflects the recorded response');
sfCheck((int) qmsSatisfactionSurveyFind($pdo, 98001, 98001, 'system_admin')['response_count'] === 1, 'Survey response count increments');

// ---- Yanit listesi.
$responses = qmsSatisfactionResponses($pdo, 98001);
sfCheck(count($responses) === 1 && $responses[0]['answers'][98011] === 5, 'Response list returns per-question answers');

// ---- Dogrulama redleri.
sfCheck(qmsSatisfactionRecordResponse($pdo, 98001, ['responded_at' => '2026-06-20', 'answers' => []], 98001, 'system_admin') === null, 'Response with no answers is rejected');
// invalid date rejected
sfCheck(qmsSatisfactionRecordResponse($pdo, 98001, ['responded_at' => 'bad-date', 'answers' => [98011 => 5]], 98001, 'system_admin') === null, 'Invalid date is rejected');
// out-of-scope survey rejected
sfCheck(qmsSatisfactionRecordResponse($pdo, 98002, ['responded_at' => '2026-06-20', 'answers' => [98013 => 5]], 98001, 'system_admin') === null, 'Another tenant survey cannot receive a response');

echo "\nCompleted $checks satisfaction checks using temporary tables.\n";
