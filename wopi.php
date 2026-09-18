<?php
// Server-to-server endpoint: never authenticate callbacks using browser cookies.
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/office/service.php';
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
$input = null;
try {
    $config = qmsOfficeConfig();
    if (!$config['enabled']) throw new QmsOfficeError(503, 'Ofis entegrasyonu devre dışı.');
    if (!qmsOfficeSchemaReady($pdo)) throw new QmsOfficeError(503, 'Ofis veritabanı kurulumu tamamlanmamış.');
    $path = $_SERVER['PATH_INFO'] ?? '';
    if (!preg_match('~^/files/([a-f0-9]{32})(/contents)?$~D', $path, $match)) throw new QmsOfficeError(404, 'WOPI kaynağı bulunamadı.');
    $token = is_string($_GET['access_token'] ?? null) ? $_GET['access_token'] : '';
    if (!preg_match('/^[a-f0-9]{64}$/D', $token)) throw new QmsOfficeError(401, 'Geçersiz erişim belirteci.');
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    qmsOfficeCheckIp($config, $ip); // Never trust client-supplied Forwarded/X-Forwarded-For.
    $headers = [];
    foreach ($_SERVER as $key => $value) if (str_starts_with($key, 'HTTP_X_WOPI_')) $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
    $url = rtrim($config['qms_url'], '/') . '/wopi.php' . $path . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '');
    $discovery = qmsOfficeProvider($config)->discovery($config);
    if ($config['require_proof']) qmsOfficeVerifyProof($discovery['proof'], $headers, $token, $url);
    // Reject revoked/foreign tokens before accepting even a bounded upload body.
    $pdo->beginTransaction();
    qmsOfficeAuthenticate($pdo, $config, $match[1], $token);
    $pdo->commit();
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($match[2])) {
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > $config['max_bytes']) throw new QmsOfficeError(413, 'Dosya boyut sınırını aşıyor.');
        $input = tmpfile(); $source = fopen('php://input', 'rb');
        if (!$input || !$source) throw new RuntimeException('Geçici dosya açılamadı.');
        $bytes = stream_copy_to_stream($source, $input, $config['max_bytes'] + 1); fclose($source);
        if ($bytes === false || $bytes > $config['max_bytes']) throw new QmsOfficeError(413, 'Dosya boyut sınırını aşıyor.');
    }
    $response = qmsOfficeDispatch($pdo, $config, $discovery, $match[1], $token, $_SERVER['REQUEST_METHOD'], isset($match[2]), $headers, $url, $ip,
        $input ? stream_get_meta_data($input)['uri'] : null);
    http_response_code($response['status']);
    foreach ($response['headers'] as $name => $value) header($name . ': ' . $value);
    if (isset($response['file'])) { header('Content-Length: ' . filesize($response['file'])); readfile($response['file']); }
    elseif (isset($response['json'])) { header('Content-Type: application/json; charset=utf-8'); echo json_encode($response['json'], JSON_THROW_ON_ERROR); }
    else echo $response['body'];
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $status = $error instanceof QmsOfficeError ? $error->status : 503;
    http_response_code($status);
    if ($error instanceof QmsOfficeError) foreach ($error->headers as $name => $value) header($name . ': ' . $value);
    // Also covers requests rejected during preflight, without recording tokens or request URLs.
    try { qmsOfficeAudit($pdo, 'endpoint_error', 'denied', [], $_SERVER['REMOTE_ADDR'] ?? '', 'status=' . $status); } catch (Throwable $ignored) { error_log('QMS office endpoint/audit unavailable'); }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $error instanceof QmsOfficeError ? $error->getMessage() : 'Ofis hizmeti şu anda kullanılamıyor.']);
} finally { if (is_resource($input)) fclose($input); }
