<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/access.php';
require_once __DIR__ . '/includes/audit-program-functions.php';

$userId = (int) ($_SESSION["qms_user_id"] ?? 0);
$role = qmsCurrentRole();

$programs = qmsAuditProgramList($pdo, $userId, $role);

// Ozet kartlari.
$total = count($programs);
$active = 0;
$completed = 0;
$linkedAudits = 0;
$thisYear = (int) date('Y');
$currentYearCount = 0;
foreach ($programs as $program) {
    if ($program["status"] === "active") {
        $active++;
    }
    if ($program["status"] === "completed") {
        $completed++;
    }
    $linkedAudits += (int) $program["audit_count"];
    if ((int) $program["year"] === $thisYear) {
        $currentYearCount++;
    }
}
$completionRate = $total > 0 ? round(($completed / $total) * 100) : 0;

$activeNav = "audit_programs";

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f9fafb">
    <title>QuAmi İç Denetim Programları</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar">
        <div class="topbar-inner">
            <div class="page-title-block">
                <strong data-i18n="auditProgramsTitle">İç Denetim Programları</strong>
                <span data-i18n="auditProgramsText">Yıllık denetim planlarını ve bağlı denetimleri izleyin.</span>
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
                <span class="section-kicker" data-i18n="auditProgramKicker">Denetim Planlama</span>
                <h1 data-i18n="auditProgramsTitle">İç Denetim Programları</h1>
                <p data-i18n="auditProgramsText">Denetimleri yıllık programlar altında planlayın, durumlarını izleyin ve raporlayın.</p>
            </div>
            <a class="secondary-button" href="audit-program-create.php" data-i18n="createAuditProgramButton">Yeni Denetim Programı</a>
        </section>

        <section class="dashboard-grid compact-dashboard-grid">
            <div class="dashboard-card metric-blue">
                <?= appIcon("table", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="auditProgramTotalLabel">Toplam Program</span>
                    <strong class="dashboard-card-number detail-card-value"><?= $total ?></strong>
                </div>
            </div>
            <div class="dashboard-card metric-teal">
                <?= appIcon("check", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="auditProgramActiveLabel">Aktif Program</span>
                    <strong class="dashboard-card-number detail-card-value"><?= $active ?></strong>
                </div>
            </div>
            <div class="dashboard-card metric-violet">
                <?= appIcon("checkBadge", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="auditProgramCompletedLabel">Tamamlanan</span>
                    <strong class="dashboard-card-number detail-card-value"><?= $completed ?> <small>(%<?= $completionRate ?>)</small></strong>
                </div>
            </div>
            <div class="dashboard-card metric-orange">
                <?= appIcon("checkBadge", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="auditProgramLinkedLabel">Bağlı Denetim</span>
                    <strong class="dashboard-card-number detail-card-value"><?= $linkedAudits ?></strong>
                </div>
            </div>
        </section>

        <section class="console-card checklist-section">
            <div class="section-heading compact-heading">
                <div>
                    <h3 data-i18n="auditProgramListTitle">Programlar</h3>
                    <p><span data-i18n="filteredRecordsLabel">Gösterilen kayıt</span>: <strong><?= $total ?></strong></p>
                </div>
            </div>

            <div class="admin-list">
                <?php if (!$programs): ?>
                    <div class="empty-state" data-i18n="noAuditProgramsText">Henüz denetim programı oluşturulmadı.</div>
                <?php endif; ?>

                <?php foreach ($programs as $program): ?>
                    <a class="admin-list-item list-link" href="audit-program-detail.php?id=<?= (int) $program["id"] ?>">
                        <div class="list-item-main">
                            <strong><?= htmlspecialchars($program["title"], ENT_QUOTES, "UTF-8") ?></strong>
                            <span><?= htmlspecialchars($program["company_name"], ENT_QUOTES, "UTF-8") ?> · <?= (int) $program["year"] ?></span>
                        </div>
                        <div class="list-item-side">
                            <span class="status-pill" data-i18n="<?= qmsAuditProgramStatusI18nKeys()[$program["status"]] ?? "" ?>"><?= htmlspecialchars(qmsAuditProgramStatusLabels()[$program["status"]] ?? $program["status"], ENT_QUOTES, "UTF-8") ?></span>
                            <span class="list-item-count"><?= (int) $program["audit_count"] ?>/<?= (int) $program["done_count"] ?> ✓</span>
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
