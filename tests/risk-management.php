<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
session_start();
require 'config/database.php';
require 'includes/risk-functions.php';
$checks=0;
function riskCheck(bool $ok,string $name):void{global $checks;if(!$ok)throw new RuntimeException('FAIL: '.$name);$checks++;echo 'PASS: '.$name.PHP_EOL;}
function riskReject(callable $fn,string $name):void{try{$fn();}catch(RuntimeException $e){riskCheck(true,$name);return;}throw new RuntimeException('FAIL: '.$name);}
foreach(['companies','users','company_admin_assignments','risks','risk_history'] as $table){$schema=$pdo->query("SHOW CREATE TABLE $table")->fetch(PDO::FETCH_NUM)[1];$schema=preg_replace('/(,\n)?\s*CONSTRAINT[^\n]+/','',$schema);$pdo->exec(str_replace('CREATE TABLE','CREATE TEMPORARY TABLE',$schema));}
$pdo->exec("INSERT INTO companies(id,company_name,active) VALUES(910001,'Tenant A',1),(910002,'Tenant B',1)");
$pdo->exec("INSERT INTO users(id,username,password_hash,full_name,role,active) VALUES(910001,'risk-admin','x','Risk Admin','system_admin',1),(910002,'risk-root','x','Risk Root','super_admin',1)");
$pdo->exec("INSERT INTO company_admin_assignments(company_id,admin_user_id,active) VALUES(910001,910001,1)");
riskCheck(qmsRiskScope($pdo,910001,false)===[910001],'System admin scope contains assigned tenant only');
riskCheck(count(qmsRiskScope($pdo,910002,true))===2,'Super admin sees active tenants');
riskCheck(qmsRiskScore(5,5)===25 && qmsRiskScore(null,5)===null,'Risk scores are calculated');
riskCheck(qmsRiskLevel(4)==='low'&&qmsRiskLevel(5)==='medium'&&qmsRiskLevel(10)==='high'&&qmsRiskLevel(17)==='critical','Risk thresholds match documented assumptions');
$valid=['company_id'=>910001,'title'=>'Tedarik kesintisi','category'=>'Operasyon','responsible_person'=>'Satın Alma','due_date'=>'2026-12-31','status'=>'open','description'=>'Tek kaynak riski','existing_controls'=>'Güvenlik stoğu','treatment_plan'=>'İkinci kaynak','initial_likelihood'=>'4','initial_impact'=>'5','residual_likelihood'=>'2','residual_impact'=>'3','note'=>'İlk kayıt'];
$data=qmsRiskValidate($valid,[910001],true);riskCheck($data['initial_likelihood']===4&&$data['residual_impact']===3,'Valid input normalized');
riskReject(fn()=>qmsRiskValidate(array_replace($valid,['company_id'=>910002]),[910001],true),'Cross-tenant company rejected');
riskReject(fn()=>qmsRiskValidate(array_replace($valid,['initial_likelihood'=>6]),[910001],true),'Likelihood outside 1-5 rejected');
riskReject(fn()=>qmsRiskValidate(array_replace($valid,['residual_impact'=>'']),[910001],true),'Partial residual assessment rejected');
riskReject(fn()=>qmsRiskValidate(array_replace($valid,['status'=>'deleted']),[910001],true),'Unknown status rejected');
riskReject(fn()=>qmsRiskValidate(array_replace($valid,['due_date'=>'2026-02-31']),[910001],true),'Invalid date rejected');
$_SESSION['risk_csrf']='token';qmsRiskVerifyCsrf('token');riskReject(fn()=>qmsRiskVerifyCsrf('wrong'),'CSRF rejected');
$stmt=$pdo->prepare('INSERT INTO risks(company_id,title,status,description,initial_likelihood,initial_impact,residual_likelihood,residual_impact,created_by,updated_by) VALUES(?,?,?,?,?,?,?,?,?,?)');
$stmt->execute([910001,$data['title'],'open',$data['description'],4,5,2,3,910001,910001]);$riskId=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO risk_history(risk_id,status,residual_likelihood,residual_impact,note,changed_by) VALUES(?,?,?,?,?,?)')->execute([$riskId,'open',2,3,'İlk kayıt',910001]);
riskCheck((int)qmsRiskFind($pdo,$riskId,910001,false)['company_id']===910001,'Assigned user reads own tenant risk');
riskCheck(qmsRiskFind($pdo,$riskId,999999,false)===[],'Unassigned user cannot read risk');
riskCheck((int)qmsRiskFind($pdo,$riskId,910002,true)['id']===$riskId,'Super admin reads risk');
$pdo->beginTransaction();$locked=qmsRiskFind($pdo,$riskId,910001,false,true);$oldVersion=(string)($locked['updated_at']??'');$pdo->prepare("UPDATE risks SET status='monitoring',residual_likelihood=2,residual_impact=2,updated_by=? WHERE id=?")->execute([910001,$riskId]);$pdo->prepare('INSERT INTO risk_history(risk_id,status,residual_likelihood,residual_impact,note,changed_by) VALUES(?,?,?,?,?,?)')->execute([$riskId,'monitoring',2,2,'Kontrol uygulandı',910001]);$pdo->commit();
$updated=qmsRiskFind($pdo,$riskId,910001,false);riskCheck($updated['status']==='monitoring'&&qmsRiskScore($updated['residual_likelihood'],$updated['residual_impact'])===4,'Tracking update changes residual score');
riskCheck((int)$pdo->query("SELECT COUNT(*) FROM risk_history WHERE risk_id=$riskId")->fetchColumn()===2,'Tracking history appended');
riskCheck((string)$updated['updated_at']!==$oldVersion,'Optimistic version advances after update');
$pdo->exec('UPDATE company_admin_assignments SET active=0');riskCheck(qmsRiskFind($pdo,$riskId,910001,false)===[],'Revoked assignment removes access');
riskCheck((int)$pdo->query('SELECT COUNT(*) FROM risks')->fetchColumn()===1,'Temporary test risk exists only in isolated table');
session_destroy();echo "Completed $checks risk checks using temporary tables.".PHP_EOL;
