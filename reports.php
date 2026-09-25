<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/access.php';
require_once __DIR__ . '/includes/supplier-functions.php';
require_once __DIR__ . '/includes/complaint-functions.php';
require_once __DIR__ . '/includes/review-functions.php';
require_once __DIR__ . '/includes/audit-program-functions.php';
require_once __DIR__ . '/includes/equipment-functions.php';
require_once __DIR__ . '/includes/permissions.php';

// Raporlama ve KPI erisimi tek kaynaktan gelir (RBAC servisi).
if (!qmsCanSession('reports.view')) {
    header("Location: dashboard.php");
    exit;
}

$userId = (int) ($_SESSION["qms_user_id"] ?? 0);
$isSuperAdmin = ($_SESSION["qms_role"] ?? "") === "super_admin";

// Kapsam tek kaynaktan: role gore gorunur sirketler (null = kisitlama yok).
$companyScope = qmsCompanyScope('companies.id', qmsVisibleCompanyIds($pdo, $userId, qmsCurrentRole()));
$companyScopeSql = $companyScope['sql'];
$companyScopeParams = $companyScope['params'];

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

$trainings = fetchReportRows(
    $pdo,
    "SELECT trainings.id, trainings.company_id, trainings.status, trainings.created_at,
            trainings.planned_date, trainings.completed_date, companies.company_name
     FROM trainings INNER JOIN companies ON companies.id = trainings.company_id
     WHERE trainings.active = 1 AND trainings.created_at BETWEEN ? AND ?" . $companyScopeSql,
    $periodParams,
    $selectedCompanyId
);

$suppliers = fetchReportRows(
    $pdo,
    "SELECT suppliers.id, suppliers.company_id, suppliers.status, suppliers.name,
            suppliers.created_at, companies.company_name
     FROM suppliers INNER JOIN companies ON companies.id = suppliers.company_id
     WHERE suppliers.active = 1 AND suppliers.created_at BETWEEN ? AND ?" . $companyScopeSql,
    $periodParams,
    $selectedCompanyId
);

$complaints = fetchReportRows(
    $pdo,
    "SELECT complaints.id, complaints.company_id, complaints.status, complaints.severity,
            complaints.subject, complaints.created_at, companies.company_name
     FROM complaints INNER JOIN companies ON companies.id = complaints.company_id
     WHERE complaints.active = 1 AND complaints.created_at BETWEEN ? AND ?" . $companyScopeSql,
    $periodParams,
    $selectedCompanyId
);

$reviews = fetchReportRows(
    $pdo,
    "SELECT management_reviews.id, management_reviews.company_id, management_reviews.status,
            management_reviews.created_at, companies.company_name,
            (SELECT COUNT(*) FROM management_review_items
              WHERE management_review_items.review_id = management_reviews.id
                AND management_review_items.item_type = 'action') AS action_count
     FROM management_reviews INNER JOIN companies ON companies.id = management_reviews.company_id
     WHERE management_reviews.active = 1 AND management_reviews.created_at BETWEEN ? AND ?" . $companyScopeSql,
    $periodParams,
    $selectedCompanyId
);

$auditPrograms = fetchReportRows(
    $pdo,
    "SELECT audit_programs.id, audit_programs.company_id, audit_programs.status, audit_programs.created_at,
            companies.company_name,
            (SELECT COUNT(*) FROM audit_program_audits
              WHERE audit_program_audits.program_id = audit_programs.id) AS audit_count
     FROM audit_programs INNER JOIN companies ON companies.id = audit_programs.company_id
     WHERE audit_programs.active = 1 AND audit_programs.created_at BETWEEN ? AND ?" . $companyScopeSql,
    $periodParams,
    $selectedCompanyId
);

