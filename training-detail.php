<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/access.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/notifications.php';
require_once __DIR__ . '/includes/training-functions.php';
require_once __DIR__ . '/includes/audit-log-functions.php';

$trainingId = (int) ($_GET["id"] ?? $_POST["training_id"] ?? 0);
$userId = (int) ($_SESSION["qms_user_id"] ?? 0);
$role = qmsCurrentRole();
$csrfScope = 'training_detail';

// Kapsamli okuma: id degistirilerek baska sirketin egitimi acilamaz.
$training = qmsTrainingFind($pdo, $trainingId, $userId, $role);

if (!$training) {
    header("Location: trainings.php");
    exit;
}

$companyId = (int) $training["company_id"];
$statusLabels = qmsTrainingStatusLabels();
$statusI18n = qmsTrainingStatusI18nKeys();
$participantStatusLabels = qmsTrainingParticipantStatusLabels();
$participantStatusI18n = qmsTrainingParticipantStatusI18nKeys();

// Katilimci olabilecek kullanicilar: sirketin kendi kullanicilari ve o sirkete
// atanmis sistem adminleri (CAPA sorumlu-kullanici modeliyle ayni).
$participantCandidates = qmsCompanyResponsibleOptions($pdo, $companyId);
$allowedParticipantIds = array_map('intval', array_column($participantCandidates, 'id'));

