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
        $provider = in_array((string) ($_POST["provider"] ?? ''), ['openai', 'groq'], true) ? (string) $_POST["provider"] : 'openai';
        $saved['provider'] = $provider;
        $newKey = trim((string) ($_POST["api_key"] ?? ""));
        if ($newKey !== "") {
            $saved['api_key'] = $newKey;
        } else {
            $saved['api_key'] = $config['api_key'] ?? '';
        }

        $presets = qmsAiProviderPresets();
        // Sağlayici DEGISTIYSE (tek tik) preset degerlerini uygula.
        if (($config['provider'] ?? '') !== $provider && isset($presets[$provider])) {
            $saved['base_url'] = $presets[$provider]['base_url'];
            $saved['model'] = $presets[$provider]['model'];
            $saved['whisper_model'] = $presets[$provider]['whisper_model'];
        } else {
            $saved['model'] = trim((string) ($_POST["model"] ?? '')) !== '' ? trim((string) $_POST["model"]) : $saved['model'];
            $saved['whisper_model'] = trim((string) ($_POST["whisper_model"] ?? '')) !== '' ? trim((string) $_POST["whisper_model"]) : $saved['whisper_model'];
            $saved['base_url'] = rtrim(trim((string) ($_POST["base_url"] ?? '')), '/') !== '' ? rtrim(trim((string) $_POST["base_url"]), '/') : $saved['base_url'];
        }
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
    } elseif ($formType === "list_models") {
        $ml = qmsAiListModels();
        if ($ml['ok']) {
            $modelsList = array_values(array_filter($ml['models'], static fn($m): bool => trim((string) $m) !== ''));
            $message = count($modelsList) > 0 ? count($modelsList) . " model bulundu; listeden seçip Kaydet'e basın." : "Hiç model döndürülmedi.";
            $messageType = "success";
        } else {
            $modelsList = [];
            $message = "Model listesi alınamadı: " . $ml['error'];
            $messageType = "error";
        }
    }
}

$activeNav = "ai_settings";

// Son AI hata detayi (gorsellestirme).
$lastError = null;
if (is_file(qmsAiLastErrorPath())) {
    $lastError = json_decode((string) file_get_contents(qmsAiLastErrorPath()), true);
    if (!is_array($lastError)) {
        $lastError = null;
    }
}

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
                <?php $presets = qmsAiProviderPresets(); $provider = (string) ($config['provider'] ?? 'openai'); ?>
                <div class="form-grid">
                    <label class="form-field form-field-wide"><span data-i18n="aiProviderLabel">Sağlayıcı</span>
                        <select name="provider" id="aiProvider">
                            <?php foreach ($presets as $pk => $pv): ?><option value="<?= $pk ?>" <?= $provider === $pk ? 'selected' : '' ?>><?= htmlspecialchars($pv['label'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?>
                        </select>
                    </label>
                    <label class="form-field form-field-wide"><span class="switch-label"><input type="checkbox" name="enabled" <?= $config['enabled'] ? 'checked' : '' ?>> <span data-i18n="aiEnabledLabel">Yapay zeka asistanını etkinleştir</span></span></label>
                    <label class="form-field form-field-wide"><span data-i18n="aiApiKeyLabel">API Anahtarı</span><input type="password" name="api_key" placeholder="<?= $config['api_key'] ? '•••••••• (kayıtlı)' : 'sk-/gsk-...' ?>" autocomplete="new-password"></label>
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
            <div style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap;">
                <form method="post" action="ai-settings.php"><?= qmsCsrfField($csrfScope) ?><input type="hidden" name="form_type" value="test"><button class="secondary-button" type="submit" data-i18n="aiSettingsTest">Bağlantıyı Test Et</button></form>
                <form method="post" action="ai-settings.php"><?= qmsCsrfField($csrfScope) ?><input type="hidden" name="form_type" value="list_models"><button class="secondary-button" type="submit" data-i18n="aiListModelsButton">Modelleri Listele</button></form>
            </div>
            <?php if (!empty($modelsList)): ?>
            <div style="margin-top:14px;">
                <label class="form-field"><span data-i18n="aiPickModelLabel">Erişilebilir Model Seç</span>
                    <select id="aiModelPicker">
                        <option value="" data-i18n="aiPickModelNone">— model seç —</option>
                        <?php foreach ($modelsList as $mid): ?><option value="<?= htmlspecialchars($mid, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($mid, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?>
                    </select>
                </label>
                <div class="form-actions"><button class="primary-button" type="button" id="aiApplyModel" data-i18n="aiApplyModelButton">Bu Modeli Kullan</button></div>
            </div>
            <?php endif; ?>
        </section>

        <section class="console-card checkout-section">
            <div class="section-heading compact-heading"><div><h3 data-i18n="aiErrorDetailTitle">Hata Detayı</h3><p data-i18n="aiErrorDetailText">Son sağlayıcı hatasının ham yanıtı (API anahtarı içermez).</p></div></div>
            <?php if ($lastError): ?>
                <p class="muted-color"><?= htmlspecialchars((string) ($lastError['time'] ?? ''), ENT_QUOTES, 'UTF-8') ?> · HTTP <?= (int) ($lastError['http'] ?? 0) ?></p>
                <textarea class="result-editor" rows="6" readonly><?= htmlspecialchars((string) ($lastError['body'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
            <?php else: ?>
                <div class="empty-state" data-i18n="aiErrorDetailEmpty">Henüz bir sağlayıcı hatası kaydedilmedi.</div>
            <?php endif; ?>
        </section>
    </main>
    <script>
    (function () {
        var presets = <?= json_encode($presets, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        var sel = document.getElementById("aiProvider");
        var model = document.querySelector("input[name=model]");
        var whisper = document.querySelector("input[name=whisper_model]");
        var base = document.querySelector("input[name=base_url]");
        if (!sel || !model || !whisper || !base) return;
        sel.addEventListener("change", function () {
            var p = presets[sel.value];
            if (!p) return;
            model.value = p.model;
            whisper.value = p.whisper_model;
            base.value = p.base_url;
        });

        var applyBtn = document.getElementById("aiApplyModel");
        var picker = document.getElementById("aiModelPicker");
        if (applyBtn && picker && model) {
            applyBtn.addEventListener("click", function () {
                if (!picker.value) return;
                model.value = picker.value;
                var saveForm = document.querySelector("form.auditor-form");
                if (saveForm) saveForm.submit();
            });
        }
    })();
    </script>
    <script src="assets/js/theme.js"></script><script src="assets/js/language.js"></script><script src="assets/js/sidebar.js"></script><script src="assets/js/pwa.js"></script>
</body>
</html>
