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
require_once __DIR__ . '/includes/document-editor.php';
require_once __DIR__ . '/includes/document-template-functions.php';

$userId = (int) ($_SESSION["qms_user_id"] ?? 0);
$csrfScope = 'ai_studio';

$docTypes = ['policy', 'procedure', 'instruction', 'guideline', 'form', 'plan', 'checklist', 'specification', 'report'];

// Görünür şirketler (dokümana aktarma / şablon kaydetme için).
$companyScope = qmsCompanyScope('companies.id', qmsVisibleCompanyIds($pdo, $userId, qmsCurrentRole()));
$companyStmt = $pdo->prepare("SELECT companies.id, companies.company_name FROM companies WHERE companies.active = 1" . $companyScope['sql'] . " ORDER BY companies.company_name");
$companyStmt->execute($companyScope['params']);
$companies = $companyStmt->fetchAll(PDO::FETCH_ASSOC);
$allowedCompanyIds = array_map('intval', array_column($companies, 'id'));

$formError = '';
$resultText = '';
$usedFallback = false;
$transferredId = 0;

/** AI taslağı (düz metin) -> güvenli HTML. */
function qmsAiDraftToHtml(string $text): string
{
    $out = '';
    $lines = preg_split('/\r?\n/', $text) ?: [];
    foreach ($lines as $line) {
        $line = rtrim($line);
        if ($line === '') {
            continue;
        }
        $esc = htmlspecialchars($line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        if (preg_match('/^\s*(\d+)\.(\d+)\s/', $line)) {
            $out .= '<h3>' . $esc . '</h3>';
        } elseif (preg_match('/^\s*\d+\.\s+/', $line)) {
            $out .= '<h2>' . $esc . '</h2>';
        } elseif (strpos($line, '•') === 0) {
            $out .= '<li>' . htmlspecialchars(ltrim($line, "• \t"), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</li>';
        } else {
            $out .= '<p>' . $esc . '</p>';
        }
    }
    return $out;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    qmsCsrfVerify($csrfScope, $_POST["csrf"] ?? null);
    $formType = (string) ($_POST["form_type"] ?? "");

    if ($formType === "generate") {
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
    } elseif ($formType === "transfer") {
        // A) Taslasi dogrudan yeni bir dokumana aktar.
        $companyId = (int) ($_POST["company_id"] ?? 0);
        $code = strtoupper(trim((string) ($_POST["document_code"] ?? "")));
        $title = trim((string) ($_POST["title"] ?? ""));
        $content = (string) ($_POST["result_text"] ?? "");
        if ($companyId <= 0 || !in_array($companyId, $allowedCompanyIds, true)) {
            $formError = "Geçerli bir şirket seçin.";
        } elseif ($code === "" || $title === "") {
            $formError = "Doküman kodu ve başlık zorunludur.";
        } elseif ($content === "") {
            $formError = "Önce bir taslak üretin.";
        } else {
            $html = qmsAiDraftToHtml($content);
            $fileBody = '<!doctype html><html lang="tr"><head><meta charset="UTF-8"><title>QuAmi</title></head><body>' . $html . '</body></html>';
            $name = bin2hex(random_bytes(20)) . '.html';
            $storageDir = __DIR__ . '/storage/documents';
            $path = $storageDir . '/' . $name;
            try {
                $pdo->beginTransaction();
                $ins = $pdo->prepare(
                    "INSERT INTO documents (company_id, document_code, title, status, current_revision, active, created_by)
                     VALUES (?,?,?,'draft','01',1,?)"
                );
                $ins->execute([$companyId, $code, mb_substr($title, 0, 255), $userId ?: null]);
                $docId = (int) $pdo->lastInsertId();
                if (file_put_contents($path, $fileBody, LOCK_EX) !== strlen($fileBody)) {
                    throw new RuntimeException('Doküman dosyası kaydedilemedi.');
                }
                $origName = $code . '.html';
                $vst = $pdo->prepare(
                    "INSERT INTO document_versions (document_id, revision_number, original_file_name, stored_file_name, mime_type, file_size, change_note, uploaded_by)
                     VALUES (?,?,?,?,'text/html',?,'AI Doküman Stüdyosu',?)"
                );
                $vst->execute([$docId, '01', $origName, $name, strlen($fileBody), $userId ?: null]);
                $pdo->prepare("UPDATE documents SET current_revision = '01' WHERE id = ?")->execute([$docId]);
                $pdo->commit();
                $transferredId = $docId;
                header("Location: document-detail.php?id=" . $docId . "&created=1");
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if (isset($path) && is_file($path)) {
                    @unlink($path);
                }
                $formError = "Dokümana aktarılamadı: " . $e->getMessage();
            }
        }
    } elseif ($formType === "save_template") {
        // C) Taslasi dokuman şablonu olarak kaydet.
        $companyId = (int) ($_POST["company_id"] ?? 0);
        $name = trim((string) ($_POST["title"] ?? ""));
        $category = trim((string) ($_POST["category"] ?? ""));
        $templateType = in_array((string) ($_POST["template_type"] ?? ''), QMS_DOCUMENT_TEMPLATE_TYPES, true) ? (string) $_POST["template_type"] : 'procedure';
        $content = (string) ($_POST["result_text"] ?? "");
        if ($companyId <= 0 || !in_array($companyId, $allowedCompanyIds, true)) {
            $formError = "Geçerli bir şirket seçin.";
        } elseif ($name === "") {
            $formError = "Şablon adı zorunludur.";
        } elseif ($content === "") {
            $formError = "Önce bir taslak üretin.";
        } else {
            $data = [
                'company_id' => $companyId,
                'name' => $name,
                'document_type' => $templateType,
                'category' => $category,
                'content_html' => qmsAiDraftToHtml($content),
            ];
            $tid = qmsDocumentTemplateAdd($pdo, $data, $userId, qmsCurrentRole());
            if ($tid !== null) {
                header("Location: document-templates.php?created=1");
                exit;
            }
            $formError = "Şablon kaydedilemedi.";
        }
    }
}

$aiReady = qmsAiAvailable();
// Şablon kaydetme türü varsayılanı, stüdyo doküman türünden türetilir.
$defaultTemplateType = 'procedure';
$selectedType = (string) ($_POST['type'] ?? 'procedure');
$templateTypeMap = [
    'policy' => 'policy', 'procedure' => 'procedure', 'instruction' => 'work_instruction',
    'form' => 'form', 'guideline' => 'other', 'plan' => 'other', 'checklist' => 'other',
    'specification' => 'other', 'report' => 'other',
];
if (isset($templateTypeMap[$selectedType])) {
    $defaultTemplateType = $templateTypeMap[$selectedType];
}
$activeNav = "ai_studio";
$templateTypes = qmsDocumentTemplateTypeLabels();

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
                <p data-i18n="aiStudioText">Dokümanı tarif edin (yazıyla ya da sesle) ve taslağı üretin; ürettiğiniz taslağı dokümana veya şablona aktarın.</p>
            </div>
            <span class="status-pill <?= $aiReady ? 'on-track' : 'no-target' ?>"><?= $aiReady ? 'AI AÇIK (gpt-oss-20b)' : 'AI KAPALI (şablon modu)' ?></span>
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
                            <select name="type" id="aiDocType">
                                <?php foreach ($docTypes as $dt): ?><option value="<?= $dt ?>" <?= ((($_POST['type'] ?? 'procedure') === $dt) || (($_POST['type'] ?? '') === '' && $dt === 'procedure')) ? 'selected' : '' ?>><?= htmlspecialchars(qmsAiDocTypeLabel($dt), ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?>
                            </select>
                        </label>
                        <label class="form-field"><span data-i18n="aiStudioTitleLabel">Başlık (isteğe bağlı)</span><input type="text" name="title" id="aiTitle" value="<?= htmlspecialchars((string) ($_POST['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="örn. Kalite Politikası"></label>
                        <label class="form-field form-field-wide"><span data-i18n="aiStudioDescLabel">Açıklama / Konu</span>
                            <div class="voice-field">
                                <textarea name="description" id="aiPrompt" rows="5" placeholder="örn. Sürdürülebilirlik odaklı bir kalite politikası oluştur..."><?= htmlspecialchars((string) ($_POST['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                                <button type="button" class="voice-mic" id="voiceBtn" aria-label="Sesle yaz">🎙</button>
                            </div>
                            <span id="voiceStatus" class="muted-color" data-i18n="aiStudioVoiceHint">Mikrofonla konuşarak yazdırabilirsiniz. Komut verirseniz tür/başlık otomatik seçilir.</span>
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
                <textarea id="aiResult" class="result-editor" rows="18" readonly><?= htmlspecialchars($resultText, ENT_QUOTES, 'UTF-8') ?></textarea>
            </section>
        </div>

        <?php if ($resultText !== ""): ?>
        <section class="console-card" style="margin-top:20px;">
            <div class="section-heading compact-heading"><div><h3 data-i18n="aiTransferTitle">Taslağı Kaydet</h3><p data-i18n="aiTransferText">Üretilen taslağı yeni bir dokümana veya doküman şablonuna aktarın.</p></div></div>
            <?php if (!$companies): ?>
                <div class="empty-state">Önce bir şirket gerekir.</div>
            <?php else: ?>
            <form class="auditor-form" method="post" action="ai-document-studio.php">
                <?= qmsCsrfField($csrfScope) ?>
                <input type="hidden" name="form_type" value="transfer">
                <input type="hidden" name="result_text" value="<?= htmlspecialchars($resultText, ENT_QUOTES, 'UTF-8') ?>">
                <div class="form-grid">
                    <label class="form-field"><span data-i18n="companySelectLabel">Şirket</span><select name="company_id" required><option value="0">Şirket seçin</option><?php foreach ($companies as $company): ?><option value="<?= (int) $company['id'] ?>"><?= htmlspecialchars($company['company_name'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></label>
                    <label class="form-field"><span data-i18n="aiTransferCodeLabel">Doküman Kodu</span><input type="text" name="document_code" required placeholder="örn. POL-001"></label>
                    <label class="form-field"><span data-i18n="aiStudioTitleLabel">Başlık</span><input type="text" name="title" required value="<?= htmlspecialchars((string) ($_POST['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="örn. Kalite Politikası"></label>
                    <label class="form-field"><span data-i18n="documentCategoryLabel">Kategori (isteğe bağlı)</span><input type="text" name="category" placeholder="örn. Yönetim"></label>
                    <label class="form-field"><span data-i18n="aiTemplateTypeLabel">Şablon Türü (şablon kaydı için)</span>
                        <select name="template_type">
                            <?php foreach ($templateTypes as $tk => $tl): ?><option value="<?= $tk ?>" <?= $defaultTemplateType === $tk ? 'selected' : '' ?>><?= htmlspecialchars($tl, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?>
                        </select>
                    </label>
                </div>
                <div class="form-actions">
                    <button class="primary-button" type="submit" data-i18n="aiTransferButton">Dokümana Aktar</button>
                    <a class="secondary-button" href="#" onclick="event.preventDefault();var f=this.closest('form');f.querySelector('[name=form_type]').value='save_template';f.submit();" data-i18n="aiSaveTemplateButton">Şablon Olarak Kaydet</a>
                </div>
            </form>
            <?php endif; ?>
        </section>
        <?php endif; ?>
    </main>

    <script>
    (function () {
        var voiceBtn = document.getElementById("voiceBtn");
        var prompt = document.getElementById("aiPrompt");
        var status = document.getElementById("voiceStatus");
        var typeSelect = document.getElementById("aiDocType");
        var titleInput = document.getElementById("aiTitle");
        var SR = window.SpeechRecognition || window.webkitSpeechRecognition;

        // B) Sesli komut ayrıştırıcı: tür + başlık + açıklama + otomatik üretim.
        var typeMap = { "politika":"policy", "prosedür":"procedure", "prosedur":"procedure", "proses":"procedure",
            "talimat":"instruction", "yönerge":"guideline", "yonerge":"guideline", "kılavuz":"guideline",
            "form":"form", "kayıt formu":"form", "plan":"plan", "kontrol listesi":"checklist",
            "checklist":"checklist", "şartname":"specification", "sartname":"specification", "rapor":"report" };
        var submitted = false;
        // Komut tetikleyici kelimeler. 'üretim' gibi sözcüklerin parçasıysa
        // tetiklenmez (Türkçe harflerle kelime sınırı kontrolü).
        function isTriggerWord(lower) {
            var triggers = ["üret", "uret", "oluştur", "olustur", "üretin", "uretin", "başlat", "baslat", "başla", "basla", "tamam"];
            for (var i = 0; i < triggers.length; i++) {
                var re = new RegExp("(^|[^a-zçğıöşü])" + triggers[i] + "([^a-zçğıöşü]|$)", "i");
                if (re.test(lower)) return true;
            }
            return false;
        }
        function submitGenerate() {
            var hidden = document.querySelector('form input[name="form_type"][value="generate"]');
            if (!hidden) return false;
            var form = hidden.closest('form');
            if (form) { form.submit(); return true; }
            return false;
        }
        function parseVoice(t) {
            if (!typeSelect) return;
            var lower = t.toLowerCase();
            var chosen = "";
            for (var k in typeMap) {
                if (lower.indexOf(k) >= 0) { chosen = typeMap[k]; break; }
            }
            if (chosen) typeSelect.value = chosen;
            var m = t.match(/başlık(?:ı|i)?\s*[:\-]?\s*([^,.;]+)/i);
            if (m && titleInput) titleInput.value = m[1].trim();
            prompt.value = t;
        }

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
            try { rec.start(); } catch (e) { }
        });
        rec.onstart = function () { active = true; voiceBtn.classList.add("is-listening"); if (status) status.textContent = "Dinleniyor... konuşun. Bitirince 'üret' deyin."; };
        rec.onend = function () {
            active = false;
            voiceBtn.classList.remove("is-listening");
            if (prompt.value) { parseVoice(prompt.value); }
            if (status && !submitted) { status.textContent = "Komut algılandı; tür/başlık otomatik işlendi. 'üret' dediyseniz taslak üretilir."; }
        };
        rec.onerror = function (e) { if (status) status.textContent = "Ses hatası: " + (e.error || ""); voiceBtn.classList.remove("is-listening"); };
        rec.onresult = function (e) {
            if (submitted) return;
            var transcript = "";
            var sawFinal = false;
            for (var i = e.resultIndex; i < e.results.length; i++) {
                transcript += e.results[i][0].transcript;
                if (e.results[i].isFinal) sawFinal = true;
            }
            prompt.value = transcript;
            // Kullanıcı 'üret/oluştur/tamam' dediğinde otomatik üret.
            if (sawFinal && isTriggerWord(transcript)) {
                submitted = true;
                parseVoice(transcript);
                if (status) status.textContent = "Üretiliyor...";
                try { rec.stop(); } catch (err) { }
                submitGenerate();
            }
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
