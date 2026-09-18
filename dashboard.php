<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';

$auditorCount = (int) $pdo->query("SELECT COUNT(*) FROM auditors WHERE active = 1")->fetchColumn();
$activeAuditCount = (int) $pdo->query("SELECT COUNT(*) FROM audits WHERE active = 1")->fetchColumn();
$openNonconformityCount = (int) $pdo->query(
    "SELECT COUNT(*) FROM nonconformities WHERE active = 1 AND status <> 'closed'"
)->fetchColumn();
$documentCount = (int) $pdo->query("SELECT COUNT(*) FROM documents WHERE active = 1")->fetchColumn();

require_once __DIR__ . '/includes/report-export-data.php';

// Performans karti, raporlama sayfasindaki ile ayni metrigi kullanir; boylece
// paneldeki deger raporlarla tutarli kalir (varsayilan donem: son 12 ay).
$reportMetrics = buildReportExportData(
    $pdo,
    (int) $_SESSION["qms_user_id"],
    ($_SESSION["qms_role"] ?? "") === "super_admin",
    []
)["metrics"];

$activeNav = "dashboard";

?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f9fafb">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="QMS">

    <title>QMS Dashboard</title>

    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="assets/icons/qms-icon-192.png">
    <link rel="stylesheet" href="assets/css/style.css">
</head>

<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>

    <header class="topbar">
        <div class="topbar-inner">
            <div class="page-title-block">
                <strong data-i18n="dashboardLinkLabel">Dashboard</strong>
                <span data-i18n="welcomeText">Kalite, denetim ve belgelendirme süreçlerini tek platformdan yönetin.</span>
            </div>

            <div class="topbar-actions">
                <button class="topbar-button" id="languageToggle" type="button">EN</button>
                <button class="topbar-button" id="themeToggle" type="button" aria-label="Tema değiştir">🌙</button>
            </div>
        </div>
    </header>

    <main class="page-container">
        <section class="page-heading">
            <span class="section-kicker" data-i18n="superAdminKicker">Sistem Üst Yönetimi</span>
            <h1 data-i18n="welcomeTitle">QMS Yönetim Sistemi</h1>
            <p data-i18n="welcomeText">Kalite, denetim ve belgelendirme süreçlerini tek platformdan yönetin.</p>
        </section>

        <section class="dashboard-grid" aria-label="Sistem özeti">
            <a class="dashboard-card metric-blue" href="auditors.php">
                <?= appIcon("users", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="auditorsCardLabel">Denetçiler</span>
                    <strong class="dashboard-card-number"><?= $auditorCount ?></strong>
                </div>
            </a>

            <div class="dashboard-card metric-teal">
                <?= appIcon("check", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="activeAuditsCardLabel">Aktif Denetimler</span>
                    <strong class="dashboard-card-number"><?= $activeAuditCount ?></strong>
                </div>
            </div>

            <a class="dashboard-card metric-orange" href="actions.php">
                <?= appIcon("alert", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="openNonconformitiesCardLabel">Açık Uygunsuzluklar</span>
                    <strong class="dashboard-card-number"><?= $openNonconformityCount ?></strong>
                </div>
            </a>

            <a class="dashboard-card metric-violet" href="reports.php">
                <?= appIcon("trend", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="actionCompletionKpi">Aksiyon Tamamlama</span>
                    <strong class="dashboard-card-number"><?= $reportMetrics["action_completion_rate"] ?>%</strong>
                </div>
            </a>

            <a class="dashboard-card metric-blue" href="documents.php">
                <?= appIcon("documents", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="documentManagementTitle">Doküman Yönetimi</span>
                    <strong class="dashboard-card-number"><?= $documentCount ?></strong>
                </div>
            </a>
        </section>
    </main>

    <script src="assets/js/theme.js"></script>
    <script src="assets/js/language.js"></script>
    <script src="assets/js/sidebar.js"></script>
    <script src="assets/js/pwa.js"></script>
</body>
</html>
