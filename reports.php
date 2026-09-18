<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';

$userId = (int) ($_SESSION["qms_user_id"] ?? 0);
$isSuperAdmin = ($_SESSION["qms_role"] ?? "") === "super_admin";
$companyIds = [];

if (!$isSuperAdmin) {
    $assignmentStmt = $pdo->prepare("SELECT company_id FROM company_admin_assignments WHERE admin_user_id = :user_id AND active = 1");
    $assignmentStmt->execute(["user_id" => $userId]);
    $companyIds = array_map('intval', $assignmentStmt->fetchAll(PDO::FETCH_COLUMN));
}

$companyScopeSql = "";
$companyScopeParams = [];
if (!$isSuperAdmin) {
    if ($companyIds) {
        $companyScopeSql = " AND companies.id IN (" . implode(',', array_fill(0, count($companyIds), '?')) . ")";
        $companyScopeParams = $companyIds;
    } else {
        $companyScopeSql = " AND 1 = 0";
    }
}

$companyStmt = $pdo->prepare("SELECT companies.id, companies.company_name FROM companies WHERE companies.active = 1" . $companyScopeSql . " ORDER BY companies.company_name");
$companyStmt->execute($companyScopeParams);
$companies = $companyStmt->fetchAll(PDO::FETCH_ASSOC);
$allowedCompanyIds = array_map('intval', array_column($companies, 'id'));

$defaultStart = date("Y-m-d", strtotime("-12 months +1 day"));
$defaultEnd = date("Y-m-d");
$startDate = $_GET["start_date"] ?? $defaultStart;
$endDate = $_GET["end_date"] ?? $defaultEnd;
$selectedCompanyId = (int) ($_GET["company_id"] ?? 0);

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) $startDate = $defaultStart;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) $endDate = $defaultEnd;
if ($startDate > $endDate) [$startDate, $endDate] = [$endDate, $startDate];
if ($selectedCompanyId > 0 && !in_array($selectedCompanyId, $allowedCompanyIds, true)) $selectedCompanyId = 0;

