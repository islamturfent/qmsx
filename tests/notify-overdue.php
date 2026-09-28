<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
session_start();
require 'config/database.php';
require 'includes/notifications.php';
$checks = 0;
function nocCheck(bool $ok, string $name): void { global $checks; if (!$ok) throw new RuntimeException('FAIL: ' . $name); $checks++; echo 'PASS: ' . $name . PHP_EOL; }

$tables = ['companies','users','company_admin_assignments','nonconformities','corrective_actions','trainings','equipment','external_audits','external_audit_findings','documents','complaints','suppliers','supplier_evaluation_schedule','contracts','processes','instruments','delivery_performance','notifications','staff_members','staff_competencies'];
foreach ($tables as $t) {
    $s = $pdo->query("SHOW CREATE TABLE $t")->fetch(PDO::FETCH_NUM)[1];
    $s = preg_replace('/(,\n)?\s*CONSTRAINT[^\n]+/', '', $s);
    $pdo->exec(str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $s));
}
$pdo->exec("INSERT INTO companies(id,company_name,active) VALUES(99941,'NO A',1)");
$pdo->exec("INSERT INTO users(id,username,password_hash,full_name,role,company_id,active) VALUES(99941,'no','x','NO','system_admin',NULL,1)");
$pdo->exec("INSERT INTO company_admin_assignments(company_id,admin_user_id,active) VALUES(99941,99941,1)");
// Bu kullanici hem sorumlu hem admin; geciken duzeltici faaliyet sorumlu kullaniciya gider.
$pdo->exec("INSERT INTO nonconformities(id,company_id,audit_id,source,title,severity,status,due_date,active) VALUES (99941,99941,0,'audit','NC','major','open','2099-01-01',1)");
$pdo->exec("INSERT INTO corrective_actions(id,nonconformity_id,action_type,action_text,responsible_user_id,due_date,status,active) VALUES (99941,99941,'corrective','Late Fix',99941,'2020-01-01','in_progress',1)");
$pdo->exec("INSERT INTO suppliers(id,company_id,name,status,active) VALUES (99941,99941,'Sup A','approved',1)");
$pdo->exec("INSERT INTO supplier_evaluation_schedule(id,supplier_id,company_id,cycle_label,due_date,status,active) VALUES (99941,99941,99941,'Q1 2026','2020-01-01','planned',1)");
// Suresi yaklasan aktif sozlesme + gozden gecirilmesi gecen proses.
$pdo->exec("INSERT INTO contracts(id,company_id,contract_name,contract_type,start_date,end_date,renewal_date,status,active) VALUES "
    . "(99941,99941,'Bakım Soz','supplier','2020-01-01','" . date('Y-m-d', strtotime('+30 days')) . "','2020-01-01','active',1)");
$pdo->exec("INSERT INTO processes(id,company_id,process_name,status,review_date,active) VALUES (99941,99941,'Satınalma','active','2020-01-01',1)");
$pdo->exec("INSERT INTO instruments(id,company_id,name,status,next_calibration_date,active) VALUES (99941,99941,'Kumpas','active','2020-01-01',1)");
// Vadesi gecen yetkinlik: personel kullanici ile eslesmiyor -> sirket adminine.
$pdo->exec("INSERT INTO staff_members(id,company_id,first_name,last_name,email,position,active) VALUES (99941,99941,'Yetk','Personel','yetk@x.local','Kalite',1)");
$pdo->exec("INSERT INTO staff_competencies(id,staff_id,competency_name,level,achieved_date,next_assessment_date,active) VALUES (99941,99941,'Kalibrasyon Uzmanligi','3','2021-01-01','2020-01-01',1)");

// Ilk calistirma: bildirim uretilir.
ob_start();
include __DIR__ . '/../scripts/notify-overdue.php';
$out1 = ob_get_clean();
$n = (int) $pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id=99941 AND notification_type='overdue_action'")->fetchColumn();
nocCheck($n === 1, 'First run creates one overdue_action notification');
nocCheck(strpos($out1, 'Overdue notifications generated: 6') !== false, 'First run reports 6 generated (action + supplier + contract + process + instrument + competency)');