$equipmentRecords = fetchReportRows(
    $pdo,
    "SELECT equipment.id, equipment.company_id, equipment.name, equipment.next_calibration_date,
            equipment.created_at, companies.company_name
     FROM equipment INNER JOIN companies ON companies.id = equipment.company_id
     WHERE equipment.active = 1 AND equipment.created_at BETWEEN ? AND ?" . $companyScopeSql,
    $periodParams,
    $selectedCompanyId
);
$equipmentOverdueCount = 0;
foreach ($equipmentRecords as $item) { if (qmsEquipmentCalibrationStatus($item) === 'overdue') $equipmentOverdueCount++; }

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
$completedTrainings = array_filter($trainings, static fn($item) => $item["status"] === "completed");
$trainingCompletionRate = count($trainings) > 0 ? round((count($completedTrainings) / count($trainings)) * 100, 1) : 0;

// Tedarikci puanlari degerlendirmelerden hesaplanir; kayitli kopya kolon yok.
$supplierEvaluations = [];
$supplierIds = array_map("intval", array_column($suppliers, "id"));
if ($supplierIds) {
    $supplierMarks = implode(",", array_fill(0, count($supplierIds), "?"));
    $supplierEvaluationStmt = $pdo->prepare("SELECT * FROM supplier_evaluations WHERE supplier_id IN ($supplierMarks)");
    $supplierEvaluationStmt->execute($supplierIds);
    foreach ($supplierEvaluationStmt->fetchAll(PDO::FETCH_ASSOC) as $supplierEvaluation) {
        $supplierEvaluations[(int) $supplierEvaluation["supplier_id"]][] = $supplierEvaluation;
    }
}
$supplierScoreTotal = 0.0;
$supplierScoreCount = 0;
foreach ($suppliers as $supplier) {
    $supplierSummary = qmsSupplierEvaluationSummary($supplierEvaluations[(int) $supplier["id"]] ?? []);
    if ($supplierSummary["average"] !== null) {
        $supplierScoreTotal += $supplierSummary["average"];
        $supplierScoreCount++;
    }
}
$supplierAverageScore = $supplierScoreCount > 0 ? round($supplierScoreTotal / $supplierScoreCount, 1) : null;
$openComplaintCount = count(array_filter($complaints, static fn($item) => qmsComplaintIsOpen((string) $item["status"])));
$reviewCount = count($reviews);
$auditProgramCount = count($auditPrograms);
$auditProgramActiveCount = count(array_filter($auditPrograms, static fn($item) => $item["status"] === "active"));
$equipmentCount = count($equipmentRecords);

$satisfactionRecords = fetchReportRows(
    $pdo,
    "SELECT responses.company_id, responses.overall_score, companies.company_name
     FROM satisfaction_responses responses INNER JOIN companies ON companies.id = responses.company_id
     WHERE responses.active = 1 AND responses.responded_at BETWEEN ? AND ?" . $companyScopeSql,
    $periodParams,
    $selectedCompanyId
);
$satisfactionCount = count($satisfactionRecords);
$satisfactionAvg = $satisfactionCount > 0 ? round((array_sum(array_column($satisfactionRecords, "overall_score"))) / $satisfactionCount, 1) : 0;

$personnelRecords = fetchReportRows(
    $pdo,
    "SELECT s.id, s.company_id, companies.company_name
     FROM staff_members s INNER JOIN companies ON companies.id = s.company_id
     WHERE s.active = 1 AND s.created_at BETWEEN ? AND ?" . $companyScopeSql,
    $periodParams,
    $selectedCompanyId
);
$personnelCount = count($personnelRecords);
$personnelExpired = 0;
$pStaffIds = array_map("intval", array_column($personnelRecords, "id"));
if ($pStaffIds !== []) {
    $pMarks = implode(",", array_fill(0, count($pStaffIds), "?"));
    $pExpStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM staff_competencies
         WHERE staff_id IN ($pMarks) AND active = 1
           AND next_assessment_date IS NOT NULL AND next_assessment_date < ?"
    );
    $pExpStmt->execute(array_merge($pStaffIds, [date("Y-m-d")]));
    $personnelExpired = (int) $pExpStmt->fetchColumn();
}

