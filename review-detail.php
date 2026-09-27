<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/review-functions.php';
require_once __DIR__ . '/includes/report-export-data.php';
require_once __DIR__ . '/includes/performance-functions.php';
require_once __DIR__ . '/includes/audit-log-functions.php';

$reviewId = (int) ($_GET["id"] ?? $_POST["review_id"] ?? 0);
$userId = (int) ($_SESSION["qms_user_id"] ?? 0);
$isSuperAdmin = ($_SESSION["qms_role"] ?? "") === "super_admin";
$role = qmsCurrentRole();
$csrfScope = 'review_detail';

// Kapsamli okuma: id degistirilerek baska sirketin kaydi acilamaz.
$review = qmsReviewFind($pdo, $reviewId, $userId, $role);

if (!$review) {
    header("Location: reviews.php");
    exit;
}

$companyId = (int) $review["company_id"];
$statusLabels = qmsReviewStatusLabels();
$statusI18n = qmsReviewStatusI18nKeys();
$itemTypeLabels = qmsReviewItemTypeLabels();
$itemTypeI18n = qmsReviewItemTypeI18nKeys();

// Sorumlu olabilecek kullanicilar ve baglanabilecek uygunsuzluklar sirkete baglidir.
$responsibleOptions = qmsCompanyResponsibleOptions($pdo, $companyId);
$allowedResponsibleIds = array_map('intval', array_column($responsibleOptions, 'id'));
$nonconformityOptions = qmsCompanyNonconformityOptions($pdo, $companyId);
$allowedNonconformityIds = array_map('intval', array_column($nonconformityOptions, 'id'));

// Girdi degerleri rapor motorundan gelir (paralel hesap yok).
$report = buildReportExportData($pdo, $userId, $isSuperAdmin, [
    "company_id" => $companyId,
    "start_date" => $review["period_start"],
    "end_date" => $review["period_end"],
]);
$inputKpis = qmsPerformanceKpis();
$inputMetrics = $report["metrics"] ?? [];

