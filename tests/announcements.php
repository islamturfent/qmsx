<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
session_start();
require 'config/database.php';
require 'includes/announcement-functions.php';
$checks = 0;
function anCheck(bool $ok, string $name): void { global $checks; if (!$ok) throw new RuntimeException('FAIL: ' . $name); $checks++; echo 'PASS: ' . $name . PHP_EOL; }

$tables = ['companies','users','company_admin_assignments','announcements'];
foreach ($tables as $t) {
    $s = $pdo->query("SHOW CREATE TABLE $t")->fetch(PDO::FETCH_NUM)[1];
    $s = preg_replace('/(,\n)?\s*CONSTRAINT[^\n]+/', '', $s);
    $pdo->exec(str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $s));
}
$pdo->exec("INSERT INTO companies(id,company_name,active) VALUES(99882,'AN A',1),(99883,'AN B',1)");
$pdo->exec("INSERT INTO users(id,username,password_hash,full_name,role,company_id,active) VALUES(99882,'an','x','AN','super_admin',NULL,1)");

$id = qmsAnnouncementAdd($pdo, ['company_id'=>99882,'title'=>'Bakım Duyurusu','body'=>'Cuma bakım yapılacak.','published'=>true], 99882, 'super_admin');
anCheck($id !== null && $id > 0, 'Announcement added');
anCheck(count(qmsAnnouncementList($pdo, 99882, 'super_admin')) === 1, 'Announcement listed');
anCheck(qmsAnnouncementFind($pdo, (int)$id, 99882, 'super_admin')['title'] === 'Bakım Duyurusu', 'Announcement fetched by id');
anCheck(qmsAnnouncementUpdate($pdo, (int)$id, ['title'=>'Bakım v2','body'=>'Yeni saatler','published'=>false], 99882, 'super_admin') === true, 'Announcement updated');
anCheck(qmsAnnouncementFind($pdo, (int)$id, 99882, 'super_admin')['published'] == 0, 'Publish toggle persists');
anCheck(count(qmsAnnouncementList($pdo, 99882, 'super_admin', true)) === 0, 'Draft hidden from published-only list');

// Dogrulama redleri.
anCheck(qmsAnnouncementAdd($pdo, ['company_id'=>0,'title'=>'x','body'=>null,'published'=>true], 99882, 'super_admin') === null, 'Blank company rejected');
anCheck(qmsAnnouncementAdd($pdo, ['company_id'=>99883,'title'=>'y','body'=>null,'published'=>true], 99882, 'system_admin') === null, 'Cross-company rejected');

// Kapsam: atanan admin yalnizca kendi sirketini gorur.
$pdo->exec("INSERT INTO company_admin_assignments(company_id,admin_user_id,active) VALUES(99882,99882,1)");
anCheck(count(qmsAnnouncementList($pdo, 99882, 'system_admin')) === 1, 'Assigned admin sees own announcements');
anCheck(qmsAnnouncementDelete($pdo, (int)$id, 99882, 'system_admin') === true, 'Announcement deletable in scope');
anCheck(count(qmsAnnouncementList($pdo, 99882, 'super_admin')) === 0, 'Deleted announcement hidden');

echo "\nCompleted $checks announcement checks using temporary tables.\n";
