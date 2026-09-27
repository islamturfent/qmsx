<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/access.php';
qmsRequirePermission('operations.view');
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/document-template-functions.php';

$userId = (int) ($_SESSION["qms_user_id"] ?? 0);
$role = qmsCurrentRole();
if (qmsIsAuditor()) {
    header("Location: my-audits.php");
    exit;
}

$scope = qmsCompanyScope('companies.id', qmsVisibleCompanyIds($pdo, $userId, $role));
$companyStmt = $pdo->prepare('SELECT companies.id, companies.company_name FROM companies WHERE companies.active = 1' . $scope['sql'] . ' ORDER BY companies.company_name');
$companyStmt->execute($scope['params']);
$companies = $companyStmt->fetchAll(PDO::FETCH_ASSOC);
$allowedCompanyIds = array_map('intval', array_column($companies, 'id'));

$typeLabels = qmsDocumentTemplateTypeLabels();
$csrfScope = 'document_templates';
$formError = '';
$editing = null;
$editId = (int) ($_GET['edit'] ?? 0);
if ($editId > 0) {
    $editing = qmsDocumentTemplateFind($pdo, $editId, $userId, $role);
    if (!$editing) {
        $editId = 0;
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    qmsCsrfVerify($csrfScope, $_POST["csrf"] ?? null);
    $formType = (string) ($_POST["form_type"] ?? "");

    if ($formType === "add" || $formType === "update") {
        $companyId = (int) ($_POST["company_id"] ?? 0);
        if ($formType === "add") {
            $newId = qmsDocumentTemplateAdd($pdo, [
                "company_id" => $companyId,
                "name" => (string) ($_POST["name"] ?? ""),
                "document_type" => (string) ($_POST["document_type"] ?? ""),
                "category" => (string) ($_POST["category"] ?? ""),
                "content_html" => (string) ($_POST["content_html"] ?? ""),
            ], $userId, $role);
            if ($newId !== null) {
                header("Location: document-templates.php?added=1");
                exit;
            }
            $formError = "Şablon eklenemedi. Geçerli bir şirket, ad ve tür girin.";
        } else {
            $ok = qmsDocumentTemplateUpdate($pdo, (int) ($_POST["id"] ?? 0), [
                "name" => (string) ($_POST["name"] ?? ""),
                "document_type" => (string) ($_POST["document_type"] ?? ""),
                "category" => (string) ($_POST["category"] ?? ""),
                "content_html" => (string) ($_POST["content_html"] ?? ""),
            ], $userId, $role);
            if ($ok) {
                header("Location: document-templates.php?updated=1");
                exit;
            }
            $formError = "Şablon güncellenemedi.";
        }
    } elseif ($formType === "delete") {
        qmsDocumentTemplateDelete($pdo, (int) ($_POST["id"] ?? 0), $userId, $role);
        header("Location: document-templates.php?deleted=1");
        exit;
    }
}

$templates = qmsDocumentTemplateList($pdo, $userId, $role);
$activeNav = "document_templates";
$selectedCompanyId = (int) ($_GET["company_id"] ?? 0);
$prefill = $editing ?: ['name' => '', 'document_type' => 'procedure', 'category' => ''];
if ($editing) {
    $selectedCompanyId = (int) $editing['company_id'];
}

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="theme-color" content="#f9fafb">
    <title>QuAmi Doküman Şablonları</title><link rel="manifest" href="manifest.webmanifest"><link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml"><link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar"><div class="topbar-inner"><div class="page-title-block"><strong data-i18n="docTemplateTitle">Doküman Şablonları</strong><span data-i18n="docTemplateText">Yeni dokümanları hızlı başlatmak için hazır şablon kütüphanesi.</span></div><div class="topbar-actions"><button class="topbar-button" id="languageToggle" type="button">EN</button><button class="topbar-button" id="themeToggle" type="button" aria-label="Tema değiştir">🌙</button></div></div></header>
    <main class="page-container">
        <section class="page-heading">
            <div>
                <span class="section-kicker" data-i18n="documentWorkspaceKicker">Doküman Çalışma Alanı</span>
                <h1 data-i18n="docTemplateTitle">Doküman Şablonları</h1>
                <p data-i18n="docTemplateText">Prosedür, politika, talimat ve form şablonlarını tek yerde saklayın ve yeniden kullanın.</p>
            </div>
        </section>

        <?php if ($formError !== ""): ?><div class="form-message error"><?= htmlspecialchars($formError, ENT_QUOTES, "UTF-8") ?></div><?php endif; ?>
        <?php if (($_GET["added"] ?? "") === "1"): ?><div class="form-message success" data-i18n="docTemplateAdded">Şablon eklendi.</div><?php endif; ?>
        <?php if (($_GET["updated"] ?? "") === "1"): ?><div class="form-message success" data-i18n="docTemplateUpdated">Şablon güncellendi.</div><?php endif; ?>
        <?php if (($_GET["deleted"] ?? "") === "1"): ?><div class="form-message success" data-i18n="docTemplateDeleted">Şablon silindi.</div><?php endif; ?>

        <div class="two-col">
            <section class="form-panel">
                <div class="section-heading compact-heading"><div><h3><?= $editing ? 'Şablonu Düzenle' : 'Yeni Şablon' ?></h3><?php if ($editing): ?><p>#<?= (int) $editing['id'] ?> düzenleniyor</p><?php endif; ?></div></div>
                <?php if (!$companies): ?><div class="form-message error" data-i18n="docTemplateNoCompany">Önce bir şirket gerekir.</div>
                <?php else: ?>
                <form class="auditor-form" method="post" action="document-templates.php<?= $editing ? '?edit=' . (int) $editing['id'] : '' ?>">
                    <?= qmsCsrfField($csrfScope) ?>
                    <input type="hidden" name="form_type" value="<?= $editing ? 'update' : 'add' ?>">
                    <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>
                    <div class="form-grid">
                        <label class="form-field"><span data-i18n="companySelectLabel">Şirket</span><select name="company_id" <?= $editing ? 'disabled' : 'required' ?>><option value="0" data-i18n="selectCompanyOption">Şirket seçin</option><?php foreach ($companies as $company): ?><option value="<?= (int) $company['id'] ?>" <?= $selectedCompanyId === (int) $company['id'] ? 'selected' : '' ?>><?= htmlspecialchars($company['company_name'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select><?php if ($editing): ?><input type="hidden" name="company_id" value="<?= (int) $editing['company_id'] ?>"><?php endif; ?></label>
                        <label class="form-field"><span data-i18n="docTemplateTypeLabel">Tür</span><select name="document_type"><?php foreach ($typeLabels as $tk => $tl): ?><option value="<?= $tk ?>" <?= $prefill['document_type'] === $tk ? 'selected' : '' ?>><?= htmlspecialchars($tl, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></label>
                        <label class="form-field"><span data-i18n="docTemplateNameLabel">Şablon Adı</span><input type="text" name="name" required maxlength="160" value="<?= htmlspecialchars((string) $prefill['name'], ENT_QUOTES, 'UTF-8') ?>"></label>
                        <label class="form-field"><span data-i18n="documentCategoryLabel">Kategori (isteğe bağlı)</span><input type="text" name="category" maxlength="120" value="<?= htmlspecialchars((string) $prefill['category'], ENT_QUOTES, 'UTF-8') ?>"></label>
                        <label class="form-field form-field-wide"><span data-i18n="docTemplateContentLabel">İçerik (HTML)</span><textarea name="content_html" rows="10"><?= htmlspecialchars($editing ? (string) $editing['content_html'] : '', ENT_QUOTES, 'UTF-8') ?></textarea></label>
                    </div>
                    <div class="form-actions"><button class="primary-button" type="submit" data-i18n="docTemplateSave">Kaydet</button><?php if ($editing): ?><a class="secondary-button" href="document-templates.php" data-i18n="docTemplateCancel">İptal</a><?php endif; ?></div>
                </form>
                <?php endif; ?>
            </section>

            <section class="console-card checklist-section">
                <div class="section-heading compact-heading"><div><h3 data-i18n="docTemplateListTitle">Şablonlar</h3><p><span data-i18n="filteredRecordsLabel">Gösterilen kayıt</span>: <strong><?= count($templates) ?></strong></p></div></div>
                <div class="admin-list">
                    <?php if (!$templates): ?><div class="empty-state" data-i18n="docTemplateEmpty">Henüz şablon yok.</div><?php endif; ?>
                    <?php foreach ($templates as $tpl): ?>
                        <div class="admin-list-item">
                            <div class="list-item-main">
                                <strong><?= htmlspecialchars($tpl['name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                <span><?= htmlspecialchars($typeLabels[$tpl['document_type']] ?? $tpl['document_type'], ENT_QUOTES, 'UTF-8') ?><?php if ($tpl['category']): ?> · <?= htmlspecialchars($tpl['category'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?></span>
                            </div>
                            <div class="list-item-side">
                                <a class="secondary-button" href="document-templates.php?edit=<?= (int) $tpl['id'] ?>" data-i18n="editButton">Düzenle</a>
                                <form method="post" action="document-templates.php" onsubmit="return confirm('Şablonu silinsin mi?');"><?= qmsCsrfField($csrfScope) ?><input type="hidden" name="form_type" value="delete"><input type="hidden" name="id" value="<?= (int) $tpl['id'] ?>"><button class="danger-button danger-button-sm" type="submit" data-i18n="docTemplateDelete">Sil</button></form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>
    </main>
    <script src="assets/js/theme.js"></script><script src="assets/js/language.js"></script><script src="assets/js/sidebar.js"></script><script src="assets/js/pwa.js"></script>
</body>
</html>
