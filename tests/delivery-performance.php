<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
session_start();
require 'config/database.php';
require 'includes/delivery-performance-functions.php';
$checks = 0;
function dpCheck(bool $ok, string $name): void { global $checks; if (!$ok) throw new RuntimeException('FAIL: ' . $name); $checks++; echo 'PASS: ' . $name . PHP_EOL; }

$tables = ['companies','users','company_admin_assignments','delivery_performance'];
foreach ($tables as $t) {
    $s = $pdo->query("SHOW CREATE TABLE $t")->fetch(PDO::FETCH_NUM)[1];
    $s = preg_replace('/(,\n)?\s*CONSTRAINT[^\n]+/', '', $s);
    $pdo->exec(str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $s));
}
$pdo->exec("INSERT INTO companies(id,company_name,active) VALUES(99910,'DP A',1),(99911,'DP B',1)");
$pdo->exec("INSERT INTO users(id,username,password_hash,full_name,role,company_id,active) VALUES(99910,'dp','x','DP','super_admin',NULL,1)");

$id = qmsDeliveryAdd($pdo, ['company_id'=>99910,'customer_name'=>'ACME','period'=>'2026-09','orders_total'=>100,'on_time_orders'=>95,'quantity_delivered'=>1000,'quantity_rejected'=>20,'notes'=>''], 99910, 'super_admin');
dpCheck($id !== null && $id > 0, 'Delivery record added');
dpCheck(count(qmsDeliveryList($pdo, 99910, 'super_admin')) === 1, 'Delivery record listed');
dpCheck(qmsDeliveryFind($pdo, (int)$id, 99910, 'super_admin')['customer_name'] === 'ACME', 'Delivery fetched by id');

// Zamaninda teslim orani: 95/100 = %95.
$row = qmsDeliveryFind($pdo, (int)$id, 99910, 'super_admin');
dpCheck(qmsDeliveryOnTimeRate($row) === 95.0, 'On-time rate computed = 95%');

// Dogrulama redleri.
dpCheck(qmsDeliveryAdd($pdo, ['company_id'=>0,'customer_name'=>'x','period'=>'2026-09','orders_total'=>1,'on_time_orders'=>0,'quantity_delivered'=>0,'quantity_rejected'=>0], 99910, 'super_admin') === null, 'Blank company rejected');
dpCheck(qmsDeliveryAdd($pdo, ['company_id'=>99911,'customer_name'=>'y','period'=>'2026-09','orders_total'=>1,'on_time_orders'=>0,'quantity_delivered'=>0,'quantity_rejected'=>0], 99910, 'system_admin') === null, 'Cross-company rejected');
dpCheck(qmsDeliveryAdd($pdo, ['company_id'=>99910,'customer_name'=>'','period'=>'2026-09','orders_total'=>1,'on_time_orders'=>0,'quantity_delivered'=>0,'quantity_rejected'=>0], 99910, 'super_admin') === null, 'Blank customer rejected');
dpCheck(qmsDeliveryAdd($pdo, ['company_id'=>99910,'customer_name'=>'z','period'=>'bad','orders_total'=>1,'on_time_orders'=>0,'quantity_delivered'=>0,'quantity_rejected'=>0], 99910, 'super_admin') === null, 'Invalid period rejected');

// Dönem filtresi.
dpCheck(count(qmsDeliveryList($pdo, 99910, 'super_admin', '2026-09')) === 1, 'Period filter matches');
dpCheck(count(qmsDeliveryList($pdo, 99910, 'super_admin', '2026-08')) === 0, 'Other period filter empty');

dpCheck(qmsDeliveryUpdate($pdo, (int)$id, ['customer_name'=>'ACME v2','period'=>'2026-09','orders_total'=>200,'on_time_orders'=>180,'quantity_delivered'=>2000,'quantity_rejected'=>10,'notes'=>''], 99910, 'super_admin') === true, 'Delivery record updated');
dpCheck(qmsDeliveryOnTimeRate(qmsDeliveryFind($pdo, (int)$id, 99910, 'super_admin')) === 90.0, 'On-time rate after update = 90%');

// Kapsam: atanan admin kendi sirketini gorur.
$pdo->exec("INSERT INTO company_admin_assignments(company_id,admin_user_id,active) VALUES(99910,99910,1)");
dpCheck(count(qmsDeliveryList($pdo, 99910, 'system_admin')) === 1, 'Assigned admin sees own records');
dpCheck(qmsDeliveryDelete($pdo, (int)$id, 99910, 'system_admin') === true, 'Delivery record deletable in scope');
dpCheck(count(qmsDeliveryList($pdo, 99910, 'super_admin')) === 0, 'Deleted record hidden');

echo "\nCompleted $checks delivery-performance checks using temporary tables.\n";
