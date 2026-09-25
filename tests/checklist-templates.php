<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
session_start();
require 'config/database.php';
require 'includes/checklist-template-functions.php';
$checks = 0;
function ctCheck(bool $ok, string $name): void { global $checks; if (!$ok) throw new RuntimeException('FAIL: ' . $name); $checks++; echo 'PASS: ' . $name . PHP_EOL; }

// Gercek tablolarin semasi gecici tablolara kopyalanir; FK'lar cikarilir.
foreach (['companies','users','company_admin_assignments','audits','audit_checklist_items','audit_checklist_templates','audit_checklist_template_items'] as $table) {
    $schema = $pdo->query("SHOW CREATE TABLE $table")->fetch(PDO::FETCH_NUM)[1];
    $schema = preg_replace('/(,\n)?\s*CONSTRAINT[^\n]+/', '', $schema);
    $pdo->exec(str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $schema));
}

$pdo->exec("INSERT INTO companies(id, company_name, active) VALUES(96001,'CT A',1),(96002,'CT B',1)");
$pdo->exec("INSERT INTO users(id, username, password_hash, full_name, role, company_id, active) VALUES
    (96001,'ct-admin','x','CT Admin','system_admin',NULL,1),
    (96002,'ct-root','x','CT Root','super_admin',NULL,1),
    (96004,'ct-other','x','CT Other','system_admin',NULL,1)");
$pdo->exec("INSERT INTO company_admin_assignments(company_id, admin_user_id, active) VALUES(96001,96001,1),(96002,96004,1)");
$pdo->exec("INSERT INTO audits(id, company_id, title, status, active) VALUES(96001,96001,'Audit A','planned',1),(96002,96002,'Audit B','planned',1)");
$pdo->exec("INSERT INTO audit_checklist_items(audit_id, item_text, result_status, active) VALUES(96001,'Existing A','pending',1)");
$pdo->exec("INSERT INTO audit_checklist_templates(id, company_id, title, active) VALUES
    (96001,96001,'Template A',1),(96002,96002,'Template B',1)");
$pdo->exec("INSERT INTO audit_checklist_template_items(template_id, item_text, requirement_ref, sort_order, active) VALUES
    (96001,'Item A1','ISO-1',1,1),
    (96001,'Item A2','ISO-2',2,1),
    (96002,'Item B1','X',1,1)");

// ---- Kapsamli liste.
$listA = qmsChecklistTemplateList($pdo, 96001, 'system_admin');
ctCheck(count($listA) === 1 && (int) $listA[0]['id'] === 96001, 'Assigned admin lists only own-company templates');
ctCheck((int) $listA[0]['item_count'] === 2, 'Template list counts active items');
ctCheck(count(qmsChecklistTemplateList($pdo, 96002, 'super_admin')) === 2, 'Super admin lists every template');
ctCheck(qmsChecklistTemplateList($pdo, 96001, 'system_admin') !== [], 'List returns rows for the visible tenant');

// ---- Kapsamli bulma.
ctCheck((int) qmsChecklistTemplateFind($pdo, 96002, 96002, 'super_admin')['id'] === 96002, 'Super admin reads any template');
ctCheck(qmsChecklistTemplateFind($pdo, 96002, 96001, 'system_admin') === [], 'Assigned admin cannot read another tenant template');
ctCheck((string) qmsChecklistTemplateFind($pdo, 96001, 96001, 'system_admin')['company_name'] === 'CT A', 'Template find joins the company');

// ---- Maddeler.
$items = qmsChecklistTemplateItems($pdo, 96001);
ctCheck(count($items) === 2, 'Template items are read in order');

// ---- Denetime uygulama (ayni sirket).
$auditA = ['id' => 96001, 'company_id' => 96001];
ctCheck(qmsChecklistTemplateApply($pdo, 96001, $auditA, 96001, 'system_admin') === 2, 'Same-company template applies two items');
$auditItems = $pdo->query("SELECT item_text FROM audit_checklist_items WHERE audit_id = 96001")->fetchAll(PDO::FETCH_ASSOC);
$texts = array_column($auditItems, 'item_text');
ctCheck(in_array('Item A1', $texts, true) && in_array('Item A2', $texts, true), 'Applied items land in the audit checklist');

// ---- Tekrar uygulama: zaten var olanlar eklenmez.
ctCheck(qmsChecklistTemplateApply($pdo, 96001, $auditA, 96001, 'system_admin') === 0, 'Re-applying skips existing items');
ctCheck((int) $pdo->query("SELECT COUNT(*) FROM audit_checklist_items WHERE audit_id = 96001")->fetchColumn() === 3, 'No duplicate items are inserted');

// ---- Farkli sirket sablonu uygulanamaz.
ctCheck(qmsChecklistTemplateApply($pdo, 96002, $auditA, 96001, 'system_admin') === 0, 'Another tenant template cannot be applied');
ctCheck((int) $pdo->query("SELECT COUNT(*) FROM audit_checklist_items WHERE audit_id = 96001")->fetchColumn() === 3, 'Cross-tenant apply adds nothing');

// ---- Gecersiz denetim bos dondurur.
ctCheck(qmsChecklistTemplateApply($pdo, 96001, ['id' => 0, 'company_id' => 96001], 96001, 'system_admin') === 0, 'Invalid audit id applies nothing');

echo "\nCompleted $checks checklist-template checks using temporary tables.\n";
