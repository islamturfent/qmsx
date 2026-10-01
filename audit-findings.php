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

// Sirket kapsami: yalnizca gorebildigi sirketlerin denetim bulgulari.
$companyScope = qmsCompanyScope('co.id', qmsVisibleCompanyIds($pdo, $userId, $role));

$companyStmt = $pdo->prepare('SELECT co.id, co.company_name FROM companies co WHERE co.active = 1' . $companyScope['sql'] . ' ORDER BY co.company_name');
$companyStmt->execute($companyScope['params']);
$companies = $companyStmt->fetchAll(PDO::FETCH_ASSOC);

$selectedCompanyId = (int) ($_GET["company_id"] ?? 0);
if (!in_array($selectedCompanyId, array_map('intval', array_column($companies, 'id')), true)) {
    $selectedCompanyId = 0;
}

$statusFilter = (string) ($_GET["status"] ?? "open");
if (!in_array($statusFilter, ["all", "open", "closed"], true)) {
    $statusFilter = "open";
}

$search = trim((string) ($_GET["q"] ?? ""));

// Bulgular = uygun bulunmayan kontrol maddeleri (bagli NC / CAPA durumu ile).
$params = $companyScope['params'];
$where = "cit.active = 1 AND cit.result_status = 'noncompliant'" . $companyScope['sql'];

if ($selectedCompanyId > 0) {
    $where .= " AND a.company_id = ?";
    $params[] = $selectedCompanyId;
}
if ($statusFilter === "open") {
    $where .= " AND (nc.id IS NULL OR nc.status <> 'closed')";
} elseif ($statusFilter === "closed") {
    $where .= " AND nc.status = 'closed'";
}
if ($search !== "") {
    $where .= " AND (cit.item_text LIKE ? OR co.company_name LIKE ? OR a.title LIKE ?)";
    $like = "%" . $search . "%";
    array_push($params, $like, $like, $like);
}

$sql = "SELECT cit.id AS finding_id, cit.item_text, cit.requirement_ref, cit.notes,
            a.id AS audit_id, a.title AS audit_title, a.planned_date,
            co.company_name,
            nc.id AS nc_id, nc.title AS nc_title, nc.status AS nc_status,
            (SELECT COUNT(*) FROM corrective_actions ca WHERE ca.nonconformity_id = nc.id AND ca.active = 1) AS capa_count
     FROM audit_checklist_items cit
     INNER JOIN audits a ON a.id = cit.audit_id
     INNER JOIN companies co ON co.id = a.company_id
     LEFT JOIN nonconformities nc ON nc.checklist_item_id = cit.id AND nc.active = 1
     WHERE " . $where . "
     ORDER BY a.planned_date IS NULL, a.planned_date DESC, cit.id DESC";

$findingsStmt = $pdo->prepare($sql);
$findingsStmt->execute($params);
$findings = $findingsStmt->fetchAll(PDO::FETCH_ASSOC);

$totalOpen = 0;
$totalClosed = 0;
foreach ($findings as $f) {
    if (!empty($f['nc_status']) && $f['nc_status'] === 'closed') {
        $totalClosed++;
    } else {
        $totalOpen++;
    }
}

