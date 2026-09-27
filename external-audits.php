<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/access.php';
require_once __DIR__ . '/includes/external-audit-functions.php';

$userId = (int) ($_SESSION["qms_user_id"] ?? 0);
$role = qmsCurrentRole();

$audits = qmsExternalAuditList($pdo, $userId, $role);
$total = count($audits);
$openFindings = 0;
$overdueFindings = 0;
$conducted = 0;
foreach ($audits as $audit) {
    $openFindings += (int) $audit["open_findings"];
    $overdueFindings += (int) $audit["overdue_findings"];
    if ($audit["status"] === "conducted" || $audit["status"] === "closed") {
        $conducted++;
    }
}

$typeLabels = qmsExternalAuditTypeLabels();
$typeI18n = qmsExternalAuditTypeI18nKeys();
$statusLabels = qmsExternalAuditStatusLabels();
$statusI18n = qmsExternalAuditStatusI18nKeys();

$activeNav = "external_audits";

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f9fafb">
    <title>QuAmi Dış Denetim & Kapama Takibi</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar">
        <div class="topbar-inner">
            <div class="page-title-block">
                <strong data-i18n="externalAuditsTitle">Dış Denetim & Kapama Takibi</strong>
                <span data-i18n="externalAuditsText">Dış denetimleri ve bulgu kapamayı izleyin.</span>
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
                <span class="section-kicker" data-i18n="externalAuditsKicker">Uygunluk & Kapama</span>
                <h1 data-i18n="externalAuditsTitle">Dış Denetim & Kapama Takibi</h1>
                <p data-i18n="externalAuditsText">Müşteri, belgelendirme ve mevzuat denetimlerini ve bulgu kapamayı izleyin.</p>
            </div>
            <a class="secondary-button" href="external-audit-create.php" data-i18n="createExternalAuditButton">Yeni Dış Denetim</a>
        </section>

        <section class="dashboard-grid compact-dashboard-grid">
            <div class="dashboard-card metric-blue">
                <?= appIcon("approvals", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="externalAuditTotalLabel">Toplam Denetim</span>
                    <strong class="dashboard-card-number detail-card-value"><?= $total ?></strong>
                </div>
            </div>
            <div class="dashboard-card metric-teal">
                <?= appIcon("check", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="externalAuditConductedLabel">Gerçekleşen</span>
                    <strong class="dashboard-card-number detail-card-value"><?= $conducted ?></strong>
                </div>
            </div>
            <div class="dashboard-card metric-orange">
                <?= appIcon("warning", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="externalFindingOpenLabel">Açık Bulgu</span>
                    <strong class="dashboard-card-number detail-card-value"><?= $openFindings ?></strong>
                </div>
            </div>
            <div class="dashboard-card metric-red">
                <?= appIcon("clock", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="externalFindingOverdueLabel">Geciken Bulgu</span>
                    <strong class="dashboard-card-number detail-card-value"><?= $overdueFindings ?></strong>
                </div>
            </div>
        </section>

        <section class="console-card checklist-section">
            <div class="section-heading compact-heading">
                <div>
                    <h3 data-i18n="externalAuditListTitle">Dış Denetimler</h3>
                    <p><span data-i18n="filteredRecordsLabel">Gösterilen kayıt</span>: <strong><?= $total ?></strong></p>
                </div>
            </div>
            <div class="admin-list">
                <?php if (!$audits): ?>
                    <div class="empty-state" data-i18n="noExternalAuditsText">Henüz dış denetim kaydı oluşturulmadı.</div>
                <?php endif; ?>
                <?php foreach ($audits as $audit): ?>
                    <a class="admin-list-item list-link" href="external-audit-detail.php?id=<?= (int) $audit["id"] ?>">
                        <div class="list-item-main">
                            <strong><?= htmlspecialchars($audit["title"], ENT_QUOTES, "UTF-8") ?></strong>
                            <span><?= htmlspecialchars($typeLabels[$audit["audit_type"]] ?? $audit["audit_type"], ENT_QUOTES, "UTF-8") ?> · <?= htmlspecialchars($audit["audited_by"] ?: "-", ENT_QUOTES, "UTF-8") ?> · <?= htmlspecialchars($audit["company_name"], ENT_QUOTES, "UTF-8") ?></span>
                        </div>
                        <div class="list-item-side">
                            <span class="status-pill" data-i18n="<?= $statusI18n[$audit["status"]] ?? "" ?>"><?= htmlspecialchars($statusLabels[$audit["status"]] ?? $audit["status"], ENT_QUOTES, "UTF-8") ?></span>
                            <span class="list-item-date"><?= (int) $audit["open_findings"] ?> <span data-i18n="externalFindingOpenShortLabel">açık</span> · <?= (int) $audit["overdue_findings"] ?> <span data-i18n="externalFindingOverdueShortLabel">geciken</span></span>
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
