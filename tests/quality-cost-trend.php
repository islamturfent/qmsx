<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
session_start();
require 'config/database.php';
require 'includes/quality-cost-functions.php';
$checks = 0;
function qctCheck(bool $ok, string $name): void { global $checks; if (!$ok) throw new RuntimeException('FAIL: ' . $name); $checks++; echo 'PASS: ' . $name . PHP_EOL; }

// Gercek tablolarin semasi gecici tablolara kopyalanir; FK'lar cikarilir.
foreach (['companies','users','company_admin_assignments','quality_costs'] as $table) {
    $schema = $pdo->query("SHOW CREATE TABLE $table")->fetch(PDO::FETCH_NUM)[1];
    $schema = preg_replace('/(,\n)?\s*CONSTRAINT[^\n]+/', '', $schema);
    $pdo->exec(str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $schema));
}

$pdo->exec("INSERT INTO companies(id, company_name, active) VALUES(99301,'COQ A',1),(99302,'COQ B',1)");
$pdo->exec("INSERT INTO users(id, username, password_hash, full_name, role, company_id, active) VALUES
    (99301,'coq-admin','x','A','system_admin',NULL,1),
    (99302,'coq-root','x','R','super_admin',NULL,1)");
$pdo->exec("INSERT INTO company_admin_assignments(company_id, admin_user_id, active) VALUES(99301,99301,1)");

$pdo->exec("INSERT INTO quality_costs(id, company_id, cost_type, title, amount, incurred_on, active) VALUES
    (99301,99301,'prevention','e1',1000.00,'2026-05-15',1),
    (99302,99301,'appraisal','e2',500.00,'2026-06-10',1),
    (99303,99301,'internal_failure','e3',300.00,'2026-07-05',1),
    (99304,99301,'external_failure','e4',200.00,'2026-05-20',1),
    (99305,99302,'external_failure','e5',999.00,'2026-05-01',1),
    (99306,99301,'prevention','old',50.00,'2025-12-31',1)");

// ---- Atanan admin sadece kendi sirketini (kapsam) gorur.
$t = qmsQualityCostMonthlyTrend($pdo, 99301, 'system_admin', 2026);
qctCheck(count($t) === 12, 'Trend returns exactly 12 months (keys 1..12)');
qctCheck((float) $t[5]['prevention'] === 1000.0, 'May prevention = 1000');
qctCheck((float) $t[5]['external_failure'] === 200.0, 'May external_failure = 200 (own company only)');
qctCheck((float) $t[6]['appraisal'] === 500.0, 'Jun appraisal = 500');
qctCheck((float) $t[7]['internal_failure'] === 300.0, 'Jul internal_failure = 300');
qctCheck((float) $t[5]['total'] === 1200.0, 'May total = prevention + failure');
qctCheck((float) $t[8]['total'] === 0.0, 'Empty months report zero');
qctCheck((float) $t[1]['total'] === 0.0, 'Previous year record does not leak into 2026');

// ---- Super admin tum sirketleri gorur.
$t2 = qmsQualityCostMonthlyTrend($pdo, 99302, 'super_admin', 2026);
qctCheck((float) $t2[5]['external_failure'] === 1199.0, 'Super admin aggregates company B external (200+999)');
qctCheck((float) $t2[5]['total'] === 2199.0, 'Super admin May total includes both companies');

// ---- Sirket filtresi.
$t3 = qmsQualityCostMonthlyTrend($pdo, 99302, 'super_admin', 2026, 99302);
qctCheck((float) $t3[5]['external_failure'] === 999.0, 'Company filter isolates company B');

// ---- Yillik toplam tutarlilik.
$yearTotals = 0.0;
foreach ($t2 as $val) { $yearTotals += $val['total']; }
qctCheck($yearTotals === 2999.0, 'All-months total equals expected annual sum');

echo "\nCompleted $checks COQ trend checks using temporary tables.\n";
