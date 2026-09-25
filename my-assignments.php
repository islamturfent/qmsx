<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/access.php';
require_once __DIR__ . '/includes/due-workbench-functions.php';

$userId = (int) ($_SESSION["qms_user_id"] ?? 0);
$sections = qmsMyAssignments($pdo, $userId);
$totalOpen = 0;
$totalOverdue = 0;
foreach ($sections as $sec) {
    $totalOpen += $sec['count'];
    foreach ($sec['rows'] as $r) {
        if ($r['overdue']) {
            $totalOverdue++;
        }
    }
}

$statusLabels = ['planned' => 'Planlandı', 'in_progress' => 'Çalışılıyor', 'verification' => 'Doğrulama', 'completed' => 'Tamamlandı', 'closed' => 'Kapalı', 'new' => 'Yeni', 'action_planned' => 'Faaliyet Planlandı', 'operational' => 'Çalışıyor'];
$activeNav = "my_assignments";

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f9fafb">
    <title>QMS Bana Atanmışlar</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar">
        <div class="topbar-inner">
            <div class="page-title-block">
                <strong data-i18n="myAssignmentsTitle">Bana Atanmışlar</strong>
                <span data-i18n="myAssignmentsText">Size atanmış açık kayıtları tek yerden izleyin.</span>
            </div>
            <div class="topbar-actions">
                <button class="topbar-button" id="languageToggle" type="button">EN</button>
                <button class="topbar-button" id="themeToggle" type="button" aria-label="Tema değiştir">🌙</button>
            </div>
        </div>
    </header>
    <main class="page-container">
        <section class="page-heading">
            <div>
                <span class="section-kicker" data-i18n="myAssignmentsKicker">Kişisel İş Akışı</span>
                <h1 data-i18n="myAssignmentsTitle">Bana Atanmışlar</h1>
                <p data-i18n="myAssignmentsText">Sorumlu olduğunuz açık düzeltici faaliyet, şikayet ve kalibrasyon kayıtları.</p>
            </div>
        </section>

        <section class="dashboard-grid compact-dashboard-grid">
            <div class="dashboard-card metric-blue">
                <?= appIcon("check", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="myAssignmentsOpenLabel">Açık Kayıt</span>
                    <strong class="dashboard-card-number detail-card-value"><?= $totalOpen ?></strong>
                </div>
            </div>
            <div class="dashboard-card <?= $totalOverdue > 0 ? 'metric-red' : '' ?>">
                <?= appIcon("alert", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="myAssignmentsOverdueLabel">Geciken</span>
                    <strong class="dashboard-card-number detail-card-value"><?= $totalOverdue ?></strong>
                </div>
            </div>
            <div class="dashboard-card metric-teal">
                <?= appIcon("complaints", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="mineComplaintsLabel">Şikayet</span>
                    <strong class="dashboard-card-number detail-card-value"><?= $sections['complaints']['count'] ?></strong>
                </div>
            </div>
            <div class="dashboard-card metric-violet">
                <?= appIcon("table", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="mineEquipmentLabel">Kalibrasyon</span>
                    <strong class="dashboard-card-number detail-card-value"><?= $sections['equipment']['count'] ?></strong>
                </div>
            </div>
        </section>

        <?php if ($totalOpen === 0): ?>
            <div class="empty-state" data-i18n="myAssignmentsEmpty">Size atanmış açık kayıt bulunmuyor.</div>
        <?php else: ?>
            <?php foreach ($sections as $key => $sec): if ($sec['count'] === 0) { continue; } ?>
                <section class="console-card checklist-section">
                    <div class="section-heading compact-heading">
                        <div>
                            <h3 data-i18n="<?= $sec['label_key'] ?>"><?= ($key === 'actions' ? 'Düzeltici Faaliyet' : ($key === 'complaints' ? 'Şikayet' : 'Kalibrasyon')) ?></h3>
                            <p><?= $sec['count'] ?> açık kayıt</p>
                        </div>
                    </div>
                    <div class="admin-list">
                        <?php foreach ($sec['rows'] as $row): ?>
                            <a class="admin-list-item <?= $row['overdue'] ? 'overdue-row' : '' ?>" href="<?= htmlspecialchars($row['link'], ENT_QUOTES, 'UTF-8') ?>">
                                <div class="list-item-main">
                                    <strong><?= htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8') ?></strong>
                                    <span><?= htmlspecialchars($row['company'], ENT_QUOTES, 'UTF-8') ?><?php if ($row['extra'] !== ''): ?> · <?= htmlspecialchars($row['extra'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?></span>
                                </div>
                                <div class="list-item-side">
                                    <span class="status-pill"><?= htmlspecialchars($statusLabels[$row['status']] ?? $row['status'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php if ($row['due']): ?><span class="status-pill <?= $row['overdue'] ? 'off-track' : '' ?>"><?= htmlspecialchars($row['due'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>
        <?php endif; ?>
    </main>
    <script src="assets/js/theme.js"></script>
    <script src="assets/js/language.js"></script>
    <script src="assets/js/sidebar.js"></script>
    <script src="assets/js/pwa.js"></script>
</body>
</html>
