<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
session_start();
$_SESSION['qms_role'] = 'super_admin';
$_SESSION['qms_user_id'] = 99910;
require 'config/database.php';
require 'includes/delivery-performance-functions.php';
$checks = 0;
function dnCheck(bool $ok, string $name): void { global $checks; if (!$ok) throw new RuntimeException('FAIL: ' . $name); $checks++; echo 'PASS: ' . $name . PHP_EOL; }

foreach (['companies', 'users', 'company_admin_assignments', 'delivery_performance', 'nonconformities'] as $t) {
    $s = $pdo->query("SHOW CREATE TABLE $t")->fetch(PDO::FETCH_NUM)[1];
    $s = preg_replace('/(,\n)?\s*CONSTRAINT[^\n]+/', '', $s);
    $pdo->exec(str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $s));
}
$pdo->exec("INSERT INTO companies(id,company_name,active) VALUES(99910,'DN A',1),(99911,'DN B',1)");
$pdo->exec("INSERT INTO users(id,username,password_hash,full_name,role,company_id,active) VALUES(99910,'dn','x','DN','super_admin',NULL,1),(99911,'dnadm','x','DN A','system_admin',99910,1)");
// Red miktari pozitif teslimat kayitlari.
$idReject = qmsDeliveryAdd($pdo, ['company_id' => 99910, 'customer_name' => 'ACME', 'period' => '2026-09', 'orders_total' => 100, 'on_time_orders' => 90, 'quantity_delivered' => 1000, 'quantity_rejected' => 25, 'notes' => ''], 99910, 'super_admin');
$idOk = qmsDeliveryAdd($pdo, ['company_id' => 99910, 'customer_name' => 'BETA', 'period' => '2026-09', 'orders_total' => 100, 'on_time_orders' => 100, 'quantity_delivered' => 500, 'quantity_rejected' => 0, 'notes' => ''], 99910, 'super_admin');

dnCheck($idReject !== null && $idOk !== null, 'Delivery records created');
$nc = qmsDeliveryCreateNonconformity($pdo, (int) $idReject, 99910, 'super_admin');
dnCheck($nc !== null && $nc > 0, 'NC created from rejected delivery');
$row = $pdo->query("SELECT * FROM nonconformities WHERE id = " . (int) $nc)->fetch(PDO::FETCH_ASSOC);
dnCheck($row !== false && $row['source'] === 'delivery', 'NC source = delivery');
dnCheck((int) $row['delivery_id'] === (int) $idReject, 'NC delivery_id links to record');
dnCheck($row['audit_id'] === null, 'NC audit_id NULL');
dnCheck(qmsDeliveryLinkedNonconformity($pdo, (int) $idReject) === (int) $nc, 'Linked NC found');
dnCheck(qmsDeliveryCreateNonconformity($pdo, (int) $idReject, 99910, 'super_admin') === (int) $nc, 'Create is idempotent (same NC)');
// Red miktari 0 olan kayittan NC olusturulmaz.
$ncOk = qmsDeliveryCreateNonconformity($pdo, (int) $idOk, 99910, 'super_admin');
dnCheck($ncOk === null && qmsDeliveryLinkedNonconformity($pdo, (int) $idOk) === 0, 'No NC from zero-reject delivery');
// Kapsam: atanmamis admin (99911) baska sirket kaydini goremez.
$pdo->exec("INSERT INTO company_admin_assignments(company_id,admin_user_id,active) VALUES(99910,99911,1)");
$ncCross = qmsDeliveryCreateNonconformity($pdo, (int) $idReject, 99911, 'system_admin');
dnCheck($ncCross === (int) $nc, 'Assigned admin to company 99910 reaches its NC (idempotent)');
$pdo->exec("DELETE FROM company_admin_assignments WHERE 1");
$pdo->exec("INSERT INTO company_admin_assignments(company_id,admin_user_id,active) VALUES(99911,99911,1)");
$ncCross2 = qmsDeliveryCreateNonconformity($pdo, (int) $idReject, 99911, 'system_admin');
dnCheck($ncCross2 === null, 'Admin not assigned to 99910 cannot create NC');

echo "\nCompleted $checks delivery-nonconformity checks using temporary tables.\n";
