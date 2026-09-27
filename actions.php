<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/access.php';
require_once __DIR__ . '/includes/vocabulary.php';
require_once __DIR__ . '/includes/capa-functions.php';

$isSuperAdmin = ($_SESSION["qms_role"] ?? "") === "super_admin";
$userId = (int) ($_SESSION["qms_user_id"] ?? 0);

// Kapsam tek kaynaktan: role gore gorunur sirketler (null = kisitlama yok).
$scope = qmsCompanyScope('companies.id', qmsVisibleCompanyIds($pdo, $userId, qmsCurrentRole()));
$scopeClause = $scope['sql'];
$scopeParams = $scope['params'];

$nonconformityStmt = $pdo->prepare(
    "SELECT 'nonconformity' AS record_type, nonconformities.id, nonconformities.title,
            nonconformities.responsible_person, nonconformities.status, nonconformities.severity,
            nonconformities.due_date, nonconformities.created_at, companies.id AS company_id,
            companies.company_name, NULL AS parent_title
     FROM nonconformities
     INNER JOIN companies ON companies.id = nonconformities.company_id
     WHERE nonconformities.active = 1" . $scopeClause
);
$nonconformityStmt->execute($scopeParams);
$records = $nonconformityStmt->fetchAll(PDO::FETCH_ASSOC);

$actionStmt = $pdo->prepare(
    "SELECT 'corrective_action' AS record_type, corrective_actions.id,
            corrective_actions.action_text AS title, corrective_actions.responsible_person,
            corrective_actions.status, nonconformities.severity, corrective_actions.due_date,
            corrective_actions.created_at, companies.id AS company_id, companies.company_name,
            nonconformities.title AS parent_title
     FROM corrective_actions
     INNER JOIN nonconformities ON nonconformities.id = corrective_actions.nonconformity_id
     INNER JOIN companies ON companies.id = nonconformities.company_id
     WHERE corrective_actions.active = 1" . $scopeClause
);
$actionStmt->execute($scopeParams);
$records = array_merge($records, $actionStmt->fetchAll(PDO::FETCH_ASSOC));

usort($records, static function ($left, $right) {
    $leftOverdue = $left["due_date"] && $left["due_date"] < date("Y-m-d") && !in_array($left["status"], ["closed", "completed"], true);
    $rightOverdue = $right["due_date"] && $right["due_date"] < date("Y-m-d") && !in_array($right["status"], ["closed", "completed"], true);
    if ($leftOverdue !== $rightOverdue) {
        return $leftOverdue ? -1 : 1;
    }
    return strcmp($right["created_at"], $left["created_at"]);
});

$summary = [
    "open_nonconformities" => 0,
    "active_actions" => 0,
    "overdue" => 0,
    "closed" => 0
];

foreach ($records as $record) {
    $isClosed = in_array($record["status"], ["closed", "completed"], true);
    $isOverdue = $record["due_date"] && $record["due_date"] < date("Y-m-d") && !$isClosed;

    if ($record["record_type"] === "nonconformity" && !$isClosed) {
        $summary["open_nonconformities"]++;
    }
    if ($record["record_type"] === "corrective_action" && !$isClosed) {
        $summary["active_actions"]++;
    }
    if ($isOverdue) {
        $summary["overdue"]++;
    }
    if ($isClosed) {
        $summary["closed"]++;
    }
}

$filters = [
    "company_id" => (int) ($_GET["company_id"] ?? 0),
    "record_type" => $_GET["record_type"] ?? "",
    "status" => $_GET["status"] ?? "",
    "severity" => $_GET["severity"] ?? "",
    "responsible" => trim($_GET["responsible"] ?? ""),
    "due" => $_GET["due"] ?? ""
];

$filteredRecords = array_values(array_filter($records, static function ($record) use ($filters) {
    $isClosed = in_array($record["status"], ["closed", "completed"], true);
    $isOverdue = $record["due_date"] && $record["due_date"] < date("Y-m-d") && !$isClosed;

    if ($filters["company_id"] > 0 && (int) $record["company_id"] !== $filters["company_id"]) return false;
    if ($filters["record_type"] !== "" && $record["record_type"] !== $filters["record_type"]) return false;
    if ($filters["status"] !== "" && $record["status"] !== $filters["status"]) return false;
    if ($filters["severity"] !== "" && $record["severity"] !== $filters["severity"]) return false;
    if ($filters["responsible"] !== "" && stripos($record["responsible_person"] ?? "", $filters["responsible"]) === false) return false;
    if ($filters["due"] === "overdue" && !$isOverdue) return false;
    if ($filters["due"] === "open" && $isClosed) return false;
    if ($filters["due"] === "closed" && !$isClosed) return false;
    return true;
}));

