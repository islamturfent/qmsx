<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
session_start();
require 'config/database.php';
require 'includes/document-editor.php';
function check($ok, $label) { if (!$ok) throw new Exception('FAIL: '.$label); echo 'PASS: '.$label.PHP_EOL; }
function rejected($callback, $label) { try { $callback(); } catch (RuntimeException $e) { check(true, $label); return; } throw new Exception('FAIL: '.$label); }
foreach (['documents', 'document_versions', 'document_approvals', 'companies', 'company_admin_assignments'] as $table) {
    $schema = $pdo->query("SHOW CREATE TABLE $table")->fetch(PDO::FETCH_NUM)[1];
$schema = preg_replace('/(,\n)?\s*CONSTRAINT[^\n]+/', '', $schema);
$pdo->exec(str_replace("CREATE TABLE", "CREATE TEMPORARY TABLE", $schema));
}
$pdo->exec("INSERT INTO companies (id, company_name) VALUES (900001, 'Editör Test Şirketi')");
$pdo->exec("INSERT INTO documents (id, company_id, document_code, title, status) VALUES (900001, 900001, 'TEST-WEB', 'Kalite Yönetim Prosedürü', 'draft')");
$paths = [];
try {
    $clean = qmsEditorHtml('<h2 onclick="x()">Türkçe İçerik</h2><script>bad()</script><img src=x onerror=x()><p style="color:red">Güvenli</p>');
    check($clean === '<h2>Türkçe İçerik</h2><p>Güvenli</p>', 'Unsafe markup removed');
    $rich = qmsEditorHtml('<p><a href="https://example.com" target="_blank" onclick="bad()">Bağlantı</a></p><table style="width:100%"><tbody><tr><th scope="col" colspan="2">Başlık</th></tr><tr><td rowspan="2">Değer</td><td>X</td></tr></tbody></table><a href="javascript:alert(1)">Kötü</a>');
    check($rich === '<p><a href="https://example.com" target="_blank" rel="noopener noreferrer">Bağlantı</a></p><table><tbody><tr><th colspan="2" scope="col">Başlık</th></tr><tr><td rowspan="2">Değer</td><td>X</td></tr></tbody></table><a>Kötü</a>', 'TinyMCE links and tables sanitized');
    check($clean === '<h2>Türkçe İçerik</h2><p>Güvenli</p>', 'HTML allowlist and Turkish text');
    qmsSaveEditorRevision($pdo, 900001, 1, true, 0, '01', '<h2>Kalite politikası</h2><p>İlk sürüm</p>', 'İlk kayıt');
    $first = qmsEditorLatest($pdo, 900001);
    $paths[] = 'storage/documents/'.$first['stored_file_name'];
    $original = file_get_contents(end($paths));
    check(qmsEditorHtml($original) === '<h2>Kalite politikası</h2><p>İlk sürüm</p>', 'Saved HTML roundtrip');
    $pdo->exec("UPDATE documents SET status = 'published' WHERE id = 900001");
    qmsSaveEditorRevision($pdo, 900001, 1, true, (int)$first['id'], '02', '<h2>Kalite politikası</h2><p>İkinci sürüm</p>', 'Güncellendi');
    $second = qmsEditorLatest($pdo, 900001);
    $paths[] = 'storage/documents/'.$second['stored_file_name'];
    check(file_get_contents($paths[0]) === $original, 'Previous file unchanged');
    check($pdo->query('SELECT COUNT(*) FROM document_versions')->fetchColumn() == 2, 'Both revisions preserved');
    check(qmsEditorDocument($pdo,900001,1,true)['status'] === 'draft', 'Published becomes draft');
    rejected(fn()=>qmsSaveEditorRevision($pdo,900001,1,true,(int)$first['id'],'03','<p>Stale</p>',''), 'Stale editor rejected');
    rejected(fn()=>qmsSaveEditorRevision($pdo,900001,777,false,(int)$second['id'],'03','<p>Denied</p>',''), 'Unassigned user rejected');
    rejected(fn()=>qmsSaveEditorRevision($pdo,900001,1,true,(int)$second['id'],'02','<p>Duplicate</p>',''), 'Duplicate revision rejected');
    foreach (['review','archived'] as $status) {
        $pdo->exec("UPDATE documents SET status = '$status' WHERE id = 900001");
        rejected(fn()=>qmsSaveEditorRevision($pdo,900001,1,true,(int)$second['id'],'03','<p>Locked</p>',''), $status.' rejected');
    }
    $pdo->exec("UPDATE documents SET status = 'approved' WHERE id = 900001");
    qmsSaveEditorRevision($pdo,900001,1,true,(int)$second['id'],'03','<h2>Kalite politikası</h2><p>Üçüncü sürüm: sürekli iyileştirme.</p>','Onay sonrası düzenleme');
    $third = qmsEditorLatest($pdo,900001);
    $paths[] = 'storage/documents/'.$third['stored_file_name'];
    check(qmsEditorDocument($pdo,900001,1,true)['status'] === 'draft', 'Approved becomes draft');
    rejected(fn()=>qmsSaveEditorRevision($pdo,900001,1,true,(int)$third['id'],'04','<script>x()</script>',''), 'Empty sanitized content rejected');
    if (in_array("--preview", $argv, true)) {
    $_SESSION = ['qms_logged_in'=>true,'qms_user_id'=>1,'qms_role'=>'super_admin'];
    $_GET = ['id'=>900001]; $_SERVER['REQUEST_METHOD'] = 'GET';
    ob_start(); include 'document-edit.php'; $page = ob_get_clean();
    file_put_contents('.qms-editor-preview.html', $page);
    }
    session_destroy();
} finally {
    foreach ($paths as $path) if (is_file($path)) unlink($path);
}
