<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/access.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/checklist-template-functions.php';

$templateId = (int) ($_GET["id"] ?? 0);
if ($templateId <= 0) {
    header("Location: checklist-templates.php");
    exit;
}

$userId = (int) ($_SESSION["qms_user_id"] ?? 0);
$role = qmsCurrentRole();
$csrfScope = 'checklist_template_detail';

// Kapsamli okuma: id degistirilerek baska sirketin sablonu acilamaz.
$template = qmsChecklistTemplateFind($pdo, $templateId, $userId, $role);
if (!$template) {
    header("Location: checklist-templates.php");
    exit;
}
$companyId = (int) $template["company_id"];

$formError = "";
$formSuccess = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    qmsCsrfVerify($csrfScope, $_POST["csrf"] ?? null);
    $formType = (string) ($_POST["form_type"] ?? "");
    $redirect = "checklist-template-detail.php?id=" . $templateId;

    if ($formType === "update_template") {
        $title = trim((string) ($_POST["title"] ?? ""));
        $description = trim((string) ($_POST["description"] ?? ""));
        if ($title === "") {
            $formError = "Lütfen şablon başlığını girin.";
        } else {
            $pdo->prepare("UPDATE audit_checklist_templates SET title = ?, description = ? WHERE id = ?")
                ->execute([mb_substr($title, 0, 255), $description !== "" ? mb_substr($description, 0, 4000) : null, $templateId]);
            $template["title"] = $title;
            $template["description"] = $description;
            $formSuccess = "Şablon güncellendi.";
        }
    } elseif ($formType === "add_item") {
        $itemText = trim((string) ($_POST["item_text"] ?? ""));
        $requirementRef = trim((string) ($_POST["requirement_ref"] ?? ""));
        if ($itemText === "") {
            $formError = "Lütfen kontrol maddesini girin.";
        } else {
            $stmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order),0) AS m FROM audit_checklist_template_items WHERE template_id = ?");
            $stmt->execute([$templateId]);
            $sort = (int) $stmt->fetchColumn() + 1;
            $pdo->prepare("INSERT INTO audit_checklist_template_items (template_id, item_text, requirement_ref, sort_order, active) VALUES (?,?,?,?,1)")
                ->execute([$templateId, mb_substr($itemText, 0, 255), $requirementRef !== "" ? mb_substr($requirementRef, 0, 120) : null, $sort]);
            header("Location: " . $redirect . "&item=added");
            exit;
        }
    } elseif ($formType === "remove_item") {
        $itemId = (int) ($_POST["item_id"] ?? 0);
        if ($itemId > 0) {
            $pdo->prepare("UPDATE audit_checklist_template_items SET active = 0 WHERE id = ? AND template_id = ?")
                ->execute([$itemId, $templateId]);
            header("Location: " . $redirect . "&item=removed");
            exit;
        }
        $formError = "Madde silinemedi.";
    } else {
        $formError = "Geçersiz istek.";
    }
}

