<?php
// Run each case in a fresh process: php tests/document-workflow.php CASE
// Without a CASE argument this file acts as the runner and executes every case.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));

$cases = ['save', 'csrf', 'stale-publish', 'draft-publish', 'request', 'approve', 'publish'];

if (!isset($argv[1])) {
    // Her vaka gercek bir sayfayi yukleyip baslik gonderip exit ettigi icin
    // ayri bir surecte kosmasi zorunlu; burada hepsini toplayip raporluyoruz.
    if (!function_exists('exec')) {
        fwrite(STDERR, 'exec() kapali; vakalari tek tek calistirin: php tests/document-workflow.php CASE' . PHP_EOL);
        exit(2);
    }

    $failed = [];
    foreach ($cases as $caseName) {
        $output = [];
        $exitCode = 0;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' . escapeshellarg($caseName), $output, $exitCode);

        $detail = '';
        foreach ($output as $line) {
            if (preg_match('/^(PASS|FAIL):/', trim($line))) { $detail = trim($line); break; }
        }
        if ($detail === '') { $detail = trim(implode(' | ', $output)); }
        if ($exitCode !== 0) { $failed[] = $caseName; }

        echo ($exitCode === 0 ? 'PASS: ' : 'FAIL: ') . $caseName . ($exitCode === 0 ? '' : ' -> ' . $detail) . PHP_EOL;
    }

    echo PHP_EOL . 'Completed ' . count($cases) . ' workflow checks; failures: ' . count($failed) . PHP_EOL;
    exit($failed ? 1 : 0);
}

session_start();
require 'config/database.php';
foreach (['documents', 'document_versions', 'document_approvals', 'companies', 'company_admin_assignments', 'users', 'notifications', 'audit_log'] as $table) {
    $schema = $pdo->query("SHOW CREATE TABLE $table")->fetch(PDO::FETCH_NUM)[1];
    $schema = preg_replace('/(,\n)?\s*CONSTRAINT[^\n]+/', '', $schema);
    $pdo->exec(str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $schema));
}
$case = $argv[1];
if (!in_array($case, $cases, true)) exit(2);
$initialStatus = match ($case) { 'save', 'csrf' => 'published', 'stale-publish', 'publish' => 'approved', 'approve' => 'review', default => 'draft' };
$expectedStatus = match ($case) { 'save' => 'draft', 'request' => 'review', 'approve' => 'approved', 'publish' => 'published', default => $initialStatus };
$pdo->exec("INSERT INTO companies (id, company_name) VALUES (900001, 'Test')");
$pdo->exec("INSERT INTO users (id, username, password_hash, full_name, role, active) VALUES (900001, 'test', 'unused', 'Test User', 'super_admin', 1)");
$pdo->exec("INSERT INTO documents (id, company_id, document_code, title, status) VALUES (900001, 900001, 'TEST', 'Test', '$initialStatus')");
$pdo->exec("INSERT INTO document_versions (id, document_id, revision_number, original_file_name, stored_file_name, mime_type, file_size) VALUES (900001, 900001, '01', 'old.pdf', 'not-a-real-file.pdf', 'application/pdf', 0)");
if ($case === 'approve') $pdo->exec("INSERT INTO document_approvals (id, document_id, approver_user_id, decision) VALUES (900001, 900001, 900001, 'pending')");
$_SESSION = ['qms_logged_in' => true, 'qms_user_id' => 900001, 'qms_role' => 'super_admin', 'document_csrf' => 'test-token'];
$_GET = ['id' => 900001];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['csrf' => $case === 'csrf' ? 'wrong' : 'test-token', 'base_version' => $case === 'stale-publish' ? 0 : 900001,
    'revision_number' => '02', 'content' => '<p>Yeni içerik</p>', 'change_note' => 'Test',
    'form_type' => match ($case) { 'request' => 'request_approval', 'approve' => 'decide_approval', default => 'publish_document' },
    'approver_user_id' => 900001, 'approval_id' => 900001, 'decision' => 'approved'];
ob_start();
register_shutdown_function(function () use ($pdo, $case, $expectedStatus) {
    while (ob_get_level()) ob_end_clean();
    $status = $pdo->query('SELECT status FROM documents WHERE id = 900001')->fetchColumn();
    $count = (int) $pdo->query('SELECT COUNT(*) FROM document_versions')->fetchColumn();
    $ok = $status === $expectedStatus && $count === ($case === 'save' ? 2 : 1);
    if ($case === 'csrf') $ok = $ok && http_response_code() === 403;
    if ($case === 'stale-publish') $ok = $ok && http_response_code() === 409;
    if ($case === 'request') $ok = $ok && $pdo->query("SELECT COUNT(*) FROM document_approvals WHERE decision = 'pending'")->fetchColumn() == 1;
    foreach ($pdo->query("SELECT stored_file_name FROM document_versions WHERE mime_type = 'text/html'") as $row) {
        $path = 'storage/documents/' . basename($row['stored_file_name']);
        if (is_file($path)) unlink($path);
    }
    session_destroy();
    echo ($ok ? 'PASS: ' : 'FAIL: ') . $case . PHP_EOL;
    if (!$ok) exit(1);
});
require in_array($case, ['save', 'csrf'], true) ? 'document-edit.php' : 'document-detail.php';
