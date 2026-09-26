<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/access.php';

// Denetci rolunun kendi calisma alani var.
if (qmsIsAuditor()) {
    header("Location: my-audits.php");
    exit;
}

$userId = (int) ($_SESSION["qms_user_id"] ?? 0);
$isSuperAdmin = ($_SESSION["qms_role"] ?? "") === "super_admin";

// Sayaclar da tenant kapsamina uyar; kapsam tek kaynaktan gelir.
$scope = qmsCompanyScope('company_id', qmsVisibleCompanyIds($pdo, $userId, qmsCurrentRole()));
$scopeClause = $scope['sql'];
$scopeParams = $scope['params'];

$scopedCount = static function (string $sql) use ($pdo, $scopeParams): int {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($scopeParams);
    return (int) $stmt->fetchColumn();
};

$auditorCount = $scopedCount("SELECT COUNT(*) FROM auditors WHERE active = 1" . $scopeClause);
$activeAuditCount = $scopedCount("SELECT COUNT(*) FROM audits WHERE active = 1" . $scopeClause);
$openNonconformityCount = $scopedCount("SELECT COUNT(*) FROM nonconformities WHERE active = 1 AND status <> 'closed'" . $scopeClause);
$documentCount = $scopedCount("SELECT COUNT(*) FROM documents WHERE active = 1" . $scopeClause);

require_once __DIR__ . '/includes/report-export-data.php';
require_once __DIR__ . '/includes/dashboard-functions.php';
require_once __DIR__ . '/includes/performance-functions.php';
require_once __DIR__ . '/includes/due-workbench-functions.php';
require_once __DIR__ . '/includes/announcement-functions.php';
require_once __DIR__ . '/includes/quality-plan-functions.php';

// Yayinda olan duyurular (kullanicinin gorebildigi kapsamda).
$dashboardAnnouncements = qmsAnnouncementList($pdo, $userId, qmsCurrentRole(), true);

// Yillik kalite plani ilerlemesi.
$dashboardPlans = qmsQualityPlanList($pdo, $userId, qmsCurrentRole());
$dashboardPlanYear = (int) date('Y');
$dashboardCurrentPlans = array_values(array_filter($dashboardPlans, static fn($p): bool => (int) $p['plan_year'] === $dashboardPlanYear));
if (!$dashboardCurrentPlans) {
    usort($dashboardPlans, static fn($a, $b): int => (int) $b['plan_year'] <=> (int) $a['plan_year']);
    $dashboardCurrentPlans = array_slice($dashboardPlans, 0, 3);
}

// Performans karti, raporlama sayfasindaki ile ayni metrigi kullanir; boylece
// paneldeki deger raporlarla tutarli kalir (varsayilan donem: son 12 ay).
$reportMetrics = buildReportExportData(
    $pdo,
    (int) $_SESSION["qms_user_id"],
    ($_SESSION["qms_role"] ?? "") === "super_admin",
    []
)["metrics"];

// Trend ve ozet aggregate sorgulardan gelir; rapor setini tekrar yuklemez.
$dashboardTrend = qmsDashboardTrend($pdo, $userId, qmsCurrentRole());
$dashboardSummary = qmsDashboardSummary($pdo, $userId, qmsCurrentRole(), $dashboardTrend);

// Yonetim kokpiti: guncel yil hedef-gerecklesen KPI matrisi + COQ mini trendi.
$cockpitYear = (int) date('Y');
$cockpitCompanies = qmsCockpitKpiMatrix($pdo, $userId, $isSuperAdmin, $cockpitYear);

