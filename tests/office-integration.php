<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
require 'config/database.php';
require 'includes/office/service.php';

$checks = 0; $fixtures = [];
function officeCheck(bool $ok, string $name): void { global $checks; if (!$ok) throw new RuntimeException('FAIL: ' . $name); $checks++; echo 'PASS: ' . $name . PHP_EOL; }
function officeReject(callable $fn, int $status, string $name): void {
    try { $fn(); } catch (QmsOfficeError $e) { officeCheck($e->status === $status, $name . ' [' . $e->status . ']'); return; }
    throw new RuntimeException('FAIL: ' . $name);
}
function officeFixture(string $text): string {
    global $fixtures;
    $path = 'storage/documents/' . bin2hex(random_bytes(20)) . '.docx';
    $zip = new ZipArchive(); $zip->open($path, ZipArchive::CREATE);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
    $zip->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>' . $text . '</w:t></w:r></w:p></w:body></w:document>');
    $zip->close(); $fixtures[] = $path; return $path;
}
foreach (['documents', 'document_versions', 'document_approvals', 'companies', 'company_admin_assignments', 'users', 'office_sessions', 'office_locks', 'office_audit'] as $table) {
    $schema = $pdo->query("SHOW CREATE TABLE $table")->fetch(PDO::FETCH_NUM)[1];
    $schema = preg_replace('/(,\n)?\s*CONSTRAINT[^\n]+/', '', $schema);
    $pdo->exec(str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $schema));
}
$pdo->exec("INSERT INTO companies (id, company_name) VALUES (900001, 'Tenant A'), (900002, 'Tenant B')");
$pdo->exec("INSERT INTO users (id, username, password_hash, full_name, role, active) VALUES (900001, 'office-root', 'unused', 'Test Root', 'super_admin', 1), (900002, 'office-admin', 'unused', 'Test Admin', 'system_admin', 1)");
$pdo->exec('INSERT INTO company_admin_assignments (company_id, admin_user_id, active) VALUES (900001, 900002, 1)');
$pdo->exec("INSERT INTO documents (id, company_id, document_code, title, status) VALUES (900001, 900001, 'OFFICE-A', 'Office test', 'published'), (900002, 900002, 'OFFICE-B', 'Other tenant', 'draft')");
$pdo->exec("INSERT INTO document_approvals (document_id, requested_by, approver_user_id, decision, request_note, decision_note, decided_at) VALUES (900001, 900002, 900001, 'approved', 'İlk yayın', 'Onaylandı', NOW())");
$config = array_replace(qmsOfficeDefaults(), ['enabled' => true, 'public_url' => 'https://office.example.test', 'discovery_url' => 'https://office.example.test/hosting/discovery', 'qms_url' => 'https://qms.example.test/qms', 'host_url' => 'https://qms.example.test/qms', 'callback_ips' => '127.0.0.1']);
$options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
if (is_file('C:/xampp/php/extras/openssl/openssl.cnf')) $options['config'] = 'C:/xampp/php/extras/openssl/openssl.cnf';
$key = openssl_pkey_new($options);
if (!$key) throw new RuntimeException('OpenSSL test key unavailable');
$details = openssl_pkey_get_details($key);
$keys = ['modulus' => base64_encode($details['rsa']['n']), 'exponent' => base64_encode($details['rsa']['e'])];
$xml = '<wopi-discovery><net-zone name="external-https"><app name="test"><action name="edit" ext="docx" urlsrc="https://office.example.test/editor?&lt;ui=UI_LLCC&amp;&gt;"/><action name="view" ext="docx" urlsrc="https://office.example.test/view?"/><action name="view_comment" ext="pdf" urlsrc="https://office.example.test/pdf?"/></app></net-zone><proof-key modulus="' . $keys['modulus'] . '" exponent="' . $keys['exponent'] . '"/></wopi-discovery>';
$discovery = QmsWopiProvider::parseDiscovery($xml, $config);
$request = function (array $session, string $method = 'GET', bool $contents = false, array $headers = [], ?string $body = null, ?array $custom = null, string $ip = '127.0.0.1') use ($pdo, $config, $discovery, $key) {
    $c = $custom ?? $config;
    $url = $c['qms_url'] . '/wopi.php/files/' . $session['file_id'] . ($contents ? '/contents' : '') . '?access_token=' . $session['token'];
    $ticks = (time() * 10000000) + 621355968000000000;
    openssl_sign(qmsOfficeProofData($session['token'], $url, $ticks), $signature, $key, OPENSSL_ALGO_SHA256);
    $headers += ['x-wopi-timestamp' => (string) $ticks, 'x-wopi-proof' => base64_encode($signature)];
    return qmsOfficeDispatch($pdo, $c, $discovery, $session['file_id'], $session['token'], $method, $contents, $headers, $url, $ip, $body);
};
try {
    $firstFile = officeFixture('İlk sürüm'); $secondFile = officeFixture('İkinci sürüm'); $thirdFile = officeFixture('Üçüncü sürüm');
    $stmt = $pdo->prepare("INSERT INTO document_versions (document_id, revision_number, original_file_name, stored_file_name, mime_type, file_size) VALUES (?, '01', 'Test.docx', ?, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', ?)");
    $stmt->execute([900001, basename($firstFile), filesize($firstFile)]); $firstId = (int) $pdo->lastInsertId();
    $stmt->execute([900002, basename($firstFile), filesize($firstFile)]);
    $firstHash = hash_file('sha256', $firstFile);
    officeCheck(isset($discovery['actions']['docx']['edit']), 'Discovery parses edit action');
    try { qmsOfficeProvider($config)->launchUrl($config, $discovery, 'doc', true, 'https://qms.example.test/wopi/files/a'); throw new Exception('DOC accepted without discovery support'); }
    catch (RuntimeException $e) { officeCheck(true, 'DOC support stays dependent on provider discovery'); }
    $pdf = qmsOfficeProvider($config)->launchUrl($config, $discovery, 'pdf', true, 'https://qms.example.test/wopi/files/a');
    officeCheck(!$pdf['write'], 'PDF capability falls back to read-only');
    $only = array_replace($config, ['provider' => 'onlyoffice-wopi']);
    officeCheck(str_contains(qmsOfficeProvider($only)->label(), 'ONLYOFFICE'), 'Provider switches by configuration');
    try { QmsWopiProvider::parseDiscovery(str_replace('https://office.example.test/editor', 'https://evil.example/editor', $xml), $config); throw new Exception('Unsafe discovery accepted'); }
    catch (RuntimeException $e) { officeCheck(true, 'Cross-origin discovery rejected'); }
    try { QmsWopiProvider::parseDiscovery('<!DOCTYPE x><wopi-discovery/>', $config); throw new Exception('DTD accepted'); }
    catch (RuntimeException $e) { officeCheck(true, 'Discovery DTD rejected'); }
    qmsOfficeCsrf('csrf', 'csrf'); officeReject(fn() => qmsOfficeCsrf('csrf', 'wrong'), 403, 'CSRF rejected');
    officeReject(fn() => qmsOfficeCreateSession($pdo, $config, $discovery, 900002, 0, 900002, true), 404, 'Cross-tenant launch rejected');
    $session = qmsOfficeCreateSession($pdo, $config, $discovery, 900001, $firstId, 900002, true);
    $other = qmsOfficeCreateSession($pdo, $config, $discovery, 900001, $firstId, 900002, true);
    $readOnly = qmsOfficeCreateSession($pdo, $config, $discovery, 900001, $firstId, 900002, false);
    officeCheck(!str_contains($session['url'], $session['token']), 'Launch URL contains no access token');
    $storedToken = $pdo->query("SELECT token_hash FROM office_sessions WHERE file_id = '{$session['file_id']}'")->fetchColumn();
    officeCheck($storedToken === hash('sha256', $session['token']) && $storedToken !== $session['token'], 'Only token hash stored');
    $info = $request($session)['json']; officeCheck($info['UserCanWrite'] && $info['Version'] === (string) $firstId, 'Signed CheckFileInfo');
    officeCheck(hash_file('sha256', $request($session, 'GET', true)['file']) === $firstHash, 'Signed GetFile');
    officeReject(fn() => $request(array_replace($session, ['token' => str_repeat('0', 64)])), 401, 'Forged token rejected');
    officeReject(fn() => $request(array_replace($session, ['file_id' => $other['file_id']])), 401, 'Token bound to file resource');
    officeReject(fn() => $request($session, 'GET', false, [], null, null, '192.0.2.1'), 403, 'Unknown callback IP rejected');
    officeReject(fn() => $request($session, 'GET', false, ['x-wopi-proof' => base64_encode('bad')] ), 500, 'Invalid callback proof rejected');
    officeReject(fn() => $request($session, 'GET', false, ['x-wopi-timestamp' => (string) ((time() - 1801) * 10000000 + 621355968000000000)]), 500, 'Expired proof rejected');
    officeReject(fn() => $request($session, 'GET', false, [], null, array_replace($config, ['enabled' => false])), 503, 'Disabled provider rejected');
    officeReject(fn() => $request($session, 'GET', false, [], null, $only), 401, 'Configuration change invalidates tokens');
    $pdo->exec("UPDATE office_sessions SET expires_at = 1 WHERE file_id = '{$session['file_id']}'");
    officeReject(fn() => $request($session), 401, 'Expired session rejected');
    $pdo->exec("UPDATE office_sessions SET expires_at = " . (time() + 1800) . " WHERE file_id = '{$session['file_id']}'");
    $pdo->exec('UPDATE company_admin_assignments SET active = 0');
    officeReject(fn() => $request($session), 404, 'Revoked tenant assignment rejected');
    $pdo->exec('UPDATE company_admin_assignments SET active = 1');
    $pdo->exec('UPDATE users SET active = 0 WHERE id = 900002');
    officeReject(fn() => $request($session), 404, 'Inactive user rejected');
    $pdo->exec('UPDATE users SET active = 1 WHERE id = 900002');
    $pdo->exec("UPDATE office_sessions SET company_id = 900002 WHERE file_id = '{$session['file_id']}'");
    officeReject(fn() => $request($session), 404, 'Token tenant binding enforced');
    $pdo->exec("UPDATE office_sessions SET company_id = 900001 WHERE file_id = '{$session['file_id']}'");
    officeReject(fn() => $request($readOnly, 'POST', true, ['x-wopi-override' => 'PUT', 'x-wopi-lock' => 'lock-a'], $secondFile), 403, 'Read-only write denied');
    officeReject(fn() => $request($session, 'POST', true, ['x-wopi-override' => 'PUT', 'x-wopi-lock' => 'lock-a'], $secondFile), 409, 'Put requires active lock');
    $request($session, 'POST', false, ['x-wopi-override' => 'LOCK', 'x-wopi-lock' => 'lock-a']);
    officeCheck($request($session, 'POST', false, ['x-wopi-override' => 'GET_LOCK'])['headers']['X-WOPI-Lock'] === 'lock-a', 'Lock acquired');
    officeReject(fn() => $request($other, 'POST', false, ['x-wopi-override' => 'LOCK', 'x-wopi-lock' => 'lock-a']), 409, 'Second writer cannot reuse lock');
    try { qmsSaveEditorRevision($pdo, 900001, 900002, false, $firstId, 'WEB', '<p>Other interface</p>', ''); throw new Exception('Web editor ignored lock'); }
    catch (RuntimeException $e) { officeCheck(true, 'Web editor respects office lock'); }
    $request($session, 'POST', false, ['x-wopi-override' => 'REFRESH_LOCK', 'x-wopi-lock' => 'lock-a']);
    $saved = $request($session, 'POST', true, ['x-wopi-override' => 'PUT', 'x-wopi-lock' => 'lock-a'], $secondFile);
    officeCheck((int) $saved['headers']['X-WOPI-ItemVersion'] > $firstId, 'PutFile creates new revision');
    officeCheck(qmsEditorDocument($pdo, 900001, 900002, false)['status'] === 'draft', 'Published document returns to draft');
    officeCheck(hash_file('sha256', $firstFile) === $firstHash, 'Previous file immutable');
    $savedId = (int) $saved['headers']['X-WOPI-ItemVersion'];
    $savedVersion = qmsOfficeVersion($pdo, 900001, $savedId);
    officeCheck($savedVersion['original_file_name'] === 'Test.docx' && $savedVersion['mime_type'] === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'New revision preserves DOCX identity');
    officeCheck(hash_file('sha256', qmsOfficeFilePath($savedVersion)) === hash_file('sha256', $secondFile), 'New revision contains returned DOCX bytes');
    officeCheck($pdo->query("SELECT COUNT(*) FROM document_approvals WHERE document_id = 900001 AND decision = 'approved'")->fetchColumn() == 1, 'Previous approval history preserved');
    officeCheck($pdo->query("SELECT COUNT(*) FROM document_approvals WHERE document_id = 900001 AND decision = 'pending'")->fetchColumn() == 0, 'New draft requires a fresh approval request');
    officeCheck(qmsEditorDocument($pdo, 900001, 900002, false)['current_revision'] === $savedVersion['revision_number'], 'Current revision advances to saved DOCX');
    $count = (int) $pdo->query('SELECT COUNT(*) FROM document_versions WHERE document_id = 900001')->fetchColumn();
    $request($session, 'POST', true, ['x-wopi-override' => 'PUT', 'x-wopi-lock' => 'lock-a'], $secondFile);
    officeCheck((int) $pdo->query('SELECT COUNT(*) FROM document_versions WHERE document_id = 900001')->fetchColumn() === $count, 'Retry is idempotent');
    officeCheck($request($readOnly)['json']['Version'] === (string) $firstId, 'Old version stays pinned');
    officeCheck(!$request($other)['json']['UserCanWrite'], 'Stale session loses write permission');
    $pdo->exec("UPDATE documents SET status = 'approved' WHERE id = 900001");
    $request($session, 'POST', true, ['x-wopi-override' => 'PUT', 'x-wopi-lock' => 'lock-a'], $thirdFile);
    officeCheck(qmsEditorDocument($pdo, 900001, 900002, false)['status'] === 'draft', 'Approved document returns to draft');
    foreach (['review', 'archived'] as $state) {
        $pdo->exec("UPDATE documents SET status = '$state' WHERE id = 900001");
        officeReject(fn() => $request($session, 'POST', true, ['x-wopi-override' => 'PUT', 'x-wopi-lock' => 'lock-a'], $secondFile), 409, $state . ' prevents callback save');
    }
    $pdo->exec("UPDATE documents SET status = 'draft' WHERE id = 900001");
    $badFile = tempnam(sys_get_temp_dir(), 'qms-office-'); $fixtures[] = $badFile; file_put_contents($badFile, '<html>not office</html>');
    officeReject(fn() => $request($session, 'POST', true, ['x-wopi-override' => 'PUT', 'x-wopi-lock' => 'lock-a'], $badFile), 415, 'Wrong file format rejected');
    file_put_contents($badFile, str_repeat('x', $config['max_bytes'] + 1));
    officeReject(fn() => $request($session, 'POST', true, ['x-wopi-override' => 'PUT', 'x-wopi-lock' => 'lock-a'], $badFile), 413, 'Oversized body rejected');
    $request($session, 'POST', false, ['x-wopi-override' => 'LOCK', 'x-wopi-oldlock' => 'lock-a', 'x-wopi-lock' => 'lock-b']);
    officeCheck($request($session, 'POST', false, ['x-wopi-override' => 'GET_LOCK'])['headers']['X-WOPI-Lock'] === 'lock-b', 'Unlock and relock supported');
    $request($session, 'POST', false, ['x-wopi-override' => 'UNLOCK', 'x-wopi-lock' => 'lock-b']);
    officeReject(fn() => $request($other, 'POST', false, ['x-wopi-override' => 'LOCK', 'x-wopi-lock' => 'lock-new']), 409, 'Stale session cannot reacquire write lock');
    officeCheck((int) $pdo->query("SELECT COUNT(*) FROM office_audit WHERE event = 'revision_created'")->fetchColumn() === 2, 'Revision audit recorded');
    officeCheck(!str_contains(json_encode($pdo->query('SELECT * FROM office_audit')->fetchAll()), $session['token']), 'Audit excludes access tokens');
    echo "Completed $checks checks using temporary tables.\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    foreach ($pdo->query('SELECT stored_file_name FROM document_versions') as $row) $fixtures[] = 'storage/documents/' . basename($row['stored_file_name']);
    foreach (array_unique($fixtures) as $path) if (is_file($path)) unlink($path);
}
