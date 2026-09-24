<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/review-functions.php';

$userId = (int) ($_SESSION["qms_user_id"] ?? 0);
$role = qmsCurrentRole();
$csrfScope = 'review_create';

// Sirket listesi kapsamdan gelir.
$companyScope = qmsCompanyScope('companies.id', qmsVisibleCompanyIds($pdo, $userId, $role));
$companyStmt = $pdo->prepare(
    "SELECT companies.id, companies.company_name
     FROM companies
     WHERE companies.active = 1" . $companyScope['sql'] . "
     ORDER BY companies.company_name"
);
$companyStmt->execute($companyScope['params']);
$companies = $companyStmt->fetchAll(PDO::FETCH_ASSOC);
$allowedCompanyIds = array_map('intval', array_column($companies, 'id'));

$formError = "";
$formData = [
    "company_id" => count($companies) === 1 ? (int) $companies[0]["id"] : 0,
    "title" => "",
    "review_date" => date("Y-m-d"),
    "period_start" => date("Y-m-01", strtotime("-1 month")),
    "period_end" => date("Y-m-t", strtotime("-1 month")),
    "participants" => "",
    "scope_notes" => "",
    "next_review_date" => date("Y-m-d", strtotime("+6 months"))
];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    qmsCsrfVerify($csrfScope, $_POST["csrf"] ?? null);

    $formData = [
        "company_id" => (int) ($_POST["company_id"] ?? 0),
        "title" => qmsReviewText($_POST["title"] ?? "", 255),
        "review_date" => trim((string) ($_POST["review_date"] ?? "")),
        "period_start" => trim((string) ($_POST["period_start"] ?? "")),
        "period_end" => trim((string) ($_POST["period_end"] ?? "")),
        "participants" => qmsReviewText($_POST["participants"] ?? "", 4000),
        "scope_notes" => qmsReviewText($_POST["scope_notes"] ?? "", 4000),
        "next_review_date" => trim((string) ($_POST["next_review_date"] ?? ""))
    ];

    $reviewDate = qmsReviewDate($formData["review_date"]);
    $periodStart = qmsReviewDate($formData["period_start"]);
    $periodEnd = qmsReviewDate($formData["period_end"]);
    $nextReviewDate = $formData["next_review_date"] !== "" ? qmsReviewDate($formData["next_review_date"]) : null;
    $nextReviewValid = $formData["next_review_date"] === "" || $nextReviewDate !== null;

    if ($formData["title"] === "") {
        $formError = "Lütfen gözden geçirme başlığını girin.";
    } elseif (!in_array($formData["company_id"], $allowedCompanyIds, true)) {
        $formError = "Geçerli bir şirket seçin.";
    } elseif ($reviewDate === null || $periodStart === null || $periodEnd === null) {
        $formError = "Toplantı ve dönem tarihleri geçerli değil.";
    } elseif ($periodEnd < $periodStart) {
        $formError = "Dönem bitiş tarihi başlangıçtan önce olamaz.";
    } elseif (!$nextReviewValid) {
        $formError = "Sonraki gözden geçirme tarihi geçerli değil.";
    } else {
        $insertStmt = $pdo->prepare(
            "INSERT INTO management_reviews
                (company_id, title, review_date, period_start, period_end, participants,
                 scope_notes, status, next_review_date, created_by, updated_by, active)
             VALUES
                (:company_id, :title, :review_date, :period_start, :period_end, :participants,
                 :scope_notes, 'planned', :next_review_date, :created_by, :updated_by, 1)"
        );
        $insertStmt->execute([
            "company_id" => $formData["company_id"],
            "title" => $formData["title"],
            "review_date" => $reviewDate,
            "period_start" => $periodStart,
            "period_end" => $periodEnd,
            "participants" => $formData["participants"] !== "" ? $formData["participants"] : null,
            "scope_notes" => $formData["scope_notes"] !== "" ? $formData["scope_notes"] : null,
            "next_review_date" => $nextReviewDate,
            "created_by" => $userId ?: null,
            "updated_by" => $userId ?: null
        ]);

        $reviewId = (int) $pdo->lastInsertId();

        header("Location: review-detail.php?id=" . $reviewId . "&created=1");
        exit;
    }
}

