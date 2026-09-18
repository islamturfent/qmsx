<?php
require_once dirname(__DIR__, 2) . '/config/office.php';

interface QmsOfficeProvider
{
    public function label(): string;
    public function discovery(array $config, bool $refresh = false): array;
    public function launchUrl(array $config, array $discovery, string $extension, bool $write, string $wopiSrc): array;
}

class QmsWopiProvider implements QmsOfficeProvider
{
    public function __construct(private string $name) {}
    public function label(): string { return $this->name; }

    public function discovery(array $config, bool $refresh = false): array
    {
        qmsOfficeValidateConfig($config, true);
        if (!extension_loaded('curl')) throw new RuntimeException('PHP cURL eklentisi gerekli.');
        $cache = dirname(__DIR__, 2) . '/storage/office/discovery-' . qmsOfficeFingerprint($config) . '.json';
        if (!$refresh && is_file($cache) && filemtime($cache) > time() - 300) {
            $data = json_decode(file_get_contents($cache), true);
            if (is_array($data)) return $data;
        }
        $body = '';
        $curl = curl_init($config['discovery_url']);
        curl_setopt_array($curl, [CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 8,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$body) { if (strlen($body) + strlen($chunk) > 2097152) return 0; $body .= $chunk; return strlen($chunk); }]);
        $ok = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        if (!$ok || $status !== 200) throw new RuntimeException('Ofis sunucusuna ulaşılamadı. Adres, TLS sertifikası ve servis durumunu kontrol edin.');
        $data = self::parseDiscovery($body, $config);
        if (file_put_contents($cache, json_encode($data, JSON_THROW_ON_ERROR), LOCK_EX) === false) throw new RuntimeException('Discovery önbelleği yazılamadı.');
        return $data;
    }

    public static function parseDiscovery(string $xml, array $config): array
    {
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) throw new RuntimeException('Discovery XML güvenli değil.');
        $old = libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $ok = $dom->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors(); libxml_use_internal_errors($old);
        if (!$ok || $dom->documentElement->tagName !== 'wopi-discovery') throw new RuntimeException('Geçerli WOPI discovery yanıtı alınamadı.');
        $actions = [];
        foreach ($dom->getElementsByTagName('action') as $node) {
            $ext = strtolower($node->getAttribute('ext'));
            $name = $node->getAttribute('name');
            if (!in_array($ext, ['doc', 'docx', 'xls', 'xlsx', 'pdf'], true) || !in_array($name, ['edit', 'view', 'view_comment'], true)) continue;
            $url = preg_replace('/<[^>]*>/', '', $node->getAttribute('urlsrc'));
            $origin = qmsOfficeOrigin($url);
            if (!in_array($origin, [qmsOfficeOrigin($config['public_url']), qmsOfficeOrigin($config['discovery_url'])], true) || $origin === '') {
                throw new RuntimeException('Discovery başka bir sunucuya yönlendiriyor; sağlayıcı adreslerini kontrol edin.');
            }
            $parsed = parse_url($url);
            if (isset($parsed['user']) || isset($parsed['pass']) || isset($parsed['fragment'])) throw new RuntimeException('Geçersiz discovery işlem adresi.');
            // Separate internal discovery and browser-facing origins without trusting arbitrary hosts.
            $actions[$ext][$name] = qmsOfficeOrigin($config['public_url']) . substr($url, strlen($origin));
        }
        $proof = [];
        $node = $dom->getElementsByTagName('proof-key')->item(0);
        if ($node) foreach (['modulus', 'exponent', 'oldmodulus', 'oldexponent'] as $key) $proof[$key] = $node->getAttribute($key);
        if ($config['require_proof'] && (empty($proof['modulus']) || empty($proof['exponent']))) throw new RuntimeException('Sunucu WOPI proof key yayımlamıyor. Sunucuda imza anahtarlarını yapılandırın.');
        if (!$actions) throw new RuntimeException('Sunucu desteklenen dosyalar için WOPI işlemi sunmuyor.');
        return ['actions' => $actions, 'proof' => $proof];
    }

    public function launchUrl(array $config, array $discovery, string $extension, bool $write, string $wopiSrc): array
    {
        $actions = $discovery['actions'][$extension] ?? [];
        $action = $write && isset($actions['edit']) ? 'edit' : (isset($actions['view']) ? 'view' : 'view_comment');
        if (empty($actions[$action])) throw new RuntimeException('Bu dosya biçimi sağlayıcıda açılamıyor. Web editörünü veya dosya indirmeyi kullanabilirsiniz.');
        $url = $actions[$action];
        $separator = str_contains($url, '?') ? (str_ends_with($url, '?') || str_ends_with($url, '&') ? '' : '&') : '?';
        return ['url' => $url . $separator . 'WOPISrc=' . rawurlencode($wopiSrc) . '&ui=tr-TR', 'write' => $action === 'edit'];
    }
}

function qmsOfficeProvider(array $config): QmsOfficeProvider
{
    return match ($config['provider']) {
        'collabora' => new QmsWopiProvider('Collabora Online / CODE'),
        'onlyoffice-wopi' => new QmsWopiProvider('ONLYOFFICE Docs (WOPI)'),
        default => throw new RuntimeException('Ofis sağlayıcısı desteklenmiyor.')
    };
}