$formError = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    qmsCsrfVerify($csrfScope, $_POST["csrf"] ?? null);

    $formType = (string) ($_POST["form_type"] ?? "");
    $redirect = "training-detail.php?id=" . $trainingId;

    if ($formType === "update_training") {
        $formData = [
            "title" => qmsTrainingText($_POST["title"] ?? "", 255),
            "category" => qmsTrainingText($_POST["category"] ?? "", 100),
            "provider" => qmsTrainingText($_POST["provider"] ?? "", 180),
            "trainer_name" => qmsTrainingText($_POST["trainer_name"] ?? "", 150),
            "planned_date" => trim((string) ($_POST["planned_date"] ?? "")),
            "duration_hours" => trim((string) ($_POST["duration_hours"] ?? "")),
            "status" => (string) ($_POST["status"] ?? "planned"),
            "description" => qmsTrainingText($_POST["description"] ?? "", 4000)
        ];

        $plannedDate = qmsTrainingDate($formData["planned_date"]);
        $durationHours = qmsTrainingDuration($formData["duration_hours"]);

        if ($formData["title"] === "") {
            $formError = "Lütfen eğitim başlığını girin.";
        } elseif (!in_array($formData["status"], QMS_TRAINING_STATUSES, true)) {
            $formError = "Geçerli bir durum seçin.";
        } elseif ($formData["planned_date"] !== "" && $plannedDate === null) {
            $formError = "Planlanan tarih geçerli değil.";
        } elseif ($formData["duration_hours"] !== "" && $durationHours === null) {
            $formError = "Süre 0 ile 1000 saat arasında bir sayı olmalıdır.";
        } else {
            // Tamamlanma tarihi duruma baglidir: "tamamlandi" ise ilk gecis
            // tarihi korunur, diger durumlarda temizlenir.
            $completedDate = $formData["status"] === "completed"
                ? ($training["completed_date"] ?: date("Y-m-d"))
                : null;

            $updateStmt = $pdo->prepare(
                "UPDATE trainings
                 SET title = :title,
                     category = :category,
                     provider = :provider,
                     trainer_name = :trainer_name,
                     planned_date = :planned_date,
                     duration_hours = :duration_hours,
                     status = :status,
                     description = :description,
                     completed_date = :completed_date,
                     updated_by = :updated_by
                 WHERE id = :id"
            );
            $updateStmt->execute([
                "title" => $formData["title"],
                "category" => $formData["category"] !== "" ? $formData["category"] : null,
                "provider" => $formData["provider"] !== "" ? $formData["provider"] : null,
                "trainer_name" => $formData["trainer_name"] !== "" ? $formData["trainer_name"] : null,
                "planned_date" => $plannedDate,
                "duration_hours" => $durationHours,
                "status" => $formData["status"],
                "description" => $formData["description"] !== "" ? $formData["description"] : null,
                "completed_date" => $completedDate,
                "updated_by" => $userId ?: null,
                "id" => $trainingId
            ]);

            // Egitim tamamlandiginda sirketin atanmis sistem adminleri bilgilendirilir.
            if (($training["status"] ?? "") !== "completed" && $formData["status"] === "completed") {
                qmsNotifyCompanyAdmins(
                    $pdo,
                    $companyId,
                    "training_completed",
                    "Eğitim tamamlandı",
                    $formData["title"],
                    "training-detail.php?id=" . $trainingId,
                    $userId
                );
            }

            qmsAuditLog(
                $pdo,
                $companyId,
                $userId,
                'training',
                $trainingId,
                $formData["status"] !== $training["status"] ? ($formData["status"] === 'completed' ? 'complete' : 'status_change') : 'update',
                'Eğitim güncellendi: ' . $formData["title"] . ' (durum: ' . $statusLabels[$formData["status"]] . ')'
            );

            header("Location: " . $redirect . "&updated=1");
            exit;
        }

        // Hata halinde formu kullanicinin girdigi degerlerle yeniden ciz.
        $training = array_merge($training, [
            "title" => $formData["title"],
            "category" => $formData["category"],
            "provider" => $formData["provider"],
            "trainer_name" => $formData["trainer_name"],
            "planned_date" => $formData["planned_date"],
            "duration_hours" => $formData["duration_hours"],
            "status" => $formData["status"],
            "description" => $formData["description"]
        ]);
    } elseif ($formType === "add_participant") {
        $participantUserId = (int) ($_POST["user_id"] ?? 0);

        if (!in_array($participantUserId, $allowedParticipantIds, true)) {
            $formError = "Yalnızca bu şirketin kullanıcıları katılımcı olarak eklenebilir.";
        } else {
            $existsStmt = $pdo->prepare(
                "SELECT COUNT(*) FROM training_participants WHERE training_id = :training_id AND user_id = :user_id"
            );
            $existsStmt->execute(["training_id" => $trainingId, "user_id" => $participantUserId]);

            if ((int) $existsStmt->fetchColumn() > 0) {
                $formError = "Bu kullanıcı zaten katılımcı listesinde.";
            } else {
                $insertStmt = $pdo->prepare(
                    "INSERT INTO training_participants (training_id, user_id, status)
                     VALUES (:training_id, :user_id, 'assigned')"
                );
                $insertStmt->execute(["training_id" => $trainingId, "user_id" => $participantUserId]);

                if ($participantUserId !== $userId) {
                    qmsNotify(
                        $pdo,
                        $participantUserId,
                        "training_assigned",
                        "Size bir eğitim atandı",
                        $training["title"],
                        "training-detail.php?id=" . $trainingId
                    );
                }

                header("Location: " . $redirect . "&participant=added");
                exit;
            }
        }
    } elseif ($formType === "update_participant" || $formType === "remove_participant") {
        $participantId = (int) ($_POST["participant_id"] ?? 0);

        // Katilimci kaydi gercekten bu egitime mi ait?
        $participantStmt = $pdo->prepare(
            "SELECT id, user_id FROM training_participants
             WHERE id = :id AND training_id = :training_id LIMIT 1"
        );
        $participantStmt->execute(["id" => $participantId, "training_id" => $trainingId]);
        $participant = $participantStmt->fetch(PDO::FETCH_ASSOC);

        if (!$participant) {
            $formError = "Katılımcı kaydı bulunamadı.";
        } elseif ($formType === "remove_participant") {
            $pdo->prepare("DELETE FROM training_participants WHERE id = :id")->execute(["id" => $participantId]);

            header("Location: " . $redirect . "&participant=removed");
            exit;
        } else {
            $participantStatus = (string) ($_POST["status"] ?? "assigned");
            $participantNote = qmsTrainingText($_POST["note"] ?? "", 255);
            $rawScore = trim((string) ($_POST["score"] ?? ""));
            $participantScore = qmsTrainingScore($rawScore);

            if (!in_array($participantStatus, QMS_TRAINING_PARTICIPANT_STATUSES, true)) {
                $formError = "Geçerli bir katılım durumu seçin.";
            } elseif ($rawScore !== "" && $participantScore === null) {
                $formError = "Puan 0 ile 100 arasında olmalıdır.";
            } else {
                $updateParticipantStmt = $pdo->prepare(
                    "UPDATE training_participants
                     SET status = :status, score = :score, note = :note
                     WHERE id = :id"
                );
                $updateParticipantStmt->execute([
                    "status" => $participantStatus,
                    "score" => $participantScore,
                    "note" => $participantNote !== "" ? $participantNote : null,
                    "id" => $participantId
                ]);

                // Katilimci egitimi tamamlayinca hedef yetkinlik personelin
                // yetkinlik kaydina islenir (personel eslenirse). Puan varsa
                // yetkinlik seviyesi ve sertifika/puan notu olarak da yazilir.
                if ($participantStatus === "completed") {
                    qmsTrainingSyncCompetency($pdo, $trainingId, (int) $participant["user_id"], $participantScore);
                }

                header("Location: " . $redirect . "&participant=updated");
                exit;
            }
        }
    } else {
        $formError = "Geçersiz istek.";
    }

    // Kaydetme basarisizsa kaydin guncel halini yeniden oku.
    $training = qmsTrainingFind($pdo, $trainingId, $userId, $role);
}

