<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
session_start();
require 'config/database.php';
require 'includes/audit-log-functions.php';
$checks = 0;
function alCheck(bool $ok, string $name): void { global $checks; if (!$ok) throw new RuntimeException('FAIL: ' . $name); $checks++; echo 'PASS: ' . $name . PHP_EOL; }

// Etiket haritalari: kullanilan tum tur/islem anahtarlari karsilanmali.
$entityLabels = qmsAuditLogEntityLabels();
$actionLabels = qmsAuditLogActionLabels();
alCheck(isset($entityLabels['audit_report'], $entityLabels['complaint'], $entityLabels['document'], $entityLabels['nonconformity'], $entityLabels['corrective_action'], $entityLabels['management_review'], $entityLabels['supplier'], $entityLabels['training'], $entityLabels['risk']), 'Entity labels cover the instrumented modules');
alCheck(isset($actionLabels['create'], $actionLabels['update'], $actionLabels['status_change'], $actionLabels['finalize'], $actionLabels['publish'], $actionLabels['archive'], $actionLabels['close'], $actionLabels['complete']), 'Action labels cover the recorded actions');

// Gecici audit_log (FK'siz).
$schema = $pdo->query('SHOW CREATE TABLE audit_log')->fetch(PDO::FETCH_NUM)[1];
$schema = preg_replace('/(,\n)?\s*CONSTRAINT[^\n]+/', '', $schema);
$pdo->exec(str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $schema));

$id1 = qmsAuditLog($pdo, 880001, 900001, 'complaint', 7, 'create', 'Şikayet oluşturuldu: A', ['severity' => 'major']);
$id2 = qmsAuditLog($pdo, 880001, 900002, 'document', 3, 'publish', 'Doküman yayımlandı: B');
$id3 = qmsAuditLog($pdo, null, 900003, 'company', 5, 'create', 'Şirket oluşturuldu');

alCheck((int) $pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn() === 3, 'Append writes one row per event');
alCheck($id3 > $id2 && $id2 > $id1, 'Log ids are ordered by insertion');
alCheck((string) $pdo->query("SELECT entity_type FROM audit_log WHERE id = $id1")->fetchColumn() === 'complaint', 'Entity type is stored');
alCheck((string) $pdo->query("SELECT action FROM audit_log WHERE id = $id2")->fetchColumn() === 'publish', 'Action is stored');
alCheck((string) $pdo->query("SELECT summary FROM audit_log WHERE id = $id1")->fetchColumn() === 'Şikayet oluşturuldu: A', 'Summary is stored verbatim');
alCheck((string) $pdo->query("SELECT details FROM audit_log WHERE id = $id1")->fetchColumn() === '{"severity":"major"}', 'Field-level details are stored as JSON');
alCheck($pdo->query("SELECT company_id FROM audit_log WHERE id = $id3")->fetchColumn() === null, 'Company-less (global) events store null company');

// Super admin: kapsam yok, tum kayitlar, en yeni en ustte.
$rows = qmsAuditLogList($pdo, 1, 'super_admin', []);
alCheck(count($rows) === 3, 'Super admin lists every event');
alCheck((int) $rows[0]['id'] === $id3, 'Newest event appears first');

// Filtre: kayit turu ve islem.
$complaints = qmsAuditLogList($pdo, 1, 'super_admin', ['entity_type' => 'complaint']);
alCheck(count($complaints) === 1 && (int) $complaints[0]['id'] === $id1, 'Entity-type filter narrows the list');
$publishes = qmsAuditLogList($pdo, 1, 'super_admin', ['action' => 'publish']);
alCheck(count($publishes) === 1 && (int) $publishes[0]['id'] === $id2, 'Action filter narrows the list');
$none = qmsAuditLogList($pdo, 1, 'super_admin', ['entity_type' => 'risk']);
alCheck(count($none) === 0, 'A filter with no matches returns none');

// Erişimi olmayan rol: hicbir kaydi goremez (1 = 0 kolu).
$isolated = qmsAuditLogList($pdo, 424242, 'company_user', []);
alCheck(count($isolated) === 0, 'A company user with no company sees nothing');

alCheck((int) $pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn() === 3, 'Temporary table holds only the fixtures');

session_destroy();
echo "Completed $checks audit-log checks using temporary tables." . PHP_EOL;
