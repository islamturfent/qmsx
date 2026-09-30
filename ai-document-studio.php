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

$userId = (int) ($_SESSION["qms_user_id"] ?? 0);
$csrfScope = 'ai_studio';

$docTypes = ['policy', 'procedure', 'instruction', 'guideline', 'form', 'plan', 'checklist', 'specification', 'report'];
$formError = '';
$resultText = '';
$usedFallback = false;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    qmsCsrfVerify($csrfScope, $_POST["csrf"] ?? null);
    if (($_POST["form_type"] ?? "") === "generate") {
        $type = in_array((string) ($_POST["type"] ?? ''), $docTypes, true) ? (string) $_POST["type"] : 'procedure';
        $title = trim((string) ($_POST["title"] ?? ''));
        $description = trim((string) ($_POST["description"] ?? ''));

        if ($title === '' && $description === '') {
            $formError = 'Lütfen en az başlık veya açıklama girin (sesle de söyleyebilirsiniz).';
        } else {
            $userPrompt = 'Doküman türü: ' . qmsAiDocTypeLabel($type) . "\n"
                . 'Başlık: ' . ($title !== '' ? $title : '(otomatik)') . "\n"
                . 'Açıklama: ' . ($description !== '' ? $description : '(verilmedi)') . "\n\n"
                . 'Lütfen bu dokümanın tam taslağını üret.';

            $res = qmsAiChat(qmsAiDocSystemPrompt(), $userPrompt);
            if ($res['ok']) {
                $resultText = $res['text'];
            } else {
                $usedFallback = true;
                $resultText = qmsAiFallbackDocument($type, $title, $description);
                $formError = 'Yapay zeka çağrısı yapılamadı; offline şablon taslağı üretildi. Neden: ' . $res['error'];
            }
        }
    }
}