$companyStmt = $pdo->prepare(
    "SELECT companies.id, companies.company_name FROM companies
     WHERE companies.active = 1" . $scopeClause . " ORDER BY companies.company_name"
);
$companyStmt->execute($scopeParams);
$companies = $companyStmt->fetchAll(PDO::FETCH_ASSOC);

$statusLabels = [
    "open" => "Açık",
    "planned" => "Planlandı",
    "in_progress" => "Çalışılıyor",
    "verification" => "Doğrulama",
    "completed" => "Tamamlandı",
    "closed" => "Kapalı"
];
// Onem sozlugu tek kaynaktan gelir (includes/vocabulary.php).
$severityLabels = qmsSeverityLabels();
$activeNav = "actions";

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f9fafb">
    <title>QuAmi Aksiyon Yönetimi</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar">
        <div class="topbar-inner">
            <div class="page-title-block">
                <strong data-i18n="actionManagementTitle">Aksiyon Yönetimi</strong>
                <span data-i18n="actionManagementText">Uygunsuzlukları ve düzeltici faaliyetleri tek merkezden izleyin.</span>
            </div>
            <div class="topbar-actions">
                <button class="topbar-button" id="languageToggle" type="button">EN</button>
                <button class="topbar-button" id="themeToggle" type="button" aria-label="Tema değiştir">🌙</button>
            </div>
        </div>
    </header>
    <main class="page-container">
        <section class="page-heading">
            <span class="section-kicker" data-i18n="sidebarOperationsLabel">Operasyonlar</span>
            <h1 data-i18n="actionManagementTitle">Aksiyon Yönetimi</h1>
            <p data-i18n="actionManagementText">Uygunsuzlukları ve düzeltici faaliyetleri tek merkezden izleyin.</p>
        </section>

        <section class="dashboard-grid">
            <div class="dashboard-card metric-orange"><?= appIcon("alert", "dashboard-card-icon") ?><div class="dashboard-card-content"><span class="dashboard-card-label" data-i18n="openNonconformitiesCardLabel">Açık Uygunsuzluklar</span><strong class="dashboard-card-number"><?= $summary["open_nonconformities"] ?></strong></div></div>
            <div class="dashboard-card metric-blue"><?= appIcon("trend", "dashboard-card-icon") ?><div class="dashboard-card-content"><span class="dashboard-card-label" data-i18n="activeCorrectiveActionsLabel">Devam Eden Faaliyetler</span><strong class="dashboard-card-number"><?= $summary["active_actions"] ?></strong></div></div>
            <div class="dashboard-card metric-red"><?= appIcon("clock", "dashboard-card-icon") ?><div class="dashboard-card-content"><span class="dashboard-card-label" data-i18n="overdueActionsLabel">Geciken Kayıtlar</span><strong class="dashboard-card-number"><?= $summary["overdue"] ?></strong></div></div>
            <div class="dashboard-card metric-teal"><?= appIcon("check", "dashboard-card-icon") ?><div class="dashboard-card-content"><span class="dashboard-card-label" data-i18n="closedActionsLabel">Kapanan Kayıtlar</span><strong class="dashboard-card-number"><?= $summary["closed"] ?></strong></div></div>
        </section>

        <section class="filter-panel">
            <form class="filter-form" method="get" action="actions.php">
                <label><span data-i18n="companySelectLabel">Şirket</span><select name="company_id"><option value="0" data-i18n="allCompaniesOption">Tüm şirketler</option><?php foreach ($companies as $company): ?><option value="<?= (int) $company["id"] ?>" <?= $filters["company_id"] === (int) $company["id"] ? "selected" : "" ?>><?= htmlspecialchars($company["company_name"], ENT_QUOTES, "UTF-8") ?></option><?php endforeach; ?></select></label>
                <label><span data-i18n="recordTypeLabel">Kayıt Türü</span><select name="record_type"><option value="" data-i18n="allRecordsOption">Tüm kayıtlar</option><option value="nonconformity" <?= $filters["record_type"] === "nonconformity" ? "selected" : "" ?> data-i18n="nonconformityTypeLabel">Uygunsuzluk</option><option value="corrective_action" <?= $filters["record_type"] === "corrective_action" ? "selected" : "" ?> data-i18n="correctiveActionTypeLabel">Düzeltici Faaliyet</option></select></label>
                <label><span data-i18n="actionStatusLabel">Durum</span><select name="status"><option value="" data-i18n="allStatusesOption">Tüm durumlar</option><?php foreach ($statusLabels as $value => $label): ?><option value="<?= $value ?>" <?= $filters["status"] === $value ? "selected" : "" ?>><?= $label ?></option><?php endforeach; ?></select></label>
                <label><span data-i18n="severityLabel">Önem Seviyesi</span><select name="severity"><option value="" data-i18n="allSeveritiesOption">Tüm seviyeler</option><?php foreach ($severityLabels as $value => $label): ?><option value="<?= $value ?>" <?= $filters["severity"] === $value ? "selected" : "" ?>><?= $label ?></option><?php endforeach; ?></select></label>
                <label><span data-i18n="dueFilterLabel">Termin</span><select name="due"><option value="" data-i18n="allDueDatesOption">Tüm kayıtlar</option><option value="overdue" <?= $filters["due"] === "overdue" ? "selected" : "" ?> data-i18n="overdueOnlyOption">Yalnız gecikenler</option><option value="open" <?= $filters["due"] === "open" ? "selected" : "" ?> data-i18n="openOnlyOption">Yalnız açıklar</option><option value="closed" <?= $filters["due"] === "closed" ? "selected" : "" ?> data-i18n="closedOnlyOption">Yalnız kapananlar</option></select></label>
                <label><span data-i18n="responsiblePersonLabel">Sorumlu Kişi</span><input type="search" name="responsible" value="<?= htmlspecialchars($filters["responsible"], ENT_QUOTES, "UTF-8") ?>"></label>
                <div class="filter-actions"><button class="primary-button" type="submit" data-i18n="applyFiltersButton">Filtrele</button><a class="secondary-button" href="actions.php" data-i18n="clearFiltersButton">Temizle</a></div>
            </form>
        </section>

        <section class="page-section">
            <div class="section-heading"><div><h2 data-i18n="actionRecordsTitle">Aksiyon Kayıtları</h2><p><span data-i18n="filteredRecordsLabel">Gösterilen kayıt</span>: <strong><?= count($filteredRecords) ?></strong></p></div></div>
            <?php if (!$filteredRecords): ?>
                <div class="empty-state" data-i18n="noActionRecordsText">Seçilen filtrelere uygun kayıt bulunamadı.</div>
            <?php else: ?>
                <div class="action-list">
                    <?php foreach ($filteredRecords as $record):
                        $isClosed = in_array($record["status"], ["closed", "completed"], true);
                        $isOverdue = $record["due_date"] && $record["due_date"] < date("Y-m-d") && !$isClosed;
                        $href = $record["record_type"] === "nonconformity" ? "nonconformity-detail.php?id=" . (int) $record["id"] : "corrective-action-detail.php?id=" . (int) $record["id"];
                    ?>
                        <a class="action-list-item <?= $isOverdue ? "overdue" : "" ?>" href="<?= $href ?>">
                            <div class="action-list-main">
                                <div class="action-list-badges">
                                    <span class="type-badge <?= $record["record_type"] === "nonconformity" ? "type-nonconformity" : "type-corrective" ?>"><?= $record["record_type"] === "nonconformity" ? "Uygunsuzluk" : "Düzeltici Faaliyet" ?></span>
                                    <?php if ($record["record_type"] === "corrective_action"): ?><span class="type-badge type-capa"><?= htmlspecialchars(qmsCapaTypeLabels()[$record["action_type"] ?? "corrective"] ?? ($record["action_type"] ?? "corrective"), ENT_QUOTES, "UTF-8") ?></span><?php endif; ?>
                                    <span class="status-badge status-<?= htmlspecialchars($record["status"], ENT_QUOTES, "UTF-8") ?>"><?= htmlspecialchars($statusLabels[$record["status"]] ?? $record["status"], ENT_QUOTES, "UTF-8") ?></span>
                                    <?php if ($isOverdue): ?><span class="overdue-badge" data-i18n="overdueLabel">Gecikmiş</span><?php endif; ?>
                                </div>
                                <h3><?= htmlspecialchars($record["title"], ENT_QUOTES, "UTF-8") ?></h3>
                                <p><?= htmlspecialchars($record["company_name"], ENT_QUOTES, "UTF-8") ?><?php if ($record["parent_title"]): ?> · <?= htmlspecialchars($record["parent_title"], ENT_QUOTES, "UTF-8") ?><?php endif; ?></p>
                            </div>
                            <div class="action-list-meta">
                                <span><strong data-i18n="responsiblePersonLabel">Sorumlu</strong><?= htmlspecialchars($record["responsible_person"] ?: "-", ENT_QUOTES, "UTF-8") ?></span>
                                <span><strong data-i18n="dueDateLabel">Termin</strong><?= htmlspecialchars($record["due_date"] ?: "-", ENT_QUOTES, "UTF-8") ?></span>
                                <span><strong data-i18n="severityLabel">Önem</strong><?= htmlspecialchars($severityLabels[$record["severity"]] ?? $record["severity"], ENT_QUOTES, "UTF-8") ?></span>
                            </div>
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
