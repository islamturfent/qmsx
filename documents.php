<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';

$isSuperAdmin = ($_SESSION["qms_role"] ?? "") === "super_admin";
$userId = (int) ($_SESSION["qms_user_id"] ?? 0);
$scopeClause = "";
$scopeParams = [];

if (!$isSuperAdmin) {
    $assignmentStmt = $pdo->prepare(
        "SELECT company_id FROM company_admin_assignments WHERE admin_user_id = :user_id AND active = 1"
    );
    $assignmentStmt->execute(["user_id" => $userId]);
    $companyIds = array_map('intval', $assignmentStmt->fetchAll(PDO::FETCH_COLUMN));
    if ($companyIds) {
        $scopeClause = " AND companies.id IN (" . implode(',', array_fill(0, count($companyIds), '?')) . ")";
        $scopeParams = $companyIds;
    } else {
        $scopeClause = " AND 1 = 0";
    }
}

$documentsStmt = $pdo->prepare(
    "SELECT documents.*, companies.company_name,
            (SELECT COUNT(*) FROM document_versions WHERE document_versions.document_id = documents.id) AS version_count,
            (SELECT id FROM document_versions WHERE document_versions.document_id = documents.id ORDER BY id DESC LIMIT 1) AS latest_version_id
     FROM documents
     INNER JOIN companies ON companies.id = documents.company_id
     WHERE documents.active = 1" . $scopeClause . "
     ORDER BY documents.updated_at DESC, documents.created_at DESC"
);
$documentsStmt->execute($scopeParams);
$documents = $documentsStmt->fetchAll(PDO::FETCH_ASSOC);

$filters = [
    "company_id" => (int) ($_GET["company_id"] ?? 0),
    "status" => $_GET["status"] ?? "",
    "category" => trim($_GET["category"] ?? ""),
    "search" => trim($_GET["search"] ?? ""),
    "review" => $_GET["review"] ?? ""
];

$filteredDocuments = array_values(array_filter($documents, static function ($document) use ($filters) {
    if ($filters["company_id"] > 0 && (int) $document["company_id"] !== $filters["company_id"]) return false;
    if ($filters["status"] !== "" && $document["status"] !== $filters["status"]) return false;
    if ($filters["category"] !== "" && ($document["category"] ?? "") !== $filters["category"]) return false;
    if ($filters["search"] !== "") {
        $haystack = $document["document_code"] . " " . $document["title"] . " " . ($document["owner_name"] ?? "");
        if (stripos($haystack, $filters["search"]) === false) return false;
    }
    $today = date("Y-m-d");
    $threshold = date("Y-m-d", strtotime("+30 days"));
    if ($filters["review"] === "overdue" && (!$document["review_date"] || $document["review_date"] >= $today || $document["status"] === "archived")) return false;
    if ($filters["review"] === "upcoming" && (!$document["review_date"] || $document["review_date"] < $today || $document["review_date"] > $threshold || $document["status"] === "archived")) return false;
    return true;
}));

$summary = ["total" => count($documents), "published" => 0, "draft" => 0, "review_due" => 0];
$reviewThreshold = date("Y-m-d", strtotime("+30 days"));
foreach ($documents as $document) {
    if ($document["status"] === "published") $summary["published"]++;
    if ($document["status"] === "draft") $summary["draft"]++;
    if ($document["review_date"] && $document["review_date"] <= $reviewThreshold && $document["status"] !== "archived") $summary["review_due"]++;
}

$companyStmt = $pdo->prepare(
    "SELECT companies.id, companies.company_name FROM companies
     WHERE companies.active = 1" . $scopeClause . " ORDER BY companies.company_name"
);
$companyStmt->execute($scopeParams);
$companies = $companyStmt->fetchAll(PDO::FETCH_ASSOC);

$categories = array_values(array_unique(array_filter(array_column($documents, "category"))));
sort($categories, SORT_NATURAL | SORT_FLAG_CASE);