$activeNav = "reviews";

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f9fafb">
    <title>QMS Yeni Gözden Geçirme</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar">
        <div class="topbar-inner">
            <div class="page-title-block">
                <strong data-i18n="newReviewTitle">Yeni Gözden Geçirme</strong>
                <span data-i18n="reviewsText">Gözden geçirme toplantılarını, girdileri ve çıkan aksiyonları izleyin.</span>
            </div>
            <div class="topbar-actions">
                <button class="topbar-button" id="languageToggle" type="button">EN</button>
                <button class="topbar-button" id="themeToggle" type="button" aria-label="Tema değiştir">🌙</button>
            </div>
        </div>
    </header>
    <main class="page-container narrow-page">
        <section class="page-heading page-heading-actions">
            <div>
                <span class="section-kicker" data-i18n="reviewRegisterKicker">Gözden Geçirme Kayıtları</span>
                <h1 data-i18n="newReviewTitle">Yeni Gözden Geçirme</h1>
                <p data-i18n="newReviewText">Toplantı kaydını oluşturun; gündem ve aksiyon kalemlerini detay sayfasından ekleyin.</p>
            </div>
            <a class="secondary-button" href="reviews.php" data-i18n="backToReviewsButton">Gözden Geçirmelere Dön</a>
        </section>
        <section class="form-panel">
            <?php if ($formError !== ""): ?>
                <div class="form-message error"><?= htmlspecialchars($formError, ENT_QUOTES, "UTF-8") ?></div>
            <?php endif; ?>
            <?php if (!$companies): ?>
                <div class="form-message error" data-i18n="reviewNoCompanyText">Gözden geçirme eklemek için önce bir şirket gerekir. Şirket kaydınız yoksa yöneticinizle görüşün.</div>
            <?php else: ?>
            <form class="auditor-form" method="post" action="review-create.php">
                <?= qmsCsrfField($csrfScope) ?>
                <div class="form-grid">
                    <label class="form-field">
                        <span data-i18n="companySelectLabel">Şirket</span>
                        <select name="company_id" required>
                            <option value="0" data-i18n="selectCompanyOption">Şirket seçin</option>
                            <?php foreach ($companies as $company): ?>
                                <option value="<?= (int) $company["id"] ?>" <?= $formData["company_id"] === (int) $company["id"] ? "selected" : "" ?>><?= htmlspecialchars($company["company_name"], ENT_QUOTES, "UTF-8") ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="form-field">
                        <span data-i18n="reviewTitleLabel">Başlık</span>
                        <input type="text" name="title" maxlength="255" value="<?= htmlspecialchars($formData["title"], ENT_QUOTES, "UTF-8") ?>" required placeholder="Örn. 2026 ilk yarı yıl gözden geçirmesi">
                    </label>
                    <label class="form-field">
                        <span data-i18n="reviewDateLabel">Toplantı Tarihi</span>
                        <input type="date" name="review_date" value="<?= htmlspecialchars($formData["review_date"], ENT_QUOTES, "UTF-8") ?>" required>
                    </label>
                    <label class="form-field">
                        <span data-i18n="reviewPeriodStartLabel">Dönem Başlangıcı</span>
                        <input type="date" name="period_start" value="<?= htmlspecialchars($formData["period_start"], ENT_QUOTES, "UTF-8") ?>" required>
                    </label>
                    <label class="form-field">
                        <span data-i18n="reviewPeriodEndLabel">Dönem Bitişi</span>
                        <input type="date" name="period_end" value="<?= htmlspecialchars($formData["period_end"], ENT_QUOTES, "UTF-8") ?>" required>
                    </label>
                    <label class="form-field">
                        <span data-i18n="nextReviewDateLabel">Sonraki Gözden Geçirme</span>
                        <input type="date" name="next_review_date" value="<?= htmlspecialchars($formData["next_review_date"], ENT_QUOTES, "UTF-8") ?>">
                    </label>
                    <label class="form-field form-field-wide">
                        <span data-i18n="reviewParticipantsLabel">Katılımcılar</span>
                        <textarea name="participants" rows="3"><?= htmlspecialchars($formData["participants"], ENT_QUOTES, "UTF-8") ?></textarea>
                    </label>
                    <label class="form-field form-field-wide">
                        <span data-i18n="reviewScopeNotesLabel">Kapsam / Not</span>
                        <textarea name="scope_notes" rows="4"><?= htmlspecialchars($formData["scope_notes"], ENT_QUOTES, "UTF-8") ?></textarea>
                    </label>
                </div>
                <div class="form-actions">
                    <button class="primary-button" type="submit" data-i18n="saveReviewButton">Gözden Geçirmeyi Kaydet</button>
                    <a class="secondary-button" href="reviews.php" data-i18n="backToReviewsButton">Gözden Geçirmelere Dön</a>
                </div>
            </form>
            <?php endif; ?>
        </section>
    </main>
    <script src="assets/js/theme.js"></script>
    <script src="assets/js/language.js"></script>
    <script src="assets/js/sidebar.js"></script>
    <script src="assets/js/pwa.js"></script>
</body>
</html>
