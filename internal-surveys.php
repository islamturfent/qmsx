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
$role = qmsCurrentRole();
if (qmsIsAuditor()) {
    header("Location: my-audits.php");
    exit;
}

$scope = qmsCompanyScope('companies.id', qmsVisibleCompanyIds($pdo, $userId, $role));
$companyStmt = $pdo->prepare('SELECT companies.id, companies.company_name FROM companies WHERE companies.active = 1' . $scope['sql'] . ' ORDER BY companies.company_name');
$companyStmt->execute($scope['params']);
$companies = $companyStmt->fetchAll(PDO::FETCH_ASSOC);

$csrfScope = 'internal_surveys';
$formError = '';
$editing = null;
$editId = (int) ($_GET['edit'] ?? 0);
if ($editId > 0) {
    $editing = qmsInternalSurveyFind($pdo, $editId, $userId, $role);
    if (!$editing) {
        $editId = 0;
    }
}
$manageId = (int) ($_GET['manage'] ?? 0);
$manageSurvey = $manageId > 0 ? qmsInternalSurveyFind($pdo, $manageId, $userId, $role) : [];
if ($manageId > 0 && !$manageSurvey) {
    $manageId = 0;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    qmsCsrfVerify($csrfScope, $_POST["csrf"] ?? null);
    $formType = (string) ($_POST["form_type"] ?? "");

    if ($formType === "add" || $formType === "update") {
        $companyId = (int) ($_POST["company_id"] ?? 0);
        if ($formType === "add") {
            $newId = qmsInternalSurveyAdd($pdo, [
                "company_id" => $companyId,
                "title" => (string) ($_POST["title"] ?? ""),
                "description" => (string) ($_POST["description"] ?? ""),
                "published" => isset($_POST["published"]),
            ], $userId, $role);
            if ($newId !== null) {
                if (isset($_POST["published"])) {
                    qmsInternalSurveyNotifyCompany($pdo, $companyId, $newId, (string) ($_POST["title"] ?? ""));
                }
                header("Location: internal-surveys.php?manage=" . $newId . "&added=1");
                exit;
            }
            $formError = "Anket eklenemedi. Geçerli bir şirket ve başlık girin.";
        } else {
            $updateId = (int) ($_POST["id"] ?? 0);
            $prior = qmsInternalSurveyFind($pdo, $updateId, $userId, $role);
            $wasPublished = !empty($prior) && (int) $prior['published'] === 1;
            $ok = qmsInternalSurveyUpdate($pdo, $updateId, [
                "title" => (string) ($_POST["title"] ?? ""),
                "description" => (string) ($_POST["description"] ?? ""),
                "published" => isset($_POST["published"]),
            ], $userId, $role);
            if ($ok) {
                if (isset($_POST["published"]) && !$wasPublished) {
                    qmsInternalSurveyNotifyCompany($pdo, (int) ($prior['company_id'] ?? 0), $updateId, (string) ($_POST["title"] ?? ""));
                }
                header("Location: internal-surveys.php?updated=1");
                exit;
            }
            $formError = "Anket güncellenemedi.";
        }
    } elseif ($formType === "delete") {
        qmsInternalSurveyDelete($pdo, (int) ($_POST["id"] ?? 0), $userId, $role);
        header("Location: internal-surveys.php?deleted=1");
        exit;
    } elseif ($formType === "question_add") {
        $target = (int) ($_POST["survey_id"] ?? 0);
        qmsInternalSurveyAddQuestion($pdo, $target, [
            "question_text" => (string) ($_POST["question_text"] ?? ""),
            "question_type" => (string) ($_POST["question_type"] ?? "rating"),
        ], $userId, $role);
        header("Location: internal-surveys.php?manage=" . $target . "&qadded=1");
        exit;
    } elseif ($formType === "question_delete") {
        $target = (int) ($_POST["survey_id"] ?? 0);
        qmsInternalSurveyDeleteQuestion($pdo, (int) ($_POST["question_id"] ?? 0), $userId, $role);
        header("Location: internal-surveys.php?manage=" . $target);
        exit;
    }
}

