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

/**
 * Sağlayici onayarlari (preset). Geciste base_url/model/whisper otomatik dolar.
 *
 * @return array<string, array{label:string, base_url:string, model:string, whisper_model:string}>
 */
function qmsAiProviderPresets(): array
{
    return [
        'openai' => [
            'label' => 'OpenAI (gpt-4o-mini + whisper-1)',
            'base_url' => 'https://api.openai.com/v1',
            'model' => 'gpt-4o-mini',
            'whisper_model' => 'whisper-1',
        ],
        'groq' => [
            'label' => 'Groq (Llama + whisper-large-v3)',
            'base_url' => 'https://api.groq.com/openai/v1',
            'model' => 'llama-3.3-70b-versatile',
            'whisper_model' => 'whisper-large-v3',
        ],
    ];
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
