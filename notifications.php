<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/app-ui.php';
require_once __DIR__ . '/includes/notifications.php';
require_once __DIR__ . '/includes/due-workbench-functions.php';

$userId = (int) ($_SESSION["qms_user_id"] ?? 0);
$csrfToken = qmsCsrfToken('notifications');

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    qmsCsrfVerify('notifications', $_POST["csrf"] ?? null);

    $formType = $_POST["form_type"] ?? "";
    if ($formType === "mark_read") {
        $notificationId = (int) ($_POST["notification_id"] ?? 0);
        $pdo->prepare(
            "UPDATE notifications SET is_read = 1, read_at = COALESCE(read_at, NOW())
             WHERE id = :id AND user_id = :user_id"
        )->execute(["id" => $notificationId, "user_id" => $userId]);
    }
    if ($formType === "mark_all_read") {
        $pdo->prepare(
            "UPDATE notifications SET is_read = 1, read_at = COALESCE(read_at, NOW())
             WHERE user_id = :user_id AND is_read = 0"
        )->execute(["user_id" => $userId]);
    }
    header("Location: notifications.php");
    exit;
}

$filter = $_GET["filter"] ?? "all";
$sql = "SELECT * FROM notifications WHERE user_id = :user_id";
if ($filter === "unread") $sql .= " AND is_read = 0";
if ($filter === "read") $sql .= " AND is_read = 1";
$sql .= " ORDER BY is_read ASC, created_at DESC, id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute(["user_id" => $userId]);
$notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

$countStmt = $pdo->prepare(
    "SELECT COUNT(*) total, SUM(is_read = 0) unread_count, SUM(is_read = 1) read_count
     FROM notifications WHERE user_id = :user_id"
);
$countStmt->execute(["user_id" => $userId]);
$counts = $countStmt->fetch(PDO::FETCH_ASSOC) ?: ["total" => 0, "unread_count" => 0, "read_count" => 0];

$notificationGroups = qmsNotificationGroupLabels();
$notificationGroupI18n = qmsNotificationGroupI18nKeys();

$myOverdue = qmsUserOverdueAssignments($pdo, $userId);

