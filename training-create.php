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

$userId = (int) ($_SESSION["qms_user_id"] ?? 0);
$csrfScope = 'training_create';

// Sirket listesi kapsamdan gelir: super admin tum sirketler, digerleri
// yalnizca gorunur sirketler.
$companyScope = qmsCompanyScope('companies.id', qmsVisibleCompanyIds($pdo, $userId, qmsCurrentRole()));
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
    "category" => "",
    "provider" => "",
    "trainer_name" => "",
    "planned_date" => "",
    "duration_hours" => "",
    "description" => "",
    "template_id" => 0,
    "target_competency" => ""
];

// Şablondan türetme için görünür şirketlerin eğitim şablonları.
$templates = [];
if ($allowedCompanyIds) {
    $tmpIn = implode(',', array_map('intval', $allowedCompanyIds));
    $templateStmt = $pdo->query(
        'SELECT id, company_id, title, category, default_duration_hours, target_competency
         FROM training_templates WHERE active = 1 AND company_id IN (' . $tmpIn . ') ORDER BY title ASC'
    );
    $templates = $templateStmt->fetchAll(PDO::FETCH_ASSOC);
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    qmsCsrfVerify($csrfScope, $_POST["csrf"] ?? null);

    $formData = [
        "company_id" => (int) ($_POST["company_id"] ?? 0),
        "title" => qmsTrainingText($_POST["title"] ?? "", 255),
        "category" => qmsTrainingText($_POST["category"] ?? "", 100),
        "provider" => qmsTrainingText($_POST["provider"] ?? "", 180),
        "trainer_name" => qmsTrainingText($_POST["trainer_name"] ?? "", 150),
        "planned_date" => trim((string) ($_POST["planned_date"] ?? "")),
        "duration_hours" => trim((string) ($_POST["duration_hours"] ?? "")),
        "description" => qmsTrainingText($_POST["description"] ?? "", 4000),
        "template_id" => (int) ($_POST["template_id"] ?? 0),
        "target_competency" => qmsTrainingText($_POST["target_competency"] ?? "", 120)
    ];

    $plannedDate = qmsTrainingDate($formData["planned_date"]);
    $durationHours = qmsTrainingDuration($formData["duration_hours"]);

    if ($formData["title"] === "") {
        $formError = "Lütfen eğitim başlığını girin.";
    } elseif (!in_array($formData["company_id"], $allowedCompanyIds, true)) {
        $formError = "Geçerli bir şirket seçin.";
    } elseif ($formData["planned_date"] !== "" && $plannedDate === null) {
        $formError = "Planlanan tarih geçerli değil.";
    } elseif ($formData["duration_hours"] !== "" && $durationHours === null) {
        $formError = "Süre 0 ile 1000 saat arasında bir sayı olmalıdır.";
    } else {
        // Seçilen şablon bu şirkete ait mi (tenant güvenliği).
        $template_id = null;
        $templateCompetency = $formData["target_competency"];
        if ($formData["template_id"] > 0) {
            $tplCheck = $pdo->prepare('SELECT id, target_competency FROM training_templates WHERE id = ? AND company_id = ? AND active = 1');
            $tplCheck->execute([$formData["template_id"], $formData["company_id"]]);
            $tpl = $tplCheck->fetch(PDO::FETCH_ASSOC);
            if ($tpl) {
                $template_id = (int) $tpl['id'];
                if ($templateCompetency === '') { $templateCompetency = (string) $tpl['target_competency']; }
            }
        }

        $insertStmt = $pdo->prepare(
            "INSERT INTO trainings
                (company_id, title, category, provider, trainer_name, planned_date,
                 duration_hours, status, description, created_by, updated_by, active,
                 template_id, target_competency)
             VALUES
                (:company_id, :title, :category, :provider, :trainer_name, :planned_date,
                 :duration_hours, 'planned', :description, :created_by, :updated_by, 1,
                 :template_id, :target_competency)"
        );
        $insertStmt->execute([
            "company_id" => $formData["company_id"],
            "title" => $formData["title"],
            "category" => $formData["category"] !== "" ? $formData["category"] : null,
            "provider" => $formData["provider"] !== "" ? $formData["provider"] : null,
            "trainer_name" => $formData["trainer_name"] !== "" ? $formData["trainer_name"] : null,
            "planned_date" => $plannedDate,
            "duration_hours" => $durationHours,
            "description" => $formData["description"] !== "" ? $formData["description"] : null,
            "created_by" => $userId ?: null,
            "updated_by" => $userId ?: null,
            "template_id" => $template_id,
            "target_competency" => $templateCompetency
        ]);

        $trainingId = (int) $pdo->lastInsertId();

        // Sirketin atanmis sistem adminleri yeni plandan haberdar edilir.
        qmsNotifyCompanyAdmins(
            $pdo,
            $formData["company_id"],
            "training_planned",
            "Yeni eğitim planlandı",
            $formData["title"],
            "training-detail.php?id=" . $trainingId,
            $userId
        );

        header("Location: training-detail.php?id=" . $trainingId . "&created=1");
        exit;
    }
}

