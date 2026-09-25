<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
session_start();
require 'config/database.php';
require 'includes/document-copy-functions.php';
$checks = 0;
function dcCheck(bool $ok, string $name): void { global $checks; if (!$ok) throw new RuntimeException('FAIL: ' . $name); $checks++; echo 'PASS: ' . $name . PHP_EOL; }

// Gercek tablolarin semasi gecici tablolara kopyalanir; FK'lar cikarilir.
foreach (['companies','users','company_admin_assignments','documents','document_copies'] as $table) {
    $schema = $pdo->query("SHOW CREATE TABLE $table")->fetch(PDO::FETCH_NUM)[1];
    $schema = preg_replace('/(,\n)?\s*CONSTRAINT[^\n]+/', '', $schema);
    $pdo->exec(str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $schema));
}

$pdo->exec("INSERT INTO companies(id, company_name, active) VALUES(99301,'DC A',1),(99302,'DC B',1)");
$pdo->exec("INSERT INTO users(id, username, password_hash, full_name, role, company_id, active) VALUES
    (99301,'dc-admin','x','DC Admin','system_admin',NULL,1),
    (99302,'dc-root','x','DC Root','super_admin',NULL,1),
    (99304,'dc-other','x','DC Other','system_admin',NULL,1)");
$pdo->exec("INSERT INTO company_admin_assignments(company_id, admin_user_id, active) VALUES(99301,99301,1),(99302,99304,1)");
$pdo->exec("INSERT INTO documents(id, company_id, document_code, title, status, current_revision, active) VALUES
    (99301,99301,'D-A','Doc A','published','01',1),
    (99302,99302,'D-B','Doc B','published','01',1)");
$pdo->exec("INSERT INTO document_copies(id, company_id, document_id, copy_no, recipient_name, location, status, distributed_on, active) VALUES
    (99301,99301,99301,'C-01','Ali','Ofis','distributed','2026-05-01',1),
    (99302,99301,99301,'C-02','Veli','Depo','returned','2026-06-01',1),
    (99303,99302,99302,'C-01','Ayse','Ofis','distributed','2026-05-01',1)");

// ---- Kapsamli liste + filter.
$list = qmsDocumentCopyList($pdo, 99301, 'system_admin');
dcCheck(count($list) === 2, 'Assigned admin lists only own-company copies');
dcCheck(count(qmsDocumentCopyList($pdo, 99302, 'super_admin')) === 3, 'Super admin lists every copy');
dcCheck(count(qmsDocumentCopyList($pdo, 99301, 'system_admin', 'returned')) === 1, 'Status filter narrows');
dcCheck((string) $list[0]['document_code'] === 'D-A', 'Copy list joins the document');

// ---- Kapsamli bulma.
dcCheck((int) qmsDocumentCopyFind($pdo, 99301, 99301, 'system_admin')['id'] === 99301, 'Assigned admin reads own copy');
dcCheck(qmsDocumentCopyFind($pdo, 99303, 99301, 'system_admin') === [], 'Assigned admin cannot read another tenant copy');

// ---- Ekleme.
$newId = qmsDocumentCopyAdd($pdo, ['document_id' => 99301, 'copy_no' => 'C-03', 'recipient_name' => 'Fatma', 'location' => 'Lab', 'distributed_on' => '2026-07-01', 'notes' => 'x'], 99301, 'system_admin');
dcCheck($newId !== null && $newId > 0, 'Valid copy is added');
dcCheck(qmsDocumentCopyAdd($pdo, ['document_id' => 99301, 'copy_no' => 'C-01', 'recipient_name' => 'X', 'distributed_on' => '2026-07-01'], 99301, 'system_admin') === null, 'Duplicate copy number is rejected');
dcCheck(qmsDocumentCopyAdd($pdo, ['document_id' => 99302, 'copy_no' => 'C-99', 'recipient_name' => 'X', 'distributed_on' => '2026-07-01'], 99301, 'system_admin') === null, 'Another tenant document is rejected');
dcCheck(qmsDocumentCopyAdd($pdo, ['document_id' => 99301, 'copy_no' => 'C-04', 'recipient_name' => '', 'distributed_on' => '2026-07-01'], 99301, 'system_admin') === null, 'Blank recipient is rejected');

// ---- Durum guncelleme (iade).
dcCheck(qmsDocumentCopyUpdateStatus($pdo, $newId, 'returned', '2026-07-10', 99301, 'system_admin') === true, 'Copy can be returned');
$ret = qmsDocumentCopyFind($pdo, $newId, 99301, 'system_admin');
dcCheck($ret['status'] === 'returned' && $ret['returned_on'] === '2026-07-10', 'Return sets the return date');
dcCheck(qmsDocumentCopyUpdateStatus($pdo, $newId, 'distributed', null, 99301, 'system_admin') === true, 'Copy can be re-distributed');
$ret2 = qmsDocumentCopyFind($pdo, $newId, 99301, 'system_admin');
dcCheck($ret2['returned_on'] === null, 'Re-distributing clears the return date');
dcCheck(qmsDocumentCopyUpdateStatus($pdo, 99303, 'returned', null, 99301, 'system_admin') === false, 'Another tenant copy cannot be changed');

echo "\nCompleted $checks document-copy checks using temporary tables.\n";
