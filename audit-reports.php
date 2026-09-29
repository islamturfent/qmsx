<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/access.php';
require_once __DIR__ . '/includes/permissions.php';
qmsRequirePermission('operations.view');

$userId = (int) ($_SESSION["qms_user_id"] ?? 0);
$role = qmsCurrentRole();

$companyScope = qmsCompanyScope('companies.id', qmsVisibleCompanyIds($pdo, $userId, $role));
$companyStmt = $pdo->prepare('SELECT companies.id, companies.company_name FROM companies WHERE companies.active = 1' . $companyScope['sql'] . ' ORDER BY companies.company_name');
$companyStmt->execute($companyScope['params']);
$companies = $companyStmt->fetchAll(PDO::FETCH_ASSOC);
$selectedCompanyId = (int) ($_GET["company_id"] ?? 0);
if (!in_array($selectedCompanyId, array_map('intval', array_column($companies, 'id')), true)) {
    $selectedCompanyId = 0;
}

$statusFilter = (string) ($_GET["status"] ?? "all");
if (!in_array($statusFilter, ["all", "draft", "final"], true)) {
    $statusFilter = "all";
}
$search = trim((string) ($_GET["q"] ?? ""));

// Kapsam raporlarin sirketi uzerinden (audit uzerinden degil - co.id).
$scope = qmsCompanyScope('r.company_id', qmsVisibleCompanyIds($pdo, $userId, $role));
$params = $scope['params'];
$where = "r.active = 1" . $scope['sql'];
if ($selectedCompanyId > 0) {
    $where .= " AND r.company_id = ?";
    $params[] = $selectedCompanyId;
}
if ($statusFilter !== "all") {
    $where .= " AND r.status = ?";
    $params[] = $statusFilter;
}
if ($search !== "") {
    $where .= " AND (a.title LIKE ? OR r.title LIKE ? OR co.company_name LIKE ?)";
    $like = "%" . $search . "%";
    array_push($params, $like, $like, $like);
}

$sql = "SELECT r.id AS report_id, r.title AS report_title, r.status, r.report_date,
            a.id AS audit_id, a.title AS audit_title, a.audit_type, co.company_name
     FROM audit_reports r
     INNER JOIN audits a ON a.id = r.audit_id
     INNER JOIN companies co ON co.id = r.company_id
     WHERE " . $where . "
     ORDER BY r.report_date DESC, r.id DESC";

$reportsStmt = $pdo->prepare($sql);
$reportsStmt->execute($params);
$reports = $reportsStmt->fetchAll(PDO::FETCH_ASSOC);

$countDraft = 0;
$countFinal = 0;
foreach ($reports as $rp) {
    if ($rp['status'] === 'draft') {
        $countDraft++;
    } elseif ($rp['status'] === 'final') {
        $countFinal++;
    }
}

$activeNav = "audit_reports";

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f9fafb">
    <title>QuAmi Denetim Raporları</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar">
        <div class="topbar-inner">
            <div class="page-title-block">
                <strong data-i18n="auditReportsMenuLabel">Denetim Raporları</strong>
                <span data-i18n="auditReportsText">Tüm denetim raporlarını listeler ve yönetir.</span>
            </div>
            <div class="topbar-actions">
                <button class="topbar-button" id="languageToggle" type="button">EN</button>
                <button class="topbar-button" id="themeToggle" type="button" aria-label="Tema değiştir">🌙</button>
            </div>
        </div>
    </header>
    <main class="page-container">
        <section class="page-heading">
            <span class="section-kicker" data-i18n="auditReportsKicker">Denetim Takibi</span>
            <h1 data-i18n="auditReportsMenuLabel">Denetim Raporları</h1>
            <p data-i18n="auditReportsText">Tüm denetim raporlarını listeler ve yönetir.</p>
        </section>

        <section class="dashboard-grid compact-dashboard-grid">
            <div class="dashboard-card metric-blue">
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="auditReportsTotal">Toplam Rapor</span>
                    <strong class="dashboard-card-number"><?= count($reports) ?></strong>
                </div>
            </div>
            <div class="dashboard-card metric-orange">
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="auditReportsDraft">Taslak</span>
                    <strong class="dashboard-card-number"><?= $countDraft ?></strong>
                </div>
            </div>
            <div class="dashboard-card metric-green">
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="auditReportsFinal">Kesinleşmiş</span>
                    <strong class="dashboard-card-number"><?= $countFinal ?></strong>
                </div>
            </div>
        </section>

        <section class="page-section console-card">
            <div class="filter-tabs">
                <form class="auditor-form finding-filter-form" method="get" action="audit-reports.php">
                    <select name="company_id">
                        <option value="0" data-i18n="allCompaniesOption">Tüm Şirketler</option>
                        <?php foreach ($companies as $c): ?>
                            <option value="<?= (int) $c["id"] ?>" <?= $selectedCompanyId === (int) $c["id"] ? "selected" : "" ?>><?= htmlspecialchars($c["company_name"], ENT_QUOTES, "UTF-8") ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="status">
                        <option value="all" <?= $statusFilter === "all" ? "selected" : "" ?> data-i18n="auditFindingsAllFilter">Tümü</option>
                        <option value="draft" <?= $statusFilter === "draft" ? "selected" : "" ?> data-i18n="auditReportsDraftFilter">Taslak</option>
                        <option value="final" <?= $statusFilter === "final" ? "selected" : "" ?> data-i18n="auditReportsFinalFilter">Kesinleşmiş</option>
                    </select>
                    <input type="text" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES, "UTF-8") ?>" placeholder="Ara...">
                    <button class="primary-button primary-button-sm" type="submit" data-i18n="applyFiltersButton">Filtrele</button>
                </form>
            </div>

            <?php if (!$reports): ?>
                <div class="empty-state" data-i18n="auditReportsEmpty">Eşleşen denetim raporu yok.</div>
            <?php else: ?>
                <div class="table-wrapper">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th data-i18n="auditFindingsCompanyCol">Şirket</th>
                                <th data-i18n="auditReportsAuditCol">Denetim</th>
                                <th data-i18n="auditReportsReportCol">Rapor</th>
                                <th data-i18n="auditReportsStatusCol">Durum</th>
                                <th data-i18n="auditReportsDateCol">Tarih</th>
                                <th data-i18n="auditReportsActionsCol">İşlem</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reports as $rp): ?>
                                <tr>
                                    <td><?= htmlspecialchars($rp["company_name"], ENT_QUOTES, "UTF-8") ?></td>
                                    <td><a href="audit-report.php?id=<?= (int) $rp["audit_id"] ?>"><?= htmlspecialchars($rp["audit_title"], ENT_QUOTES, "UTF-8") ?></a></td>
                                    <td><?= htmlspecialchars($rp["report_title"], ENT_QUOTES, "UTF-8") ?></td>
                                    <td><span class="status-pill <?= $rp["status"] === "final" ? "" : "status-open" ?>"><?= $rp["status"] === "final" ? "Kesinleşmiş" : "Taslak" ?></span></td>
                                    <td><?= htmlspecialchars((string) ($rp["report_date"] ?? "-"), ENT_QUOTES, "UTF-8") ?></td>
                                    <td>
                                        <a class="secondary-button secondary-button-sm" href="audit-report.php?id=<?= (int) $rp["audit_id"] ?>" data-i18n="auditReportsEditButton">Düzenle</a>
                                        <a class="secondary-button secondary-button-sm" href="audit-report-export-pdf.php?id=<?= (int) $rp["audit_id"] ?>" data-i18n="auditReportsPdfButton">PDF</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
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
