<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
session_start();
require 'config/database.php';
require 'includes/document-template-functions.php';
$checks = 0;
function dtCheck(bool $ok, string $name): void { global $checks; if (!$ok) throw new RuntimeException('FAIL: ' . $name); $checks++; echo 'PASS: ' . $name . PHP_EOL; }

$tables = ['companies','users','company_admin_assignments','document_templates'];
foreach ($tables as $t) {
    $s = $pdo->query("SHOW CREATE TABLE $t")->fetch(PDO::FETCH_NUM)[1];
    $s = preg_replace('/(,\n)?\s*CONSTRAINT[^\n]+/', '', $s);
    $pdo->exec(str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $s));
}
$pdo->exec("INSERT INTO companies(id,company_name,active) VALUES(99880,'DT A',1),(99881,'DT B',1)");
$pdo->exec("INSERT INTO users(id,username,password_hash,full_name,role,company_id,active) VALUES(99880,'dt','x','DT','super_admin',NULL,1)");

$id = qmsDocumentTemplateAdd($pdo, ['company_id'=>99880,'name'=>'Giriş Prosedürü','document_type'=>'procedure','category'=>'Genel','content_html'=>'<p>giriş</p>'], 99880, 'super_admin');
dtCheck($id !== null && $id > 0, 'Template is added');
dtCheck(count(qmsDocumentTemplateList($pdo, 99880, 'super_admin')) === 1, 'Template listed');
dtCheck(qmsDocumentTemplateFind($pdo, (int)$id, 99880, 'super_admin')['name'] === 'Giriş Prosedürü', 'Template fetched by id');
dtCheck(qmsDocumentTemplateUpdate($pdo, (int)$id, ['name'=>'Giriş v2','document_type'=>'work_instruction','category'=>'','content_html'=>'<p>v2</p>'], 99880, 'super_admin') === true, 'Template updated');
dtCheck(qmsDocumentTemplateFind($pdo, (int)$id, 99880, 'super_admin')['document_type'] === 'work_instruction', 'Update persists type');

// Dogrulama redleri.
dtCheck(qmsDocumentTemplateAdd($pdo, ['company_id'=>0,'name'=>'x','document_type'=>'procedure','category'=>'','content_html'=>null], 99880, 'super_admin') === null, 'Blank company rejected');
dtCheck(qmsDocumentTemplateAdd($pdo, ['company_id'=>99881,'name'=>'y','document_type'=>'bad','category'=>'','content_html'=>null], 99880, 'system_admin') === null, 'Invalid type / cross-company rejected');

// Kapsam: atanan admin yalnizca kendi sirketini gorur.
$pdo->exec("INSERT INTO company_admin_assignments(company_id,admin_user_id,active) VALUES(99880,99880,1)");
dtCheck(count(qmsDocumentTemplateList($pdo, 99880, 'system_admin')) === 1, 'Assigned admin sees own templates');
dtCheck(qmsDocumentTemplateDelete($pdo, (int)$id, 99880, 'system_admin') === true, 'Template deletable in scope');
dtCheck(count(qmsDocumentTemplateList($pdo, 99880, 'super_admin')) === 0, 'Deleted template hidden');

echo "\nCompleted $checks document-template checks using temporary tables.\n";