$templateItems = qmsChecklistTemplateItems($pdo, $templateId);
$activeNav = "checklist_templates";

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f9fafb">
    <title>QMS Kontrol Listesi Şablon Detayı</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar">
        <div class="topbar-inner">
            <div class="page-title-block">
                <strong data-i18n="checklistTemplateDetailTitle">Kontrol Listesi Şablon Detayı</strong>
                <span><?= htmlspecialchars($template["company_name"], ENT_QUOTES, "UTF-8") ?> · <?= htmlspecialchars($template["title"], ENT_QUOTES, "UTF-8") ?></span>
            </div>
            <div class="topbar-actions">
                <button class="topbar-button" id="languageToggle" type="button">EN</button>
                <button class="topbar-button" id="themeToggle" type="button" aria-label="Tema değiştir">🌙</button>
            </div>
        </div>
    </header>
    <main class="page-container">
        <section class="page-heading page-heading-actions">
            <div>
                <span class="section-kicker" data-i18n="checklistTemplateKicker">Denetim Planlama</span>
                <h1 data-i18n="checklistTemplateDetailTitle">Kontrol Listesi Şablon Detayı</h1>
                <p><?= htmlspecialchars($template["title"], ENT_QUOTES, "UTF-8") ?> · <?= (int) $template["item_count"] ?> <span data-i18n="checklistTemplateItemsLabel">madde</span></p>
            </div>
            <a class="secondary-button" href="checklist-templates.php" data-i18n="backToTemplatesButton">Şablonlara Dön</a>
        </section>

        <?php if ($formSuccess !== ""): ?>
            <div class="form-message success"><?= htmlspecialchars($formSuccess, ENT_QUOTES, "UTF-8") ?></div>
        <?php endif; ?>
        <?php if ($formError !== ""): ?>
            <div class="form-message error"><?= htmlspecialchars($formError, ENT_QUOTES, "UTF-8") ?></div>
        <?php endif; ?>
        <?php if (($_GET["item"] ?? "") === "added"): ?>
            <div class="form-message success" data-i18n="checklistTemplateItemAddedMessage">Madde eklendi.</div>
        <?php endif; ?>
        <?php if (($_GET["item"] ?? "") === "removed"): ?>
            <div class="form-message success" data-i18n="checklistTemplateItemRemovedMessage">Madde kaldırıldı.</div>
        <?php endif; ?>

        <section class="form-panel">
            <div class="section-heading compact-heading">
                <div>
                    <h3 data-i18n="checklistTemplateInfoTitle">Şablon Bilgileri</h3>
                    <p data-i18n="checklistTemplateInfoText">Şablon adını ve açıklamasını düzenleyin.</p>
                </div>
            </div>
            <form class="auditor-form" method="post" action="checklist-template-detail.php?id=<?= $templateId ?>">
                <?= qmsCsrfField($csrfScope) ?>
                <input type="hidden" name="form_type" value="update_template">
                <div class="form-grid">
                    <label class="form-field">
                        <span data-i18n="checklistTemplateTitleLabel">Şablon Başlığı</span>
                        <input type="text" name="title" value="<?= htmlspecialchars($template["title"], ENT_QUOTES, "UTF-8") ?>" required maxlength="255">
                    </label>
                    <label class="form-field form-field-wide">
                        <span data-i18n="checklistTemplateDescriptionLabel">Açıklama</span>
                        <textarea name="description" rows="3"><?= htmlspecialchars((string) ($template["description"] ?? ""), ENT_QUOTES, "UTF-8") ?></textarea>
                    </label>
                </div>
                <div class="form-actions">
                    <button class="primary-button" type="submit" data-i18n="saveChecklistTemplateButton">Şablonu Kaydet</button>
                </div>
            </form>
        </section>

        <section class="console-card checklist-section">
            <div class="section-heading compact-heading">
                <div>
                    <h3 data-i18n="checklistTemplateItemsTitle">Kontrol Maddeleri</h3>
                    <p><span data-i18n="filteredRecordsLabel">Toplam madde</span>: <strong><?= count($templateItems) ?></strong></p>
                </div>
            </div>

            <form class="auditor-form item-add-form" method="post" action="checklist-template-detail.php?id=<?= $templateId ?>">
                <?= qmsCsrfField($csrfScope) ?>
                <input type="hidden" name="form_type" value="add_item">
                <div class="form-grid">
                    <label class="form-field">
                        <span data-i18n="checklistItemTextLabel">Madde Metni</span>
                        <input type="text" name="item_text" required maxlength="255">
                    </label>
                    <label class="form-field">
                        <span data-i18n="requirementRefLabel">Referans / Madde</span>
                        <input type="text" name="requirement_ref" maxlength="120">
                    </label>
                </div>
                <div class="form-actions">
                    <button class="secondary-button" type="submit" data-i18n="addChecklistItemButton">Madde Ekle</button>
                </div>
            </form>

            <div class="admin-list">
                <?php if (!$templateItems): ?>
                    <div class="empty-state" data-i18n="noChecklistItemsText">Bu şablonda henüz kontrol maddesi yok.</div>
                <?php endif; ?>
                <?php foreach ($templateItems as $item): ?>
                    <div class="admin-list-item">
                        <div class="list-item-main">
                            <strong><?= htmlspecialchars($item["item_text"], ENT_QUOTES, "UTF-8") ?></strong>
                            <span><?= htmlspecialchars($item["requirement_ref"] ?: "-", ENT_QUOTES, "UTF-8") ?></span>
                        </div>
                        <div class="list-item-side">
                            <form method="post" action="checklist-template-detail.php?id=<?= $templateId ?>" onsubmit="return confirm('Maddeyi kaldırsın mı?');">
                                <?= qmsCsrfField($csrfScope) ?>
                                <input type="hidden" name="form_type" value="remove_item">
                                <input type="hidden" name="item_id" value="<?= (int) $item["id"] ?>">
                                <button class="danger-button danger-button-sm" type="submit" data-i18n="removeChecklistItemButton">Kaldır</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    </main>
    <script src="assets/js/theme.js"></script>
    <script src="assets/js/language.js"></script>
    <script src="assets/js/sidebar.js"></script>
    <script src="assets/js/pwa.js"></script>
</body>
</html>
