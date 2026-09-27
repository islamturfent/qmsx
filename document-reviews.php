<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/access.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/document-review-functions.php';

$userId = (int) ($_SESSION["qms_user_id"] ?? 0);
$role = qmsCurrentRole();
$csrfScope = 'document_reviews';

// Gözden geçirme islemi yonetim rollerine aciktir; digerleri kuyrugu salt-okunur gorur.
$canReview = in_array($role, ["super_admin", "system_admin"], true);

$filter = (string) ($_GET["filter"] ?? "");
if (!in_array($filter, ["overdue", "due_soon", "on_schedule", "not_scheduled", ""], true)) {
    $filter = "";
}

$formError = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    qmsCsrfVerify($csrfScope, $_POST["csrf"] ?? null);

    if (!$canReview) {
        $formError = "Gözden geçirme işlemi için yetkili değilsiniz.";
    } else {
        $formType = (string) ($_POST["form_type"] ?? "");
        if ($formType !== "record_review") {
            $formError = "Geçersiz istek.";
        } else {
            $documentId = (int) ($_POST["document_id"] ?? 0);
            $next = trim((string) ($_POST["next_review_date"] ?? ""));
            $reviewedAt = trim((string) ($_POST["reviewed_at"] ?? date("Y-m-d")));
            $reviewId = qmsDocumentReviewRecord($pdo, $documentId, [
                "outcome" => (string) ($_POST["outcome"] ?? ""),
                "notes" => (string) ($_POST["notes"] ?? ""),
                "reviewed_at" => $reviewedAt,
                "next_review_date" => $next,
                "document_title" => (string) ($_POST["document_title"] ?? ""),
            ], $userId, $role);

            if ($reviewId !== null) {
                header("Location: document-reviews.php?reviewed=1");
                exit;
            }
            $formError = "Gözden geçirme kaydı oluşturulamadı. Geçerli bir sonraki tarih girin.";
        }
    }
}

$queue = qmsDocumentReviewQueue($pdo, $userId, $role, $filter);
$history = qmsDocumentReviewHistory($pdo, $userId, $role, 15);
$all = qmsDocumentReviewQueue($pdo, $userId, $role);
$statusCounts = ["overdue" => 0, "due_soon" => 0, "on_schedule" => 0, "not_scheduled" => 0];
foreach ($all as $row) {
    if (isset($statusCounts[$row["review_status"]])) {
        $statusCounts[$row["review_status"]]++;
    }
}

$statusLabels = qmsDocumentReviewStatusLabels();
$statusI18n = qmsDocumentReviewStatusI18nKeys();
$outcomeLabels = qmsDocumentReviewOutcomeLabels();
$outcomeI18n = qmsDocumentReviewOutcomeI18nKeys();

