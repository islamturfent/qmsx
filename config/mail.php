<?php
// E-posta (SMTP) yapilandirmasi.
// Storage'daki ayarlar super admin tarafindan kaydedilebilir; cevre degiskenleri
// (QMS_MAIL_*) storage ayarlarini ezebilir.
function qmsMailDefaults(): array
{
    return [
        'enabled' => false,
        'from_email' => 'qms@localhost',
        'from_name' => 'QMS',
        'base_url' => 'http://localhost/qmsx/',
        'host' => '',
        'port' => 587,
        'username' => '',
        'password' => '',
        'encryption' => 'tls', // none | tls | ssl
        'timeout' => 15,
        'debug' => false,
    ];
}

function qmsMailConfig(): array
{
    $config = qmsMailDefaults();
    $path = dirname(__DIR__) . '/storage/mail/settings.json';
    if (is_file($path)) {
        $saved = json_decode(file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
        $config = array_replace($config, array_intersect_key($saved, $config));
    }
    foreach ($config as $key => $default) {
        $value = getenv('QMS_MAIL_' . strtoupper($key));
        if ($value !== false) {
            $config[$key] = is_bool($default)
                ? filter_var($value, FILTER_VALIDATE_BOOLEAN)
                : (is_int($default) ? (int) $value : $value);
        }
    }
    return $config;
}