$participants = qmsTrainingParticipants($pdo, $trainingId);
$participantSummary = qmsTrainingParticipantSummary($participants);
$participantUserIds = array_map('intval', array_column($participants, 'user_id'));

// Yalnizca henuz katilimci olmayan sirket kullanicilari onerilir.
$selectableCandidates = array_values(array_filter(
    $participantCandidates,
    static fn(array $candidate): bool => !in_array((int) $candidate['id'], $participantUserIds, true)
));

$activeNav = "trainings";

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f9fafb">
    <title>QuAmi Eğitim Detayı</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar">
        <div class="topbar-inner">
            <div class="page-title-block">
                <strong data-i18n="trainingDetailTitle">Eğitim Detayı</strong>
                <span><?= htmlspecialchars($training["company_name"], ENT_QUOTES, "UTF-8") ?> · <?= htmlspecialchars($training["title"], ENT_QUOTES, "UTF-8") ?></span>
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
                <span class="section-kicker" data-i18n="trainingWorkspaceKicker">Eğitim Çalışma Alanı</span>
                <h1 data-i18n="trainingDetailTitle">Eğitim Detayı</h1>
                <p><?= htmlspecialchars($training["title"], ENT_QUOTES, "UTF-8") ?></p>
            </div>
            <a class="secondary-button" href="trainings.php" data-i18n="backToTrainingsButton">Eğitimlere Dön</a>
        </section>

        <section class="dashboard-grid compact-dashboard-grid">
            <div class="dashboard-card metric-blue">
                <?= appIcon("training", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="trainingStatusLabel">Durum</span>
                    <strong class="dashboard-card-number detail-card-value" data-i18n="<?= $statusI18n[$training["status"]] ?? "" ?>"><?= htmlspecialchars($statusLabels[$training["status"]] ?? $training["status"], ENT_QUOTES, "UTF-8") ?></strong>
                </div>
            </div>
            <div class="dashboard-card metric-orange">
                <?= appIcon("clock", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="plannedDateLabel">Planlanan Tarih</span>
                    <strong class="dashboard-card-number detail-card-value"><?= htmlspecialchars($training["planned_date"] ?: "-", ENT_QUOTES, "UTF-8") ?></strong>
                </div>
            </div>
            <div class="dashboard-card metric-violet">
                <?= appIcon("users", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="trainingParticipantsTitle">Katılımcılar</span>
                    <strong class="dashboard-card-number detail-card-value"><?= $participantSummary["completed"] ?>/<?= $participantSummary["total"] ?></strong>
                </div>
            </div>
            <div class="dashboard-card metric-teal">
                <?= appIcon("check", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="trainingCompletionLabel">Tamamlanma</span>
                    <strong class="dashboard-card-number detail-card-value"><?= $participantSummary["rate"] ?>%</strong>
                </div>
            </div>
            <?php if ((string) ($training["target_competency"] ?? "") !== ""): ?>
            <div class="dashboard-card metric-violet">
                <?= appIcon("sparkles", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="trainingCompetencyLabel">Hedef Yetkinlik</span>
                    <strong class="dashboard-card-number detail-card-value"><?= htmlspecialchars((string) $training["target_competency"], ENT_QUOTES, "UTF-8") ?></strong>
                </div>
            </div>
            <?php endif; ?>
        </section>

        <section class="form-panel">
            <?php if (isset($_GET["created"]) && $_GET["created"] === "1"): ?>
                <div class="form-message success" data-i18n="trainingCreatedMessage">Eğitim kaydı oluşturuldu.</div>
            <?php endif; ?>
            <?php if (isset($_GET["updated"]) && $_GET["updated"] === "1"): ?>
                <div class="form-message success" data-i18n="trainingUpdatedMessage">Eğitim kaydı güncellendi.</div>
            <?php endif; ?>
            <?php if (($_GET["participant"] ?? "") === "added"): ?>
                <div class="form-message success" data-i18n="trainingParticipantAddedMessage">Katılımcı eklendi.</div>
            <?php elseif (($_GET["participant"] ?? "") === "updated"): ?>
                <div class="form-message success" data-i18n="trainingParticipantUpdatedMessage">Katılımcı güncellendi.</div>
            <?php elseif (($_GET["participant"] ?? "") === "removed"): ?>
                <div class="form-message success" data-i18n="trainingParticipantRemovedMessage">Katılımcı kaldırıldı.</div>
            <?php endif; ?>
            <?php if ($formError !== ""): ?>
                <div class="form-message error"><?= htmlspecialchars($formError, ENT_QUOTES, "UTF-8") ?></div>
            <?php endif; ?>

            <form class="auditor-form" method="post" action="training-detail.php?id=<?= $trainingId ?>">
                <?= qmsCsrfField($csrfScope) ?>
                <input type="hidden" name="form_type" value="update_training">
                <input type="hidden" name="training_id" value="<?= $trainingId ?>">
                <div class="form-grid">
                    <label class="form-field">
                        <span data-i18n="companySelectLabel">Şirket</span>
                        <input type="text" value="<?= htmlspecialchars($training["company_name"], ENT_QUOTES, "UTF-8") ?>" readonly>
                    </label>
                    <label class="form-field">
                        <span data-i18n="trainingTitleLabel">Eğitim Başlığı</span>
                        <input type="text" name="title" maxlength="255" value="<?= htmlspecialchars($training["title"], ENT_QUOTES, "UTF-8") ?>" required>
                    </label>
                    <label class="form-field">
                        <span data-i18n="trainingCategoryLabel">Kategori</span>
                        <input type="text" name="category" maxlength="100" value="<?= htmlspecialchars((string) ($training["category"] ?? ""), ENT_QUOTES, "UTF-8") ?>">
                    </label>
                    <label class="form-field">
                        <span data-i18n="trainingProviderLabel">Eğitim Sağlayıcı</span>
                        <input type="text" name="provider" maxlength="180" value="<?= htmlspecialchars((string) ($training["provider"] ?? ""), ENT_QUOTES, "UTF-8") ?>">
                    </label>
                    <label class="form-field">
                        <span data-i18n="trainingTrainerLabel">Eğitmen</span>
                        <input type="text" name="trainer_name" maxlength="150" value="<?= htmlspecialchars((string) ($training["trainer_name"] ?? ""), ENT_QUOTES, "UTF-8") ?>">
                    </label>
                    <label class="form-field">
                        <span data-i18n="plannedDateLabel">Planlanan Tarih</span>
                        <input type="date" name="planned_date" value="<?= htmlspecialchars((string) ($training["planned_date"] ?? ""), ENT_QUOTES, "UTF-8") ?>">
                    </label>
                    <label class="form-field">
                        <span data-i18n="trainingDurationLabel">Süre (saat)</span>
                        <input type="number" name="duration_hours" min="0" max="1000" step="0.5" value="<?= htmlspecialchars((string) ($training["duration_hours"] ?? ""), ENT_QUOTES, "UTF-8") ?>">
                    </label>
                    <label class="form-field">
                        <span data-i18n="trainingStatusLabel">Durum</span>
                        <select name="status">
                            <?php foreach ($statusLabels as $value => $label): ?>
                                <option value="<?= $value ?>" <?= $training["status"] === $value ? "selected" : "" ?> data-i18n="<?= $statusI18n[$value] ?>"><?= htmlspecialchars($label, ENT_QUOTES, "UTF-8") ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="form-field">
                        <span data-i18n="trainingCompletedDateLabel">Tamamlanma Tarihi</span>
                        <input type="text" value="<?= htmlspecialchars((string) ($training["completed_date"] ?: "-"), ENT_QUOTES, "UTF-8") ?>" readonly>
                        <small data-i18n="trainingCompletedDateHelp">Durum "Tamamlandı" olduğunda otomatik yazılır.</small>
                    </label>
                    <label class="form-field form-field-wide">
                        <span data-i18n="trainingDescriptionLabel">Açıklama</span>
                        <textarea name="description" rows="4"><?= htmlspecialchars((string) ($training["description"] ?? ""), ENT_QUOTES, "UTF-8") ?></textarea>
                    </label>
                </div>
                <div class="form-actions">
                    <button class="primary-button" type="submit" data-i18n="saveTrainingButton">Eğitimi Kaydet</button>
                </div>
            </form>
        </section>

        <section class="console-card checklist-section">
            <div class="section-heading compact-heading">
                <div>
                    <h3 data-i18n="trainingParticipantsTitle">Katılımcılar</h3>
                    <p data-i18n="trainingParticipantsText">Eğitime atanan kullanıcılar, katılım durumu ve puanları.</p>
                </div>
            </div>

            <div class="admin-list">
                <?php if (!$participants): ?>
                    <div class="empty-state" data-i18n="noTrainingParticipantsText">Bu eğitime henüz katılımcı eklenmedi.</div>
                <?php endif; ?>

                <?php foreach ($participants as $participant): ?>
                    <form class="checklist-item-form" method="post" action="training-detail.php?id=<?= $trainingId ?>">
                        <?= qmsCsrfField($csrfScope) ?>
                        <input type="hidden" name="training_id" value="<?= $trainingId ?>">
                        <input type="hidden" name="participant_id" value="<?= (int) $participant["id"] ?>">
                        <div class="checklist-item-heading">
                            <div>
                                <strong><?= htmlspecialchars($participant["full_name"], ENT_QUOTES, "UTF-8") ?></strong>
                                <span><?= htmlspecialchars(appRoleLabel((string) $participant["role"]), ENT_QUOTES, "UTF-8") ?></span>
                            </div>
                            <span class="status-pill"><?= htmlspecialchars($participantStatusLabels[$participant["status"]] ?? $participant["status"], ENT_QUOTES, "UTF-8") ?></span>
                        </div>
                        <div class="form-grid checklist-edit-grid">
                            <label class="form-field">
                                <span data-i18n="trainingParticipantStatusLabel">Katılım Durumu</span>
                                <select name="status">
                                    <?php foreach ($participantStatusLabels as $value => $label): ?>
                                        <option value="<?= $value ?>" <?= $participant["status"] === $value ? "selected" : "" ?> data-i18n="<?= $participantStatusI18n[$value] ?>"><?= htmlspecialchars($label, ENT_QUOTES, "UTF-8") ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="form-field">
                                <span data-i18n="trainingParticipantScoreLabel">Puan (0–100)</span>
                                <input type="number" name="score" min="0" max="100" step="0.01" value="<?= htmlspecialchars((string) ($participant["score"] ?? ""), ENT_QUOTES, "UTF-8") ?>">
                            </label>
                            <label class="form-field form-field-wide">
                                <span data-i18n="trainingParticipantNoteLabel">Not</span>
                                <input type="text" name="note" maxlength="255" value="<?= htmlspecialchars((string) ($participant["note"] ?? ""), ENT_QUOTES, "UTF-8") ?>">
                            </label>
                        </div>
                        <div class="form-actions">
                            <button class="primary-button" type="submit" name="form_type" value="update_participant" data-i18n="saveParticipantButton">Katılımcıyı Kaydet</button>
                            <button class="danger-button" type="submit" name="form_type" value="remove_participant" data-i18n="removeParticipantButton">Kaldır</button>
                        </div>
                    </form>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="page-section form-panel">
            <div class="section-heading compact-heading">
                <div>
                    <h3 data-i18n="addParticipantTitle">Katılımcı Ekle</h3>
                    <p data-i18n="addParticipantText">Katılımcılar şirketin sistem kullanıcılarıdır; eklenen kullanıcıya bildirim gider.</p>
                </div>
            </div>
            <?php if (!$participantCandidates): ?>
                <div class="empty-state" data-i18n="noParticipantCandidatesText">Bu şirkette katılımcı olabilecek kullanıcı bulunmuyor.</div>
            <?php elseif (!$selectableCandidates): ?>
                <div class="empty-state" data-i18n="participantAllAddedText">Bu şirketteki tüm uygun kullanıcılar zaten katılımcı listesinde.</div>
            <?php else: ?>
                <form class="auditor-form" method="post" action="training-detail.php?id=<?= $trainingId ?>">
                    <?= qmsCsrfField($csrfScope) ?>
                    <input type="hidden" name="form_type" value="add_participant">
                    <input type="hidden" name="training_id" value="<?= $trainingId ?>">
                    <div class="form-grid">
                        <label class="form-field form-field-wide">
                            <span data-i18n="participantUserLabel">Kullanıcı</span>
                            <select name="user_id" required>
                                <option value="0" data-i18n="participantSelectOption">Kullanıcı seçin</option>
                                <?php foreach ($selectableCandidates as $candidate): ?>
                                    <option value="<?= (int) $candidate["id"] ?>"><?= htmlspecialchars($candidate["full_name"], ENT_QUOTES, "UTF-8") ?> · <?= htmlspecialchars(appRoleLabel((string) $candidate["role"]), ENT_QUOTES, "UTF-8") ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </div>
                    <div class="form-actions">
                        <button class="primary-button" type="submit" data-i18n="addParticipantButton">Katılımcı Ekle</button>
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
