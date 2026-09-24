<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/review-functions.php';

$userId = (int) ($_SESSION["qms_user_id"] ?? 0);
$role = qmsCurrentRole();

$statusLabels = qmsReviewStatusLabels();
$statusI18n = qmsReviewStatusI18nKeys();

// Kapsamli liste: role gore gorunur sirketlerin gozden gecirmeleri.
$reviews = qmsReviewList($pdo, $userId, $role);

// Ozet kartlari filtre uygulanmadan, kapsamin tamami uzerinden hesaplanir.
$summary = ["total" => count($reviews), "planned" => 0, "completed" => 0, "actions" => 0];
foreach ($reviews as $review) {
    if ($review["status"] === "completed") {
        $summary["completed"]++;
    } else {
        $summary["planned"]++;
    }
    $summary["actions"] += (int) $review["action_count"];
}

$filters = [
    "company_id" => (int) ($_GET["company_id"] ?? 0),
    "status" => (string) ($_GET["status"] ?? "")
];

$visibleReviews = array_values(array_filter($reviews, static function (array $review) use ($filters): bool {
    if ($filters["company_id"] > 0 && (int) $review["company_id"] !== $filters["company_id"]) {
        return false;
    }
    if ($filters["status"] !== "" && $review["status"] !== $filters["status"]) {
        return false;
    }

    return true;
}));

$companyScope = qmsCompanyScope("companies.id", qmsVisibleCompanyIds($pdo, $userId, $role));
$companyStmt = $pdo->prepare(
    "SELECT companies.id, companies.company_name
     FROM companies
     WHERE companies.active = 1" . $companyScope["sql"] . "
     ORDER BY companies.company_name"
);
$companyStmt->execute($companyScope["params"]);
$companies = $companyStmt->fetchAll(PDO::FETCH_ASSOC);

$activeNav = "reviews";

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f9fafb">
    <title>QMS Yönetimin Gözden Geçirmesi</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar">
        <div class="topbar-inner">
            <div class="page-title-block">
                <strong data-i18n="reviewsTitle">Yönetimin Gözden Geçirmesi</strong>
                <span data-i18n="reviewsText">Gözden geçirme toplantılarını, girdileri ve çıkan aksiyonları izleyin.</span>
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
                <span class="section-kicker" data-i18n="reviewRegisterKicker">Gözden Geçirme Kayıtları</span>
                <h1 data-i18n="reviewsTitle">Yönetimin Gözden Geçirmesi</h1>
                <p data-i18n="reviewsText">Gözden geçirme toplantılarını, girdileri ve çıkan aksiyonları izleyin.</p>
            </div>
            <a class="primary-button" href="review-create.php" data-i18n="newReviewButton">Yeni Gözden Geçirme</a>
        </section>

        <?php if (($_GET["review"] ?? "") === "created"): ?>
            <div class="form-message success" data-i18n="reviewCreatedMessage">Gözden geçirme kaydı oluşturuldu.</div>
        <?php endif; ?>

        <section class="dashboard-grid compact-dashboard-grid">
            <div class="dashboard-card metric-blue">
                <?= appIcon("reviews", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="reviewTotalLabel">Toplam Gözden Geçirme</span>
                    <strong class="dashboard-card-number"><?= $summary["total"] ?></strong>
                </div>
            </div>
            <div class="dashboard-card metric-orange">
                <?= appIcon("clock", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="reviewPlannedLabel">Planlanan</span>
                    <strong class="dashboard-card-number"><?= $summary["planned"] ?></strong>
                </div>
            </div>
            <div class="dashboard-card metric-teal">
                <?= appIcon("check", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="reviewCompletedLabel">Tamamlanan</span>
                    <strong class="dashboard-card-number"><?= $summary["completed"] ?></strong>
                </div>
            </div>
            <div class="dashboard-card metric-violet">
                <?= appIcon("checkBadge", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="reviewActionCountLabel">Aksiyon Kalemi</span>
                    <strong class="dashboard-card-number"><?= $summary["actions"] ?></strong>
                </div>
            </div>
        </section>

        <section class="form-panel">
            <form method="get" class="filter-grid">
                <label class="form-field">
                    <span data-i18n="companySelectLabel">Şirket</span>
                    <select name="company_id">
                        <option value="0" data-i18n="allCompaniesOption">Tüm şirketler</option>
                        <?php foreach ($companies as $company): ?>
                            <option value="<?= (int) $company["id"] ?>" <?= $filters["company_id"] === (int) $company["id"] ? "selected" : "" ?>><?= htmlspecialchars($company["company_name"], ENT_QUOTES, "UTF-8") ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="form-field">
                    <span data-i18n="reviewStatusLabel">Durum</span>
                    <select name="status">
                        <option value="" data-i18n="allOption">Tümü</option>
                        <?php foreach ($statusLabels as $value => $label): ?>
                            <option value="<?= $value ?>" <?= $filters["status"] === $value ? "selected" : "" ?> data-i18n="<?= $statusI18n[$value] ?>"><?= htmlspecialchars($label, ENT_QUOTES, "UTF-8") ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <div class="form-actions">
                    <button class="primary-button" type="submit" data-i18n="applyFiltersButton">Filtrele</button>
                    <a class="secondary-button" href="reviews.php" data-i18n="clearFiltersButton">Temizle</a>
                </div>
            </form>
        </section>

        <section class="page-section">
            <div class="record-card-grid">
                <?php if (!$visibleReviews): ?>
                    <div class="empty-state" data-i18n="noReviewsText">Filtrelere uygun gözden geçirme bulunamadı.</div>
                <?php endif; ?>
                <?php foreach ($visibleReviews as $review): ?>
                    <a class="record-card" href="review-detail.php?id=<?= (int) $review["id"] ?>">
                        <div class="record-card-topline">
                            <span><?= htmlspecialchars($review["company_name"], ENT_QUOTES, "UTF-8") ?></span>
                            <span class="status-badge status-<?= htmlspecialchars($review["status"], ENT_QUOTES, "UTF-8") ?>" data-i18n="<?= $statusI18n[$review["status"]] ?? "" ?>"><?= htmlspecialchars($statusLabels[$review["status"]] ?? $review["status"], ENT_QUOTES, "UTF-8") ?></span>
                        </div>
                        <div class="record-card-body">
                            <h3><?= htmlspecialchars($review["title"], ENT_QUOTES, "UTF-8") ?></h3>
                            <p><?= htmlspecialchars($review["review_date"], ENT_QUOTES, "UTF-8") ?> · <?= htmlspecialchars($review["period_start"], ENT_QUOTES, "UTF-8") ?> – <?= htmlspecialchars($review["period_end"], ENT_QUOTES, "UTF-8") ?></p>
                            <span class="record-card-meta">
                                <?= (int) $review["item_count"] ?> <span data-i18n="reviewItemCountLabel">kalem</span>
                                <?php if ((int) $review["action_count"] > 0): ?> · <?= (int) $review["action_count"] ?> <span data-i18n="reviewActionCountLabel">aksiyon</span><?php endif; ?>
                                <?php if ($review["next_review_date"]): ?> · <span data-i18n="nextReviewDateLabel">Sonraki</span>: <?= htmlspecialchars($review["next_review_date"], ENT_QUOTES, "UTF-8") ?><?php endif; ?>
                            </span>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    </main>
    <script src="assets/js/theme.js"></script>
    <script src="assets/js/language.js"></script>
    <script src="assets/js/sidebar.js"></script>
    <script src="assets/js/pwa.js"></script>
</body>
</html>
