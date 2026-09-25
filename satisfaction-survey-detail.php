<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/access.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/satisfaction-functions.php';

$surveyId = (int) ($_GET["id"] ?? 0);
if ($surveyId <= 0) {
    header("Location: satisfaction-surveys.php");
    exit;
}

$userId = (int) ($_SESSION["qms_user_id"] ?? 0);
$role = qmsCurrentRole();
$csrfScope = 'satisfaction_survey_detail';

// Kapsamli okuma: id degistirilerek baska sirketin ankette acilamaz.
$survey = qmsSatisfactionSurveyFind($pdo, $surveyId, $userId, $role);
if (!$survey) {
    header("Location: satisfaction-surveys.php");
    exit;
}

$formError = "";
$formSuccess = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    qmsCsrfVerify($csrfScope, $_POST["csrf"] ?? null);
    $formType = (string) ($_POST["form_type"] ?? "");
    $redirect = "satisfaction-survey-detail.php?id=" . $surveyId;

    if ($formType === "update_survey") {
        $title = trim((string) ($_POST["title"] ?? ""));
        $description = trim((string) ($_POST["description"] ?? ""));
        if ($title === "") {
            $formError = "Lütfen anket başlığını girin.";
        } else {
            $pdo->prepare("UPDATE satisfaction_surveys SET title = ?, description = ? WHERE id = ?")
                ->execute([mb_substr($title, 0, 255), $description !== "" ? mb_substr($description, 0, 4000) : null, $surveyId]);
            $survey["title"] = $title;
            $survey["description"] = $description;
            $formSuccess = "Anket güncellendi.";
        }
    } elseif ($formType === "add_question") {
        $questionText = trim((string) ($_POST["question_text"] ?? ""));
        if ($questionText === "") {
            $formError = "Lütfen soruyu girin.";
        } else {
            $stmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order),0) AS m FROM satisfaction_questions WHERE survey_id = ?");
            $stmt->execute([$surveyId]);
            $sort = (int) $stmt->fetchColumn() + 1;
            $pdo->prepare("INSERT INTO satisfaction_questions (survey_id, question_text, sort_order, active) VALUES (?,?,?,1)")
                ->execute([$surveyId, mb_substr($questionText, 0, 255), $sort]);
            header("Location: " . $redirect . "&q=added");
            exit;
        }
    } elseif ($formType === "remove_question") {
        $questionId = (int) ($_POST["question_id"] ?? 0);
        if ($questionId > 0) {
            $pdo->prepare("UPDATE satisfaction_questions SET active = 0 WHERE id = ? AND survey_id = ?")
                ->execute([$questionId, $surveyId]);
            header("Location: " . $redirect . "&q=removed");
            exit;
        }
        $formError = "Soru silinemedi.";
    } elseif ($formType === "record_response") {
        $answers = [];
        foreach (($_POST["answer"] ?? []) as $qid => $rating) {
            $answers[(int) $qid] = (int) $rating;
        }
        $responseId = qmsSatisfactionRecordResponse($pdo, $surveyId, [
            "customer_name" => (string) ($_POST["customer_name"] ?? ""),
            "customer_contact" => (string) ($_POST["customer_contact"] ?? ""),
            "responded_at" => (string) ($_POST["responded_at"] ?? ""),
            "comment" => (string) ($_POST["comment"] ?? ""),
            "answers" => $answers,
        ], $userId, $role);

        if ($responseId !== null) {
            header("Location: " . $redirect . "&response=recorded");
            exit;
        }
        $formError = "Yanıt kaydedilemedi. Geçerli bir tarih ve tüm sorular için puan girin.";
    } else {
        $formError = "Geçersiz istek.";
    }
}

