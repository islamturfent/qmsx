<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/access.php';
qmsRequirePermission('operations.view');
require_once __DIR__ . '/includes/satisfaction-functions.php';

$userId = (int) ($_SESSION["qms_user_id"] ?? 0);
$role = qmsCurrentRole();

$surveys = qmsSatisfactionSurveyList($pdo, $userId, $role);
$totalSurveys = count($surveys);
$totalResponses = 0;
$scoreSum = 0;
$scoreCount = 0;
foreach ($surveys as $survey) {
    $totalResponses += (int) $survey["response_count"];
    if (((float) $survey["avg_score"]) > 0) {
        $scoreSum += (float) $survey["avg_score"] * (int) $survey["response_count"];
        $scoreCount += (int) $survey["response_count"];
    }
}
$avgOverall = $scoreCount > 0 ? round($scoreSum / $scoreCount, 1) : 0;

$activeNav = "satisfaction";

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f9fafb">
    <title>QuAmi Müşteri Memnuniyeti Anketleri</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar">
        <div class="topbar-inner">
            <div class="page-title-block">
                <strong data-i18n="satisfactionTitle">Müşteri Memnuniyeti Anketleri</strong>
                <span data-i18n="satisfactionText">Anket şablonları oluşturun ve müşteri memnuniyetini izleyin.</span>
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
                <span class="section-kicker" data-i18n="satisfactionKicker">Müşteri Odaklılık</span>
                <h1 data-i18n="satisfactionTitle">Müşteri Memnuniyeti Anketleri</h1>
                <p data-i18n="satisfactionText">Anket şablonları oluşturun, müşteri yanıtlarını toplayın ve memnuniyeti raporlayın.</p>
            </div>
            <a class="secondary-button" href="satisfaction-survey-create.php" data-i18n="createSatisfactionSurveyButton">Yeni Anket</a>
        </section>

        <section class="dashboard-grid compact-dashboard-grid">
            <div class="dashboard-card metric-blue">
                <?= appIcon("complaints", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="satisfactionSurveyTotalLabel">Toplam Anket</span>
                    <strong class="dashboard-card-number detail-card-value"><?= $totalSurveys ?></strong>
                </div>
            </div>
            <div class="dashboard-card metric-violet">
                <?= appIcon("check", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="satisfactionResponseTotalLabel">Toplam Yanıt</span>
                    <strong class="dashboard-card-number detail-card-value"><?= $totalResponses ?></strong>
                </div>
            </div>
            <div class="dashboard-card metric-teal">
                <?= appIcon("performance", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="satisfactionAvgLabel">Ortalama Memnuniyet</span>
                    <strong class="dashboard-card-number detail-card-value"><?= $avgOverall ?>/5</strong>
                </div>
            </div>
        </section>

        <section class="console-card checklist-section">
            <div class="section-heading compact-heading">
                <div>
                    <h3 data-i18n="satisfactionSurveyListTitle">Anketler</h3>
                    <p><span data-i18n="filteredRecordsLabel">Gösterilen kayıt</span>: <strong><?= $totalSurveys ?></strong></p>
                </div>
            </div>
            <div class="admin-list">
                <?php if (!$surveys): ?>
                    <div class="empty-state" data-i18n="noSatisfactionSurveysText">Henüz müşteri memnuniyeti anketi oluşturulmadı.</div>
                <?php endif; ?>
                <?php foreach ($surveys as $survey): ?>
                    <a class="admin-list-item list-link" href="satisfaction-survey-detail.php?id=<?= (int) $survey["id"] ?>">
                        <div class="list-item-main">
                            <strong><?= htmlspecialchars($survey["title"], ENT_QUOTES, "UTF-8") ?></strong>
                            <span><?= htmlspecialchars($survey["company_name"], ENT_QUOTES, "UTF-8") ?></span>
                        </div>
                        <div class="list-item-side">
                            <span class="list-item-date"><?= (int) $survey["response_count"] ?> <span data-i18n="satisfactionResponsesShortLabel">yanıt</span> · <?= ((float) $survey["avg_score"]) > 0 ? htmlspecialchars((string) $survey["avg_score"], ENT_QUOTES, "UTF-8") . "/5" : "-" ?></span>
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
