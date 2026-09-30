<?php

declare(strict_types=1);

/**
 * Yapay zeka (AI) ayarlari.
 *
 * Kimlik bilgisi ve tercihler `storage/ai/settings.json` altinda saklanir;
 * bu dosya git'e commit edilmez (bkz. .gitignore). Cagrilar her zaman
 * sunucu tarafindan yapilir; API anahtari tarayiciya hicbir zaman gonderilmez.
 */

const QMS_AI_DEFAULTS = [
    'enabled' => false,
    'provider' => 'openai',
    'api_key' => '',
    'model' => 'gpt-4o-mini',
    'whisper_model' => 'whisper-1',
    'base_url' => 'https://api.openai.com/v1',
    'timeout' => 60,
];

/** Varsayilan AI yapilandirmasi. */
function qmsAiDefaults(): array
{
    return QMS_AI_DEFAULTS;
}

/** Diskten okunan AI ayar dosyasi; yoksa varsayilanlar. */
function qmsAiConfig(): array
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $defaults = qmsAiDefaults();
    $path = __DIR__ . '/../storage/ai/settings.json';
    $file = $path;
    if (is_file($file)) {
        $raw = json_decode((string) file_get_contents($file), true);
        if (is_array($raw)) {
            $defaults = array_merge($defaults, $raw);
        }
    }
    $cached = $defaults;
    return $cached;
}