$questions = qmsSatisfactionQuestions($pdo, $surveyId);
$responses = qmsSatisfactionResponses($pdo, $surveyId);
$activeNav = "satisfaction";

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f9fafb">
    <title>QMS Müşteri Memnuniyeti Anket Detayı</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar">
        <div class="topbar-inner">
            <div class="page-title-block">
                <strong data-i18n="satisfactionSurveyDetailTitle">Müşteri Memnuniyeti Anket Detayı</strong>
                <span><?= htmlspecialchars($survey["company_name"], ENT_QUOTES, "UTF-8") ?> · <?= htmlspecialchars($survey["title"], ENT_QUOTES, "UTF-8") ?></span>
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
                <span class="section-kicker" data-i18n="satisfactionKicker">Müşteri Odaklılık</span>
                <h1 data-i18n="satisfactionSurveyDetailTitle">Müşteri Memnuniyeti Anket Detayı</h1>
                <p><?= htmlspecialchars($survey["title"], ENT_QUOTES, "UTF-8") ?> · <?= (int) $survey["response_count"] ?> <span data-i18n="satisfactionResponsesShortLabel">yanıt</span> · <?= ((float) $survey["avg_score"]) > 0 ? htmlspecialchars((string) $survey["avg_score"], ENT_QUOTES, "UTF-8") . "/5" : "-" ?></p>
            </div>
            <a class="secondary-button" href="satisfaction-surveys.php" data-i18n="backToSurveysButton">Anketlere Dön</a>
        </section>

        <?php if ($formSuccess !== ""): ?>
            <div class="form-message success"><?= htmlspecialchars($formSuccess, ENT_QUOTES, "UTF-8") ?></div>
        <?php endif; ?>
        <?php if ($formError !== ""): ?>
            <div class="form-message error"><?= htmlspecialchars($formError, ENT_QUOTES, "UTF-8") ?></div>
        <?php endif; ?>
        <?php if (($_GET["q"] ?? "") === "added"): ?><div class="form-message success" data-i18n="satisfactionQuestionAddedMessage">Soru eklendi.</div><?php endif; ?>
        <?php if (($_GET["q"] ?? "") === "removed"): ?><div class="form-message success" data-i18n="satisfactionQuestionRemovedMessage">Soru kaldırıldı.</div><?php endif; ?>
        <?php if (($_GET["response"] ?? "") === "recorded"): ?><div class="form-message success" data-i18n="satisfactionResponseRecordedMessage">Müşteri yanıtı kaydedildi.</div><?php endif; ?>

        <div class="two-col">
            <section class="form-panel">
                <div class="section-heading compact-heading">
                    <div>
                        <h3 data-i18n="satisfactionSurveyInfoTitle">Anket Bilgileri</h3>
                        <p data-i18n="satisfactionSurveyInfoText">Anket adını ve açıklamasını düzenleyin.</p>
                    </div>
                </div>
                <form class="auditor-form" method="post" action="satisfaction-survey-detail.php?id=<?= $surveyId ?>">
                    <?= qmsCsrfField($csrfScope) ?>
                    <input type="hidden" name="form_type" value="update_survey">
                    <div class="form-grid">
                        <label class="form-field">
                            <span data-i18n="satisfactionSurveyTitleLabel">Anket Başlığı</span>
                            <input type="text" name="title" value="<?= htmlspecialchars($survey["title"], ENT_QUOTES, "UTF-8") ?>" required maxlength="255">
                        </label>
                        <label class="form-field form-field-wide">
                            <span data-i18n="satisfactionSurveyDescriptionLabel">Açıklama</span>
                            <textarea name="description" rows="3"><?= htmlspecialchars((string) ($survey["description"] ?? ""), ENT_QUOTES, "UTF-8") ?></textarea>
                        </label>
                    </div>
                    <div class="form-actions">
                        <button class="primary-button" type="submit" data-i18n="saveSatisfactionSurveyButton">Anketi Kaydet</button>
                    </div>
                </form>
            </section>

            <section class="console-card checklist-section">
                <div class="section-heading compact-heading">
                    <div>
                        <h3 data-i18n="satisfactionQuestionsTitle">Soru Seti</h3>
                        <p><span data-i18n="satisfactionQuestionsCountLabel">Toplam soru</span>: <strong><?= count($questions) ?></strong></p>
                    </div>
                </div>

                <form class="auditor-form" method="post" action="satisfaction-survey-detail.php?id=<?= $surveyId ?>">
                    <?= qmsCsrfField($csrfScope) ?>
                    <input type="hidden" name="form_type" value="add_question">
                    <div class="form-grid">
                        <label class="form-field">
                            <span data-i18n="satisfactionQuestionTextLabel">Soru Metni</span>
                            <input type="text" name="question_text" required maxlength="255">
                        </label>
                    </div>
                    <div class="form-actions">
                        <button class="secondary-button" type="submit" data-i18n="addSatisfactionQuestionButton">Soru Ekle</button>
                    </div>
                </form>

                <div class="admin-list">
                    <?php if (!$questions): ?>
                        <div class="empty-state" data-i18n="noSatisfactionQuestionsText">Bu ankette henüz soru yok.</div>
                    <?php endif; ?>
                    <?php foreach ($questions as $question): ?>
                        <div class="admin-list-item">
                            <div class="list-item-main">
                                <strong><?= htmlspecialchars($question["question_text"], ENT_QUOTES, "UTF-8") ?></strong>
                            </div>
                            <div class="list-item-side">
                                <form method="post" action="satisfaction-survey-detail.php?id=<?= $surveyId ?>" onsubmit="return confirm('Soruyu kaldırsın mı?');">
                                    <?= qmsCsrfField($csrfScope) ?>
                                    <input type="hidden" name="form_type" value="remove_question">
                                    <input type="hidden" name="question_id" value="<?= (int) $question["id"] ?>">
                                    <button class="danger-button danger-button-sm" type="submit" data-i18n="removeSatisfactionQuestionButton">Kaldır</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>

        <section class="console-card checklist-section">
            <div class="section-heading compact-heading">
                <div>
                    <h3 data-i18n="satisfactionRecordResponseTitle">Müşteri Yanıtı Gir</h3>
                    <p data-i18n="satisfactionRecordResponseText">Müşteri adına anketi 1-5 olarak doldurun; puanlama otomatik ortalanır.</p>
                </div>
            </div>
            <?php if (!$questions): ?>
                <div class="empty-state" data-i18n="satisfactionRecordNeedQuestionsText">Yanıt girmek için önce soru ekleyin.</div>
            <?php else: ?>
            <form class="auditor-form" method="post" action="satisfaction-survey-detail.php?id=<?= $surveyId ?>">
                <?= qmsCsrfField($csrfScope) ?>
                <input type="hidden" name="form_type" value="record_response">
                <div class="form-grid">
                    <label class="form-field">
                        <span data-i18n="satisfactionCustomerNameLabel">Müşteri Adı</span>
                        <input type="text" name="customer_name" maxlength="180">
                    </label>
                    <label class="form-field">
                        <span data-i18n="satisfactionCustomerContactLabel">İletişim Bilgisi</span>
                        <input type="text" name="customer_contact" maxlength="180">
                    </label>
                    <label class="form-field">
                        <span data-i18n="satisfactionResponseDateLabel">Yanıt Tarihi</span>
                        <input type="date" name="responded_at" value="<?= date("Y-m-d") ?>" required>
                    </label>
                </div>

                <?php foreach ($questions as $i => $question): ?>
                    <div class="rating-row">
                        <span><?= htmlspecialchars($question["question_text"], ENT_QUOTES, "UTF-8") ?></span>
                        <div class="rating-inputs">
                            <?php for ($r = 1; $r <= 5; $r++): ?>
                                <label class="rating-option">
                                    <input type="radio" name="answer[<?= (int) $question["id"] ?>]" value="<?= $r ?>" <?= ($i === 0 && $r === 5) ? "checked" : "" ?>> <span><?= $r ?></span>
                                </label>
                            <?php endfor; ?>
                        </div>
                    </div>
                <?php endforeach; ?>

                <label class="form-field form-field-wide">
                    <span data-i18n="satisfactionCommentLabel">Yorum</span>
                    <textarea name="comment" rows="3" maxlength="4000"></textarea>
                </label>
                <div class="form-actions">
                    <button class="primary-button" type="submit" data-i18n="saveSatisfactionResponseButton">Yanıtı Kaydet</button>
                </div>
            </form>
            <?php endif; ?>
        </section>

        <section class="console-card">
            <div class="section-heading compact-heading">
                <div>
                    <h3 data-i18n="satisfactionResponsesTitle">Yanıtlar</h3>
                    <p data-i18n="satisfactionResponsesText">Kaydedilen müşteri yanıtları.</p>
                </div>
            </div>
            <div class="admin-list">
                <?php if (!$responses): ?>
                    <div class="empty-state" data-i18n="noSatisfactionResponsesText">Henüz müşteri yanıtı kaydedilmedi.</div>
                <?php endif; ?>
                <?php foreach ($responses as $response): ?>
                    <div class="admin-list-item">
                        <div class="list-item-main">
                            <strong><?= htmlspecialchars($response["customer_name"] ?: "-", ENT_QUOTES, "UTF-8") ?></strong>
                            <span><?= htmlspecialchars($response["responded_at"], ENT_QUOTES, "UTF-8") ?> · <?= htmlspecialchars($response["comment"] ?: "-", ENT_QUOTES, "UTF-8") ?></span>
                        </div>
                        <div class="list-item-side">
                            <span class="status-pill"><?= (int) $response["overall_score"] ?>/5</span>
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
