<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
session_start();
require 'config/database.php';
require 'includes/document-review-functions.php';
$checks = 0;
function drCheck(bool $ok, string $name): void { global $checks; if (!$ok) throw new RuntimeException('FAIL: ' . $name); $checks++; echo 'PASS: ' . $name . PHP_EOL; }

// Gercek tablolarin semasi gecici tablolara kopyalanir; FK'lar cikarilir.
foreach (['companies','users','company_admin_assignments','documents','document_reviews','audit_log'] as $table) {
    $schema = $pdo->query("SHOW CREATE TABLE $table")->fetch(PDO::FETCH_NUM)[1];
    $schema = preg_replace('/(,\n)?\s*CONSTRAINT[^\n]+/', '', $schema);
    $pdo->exec(str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $schema));
}

$pdo->exec("INSERT INTO companies(id, company_name, active) VALUES(97001,'DR A',1),(97002,'DR B',1)");
$pdo->exec("INSERT INTO users(id, username, password_hash, full_name, role, company_id, active) VALUES
    (97001,'dr-admin','x','DR Admin','system_admin',NULL,1),
    (97002,'dr-root','x','DR Root','super_admin',NULL,1),
    (97004,'dr-other','x','DR Other','system_admin',NULL,1)");
$pdo->exec("INSERT INTO company_admin_assignments(company_id, admin_user_id, active) VALUES(97001,97001,1),(97002,97004,1)");
$pdo->exec("INSERT INTO documents(id, company_id, document_code, title, status, current_revision, review_date, active) VALUES
    (97001,97001,'D-01','Overdue doc','published','01','2020-01-01',1),
    (97002,97001,'D-02','Due soon doc','published','02','2030-01-01',1),
    (97003,97001,'D-03','No review doc','published','01',NULL,1),
    (97004,97001,'D-04','Far future doc','published','01','2035-01-01',1),
    (97005,97002,'D-05','Other tenant doc','published','01','2020-01-01',1)");

$today = '2026-06-15';

// ---- Durum turetim.
drCheck(qmsDocumentReviewStatus(null, $today) === 'not_scheduled', 'Null review date is not scheduled');
drCheck(qmsDocumentReviewStatus('2020-01-01', $today) === 'overdue', 'Past review date is overdue');
drCheck(qmsDocumentReviewStatus('2026-06-20', $today) === 'due_soon', 'Date within the window is due soon');
drCheck(qmsDocumentReviewStatus('2035-01-01', $today) === 'on_schedule', 'Far future date is on schedule');

// ---- Kuyruk kapsami ve siralama.
$queue = qmsDocumentReviewQueue($pdo, 97001, 'system_admin');
drCheck(count($queue) === 4, 'Assigned admin queues only own-company documents');
drCheck((int) $queue[0]['id'] === 97001, 'Overdue document is first in the queue');
$ids = array_map('intval', array_column($queue, 'id'));
drCheck(in_array(97002, $ids, true) && in_array(97003, $ids, true), 'Queue includes due-soon and unscheduled docs');
drCheck(!in_array(97005, $ids, true), 'Another tenant document is excluded');
drCheck(count(qmsDocumentReviewQueue($pdo, 97002, 'super_admin')) === 5, 'Super admin queues every tenant');

// ---- Filtre.
drCheck(count(qmsDocumentReviewQueue($pdo, 97001, 'system_admin', 'overdue')) === 1, 'Overdue filter narrows to one');
drCheck(count(qmsDocumentReviewQueue($pdo, 97001, 'system_admin', 'not_scheduled')) === 1, 'Not-scheduled filter narrows to one');

// ---- Gecmis bos.
drCheck(qmsDocumentReviewHistory($pdo, 97001, 'system_admin') === [], 'Review history is empty before any record');

// ---- Gecerli kayit: islem yazilir, dokumanda ilerler.
$reviewId = qmsDocumentReviewRecord($pdo, 97001, [
    'outcome' => 'needs_revision', 'notes' => 'Güncelle', 'reviewed_at' => '2026-06-15',
    'next_review_date' => '2027-06-15', 'document_title' => 'Overdue doc',
], 97001, 'system_admin');
drCheck($reviewId !== null && $reviewId > 0, 'Valid review inserts a record');
$newDate = (string) $pdo->query('SELECT review_date FROM documents WHERE id = 97001')->fetchColumn();
drCheck($newDate === '2027-06-15', 'Document review_date advances to the next date');
drCheck((int) $pdo->query('SELECT COUNT(*) FROM document_reviews WHERE document_id = 97001')->fetchColumn() === 1, 'Review row is stored');
drCheck((int) $pdo->query("SELECT COUNT(*) FROM audit_log WHERE entity_type='document' AND entity_id=97001 AND action='review'")->fetchColumn() === 1, 'Review writes an audit log entry');
$hist = qmsDocumentReviewHistory($pdo, 97001, 'system_admin');
drCheck(count($hist) === 1 && $hist[0]['outcome'] === 'needs_revision', 'History shows the recorded review');

// ---- Dogrulama redleri.
drCheck(qmsDocumentReviewRecord($pdo, 97001, ['outcome' => 'bad', 'notes' => '', 'reviewed_at' => '2026-06-15', 'next_review_date' => '2027-06-15'], 97001, 'system_admin') === null, 'Invalid outcome is rejected');
drCheck(qmsDocumentReviewRecord($pdo, 97001, ['outcome' => 'ok', 'notes' => '', 'reviewed_at' => 'nope', 'next_review_date' => '2027-06-15'], 97001, 'system_admin') === null, 'Invalid dates are rejected');

// ---- Kapsam disi dokuman kaydedilemez.
drCheck(qmsDocumentReviewRecord($pdo, 97005, ['outcome' => 'ok', 'notes' => '', 'reviewed_at' => '2026-06-15', 'next_review_date' => '2027-06-15'], 97001, 'system_admin') === null, 'Other tenant document cannot be reviewed');
drCheck((int) $pdo->query('SELECT COUNT(*) FROM document_reviews')->fetchColumn() === 1, 'Rejected attempts add no review rows');

echo "\nCompleted $checks document-review checks using temporary tables.\n";