$externalAuditRecords = fetchReportRows(
    $pdo,
    "SELECT a.id, a.company_id, a.status, companies.company_name,
            (SELECT COUNT(*) FROM external_audit_findings f
              WHERE f.external_audit_id = a.id AND f.active = 1 AND f.status <> 'closed') AS open_findings
     FROM external_audits a INNER JOIN companies ON companies.id = a.company_id
     WHERE a.active = 1 AND a.created_at BETWEEN ? AND ?" . $companyScopeSql,
    $periodParams,
    $selectedCompanyId
);
$externalAuditCount = count($externalAuditRecords);
$externalAuditOpen = 0;
foreach ($externalAuditRecords as $item) { $externalAuditOpen += (int) $item["open_findings"]; }

$qualityCostRecords = fetchReportRows(
    $pdo,
    "SELECT c.company_id, c.cost_type, c.amount
     FROM quality_costs c INNER JOIN companies ON companies.id = c.company_id
     WHERE c.active = 1 AND c.incurred_on BETWEEN ? AND ?" . $companyScopeSql,
    $periodParams,
    $selectedCompanyId
);
$qualityCostTotal = 0.0;
$qualityCostFailure = 0.0;
foreach ($qualityCostRecords as $item) {
    $amt = (float) $item["amount"];
    $qualityCostTotal += $amt;
    if (in_array($item["cost_type"], ["internal_failure", "external_failure"], true)) {
        $qualityCostFailure += $amt;
    }
}
$qualityCostTotal = round($qualityCostTotal, 2);
$qualityCostFailure = round($qualityCostFailure, 2);

$copyRecords = fetchReportRows(
    $pdo,
    "SELECT c.company_id, c.status
     FROM document_copies c INNER JOIN companies ON companies.id = c.company_id
     WHERE c.active = 1 AND c.distributed_on BETWEEN ? AND ?" . $companyScopeSql,
    $periodParams,
    $selectedCompanyId
);
$copyCount = count($copyRecords);
$copyReturned = 0;
foreach ($copyRecords as $item) { if ($item["status"] === "returned") $copyReturned++; }

