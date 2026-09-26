<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
session_start();
require 'config/database.php';
require 'includes/announcement-functions.php';
require 'includes/internal-survey-functions.php';
require 'includes/improvement-functions.php';
require 'includes/incident-functions.php';
$checks = 0;
function nmCheck(bool $ok, string $name): void { global $checks; if (!$ok) throw new RuntimeException('FAIL: ' . $name); $checks++; echo 'PASS: ' . $name . PHP_EOL; }

$tables = ['companies','users','notifications','notification_preferences','company_admin_assignments'];
foreach ($tables as $t) {
    $s = $pdo->query("SHOW CREATE TABLE $t")->fetch(PDO::FETCH_NUM)[1];
    $s = preg_replace('/(,\n)?\s*CONSTRAINT[^\n]+/', '', $s);
    $pdo->exec(str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $s));
}
$pdo->exec("INSERT INTO companies(id,company_name,active) VALUES(99951,'NM A',1)");
$pdo->exec("INSERT INTO users(id,username,password_hash,full_name,role,company_id,active) VALUES
    (99951,'nm-co','x','Şirket','company_user',99951,1),
    (99952,'nm-sa','x','SA','super_admin',NULL,1),
    (99953,'nm-ad','x','Admin','system_admin',NULL,1)");
$pdo->exec("INSERT INTO company_admin_assignments(company_id,admin_user_id,active) VALUES(99951,99953,1)");

$countFor = static function (PDO $pdo, int $userId, string $type): int {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND notification_type = ?');
    $stmt->execute([$userId, $type]);
    return (int) $stmt->fetchColumn();
};

// Duyuru bildirimi: sirket kullanicisi ve super admin bilgilendirilir.
qmsAnnouncementNotifyCompany($pdo, 99951, 9001, 'Bakım Duyurusu');
nmCheck($countFor($pdo, 99951, 'announcement_published') === 1, 'Announcement notify reaches company user');
nmCheck($countFor($pdo, 99952, 'announcement_published') === 1, 'Announcement notify reaches super admin');

// Anket bildirimi: sirket kullanicisi doldurmaya davet edilir.
qmsInternalSurveyNotifyCompany($pdo, 99951, 9002, 'Memnuniyet 2026');
nmCheck($countFor($pdo, 99951, 'internal_survey_published') === 1, 'Survey notify reaches company user');
nmCheck($countFor($pdo, 99952, 'internal_survey_published') === 1, 'Survey notify reaches super admin');

// Iyilestirme bildirimleri sirket adminine gider.
qmsImprovementNotify($pdo, 99951, 'improvement_submitted', 'Yeni öneri: X', 'improvements.php');
qmsImprovementNotify($pdo, 99951, 'improvement_implemented', 'Uygulandı: X', 'improvements.php');
nmCheck($countFor($pdo, 99953, 'improvement_submitted') === 1, 'Improvement submitted notifies company admin');
nmCheck($countFor($pdo, 99953, 'improvement_implemented') === 1, 'Improvement implemented notifies company admin');

// Yeni olay bildirimi sirket adminine gider.
qmsIncidentNotify($pdo, 99951, 'Ramak kala', 'critical', 'incidents.php');
nmCheck($countFor($pdo, 99953, 'incident_reported') === 1, 'Incident reported notifies company admin');

echo "\nCompleted $checks notify-modules checks using temporary tables.\n";