$statusLabels = [
    "draft" => "Taslak",
    "review" => "İncelemede",
    "approved" => "Onaylandı",
    "published" => "Yayında",
    "archived" => "Arşivlendi"
];
$activeNav = "documents";

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f9fafb">
    <title>QMS Doküman Yönetimi</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar">
        <div class="topbar-inner">
            <div class="page-title-block">
                <strong data-i18n="documentManagementTitle">Doküman Yönetimi</strong>
                <span data-i18n="documentManagementText">Kontrollü dokümanları, yayın durumlarını ve revizyonları yönetin.</span>
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
                <span class="section-kicker" data-i18n="sidebarOperationsLabel">Operasyonlar</span>
                <h1 data-i18n="documentManagementTitle">Doküman Yönetimi</h1>
                <p data-i18n="documentManagementText">Kontrollü dokümanları, yayın durumlarını ve revizyonları yönetin.</p>
            </div>
            <a class="primary-button" href="document-create.php" data-i18n="createDocumentButton">Yeni Doküman</a>
        </section>

        <?php if (isset($_GET["document"]) && $_GET["document"] === "created"): ?>
            <div class="form-message success" data-i18n="documentCreatedMessage">Doküman oluşturuldu.</div>
        <?php endif; ?>

        <section class="dashboard-grid">
            <div class="dashboard-card metric-blue"><?= appIcon("documents", "dashboard-card-icon") ?><div class="dashboard-card-content"><span class="dashboard-card-label" data-i18n="totalDocumentsLabel">Toplam Doküman</span><strong class="dashboard-card-number"><?= $summary["total"] ?></strong></div></div>
            <div class="dashboard-card metric-teal"><?= appIcon("check", "dashboard-card-icon") ?><div class="dashboard-card-content"><span class="dashboard-card-label" data-i18n="publishedDocumentsLabel">Yayındaki Dokümanlar</span><strong class="dashboard-card-number"><?= $summary["published"] ?></strong></div></div>
            <div class="dashboard-card metric-violet"><?= appIcon("pencil", "dashboard-card-icon") ?><div class="dashboard-card-content"><span class="dashboard-card-label" data-i18n="draftDocumentsLabel">Taslaklar</span><strong class="dashboard-card-number"><?= $summary["draft"] ?></strong></div></div>
            <div class="dashboard-card metric-orange"><?= appIcon("alert", "dashboard-card-icon") ?><div class="dashboard-card-content"><span class="dashboard-card-label" data-i18n="reviewDueDocumentsLabel">Gözden Geçirilecek</span><strong class="dashboard-card-number"><?= $summary["review_due"] ?></strong></div></div>
        </section>

        <section class="filter-panel">
            <form class="filter-form document-filter-form" method="get" action="documents.php">
                <label><span data-i18n="companySelectLabel">Şirket</span><select name="company_id"><option value="0" data-i18n="allCompaniesOption">Tüm şirketler</option><?php foreach ($companies as $company): ?><option value="<?= (int) $company["id"] ?>" <?= $filters["company_id"] === (int) $company["id"] ? "selected" : "" ?>><?= htmlspecialchars($company["company_name"], ENT_QUOTES, "UTF-8") ?></option><?php endforeach; ?></select></label>
                <label><span data-i18n="documentStatusLabel">Durum</span><select name="status"><option value="" data-i18n="allStatusesOption">Tüm durumlar</option><?php foreach ($statusLabels as $value => $label): ?><option value="<?= $value ?>" <?= $filters["status"] === $value ? "selected" : "" ?>><?= $label ?></option><?php endforeach; ?></select></label>
                <label><span data-i18n="documentCategoryLabel">Kategori</span><select name="category"><option value="" data-i18n="allCategoriesOption">Tüm kategoriler</option><?php foreach ($categories as $category): ?><option value="<?= htmlspecialchars($category, ENT_QUOTES, "UTF-8") ?>" <?= $filters["category"] === $category ? "selected" : "" ?>><?= htmlspecialchars($category, ENT_QUOTES, "UTF-8") ?></option><?php endforeach; ?></select></label>
                <label><span data-i18n="documentSearchLabel">Ara</span><input type="search" name="search" value="<?= htmlspecialchars($filters["search"], ENT_QUOTES, "UTF-8") ?>" placeholder="Kod, başlık veya sorumlu"></label>
                <label><span data-i18n="reviewStatusLabel">Gözden Geçirme</span><select name="review"><option value="" data-i18n="allReviewDatesOption">Tüm tarihler</option><option value="upcoming" <?= $filters["review"] === "upcoming" ? "selected" : "" ?> data-i18n="upcomingReviewOption">30 gün içinde</option><option value="overdue" <?= $filters["review"] === "overdue" ? "selected" : "" ?> data-i18n="overdueReviewOption">Tarihi geçenler</option></select></label>
                <div class="filter-actions"><button class="primary-button" type="submit" data-i18n="applyFiltersButton">Filtrele</button><a class="secondary-button" href="documents.php" data-i18n="clearFiltersButton">Temizle</a></div>
            </form>
        </section>

        <section class="page-section">
            <div class="section-heading"><div><h2 data-i18n="documentRecordsTitle">Kontrollü Dokümanlar</h2><p><span data-i18n="filteredRecordsLabel">Gösterilen kayıt</span>: <strong><?= count($filteredDocuments) ?></strong></p></div></div>
            <?php if (!$filteredDocuments): ?>
                <div class="empty-state" data-i18n="noDocumentsText">Henüz doküman bulunmuyor.</div>
            <?php else: ?>
                <div class="record-grid document-grid">
                    <?php foreach ($filteredDocuments as $document):
                        $reviewOverdue = $document["review_date"] && $document["review_date"] < date("Y-m-d") && $document["status"] !== "archived";
                        $reviewUpcoming = $document["review_date"] && !$reviewOverdue && $document["review_date"] <= $reviewThreshold && $document["status"] !== "archived";
                    ?>
                        <article class="record-card document-card <?= $reviewOverdue ? "review-overdue" : "" ?>">
                            <div class="record-card-icon metric-blue">▤</div>
                            <span class="status-pill document-status status-<?= htmlspecialchars($document["status"], ENT_QUOTES, "UTF-8") ?>"><?= htmlspecialchars($statusLabels[$document["status"]] ?? $document["status"], ENT_QUOTES, "UTF-8") ?></span>
                            <a class="record-card-body document-card-main" href="document-detail.php?id=<?= (int) $document["id"] ?>">
                                <span class="record-card-eyebrow"><?= htmlspecialchars($document["company_name"], ENT_QUOTES, "UTF-8") ?></span>
                                <h2><?= htmlspecialchars($document["document_code"], ENT_QUOTES, "UTF-8") ?> · <?= htmlspecialchars($document["title"], ENT_QUOTES, "UTF-8") ?></h2>
                                <p><?= htmlspecialchars($document["category"] ?: "-", ENT_QUOTES, "UTF-8") ?></p>
                                <span class="record-card-meta"><span data-i18n="revisionLabel">Revizyon</span>: <?= htmlspecialchars($document["current_revision"], ENT_QUOTES, "UTF-8") ?> · <?= (int) $document["version_count"] ?> <span data-i18n="fileLabel">dosya</span></span>
                            </a>
                            <?php if ($reviewOverdue || $reviewUpcoming): ?><span class="review-alert <?= $reviewOverdue ? "overdue" : "upcoming" ?>" data-i18n="<?= $reviewOverdue ? "reviewOverdueLabel" : "reviewUpcomingLabel" ?>"><?= $reviewOverdue ? "Gözden geçirme tarihi geçti" : "Gözden geçirme yaklaşıyor" ?></span><?php endif; ?>
                            <?php if ($document["latest_version_id"]): ?><a class="secondary-button document-download-button" href="document-download.php?id=<?= (int) $document["latest_version_id"] ?>" data-i18n="downloadCurrentFileButton">Güncel Dosyayı İndir</a><?php endif; ?>
                        </article>
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