// Kisisel ozet: bana atananlar + gecikenlerim + bekleyen onaylar.
$myOverdueCount = count(qmsUserOverdueAssignments($pdo, $userId));
$myOpenSections = qmsMyAssignments($pdo, $userId);
$myOpenCount = 0;
foreach ($myOpenSections as $sec) { $myOpenCount += $sec['count']; }
$pendingStmt = $pdo->prepare("SELECT COUNT(*) FROM document_approvals WHERE approver_user_id = ? AND decision = 'pending'");
$pendingStmt->execute([$userId]);
$pendingApprovalsCount = (int) $pendingStmt->fetchColumn();
$cockpitKpiLabels = qmsPerformanceKpiLabels();
$cockpitCostRows = array_values($dashboardTrend);
$cockpitMaxCost = 1.0;
foreach ($cockpitCostRows as $row) { $cockpitMaxCost = max($cockpitMaxCost, (float) $row['cost_total']); }

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
            <a class="dashboard-card metric-teal" href="announcements.php">
                <?= appIcon("complaints", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="dashboardAnnouncementsCardLabel">Duyurular</span>
                    <strong class="dashboard-card-number"><?= count($dashboardAnnouncements) ?></strong>
                </div>
            </a>
            <a class="dashboard-card metric-violet" href="quality-plan.php">
                <?= appIcon("table", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="dashboardQualityPlanCardLabel">Yıllık Plan</span>
                    <strong class="dashboard-card-number"><?= count($dashboardCurrentPlans) ?></strong>
                </div>
            </a>
        </section>

        <section class="page-section console-card">
            <div class="section-heading compact-heading">
                <div>
                    <h3 data-i18n="dashboardPersonalTitle">Kişisel Özet</h3>
                    <p data-i18n="dashboardPersonalText">Size atanmış kayıtlar, geciken işleriniz ve bekleyen onaylar.</p>
                </div>
            </div>
            <div class="dashboard-grid compact-dashboard-grid">
                <a class="dashboard-card metric-blue" href="my-assignments.php">
                    <?= appIcon("checkBadge", "dashboard-card-icon") ?>
                    <div class="dashboard-card-content"><span class="dashboard-card-label" data-i18n="dashboardPersonalOpenLabel">Bana Atananlar</span><strong class="dashboard-card-number detail-card-value"><?= $myOpenCount ?></strong></div>
                </a>
                <a class="dashboard-card <?= $myOverdueCount > 0 ? 'metric-red' : '' ?>" href="my-assignments.php">
                    <?= appIcon("alert", "dashboard-card-icon") ?>
                    <div class="dashboard-card-content"><span class="dashboard-card-label" data-i18n="dashboardPersonalOverdueLabel">Geciken İşlerim</span><strong class="dashboard-card-number detail-card-value"><?= $myOverdueCount ?></strong></div>
                </a>
                <a class="dashboard-card <?= $pendingApprovalsCount > 0 ? 'metric-orange' : '' ?>" href="document-approvals.php">
                    <?= appIcon("approvals", "dashboard-card-icon") ?>
                    <div class="dashboard-card-content"><span class="dashboard-card-label" data-i18n="dashboardPersonalApprovalsLabel">Bekleyen Onay</span><strong class="dashboard-card-number detail-card-value"><?= $pendingApprovalsCount ?></strong></div>
                </a>
            </div>
        </section>

        <?php if ($dashboardAnnouncements): ?>
        <section class="page-section console-card">
            <div class="section-heading compact-heading">
                <div>
                    <h3><?= appIcon("complaints", "heading-inline-icon") ?><span data-i18n="dashboardAnnouncementsTitle">Yayındaki Duyurular</span></h3>
                    <p data-i18n="dashboardAnnouncementsText">Şirketiniz için yayınlanan güncel duyurular.</p>
                </div>
                <a class="secondary-button secondary-button-sm" href="announcements.php" data-i18n="dashboardAnnouncementsMoreLink">Tümü</a>
            </div>
            <div class="announcement-feed">
                <?php foreach (array_slice($dashboardAnnouncements, 0, 5) as $ann): ?>
                    <div class="announcement-item">
                        <strong><?= htmlspecialchars($ann['title'], ENT_QUOTES, 'UTF-8') ?></strong>
                        <?php if ($ann['body']): ?><p><?= htmlspecialchars(mb_substr((string) $ann['body'], 0, 220), ENT_QUOTES, 'UTF-8') ?><?= mb_strlen((string) $ann['body']) > 220 ? '…' : '' ?></p><?php endif; ?>
                        <small><?= htmlspecialchars((string) $ann['created_at'], ENT_QUOTES, 'UTF-8') ?><?= $ann['creator_name'] ? ' · ' . htmlspecialchars($ann['creator_name'], ENT_QUOTES, 'UTF-8') : '' ?></small>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <?php if ($dashboardCurrentPlans): ?>
        <section class="page-section console-card">
            <div class="section-heading compact-heading">
                <div>
                    <h3><?= appIcon("table", "heading-inline-icon") ?><span data-i18n="dashboardQualityPlanTitle">Kalite Planı İlerlemesi</span></h3>
                    <p data-i18n="dashboardQualityPlanText">Yıllık kalite hedeflerinin tamamlanma durumu ve ortalama ilerlemesi.</p>
                </div>
                <a class="secondary-button secondary-button-sm" href="quality-plan.php" data-i18n="dashboardQualityPlanMoreLink">Tümü</a>
            </div>
            <div class="plan-progress-list">
                <?php foreach ($dashboardCurrentPlans as $pl): ?>
                    <?php $planPct = $pl['avg_progress'] !== null ? (int) $pl['avg_progress'] : 0; ?>
                    <div class="plan-progress-item">
                        <div class="plan-progress-head">
                            <strong><?= htmlspecialchars($pl['title'], ENT_QUOTES, 'UTF-8') ?> (<?= (int) $pl['plan_year'] ?>)</strong>
                            <span><?= (int) $pl['item_completed'] ?>/<?= (int) $pl['item_total'] ?> tamamlandı · %<?= $planPct ?></span>
                        </div>
                        <div class="progress-track"><div class="progress-fill" style="width:<?= min(100, $planPct) ?>%"></div></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <section class="page-section console-card summary-card">
            <div class="section-heading compact-heading">
                <div>
                    <h3><?= appIcon("sparkles", "heading-inline-icon") ?><span data-i18n="dashboardSummaryTitle">Dönem Özeti</span></h3>
                    <p data-i18n="dashboardSummaryText">Son 12 ayın kayıtlarından yerel olarak derlenen özet; harici bir servis kullanmaz.</p>
                </div>
            </div>
            <p class="summary-headline"><?= htmlspecialchars($dashboardSummary["headline"], ENT_QUOTES, "UTF-8") ?></p>
            <ul class="summary-points">
                <?php foreach ($dashboardSummary["points"] as $point): ?>
                    <li class="summary-point tone-<?= htmlspecialchars($point["tone"], ENT_QUOTES, "UTF-8") ?>"><?= htmlspecialchars($point["text"], ENT_QUOTES, "UTF-8") ?></li>
                <?php endforeach; ?>
            </ul>
        </section>

        <section class="page-section console-card">
            <div class="section-heading compact-heading">
                <div>
                    <h3 data-i18n="dashboardTrendsTitle">Son 12 Ay Trendleri</h3>
                    <p data-i18n="dashboardTrendsText">Aylık denetim, uygunsuzluk, tamamlanan aksiyon, eğitim ve şikayet hareketi.</p>
                </div>
            </div>
            <?php
            $trendRows = array_values($dashboardTrend);
            $trendSeries = [
                ["key" => "audits", "label" => "Denetimler", "i18n" => "dashboardTrendAuditsLabel", "icon" => "check", "color" => "blue"],
                ["key" => "nonconformities", "label" => "Uygunsuzluklar", "i18n" => "dashboardTrendNonconformitiesLabel", "icon" => "alert", "color" => "orange"],
                ["key" => "actions_completed", "label" => "Tamamlanan Aksiyon", "i18n" => "dashboardTrendActionsLabel", "icon" => "checkBadge", "color" => "violet"],
                ["key" => "trainings_completed", "label" => "Tamamlanan Eğitim", "i18n" => "dashboardTrendTrainingsLabel", "icon" => "training", "color" => "teal"],
                ["key" => "complaints", "label" => "Şikayetler", "i18n" => "dashboardTrendComplaintsLabel", "icon" => "complaints", "color" => "brand"],
            ];
            ?>
            <div class="trend-list">
                <?php foreach ($trendSeries as $series): ?>
                    <?php $seriesMax = 1; foreach ($trendRows as $row) { $seriesMax = max($seriesMax, (int) $row[$series["key"]]); } ?>
                    <div class="trend-series">
                        <div class="trend-series-head">
                            <?= appIcon($series["icon"], "trend-series-icon") ?>
                            <span data-i18n="<?= $series["i18n"] ?>"><?= htmlspecialchars($series["label"], ENT_QUOTES, "UTF-8") ?></span>
                        </div>
                        <div class="trend-strip">
                            <?php foreach ($trendRows as $row): ?>
                                <?php $trendValue = (int) $row[$series["key"]]; $trendHeight = $trendValue > 0 ? round(($trendValue / $seriesMax) * 100) : 0; ?>
                                <div class="trend-bar" title="<?= htmlspecialchars($row["label"] . ": " . $trendValue, ENT_QUOTES, "UTF-8") ?>">
                                    <span class="trend-bar-value"><?= $trendValue > 0 ? $trendValue : "" ?></span>
                                    <span class="trend-bar-track"><span class="trend-bar-fill bar-<?= $series["color"] ?>" style="height:<?= $trendHeight ?>%"></span></span>
                                    <span class="trend-bar-label"><?= htmlspecialchars($row["label"], ENT_QUOTES, "UTF-8") ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="page-section console-card">
            <div class="section-heading compact-heading">
                <div>
                    <h3 data-i18n="cockpitCoqTitle">COQ Trendi</h3>
                    <p data-i18n="cockpitCoqText">Kalite maliyeti (önleme, değerlendirme, hata) aylık dağılımı.</p>
                </div>
                <a class="secondary-button secondary-button-sm" href="quality-cost-trend.php" data-i18n="cockpitCoqDetailLink">Detay</a>
            </div>
            <?php if (max(array_column($cockpitCostRows, 'cost_total')) > 0): ?>
            <div class="trend-chart">
                <?php foreach ($cockpitCostRows as $row): ?>
                    <div class="trend-column">
                        <div class="cost-trend-bars">
                            <div class="cost-bar-stack" title="Aylık toplam: <?= number_format((float) $row['cost_total'], 2) ?> ₺">
                                <?php if ((float) $row['prevention'] > 0): ?><span class="cost-stack-seg seg-prevention" style="height: <?= max(2, ((float) $row['prevention'] / $cockpitMaxCost) * 100) ?>%;"></span><?php endif; ?>
                                <?php if ((float) $row['appraisal'] > 0): ?><span class="cost-stack-seg seg-appraisal" style="height: <?= max(2, ((float) $row['appraisal'] / $cockpitMaxCost) * 100) ?>%;"></span><?php endif; ?>
                                <?php if ((float) $row['internal_failure'] > 0): ?><span class="cost-stack-seg seg-internal" style="height: <?= max(2, ((float) $row['internal_failure'] / $cockpitMaxCost) * 100) ?>%;"></span><?php endif; ?>
                                <?php if ((float) $row['external_failure'] > 0): ?><span class="cost-stack-seg seg-external" style="height: <?= max(2, ((float) $row['external_failure'] / $cockpitMaxCost) * 100) ?>%;"></span><?php endif; ?>
                            </div>
                        </div>
                        <small><?= htmlspecialchars($row['label'], ENT_QUOTES, 'UTF-8') ?></small>
                        <div class="cost-trend-total"><strong><?= number_format((float) $row['cost_total'], 0) ?></strong></div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="chart-legend">
                <span><i class="legend-blue"></i><span data-i18n="costTypePreventionLabel">Önleme</span></span>
                <span><i class="legend-teal"></i><span data-i18n="costTypeAppraisalLabel">Değerlendirme</span></span>
                <span><i class="legend-orange"></i><span data-i18n="costTypeInternalFailureLabel">İç Hata</span></span>
                <span><i class="legend-red"></i><span data-i18n="costTypeExternalFailureLabel">Dış Hata</span></span>
            </div>
            <?php else: ?>
                <div class="empty-state" data-i18n="cockpitCoqEmpty">Seçilen dönemde kalite maliyeti kaydı bulunmuyor.</div>
            <?php endif; ?>
        </section>

        <section class="page-section console-card">
            <div class="section-heading compact-heading">
                <div>
                    <h3 data-i18n="cockpitKpiTitle">Hedef vs Gerçekleşen</h3>
                    <p data-i18n="cockpitKpiText">Güncel yıl (<?= $cockpitYear ?>) KPI hedefleri ve gerçekleşen değerler.</p>
                </div>
                <a class="secondary-button secondary-button-sm" href="performance.php" data-i18n="cockpitKpiDetailLink">Performans</a>
            </div>
            <?php if (!$cockpitCompanies): ?>
                <div class="empty-state" data-i18n="cockpitKpiEmpty">Hedef koyulmuş şirket bulunmuyor. Performans sayfasından hedef ekleyin.</div>
            <?php else: ?>
                <div class="cockpit-kpi-grid">
                    <?php foreach ($cockpitCompanies as $company): ?>
                        <div class="cockpit-kpi-card">
                            <div class="cockpit-kpi-head">
                                <strong><?= htmlspecialchars($company['name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                <span class="status-pill <?= $company['all_on_track'] ? 'on-track' : 'off-track' ?>"><?= $company['on_track_count'] ?>/<?= $company['target_count'] ?> &#x2713;</span>
                            </div>
                            <div class="table-scroll">
                                <table class="data-table compact-table">
                                    <thead>
                                        <tr>
                                            <th data-i18n="cockpitKpiKpiTh">KPI</th>
                                            <th data-i18n="cockpitKpiTargetTh">Hedef</th>
                                            <th data-i18n="cockpitKpiActualTh">Gerçekleşen</th>
                                            <th data-i18n="cockpitKpiStatusTh">Durum</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($company['rows'] as $row): if ($row['target'] === null) { continue; } ?>
                                            <tr>
                                                <td><?= htmlspecialchars($cockpitKpiLabels[$row['kpi_key']] ?? $row['kpi_key'], ENT_QUOTES, 'UTF-8') ?></td>
                                                <td><?= htmlspecialchars((string) $row['target'], ENT_QUOTES, 'UTF-8') ?><?= htmlspecialchars($row['unit'], ENT_QUOTES, 'UTF-8') ?></td>
                                                <td><?= $row['actual'] === null ? '-' : htmlspecialchars((string) $row['actual'], ENT_QUOTES, 'UTF-8') . htmlspecialchars($row['unit'], ENT_QUOTES, 'UTF-8') ?></td>
                                                <td>
                                                    <?php if ($row['on_track'] === null): ?>
                                                        <span class="status-pill no-target" data-i18n="cockpitKpiNa">–</span>
                                                    <?php elseif ($row['on_track']): ?>
                                                        <span class="status-pill on-track" data-i18n="cockpitKpiOnTrack">Hedefte</span>
                                                    <?php else: ?>
                                                        <span class="status-pill off-track" data-i18n="cockpitKpiOffTrack">Sapma var</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
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