$activeNav = "notifications";

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="theme-color" content="#f9fafb">
    <title>QuAmi Bildirim Merkezi</title><link rel="manifest" href="manifest.webmanifest"><link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml"><link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar"><div class="topbar-inner"><div class="page-title-block"><strong data-i18n="notificationCenterTitle">Bildirim Merkezi</strong><span data-i18n="notificationCenterText">Onay, karar ve yayın hareketlerinizi takip edin.</span></div><div class="topbar-actions"><button class="topbar-button" id="languageToggle" type="button">EN</button><button class="topbar-button" id="themeToggle" type="button" aria-label="Tema değiştir">🌙</button></div></div></header>
    <main class="page-container">
        <section class="page-heading page-heading-actions"><div><span class="section-kicker" data-i18n="sidebarOverviewLabel">Genel</span><h1 data-i18n="notificationCenterTitle">Bildirim Merkezi</h1><p data-i18n="notificationCenterText">Onay, karar ve yayın hareketlerinizi takip edin.</p></div><?php if ((int) $counts["unread_count"] > 0): ?><form method="post" action="notifications.php"><?= qmsCsrfField('notifications') ?><input type="hidden" name="form_type" value="mark_all_read"><button class="primary-button" type="submit" data-i18n="markAllReadButton">Tümünü Okundu İşaretle</button></form><?php endif; ?></section>
        <section class="dashboard-grid compact-dashboard-grid">
            <a class="dashboard-card metric-blue" href="notifications.php"><?= appIcon("table", "dashboard-card-icon") ?><div class="dashboard-card-content"><span class="dashboard-card-label" data-i18n="allNotificationsLabel">Tüm Bildirimler</span><strong class="dashboard-card-number"><?= (int) $counts["total"] ?></strong></div></a>
            <a class="dashboard-card metric-orange" href="notifications.php?filter=unread"><?= appIcon("alert", "dashboard-card-icon") ?><div class="dashboard-card-content"><span class="dashboard-card-label" data-i18n="unreadNotificationsLabel">Okunmamış</span><strong class="dashboard-card-number"><?= (int) $counts["unread_count"] ?></strong></div></a>
            <a class="dashboard-card metric-teal" href="notifications.php?filter=read"><?= appIcon("check", "dashboard-card-icon") ?><div class="dashboard-card-content"><span class="dashboard-card-label" data-i18n="readNotificationsLabel">Okunmuş</span><strong class="dashboard-card-number"><?= (int) $counts["read_count"] ?></strong></div></a>
        </section>
        <?php if ($myOverdue): ?>
        <section class="console-card checklist-section">
            <div class="section-heading compact-heading">
                <div>
                    <h3 data-i18n="myOverdueTitle">Geciken İşlerim</h3>
                    <p data-i18n="myOverdueText">Size atanmış ve terminal geçmiş kayıtlar.</p>
                </div>
                <a class="secondary-button secondary-button-sm" href="overdue.php" data-i18n="viewOverdueLink">Tümünü Gör</a>
            </div>
            <div class="admin-list">
                <?php foreach ($myOverdue as $od): ?>
                    <a class="admin-list-item overdue-row" href="<?= htmlspecialchars($od['link'], ENT_QUOTES, 'UTF-8') ?>">
                        <div class="list-item-main">
                            <strong><?= htmlspecialchars($od['text'], ENT_QUOTES, 'UTF-8') ?></strong>
                            <span><?= htmlspecialchars($od['label'], ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars($od['company'], ENT_QUOTES, 'UTF-8') ?><?php if ($od['sub'] !== ''): ?> · <?= htmlspecialchars($od['sub'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?></span>
                        </div>
                        <div class="list-item-side"><span class="status-pill off-track"><?= htmlspecialchars($od['due'], ENT_QUOTES, 'UTF-8') ?></span></div>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>
        <section class="page-section">
            <div class="section-heading"><div><h2 data-i18n="recentNotificationsTitle">Son Bildirimler</h2><p><span data-i18n="filteredRecordsLabel">Gösterilen kayıt</span>: <strong><?= count($notifications) ?></strong></p></div></div>
            <?php if (!$notifications): ?><div class="empty-state" data-i18n="noNotificationsText">Gösterilecek bildirim bulunmuyor.</div><?php else: ?>
                <div class="notification-list">
                    <?php foreach ($notifications as $notification): ?>
                        <?php $notificationMeta = qmsNotificationMeta((string) $notification["notification_type"]); ?>
                        <article class="notification-item <?= (int) $notification["is_read"] === 0 ? "unread" : "" ?>">
                            <div class="notification-icon notification-icon-<?= htmlspecialchars($notificationMeta["group"], ENT_QUOTES, "UTF-8") ?>"><?= appIcon($notificationMeta["icon"], "") ?></div>
                            <div class="notification-content"><span class="status-pill" data-i18n="<?= $notificationGroupI18n[$notificationMeta["group"]] ?? "notificationGroupGeneralLabel" ?>"><?= htmlspecialchars($notificationGroups[$notificationMeta["group"]] ?? $notificationGroups["general"], ENT_QUOTES, "UTF-8") ?></span><strong><?= htmlspecialchars($notification["title"], ENT_QUOTES, "UTF-8") ?></strong><p><?= htmlspecialchars($notification["message"] ?: "", ENT_QUOTES, "UTF-8") ?></p><span><?= htmlspecialchars($notification["created_at"], ENT_QUOTES, "UTF-8") ?></span></div>
                            <div class="notification-actions"><?php if ($notification["link_url"]): ?><a class="primary-button" href="<?= htmlspecialchars($notification["link_url"], ENT_QUOTES, "UTF-8") ?>" data-i18n="openRecordButton">Kaydı Aç</a><?php endif; ?><?php if ((int) $notification["is_read"] === 0): ?><form method="post" action="notifications.php"><?= qmsCsrfField('notifications') ?><input type="hidden" name="form_type" value="mark_read"><input type="hidden" name="notification_id" value="<?= (int) $notification["id"] ?>"><button class="secondary-button" type="submit" data-i18n="markReadButton">Okundu</button></form><?php endif; ?></div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </main>
    <script src="assets/js/theme.js"></script><script src="assets/js/language.js"></script><script src="assets/js/sidebar.js"></script><script src="assets/js/pwa.js"></script>
</body>
</html>
