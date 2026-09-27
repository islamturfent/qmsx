<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/access.php';
require_once __DIR__ . '/includes/approval-workflow-functions.php';

$userId = (int) ($_SESSION["qms_user_id"] ?? 0);
$role = qmsCurrentRole();

$runs = qmsApprovalRunList($pdo, $userId, $role);
$total = count($runs);
$inProgress = 0;
$approved = 0;
$rejected = 0;
foreach ($runs as $run) {
    if ($run["status"] === "in_progress") $inProgress++;
    if ($run["status"] === "approved") $approved++;
    if ($run["status"] === "rejected") $rejected++;
}

$statusLabels = qmsApprovalStatusLabels();
$statusI18n = qmsApprovalStatusI18nKeys();
$activeNav = "approvals";

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f9fafb">
    <title>QuAmi Onay & İmza Workflow</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar">
        <div class="topbar-inner">
            <div class="page-title-block">
                <strong data-i18n="approvalWorkflowTitle">Onay & İmza Workflow</strong>
                <span data-i18n="approvalWorkflowText">Çok adımlı onay ve imza akışlarını yönetin.</span>
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
                <span class="section-kicker" data-i18n="approvalWorkflowKicker">Kayıt Onayı</span>
                <h1 data-i18n="approvalWorkflowTitle">Onay & İmza Workflow</h1>
                <p data-i18n="approvalWorkflowText">Kayıtlar için sıralı onay/imza adımlarını başlatın ve izleyin.</p>
            </div>
            <a class="secondary-button" href="approval-runs-create.php" data-i18n="createApprovalRunButton">Yeni Onay Akışı</a>
        </section>

        <section class="dashboard-grid compact-dashboard-grid">
            <div class="dashboard-card metric-blue">
                <?= appIcon("approvals", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="approvalRunTotalLabel">Toplam Akış</span>
                    <strong class="dashboard-card-number detail-card-value"><?= $total ?></strong>
                </div>
            </div>
            <div class="dashboard-card metric-orange">
                <?= appIcon("clock", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="approvalRunInProgressLabel">Devam Eden</span>
                    <strong class="dashboard-card-number detail-card-value"><?= $inProgress ?></strong>
                </div>
            </div>
            <div class="dashboard-card metric-teal">
                <?= appIcon("checkBadge", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="approvalRunApprovedLabel">Onaylanan</span>
                    <strong class="dashboard-card-number detail-card-value"><?= $approved ?></strong>
                </div>
            </div>
            <div class="dashboard-card metric-red">
                <?= appIcon("warning", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="approvalRunRejectedLabel">Reddedilen</span>
                    <strong class="dashboard-card-number detail-card-value"><?= $rejected ?></strong>
                </div>
            </div>
        </section>

        <section class="console-card checklist-section">
            <div class="section-heading compact-heading">
                <div>
                    <h3 data-i18n="approvalRunListTitle">Onay Akışları</h3>
                    <p><span data-i18n="filteredRecordsLabel">Gösterilen kayıt</span>: <strong><?= $total ?></strong></p>
                </div>
            </div>
            <div class="admin-list">
                <?php if (!$runs): ?>
                    <div class="empty-state" data-i18n="noApprovalRunsText">Henüz onay akışı oluşturulmadı.</div>
                <?php endif; ?>
                <?php foreach ($runs as $run): ?>
                    <a class="admin-list-item list-link" href="approval-runs-detail.php?id=<?= (int) $run["id"] ?>">
                        <div class="list-item-main">
                            <strong><?= htmlspecialchars($run["subject"], ENT_QUOTES, "UTF-8") ?></strong>
                            <span><?= htmlspecialchars($run["company_name"], ENT_QUOTES, "UTF-8") ?> · <?= (int) $run["step_count"] - (int) $run["pending_count"] ?>/<?= (int) $run["step_count"] ?> <span data-i18n="approvalSignedShortLabel">imza</span></span>
                        </div>
                        <div class="list-item-side">
                            <span class="status-pill" data-i18n="<?= $statusI18n[$run["status"]] ?? "" ?>"><?= htmlspecialchars($statusLabels[$run["status"]] ?? $run["status"], ENT_QUOTES, "UTF-8") ?></span>
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
