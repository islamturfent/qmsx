<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/access.php';
require_once __DIR__ . '/includes/personnel-functions.php';

$userId = (int) ($_SESSION["qms_user_id"] ?? 0);
$role = qmsCurrentRole();

$personnel = qmsPersonnelList($pdo, $userId, $role);
$total = count($personnel);
$totalCompetencies = 0;
$expired = 0;
foreach ($personnel as $member) {
    $totalCompetencies += (int) $member["competency_count"];
    $expired += (int) $member["expired_count"];
}

$activeNav = "personnel";

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f9fafb">
    <title>QuAmi Personel & Yetkinlik</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar">
        <div class="topbar-inner">
            <div class="page-title-block">
                <strong data-i18n="personnelTitle">Personel & Yetkinlik</strong>
                <span data-i18n="personnelText">Personel kayıtlarını ve yetkinlik vadesini izleyin.</span>
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
                <span class="section-kicker" data-i18n="personnelKicker">Kaynak Yönetimi</span>
                <h1 data-i18n="personnelTitle">Personel & Yetkinlik</h1>
                <p data-i18n="personnelText">Personel kayıtlarını ve yetkinlik matrisini yönetin.</p>
            </div>
            <a class="secondary-button" href="personnel-create.php" data-i18n="createPersonnelButton">Yeni Personel</a>
        </section>

        <section class="dashboard-grid compact-dashboard-grid">
            <div class="dashboard-card metric-blue">
                <?= appIcon("users", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="personnelTotalLabel">Toplam Personel</span>
                    <strong class="dashboard-card-number detail-card-value"><?= $total ?></strong>
                </div>
            </div>
            <div class="dashboard-card metric-violet">
                <?= appIcon("training", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="personnelCompetencyTotalLabel">Toplam Yetkinlik</span>
                    <strong class="dashboard-card-number detail-card-value"><?= $totalCompetencies ?></strong>
                </div>
            </div>
            <div class="dashboard-card metric-red">
                <?= appIcon("warning", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="personnelExpiredTotalLabel">Vadesi Geçen Değerlendirme</span>
                    <strong class="dashboard-card-number detail-card-value"><?= $expired ?></strong>
                </div>
            </div>
        </section>

        <section class="console-card checklist-section">
            <div class="section-heading compact-heading">
                <div>
                    <h3 data-i18n="personnelListTitle">Personel</h3>
                    <p><span data-i18n="filteredRecordsLabel">Gösterilen kayıt</span>: <strong><?= $total ?></strong></p>
                </div>
            </div>
            <div class="admin-list">
                <?php if (!$personnel): ?>
                    <div class="empty-state" data-i18n="noPersonnelText">Henüz personel kaydı oluşturulmadı.</div>
                <?php endif; ?>
                <?php foreach ($personnel as $member): ?>
                    <a class="admin-list-item list-link" href="personnel-detail.php?id=<?= (int) $member["id"] ?>">
                        <div class="list-item-main">
                            <strong><?= htmlspecialchars($member["first_name"] . ' ' . $member["last_name"], ENT_QUOTES, "UTF-8") ?></strong>
                            <span><?= htmlspecialchars($member["position"] ?: "-", ENT_QUOTES, "UTF-8") ?> · <?= htmlspecialchars($member["company_name"], ENT_QUOTES, "UTF-8") ?></span>
                        </div>
                        <div class="list-item-side">
                            <span class="list-item-date"><?= (int) $member["competency_count"] ?> <span data-i18n="personnelCompetencyShortLabel">yetkinlik</span><?= (int) $member["expired_count"] > 0 ? ' · <span class="status-pill" data-i18n="competencyExpiredPill">Geçti</span>' : '' ?></span>
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