$activeNav = "document_reviews";

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f9fafb">
    <title>QuAmi Doküman Gözden Geçirme Merkezi</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar">
        <div class="topbar-inner">
            <div class="page-title-block">
                <strong data-i18n="docReviewCenterTitle">Doküman Gözden Geçirme Merkezi</strong>
                <span data-i18n="docReviewCenterText">Vadesi gelen dokümanları gözden geçirin ve geçmişi izleyin.</span>
            </div>
            <div class="topbar-actions">
                <button class="topbar-button" id="languageToggle" type="button">EN</button>
                <button class="topbar-button" id="themeToggle" type="button" aria-label="Tema değiştir">🌙</button>
            </div>
        </div>
    </header>
    <main class="page-container">
        <section class="page-heading">
            <div>
                <span class="section-kicker" data-i18n="docReviewKicker">Doküman Kontrolü</span>
                <h1 data-i18n="docReviewCenterTitle">Doküman Gözden Geçirme Merkezi</h1>
                <p data-i18n="docReviewCenterText">Dokümanların periyodik gözden geçirme vadesini takip edin ve işlem kaydını tutun.</p>
            </div>
        </section>

        <section class="dashboard-grid compact-dashboard-grid">
            <div class="dashboard-card metric-red">
                <?= appIcon("warning", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="docReviewOverdueTotalLabel">Vadesi Geçen</span>
                    <strong class="dashboard-card-number detail-card-value"><?= $statusCounts["overdue"] ?></strong>
                </div>
            </div>
            <div class="dashboard-card metric-orange">
                <?= appIcon("clock", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="docReviewDueSoonTotalLabel">Yaklaşan</span>
                    <strong class="dashboard-card-number detail-card-value"><?= $statusCounts["due_soon"] ?></strong>
                </div>
            </div>
            <div class="dashboard-card metric-teal">
                <?= appIcon("check", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="docReviewOnScheduleTotalLabel">Programında</span>
                    <strong class="dashboard-card-number detail-card-value"><?= $statusCounts["on_schedule"] ?></strong>
                </div>
            </div>
            <div class="dashboard-card metric-violet">
                <?= appIcon("documents", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="docReviewNotScheduledTotalLabel">Planlanmadı</span>
                    <strong class="dashboard-card-number detail-card-value"><?= $statusCounts["not_scheduled"] ?></strong>
                </div>
            </div>
        </section>

        <?php if ($formError !== ""): ?>
            <div class="form-message error"><?= htmlspecialchars($formError, ENT_QUOTES, "UTF-8") ?></div>
        <?php endif; ?>
        <?php if (($_GET["reviewed"] ?? "") === "1"): ?>
            <div class="form-message success" data-i18n="docReviewRecordedMessage">Gözden geçirme kaydedildi.</div>
        <?php endif; ?>

        <section class="console-card checklist-section">
            <div class="section-heading compact-heading">
                <div>
                    <h3 data-i18n="docReviewQueueTitle">Gözden Geçirme Kuyruğu</h3>
                    <p><span data-i18n="filteredRecordsLabel">Gösterilen kayıt</span>: <strong><?= count($queue) ?></strong></p>
                </div>
            </div>

            <div class="filter-bar">
                <a class="<?= $filter === "" ? "filter-pill active" : "filter-pill" ?>" href="document-reviews.php" data-i18n="docReviewAllFilter">Tümü</a>
                <a class="<?= $filter === "overdue" ? "filter-pill active" : "filter-pill" ?>" href="document-reviews.php?filter=overdue" data-i18n="docReviewOverdueFilter">Vadesi Geçen</a>
                <a class="<?= $filter === "due_soon" ? "filter-pill active" : "filter-pill" ?>" href="document-reviews.php?filter=due_soon" data-i18n="docReviewDueSoonFilter">Yaklaşan</a>
                <a class="<?= $filter === "on_schedule" ? "filter-pill active" : "filter-pill" ?>" href="document-reviews.php?filter=on_schedule" data-i18n="docReviewOnScheduleFilter">Programında</a>
                <a class="<?= $filter === "not_scheduled" ? "filter-pill active" : "filter-pill" ?>" href="document-reviews.php?filter=not_scheduled" data-i18n="docReviewNotScheduledFilter">Planlanmadı</a>
            </div>

            <div class="admin-list">
                <?php if (!$queue): ?>
                    <div class="empty-state" data-i18n="docReviewNoDocumentsText">Filtrelere uygun doküman bulunamadı.</div>
                <?php endif; ?>

                <?php foreach ($queue as $doc): ?>
                    <div class="admin-list-item review-queue-item">
                        <div class="list-item-main">
                            <a href="document-detail.php?id=<?= (int) $doc["id"] ?>">
                                <strong><?= htmlspecialchars($doc["title"], ENT_QUOTES, "UTF-8") ?></strong>
                            </a>
                            <span><?= htmlspecialchars($doc["document_code"], ENT_QUOTES, "UTF-8") ?> · <?= htmlspecialchars($doc["company_name"], ENT_QUOTES, "UTF-8") ?> · Rev <?= htmlspecialchars($doc["current_revision"], ENT_QUOTES, "UTF-8") ?></span>
                        </div>
                        <div class="list-item-side">
                            <span class="status-pill" data-i18n="<?= $statusI18n[$doc["review_status"]] ?? "" ?>"><?= htmlspecialchars($statusLabels[$doc["review_status"]] ?? $doc["review_status"], ENT_QUOTES, "UTF-8") ?></span>
                            <span class="list-item-date"><?= htmlspecialchars($doc["review_date"] ?: "-", ENT_QUOTES, "UTF-8") ?></span>
                        </div>
                    </div>

                    <?php if ($canReview): ?>
                    <form class="auditor-form review-submit-form" method="post" action="document-reviews.php">
                        <?= qmsCsrfField($csrfScope) ?>
                        <input type="hidden" name="form_type" value="record_review">
                        <input type="hidden" name="document_id" value="<?= (int) $doc["id"] ?>">
                        <input type="hidden" name="document_title" value="<?= htmlspecialchars($doc["title"], ENT_QUOTES, "UTF-8") ?>">
                        <div class="form-grid review-form-grid">
                            <label class="form-field">
                                <span data-i18n="docReviewOutcomeLabel">Karar</span>
                                <select name="outcome" required>
                                    <?php foreach ($outcomeLabels as $key => $label): ?>
                                        <option value="<?= $key ?>"><?= htmlspecialchars($label, ENT_QUOTES, "UTF-8") ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="form-field">
                                <span data-i18n="docReviewDateLabel">Gözden Geçirme Tarihi</span>
                                <input type="date" name="reviewed_at" value="<?= date("Y-m-d") ?>">
                            </label>
                            <label class="form-field">
                                <span data-i18n="docReviewNextDateLabel">Sonraki Gözden Geçirme</span>
                                <input type="date" name="next_review_date" required>
                            </label>
                            <label class="form-field form-field-wide">
                                <span data-i18n="docReviewNotesLabel">Notlar</span>
                                <input type="text" name="notes" maxlength="4000">
                            </label>
                        </div>
                        <div class="form-actions">
                            <button class="secondary-button" type="submit" data-i18n="docReviewRecordButton">Gözden Geçirmeyi Kaydet</button>
                        </div>
                    </form>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="console-card">
            <div class="section-heading compact-heading">
                <div>
                    <h3 data-i18n="docReviewHistoryTitle">Gözden Geçirme Geçmişi</h3>
                    <p data-i18n="docReviewHistoryText">Son gözden geçirme işlemleri.</p>
                </div>
            </div>
            <div class="admin-list">
                <?php if (!$history): ?>
                    <div class="empty-state" data-i18n="docReviewNoHistoryText">Henüz gözden geçirme kaydı yok.</div>
                <?php endif; ?>
                <?php foreach ($history as $entry): ?>
                    <div class="admin-list-item">
                        <div class="list-item-main">
                            <a href="document-detail.php?id=<?= (int) $entry["document_id"] ?>">
                                <strong><?= htmlspecialchars($entry["document_title"], ENT_QUOTES, "UTF-8") ?></strong>
                            </a>
                            <span><?= htmlspecialchars($entry["document_code"], ENT_QUOTES, "UTF-8") ?> · <?= htmlspecialchars($entry["reviewer_name"] ?: "-", ENT_QUOTES, "UTF-8") ?> · <?= htmlspecialchars($entry["reviewed_at"], ENT_QUOTES, "UTF-8") ?></span>
                        </div>
                        <div class="list-item-side">
                            <span class="status-pill" data-i18n="<?= $outcomeI18n[$entry["outcome"]] ?? "" ?>"><?= htmlspecialchars($outcomeLabels[$entry["outcome"]] ?? $entry["outcome"], ENT_QUOTES, "UTF-8") ?></span>
                            <span class="list-item-date"><?= htmlspecialchars($entry["notes"] ?: "-", ENT_QUOTES, "UTF-8") ?></span>
                        </div>
                    </div>
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