$approvalRunRecords = fetchReportRows(
    $pdo,
    "SELECT r.company_id, r.status
     FROM approval_runs r INNER JOIN companies ON companies.id = r.company_id
     WHERE r.active = 1 AND r.created_at BETWEEN ? AND ?" . $companyScopeSql,
    $periodParams,
    $selectedCompanyId
);
$approvalRunCount = count($approvalRunRecords);
$approvalRunApproved = 0;
$approvalRunPending = 0;
foreach ($approvalRunRecords as $item) { if ($item["status"] === "approved") $approvalRunApproved++; if ($item["status"] === "in_progress") $approvalRunPending++; }

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
    $companyPerformance[(int) $company["id"]] = ["name" => $company["company_name"], "audits" => 0, "nonconformities" => 0, "actions" => 0, "completed" => 0, "trainings" => 0, "trainingsCompleted" => 0, "suppliers" => 0, "suppliersApproved" => 0, "complaints" => 0, "complaintsOpen" => 0, "reviews" => 0, "reviewsActions" => 0, "auditPrograms" => 0, "auditProgramsActive" => 0, "equipment" => 0, "equipmentOverdue" => 0];
}
foreach ($audits as $item) if (isset($companyPerformance[(int) $item["company_id"]])) $companyPerformance[(int) $item["company_id"]]["audits"]++;
foreach ($nonconformities as $item) if (isset($companyPerformance[(int) $item["company_id"]])) $companyPerformance[(int) $item["company_id"]]["nonconformities"]++;
foreach ($correctiveActions as $item) if (isset($companyPerformance[(int) $item["company_id"]])) {
    $companyPerformance[(int) $item["company_id"]]["actions"]++;
    if (in_array($item["status"], ["completed", "closed"], true)) $companyPerformance[(int) $item["company_id"]]["completed"]++;
}
foreach ($trainings as $item) if (isset($companyPerformance[(int) $item["company_id"]])) {
    $companyPerformance[(int) $item["company_id"]]["trainings"]++;
    if ($item["status"] === "completed") $companyPerformance[(int) $item["company_id"]]["trainingsCompleted"]++;
}
foreach ($suppliers as $item) if (isset($companyPerformance[(int) $item["company_id"]])) {
    $companyPerformance[(int) $item["company_id"]]["suppliers"]++;
    if ($item["status"] === "approved") $companyPerformance[(int) $item["company_id"]]["suppliersApproved"]++;
}
foreach ($complaints as $item) if (isset($companyPerformance[(int) $item["company_id"]])) {
    $companyPerformance[(int) $item["company_id"]]["complaints"]++;
    if (qmsComplaintIsOpen((string) $item["status"])) $companyPerformance[(int) $item["company_id"]]["complaintsOpen"]++;
}
foreach ($reviews as $item) if (isset($companyPerformance[(int) $item["company_id"]])) {
    $companyPerformance[(int) $item["company_id"]]["reviews"]++;
    $companyPerformance[(int) $item["company_id"]]["reviewsActions"] += (int) $item["action_count"];
}
foreach ($auditPrograms as $item) if (isset($companyPerformance[(int) $item["company_id"]])) {
    $companyPerformance[(int) $item["company_id"]]["auditPrograms"]++;
    if ($item["status"] === "active") $companyPerformance[(int) $item["company_id"]]["auditProgramsActive"]++;
}
foreach ($equipmentRecords as $item) if (isset($companyPerformance[(int) $item["company_id"]])) {
    $companyPerformance[(int) $item["company_id"]]["equipment"]++;
    if (qmsEquipmentCalibrationStatus($item) === 'overdue') $companyPerformance[(int) $item["company_id"]]["equipmentOverdue"]++;
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
            <div class="dashboard-card metric-blue"><?= appIcon("training", "dashboard-card-icon") ?><div class="dashboard-card-content"><span class="dashboard-card-label" data-i18n="trainingCompletionKpi">Eğitim Tamamlama</span><strong class="dashboard-card-number"><?= $trainingCompletionRate ?>%</strong></div></div>
            <div class="dashboard-card metric-violet"><?= appIcon("suppliers", "dashboard-card-icon") ?><div class="dashboard-card-content"><span class="dashboard-card-label" data-i18n="supplierScoreKpi">Tedarikçi Puanı</span><strong class="dashboard-card-number"><?= $supplierAverageScore === null ? "-" : htmlspecialchars((string) $supplierAverageScore, ENT_QUOTES, "UTF-8") ?></strong></div></div>
            <div class="dashboard-card metric-orange"><?= appIcon("complaints", "dashboard-card-icon") ?><div class="dashboard-card-content"><span class="dashboard-card-label" data-i18n="complaintOpenKpi">Açık Şikayet</span><strong class="dashboard-card-number"><?= $openComplaintCount ?></strong></div></div>
            <div class="dashboard-card metric-blue"><?= appIcon("reviews", "dashboard-card-icon") ?><div class="dashboard-card-content"><span class="dashboard-card-label" data-i18n="reviewCountKpi">Gözden Geçirme</span><strong class="dashboard-card-number"><?= $reviewCount ?></strong></div></div>
            <div class="dashboard-card metric-teal"><?= appIcon("approvals", "dashboard-card-icon") ?><div class="dashboard-card-content"><span class="dashboard-card-label" data-i18n="auditProgramCountKpi">Denetim Programı</span><strong class="dashboard-card-number"><?= $auditProgramCount ?> <small data-i18n="auditProgramActiveKpi">Aktif <?= $auditProgramActiveCount ?></small></strong></div></div>
            <div class="dashboard-card metric-orange"><?= appIcon("table", "dashboard-card-icon") ?><div class="dashboard-card-content"><span class="dashboard-card-label" data-i18n="equipmentCountKpi">Ekipman</span><strong class="dashboard-card-number"><?= $equipmentCount ?> <small data-i18n="equipmentOverdueKpi">Geçmiş <?= $equipmentOverdueCount ?></small></strong></div></div>
            <div class="dashboard-card metric-violet"><?= appIcon("performance", "dashboard-card-icon") ?><div class="dashboard-card-content"><span class="dashboard-card-label" data-i18n="satisfactionAvgKpi">Memnuniyet</span><strong class="dashboard-card-number"><?= $satisfactionAvg ?> <small data-i18n="satisfactionCountKpi"><?= $satisfactionCount ?> yanıt</small></strong></div></div>
            <div class="dashboard-card metric-blue"><?= appIcon("users", "dashboard-card-icon") ?><div class="dashboard-card-content"><span class="dashboard-card-label" data-i18n="personnelCountKpi">Personel</span><strong class="dashboard-card-number"><?= $personnelCount ?> <small data-i18n="personnelExpiredKpi">Geçmiş <?= $personnelExpired ?></small></strong></div></div>
            <div class="dashboard-card metric-orange"><?= appIcon("alert", "dashboard-card-icon") ?><div class="dashboard-card-content"><span class="dashboard-card-label" data-i18n="externalAuditCountKpi">Dış Denetim</span><strong class="dashboard-card-number"><?= $externalAuditCount ?> <small data-i18n="externalAuditOpenKpi">Açık Bulgu <?= $externalAuditOpen ?></small></strong></div></div>
            <div class="dashboard-card metric-red"><?= appIcon("table", "dashboard-card-icon") ?><div class="dashboard-card-content"><span class="dashboard-card-label" data-i18n="qualityCostTotalKpi">COQ</span><strong class="dashboard-card-number"><?= number_format($qualityCostTotal, 2) ?> <small data-i18n="qualityCostFailureKpi">Hata <?= number_format($qualityCostFailure, 2) ?></small></strong></div></div>
            <div class="dashboard-card metric-blue"><?= appIcon("documents", "dashboard-card-icon") ?><div class="dashboard-card-content"><span class="dashboard-card-label" data-i18n="copyCountKpi">Dağıtılan Kopya</span><strong class="dashboard-card-number"><?= $copyCount ?> <small data-i18n="copyReturnedKpi">İade <?= $copyReturned ?></small></strong></div></div>
            <div class="dashboard-card metric-violet"><?= appIcon("approvals", "dashboard-card-icon") ?><div class="dashboard-card-content"><span class="dashboard-card-label" data-i18n="approvalRunCountKpi">Onay Akışı</span><strong class="dashboard-card-number"><?= $approvalRunCount ?> <small data-i18n="approvalRunPendingKpi">Devam <?= $approvalRunPending ?></small></strong></div></div>
        </section>

        <section class="report-layout">
            <article class="report-panel report-panel-wide"><div class="section-heading compact-heading"><div><h2 data-i18n="auditTrendTitle">Denetim ve Uygunsuzluk Trendi</h2><p data-i18n="auditTrendText">Seçilen dönemde aylık kayıt dağılımı.</p></div></div><div class="trend-chart"><?php foreach ($months as $month): ?><div class="trend-column"><div class="trend-bars"><span class="trend-bar audits" style="height: <?= max(4, ($month["audits"] / $maxTrend) * 100) ?>%" title="Denetim: <?= $month["audits"] ?>"></span><span class="trend-bar nonconformities" style="height: <?= max(4, ($month["nonconformities"] / $maxTrend) * 100) ?>%" title="Uygunsuzluk: <?= $month["nonconformities"] ?>"></span></div><small><?= htmlspecialchars($month["label"], ENT_QUOTES, "UTF-8") ?></small></div><?php endforeach; ?></div><div class="chart-legend"><span><i class="legend-blue"></i><span data-i18n="auditsLegend">Denetimler</span></span><span><i class="legend-orange"></i><span data-i18n="nonconformitiesLegend">Uygunsuzluklar</span></span></div></article>
            <article class="report-panel"><div class="section-heading compact-heading"><div><h2 data-i18n="documentStatusReportTitle">Doküman Durumları</h2><p data-i18n="documentStatusReportText">Seçilen dönemde oluşturulan dokümanlar.</p></div></div><div class="status-chart"><?php foreach ($documentStatuses as $status => $count): ?><div class="status-chart-row"><span><?= htmlspecialchars(ucfirst($status), ENT_QUOTES, "UTF-8") ?></span><div><i style="width: <?= ($count / $maxDocumentStatus) * 100 ?>%"></i></div><strong><?= $count ?></strong></div><?php endforeach; ?></div></article>
        </section>

        <section class="page-section"><div class="section-heading"><div><h2 data-i18n="companyPerformanceTitle">Şirket Performansı</h2><p data-i18n="companyPerformanceText">Denetim ve aksiyon sonuçlarının şirket bazlı özeti.</p></div></div><?php if (!$companyPerformance): ?><div class="empty-state" data-i18n="noReportDataText">Seçilen dönem için rapor verisi bulunmuyor.</div><?php else: ?><div class="report-table-wrap"><table class="report-table"><thead><tr><th data-i18n="companyNameLabel">Şirket</th><th data-i18n="auditsLegend">Denetimler</th><th data-i18n="nonconformitiesLegend">Uygunsuzluklar</th><th data-i18n="correctiveActionsTitle">Düzeltici Faaliyetler</th><th data-i18n="actionCompletionKpi">Aksiyon Tamamlama</th><th data-i18n="trainingsLegend">Eğitimler</th><th data-i18n="trainingCompletionKpi">Eğitim Tamamlama</th><th data-i18n="suppliersLegend">Tedarikçiler</th><th data-i18n="complaintsLegend">Şikayetler</th><th data-i18n="complaintOpenKpi">Açık Şikayet</th><th data-i18n="reviewsLegend">Gözden Geçirmeler</th><th data-i18n="reviewActionCountLabel">GGR Aksiyonu</th><th data-i18n="auditProgramCountKpi">Denetim Programı</th><th data-i18n="auditProgramActiveLabel">Aktif</th><th data-i18n="equipmentCountKpi">Ekipman</th><th data-i18n="equipmentOverdueKpi">Geçmiş</th></tr></thead><tbody><?php foreach ($companyPerformance as $row): $rate = $row["actions"] > 0 ? round(($row["completed"] / $row["actions"]) * 100, 1) : 0; $trainingRate = $row["trainings"] > 0 ? round(($row["trainingsCompleted"] / $row["trainings"]) * 100, 1) : 0; ?><tr><td><?= htmlspecialchars($row["name"], ENT_QUOTES, "UTF-8") ?></td><td><?= $row["audits"] ?></td><td><?= $row["nonconformities"] ?></td><td><?= $row["actions"] ?></td><td><span class="table-progress"><i style="width: <?= $rate ?>%"></i></span><strong><?= $rate ?>%</strong></td><td><?= $row["trainings"] ?></td><td><span class="table-progress"><i style="width: <?= $trainingRate ?>%"></i></span><strong><?= $trainingRate ?>%</strong></td><td><?= $row["suppliers"] ?></td><td><?= $row["complaints"] ?></td><td><?= $row["complaintsOpen"] ?></td><td><?= $row["reviews"] ?></td><td><?= $row["reviewsActions"] ?></td><td><?= $row["auditPrograms"] ?></td><td><?= $row["auditProgramsActive"] ?></td><td><?= $row["equipment"] ?></td><td><?= $row["equipmentOverdue"] ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
    </main>
    <script src="assets/js/theme.js"></script><script src="assets/js/language.js"></script><script src="assets/js/sidebar.js"></script><script src="assets/js/pwa.js"></script>
</body>
</html>
