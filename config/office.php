<?php
// Environment variables override settings saved by the super administrator.
function qmsOfficeDefaults(): array
{
    return ['enabled' => false, 'provider' => 'collabora', 'public_url' => '', 'discovery_url' => '',
        'qms_url' => '', 'host_url' => '', 'callback_ips' => '', 'require_proof' => true, 'allow_http' => false,
        'token_ttl' => 1800, 'max_bytes' => 10485760];
}

function qmsOfficeConfig(): array
{
    $config = qmsOfficeDefaults();
    $path = dirname(__DIR__) . '/storage/office/settings.json';
    if (is_file($path)) {
        $saved = json_decode(file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
        $config = array_replace($config, array_intersect_key($saved, $config));
    }
    foreach ($config as $key => $default) {
        $value = getenv('QMS_OFFICE_' . strtoupper($key));
        if ($value !== false) $config[$key] = is_bool($default) ? filter_var($value, FILTER_VALIDATE_BOOLEAN) : (is_int($default) ? (int) $value : $value);
    }
    return $config;
}

function qmsOfficeOrigin(string $url): string
{
    $p = parse_url($url);
    if (!$p || empty($p['scheme']) || empty($p['host'])) return '';
    return strtolower($p['scheme'] . '://' . $p['host']) . (isset($p['port']) ? ':' . $p['port'] : '');
}

function qmsOfficeValidateConfig(array $c, bool $connecting = false): void
{
    if (!in_array($c['provider'], ['collabora', 'onlyoffice-wopi'], true)) throw new RuntimeException('Geçersiz ofis sağlayıcısı.');
    foreach (['public_url', 'discovery_url', 'qms_url', 'host_url'] as $key) {
        $url = $c[$key];
        if ($url === '' && !$connecting && !$c['enabled']) continue;
        $p = parse_url($url);
        if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array($p['scheme'] ?? '', $c['allow_http'] ? ['http', 'https'] : ['https'], true)
            || isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment']) || preg_match('/[\x00-\x20<>]/', $url)) {
            throw new RuntimeException($key . ': geçerli bir HTTPS adresi girin. HTTP yalnız yerel deneme ayarıyla kullanılabilir.');
        }
    }
    $ips = array_filter(array_map('trim', explode(',', $c['callback_ips'])));
    if (($connecting || $c['enabled']) && !$ips) throw new RuntimeException('Ofis sunucusunun callback IP adreslerini belirtin.');
    foreach ($ips as $ip) if (!filter_var($ip, FILTER_VALIDATE_IP)) throw new RuntimeException('Callback listesi virgülle ayrılmış tam IP adreslerinden oluşmalıdır.');
    if ($c['token_ttl'] < 300 || $c['token_ttl'] > 3600) throw new RuntimeException('Belirteç süresi 300–3600 saniye olmalıdır.');
    if ($c['max_bytes'] < 1024 || $c['max_bytes'] > 50 * 1024 * 1024) throw new RuntimeException('Dosya sınırı 1 KB–50 MB arasında olmalıdır.');
    // Unsigned callbacks are permitted only for an explicitly configured local HTTP trial.
    if (!$c['require_proof'] && !$c['allow_http']) throw new RuntimeException('HTTPS/üretim yapılandırmasında WOPI imza doğrulaması zorunludur.');
    if (!$c['require_proof']) {
        foreach (['public_url', 'discovery_url', 'qms_url', 'host_url'] as $key) {
            if ($c[$key] === '') continue;
            $host = trim(strtolower((string) parse_url($c[$key], PHP_URL_HOST)), '[]');
            $private = filter_var($host, FILTER_VALIDATE_IP) && !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
            if (!in_array($host, ['localhost', 'host.docker.internal'], true) && !$private) throw new RuntimeException('İmzasız deneme yalnız localhost veya özel IP adresleriyle kullanılabilir.');
        }
    }
}

function qmsOfficeFingerprint(array $c): string
{
    return hash('sha256', json_encode($c, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}
