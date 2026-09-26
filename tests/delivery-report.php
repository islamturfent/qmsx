<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
session_start();
$_SESSION['qms_role'] = 'super_admin';
$_SESSION['qms_user_id'] = 99910;
require 'config/database.php';
require 'includes/report-export-data.php';
require 'includes/dashboard-functions.php';
$checks = 0;
function drCheck(bool $ok, string $name): void { global $checks; if (!$ok) throw new RuntimeException('FAIL: ' . $name); $checks++; echo 'PASS: ' . $name . PHP_EOL; }

foreach (['companies', 'users', 'delivery_performance'] as $t) {
    $s = $pdo->query("SHOW CREATE TABLE $t")->fetch(PDO::FETCH_NUM)[1];
    $s = preg_replace('/(,\n)?\s*CONSTRAINT[^\n]+/', '', $s);
    $pdo->exec(str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $s));
}
$pdo->exec("INSERT INTO companies(id,company_name,active) VALUES(99910,'DR A',1),(99911,'DR B',1)");
$pdo->exec("INSERT INTO users(id,username,password_hash,full_name,role,company_id,active) VALUES(99910,'dr','x','DR','super_admin',NULL,1)");
$pdo->exec("INSERT INTO delivery_performance(id,company_id,customer_name,period,orders_total,on_time_orders,quantity_delivered,quantity_rejected,notes,active) VALUES(1,99910,'ACME','2026-09',100,95,1000,20,NULL,1),(2,99911,'Beta','2026-09',50,40,300,5,NULL,1)");

$report = buildReportExportData($pdo, 99910, true, ['start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
drCheck(isset($report['delivery_list']) && count($report['delivery_list']) === 2, 'Delivery list present with 2 records');
drCheck($report['metrics']['delivery_count'] === 2, 'Delivery count metric = 2');
drCheck($report['metrics']['delivery_ontime_rate'] === 90.0, 'Overall on-time rate = 135/150 = 90.0');
drCheck($report['metrics']['delivery_rejected'] === 25, 'Rejected total = 25');
$acme = null;
foreach ($report['delivery_list'] as $r) { if ($r['customer_name'] === 'ACME') { $acme = $r; break; } }
drCheck($acme !== null && $acme['on_time_rate'] === 95.0, 'Per-record on-time rate ACME = 95');

// Dönem Özeti delivery noktasi (qmsDashboardSummary) - trend bos da olsa
// teslimat toplamlari bagimsiz topli sorgudan okunur.
$summary = qmsDashboardSummary($pdo, 99910, 'super_admin', []);
$found = false;
foreach ($summary['points'] as $p) { if (mb_strpos($p['text'], 'Teslimat performansı') !== false) { $found = true; break; } }
drCheck($found, 'Dönem Özeti delivery point present');

echo "\nCompleted $checks delivery-report checks using temporary tables.\n";
