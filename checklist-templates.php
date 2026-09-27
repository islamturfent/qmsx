<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/access.php';
qmsRequirePermission('checklist_templates.view');
require_once __DIR__ . '/includes/checklist-template-functions.php';

$userId = (int) ($_SESSION["qms_user_id"] ?? 0);
$role = qmsCurrentRole();

$templates = qmsChecklistTemplateList($pdo, $userId, $role);

$total = count($templates);
$totalItems = 0;
foreach ($templates as $template) {
    $totalItems += (int) $template["item_count"];
}

$activeNav = "checklist_templates";

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f9fafb">
    <title>QuAmi Kontrol Listesi Şablonları</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar">
        <div class="topbar-inner">
            <div class="page-title-block">
                <strong data-i18n="checklistTemplatesTitle">Kontrol Listesi Şablonları</strong>
                <span data-i18n="checklistTemplatesText">Standart kontrol maddelerini şablonlarda toplayın ve denetimlere uygulayın.</span>
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
                <h1 data-i18n="checklistTemplatesTitle">Kontrol Listesi Şablonları</h1>
                <p data-i18n="checklistTemplatesText">Denetçiler standart maddeleri şablonlardan yeniden kullansın.</p>
            </div>
            <a class="secondary-button" href="checklist-template-create.php" data-i18n="createChecklistTemplateButton">Yeni Şablon</a>
        </section>

        <section class="dashboard-grid compact-dashboard-grid">
            <div class="dashboard-card metric-blue">
                <?= appIcon("approvals", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="checklistTemplateTotalLabel">Toplam Şablon</span>
                    <strong class="dashboard-card-number detail-card-value"><?= $total ?></strong>
                </div>
            </div>
            <div class="dashboard-card metric-violet">
                <?= appIcon("check", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="checklistTemplateItemTotalLabel">Toplam Madde</span>
                    <strong class="dashboard-card-number detail-card-value"><?= $totalItems ?></strong>
                </div>
            </div>
        </section>

        <section class="console-card checklist-section">
            <div class="section-heading compact-heading">
                <div>
                    <h3 data-i18n="checklistTemplateListTitle">Şablonlar</h3>
                    <p><span data-i18n="filteredRecordsLabel">Gösterilen kayıt</span>: <strong><?= $total ?></strong></p>
                </div>
            </div>
            <div class="admin-list">
                <?php if (!$templates): ?>
                    <div class="empty-state" data-i18n="noChecklistTemplatesText">Henüz kontrol listesi şablonu oluşturulmadı.</div>
                <?php endif; ?>
                <?php foreach ($templates as $template): ?>
                    <a class="admin-list-item list-link" href="checklist-template-detail.php?id=<?= (int) $template["id"] ?>">
                        <div class="list-item-main">
                            <strong><?= htmlspecialchars($template["title"], ENT_QUOTES, "UTF-8") ?></strong>
                            <span><?= htmlspecialchars($template["company_name"], ENT_QUOTES, "UTF-8") ?></span>
                        </div>
                        <div class="list-item-side">
                            <span class="list-item-count"><?= (int) $template["item_count"] ?> <span data-i18n="checklistTemplateItemsLabel">madde</span></span>
                        </div>
                    </a>
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