$aiReady = qmsAiAvailable();
$activeNav = "ai_studio";

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="theme-color" content="#f9fafb">
    <title>QuAmi AI Doküman Stüdyosu</title><link rel="manifest" href="manifest.webmanifest"><link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml"><link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar"><div class="topbar-inner"><div class="page-title-block"><strong data-i18n="aiStudioTitle">AI Doküman Stüdyosu</strong><span data-i18n="aiStudioText">Yapay zeka ile doküman taslağı oluşturun; sesle komut verin.</span></div><div class="topbar-actions"><button class="topbar-button" id="languageToggle" type="button">EN</button><button class="topbar-button" id="themeToggle" type="button" aria-label="Tema değiştir">🌙</button></div></div></header>
    <main class="page-container">
        <section class="page-heading page-heading-actions">
            <div>
                <span class="section-kicker" data-i18n="aiStudioKicker">Yapay Zeka</span>
                <h1 data-i18n="aiStudioTitle">AI Doküman Stüdyosu</h1>
                <p data-i18n="aiStudioText">Dokümanı tarif edin (yazıyla ya da sesle) ve taslağı üretin. AI kapalıysa offline şablon motoru devreye girer.</p>
            </div>
            <span class="status-pill <?= $aiReady ? 'on-track' : 'no-target' ?>"><?= $aiReady ? 'AI AÇIK (gpt-4o-mini)' : 'AI KAPALI (şablon modu)' ?></span>
        </section>

        <?php if ($formError !== ""): ?><div class="form-message error"><?= htmlspecialchars($formError, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

        <div class="two-col">
            <section class="form-panel">
                <div class="section-heading compact-heading"><div><h3 data-i18n="aiStudioPromptTitle">Dokümanı Tarif Et</h3><p data-i18n="aiStudioPromptText">Mikrofon ile söyleyin veya yazın; türü seçip üretin.</p></div></div>
                <form class="auditor-form" method="post" action="ai-document-studio.php">
                    <?= qmsCsrfField($csrfScope) ?>
                    <input type="hidden" name="form_type" value="generate">
                    <div class="form-grid">
                        <label class="form-field"><span data-i18n="aiStudioTypeLabel">Doküman Türü</span>
                            <select name="type">
                                <?php foreach ($docTypes as $dt): ?><option value="<?= $dt ?>" <?= (($_POST['type'] ?? 'procedure') === $dt) || (($_POST['type'] ?? '') === '' && $dt === 'procedure') ? 'selected' : '' ?>><?= htmlspecialchars(qmsAiDocTypeLabel($dt), ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?>
                            </select>
                        </label>
                        <label class="form-field"><span data-i18n="aiStudioTitleLabel">Başlık (isteğe bağlı)</span><input type="text" name="title" value="<?= htmlspecialchars((string) ($_POST['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="örn. Kalite Politikası"></label>
                        <label class="form-field form-field-wide"><span data-i18n="aiStudioDescLabel">Açıklama / Konu</span>
                            <div class="voice-field">
                                <textarea name="description" id="aiPrompt" rows="5" placeholder="örn. Sürdürülebilirlik odaklı bir kalite politikası oluştur..."><?= htmlspecialchars((string) ($_POST['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                                <button type="button" class="voice-mic" id="voiceBtn" aria-label="Sesle yaz">🎙</button>
                            </div>
                            <span id="voiceStatus" class="muted-color" data-i18n="aiStudioVoiceHint">Mikrofonla konuşarak yazdırabilirsiniz.</span>
                        </label>
                    </div>
                    <div class="form-actions">
                        <button class="primary-button" type="submit" data-i18n="aiStudioGenerate">Taslağı Üret</button>
                    </div>
                </form>
            </section>

            <section class="console-card checklist-section">
                <div class="section-heading compact-heading">
                    <div><h3 data-i18n="aiStudioResultTitle">Üretilen Taslak</h3></div>
                    <button class="secondary-button" type="button" id="copyBtn" data-i18n="aiStudioCopy">Kopyala</button>
                </div>
                <?php if ($usedFallback): ?><div class="form-message warning">DİKKAT: Bu taslak offline şablon motoruyla üretildi (AI etkin değil).</div><?php endif; ?>
                <textarea id="aiResult" class="result-editor" rows="18" placeholder="<?= htmlspecialchars($aiReady ? 'Taslak burada görünecek...' : 'Yapay zeka şu an kapalı; şablon taslağı burada görünecek...', ENT_QUOTES, 'UTF-8') ?>" readonly><?= htmlspecialchars($resultText, ENT_QUOTES, 'UTF-8') ?></textarea>
            </section>
        </div>
    </main>

    <script>
    (function () {
        var voiceBtn = document.getElementById("voiceBtn");
        var prompt = document.getElementById("aiPrompt");
        var status = document.getElementById("voiceStatus");
        var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
        if (!SR || !voiceBtn || !prompt) {
            if (voiceBtn) { voiceBtn.hidden = true; }
            if (status) { status.textContent = "Tarayıcınız ses tanımayı desteklemiyor (Chrome/Edge önerilir)."; }
            return;
        }
        var rec = new SR();
        rec.lang = "tr-TR";
        rec.continuous = true;
        rec.interimResults = true;
        var active = false;
        voiceBtn.addEventListener("click", function () {
            if (active) { rec.stop(); return; }
            prompt.focus();
            try { rec.start(); } catch (e) { /* zaten basladi */ }
        });
        rec.onstart = function () { active = true; voiceBtn.classList.add("is-listening"); if (status) status.textContent = "Dinleniyor... konuşun. Bitirince tekrar tıklayın."; };
        rec.onend = function () { active = false; voiceBtn.classList.remove("is-listening"); if (status) status.textContent = "Kaydedildi. Düzenleyebilir veya yeni komut verebilirsiniz."; };
        rec.onerror = function (e) { if (status) status.textContent = "Ses hatası: " + (e.error || ""); voiceBtn.classList.remove("is-listening"); };
        rec.onresult = function (e) {
            var transcript = "";
            for (var i = e.resultIndex; i < e.results.length; i++) {
                transcript += e.results[i][0].transcript;
            }
            prompt.value = transcript;
        };
    })();

    document.getElementById("copyBtn").addEventListener("click", function () {
        var ta = document.getElementById("aiResult");
        if (!ta.value) return;
        ta.select();
        ta.setSelectionRange(0, ta.value.length);
        try { navigator.clipboard.writeText(ta.value); } catch (e) { document.execCommand("copy"); }
    });
    </script>
    <script src="assets/js/theme.js"></script><script src="assets/js/language.js"></script><script src="assets/js/sidebar.js"></script><script src="assets/js/pwa.js"></script>
</body>
</html>