// Ikinci calistirma: idempotent, yeni bildirim uretilmez.
ob_start();
include __DIR__ . '/../scripts/notify-overdue.php';
$out2 = ob_get_clean();
$n2 = (int) $pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id=99941 AND notification_type='overdue_action'")->fetchColumn();
nocCheck($n2 === 1, 'Second run does not duplicate the notification (idempotent)');
nocCheck(strpos($out2, 'Overdue notifications generated: 0') !== false, 'Second run reports 0 generated');

// Vadesi gecen tedarikci degerlendirme: admin'e overdue_supplier_eval bildirimi.
$ns = (int) $pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id=99941 AND notification_type='overdue_supplier_eval'")->fetchColumn();
nocCheck($ns >= 1, 'Overdue supplier schedule notifies admin (overdue_supplier_eval)');
$noCount = static function (PDO $pdo, int $userId, string $type): int {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND notification_type = ?');
    $stmt->execute([$userId, $type]);
    return (int) $stmt->fetchColumn();
};
nocCheck($noCount($pdo, 99941, 'contract_expiring') >= 1, 'Expiring contract notifies admin (contract_expiring)');
nocCheck($noCount($pdo, 99941, 'process_review_overdue') >= 1, 'Overdue process review notifies admin (process_review_overdue)');
nocCheck($noCount($pdo, 99941, 'instrument_calibration_overdue') >= 1, 'Overdue instrument calibration notifies admin (instrument_calibration_overdue)');
nocCheck($noCount($pdo, 99941, 'overdue_competency') >= 1, 'Overdue competency assessment notifies admin (overdue_competency)');

// Teslimat red esigi: yuksek red oranli kayit eklenir ve --threshold ile
// delivery_rejection bildirimi uretilir; dusuk oranli kayit esigi asmaz.
$pdo->exec("INSERT INTO delivery_performance(id,company_id,customer_name,period,orders_total,on_time_orders,quantity_delivered,quantity_rejected,notes,active) VALUES (99941,99941,'ACME','2026-10',100,80,1000,250,NULL,1),(99942,99941,'BETA','2026-10',100,99,1000,5,NULL,1)");
$argv = ['notify-overdue.php', '--threshold=0.05'];
ob_start();
include __DIR__ . '/../scripts/notify-overdue.php';
$out3 = ob_get_clean();
nocCheck(strpos($out3, 'Overdue notifications generated: 1') !== false, 'Delivery threshold run generates exactly 1 new notification');
nocCheck($noCount($pdo, 99941, 'delivery_rejection') >= 1, 'High reject-rate delivery notifies admin (delivery_rejection)');
$nHigh = (int) $pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id=99941 AND notification_type='delivery_rejection' AND link_url LIKE '%2026-10%'")->fetchColumn();
nocCheck($nHigh >= 1, 'Only the high reject delivery (ACME) is notified');

// Esik %50'ye cikarilirsa ACME %25 oranli kayit esigi asmaz -> yeni bildirim yok.
$argv = ['notify-overdue.php', '--threshold=0.50'];
ob_start();
include __DIR__ . '/../scripts/notify-overdue.php';
$out4 = ob_get_clean();
nocCheck(strpos($out4, 'Overdue notifications generated: 0') !== false, 'Higher threshold generates no new delivery notification');

// delivery_rejection turu dogru gruba bagli (eposta tercihi 'delivery' grubu).
$meta = qmsNotificationTypes()['delivery_rejection'];
nocCheck(($meta['group'] ?? '') === 'delivery' && $meta['icon'] === 'truck', 'delivery_rejection maps to delivery group with truck icon');
nocCheck(isset(qmsNotificationGroupLabels()['delivery']), 'delivery notification group label exists');

echo "\nCompleted $checks notify-overdue checks using temporary tables.\n";
