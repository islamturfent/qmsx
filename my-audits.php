<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/access.php';
require_once __DIR__ . '/includes/permissions.php';

$userId = (int) ($_SESSION["qms_user_id"] ?? 0);

// Bu ekran denetci rolu icin; izin tek kaynaktan (RBAC servisi) gelir.
if (!qmsCanSession('my_audits.view')) {
    header("Location: dashboard.php");
    exit;
}

$auditsStmt = $pdo->prepare(
    "SELECT audits.id, audits.title, audits.audit_type, audits.planned_date, audits.status,
            companies.company_name,
            (SELECT COUNT(*) FROM audit_checklist_items
              WHERE audit_checklist_items.audit_id = audits.id AND audit_checklist_items.active = 1) AS checklist_total,
            (SELECT COUNT(*) FROM audit_checklist_items
              WHERE audit_checklist_items.audit_id = audits.id AND audit_checklist_items.active = 1
                AND audit_checklist_items.result_status <> 'pending') AS checklist_done
     FROM audits
     INNER JOIN companies ON companies.id = audits.company_id
     INNER JOIN audit_auditors ON audit_auditors.audit_id = audits.id
     INNER JOIN auditors ON auditors.id = audit_auditors.auditor_id
     WHERE auditors.user_id = :user_id AND auditors.active = 1 AND audits.active = 1
     ORDER BY audits.planned_date IS NULL, audits.planned_date ASC, audits.id DESC"
);
$auditsStmt->execute(["user_id" => $userId]);
$audits = $auditsStmt->fetchAll(PDO::FETCH_ASSOC);

$today = date('Y-m-d');
$auditStatusLabels = ['planned' => 'Planlandı', 'in_progress' => 'Devam Ediyor', 'done' => 'Tamamlandı'];

$totalAssigned = count($audits);
$pendingAssigned = 0;
$completedAssigned = 0;
$overdueAssigned = 0;
$classed = [];
foreach ($audits as $assignedAudit) {
    $total = (int) $assignedAudit['checklist_total'];
    $done  = (int) $assignedAudit['checklist_done'];
    $assignOverdue = $done < $total && !empty($assignedAudit['planned_date']) && $assignedAudit['planned_date'] < $today;
    $assignCompleted = $total > 0 && $done >= $total;
    $assignPending = $done < $total;
    $assignedAudit['_pending'] = $assignPending;
    $assignedAudit['_completed'] = $assignCompleted;
    $assignedAudit['_overdue'] = $assignOverdue;
    $classed[] = $assignedAudit;
    if ($assignPending) {
        $pendingAssigned++;
    }
    if ($assignCompleted) {
        $completedAssigned++;
    }
    if ($assignOverdue) {
        $overdueAssigned++;
    }
}

// Durum filtresi (Tum / Devam Eden / Tamamlanan / Geciken).
$filter = (string) ($_GET['filter'] ?? 'all');
if (!in_array($filter, ['all', 'pending', 'completed', 'overdue'], true)) {
    $filter = 'all';
}
$displayAudits = array_values(array_filter($classed, static function (array $a) use ($filter): bool {
    if ($filter === 'pending') {
        return $a['_pending'];
    }
    if ($filter === 'completed') {
        return $a['_completed'];
    }
    if ($filter === 'overdue') {
        return $a['_overdue'];
    }
    return true;
}));

