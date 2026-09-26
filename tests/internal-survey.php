<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
session_start();
require 'config/database.php';
require 'includes/internal-survey-functions.php';
$checks = 0;
function isCheck(bool $ok, string $name): void { global $checks; if (!$ok) throw new RuntimeException('FAIL: ' . $name); $checks++; echo 'PASS: ' . $name . PHP_EOL; }

$tables = ['companies','users','company_admin_assignments','internal_surveys','internal_survey_questions','internal_survey_responses'];
foreach ($tables as $t) {
    $s = $pdo->query("SHOW CREATE TABLE $t")->fetch(PDO::FETCH_NUM)[1];
    $s = preg_replace('/(,\n)?\s*CONSTRAINT[^\n]+/', '', $s);
    $pdo->exec(str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $s));
}
$pdo->exec("INSERT INTO companies(id,company_name,active) VALUES(99884,'IS A',1),(99885,'IS B',1)");
$pdo->exec("INSERT INTO users(id,username,password_hash,full_name,role,company_id,active) VALUES(99884,'is','x','IS','super_admin',NULL,1)");

$sid = qmsInternalSurveyAdd($pdo, ['company_id'=>99884,'title'=>'Memnuniyet 2026','description'=>'Yıllık anket','published'=>true], 99884, 'super_admin');
isCheck($sid !== null && $sid > 0, 'Survey added');
isCheck(count(qmsInternalSurveyList($pdo, 99884, 'super_admin')) === 1, 'Survey listed');
isCheck(qmsInternalSurveyFind($pdo, (int)$sid, 99884, 'super_admin')['title'] === 'Memnuniyet 2026', 'Survey fetched by id');

// Dogrulama redler.
isCheck(qmsInternalSurveyAdd($pdo, ['company_id'=>0,'title'=>'x','description'=>null,'published'=>true], 99884, 'super_admin') === null, 'Blank company rejected');
isCheck(qmsInternalSurveyAdd($pdo, ['company_id'=>99885,'title'=>'y','description'=>null,'published'=>true], 99884, 'system_admin') === null, 'Cross-company rejected');

// Sorular.
$q1 = qmsInternalSurveyAddQuestion($pdo, (int)$sid, ['question_text'=>'Genel memnuniyet','question_type'=>'rating'], 99884, 'super_admin');
$q2 = qmsInternalSurveyAddQuestion($pdo, (int)$sid, ['question_text'=>'Öneriler','question_type'=>'text'], 99884, 'super_admin');
isCheck($q1 !== null && $q2 !== null, 'Questions added');
isCheck(count(qmsInternalSurveyQuestions($pdo, (int)$sid)) === 2, 'Questions listed');

// Yanit.
isCheck(qmsInternalSurveyHasResponded($pdo, (int)$sid, 99884) === false, 'Not responded yet');
isCheck(count(qmsInternalFillableSurveys($pdo, 99884, 'super_admin')) === 1, 'Published survey is fillable');
isCheck(qmsInternalSurveySubmit($pdo, (int)$sid, [(int)$q1=>5, (int)$q2=>'İyi gidiyor'], 99884, 'super_admin') === true, 'Responses submitted');
isCheck(qmsInternalSurveyHasResponded($pdo, (int)$sid, 99884) === true, 'Responded now true');
isCheck(count(qmsInternalFillableSurveys($pdo, 99884, 'super_admin')) === 0, 'Filled survey no longer fillable');
isCheck(qmsInternalSurveySubmit($pdo, (int)$sid, [(int)$q1=>3], 99884, 'super_admin') === false, 'Duplicate submission rejected');

$results = qmsInternalSurveyResults($pdo, (int)$sid);
isCheck(count($results) === 2, 'Results has per-question row');
isCheck((float)$results[0]['avg_rating'] === 5.0, 'Rating average computed');

// Yayinda olmayan anket dolduramaz.
$pid = qmsInternalSurveyAdd($pdo, ['company_id'=>99884,'title'=>'Taslak','description'=>null,'published'=>false], 99884, 'super_admin');
isCheck(count(qmsInternalFillableSurveys($pdo, 99884, 'super_admin')) === 0, 'Draft survey not fillable');

// Kapsam: atanan admin kendi sirketini gorur; taslak guncellenebilir.
$pdo->exec("INSERT INTO company_admin_assignments(company_id,admin_user_id,active) VALUES(99884,99884,1)");
isCheck(count(qmsInternalSurveyList($pdo, 99884, 'system_admin')) === 2, 'Assigned admin sees own surveys');
isCheck(qmsInternalSurveyUpdate($pdo, (int)$pid, ['title'=>'Taslak v2','description'=>'','published'=>false], 99884, 'system_admin') === true, 'Survey updatable in scope');
isCheck(qmsInternalSurveyDeleteQuestion($pdo, (int)$q2, 99884, 'system_admin') === true, 'Question deletable in scope');
isCheck(count(qmsInternalSurveyQuestions($pdo, (int)$sid)) === 1, 'Deleted question hidden');
isCheck(qmsInternalSurveyDelete($pdo, (int)$sid, 99884, 'system_admin') === true, 'Survey deletable in scope');
isCheck(count(qmsInternalSurveyList($pdo, 99884, 'super_admin')) === 1, 'Deleted survey hidden');

echo "\nCompleted $checks internal-survey checks using temporary tables.\n";