$activeNav = "audit_findings";

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f9fafb">
    <title>QuAmi Denetim Bulguları</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar">
        <div class="topbar-inner">
            <div class="page-title-block">
                <strong data-i18n="auditFindingsTitle">Denetim Bulguları</strong>
                <span data-i18n="auditFindingsText">Uygun bulunmayan denetim maddelerini ve kapanış durumlarını izleyin.</span>
            </div>
            <div class="topbar-actions">
                <button class="topbar-button" id="languageToggle" type="button">EN</button>
                <button class="topbar-button" id="themeToggle" type="button" aria-label="Tema değiştir">🌙</button>
            </div>
        </div>
    </header>
    <main class="page-container">
        <section class="page-heading">
            <span class="section-kicker" data-i18n="auditFindingsKicker">Denetim Takibi</span>
            <h1 data-i18n="auditFindingsTitle">Denetim Bulguları</h1>
            <p data-i18n="auditFindingsText">Uygun bulunmayan denetim maddelerini ve kapanış durumlarını izleyin.</p>
        </section>

        <section class="dashboard-grid compact-dashboard-grid">
            <div class="dashboard-card metric-orange">
                <?= appIcon("warning", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="auditFindingsOpenLabel">Açık Bulgu</span>
                    <strong class="dashboard-card-number"><?= $totalOpen ?></strong>
                </div>
            </div>
            <div class="dashboard-card metric-green">
                <?= appIcon("check", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="auditFindingsClosedLabel">Kapalı Bulgu</span>
                    <strong class="dashboard-card-number"><?= $totalClosed ?></strong>
                </div>
            </div>
            <div class="dashboard-card metric-blue">
                <?= appIcon("reports", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="auditFindingsTotalLabel">Toplam Bulgu</span>
                    <strong class="dashboard-card-number"><?= count($findings) ?></strong>
                </div>
            </div>
        </section>

        <section class="page-section console-card">
            <div class="export-bar">
                <a class="secondary-button" href="audit-findings-export.php?company_id=<?= $selectedCompanyId ?>&status=<?= htmlspecialchars($statusFilter, ENT_QUOTES, "UTF-8") ?>&q=<?= urlencode($search) ?>&format=xlsx" data-i18n="excelDownloadLabel">Excel İndir</a>
                <a class="secondary-button" href="audit-findings-export.php?company_id=<?= $selectedCompanyId ?>&status=<?= htmlspecialchars($statusFilter, ENT_QUOTES, "UTF-8") ?>&q=<?= urlencode($search) ?>&format=pdf" data-i18n="pdfDownloadLabel">PDF İndir</a>
            </div>
            <div class="filter-tabs">
                <form class="auditor-form finding-filter-form" method="get" action="audit-findings.php">
                    <select name="company_id">
                        <option value="0" data-i18n="allCompaniesOption">Tüm Şirketler</option>
                        <?php foreach ($companies as $c): ?>
                            <option value="<?= (int) $c["id"] ?>" <?= $selectedCompanyId === (int) $c["id"] ? "selected" : "" ?>><?= htmlspecialchars($c["company_name"], ENT_QUOTES, "UTF-8") ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="status">
                        <option value="open" <?= $statusFilter === "open" ? "selected" : "" ?> data-i18n="auditFindingsOpenFilter">Açık</option>
                        <option value="all" <?= $statusFilter === "all" ? "selected" : "" ?> data-i18n="auditFindingsAllFilter">Tümü</option>
                        <option value="closed" <?= $statusFilter === "closed" ? "selected" : "" ?> data-i18n="auditFindingsClosedFilter">Kapalı</option>
                    </select>
                    <input type="text" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES, "UTF-8") ?>" placeholder="<?= htmlspecialchars($searchLabel ?? 'Ara...', ENT_QUOTES, "UTF-8") ?>">
                    <button class="primary-button primary-button-sm" type="submit" data-i18n="applyFiltersButton">Filtrele</button>
                </form>
            </div>

            <?php if (!$findings): ?>
                <div class="empty-state" data-i18n="auditFindingsEmpty">Eşleşen denetim bulgusu yok.</div>
            <?php else: ?>
                <div class="table-wrapper">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th data-i18n="auditFindingsCompanyCol">Şirket</th>
                                <th data-i18n="auditFindingsAuditCol">Denetim</th>
                                <th data-i18n="auditFindingsBulguCol">Bulgu</th>
                                <th data-i18n="auditFindingsNcStatusCol">Uygunsuzluk</th>
                                <th data-i18n="auditFindingsCapaCol">CAPA</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($findings as $f): ?>
                                <?php $isClosed = !empty($f["nc_status"]) && $f["nc_status"] === "closed"; ?>
                                <tr>
                                    <td><?= htmlspecialchars($f["company_name"], ENT_QUOTES, "UTF-8") ?></td>
                                    <td><a href="audit-detail.php?id=<?= (int) $f["audit_id"] ?>"><?= htmlspecialchars($f["audit_title"], ENT_QUOTES, "UTF-8") ?></a><br><small><?= htmlspecialchars((string) ($f["planned_date"] ?? "-"), ENT_QUOTES, "UTF-8") ?></small></td>
                                    <td>
                                        <strong><?= htmlspecialchars($f["item_text"], ENT_QUOTES, "UTF-8") ?></strong>
                                        <?php if (!empty($f["requirement_ref"])): ?><br><small>Ref: <?= htmlspecialchars($f["requirement_ref"], ENT_QUOTES, "UTF-8") ?></small><?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($f["nc_id"])): ?>
                                            <a href="nonconformity-detail.php?id=<?= (int) $f["nc_id"] ?>"><?= htmlspecialchars($f["nc_title"], ENT_QUOTES, "UTF-8") ?></a>
                                            <br><span class="status-pill status-<?= htmlspecialchars($isClosed ? "closed" : "open", ENT_QUOTES, "UTF-8") ?>"><?= htmlspecialchars($f["nc_status"], ENT_QUOTES, "UTF-8") ?></span>
                                        <?php else: ?>
                                            <span class="record-card-label" data-i18n="auditFindingsNoNc">Bağlı NC yok</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($f["nc_id"])): ?>
                                            <?php if ((int) $f["capa_count"] > 0): ?>
                                                <a href="nonconformity-detail.php?id=<?= (int) $f["nc_id"] ?>"><span class="status-pill"><?= (int) $f["capa_count"] ?> faaliyet</span></a>
                                            <?php else: ?>
                                                <a class="secondary-button secondary-button-sm" href="nonconformity-detail.php?id=<?= (int) $f["nc_id"] ?>">CAPA ekle</a>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="record-card-label" data-i18n="auditFindingsNoCapa">Bağlı NC yok</span>
                                        <?php endif; ?>
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