$activeNav = "my_audits";

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f9fafb">
    <title>QuAmi Denetimlerim</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar">
        <div class="topbar-inner">
            <div class="page-title-block">
                <strong data-i18n="myAuditsTitle">Denetimlerim</strong>
                <span data-i18n="myAuditsText">Size atanmış denetimler ve kontrol listesi durumları.</span>
            </div>
            <div class="topbar-actions">
                <button class="topbar-button" id="languageToggle" type="button">EN</button>
                <button class="topbar-button" id="themeToggle" type="button" aria-label="Tema değiştir">🌙</button>
            </div>
        </div>
    </header>
    <main class="page-container">
        <section class="page-heading">
            <span class="section-kicker" data-i18n="auditWorkspaceKicker">Denetim Çalışma Alanı</span>
            <h1 data-i18n="myAuditsTitle">Denetimlerim</h1>
            <p data-i18n="myAuditsText">Size atanmış denetimler ve kontrol listesi durumları.</p>
        </section>

        <section class="dashboard-grid compact-dashboard-grid">
            <div class="dashboard-card metric-blue">
                <?= appIcon("reports", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="myAuditsTotalLabel">Atanan Denetim</span>
                    <strong class="dashboard-card-number"><?= $totalAssigned ?></strong>
                </div>
            </div>
            <div class="dashboard-card metric-orange">
                <?= appIcon("clock", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="myAuditsPendingLabel">Devam Eden</span>
                    <strong class="dashboard-card-number"><?= $pendingAssigned ?></strong>
                </div>
            </div>
            <div class="dashboard-card metric-green">
                <?= appIcon("check", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="myAuditsCompletedLabel">Tamamlanan</span>
                    <strong class="dashboard-card-number"><?= $completedAssigned ?></strong>
                </div>
            </div>
            <div class="dashboard-card metric-red">
                <?= appIcon("alert", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="myAuditsOverdueLabel">Geciken</span>
                    <strong class="dashboard-card-number"><?= $overdueAssigned ?></strong>
                </div>
            </div>
        </section>

        <section class="page-section">
            <div class="section-heading">
                <div><h2 data-i18n="myAuditsListTitle">Atanmış Denetimler</h2><p data-i18n="myAuditsListText">Yalnızca size atanmış denetimleri görürsünüz.</p></div>
                <?php if ($totalAssigned > 0): ?>
                <div class="page-heading-actions-buttons">
                    <a class="secondary-button" href="my-audits-export.php?filter=<?= htmlspecialchars($filter, ENT_QUOTES, "UTF-8") ?>&format=xlsx" data-i18n="excelDownloadLabel">Excel İndir</a>
                    <a class="secondary-button" href="my-audits-export.php?filter=<?= htmlspecialchars($filter, ENT_QUOTES, "UTF-8") ?>&format=pdf" data-i18n="pdfDownloadLabel">PDF İndir</a>
                </div>
                <?php endif; ?>
            </div>

            <div class="filter-tabs">
                <a class="filter-tab<?= $filter === 'all' ? ' active' : '' ?>" href="my-audits.php?filter=all"><span data-i18n="myAuditsFilterAllLabel">Tümü</span> (<?= $totalAssigned ?>)</a>
                <a class="filter-tab<?= $filter === 'pending' ? ' active' : '' ?>" href="my-audits.php?filter=pending"><span data-i18n="myAuditsFilterPendingLabel">Devam Eden</span> (<?= $pendingAssigned ?>)</a>
                <a class="filter-tab<?= $filter === 'completed' ? ' active' : '' ?>" href="my-audits.php?filter=completed"><span data-i18n="myAuditsFilterCompletedLabel">Tamamlanan</span> (<?= $completedAssigned ?>)</a>
                <a class="filter-tab<?= $filter === 'overdue' ? ' active' : '' ?>" href="my-audits.php?filter=overdue"><span data-i18n="myAuditsFilterOverdueLabel">Geciken</span> (<?= $overdueAssigned ?>)</a>
            </div>

            <?php if (!$displayAudits): ?>
                <div class="empty-state" data-i18n="noAssignedAuditsText">Henüz size atanmış bir denetim yok.</div>
            <?php else: ?>
                <div class="record-card-grid">
                    <?php foreach ($displayAudits as $audit): ?>
                        <?php
                        $checklistTotal = (int) $audit["checklist_total"];
                        $checklistDone = (int) $audit["checklist_done"];
                        $progress = $checklistTotal > 0 ? (int) round(($checklistDone / $checklistTotal) * 100) : 0;
                        ?>
                        <a class="record-card document-card" href="audit-detail.php?id=<?= (int) $audit["id"] ?>">
                            <div class="record-card-topline">
                                <span><?= htmlspecialchars($audit["company_name"], ENT_QUOTES, "UTF-8") ?></span>
                                <span class="status-badge" data-i18n="<?= 'auditStatus_' . $audit["status"] ?? '' ?>"><?= htmlspecialchars($auditStatusLabels[$audit["status"]] ?? $audit["status"], ENT_QUOTES, "UTF-8") ?></span>
                                <?php if ($audit["_pending"] && !empty($audit["planned_date"]) && $audit["planned_date"] < $today): ?>
                                    <span class="record-card-label danger-text" data-i18n="myAuditOverdueTag">Gecikti</span>
                                <?php endif; ?>
                            </div>
                            <h3><?= htmlspecialchars($audit["title"], ENT_QUOTES, "UTF-8") ?></h3>
                            <p><?= htmlspecialchars($audit["audit_type"] ?: "-", ENT_QUOTES, "UTF-8") ?> · <?= htmlspecialchars($audit["planned_date"] ?: "-", ENT_QUOTES, "UTF-8") ?></p>
                            <div class="status-chart-row">
                                <span data-i18n="myAuditsProgressLabel">Kontrol listesi</span>
                                <div class="table-progress"><i style="width: <?= $progress ?>%"></i></div>
                                <strong><?= $checklistDone ?>/<?= $checklistTotal ?></strong>
                            </div>
                            <span class="record-card-cta">
                                <?php if ($audit["status"] === "planned"): ?><span data-i18n="myAuditStartCta">Denetime Başla</span>
                                <?php elseif ($audit["status"] === "in_progress"): ?><span data-i18n="myAuditContinueCta">Denetime Devam Et</span>
                                <?php else: ?><span data-i18n="myAuditViewReportCta">Raporu Görüntüle</span><?php endif; ?>
                                →
                            </span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </main>
    <script src="assets/js/theme.js"></script>
    <script src="assets/js/language.js"></script>
    <script src="assets/js/sidebar.js"></script>
    <script src="assets/js/pwa.js"></script>
</body>
</html>
