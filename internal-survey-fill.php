<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/access.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/internal-survey-functions.php';

$userId = (int) ($_SESSION["qms_user_id"] ?? 0);
if ($userId <= 0) {
    header("Location: login.php");
    exit;
}
$role = qmsCurrentRole();
if (qmsIsAuditor()) {
    header("Location: my-audits.php");
    exit;
}

$csrfScope = 'internal_survey_fill';
$message = '';
$error = '';

$fillId = (int) ($_GET['fill'] ?? 0);
$fillSurvey = [];
$fillQuestions = [];
if ($fillId > 0) {
    $fillSurvey = qmsInternalSurveyFind($pdo, $fillId, $userId, $role);
    if ($fillSurvey && (int) $fillSurvey['published'] === 1 && !qmsInternalSurveyHasResponded($pdo, $fillId, $userId)) {
        $fillQuestions = qmsInternalSurveyQuestions($pdo, $fillId);
    } else {
        $fillSurvey = [];
        $fillId = 0;
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    qmsCsrfVerify('internal_survey_fill', $_POST["csrf"] ?? null);
    $surveyId = (int) ($_POST["survey_id"] ?? 0);
    $responses = [];
    foreach ($_POST as $key => $val) {
        if (strpos((string) $key, 'q_') === 0) {
            $qid = (int) substr((string) $key, 2);
            $responses[$qid] = $val;
        }
    }
    if ($surveyId > 0 && qmsInternalSurveySubmit($pdo, $surveyId, $responses, $userId, $role)) {
        header("Location: internal-survey-fill.php?done=" . $surveyId);
        exit;
    }
    $error = "Yanıtlarınız kaydedilemedi. Anket yayında olmayabilir veya daha önce doldurulmuş olabilir.";
}

$surveys = qmsInternalFillableSurveys($pdo, $userId, $role);
$activeNav = "internal_surveys";

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="theme-color" content="#f9fafb">
    <title>QMS İç Memnuniyet Anketi</title><link rel="manifest" href="manifest.webmanifest"><link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml"><link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar"><div class="topbar-inner"><div class="page-title-block"><strong data-i18n="internalSurveyFillTitle">Anketi Doldur</strong><span data-i18n="internalSurveyFillText">Yayındaki iç memnuniyet anketlerini doldurun.</span></div><div class="topbar-actions"><button class="topbar-button" id="languageToggle" type="button">EN</button><button class="topbar-button" id="themeToggle" type="button" aria-label="Tema değiştir">🌙</button></div></div></header>
    <main class="page-container">
        <section class="page-heading">
            <div>
                <span class="section-kicker" data-i18n="internalSurveyKicker">İnsan Kaynakları</span>
                <h1 data-i18n="internalSurveyFillTitle">Anketi Doldur</h1>
                <p data-i18n="internalSurveyFillText">Sizin için yayındaki anketleri listeleyin ve yanıtlarınızı iletin.</p>
            </div>
        </section>

        <?php if ($error !== ""): ?><div class="form-message error"><?= htmlspecialchars($error, ENT_QUOTES, "UTF-8") ?></div><?php endif; ?>
        <?php if (($doneId = (int) ($_GET['done'] ?? 0)) > 0): ?><div class="form-message success" data-i18n="internalSurveySubmitted">Yanıtlarınız kaydedildi. Teşekkür ederiz!</div><?php endif; ?>

        <?php if ($fillId > 0): ?>
            <div class="two-col">
                <section class="console-card checklist-section">
                    <div class="section-heading compact-heading"><div><h3 data-i18n="internalSurveyFillNowTitle">Yanıtla</h3><p><?= htmlspecialchars($fillSurvey['title'], ENT_QUOTES, 'UTF-8') ?></p></div></div>
                    <?php if (!$fillQuestions): ?><div class="empty-state" data-i18n="internalSurveyNoQuestions">Bu anketin henüz sorusu yok.</div>
                    <?php else: ?>
                    <form class="auditor-form" method="post" action="internal-survey-fill.php?fill=<?= (int) $fillId ?>">
                        <?= qmsCsrfField('internal_survey_fill') ?>
                        <input type="hidden" name="survey_id" value="<?= (int) $fillId ?>">
                        <div class="form-grid">
                            <?php foreach ($fillQuestions as $q): ?>
                                <?php if ($q['question_type'] === 'text'): ?>
                                <label class="form-field form-field-wide"><span><?= htmlspecialchars($q['question_text'], ENT_QUOTES, 'UTF-8') ?></span><textarea name="q_<?= (int) $q['id'] ?>" rows="3"></textarea></label>
                                <?php else: ?>
                                <label class="form-field"><span><?= htmlspecialchars($q['question_text'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <select name="q_<?= (int) $q['id'] ?>" required>
                                        <option value="" data-i18n="internalSurveySelectRating">— seçin —</option>
                                        <option value="1">1</option><option value="2">2</option><option value="3">3</option><option value="4">4</option><option value="5">5</option>
                                    </select>
                                </label>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                        <div class="form-actions"><button class="primary-button" type="submit" data-i18n="internalSurveySubmit">Yanıtları Gönder</button><a class="secondary-button" href="internal-survey-fill.php" data-i18n="internalSurveyCancel">İptal</a></div>
                    </form>
                    <?php endif; ?>
                </section>
            </div>
        <?php else: ?>
            <section class="console-card checklist-section">
                <div class="section-heading compact-heading"><div><h3 data-i18n="internalSurveyFillableListTitle">Doldurulacak Anketler</h3><p><span data-i18n="filteredRecordsLabel">Gösterilen kayıt</span>: <strong><?= count($surveys) ?></strong></p></div></div>
                <div class="admin-list">
                    <?php if (!$surveys): ?><div class="empty-state" data-i18n="internalSurveyFillEmpty">Sizin için doldurulacak yayındaki anket yok.</div><?php endif; ?>
                    <?php foreach ($surveys as $s): ?>
                        <div class="admin-list-item">
                            <div class="list-item-main">
                                <strong><?= htmlspecialchars($s['title'], ENT_QUOTES, 'UTF-8') ?></strong>
                                <span><?= (int) $s['question_count'] ?> soru<?php if ($s['description']): ?> · <?= htmlspecialchars(mb_substr((string) $s['description'], 0, 120), ENT_QUOTES, 'UTF-8') ?><?php endif; ?></span>
                            </div>
                            <div class="list-item-side">
                                <a class="secondary-button secondary-button-sm" href="internal-survey-fill.php?fill=<?= (int) $s['id'] ?>" data-i18n="internalSurveyStartFill">Doldur</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>
    </main>
    <script src="assets/js/theme.js"></script><script src="assets/js/language.js"></script><script src="assets/js/sidebar.js"></script><script src="assets/js/pwa.js"></script>
</body>
</html>
