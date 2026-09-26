<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
session_start();
require 'config/database.php';
require 'includes/contract-functions.php';
$checks = 0;
function caCheck(bool $ok, string $name): void { global $checks; if (!$ok) throw new RuntimeException('FAIL: ' . $name); $checks++; echo 'PASS: ' . $name . PHP_EOL; }

$tables = ['companies','users','company_admin_assignments','contracts','contract_attachments'];
foreach ($tables as $t) {
    $s = $pdo->query("SHOW CREATE TABLE $t")->fetch(PDO::FETCH_NUM)[1];
    $s = preg_replace('/(,\n)?\s*CONSTRAINT[^\n]+/', '', $s);
    $pdo->exec(str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $s));
}
$pdo->exec("INSERT INTO companies(id,company_name,active) VALUES(99901,'CA A',1)");
$pdo->exec("INSERT INTO users(id,username,password_hash,full_name,role,company_id,active) VALUES(99901,'ca','x','CA','super_admin',NULL,1)");
$pdo->exec("INSERT INTO contracts(id, company_id, contract_name, contract_type, status, active) VALUES(99901,99901,'Bakım','supplier','active',1)");
$pdo->exec("INSERT INTO contract_attachments(id, contract_id, original_name, stored_name, mime_type, file_size) VALUES(99901,99901,'sozlesme.pdf','abc123.pdf','application/pdf',2048)");

caCheck(count(qmsContractAttachments($pdo, 99901)) === 1, 'Attachments listed for contract');

// Kapsam: super admin gorur; atanmamis admin goremez.
$att = qmsContractAttachmentFind($pdo, 99901, 99901, 'super_admin');
caCheck(!empty($att), 'Attachment findable in scope');
caCheck((string) $att['original_name'] === 'sozlesme.pdf', 'Attachment original name persisted');

// Kapsam: sistem admini bu sirkete atanmamis -> bulamaz.
caCheck(empty(qmsContractAttachmentFind($pdo, 99901, 99901, 'system_admin')), 'Out-of-scope admin cannot find attachment');

// Kapsam: atanan admin bulabilir.
$pdo->exec("INSERT INTO company_admin_assignments(company_id,admin_user_id,active) VALUES(99901,99901,1)");
caCheck(!empty(qmsContractAttachmentFind($pdo, 99901, 99901, 'system_admin')), 'Assigned admin finds attachment');

// Sil: kayit + (varsa) dosya kaldirilir.
caCheck(qmsContractDeleteAttachment($pdo, 99901, 99901, 'system_admin') === true, 'Attachment deletable in scope');
caCheck(count(qmsContractAttachments($pdo, 99901)) === 0, 'Attachment list empty after delete');

echo "\nCompleted $checks contract-attachment checks using temporary tables.\n";