$formError = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    qmsCsrfVerify($csrfScope, $_POST["csrf"] ?? null);

    $formType = (string) ($_POST["form_type"] ?? "");
    $redirect = "review-detail.php?id=" . $reviewId;

    if ($formType === "update_review") {
        $formData = [
            "title" => qmsReviewText($_POST["title"] ?? "", 255),
            "review_date" => trim((string) ($_POST["review_date"] ?? "")),
            "period_start" => trim((string) ($_POST["period_start"] ?? "")),
            "period_end" => trim((string) ($_POST["period_end"] ?? "")),
            "participants" => qmsReviewText($_POST["participants"] ?? "", 4000),
            "scope_notes" => qmsReviewText($_POST["scope_notes"] ?? "", 4000),
            "status" => (string) ($_POST["status"] ?? "planned"),
            "next_review_date" => trim((string) ($_POST["next_review_date"] ?? ""))
        ];

        $reviewDate = qmsReviewDate($formData["review_date"]);
        $periodStart = qmsReviewDate($formData["period_start"]);
        $periodEnd = qmsReviewDate($formData["period_end"]);
        $nextReviewDate = $formData["next_review_date"] !== "" ? qmsReviewDate($formData["next_review_date"]) : null;
        $nextReviewValid = $formData["next_review_date"] === "" || $nextReviewDate !== null;

        if ($formData["title"] === "") {
            $formError = "Lütfen gözden geçirme başlığını girin.";
        } elseif (!in_array($formData["status"], QMS_REVIEW_STATUSES, true)) {
            $formError = "Geçerli bir durum seçin.";
        } elseif ($reviewDate === null || $periodStart === null || $periodEnd === null) {
            $formError = "Toplantı ve dönem tarihleri geçerli değil.";
        } elseif ($periodEnd < $periodStart) {
            $formError = "Dönem bitiş tarihi başlangıçtan önce olamaz.";
        } elseif (!$nextReviewValid) {
            $formError = "Sonraki gözden geçirme tarihi geçerli değil.";
        } else {
            $updateStmt = $pdo->prepare(
                "UPDATE management_reviews
                 SET title = :title,
                     review_date = :review_date,
                     period_start = :period_start,
                     period_end = :period_end,
                     participants = :participants,
                     scope_notes = :scope_notes,
                     status = :status,
                     next_review_date = :next_review_date,
                     updated_by = :updated_by
                 WHERE id = :id"
            );
            $updateStmt->execute([
                "title" => $formData["title"],
                "review_date" => $reviewDate,
                "period_start" => $periodStart,
                "period_end" => $periodEnd,
                "participants" => $formData["participants"] !== "" ? $formData["participants"] : null,
                "scope_notes" => $formData["scope_notes"] !== "" ? $formData["scope_notes"] : null,
                "status" => $formData["status"],
                "next_review_date" => $nextReviewDate,
                "updated_by" => $userId ?: null,
                "id" => $reviewId
            ]);

            qmsReviewNotifyStatusChange($pdo, [
                "company_id" => $companyId,
                "title" => $formData["title"],
                "link" => $redirect,
                "previous_status" => (string) $review["status"],
                "new_status" => $formData["status"],
                "actor_user_id" => $userId
            ]);

            if ($formData["status"] === "completed" && $review["status"] !== "completed") {
                qmsAuditLog($pdo, $companyId, $userId, 'management_review', $reviewId, 'complete', 'Yönetim gözden geçirmesi tamamlandı: ' . $formData["title"]);
            }

            header("Location: " . $redirect . "&updated=1");
            exit;
        }
    } elseif ($formType === "add_item" || $formType === "update_item" || $formType === "remove_item") {
        $itemId = (int) ($_POST["item_id"] ?? 0);

        if ($formType === "update_item" || $formType === "remove_item") {
            $item = qmsReviewItemFind($pdo, $itemId, $userId, $role);
            if (!$item || (int) $item["review_id"] !== $reviewId) {
                $formError = "Kalem kaydı bulunamadı.";
            } elseif ($formType === "remove_item") {
                $pdo->prepare("DELETE FROM management_review_items WHERE id = :id")->execute(["id" => $itemId]);
                header("Location: " . $redirect . "&item=removed");
                exit;
            }
        }

        if ($formType !== "remove_item" && $formError === "") {
            $itemData = [
                "item_type" => (string) ($_POST["item_type"] ?? "decision"),
                "topic" => qmsReviewText($_POST["topic"] ?? "", 255),
                "description" => qmsReviewText($_POST["description"] ?? "", 4000),
                "responsible_user_id" => (int) ($_POST["responsible_user_id"] ?? 0),
                "due_date" => trim((string) ($_POST["due_date"] ?? "")),
                "nonconformity_id" => (int) ($_POST["nonconformity_id"] ?? 0)
            ];

            $dueDate = $itemData["due_date"] !== "" ? qmsReviewDate($itemData["due_date"]) : null;

            if ($itemData["responsible_user_id"] > 0 && !in_array($itemData["responsible_user_id"], $allowedResponsibleIds, true)) {
                $itemData["responsible_user_id"] = 0;
            }
            if ($itemData["nonconformity_id"] > 0 && !in_array($itemData["nonconformity_id"], $allowedNonconformityIds, true)) {
                $formError = "Seçilen uygunsuzluk bu şirkete ait değil.";
            }

            if ($formError === "" && $itemData["topic"] === "") {
                $formError = "Lütfen kalem başlığını girin.";
            } elseif ($formError === "" && !in_array($itemData["item_type"], QMS_REVIEW_ITEM_TYPES, true)) {
                $formError = "Geçerli bir kalem türü seçin.";
            } elseif ($formError === "" && $itemData["due_date"] !== "" && $dueDate === null) {
                $formError = "Termin tarihi geçerli değil.";
            } elseif ($formError === "") {
                if ($formType === "add_item") {
                    $itemStmt = $pdo->prepare(
                        "INSERT INTO management_review_items
                            (review_id, item_type, topic, description, responsible_user_id, due_date, nonconformity_id)
                         VALUES (:review_id, :item_type, :topic, :description, :responsible_user_id, :due_date, :nonconformity_id)"
                    );
                } else {
                    $itemStmt = $pdo->prepare(
                        "UPDATE management_review_items
                         SET item_type = :item_type, topic = :topic, description = :description,
                             responsible_user_id = :responsible_user_id, due_date = :due_date,
                             nonconformity_id = :nonconformity_id
                         WHERE id = :id"
                    );
                }
                $itemStmt->execute(array_merge([
                    "item_type" => $itemData["item_type"],
                    "topic" => $itemData["topic"],
                    "description" => $itemData["description"] !== "" ? $itemData["description"] : null,
                    "responsible_user_id" => $itemData["responsible_user_id"] > 0 ? $itemData["responsible_user_id"] : null,
                    "due_date" => $dueDate,
                    "nonconformity_id" => $itemData["nonconformity_id"] > 0 ? $itemData["nonconformity_id"] : null,
                ], $formType === "add_item" ? ["review_id" => $reviewId] : ["id" => $itemId]));

                header("Location: " . $redirect . "&item=" . ($formType === "add_item" ? "added" : "updated"));
                exit;
            }
        }
    } else {
        $formError = "Geçersiz istek.";
    }
}