$activeNav = "trainings";

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f9fafb">
    <title>QuAmi Yeni Eğitim</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar">
        <div class="topbar-inner">
            <div class="page-title-block">
                <strong data-i18n="newTrainingTitle">Yeni Eğitim</strong>
                <span data-i18n="trainingManagementText">Eğitimleri planlayın, katılımcıları ve tamamlanma durumunu izleyin.</span>
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
                <span class="section-kicker" data-i18n="trainingRegisterKicker">Eğitim Kayıtları</span>
                <h1 data-i18n="newTrainingTitle">Yeni Eğitim</h1>
                <p data-i18n="newTrainingText">Eğitimi planlayın; katılımcıları kaydı oluşturduktan sonra ekleyin.</p>
            </div>
            <a class="secondary-button" href="trainings.php" data-i18n="backToTrainingsButton">Eğitimlere Dön</a>
        </section>
        <section class="form-panel">
            <?php if ($formError !== ""): ?>
                <div class="form-message error"><?= htmlspecialchars($formError, ENT_QUOTES, "UTF-8") ?></div>
            <?php endif; ?>
            <?php if (!$companies): ?>
                <div class="form-message error" data-i18n="trainingNoCompanyText">Eğitim eklemek için önce bir şirket gerekir. Şirket kaydınız yoksa yöneticinizle görüşün.</div>
            <?php else: ?>
            <form class="auditor-form" method="post" action="training-create.php">
                <?= qmsCsrfField($csrfScope) ?>
                <div class="form-grid">
                    <?php if ($templates): ?>
                    <label class="form-field">
                        <span data-i18n="trainingTemplatePrefillLabel">Şablondan Doldur (isteğe bağlı)</span>
                        <select name="template_id" id="trainingTemplateSelect">
                            <option value="0" data-i18n="trainingTemplateNoneOption">— Şablon seçilmedi —</option>
                            <?php foreach ($templates as $tpl): ?>
                                <option value="<?= (int) $tpl['id'] ?>"
                                    data-company="<?= (int) $tpl['company_id'] ?>"
                                    data-title="<?= htmlspecialchars($tpl['title'], ENT_QUOTES, 'UTF-8') ?>"
                                    data-category="<?= htmlspecialchars($tpl['category'], ENT_QUOTES, 'UTF-8') ?>"
                                    data-duration="<?= htmlspecialchars((string) $tpl['default_duration_hours'], ENT_QUOTES, 'UTF-8') ?>"
                                    data-competency="<?= htmlspecialchars($tpl['target_competency'], ENT_QUOTES, 'UTF-8') ?>"
                                    data-company-set="<?= (int) $tpl['company_id'] ?>"><?= htmlspecialchars($tpl['title'], ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <?php endif; ?>
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
                        <span data-i18n="trainingTitleLabel">Eğitim Başlığı</span>
                        <input type="text" name="title" maxlength="255" value="<?= htmlspecialchars($formData["title"], ENT_QUOTES, "UTF-8") ?>" required>
                    </label>
                    <label class="form-field">
                        <span data-i18n="trainingCategoryLabel">Kategori</span>
                        <input type="text" name="category" maxlength="100" value="<?= htmlspecialchars($formData["category"], ENT_QUOTES, "UTF-8") ?>" placeholder="ISO 9001, İSG, KVKK…">
                    </label>
                    <label class="form-field">
                        <span data-i18n="trainingProviderLabel">Eğitim Sağlayıcı</span>
                        <input type="text" name="provider" maxlength="180" value="<?= htmlspecialchars($formData["provider"], ENT_QUOTES, "UTF-8") ?>">
                    </label>
                    <label class="form-field">
                        <span data-i18n="trainingTrainerLabel">Eğitmen</span>
                        <input type="text" name="trainer_name" maxlength="150" value="<?= htmlspecialchars($formData["trainer_name"], ENT_QUOTES, "UTF-8") ?>">
                    </label>
                    <label class="form-field">
                        <span data-i18n="plannedDateLabel">Planlanan Tarih</span>
                        <input type="date" name="planned_date" value="<?= htmlspecialchars($formData["planned_date"], ENT_QUOTES, "UTF-8") ?>">
                    </label>
                    <label class="form-field">
                        <span data-i18n="trainingDurationLabel">Süre (saat)</span>
                        <input type="number" name="duration_hours" min="0" max="1000" step="0.5" value="<?= htmlspecialchars($formData["duration_hours"], ENT_QUOTES, "UTF-8") ?>">
                    </label>
                    <label class="form-field">
                        <span data-i18n="trainingCompetencyLabel">Hedef Yetkinlik</span>
                        <input type="text" name="target_competency" id="trainingCompetency" maxlength="120" value="<?= htmlspecialchars($formData["target_competency"], ENT_QUOTES, "UTF-8") ?>">
                    </label>
                    <label class="form-field form-field-wide">
                        <span data-i18n="trainingDescriptionLabel">Açıklama</span>
                        <textarea name="description" rows="5"><?= htmlspecialchars($formData["description"], ENT_QUOTES, "UTF-8") ?></textarea>
                    </label>
                </div>
                <div class="form-actions">
                    <button class="primary-button" type="submit" data-i18n="saveTrainingButton">Eğitimi Kaydet</button>
                    <a class="secondary-button" href="trainings.php" data-i18n="backToTrainingsButton">Eğitimlere Dön</a>
                </div>
            </form>
            <?php endif; ?>
        </section>
    </main>
    <script src="assets/js/theme.js"></script>
    <script src="assets/js/language.js"></script>
    <script src="assets/js/sidebar.js"></script>
    <script src="assets/js/pwa.js"></script>
    <script>
    /* Şablondan eğitim alanlarini doldur (gorsel; is mantigini degistirmez). */
    (function () {
        var sel = document.getElementById('trainingTemplateSelect');
        if (!sel) { return; }
        var company = document.querySelector('select[name="company_id"]');
        var f = function (name) { return document.querySelector('input[name="' + name + '"]'); };
        var title = f('title'), category = f('category'), dur = f('duration_hours'), comp = document.getElementById('trainingCompetency');
        sel.addEventListener('change', function () {
            var opt = sel.selectedOptions && sel.selectedOptions[0];
            if (!opt || !opt.value || opt.value === '0') { return; }
            if (company && opt.getAttribute('data-company-set')) { company.value = opt.getAttribute('data-company-set'); }
            if (title && opt.getAttribute('data-title')) { title.value = opt.getAttribute('data-title'); }
            if (category && opt.getAttribute('data-category')) { category.value = opt.getAttribute('data-category'); }
            if (dur && opt.getAttribute('data-duration') && opt.getAttribute('data-duration') !== '0') { dur.value = opt.getAttribute('data-duration'); }
            if (comp && opt.getAttribute('data-competency')) { comp.value = opt.getAttribute('data-competency'); }
        });
    })();
    </script>
</body>
</html>
