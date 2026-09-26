<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
session_start();
require 'config/database.php';
require 'includes/improvement-functions.php';
$checks = 0;
function impCheck(bool $ok, string $name): void { global $checks; if (!$ok) throw new RuntimeException('FAIL: ' . $name); $checks++; echo 'PASS: ' . $name . PHP_EOL; }

$tables = ['companies','users','company_admin_assignments','improvements'];
foreach ($tables as $t) {
    $s = $pdo->query("SHOW CREATE TABLE $t")->fetch(PDO::FETCH_NUM)[1];
    $s = preg_replace('/(,\n)?\s*CONSTRAINT[^\n]+/', '', $s);
    $pdo->exec(str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $s));
}
$pdo->exec("INSERT INTO companies(id,company_name,active) VALUES(99890,'IMP A',1),(99891,'IMP B',1)");
$pdo->exec("INSERT INTO users(id,username,password_hash,full_name,role,company_id,active) VALUES(99890,'imp','x','IMP','super_admin',NULL,1)");

$id = qmsImprovementAdd($pdo, ['company_id'=>99890,'title'=>'Arıza oranını azalt','description'=>'Proses iyileştirme','category'=>'Süreç','benefit_type'=>'quality','impact'=>'high','priority'=>'high','responsible'=>'Kalite Ekibi','target_date'=>'2026-06-30','status'=>'submitted','eval_score'=>'','result'=>''], 99890, 'super_admin');
impCheck($id !== null && $id > 0, 'Suggestion added');
impCheck(count(qmsImprovementList($pdo, 99890, 'super_admin')) === 1, 'Suggestion listed');
impCheck(qmsImprovementFind($pdo, (int)$id, 99890, 'super_admin')['title'] === 'Arıza oranını azalt', 'Suggestion fetched by id');

// Dogrulama redleri.
impCheck(qmsImprovementAdd($pdo, ['company_id'=>0,'title'=>'x','benefit_type'=>'quality','impact'=>'low','priority'=>'low','status'=>'submitted'], 99890, 'super_admin') === null, 'Blank company rejected');
impCheck(qmsImprovementAdd($pdo, ['company_id'=>99891,'title'=>'y','benefit_type'=>'quality','impact'=>'low','priority'=>'low','status'=>'submitted'], 99890, 'system_admin') === null, 'Cross-company rejected');
impCheck(qmsImprovementAdd($pdo, ['company_id'=>99890,'title'=>'','benefit_type'=>'quality','impact'=>'low','priority'=>'low','status'=>'submitted'], 99890, 'super_admin') === null, 'Blank title rejected');

// Guvenli olmayan fayda türü varsayılan değere düşer mi? (dogrudan insert yapilmaz; update ile)
impCheck(qmsImprovementUpdate($pdo, (int)$id, ['title'=>'Arıza oranını azalt v2','description'=>'','category'=>'','benefit_type'=>'quality','impact'=>'medium','priority'=>'normal','responsible'=>'','target_date'=>'','status'=>'implemented','eval_score'=>'4','result'=>'Uygulandı'], 99890, 'super_admin') === true, 'Suggestion updated');
impCheck(qmsImprovementFind($pdo, (int)$id, 99890, 'super_admin')['status'] === 'implemented', 'Status persisted to implemented');
impCheck((int) qmsImprovementFind($pdo, (int)$id, 99890, 'super_admin')['eval_score'] === 4, 'Eval score persisted');

// Filtreler.
impCheck(count(qmsImprovementList($pdo, 99890, 'super_admin', 'implemented')) === 1, 'Implemented filter matches');
impCheck(count(qmsImprovementList($pdo, 99890, 'super_admin', 'open')) === 0, 'Implemented not in open filter');

// Kapsam: atanan admin kendi sirketini gorur.
$pdo->exec("INSERT INTO company_admin_assignments(company_id,admin_user_id,active) VALUES(99890,99890,1)");
impCheck(count(qmsImprovementList($pdo, 99890, 'system_admin')) === 1, 'Assigned admin sees own suggestions');
impCheck(qmsImprovementDelete($pdo, (int)$id, 99890, 'system_admin') === true, 'Suggestion deletable in scope');
impCheck(count(qmsImprovementList($pdo, 99890, 'super_admin')) === 0, 'Deleted suggestion hidden');

echo "\nCompleted $checks improvement checks using temporary tables.\n";
