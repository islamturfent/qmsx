<?php

declare(strict_types=1);

/**
 * Yapay zeka asistani yardimcilari.
 *
 * Sağlayıcı soyutlaması: su an OpenAI (gpt-4o-mini) + Whisper desteklenir.
 * Cagrilar yalnizca sunucu tarafinda yapilir; kimlik bilgisi `config/ai.php`
 * uzerinden `storage/ai/settings.json`'dan okunur. AI etkin degilse veya
 * cagri basarisizsa `qmsAiFallbackDocument()` ile offline şablon doner.
 */

require_once __DIR__ . '/../config/ai.php';

/** AI etkin mi? (anahtar ve aciklik). */
function qmsAiAvailable(): bool
{
    $cfg = qmsAiConfig();
    return (bool) $cfg['enabled'] && trim((string) $cfg['api_key']) !== '';
}

/** OpenAI chat tamamlamasi. @return array{ok:bool,text:string,error:string} */
function qmsAiChat(string $systemPrompt, string $userPrompt, int $maxTokens = 2200): array
{
    $cfg = qmsAiConfig();
    if (!qmsAiAvailable()) {
        return ['ok' => false, 'text' => '', 'error' => 'Yapay zeka etkin değil.'];
    }

    $url = rtrim((string) $cfg['base_url'], '/') . '/chat/completions';
    $payload = [
        'model' => (string) $cfg['model'],
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt],
        ],
        'temperature' => 0.6,
        'max_tokens' => $maxTokens,
    ];

    $result = qmsAiHttpPost($url, $payload, (string) $cfg['api_key'], (int) $cfg['timeout']);
    if ($result['http'] !== 200) {
        return ['ok' => false, 'text' => '', 'error' => 'AI çağrısı başarısız (HTTP ' . $result['http'] . '): ' . $result['body']];
    }
    $decoded = json_decode($result['body'], true);
    $text = (string) ($decoded['choices'][0]['message']['content'] ?? '');
    if ($text === '') {
        return ['ok' => false, 'text' => '', 'error' => 'AI boş yanıt döndürdü.'];
    }
    return ['ok' => true, 'text' => $text, 'error' => ''];
}

/** Whisper ile ses tanima (multipart). $audioBase64: ham ses, $mime: video/webm vb. */
function qmsAiWhisperTranscribe(string $audioBase64, string $mime = 'audio/webm', string $filename = 'voice.webm'): array
{
    $cfg = qmsAiConfig();
    if (!qmsAiAvailable()) {
        return ['ok' => false, 'text' => '', 'error' => 'Yapay zeka etkin değil.'];
    }
    $url = rtrim((string) $cfg['base_url'], '/') . '/audio/transcriptions';

    $audioBytes = base64_decode((string) $audioBase64, true);
    if ($audioBytes === false || $audioBytes === '') {
        return ['ok' => false, 'text' => '', 'error' => 'Ses verisi çözülemedi.'];
    }

    $boundary = '----qms' . bin2hex(random_bytes(8));
    $body = '--' . $boundary . "\r\n"
        . 'Content-Disposition: form-data; name="model"' . "\r\n\r\n"
        . (string) $cfg['whisper_model'] . "\r\n"
        . '--' . $boundary . "\r\n"
        . 'Content-Disposition: form-data; name="language"' . "\r\n\r\n"
        . "tr\r\n"
        . '--' . $boundary . "\r\n"
        . 'Content-Disposition: form-data; name="file"; filename="' . $filename . '"' . "\r\n"
        . 'Content-Type: ' . $mime . "\r\n\r\n"
        . $audioBytes . "\r\n"
        . '--' . $boundary . "--\r\n";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . (string) $cfg['api_key'],
            'Content-Type: multipart/form-data; boundary=' . $boundary,
        ],
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => (int) $cfg['timeout'],
    ]);
    $bodyResp = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = (string) curl_error($ch);
    curl_close($ch);

    if ($httpCode !== 200) {
        return ['ok' => false, 'text' => '', 'error' => 'Ses tanıma başarısız (HTTP ' . $httpCode . '): ' . ($bodyResp === false ? $err : (string) $bodyResp)];
    }
    $decoded = json_decode((string) $bodyResp, true);
    $text = trim((string) ($decoded['text'] ?? ''));
    if ($text === '') {
        return ['ok' => false, 'text' => '', 'error' => 'Ses tanıma boş metin döndürdü.'];
    }
    return ['ok' => true, 'text' => $text, 'error' => ''];
}

