<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
session_start();
require 'config/database.php';
require 'includes/review-functions.php';
$checks = 0;
function reviewCheck(bool $ok, string $name): void { global $checks; if (!$ok) throw new RuntimeException('FAIL: ' . $name); $checks++; echo 'PASS: ' . $name . PHP_EOL; }
function reviewCount(PDO $pdo): int { return (int) $pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn(); }

// Gercek tablolarin semasi gecici tablolara kopyalanir: kiraci izolasyonu ve
// bildirim kurallari gercek veriye dokunmadan sinanir.
foreach (['companies', 'users', 'company_admin_assignments', 'audits', 'nonconformities', 'management_reviews', 'management_review_items', 'notifications'] as $table) {
    $schema = $pdo->query("SHOW CREATE TABLE $table")->fetch(PDO::FETCH_NUM)[1];
    $schema = preg_replace('/(,\n)?\s*CONSTRAINT[^\n]+/', '', $schema);
    $pdo->exec(str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $schema));
}

$pdo->exec("INSERT INTO companies(id, company_name, active) VALUES(970001,'Review Tenant A',1),(970002,'Review Tenant B',1)");
$pdo->exec("INSERT INTO users(id, username, password_hash, full_name, role, company_id, active) VALUES
    (970001,'review-admin','x','Review Admin','system_admin',NULL,1),
    (970002,'review-root','x','Review Root','super_admin',NULL,1),
    (970003,'review-user','x','Review Company User','company_user',970001,1),
    (970004,'review-other','x','Review Other Admin','system_admin',NULL,1),
    (970005,'review-passive','x','Review Passive Admin','system_admin',NULL,0)");
$pdo->exec("INSERT INTO company_admin_assignments(company_id, admin_user_id, active) VALUES(970001, 970001, 1),(970002, 970004, 1),(970001, 970005, 1)");
$pdo->exec("INSERT INTO audits(id, company_id, title, status, active) VALUES(970001, 970001, 'Review audit A', 'planned', 1)");
$pdo->exec("INSERT INTO nonconformities(id, company_id, audit_id, title, severity, status, active) VALUES
    (970001, 970001, 970001, 'Review NC A', 'major', 'open', 1),
    (970002, 970001, 970001, 'Review NC passive', 'minor', 'closed', 0)");
$pdo->exec("INSERT INTO management_reviews(id, company_id, title, review_date, period_start, period_end, status, active) VALUES
    (970001, 970001, 'Review A', '2026-01-15', '2025-01-01', '2025-12-31', 'planned', 1),
    (970002, 970001, 'Review passive', '2026-02-15', '2025-01-01', '2025-12-31', 'planned', 0),
    (970003, 970002, 'Review B', '2026-03-01', '2025-01-01', '2025-12-31', 'completed', 1)");
$pdo->exec("INSERT INTO management_review_items(id, review_id, item_type, topic, responsible_user_id, nonconformity_id) VALUES
    (970001, 970001, 'input', 'Girdi A', NULL, NULL),
    (970002, 970001, 'action', 'Aksiyon A', 970003, 970001),
    (970003, 970003, 'decision', 'Karar B', NULL, NULL)");

// ---- Alan dogrulama yardimcilari.
reviewCheck(qmsReviewDate('2026-01-15') === '2026-01-15' && qmsReviewDate('15.01.2026') === null, 'Dates require ISO format');
reviewCheck(qmsReviewText('  Fazla bosluk  ', 100) === 'Fazla bosluk' && mb_strlen(qmsReviewText(str_repeat('a', 300), 255)) === 255, 'Text is trimmed and truncated');

// ---- Kapsam: id degistirilerek baska kiraciya gecilemez.
reviewCheck((int) qmsReviewFind($pdo, 970001, 970001, 'system_admin')['company_id'] === 970001, 'Assigned admin reads own tenant review');
reviewCheck(qmsReviewFind($pdo, 970003, 970001, 'system_admin') === [], 'Assigned admin cannot read another tenant review');
reviewCheck((int) qmsReviewFind($pdo, 970001, 970003, 'company_user')['id'] === 970001, 'Company user reads own company review');
reviewCheck(qmsReviewFind($pdo, 970003, 970003, 'company_user') === [], 'Company user cannot read another company review');
reviewCheck((int) qmsReviewFind($pdo, 970003, 970002, 'super_admin')['id'] === 970003, 'Super admin reads any review');
reviewCheck(qmsReviewFind($pdo, 970002, 970002, 'super_admin') === [], 'Inactive review is not readable');

// ---- Liste.
$list = qmsReviewList($pdo, 970001, 'system_admin');
reviewCheck(count($list) === 1 && (int) $list[0]['id'] === 970001, 'Review list is limited to the visible tenant');
reviewCheck((int) $list[0]['item_count'] === 2 && (int) $list[0]['action_count'] === 1, 'Review list counts items and actions');
reviewCheck(count(qmsReviewList($pdo, 970002, 'super_admin')) === 2, 'Super admin list sees every active review');
$pdo->exec('UPDATE company_admin_assignments SET active = 0');
reviewCheck(qmsReviewList($pdo, 970001, 'system_admin') === [], 'Revoked assignment removes access');
$pdo->exec('UPDATE company_admin_assignments SET active = 1 WHERE company_id = 970001');

// ---- Kalemler.
$items = qmsReviewItems($pdo, 970001);
reviewCheck(count($items) === 2, 'Items are limited to one review');
reviewCheck((string) $items[0]['responsible_full_name'] === 'Review Company User' || (string) $items[1]['responsible_full_name'] === 'Review Company User', 'Action item joins the responsible user');
$actionItem = null;
foreach ($items as $item) {
    if ($item['item_type'] === 'action') {
        $actionItem = $item;
    }
}
reviewCheck($actionItem !== null && (string) $actionItem['nonconformity_title'] === 'Review NC A', 'Action item joins the linked nonconformity');

// Kalem kapsami: baska kiraci veya baska gozden gecirme okunamaz.
reviewCheck((int) qmsReviewItemFind($pdo, 970001, 970001, 'system_admin')['review_id'] === 970001, 'Assigned admin reads own item');
reviewCheck(qmsReviewItemFind($pdo, 970003, 970001, 'system_admin') === [], 'Item of another tenant is not readable');
reviewCheck(qmsReviewItemFind($pdo, 970001, 970004, 'system_admin') === [], 'Item is hidden from an admin of another tenant');

// ---- Bildirim kurallari: tamamlama yone gider.
$statusContext = [
    'company_id' => 970001,
    'title' => 'Review A',
    'link' => 'review-detail.php?id=970001',
    'previous_status' => 'planned',
    'new_status' => 'completed',
    'actor_user_id' => 970003
];
reviewCheck(qmsReviewNotifyStatusChange($pdo, $statusContext) === 1, 'Completing a review notifies the assigned admin once');
reviewCheck((int) $pdo->query("SELECT COUNT(*) FROM notifications WHERE notification_type = 'review_completed' AND user_id = 970001")->fetchColumn() === 1, 'Completion notification type is stored');
reviewCheck((int) $pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id = 970004")->fetchColumn() === 0, 'Admin of another tenant is not notified');
reviewCheck((int) $pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id = 970005")->fetchColumn() === 0, 'Passive admin is not notified');
reviewCheck((int) $pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id = 970002")->fetchColumn() === 0, 'Super admin is not notified about tenant records');

$pdo->exec('DELETE FROM notifications');
reviewCheck(qmsReviewNotifyStatusChange($pdo, array_merge($statusContext, ['actor_user_id' => 970001])) === 0, 'The acting admin is not notified by their own completion');
$pdo->exec('DELETE FROM notifications');
qmsReviewNotifyStatusChange($pdo, array_merge($statusContext, ['previous_status' => 'completed']));
reviewCheck(reviewCount($pdo) === 0, 'Re-saving an already-completed review sends no notification');
qmsReviewNotifyStatusChange($pdo, array_merge($statusContext, ['new_status' => 'planned']));
reviewCheck(reviewCount($pdo) === 0, 'Returning to planned sends no notification');

// ---- Sozlukler ve sabitler.
reviewCheck(array_keys(qmsReviewStatusLabels()) === QMS_REVIEW_STATUSES, 'Status labels match the documented flow');
reviewCheck(array_keys(qmsReviewItemTypeLabels()) === QMS_REVIEW_ITEM_TYPES, 'Item type labels match the documented list');
reviewCheck(
    count(qmsReviewStatusI18nKeys()) === count(QMS_REVIEW_STATUSES)
        && count(qmsReviewItemTypeI18nKeys()) === count(QMS_REVIEW_ITEM_TYPES),
    'Every status and item type has an i18n key'
);

// ---- Bildirim arayuz haritasi.
reviewCheck(qmsNotificationMeta('review_completed')['group'] === 'review', 'Review type maps to the review group');
reviewCheck(isset(qmsNotificationGroupLabels()['review'], qmsNotificationGroupI18nKeys()['review']), 'The review group has a label and an i18n key');

reviewCheck((int) $pdo->query('SELECT COUNT(*) FROM management_reviews')->fetchColumn() === 3, 'Temporary table holds only the fixtures');
reviewCheck((int) $pdo->query('SELECT COUNT(*) FROM management_review_items')->fetchColumn() === 3, 'Item table holds only the fixtures');

session_destroy();
echo "Completed $checks review checks using temporary tables." . PHP_EOL;
