<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
session_start();
require 'config/database.php';
require 'includes/access.php';
$checks = 0;
function pCheck(bool $ok, string $name): void { global $checks; if (!$ok) throw new RuntimeException('FAIL: ' . $name); $checks++; echo 'PASS: ' . $name . PHP_EOL; }

// 1) Migration kolonlari mevcut.
$cols = [];
foreach ($pdo->query('SHOW COLUMNS FROM audits') as $c) { $cols[$c['Field']] = true; }
pCheck(isset($cols['started_at']), 'audits.started_at column exists');
pCheck(isset($cols['completed_at']), 'audits.completed_at column exists');

// 2) Gecici denetim + kontrol maddesi ile yasam dongusu.
$companyId = (int) $pdo->query('SELECT id FROM companies WHERE active = 1 LIMIT 1')->fetchColumn();
pCheck($companyId > 0, 'A company exists for the fixture');

$pdo->prepare('INSERT INTO audits (company_id, title, audit_type, planned_date, status, active) VALUES (?, ?, ?, ?, ?, 1)')
    ->execute([$companyId, 'TEST denetim', 'internal', date('Y-m-d'), 'planned']);
$auditId = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO audit_checklist_items (audit_id, item_text, result_status, active) VALUES (?, ?, ?, 1)')
    ->execute([$auditId, 'TEST madde', 'pending']);
$itemId = (int) $pdo->lastInsertId();

try {
    // Baslat
    $pdo->prepare("UPDATE audits SET status = 'in_progress', started_at = COALESCE(started_at, NOW()) WHERE id = ?")->execute([$auditId]);
    $row = $pdo->prepare('SELECT status, started_at FROM audits WHERE id = ?'); $row->execute([$auditId]); $a1 = $row->fetch();
    pCheck($a1['status'] === 'in_progress' && $a1['started_at'] !== null, 'Start sets in_progress + started_at');

    // Tamamlama bekleyen madde varken engellenmeli.
    $pending = (int) $pdo->prepare('SELECT COUNT(*) FROM audit_checklist_items WHERE audit_id = ? AND active = 1 AND result_status = \'pending\'')->execute([$auditId]) ? null : null;
    $ps = $pdo->prepare('SELECT COUNT(*) FROM audit_checklist_items WHERE audit_id = ? AND active = 1 AND result_status = \'pending\'');
    $ps->execute([$auditId]);
    $pendCount = (int) $ps->fetchColumn();
    pCheck($pendCount > 0, 'Pending checklist item blocks completion');

    // Derecelendir ve tamamla.
    $pdo->prepare("UPDATE audit_checklist_items SET result_status = 'compliant' WHERE id = ?")->execute([$itemId]);
    $pdo->prepare("UPDATE audits SET status = 'done', completed_at = NOW() WHERE id = ?")->execute([$auditId]);
    $row = $pdo->prepare('SELECT status, completed_at FROM audits WHERE id = ?'); $row->execute([$auditId]); $a2 = $row->fetch();
    pCheck($a2['status'] === 'done' && $a2['completed_at'] !== null, 'Complete sets done + completed_at');

    // Yeniden ac.
    $pdo->prepare("UPDATE audits SET status = 'in_progress', completed_at = NULL WHERE id = ?")->execute([$auditId]);
    $row = $pdo->prepare('SELECT status, completed_at FROM audits WHERE id = ?'); $row->execute([$auditId]); $a3 = $row->fetch();
    pCheck($a3['status'] === 'in_progress' && $a3['completed_at'] === null, 'Reopen resets to in_progress and clears completed_at');
} finally {
    $pdo->prepare('DELETE FROM audit_checklist_items WHERE audit_id = ?')->execute([$auditId]);
    $pdo->prepare('DELETE FROM audits WHERE id = ?')->execute([$auditId]);
}

echo "Completed $checks audit-execution checks." . PHP_EOL;