/** Genel HTTP POST yardimcisi (JSON body). @return array{http:int,body:string} */
function qmsAiHttpPost(string $url, array $payload, string $apiKey, int $timeout): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => max(10, $timeout),
    ]);
    $body = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['http' => $http, 'body' => $body === false ? '' : (string) $body];
}

/**
 * Offline şablon motoru: AI etkin degilken bile calisir.
 * @return string QMS stili taslak (Markdown benzeri).
 */
function qmsAiFallbackDocument(string $type, string $title, string $description): string
{
    $title = trim($title) !== '' ? trim($title) : 'Belirlenmemiş Doküman';
    $desc = trim($description);
    $now = date('Y-m-d');

    $head = "DOKÜMAN TASLAĞI (Şablon Motoru)\n"
        . "Doküman: " . $title . "\n"
        . "Tür: " . htmlspecialchars(qmsAiDocTypeLabel($type), ENT_QUOTES, 'UTF-8') . "\n"
        . "Tarih: " . $now . "\n"
        . "Revizyon: 0.1 · Durum: Taslak\n"
        . "Not: Bu taslak otomatik şablon motoruyla üretildi; yapay zeka etkinleştirildiğinde daha zengin bir taslak üretilir.\n\n";

    $body = "1. AMAÇ\n";
    $body .= "   " . ($desc !== '' ? $desc : 'Bu dokümanın amacını tanımlayın.') . "\n\n";

    $body .= "2. KAPSAM\n";
    $body .= "   " . ($type === 'policy' ? 'Organizasyon genelinde geçerlidir.' : ($type === 'procedure' ? 'İlgili tüm bölüm ve süreçler için geçerlidir.' : 'İlgili süreç ve faaliyetler için geçerlidir.')) . "\n\n";

    $body .= "3. TANIMLAR VE KISALTMALAR\n";
    $body .= "   (Gerekli terimleri tanımlayın.)\n\n";

    $body .= "4. SORUMLULUKLAR\n";
    $body .= "   • Doküman Sahibi: (atanacak)\n";
    $body .= "   • Uygulayan: İlgili bölümler\n\n";

    $body .= "5. UYGULAMA\n";
    if ($type === 'policy' || $type === 'procedure' || $type === 'instruction') {
        $body .= "   5.1 Girdiler ve kapsam\n";
        $body .= "   5.2 Uygulama adımları\n";
        $body .= "       (Adım adım açıklayın.)\n";
        $body .= "   5.3 Kontroller ve kayıtlar\n";
    } else {
        $body .= "   (Form alanları ve doldurma talimatı buraya.)\n";
    }
    $body .= "\n";

    $body .= "6. KAYITLAR VE REFERANSLAR\n";
    $body .= "   • Bu dokümanın oluşturduğu kayıtlar tanımlanır.\n\n";

    $body .= "7. GÖZDEN GEÇİRME VE ONAY\n";
    $body .= "   • Hazırlayan: ______  Kontrol: ______  Onay: ______\n";

    return $head . $body;
}

/** Doküman turu etiketi (TR). */
function qmsAiDocTypeLabel(string $type): string
{
    return [
        'policy' => 'Politika',
        'procedure' => 'Prosedür',
        'instruction' => 'Talimat',
        'form' => 'Form',
        'guideline' => 'Yönerge',
    ][$type] ?? 'Prosedür';
}

/** AI icin sistem talimatı (QMS şablon iskeleti). */
function qmsAiDocSystemPrompt(): string
{
    return "Sen kalite yönetim sistemi (QMS) doküman yazım uzmanısın. Kullanıcının istediği bir doküman için profesyonel, standart bir taslak üret. "
        . "Çıktı temiz ve düzenli olsun; numaralı bölümler kullan: 1. AMAÇ, 2. KAPSAM, 3. TANIMLAR, 4. SORUMLULUKLAR, 5. UYGULAMA, 6. KAYITLAR, 7. GÖZDEN GEÇİRME VE ONAY. "
        . "Türkçe yaz. Başlık ve doküman bilgisi en üstte olsun (doküman adı, tür, revizyon, tarih). "
        . "Gereksiz bilgi uydurma; kullanıcının verdiği bilgiyi kullan, eksikse '(...)' ile doldurulacak yer bırak.";
}
