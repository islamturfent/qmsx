<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/ai.php';
require_once __DIR__ . '/includes/access.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/ai-functions.php';

$role = (string) ($_SESSION["qms_role"] ?? "");
if ($role !== "super_admin" && $role !== "system_admin") {
    http_response_code(403);
    exit("Yetkisiz erişim.");
}

$csrfScope = 'ai_settings';
$config = qmsAiConfig();
$settingsPath = __DIR__ . '/storage/ai/settings.json';
$message = "";
$messageType = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    qmsCsrfVerify($csrfScope, $_POST["csrf"] ?? null);
    $formType = (string) ($_POST["form_type"] ?? "");

    if ($formType === "save") {
        $saved = qmsAiDefaults();
        $saved['enabled'] = isset($_POST["enabled"]);
        $saved['provider'] = 'openai';
        $newKey = trim((string) ($_POST["api_key"] ?? ""));
        if ($newKey !== "") {
            $saved['api_key'] = $newKey;
        } else {
            $saved['api_key'] = $config['api_key'] ?? '';
        }
        $saved['model'] = trim((string) ($_POST["model"] ?? 'gpt-4o-mini')) !== '' ? trim((string) $_POST["model"]) : 'gpt-4o-mini';
        $saved['whisper_model'] = trim((string) ($_POST["whisper_model"] ?? 'whisper-1')) !== '' ? trim((string) $_POST["whisper_model"]) : 'whisper-1';
        $saved['base_url'] = rtrim(trim((string) ($_POST["base_url"] ?? 'https://api.openai.com/v1')), '/');
        $saved['timeout'] = max(10, min(300, (int) ($_POST["timeout"] ?? 60)));

        $dir = dirname($settingsPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        file_put_contents($settingsPath, json_encode($saved, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        $config = qmsAiConfig();
        $message = "Yapay zeka ayarları kaydedildi.";
        $messageType = "success";
    } elseif ($formType === "test") {
        $r = qmsAiChat("Kısa ve öz cevap ver.", "Bağlantı testi: sadece 'OK' yaz.");
        $message = $r['ok'] ? "AI bağlantısı çalışıyor: " . mb_substr($r['text'], 0, 80) : "AI bağlantı testi başarısız: " . $r['error'];
        $messageType = $r['ok'] ? "success" : "error";
    }
}

$activeNav = "ai_settings";

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="theme-color" content="#f9fafb">
    <title>QuAmi Yapay Zeka Ayarları</title><link rel="manifest" href="manifest.webmanifest"><link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml"><link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar"><div class="topbar-inner"><div class="page-title-block"><strong data-i18n="aiSettingsTitle">Yapay Zeka Ayarları</strong><span data-i18n="aiSettingsText">Doküman stüdyosu ve sesli komut için sağlayıcı bilgileri.</span></div><div class="topbar-actions"><button class="topbar-button" id="languageToggle" type="button">EN</button><button class="topbar-button" id="themeToggle" type="button" aria-label="Tema değiştir">🌙</button></div></div></header>
    <main class="page-container">
        <section class="page-heading page-heading-actions">
            <div>
                <span class="section-kicker" data-i18n="aiSettingsKicker">Sistem Kurulumu</span>
                <h1 data-i18n="aiSettingsTitle">Yapay Zeka Ayarları</h1>
                <p data-i18n="aiSettingsText">Gpt-4o-mini (metin) + Whisper (ses tanıma) sağlayıcısı. Anahtar yalnızca sunucuda saklanır ve asla tarayıcıya gönderilmez.</p>
            </div>
            <span class="status-pill <?= $config['enabled'] && $config['api_key'] ? 'on-track' : 'no-target' ?>"><?= $config['enabled'] && $config['api_key'] ? 'AI AÇIK' : 'AI KAPALI' ?></span>
        </section>

        <?php if ($message !== ""): ?><div class="form-message <?= $messageType ?>"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

        <section class="form-panel">
            <div class="section-heading compact-heading"><div><h3 data-i18n="aiSettingsFormTitle">OpenAI Yapılandırması</h3><p data-i18n="aiSettingsFormText">API anahtarını platform.openai.com'den alın; gpt-4o-mini ve whisper-1 modelleri kullanılır.</p></div></div>
            <form class="auditor-form" method="post" action="ai-settings.php">
                <?= qmsCsrfField($csrfScope) ?>
                <input type="hidden" name="form_type" value="save">
                <div class="form-grid">
                    <label class="form-field form-field-wide"><span class="switch-label"><input type="checkbox" name="enabled" <?= $config['enabled'] ? 'checked' : '' ?>> <span data-i18n="aiEnabledLabel">Yapay zeka asistanını etkinleştir</span></span></label>
                    <label class="form-field form-field-wide"><span data-i18n="aiApiKeyLabel">API Anahtarı</span><input type="password" name="api_key" placeholder="<?= $config['api_key'] ? '•••••••• (kayıtlı)' : 'sk-...' ?>" autocomplete="new-password"></label>
                    <label class="form-field"><span data-i18n="aiModelLabel">Metin Modeli</span><input type="text" name="model" value="<?= htmlspecialchars((string) $config['model'], ENT_QUOTES, 'UTF-8') ?>" placeholder="gpt-4o-mini"></label>
                    <label class="form-field"><span data-i18n="aiWhisperModelLabel">Ses Modeli</span><input type="text" name="whisper_model" value="<?= htmlspecialchars((string) $config['whisper_model'], ENT_QUOTES, 'UTF-8') ?>" placeholder="whisper-1"></label>
                    <label class="form-field form-field-wide"><span data-i18n="aiBaseUrlLabel">API Temel URL</span><input type="text" name="base_url" value="<?= htmlspecialchars((string) $config['base_url'], ENT_QUOTES, 'UTF-8') ?>" placeholder="https://api.openai.com/v1"></label>
                    <label class="form-field"><span data-i18n="aiTimeoutLabel">Zaman Aşımı (sn)</span><input type="number" name="timeout" min="10" max="300" value="<?= (int) $config['timeout'] ?>"></label>
                </div>
                <div class="form-actions">
                    <button class="primary-button" type="submit" data-i18n="aiSettingsSave">Kaydet</button>
                    <a class="secondary-button" href="ai-settings.php?test=1" onclick="this.href='ai-settings.php'">Test</a>
                </div>
            </form>
            <div style="margin-top:14px;">
                <form method="post" action="ai-settings.php"><?= qmsCsrfField($csrfScope) ?><input type="hidden" name="form_type" value="test"><button class="secondary-button" type="submit" data-i18n="aiSettingsTest">Bağlantıyı Test Et</button></form>
            </div>
        </section>
    </main>
    <script src="assets/js/theme.js"></script><script src="assets/js/language.js"></script><script src="assets/js/sidebar.js"></script><script src="assets/js/pwa.js"></script>
</body>
</html>