$items = qmsReviewItems($pdo, $reviewId);
$itemCount = count($items);
$actionCount = count(array_filter($items, static fn(array $item): bool => $item["item_type"] === "action"));
$inputCount = count(array_filter($items, static fn(array $item): bool => $item["item_type"] === "input"));
$decisionCount = $itemCount - $actionCount - $inputCount;

$activeNav = "reviews";

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f9fafb">
    <title>QuAmi Gözden Geçirme Detayı</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar">
        <div class="topbar-inner">
            <div class="page-title-block">
                <strong data-i18n="reviewDetailTitle">Gözden Geçirme Detayı</strong>
                <span><?= htmlspecialchars($review["company_name"], ENT_QUOTES, "UTF-8") ?> · <?= htmlspecialchars($review["title"], ENT_QUOTES, "UTF-8") ?></span>
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
                <span class="section-kicker" data-i18n="reviewWorkspaceKicker">Gözden Geçirme Çalışma Alanı</span>
                <h1 data-i18n="reviewDetailTitle">Gözden Geçirme Detayı</h1>
                <p><?= htmlspecialchars($review["title"], ENT_QUOTES, "UTF-8") ?></p>
            </div>
            <a class="secondary-button" href="reviews.php" data-i18n="backToReviewsButton">Gözden Geçirmelere Dön</a>
        </section>

        <section class="dashboard-grid compact-dashboard-grid">
            <div class="dashboard-card metric-blue">
                <?= appIcon("reviews", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="reviewStatusLabel">Durum</span>
                    <strong class="dashboard-card-number detail-card-value" data-i18n="<?= $statusI18n[$review["status"]] ?? "" ?>"><?= htmlspecialchars($statusLabels[$review["status"]] ?? $review["status"], ENT_QUOTES, "UTF-8") ?></strong>
                </div>
            </div>
            <div class="dashboard-card metric-violet">
                <?= appIcon("clock", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="reviewDateLabel">Toplantı Tarihi</span>
                    <strong class="dashboard-card-number detail-card-value"><?= htmlspecialchars($review["review_date"], ENT_QUOTES, "UTF-8") ?></strong>
                </div>
            </div>
            <div class="dashboard-card metric-orange">
                <?= appIcon("table", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="reviewPeriodLabel">Dönem</span>
                    <strong class="dashboard-card-number detail-card-value"><?= htmlspecialchars($review["period_start"], ENT_QUOTES, "UTF-8") ?> – <?= htmlspecialchars($review["period_end"], ENT_QUOTES, "UTF-8") ?></strong>
                </div>
            </div>
            <div class="dashboard-card metric-teal">
                <?= appIcon("checkBadge", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="reviewItemCountLabel">Kalem Sayısı</span>
                    <strong class="dashboard-card-number detail-card-value"><?= $itemCount ?></strong>
                </div>
            </div>
        </section>

        <section class="form-panel">
            <?php if (($_GET["created"] ?? "") === "1"): ?>
                <div class="form-message success" data-i18n="reviewCreatedMessage">Gözden geçirme kaydı oluşturuldu.</div>
            <?php endif; ?>
            <?php if (($_GET["updated"] ?? "") === "1"): ?>
                <div class="form-message success" data-i18n="reviewUpdatedMessage">Gözden geçirme kaydı güncellendi.</div>
            <?php endif; ?>
            <?php if (($_GET["item"] ?? "") === "added"): ?>
                <div class="form-message success" data-i18n="reviewItemAddedMessage">Kalem eklendi.</div>
            <?php elseif (($_GET["item"] ?? "") === "updated"): ?>
                <div class="form-message success" data-i18n="reviewItemUpdatedMessage">Kalem güncellendi.</div>
            <?php elseif (($_GET["item"] ?? "") === "removed"): ?>
                <div class="form-message success" data-i18n="reviewItemRemovedMessage">Kalem kaldırıldı.</div>
            <?php endif; ?>
            <?php if ($formError !== ""): ?>
                <div class="form-message error"><?= htmlspecialchars($formError, ENT_QUOTES, "UTF-8") ?></div>
            <?php endif; ?>

            <form class="auditor-form" method="post" action="review-detail.php?id=<?= $reviewId ?>">
                <?= qmsCsrfField($csrfScope) ?>
                <input type="hidden" name="form_type" value="update_review">
                <input type="hidden" name="review_id" value="<?= $reviewId ?>">
                <div class="form-grid">
                    <label class="form-field">
                        <span data-i18n="companySelectLabel">Şirket</span>
                        <input type="text" value="<?= htmlspecialchars($review["company_name"], ENT_QUOTES, "UTF-8") ?>" readonly>
                    </label>
                    <label class="form-field">
                        <span data-i18n="reviewTitleLabel">Başlık</span>
                        <input type="text" name="title" maxlength="255" value="<?= htmlspecialchars($review["title"], ENT_QUOTES, "UTF-8") ?>" required>
                    </label>
                    <label class="form-field">
                        <span data-i18n="reviewDateLabel">Toplantı Tarihi</span>
                        <input type="date" name="review_date" value="<?= htmlspecialchars($review["review_date"], ENT_QUOTES, "UTF-8") ?>" required>
                    </label>
                    <label class="form-field">
                        <span data-i18n="reviewPeriodStartLabel">Dönem Başlangıcı</span>
                        <input type="date" name="period_start" value="<?= htmlspecialchars($review["period_start"], ENT_QUOTES, "UTF-8") ?>" required>
                    </label>
                    <label class="form-field">
                        <span data-i18n="reviewPeriodEndLabel">Dönem Bitişi</span>
                        <input type="date" name="period_end" value="<?= htmlspecialchars($review["period_end"], ENT_QUOTES, "UTF-8") ?>" required>
                    </label>
                    <label class="form-field">
                        <span data-i18n="nextReviewDateLabel">Sonraki Gözden Geçirme</span>
                        <input type="date" name="next_review_date" value="<?= htmlspecialchars((string) ($review["next_review_date"] ?? ""), ENT_QUOTES, "UTF-8") ?>">
                    </label>
                    <label class="form-field">
                        <span data-i18n="reviewStatusLabel">Durum</span>
                        <select name="status">
                            <?php foreach ($statusLabels as $value => $label): ?>
                                <option value="<?= $value ?>" <?= $review["status"] === $value ? "selected" : "" ?> data-i18n="<?= $statusI18n[$value] ?>"><?= htmlspecialchars($label, ENT_QUOTES, "UTF-8") ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="form-field form-field-wide">
                        <span data-i18n="reviewParticipantsLabel">Katılımcılar</span>
                        <textarea name="participants" rows="3"><?= htmlspecialchars((string) ($review["participants"] ?? ""), ENT_QUOTES, "UTF-8") ?></textarea>
                    </label>
                    <label class="form-field form-field-wide">
                        <span data-i18n="reviewScopeNotesLabel">Kapsam / Not</span>
                        <textarea name="scope_notes" rows="4"><?= htmlspecialchars((string) ($review["scope_notes"] ?? ""), ENT_QUOTES, "UTF-8") ?></textarea>
                    </label>
                </div>
                <div class="form-actions">
                    <button class="primary-button" type="submit" data-i18n="saveReviewButton">Gözden Geçirmeyi Kaydet</button>
                </div>
            </form>
        </section>

        <section class="page-section form-panel">
            <div class="section-heading compact-heading">
                <div>
                    <h3 data-i18n="reviewInputsTitle">Dönem Girdileri</h3>
                    <p data-i18n="reviewInputsText">Bu dönem için rapor motorundan alınan KPI değerleri; gözden geçirme kararlarının girdisidir.</p>
                </div>
            </div>
            <div class="dashboard-grid performance-grid">
                <?php foreach ($inputKpis as $kpiKey => $definition): $inputValue = $inputMetrics[$kpiKey] ?? null; ?>
                    <div class="dashboard-card performance-card compact-input-card">
                        <div class="dashboard-card-content">
                            <?= appIcon($definition["icon"], "dashboard-card-icon") ?>
                            <span class="dashboard-card-label" data-i18n="<?= $definition["label_key"] ?>"><?= htmlspecialchars(qmsPerformanceKpiLabels()[$kpiKey] ?? $kpiKey, ENT_QUOTES, "UTF-8") ?></span>
                            <strong class="dashboard-card-number"><?= $inputValue === null ? "-" : htmlspecialchars((string) $inputValue, ENT_QUOTES, "UTF-8") ?><?= htmlspecialchars($definition["unit"], ENT_QUOTES, "UTF-8") ?></strong>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="console-card checklist-section">
            <div class="section-heading compact-heading">
                <div>
                    <h3 data-i18n="reviewItemsTitle">Gündem ve Kararlar</h3>
                    <p data-i18n="reviewItemsText">Girdi, karar ve aksiyon kalemleri; sorumlu, termin ve uygunsuzluk bağlantısı ile.</p>
                </div>
            </div>

            <div class="admin-list">
                <?php if (!$items): ?>
                    <div class="empty-state" data-i18n="noReviewItemsText">Bu gözden geçirme için henüz kalem eklenmedi.</div>
                <?php endif; ?>

                <?php foreach ($items as $item): ?>
                    <form class="checklist-item-form" method="post" action="review-detail.php?id=<?= $reviewId ?>">
                        <?= qmsCsrfField($csrfScope) ?>
                        <input type="hidden" name="review_id" value="<?= $reviewId ?>">
                        <input type="hidden" name="item_id" value="<?= (int) $item["id"] ?>">
                        <div class="checklist-item-heading">
                            <div>
                                <strong><?= htmlspecialchars($item["topic"], ENT_QUOTES, "UTF-8") ?></strong>
                                <span><?= htmlspecialchars((string) ($item["responsible_full_name"] ?: "-"), ENT_QUOTES, "UTF-8") ?><?= $item["nonconformity_title"] ? " · " . htmlspecialchars($item["nonconformity_title"], ENT_QUOTES, "UTF-8") : "" ?></span>
                            </div>
                            <span class="status-pill" data-i18n="<?= $itemTypeI18n[$item["item_type"]] ?? "" ?>"><?= htmlspecialchars($itemTypeLabels[$item["item_type"]] ?? $item["item_type"], ENT_QUOTES, "UTF-8") ?></span>
                        </div>
                        <div class="form-grid checklist-edit-grid">
                            <label class="form-field">
                                <span data-i18n="reviewItemTypeLabel">Tür</span>
                                <select name="item_type">
                                    <?php foreach ($itemTypeLabels as $value => $label): ?>
                                        <option value="<?= $value ?>" <?= $item["item_type"] === $value ? "selected" : "" ?> data-i18n="<?= $itemTypeI18n[$value] ?>"><?= htmlspecialchars($label, ENT_QUOTES, "UTF-8") ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="form-field form-field-wide">
                                <span data-i18n="reviewTopicLabel">Başlık</span>
                                <input type="text" name="topic" maxlength="255" value="<?= htmlspecialchars($item["topic"], ENT_QUOTES, "UTF-8") ?>" required>
                            </label>
                            <label class="form-field">
                                <span data-i18n="responsibleUserLabel">Sorumlu (isteğe bağlı)</span>
                                <select name="responsible_user_id">
                                    <option value="0" data-i18n="responsibleUserNoneOption">— seçilmedi</option>
                                    <?php foreach ($responsibleOptions as $responsibleOption): ?>
                                        <option value="<?= (int) $responsibleOption["id"] ?>" <?= (int) ($item["responsible_user_id"] ?? 0) === (int) $responsibleOption["id"] ? "selected" : "" ?>><?= htmlspecialchars($responsibleOption["full_name"], ENT_QUOTES, "UTF-8") ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="form-field">
                                <span data-i18n="dueDateLabel">Termin</span>
                                <input type="date" name="due_date" value="<?= htmlspecialchars((string) ($item["due_date"] ?? ""), ENT_QUOTES, "UTF-8") ?>">
                            </label>
                            <label class="form-field">
                                <span data-i18n="reviewLinkedNonconformityLabel">Uygunsuzluk</span>
                                <select name="nonconformity_id">
                                    <option value="0" data-i18n="reviewNoLinkOption">— bağlantı yok</option>
                                    <?php foreach ($nonconformityOptions as $option): ?>
                                        <option value="<?= (int) $option["id"] ?>" <?= (int) ($item["nonconformity_id"] ?? 0) === (int) $option["id"] ? "selected" : "" ?>><?= htmlspecialchars($option["title"], ENT_QUOTES, "UTF-8") ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="form-field form-field-wide">
                                <span data-i18n="reviewDescriptionLabel">Açıklama</span>
                                <textarea name="description" rows="3"><?= htmlspecialchars((string) ($item["description"] ?? ""), ENT_QUOTES, "UTF-8") ?></textarea>
                            </label>
                        </div>
                        <div class="form-actions">
                            <button class="primary-button" type="submit" name="form_type" value="update_item" data-i18n="saveItemButton">Kalemi Kaydet</button>
                            <button class="danger-button" type="submit" name="form_type" value="remove_item" data-i18n="removeItemButton">Kaldır</button>
                        </div>
                    </form>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="page-section form-panel">
            <div class="section-heading compact-heading">
                <div>
                    <h3 data-i18n="addReviewItemTitle">Kalem Ekle</h3>
                    <p data-i18n="addReviewItemText">Bir girdi, karar veya aksiyon kalemi ekleyin; aksiyonlara sorumlu ve termin atanabilir.</p>
                </div>
            </div>
            <form class="auditor-form" method="post" action="review-detail.php?id=<?= $reviewId ?>">
                <?= qmsCsrfField($csrfScope) ?>
                <input type="hidden" name="form_type" value="add_item">
                <input type="hidden" name="review_id" value="<?= $reviewId ?>">
                <div class="form-grid">
                    <label class="form-field">
                        <span data-i18n="reviewItemTypeLabel">Tür</span>
                        <select name="item_type">
                            <?php foreach ($itemTypeLabels as $value => $label): ?>
                                <option value="<?= $value ?>" data-i18n="<?= $itemTypeI18n[$value] ?>"><?= htmlspecialchars($label, ENT_QUOTES, "UTF-8") ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="form-field">
                        <span data-i18n="reviewTopicLabel">Başlık</span>
                        <input type="text" name="topic" maxlength="255" required>
                    </label>
                    <label class="form-field">
                        <span data-i18n="responsibleUserLabel">Sorumlu (isteğe bağlı)</span>
                        <select name="responsible_user_id">
                            <option value="0" data-i18n="responsibleUserNoneOption">— seçilmedi</option>
                            <?php foreach ($responsibleOptions as $responsibleOption): ?>
                                <option value="<?= (int) $responsibleOption["id"] ?>"><?= htmlspecialchars($responsibleOption["full_name"], ENT_QUOTES, "UTF-8") ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="form-field">
                        <span data-i18n="dueDateLabel">Termin</span>
                        <input type="date" name="due_date">
                    </label>
                    <label class="form-field">
                        <span data-i18n="reviewLinkedNonconformityLabel">Uygunsuzluk</span>
                        <select name="nonconformity_id">
                            <option value="0" data-i18n="reviewNoLinkOption">— bağlantı yok</option>
                            <?php foreach ($nonconformityOptions as $option): ?>
                                <option value="<?= (int) $option["id"] ?>"><?= htmlspecialchars($option["title"], ENT_QUOTES, "UTF-8") ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="form-field form-field-wide">
                        <span data-i18n="reviewDescriptionLabel">Açıklama</span>
                        <textarea name="description" rows="3"></textarea>
                    </label>
                </div>
                <div class="form-actions">
                    <button class="primary-button" type="submit" data-i18n="addReviewItemButton">Kalem Ekle</button>
                </div>
            </form>
        </section>
    </main>
    <script src="assets/js/theme.js"></script>
    <script src="assets/js/language.js"></script>
    <script src="assets/js/sidebar.js"></script>
    <script src="assets/js/pwa.js"></script>
</body>
</html>