function fetchReportRows(PDO $pdo, string $sql, array $baseParams, int $selectedCompanyId): array
{
    if ($selectedCompanyId > 0) {
        $sql .= " AND companies.id = ?";
        $baseParams[] = $selectedCompanyId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($baseParams);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$periodParams = array_merge([$startDate . " 00:00:00", $endDate . " 23:59:59"], $companyScopeParams);

$audits = fetchReportRows(
    $pdo,
    "SELECT audits.id, audits.company_id, audits.status, audits.created_at, companies.company_name
     FROM audits INNER JOIN companies ON companies.id = audits.company_id
     WHERE audits.active = 1 AND audits.created_at BETWEEN ? AND ?" . $companyScopeSql,
    $periodParams,
    $selectedCompanyId
);

$nonconformities = fetchReportRows(
    $pdo,
    "SELECT nonconformities.id, nonconformities.company_id, nonconformities.status,
            nonconformities.severity, nonconformities.created_at, nonconformities.updated_at,
            companies.company_name
     FROM nonconformities INNER JOIN companies ON companies.id = nonconformities.company_id
     WHERE nonconformities.active = 1 AND nonconformities.created_at BETWEEN ? AND ?" . $companyScopeSql,
    $periodParams,
    $selectedCompanyId
);

$correctiveActions = fetchReportRows(
    $pdo,
    "SELECT corrective_actions.id, nonconformities.company_id, corrective_actions.status,
            corrective_actions.due_date, corrective_actions.created_at, corrective_actions.completed_at,
            companies.company_name
     FROM corrective_actions
     INNER JOIN nonconformities ON nonconformities.id = corrective_actions.nonconformity_id
     INNER JOIN companies ON companies.id = nonconformities.company_id
     WHERE corrective_actions.active = 1 AND corrective_actions.created_at BETWEEN ? AND ?" . $companyScopeSql,
    $periodParams,
    $selectedCompanyId
);

$documents = fetchReportRows(
    $pdo,
    "SELECT documents.id, documents.company_id, documents.status, documents.review_date,
            documents.created_at, companies.company_name
     FROM documents INNER JOIN companies ON companies.id = documents.company_id
     WHERE documents.active = 1 AND documents.created_at BETWEEN ? AND ?" . $companyScopeSql,
    $periodParams,
    $selectedCompanyId
);

$reviewParams = $companyScopeParams;
$reviewDocuments = fetchReportRows(
    $pdo,
    "SELECT documents.id, documents.company_id, documents.review_date, documents.status, companies.company_name
     FROM documents INNER JOIN companies ON companies.id = documents.company_id
     WHERE documents.active = 1 AND documents.status <> 'archived'" . $companyScopeSql,
    $reviewParams,
    $selectedCompanyId
);

$closedNonconformities = array_filter($nonconformities, static fn($item) => $item["status"] === "closed");
$totalCloseDays = 0;
foreach ($closedNonconformities as $item) {
    $closedAt = new DateTime($item["updated_at"] ?: $item["created_at"]);
    $createdAt = new DateTime($item["created_at"]);
    $totalCloseDays += max(0, (int) $createdAt->diff($closedAt)->format('%a'));
}

$completedActions = array_filter($correctiveActions, static fn($item) => in_array($item["status"], ["completed", "closed"], true));
$overdueActions = array_filter($correctiveActions, static fn($item) => $item["due_date"] && $item["due_date"] < date("Y-m-d") && !in_array($item["status"], ["completed", "closed"], true));
$reviewThreshold = date("Y-m-d", strtotime("+30 days"));
$reviewDue = array_filter($reviewDocuments, static fn($item) => $item["review_date"] && $item["review_date"] <= $reviewThreshold);

$auditCount = count($audits);
$nonconformityCount = count($nonconformities);
$nonconformityRate = $auditCount > 0 ? round(($nonconformityCount / $auditCount) * 100, 1) : 0;
$actionCompletionRate = count($correctiveActions) > 0 ? round((count($completedActions) / count($correctiveActions)) * 100, 1) : 0;
$averageCloseDays = count($closedNonconformities) > 0 ? round($totalCloseDays / count($closedNonconformities), 1) : 0;

$documentStatuses = ["draft" => 0, "review" => 0, "approved" => 0, "published" => 0, "archived" => 0];
foreach ($documents as $document) {
    if (isset($documentStatuses[$document["status"]])) $documentStatuses[$document["status"]]++;
}
$maxDocumentStatus = max(1, ...array_values($documentStatuses));

$months = [];
$cursor = new DateTime(date("Y-m-01", strtotime($startDate)));
$lastMonth = new DateTime(date("Y-m-01", strtotime($endDate)));
while ($cursor <= $lastMonth && count($months) < 24) {
    $key = $cursor->format("Y-m");
    $months[$key] = ["label" => $cursor->format("m/Y"), "audits" => 0, "nonconformities" => 0];
    $cursor->modify("+1 month");
}
foreach ($audits as $audit) {
    $key = date("Y-m", strtotime($audit["created_at"]));
    if (isset($months[$key])) $months[$key]["audits"]++;
}
foreach ($nonconformities as $item) {
    $key = date("Y-m", strtotime($item["created_at"]));
    if (isset($months[$key])) $months[$key]["nonconformities"]++;
}
$maxTrend = 1;
foreach ($months as $month) $maxTrend = max($maxTrend, $month["audits"], $month["nonconformities"]);

$companyPerformance = [];
foreach ($companies as $company) {
    if ($selectedCompanyId > 0 && (int) $company["id"] !== $selectedCompanyId) continue;
    $companyPerformance[(int) $company["id"]] = ["name" => $company["company_name"], "audits" => 0, "nonconformities" => 0, "actions" => 0, "completed" => 0];
}
foreach ($audits as $item) if (isset($companyPerformance[(int) $item["company_id"]])) $companyPerformance[(int) $item["company_id"]]["audits"]++;
foreach ($nonconformities as $item) if (isset($companyPerformance[(int) $item["company_id"]])) $companyPerformance[(int) $item["company_id"]]["nonconformities"]++;
foreach ($correctiveActions as $item) if (isset($companyPerformance[(int) $item["company_id"]])) {
    $companyPerformance[(int) $item["company_id"]]["actions"]++;
    if (in_array($item["status"], ["completed", "closed"], true)) $companyPerformance[(int) $item["company_id"]]["completed"]++;
}

$activeNav = "reports";
$exportQuery = http_build_query([
    "company_id" => $selectedCompanyId,
    "start_date" => $startDate,
    "end_date" => $endDate,
]);

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="theme-color" content="#f9fafb">
    <title>QMS Raporlama</title><link rel="manifest" href="manifest.webmanifest"><link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml"><link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar"><div class="topbar-inner"><div class="page-title-block"><strong data-i18n="reportingTitle">Raporlama ve KPI</strong><span data-i18n="reportingText">Kalite performansını şirket ve dönem bazında izleyin.</span></div><div class="topbar-actions"><button class="topbar-button" id="languageToggle" type="button">EN</button><button class="topbar-button" id="themeToggle" type="button" aria-label="Tema değiştir">🌙</button></div></div></header>
    <main class="page-container">
        <section class="page-heading page-heading-actions">
            <div><span class="section-kicker" data-i18n="sidebarOverviewLabel">Genel</span><h1 data-i18n="reportingTitle">Raporlama ve KPI</h1><p data-i18n="reportingText">Kalite performansını şirket ve dönem bazında izleyin.</p></div>
            <div class="report-export-actions">
                <a class="primary-button" href="report-export-xlsx.php?<?= htmlspecialchars($exportQuery, ENT_QUOTES, "UTF-8") ?>" data-i18n="downloadExcelButton">Excel İndir</a>
                <a class="primary-button" href="report-export-pdf.php?<?= htmlspecialchars($exportQuery, ENT_QUOTES, "UTF-8") ?>" data-i18n="downloadPdfButton">PDF İndir</a>
            </div>
        </section>
        <section class="filter-panel"><form class="filter-form report-filter-form" method="get" action="reports.php"><label><span data-i18n="companySelectLabel">Şirket</span><select name="company_id"><option value="0" data-i18n="allCompaniesOption">Tüm şirketler</option><?php foreach ($companies as $company): ?><option value="<?= (int) $company["id"] ?>" <?= $selectedCompanyId === (int) $company["id"] ? "selected" : "" ?>><?= htmlspecialchars($company["company_name"], ENT_QUOTES, "UTF-8") ?></option><?php endforeach; ?></select></label><label><span data-i18n="startDateLabel">Başlangıç</span><input type="date" name="start_date" value="<?= htmlspecialchars($startDate, ENT_QUOTES, "UTF-8") ?>"></label><label><span data-i18n="endDateLabel">Bitiş</span><input type="date" name="end_date" value="<?= htmlspecialchars($endDate, ENT_QUOTES, "UTF-8") ?>"></label><div class="filter-actions"><button class="primary-button" type="submit" data-i18n="applyFiltersButton">Filtrele</button><a class="secondary-button" href="reports.php" data-i18n="clearFiltersButton">Temizle</a></div></form></section>

        <section class="dashboard-grid report-kpi-grid">
            <div class="dashboard-card metric-blue"><?= appIcon("check", "dashboard-card-icon") ?><div class="dashboard-card-content"><span class="dashboard-card-label" data-i18n="auditCountKpi">Denetim Sayısı</span><strong class="dashboard-card-number"><?= $auditCount ?></strong></div></div>
            <div class="dashboard-card metric-orange"><?= appIcon("alert", "dashboard-card-icon") ?><div class="dashboard-card-content"><span class="dashboard-card-label" data-i18n="nonconformityRateKpi">Denetim Başına Uygunsuzluk</span><strong class="dashboard-card-number"><?= $nonconformityRate ?>%</strong></div></div>
            <div class="dashboard-card metric-teal"><?= appIcon("trend", "dashboard-card-icon") ?><div class="dashboard-card-content"><span class="dashboard-card-label" data-i18n="actionCompletionKpi">Aksiyon Tamamlama</span><strong class="dashboard-card-number"><?= $actionCompletionRate ?>%</strong></div></div>
            <div class="dashboard-card metric-red"><?= appIcon("clock", "dashboard-card-icon") ?><div class="dashboard-card-content"><span class="dashboard-card-label" data-i18n="overdueActionsLabel">Geciken Kayıtlar</span><strong class="dashboard-card-number"><?= count($overdueActions) ?></strong></div></div>
            <div class="dashboard-card metric-violet"><?= appIcon("clock", "dashboard-card-icon") ?><div class="dashboard-card-content"><span class="dashboard-card-label" data-i18n="averageClosureKpi">Ortalama Kapanma</span><strong class="dashboard-card-number"><?= $averageCloseDays ?> <small data-i18n="dayLabel">gün</small></strong></div></div>
            <div class="dashboard-card metric-orange"><?= appIcon("documents", "dashboard-card-icon") ?><div class="dashboard-card-content"><span class="dashboard-card-label" data-i18n="reviewDueDocumentsLabel">Gözden Geçirilecek</span><strong class="dashboard-card-number"><?= count($reviewDue) ?></strong></div></div>
        </section>

        <section class="report-layout">
            <article class="report-panel report-panel-wide"><div class="section-heading compact-heading"><div><h2 data-i18n="auditTrendTitle">Denetim ve Uygunsuzluk Trendi</h2><p data-i18n="auditTrendText">Seçilen dönemde aylık kayıt dağılımı.</p></div></div><div class="trend-chart"><?php foreach ($months as $month): ?><div class="trend-column"><div class="trend-bars"><span class="trend-bar audits" style="height: <?= max(4, ($month["audits"] / $maxTrend) * 100) ?>%" title="Denetim: <?= $month["audits"] ?>"></span><span class="trend-bar nonconformities" style="height: <?= max(4, ($month["nonconformities"] / $maxTrend) * 100) ?>%" title="Uygunsuzluk: <?= $month["nonconformities"] ?>"></span></div><small><?= htmlspecialchars($month["label"], ENT_QUOTES, "UTF-8") ?></small></div><?php endforeach; ?></div><div class="chart-legend"><span><i class="legend-blue"></i><span data-i18n="auditsLegend">Denetimler</span></span><span><i class="legend-orange"></i><span data-i18n="nonconformitiesLegend">Uygunsuzluklar</span></span></div></article>
            <article class="report-panel"><div class="section-heading compact-heading"><div><h2 data-i18n="documentStatusReportTitle">Doküman Durumları</h2><p data-i18n="documentStatusReportText">Seçilen dönemde oluşturulan dokümanlar.</p></div></div><div class="status-chart"><?php foreach ($documentStatuses as $status => $count): ?><div class="status-chart-row"><span><?= htmlspecialchars(ucfirst($status), ENT_QUOTES, "UTF-8") ?></span><div><i style="width: <?= ($count / $maxDocumentStatus) * 100 ?>%"></i></div><strong><?= $count ?></strong></div><?php endforeach; ?></div></article>
        </section>

        <section class="page-section"><div class="section-heading"><div><h2 data-i18n="companyPerformanceTitle">Şirket Performansı</h2><p data-i18n="companyPerformanceText">Denetim ve aksiyon sonuçlarının şirket bazlı özeti.</p></div></div><?php if (!$companyPerformance): ?><div class="empty-state" data-i18n="noReportDataText">Seçilen dönem için rapor verisi bulunmuyor.</div><?php else: ?><div class="report-table-wrap"><table class="report-table"><thead><tr><th data-i18n="companyNameLabel">Şirket</th><th data-i18n="auditsLegend">Denetimler</th><th data-i18n="nonconformitiesLegend">Uygunsuzluklar</th><th data-i18n="correctiveActionsTitle">Düzeltici Faaliyetler</th><th data-i18n="actionCompletionKpi">Aksiyon Tamamlama</th></tr></thead><tbody><?php foreach ($companyPerformance as $row): $rate = $row["actions"] > 0 ? round(($row["completed"] / $row["actions"]) * 100, 1) : 0; ?><tr><td><?= htmlspecialchars($row["name"], ENT_QUOTES, "UTF-8") ?></td><td><?= $row["audits"] ?></td><td><?= $row["nonconformities"] ?></td><td><?= $row["actions"] ?></td><td><span class="table-progress"><i style="width: <?= $rate ?>%"></i></span><strong><?= $rate ?>%</strong></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
    </main>
    <script src="assets/js/theme.js"></script><script src="assets/js/language.js"></script><script src="assets/js/sidebar.js"></script><script src="assets/js/pwa.js"></script>
</body>
</html>
