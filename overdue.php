<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/access.php';
qmsRequirePermission('overdue.view');
require_once __DIR__ . '/includes/due-workbench-functions.php';

$userId = (int) ($_SESSION["qms_user_id"] ?? 0);
if (qmsIsAuditor()) {
    header("Location: my-audits.php");
    exit;
}
$role = qmsCurrentRole();

$sections = qmsOverdueWorkbench($pdo, $userId, $role);
$totalOverdue = 0;
foreach ($sections as $sec) {
    $totalOverdue += $sec['count'];
}
$auditorWorkload = qmsAuditorWorkload($pdo, $userId, $role);
$activeNav = "overdue";

$severityLabels = ['minor' => 'Küçük', 'major' => 'Büyük', 'critical' => 'Kritik'];

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f9fafb">
    <title>QuAmi Vadesi Gelen / Geciken İşler</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar">
        <div class="topbar-inner">
            <div class="page-title-block">
                <strong data-i18n="overdueTitle">Vadesi Gelen İşler</strong>
                <span data-i18n="overdueText">Modüller arası geciken kayıtları tek yerden izleyin.</span>
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
                <span class="section-kicker" data-i18n="overdueKicker">Operasyonel Takip</span>
                <h1 data-i18n="overdueTitle">Vadesi Gelen / Geciken İşler</h1>
                <p data-i18n="overdueText">Düzeltici faaliyet, uygunsuzluk, eğitim, kalibrasyon, bulgu, doküman ve şikayet terminlerinin özeti.</p>
            </div>
            <div class="form-actions">
                <a class="secondary-button" href="overdue-export.php?format=xlsx" data-i18n="exportExcelButton">Excel İndir</a>
                <a class="primary-button" href="overdue-export.php?format=pdf" data-i18n="exportPdfButton">PDF İndir</a>
            </div>
        </section>

        <section class="dashboard-grid compact-dashboard-grid">
            <div class="dashboard-card metric-red">
                <?= appIcon("alert", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="overdueTotalLabel">Toplam Geciken</span>
                    <strong class="dashboard-card-number detail-card-value"><?= $totalOverdue ?></strong>
                </div>
            </div>
            <?php foreach ($sections as $key => $sec): ?>
                <div class="dashboard-card <?= $sec['count'] > 0 ? 'metric-orange' : '' ?>">
                    <?= appIcon($sec['icon'], "dashboard-card-icon") ?>
                    <div class="dashboard-card-content">
                        <span class="dashboard-card-label" data-i18n="<?= $sec['label_key'] ?>"><?= ($key === 'actions' ? 'Düzeltici Faaliyet' : ($key === 'nonconformities' ? 'Uygunsuzluk' : ($key === 'trainings' ? 'Eğitim' : ($key === 'equipment' ? 'Kalibrasyon' : ($key === 'findings' ? 'Bulgular' : ($key === 'documents' ? 'Doküman GGR' : 'Şikayet')))))) ?></span>
                        <strong class="dashboard-card-number detail-card-value"><?= $sec['count'] ?></strong>
                    </div>
                </div>
            <?php endforeach; ?>
        </section>

        <?php if ($totalOverdue === 0): ?>
            <div class="empty-state" data-i18n="overdueEmpty">Harika! Geciken kayıt yok.</div>
        <?php else: ?>
            <section class="console-card checklist-section">
                <div class="section-heading compact-heading">
                    <div>
                        <h3 data-i18n="overdueListTitle">Geciken Kayıtlar</h3>
                        <p data-i18n="overdueListText">Termini geçmiş ve hâlâ açık olan kayıtlar.</p>
                    </div>
                </div>
                <?php foreach ($sections as $key => $sec): if ($sec['count'] === 0) { continue; } ?>
                    <div class="overdue-section">
                        <div class="overdue-section-head">
                            <?= appIcon($sec['icon'], "trend-series-icon") ?>
                            <strong data-i18n="<?= $sec['label_key'] ?>"><?= ($key === 'actions' ? 'Düzeltici Faaliyet' : ($key === 'nonconformities' ? 'Uygunsuzluk' : ($key === 'trainings' ? 'Eğitim' : ($key === 'equipment' ? 'Ekipman Kalibrasyonu' : ($key === 'findings' ? 'Dış Denetim Bulguları' : ($key === 'documents' ? 'Doküman Gözden Geçirme' : 'Şikayetler')))))) ?></strong>
                            <span class="status-pill off-track"><?= $sec['count'] ?></span>
                        </div>
                        <div class="admin-list">
                            <?php foreach ($sec['rows'] as $row): ?>
                                <a class="admin-list-item overdue-row" href="<?= htmlspecialchars($row['link'], ENT_QUOTES, 'UTF-8') ?>">
                                    <div class="list-item-main">
                                        <strong><?= htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        <span><?= htmlspecialchars($row['company'], ENT_QUOTES, 'UTF-8') ?><?php if ($row['extra'] !== ''): ?> · <?= htmlspecialchars(($key === 'findings' ? ($severityLabels[$row['extra']] ?? $row['extra']) : $row['extra']), ENT_QUOTES, 'UTF-8') ?><?php endif; ?></span>
                                    </div>
                                    <div class="list-item-side">
                                        <span class="status-pill off-track"><?= htmlspecialchars($row['due'], ENT_QUOTES, 'UTF-8') ?></span>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>

        <?php if ($auditorWorkload): ?>
        <section class="console-card checklist-section">
            <div class="section-heading compact-heading">
                <div>
                    <h3 data-i18n="auditorWorkloadTitle">Denetçi İş Yükü</h3>
                    <p data-i18n="auditorWorkloadText">Denetçi başına atanmış denetim ve açık uygunsuzluk/faaliyet yükü.</p>
                </div>
            </div>
            <div class="cockpit-kpi-grid">
                <?php foreach ($auditorWorkload as $aw): ?>
                    <div class="cockpit-kpi-card">
                        <div class="cockpit-kpi-head">
                            <strong><?= htmlspecialchars($aw['name'], ENT_QUOTES, 'UTF-8') ?></strong>
                            <span class="status-pill <?= $aw['total_open'] === 0 ? 'on-track' : 'off-track' ?>"><?= $aw['total_open'] ?></span>
                        </div>
                        <div class="table-scroll">
                            <table class="data-table compact-table">
                                <tbody>
                                    <tr><td data-i18n="auditorWorkloadCompanyTh">Şirket</td><td><?= htmlspecialchars($aw['company'], ENT_QUOTES, 'UTF-8') ?></td></tr>
                                    <tr><td data-i18n="auditorWorkloadAuditsTh">Atanmış Denetim</td><td><?= $aw['assigned_audits'] ?></td></tr>
                                    <tr><td data-i18n="auditorWorkloadNcTh">Açık Uygunsuzluk</td><td><?= $aw['open_nonconformities'] ?></td></tr>
                                    <tr><td data-i18n="auditorWorkloadActionsTh">Açık Faaliyet</td><td><?= $aw['open_actions'] ?></td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>
    </main>
    <script src="assets/js/theme.js"></script>
    <script src="assets/js/language.js"></script>
    <script src="assets/js/sidebar.js"></script>
    <script src="assets/js/pwa.js"></script>
</body>
</html>
