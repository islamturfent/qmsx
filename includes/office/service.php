<?php
require_once dirname(__DIR__) . '/document-editor.php';
require_once __DIR__ . '/adapter.php';
require_once __DIR__ . '/security.php';

function qmsOfficeFilePath(array $version): string
{
    return dirname(__DIR__, 2) . '/storage/documents/' . basename($version['stored_file_name']);
}

function qmsOfficeVersion(PDO $pdo, int $docId, int $versionId): array
{
    $stmt = $pdo->prepare('SELECT * FROM document_versions WHERE id = ? AND document_id = ?');
    $stmt->execute([$versionId, $docId]);
    $version = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$version || !is_file(qmsOfficeFilePath($version))) throw new QmsOfficeError(404, 'Revizyon dosyası bulunamadı.');
    return $version;
}

function qmsOfficeWritable(PDO $pdo, array $doc): bool
{
    if (!in_array($doc['status'], ['draft', 'approved', 'published'], true)) return false;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM document_approvals WHERE document_id = ? AND decision = 'pending'");
    $stmt->execute([$doc['id']]);
    return (int) $stmt->fetchColumn() === 0;
}

function qmsOfficeCreateSession(PDO $pdo, array $config, array $discovery, int $docId, int $versionId, int $userId, bool $wantWrite, string $ip = ''): array
{
    if (!$config['enabled']) throw new QmsOfficeError(503, 'Ofis entegrasyonu devre dışı.');
    qmsOfficeValidateConfig($config, true);
    $provider = qmsOfficeProvider($config);
    try {
        $pdo->beginTransaction();
        $actor = qmsOfficeActor($pdo, $userId);
        $doc = qmsEditorDocument($pdo, $docId, $userId, $actor['role'] === 'super_admin', true);
        if (!$doc) throw new QmsOfficeError(404, 'Dokümana erişim yetkiniz yok.');
        $latest = qmsEditorLatest($pdo, $docId);
        $version = qmsOfficeVersion($pdo, $docId, $versionId ?: (int) ($latest['id'] ?? 0));
        $ext = strtolower(pathinfo($version['original_file_name'], PATHINFO_EXTENSION));
        $write = $wantWrite && (int) $version['id'] === (int) ($latest['id'] ?? 0) && qmsOfficeWritable($pdo, $doc);
        $fileId = bin2hex(random_bytes(16));
        $token = bin2hex(random_bytes(32));
        $src = rtrim($config['qms_url'], '/') . '/wopi.php/files/' . $fileId;
        $launch = $provider->launchUrl($config, $discovery, $ext, $write, $src);
        $write = $launch['write'];
        $expires = time() + $config['token_ttl'];
        $pdo->prepare('INSERT INTO office_sessions (file_id, token_hash, document_id, company_id, user_id, version_id, can_write, config_hash, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$fileId, hash('sha256', $token), $docId, $doc['company_id'], $userId, $version['id'], (int) $write, qmsOfficeFingerprint($config), $expires]);
        qmsOfficeAudit($pdo, 'session_open', 'ok', ['company_id' => $doc['company_id'], 'document_id' => $docId, 'user_id' => $userId, 'version_id' => $version['id']], $ip, $write ? 'edit' : 'view');
        $pdo->commit();
        return ['file_id' => $fileId, 'token' => $token, 'expires' => $expires, 'url' => $launch['url'], 'write' => $write];
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}

function qmsOfficeAuthenticate(PDO $pdo, array $config, string $fileId, string $token): array
{
    if (!preg_match('/^[a-f0-9]{64}$/D', $token)) throw new QmsOfficeError(401, 'Geçersiz erişim belirteci.');
    $stmt = $pdo->prepare('SELECT * FROM office_sessions WHERE file_id = ?');
    $stmt->execute([$fileId]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$session || !hash_equals($session['token_hash'], hash('sha256', $token)) || $session['revoked'] || (int) $session['expires_at'] <= time()
        || !hash_equals($session['config_hash'], qmsOfficeFingerprint($config))) throw new QmsOfficeError(401, 'Ofis oturumu geçersiz veya süresi dolmuş.');
    $actor = qmsOfficeActor($pdo, (int) $session['user_id']);
    $doc = qmsEditorDocument($pdo, (int) $session['document_id'], (int) $actor['id'], $actor['role'] === 'super_admin', true);
    if (!$doc || (int) $doc['company_id'] !== (int) $session['company_id']) throw new QmsOfficeError(404, 'Dokümana erişim yetkiniz yok.');
    $stmt = $pdo->prepare('SELECT active FROM companies WHERE id = ?'); $stmt->execute([$session['company_id']]);
    if (!(int) $stmt->fetchColumn()) throw new QmsOfficeError(404, 'Şirket erişimi kapalı.');
    // Re-read after the document lock: another callback may have advanced this session.
    $stmt = $pdo->prepare('SELECT * FROM office_sessions WHERE file_id = ? FOR UPDATE'); $stmt->execute([$fileId]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($session['revoked'] || (int) $session['expires_at'] <= time()) throw new QmsOfficeError(401, 'Ofis oturumunun süresi dolmuş.');
    return [$session, $doc, $actor];
}

function qmsOfficeValidateFile(string $path, string $ext, int $maxBytes): void
{
    $size = filesize($path);
    if (!$size || $size > $maxBytes) throw new QmsOfficeError(413, 'Dosya boş veya boyut sınırını aşıyor.');
    $head = file_get_contents($path, false, null, 0, 8);
    if ($ext === 'pdf' && str_starts_with($head, '%PDF-')) return;
    if (in_array($ext, ['doc', 'xls'], true) && $head === "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") return;
    if (in_array($ext, ['docx', 'xlsx'], true)) {
        $zip = new ZipArchive();
        if ($zip->open($path) === true) {
            $required = $ext === 'docx' ? 'word/document.xml' : 'xl/workbook.xml';
            $valid = $zip->locateName('[Content_Types].xml') !== false && $zip->locateName($required) !== false && $zip->numFiles <= 10000;
            $expanded = 0;
            for ($i = 0; $valid && $i < $zip->numFiles; $i++) { $expanded += $zip->statIndex($i)['size']; if ($expanded > 200 * 1024 * 1024) $valid = false; }
            $zip->close();
            if ($valid) return;
        }
    }
    throw new QmsOfficeError(415, 'Kaydedilen dosyanın biçimi mevcut revizyonla uyumlu değil.');
}

function qmsOfficeDispatch(PDO $pdo, array $config, array $discovery, string $fileId, string $token, string $method, bool $contents, array $headers, string $signedUrl, string $ip, ?string $bodyPath = null): array
{
    $context = []; $newPath = null;
    $operation = $method === 'GET' ? ($contents ? 'GetFile' : 'CheckFileInfo') : ($headers['x-wopi-override'] ?? 'unknown');
    try {
        if (!$config['enabled']) throw new QmsOfficeError(503, 'Ofis entegrasyonu devre dışı.');
        qmsOfficeValidateConfig($config, true);
        qmsOfficeCheckIp($config, $ip);
        if ($config['require_proof']) qmsOfficeVerifyProof($discovery['proof'], $headers, $token, $signedUrl);
        $pdo->beginTransaction();
        [$session, $doc, $actor] = qmsOfficeAuthenticate($pdo, $config, $fileId, $token);
        $context = $session;
        $version = qmsOfficeVersion($pdo, (int) $doc['id'], (int) $session['version_id']);
        $latest = qmsEditorLatest($pdo, (int) $doc['id']);
        $write = (bool) $session['can_write'] && qmsOfficeWritable($pdo, $doc) && (int) $latest['id'] === (int) $version['id'];
        $response = ['status' => 200, 'headers' => ['X-WOPI-ItemVersion' => (string) $version['id']], 'body' => ''];
        if ($method === 'GET') {
            if ($contents) {
                $response['file'] = qmsOfficeFilePath($version);
                $response['headers']['Content-Type'] = 'application/octet-stream';
            } else {
                $response['json'] = ['BaseFileName' => $version['original_file_name'], 'OwnerId' => 'company-' . $doc['company_id'],
                    'Size' => (int) filesize(qmsOfficeFilePath($version)), 'Version' => (string) $version['id'],
                    'UserId' => 'company-' . $doc['company_id'] . '-user-' . $actor['id'], 'UserFriendlyName' => $actor['full_name'],
                    'UserCanWrite' => $write, 'ReadOnly' => !$write, 'SupportsUpdate' => $write, 'SupportsLocks' => true,
                    'SupportsGetLock' => true, 'UserCanNotWriteRelative' => true, 'SupportsRename' => false,
                    'PostMessageOrigin' => qmsOfficeOrigin($config['host_url'])];
            }
        } elseif ($method === 'POST') {
            $stmt = $pdo->prepare('SELECT * FROM office_locks WHERE document_id = ?'); $stmt->execute([$doc['id']]);
            $lock = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($lock && (int) $lock['expires_at'] <= time()) $lock = false;
            $current = $lock['lock_value'] ?? '';
            $provided = $headers['x-wopi-lock'] ?? '';
            $old = $headers['x-wopi-oldlock'] ?? null;
            $conflict = fn() => new QmsOfficeError(409, 'Doküman kilidi veya revizyonu değişti.', ['X-WOPI-Lock' => $current]);
            if (!$write && !in_array($operation, ['GET_LOCK', 'UNLOCK'], true)) {
                if ($session['can_write']) throw $conflict();
                throw new QmsOfficeError(403, 'Salt okunur oturum.');
            }
            if ($contents && $operation !== 'PUT') throw new QmsOfficeError(501, 'WOPI işlemi desteklenmiyor.');
            if (!$contents && $operation === 'GET_LOCK') {
                $response['headers']['X-WOPI-Lock'] = $current;
            } elseif (!$contents && in_array($operation, ['LOCK', 'REFRESH_LOCK', 'UNLOCK'], true)) {
                if ($provided === '' || strlen($provided) > 1024 || preg_match('/[\r\n\x00]/', $provided) || ($old !== null && (strlen($old) > 1024 || preg_match('/[\r\n\x00]/', $old)))) throw new QmsOfficeError(400, 'Geçersiz kilit değeri.');
                if ($lock && ($lock['file_id'] !== $fileId || !hash_equals($current, $old ?? $provided))) throw $conflict();
                if (!$lock && ($operation !== 'LOCK' || $old !== null)) throw $conflict();
                if ($operation === 'UNLOCK') {
                    $pdo->prepare('DELETE FROM office_locks WHERE document_id = ?')->execute([$doc['id']]);
                } else {
                    $pdo->prepare('INSERT INTO office_locks (document_id, file_id, lock_value, expires_at) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE file_id = VALUES(file_id), lock_value = VALUES(lock_value), expires_at = VALUES(expires_at)')
                        ->execute([$doc['id'], $fileId, $provided, min(time() + 1800, (int) $session['expires_at'])]);
                }
            } elseif ($contents && $operation === 'PUT') {
                if (!$lock || $lock['file_id'] !== $fileId || !hash_equals($current, $provided)) throw $conflict();
                if (!$bodyPath || !is_file($bodyPath)) throw new QmsOfficeError(400, 'Dosya içeriği eksik.');
                $ext = strtolower(pathinfo($version['original_file_name'], PATHINFO_EXTENSION));
                qmsOfficeValidateFile($bodyPath, $ext, $config['max_bytes']);
                // A retry of the same successful save must not create a duplicate revision.
                if (!hash_equals(hash_file('sha256', qmsOfficeFilePath($version)), hash_file('sha256', $bodyPath))) {
                    $name = bin2hex(random_bytes(20)) . '.' . $ext;
                    $newPath = dirname(__DIR__, 2) . '/storage/documents/' . $name;
                    if (!copy($bodyPath, $newPath)) throw new RuntimeException('Revizyon dosyası yazılamadı.');
                    $revision = 'W' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3));
                    $pdo->prepare('INSERT INTO document_versions (document_id, revision_number, original_file_name, stored_file_name, mime_type, file_size, change_note, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                        ->execute([$doc['id'], $revision, $version['original_file_name'], $name, $version['mime_type'], filesize($newPath), 'Ofis editöründe kaydedildi (' . $config['provider'] . ')', $actor['id']]);
                    $newId = (int) $pdo->lastInsertId();
                    $pdo->prepare("UPDATE documents SET current_revision = ?, status = 'draft' WHERE id = ?")->execute([$revision, $doc['id']]);
                    $pdo->prepare('UPDATE office_sessions SET version_id = ? WHERE file_id = ?')->execute([$newId, $fileId]);
                    $context['version_id'] = $newId;
                    $response['headers']['X-WOPI-ItemVersion'] = (string) $newId;
                    qmsOfficeAudit($pdo, 'revision_created', 'ok', $context, $ip, 'previous=' . $version['id'] . '; status=' . $doc['status'] . '->draft');
                }
            } else throw new QmsOfficeError(501, 'WOPI işlemi desteklenmiyor.');
        } else throw new QmsOfficeError(405, 'HTTP yöntemi desteklenmiyor.');
        qmsOfficeAudit($pdo, in_array($operation, ['GetFile', 'CheckFileInfo', 'GET_LOCK', 'LOCK', 'REFRESH_LOCK', 'UNLOCK', 'PUT'], true) ? $operation : 'unsupported', 'ok', $context, $ip);
        $pdo->commit();
        return $response;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($newPath && is_file($newPath)) unlink($newPath);
        try { qmsOfficeAudit($pdo, 'callback_rejected', 'denied', $context, $ip, 'status=' . ($error instanceof QmsOfficeError ? $error->status : 500)); } catch (Throwable $ignored) { error_log('QMS office audit write failed'); }
        throw $error;
    }
}
