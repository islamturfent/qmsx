<?php

class QmsOfficeError extends RuntimeException
{
    public function __construct(public int $status, string $message, public array $headers = []) { parent::__construct($message); }
}

function qmsOfficeAudit(PDO $pdo, string $event, string $outcome, array $context = [], string $ip = '', string $details = ''): void
{
    $stmt = $pdo->prepare('INSERT INTO office_audit (company_id, document_id, user_id, version_id, event, outcome, source_ip, details) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$context['company_id'] ?? null, $context['document_id'] ?? null, $context['user_id'] ?? null,
        $context['version_id'] ?? null, $event, $outcome, substr($ip, 0, 45), mb_substr($details, 0, 500)]);
}

function qmsOfficeSchemaReady(PDO $pdo): bool
{
    try {
        foreach (['office_sessions', 'office_locks', 'office_audit'] as $table) $pdo->query('SELECT 1 FROM ' . $table . ' LIMIT 0');
        return true;
    } catch (PDOException $e) { if ($e->getCode() === '42S02') return false; throw $e; }
}

function qmsOfficeCsrf(string $expected, mixed $provided): void
{
    if ($expected === '' || !is_string($provided) || !hash_equals($expected, $provided)) throw new QmsOfficeError(403, 'Oturum doğrulanamadı. Sayfayı yenileyin.');
}

function qmsOfficeCheckIp(array $config, string $ip): void
{
    $packed = @inet_pton($ip);
    foreach (array_map('trim', explode(',', $config['callback_ips'])) as $allowed) {
        if ($packed !== false && @inet_pton($allowed) === $packed) return;
    }
    throw new QmsOfficeError(403, 'Ofis sunucusu IP adresi yetkili değil.');
}

function qmsOfficeDer(int $tag, string $value): string
{
    $length = strlen($value);
    if ($length < 128) return chr($tag) . chr($length) . $value;
    $bytes = ltrim(pack('N', $length), "\0");
    return chr($tag) . chr(128 | strlen($bytes)) . $bytes . $value;
}

function qmsOfficePublicKey(string $modulus, string $exponent): string
{
    $m = base64_decode($modulus, true); $e = base64_decode($exponent, true);
    if (!$m || !$e || strlen($m) < 128 || strlen($m) > 1024 || strlen($e) > 8) return '';
    $integer = function ($v) { $v = ltrim($v, "\0"); if (ord($v[0]) & 128) $v = "\0" . $v; return qmsOfficeDer(2, $v); };
    $der = qmsOfficeDer(48, $integer($m) . $integer($e));
    return "-----BEGIN RSA PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END RSA PUBLIC KEY-----\n";
}

function qmsOfficeProofData(string $token, string $url, int $ticks): string
{
    $url = strtoupper($url);
    return pack('N', strlen($token)) . $token . pack('N', strlen($url)) . $url . pack('N', 8) . pack('J', $ticks);
}

function qmsOfficeVerifyProof(array $keys, array $headers, string $token, string $url): void
{
    $timestamp = $headers['x-wopi-timestamp'] ?? '';
    if (!preg_match('/^[0-9]{18}$/', $timestamp)) throw new QmsOfficeError(500, 'Geçersiz WOPI zaman damgası.');
    $ticks = (int) $timestamp;
    $seconds = intdiv($ticks - 621355968000000000, 10000000);
    if (abs(time() - $seconds) > 1200) throw new QmsOfficeError(500, 'WOPI imzasının süresi geçersiz.');
    $data = qmsOfficeProofData($token, $url, $ticks);
    $current = qmsOfficePublicKey($keys['modulus'] ?? '', $keys['exponent'] ?? '');
    $old = qmsOfficePublicKey($keys['oldmodulus'] ?? '', $keys['oldexponent'] ?? '');
    foreach ([[$current, $headers['x-wopi-proof'] ?? ''], [$current, $headers['x-wopi-proofold'] ?? ''], [$old, $headers['x-wopi-proof'] ?? '']] as [$key, $signature]) {
        $signature = base64_decode($signature, true);
        if ($key !== '' && $signature && openssl_verify($data, $signature, $key, OPENSSL_ALGO_SHA256) === 1) return;
    }
    throw new QmsOfficeError(500, 'WOPI imzası doğrulanamadı.');
}

function qmsOfficeActor(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare("SELECT id, full_name, role FROM users WHERE id = ? AND active = 1 AND role IN ('super_admin', 'system_admin')");
    $stmt->execute([$id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) throw new QmsOfficeError(404, 'Kullanıcı yetkisi bulunamadı.');
    return $user;
}