$surveys = qmsInternalSurveyList($pdo, $userId, $role);
$activeNav = "internal_surveys";
$selectedCompanyId = (int) ($_GET["company_id"] ?? 0);
$prefill = $editing ?: ['title' => '', 'description' => '', 'published' => false];
if ($editing) {
    $selectedCompanyId = (int) $editing['company_id'];
}
$manageQuestions = [];
$manageResults = [];
if ($manageId > 0) {
    $manageQuestions = qmsInternalSurveyQuestions($pdo, $manageId);
    $manageResults = qmsInternalSurveyResults($pdo, $manageId);
}

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="theme-color" content="#f9fafb">
    <title>QMS İç Memnuniyet Anketi</title><link rel="manifest" href="manifest.webmanifest"><link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml"><link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar"><div class="topbar-inner"><div class="page-title-block"><strong data-i18n="internalSurveyTitle">İç Memnuniyet Anketi</strong><span data-i18n="internalSurveyText">Çalışan memnuniyeti anketlerini tasarlayın ve sonuçları izleyin.</span></div><div class="topbar-actions"><button class="topbar-button" id="languageToggle" type="button">EN</button><button class="topbar-button" id="themeToggle" type="button" aria-label="Tema değiştir">🌙</button></div></div></header>
    <main class="page-container">
        <?php if ($manageId > 0): ?>
        <section class="page-heading page-heading-actions">
            <div>
                <span class="section-kicker" data-i18n="internalSurveyKicker">İnsan Kaynakları</span>
                <h1><?= htmlspecialchars($manageSurvey['title'], ENT_QUOTES, 'UTF-8') ?></h1>
                <p><span data-i18n="internalSurveyRespondentsLabel">Katılımcı</span>: <strong><?= (int) $manageSurvey['respond_count'] ?></strong><?= $manageSurvey['avg_rating'] !== null ? ' · ortalama ' . htmlspecialchars((string) $manageSurvey['avg_rating'], ENT_QUOTES, 'UTF-8') . '/5' : '' ?></p>
            </div>
            <a class="secondary-button" href="internal-surveys.php" data-i18n="internalSurveyBack">Anketlere Dön</a>
        </section>
        <?php else: ?>
        <section class="page-heading">
            <div>
                <span class="section-kicker" data-i18n="internalSurveyKicker">İnsan Kaynakları</span>
                <h1 data-i18n="internalSurveyTitle">İç Memnuniyet Anketi</h1>
                <p data-i18n="internalSurveyText">Şirket içi anketler oluşturun, soru setlerini yönetin ve yanıt ortalamalarını görüntüleyin.</p>
            </div>
        </section>
        <?php endif; ?>

        <?php if ($formError !== ""): ?><div class="form-message error"><?= htmlspecialchars($formError, ENT_QUOTES, "UTF-8") ?></div><?php endif; ?>
        <?php if (($_GET["added"] ?? "") === "1"): ?><div class="form-message success" data-i18n="internalSurveyAdded">Anket eklendi. Şimdi soruları tanımlayın.</div><?php endif; ?>
        <?php if (($_GET["updated"] ?? "") === "1"): ?><div class="form-message success" data-i18n="internalSurveyUpdated">Anket güncellendi.</div><?php endif; ?>
        <?php if (($_GET["deleted"] ?? "") === "1"): ?><div class="form-message success" data-i18n="internalSurveyDeleted">Anket silindi.</div><?php endif; ?>
        <?php if (($_GET["qadded"] ?? "") === "1"): ?><div class="form-message success" data-i18n="internalSurveyQuestionAdded">Soru eklendi.</div><?php endif; ?>

        <?php if ($manageId > 0): ?>
            <div class="two-col">
                <section class="console-card checklist-section">
                    <div class="section-heading compact-heading"><div><h3 data-i18n="internalSurveyDetailTitle">Anket Detayı</h3><p>#<?= (int) $manageId ?> · <?= htmlspecialchars($manageSurvey['title'], ENT_QUOTES, 'UTF-8') ?></p></div><div><?php if ((int) $manageSurvey['published'] === 1): ?><span class="status-badge status-pill" data-i18n="internalSurveyPublishedBadge">Yayında</span><?php else: ?><span class="status-badge" data-i18n="internalSurveyDraftBadge">Taslak</span><?php endif; ?></div></div>
                    <p class="muted-block"><?= htmlspecialchars((string) ($manageSurvey['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>

                    <div class="section-heading compact-heading"><div><h3 data-i18n="internalSurveyQuestionsTitle">Sorular</h3><p><span data-i18n="filteredRecordsLabel">Soru</span>: <strong><?= count($manageQuestions) ?></strong></p></div></div>
                    <form class="auditor-form" method="post" action="internal-surveys.php?manage=<?= (int) $manageId ?>">
                        <?= qmsCsrfField($csrfScope) ?>
                        <input type="hidden" name="form_type" value="question_add">
                        <input type="hidden" name="survey_id" value="<?= (int) $manageId ?>">
                        <div class="form-grid">
                            <label class="form-field"><span data-i18n="internalSurveyQuestionLabel">Soru</span><input type="text" name="question_text" required maxlength="500"></label>
                            <label class="form-field"><span data-i18n="internalSurveyTypeLabel">Tür</span><select name="question_type"><option value="rating" data-i18n="internalSurveyTypeRating">Değerlendirme (1-5)</option><option value="text" data-i18n="internalSurveyTypeText">Serbest metin</option></select></label>
                        </div>
                        <div class="form-actions"><button class="primary-button" type="submit" data-i18n="internalSurveyAddQuestion">Soru Ekle</button></div>
                    </form>
                    <div class="admin-list">
                        <?php if (!$manageQuestions): ?><div class="empty-state" data-i18n="internalSurveyNoQuestions">Henüz soru eklenmedi.</div><?php endif; ?>
                        <?php foreach ($manageQuestions as $q): ?>
                            <div class="admin-list-item">
                                <div class="list-item-main">
                                    <strong><?= htmlspecialchars($q['question_text'], ENT_QUOTES, 'UTF-8') ?></strong>
                                    <span><?= $q['question_type'] === 'rating' ? 'Değerlendirme (1-5)' : 'Serbest metin' ?> · #<?= (int) $q['sort_order'] ?></span>
                                </div>
                                <div class="list-item-side">
                                    <form method="post" action="internal-surveys.php?manage=<?= (int) $manageId ?>" onsubmit="return confirm('Soru silinsin mi? Yanıtları da kaldırılır.');"><?= qmsCsrfField($csrfScope) ?><input type="hidden" name="form_type" value="question_delete"><input type="hidden" name="survey_id" value="<?= (int) $manageId ?>"><input type="hidden" name="question_id" value="<?= (int) $q['id'] ?>"><button class="danger-button danger-button-sm" type="submit" data-i18n="internalSurveyDeleteQuestion">Sil</button></form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>

                <section class="console-card checklist-section">
                    <div class="section-heading compact-heading"><div><h3 data-i18n="internalSurveyResultsTitle">Sonuçlar</h3><p><span data-i18n="internalSurveyRespondentsLabel">Katılımcı</span>: <strong><?= (int) $manageSurvey['respond_count'] ?></strong></p></div></div>
                    <div class="admin-list">
                        <?php if (!$manageResults): ?><div class="empty-state" data-i18n="internalSurveyNoResults">Henüz yanıt yok.</div><?php endif; ?>
                        <?php foreach ($manageResults as $r): ?>
                            <div class="admin-list-item">
                                <div class="list-item-main">
                                    <strong><?= htmlspecialchars($r['question_text'], ENT_QUOTES, 'UTF-8') ?></strong>
                                    <span><?= (int) $r['answer_count'] ?> yanıt<?= $r['question_type'] === 'rating' ? ' · ortalama ' . htmlspecialchars((string) $r['avg_rating'], ENT_QUOTES, 'UTF-8') . '/5' : '' ?></span>
                                </div>
                                <?php if ($r['question_type'] === 'rating' && $r['avg_rating'] !== null): ?>
                                <div class="list-item-side"><div class="rating-bar"><div class="rating-bar-fill" style="width:<?= min(100, (float) $r['avg_rating'] * 20) ?>%"></div></div></div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            </div>
        <?php else: ?>

        <div class="two-col">
            <section class="form-panel">
                <div class="section-heading compact-heading"><div><h3><?= $editing ? 'Anketi Düzenle' : 'Yeni Anket' ?></h3><?php if ($editing): ?><p>#<?= (int) $editing['id'] ?> düzenleniyor</p><?php endif; ?></div></div>
                <?php if (!$companies): ?><div class="form-message error" data-i18n="internalSurveyNoCompany">Önce bir şirket gerekir.</div>
                <?php else: ?>
                <form class="auditor-form" method="post" action="internal-surveys.php<?= $editing ? '?edit=' . (int) $editing['id'] : '' ?>">
                    <?= qmsCsrfField($csrfScope) ?>
                    <input type="hidden" name="form_type" value="<?= $editing ? 'update' : 'add' ?>">
                    <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>
                    <div class="form-grid">
                        <label class="form-field"><span data-i18n="companySelectLabel">Şirket</span><select name="company_id" <?= $editing ? 'disabled' : 'required' ?>><option value="0" data-i18n="selectCompanyOption">Şirket seçin</option><?php foreach ($companies as $company): ?><option value="<?= (int) $company['id'] ?>" <?= $selectedCompanyId === (int) $company['id'] ? 'selected' : '' ?>><?= htmlspecialchars($company['company_name'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></label>
                        <label class="form-field"><span data-i18n="internalSurveyTitleLabel">Başlık</span><input type="text" name="title" required maxlength="190" value="<?= htmlspecialchars((string) $prefill['title'], ENT_QUOTES, 'UTF-8') ?>"></label>
                        <label class="form-field form-field-wide"><span data-i18n="internalSurveyDescriptionLabel">Açıklama</span><textarea name="description" rows="4"><?= htmlspecialchars((string) ($prefill['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea></label>
                        <label class="form-field form-field-wide checkbox-field"><input type="checkbox" name="published" value="1" <?= $prefill['published'] ? 'checked' : '' ?>> <span data-i18n="internalSurveyPublishLabel">Yayında (kullanıcılar doldurabilir)</span></label>
                    </div>
                    <div class="form-actions"><button class="primary-button" type="submit" data-i18n="internalSurveySave">Kaydet</button><?php if ($editing): ?><a class="secondary-button" href="internal-surveys.php" data-i18n="internalSurveyCancel">İptal</a><?php endif; ?></div>
                </form>
                <?php endif; ?>
            </section>

            <section class="console-card checklist-section">
                <div class="section-heading compact-heading"><div><h3 data-i18n="internalSurveyListTitle">Anketler</h3><p><span data-i18n="filteredRecordsLabel">Gösterilen kayıt</span>: <strong><?= count($surveys) ?></strong></p></div></div>
                <div class="admin-list">
                    <?php if (!$surveys): ?><div class="empty-state" data-i18n="internalSurveyEmpty">Henüz anket yok.</div><?php endif; ?>
                    <?php foreach ($surveys as $s): ?>
                        <div class="admin-list-item">
                            <div class="list-item-main">
                                <strong><?= htmlspecialchars($s['title'], ENT_QUOTES, 'UTF-8') ?></strong>
                                <span>
                                    <?php if ((int) $s['published'] === 1): ?><span class="status-badge status-pill" data-i18n="internalSurveyPublishedBadge">Yayında</span><?php else: ?><span class="status-badge" data-i18n="internalSurveyDraftBadge">Taslak</span><?php endif; ?>
                                    · <?= (int) $s['respond_count'] ?> katılımcı<?= $s['avg_rating'] !== null ? ' · ortalama ' . htmlspecialchars((string) $s['avg_rating'], ENT_QUOTES, 'UTF-8') . '/5' : '' ?>
                                </span>
                            </div>
                            <div class="list-item-side">
                                <a class="secondary-button secondary-button-sm" href="internal-surveys.php?manage=<?= (int) $s['id'] ?>" data-i18n="internalSurveyManage">Yönet</a>
                                <a class="secondary-button secondary-button-sm" href="internal-surveys.php?edit=<?= (int) $s['id'] ?>" data-i18n="editButton">Düzenle</a>
                                <form method="post" action="internal-surveys.php" onsubmit="return confirm('Anket silinsin mi?');"><?= qmsCsrfField($csrfScope) ?><input type="hidden" name="form_type" value="delete"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>"><button class="danger-button danger-button-sm" type="submit" data-i18n="internalSurveyDelete">Sil</button></form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>
        <?php endif; ?>
    </main>
    <script src="assets/js/theme.js"></script><script src="assets/js/language.js"></script><script src="assets/js/sidebar.js"></script><script src="assets/js/pwa.js"></script>
</body>
</html>
