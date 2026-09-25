<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/access.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/personnel-functions.php';

$staffId = (int) ($_GET["id"] ?? 0);
if ($staffId <= 0) {
    header("Location: personnel.php");
    exit;
}

$userId = (int) ($_SESSION["qms_user_id"] ?? 0);
$role = qmsCurrentRole();
$csrfScope = 'personnel_detail';

// Kapsamli okuma: id degistirilerek baska sirketin personeli acilamaz.
$staff = qmsPersonnelFind($pdo, $staffId, $userId, $role);
if (!$staff) {
    header("Location: personnel.php");
    exit;
}
$companyId = (int) $staff["company_id"];
$levelLabels = qmsPersonnelLevelLabels();
$statusLabels = qmsCompetencyStatusLabels();

$formError = "";
$formSuccess = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    qmsCsrfVerify($csrfScope, $_POST["csrf"] ?? null);
    $formType = (string) ($_POST["form_type"] ?? "");
    $redirect = "personnel-detail.php?id=" . $staffId;

    if ($formType === "update_personnel") {
        $first = trim((string) ($_POST["first_name"] ?? ""));
        $last = trim((string) ($_POST["last_name"] ?? ""));
        if ($first === "" || $last === "") {
            $formError = "Lütfen personel adını ve soyadını girin.";
        } else {
            $pdo->prepare(
                "UPDATE staff_members SET first_name=?, last_name=?, employee_code=?, department=?, position=?, email=?, phone=?, hired_date=? WHERE id=?"
            )->execute([
                mb_substr($first, 0, 80),
                mb_substr($last, 0, 80),
                trim((string) ($_POST["employee_code"] ?? "")) !== "" ? mb_substr(trim((string) $_POST["employee_code"]), 0, 60) : null,
                trim((string) ($_POST["department"] ?? "")) !== "" ? mb_substr(trim((string) $_POST["department"]), 0, 100) : null,
                trim((string) ($_POST["position"] ?? "")) !== "" ? mb_substr(trim((string) $_POST["position"]), 0, 120) : null,
                trim((string) ($_POST["email"] ?? "")) !== "" ? mb_substr(trim((string) $_POST["email"]), 0, 180) : null,
                trim((string) ($_POST["phone"] ?? "")) !== "" ? mb_substr(trim((string) $_POST["phone"]), 0, 60) : null,
                trim((string) ($_POST["hired_date"] ?? "")) !== "" ? trim((string) $_POST["hired_date"]) : null,
                $staffId,
            ]);
            $staff = qmsPersonnelFind($pdo, $staffId, $userId, $role);
            $formSuccess = "Personel güncellendi.";
        }
    } elseif ($formType === "add_competency") {
        $newId = qmsPersonnelAddCompetency($pdo, $staffId, [
            "competency_name" => (string) ($_POST["competency_name"] ?? ""),
            "level" => (int) ($_POST["level"] ?? 0),
            "achieved_date" => (string) ($_POST["achieved_date"] ?? ""),
            "next_assessment_date" => (string) ($_POST["next_assessment_date"] ?? ""),
            "notes" => (string) ($_POST["notes"] ?? ""),
        ], $userId, $role);

        if ($newId !== null) {
            header("Location: " . $redirect . "&c=added");
            exit;
        }
        $formError = "Yetkinlik eklenemedi. Geçerli bir ad ve 1-5 seviye girin.";
    } elseif ($formType === "remove_competency") {
        $competencyId = (int) ($_POST["competency_id"] ?? 0);
        if (qmsPersonnelRemoveCompetency($pdo, $staffId, $competencyId, $userId, $role)) {
            header("Location: " . $redirect . "&c=removed");
            exit;
        }
        $formError = "Yetkinlik kaldırılamadı.";
    } else {
        $formError = "Geçersiz istek.";
    }
}

$competencies = qmsPersonnelCompetencies($pdo, $staffId);
$activeNav = "personnel";

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f9fafb">
    <title>QMS Personel Detayı</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar">
        <div class="topbar-inner">
            <div class="page-title-block">
                <strong data-i18n="personnelDetailTitle">Personel Detayı</strong>
                <span><?= htmlspecialchars($staff["company_name"], ENT_QUOTES, "UTF-8") ?> · <?= htmlspecialchars($staff["first_name"] . ' ' . $staff["last_name"], ENT_QUOTES, "UTF-8") ?></span>
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
                <span class="section-kicker" data-i18n="personnelKicker">Kaynak Yönetimi</span>
                <h1 data-i18n="personnelDetailTitle">Personel Detayı</h1>
                <p><?= htmlspecialchars($staff["first_name"] . ' ' . $staff["last_name"], ENT_QUOTES, "UTF-8") ?> · <?= htmlspecialchars($staff["position"] ?: "-", ENT_QUOTES, "UTF-8") ?></p>
            </div>
            <a class="secondary-button" href="personnel.php" data-i18n="backToPersonnelButton">Personel Listesine Dön</a>
        </section>

        <section class="dashboard-grid compact-dashboard-grid">
            <div class="dashboard-card metric-blue">
                <?= appIcon("users", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="personnelDepartmentLabel">Bölüm</span>
                    <strong class="dashboard-card-number detail-card-value"><?= htmlspecialchars($staff["department"] ?: "-", ENT_QUOTES, "UTF-8") ?></strong>
                </div>
            </div>
            <div class="dashboard-card metric-violet">
                <?= appIcon("training", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="personnelCompetencyTotalLabel">Yetkinlik Sayısı</span>
                    <strong class="dashboard-card-number detail-card-value"><?= (int) $staff["competency_count"] ?></strong>
                </div>
            </div>
            <div class="dashboard-card metric-red">
                <?= appIcon("warning", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="personnelExpiredTotalLabel">Vadesi Geçen</span>
                    <strong class="dashboard-card-number detail-card-value"><?= (int) $staff["expired_count"] ?></strong>
                </div>
            </div>
        </section>

        <?php if ($formSuccess !== ""): ?>
            <div class="form-message success"><?= htmlspecialchars($formSuccess, ENT_QUOTES, "UTF-8") ?></div>
        <?php endif; ?>
        <?php if ($formError !== ""): ?>
            <div class="form-message error"><?= htmlspecialchars($formError, ENT_QUOTES, "UTF-8") ?></div>
        <?php endif; ?>
        <?php if (($_GET["c"] ?? "") === "added"): ?><div class="form-message success" data-i18n="personnelCompetencyAddedMessage">Yetkinlik eklendi.</div><?php endif; ?>
        <?php if (($_GET["c"] ?? "") === "removed"): ?><div class="form-message success" data-i18n="personnelCompetencyRemovedMessage">Yetkinlik kaldırıldı.</div><?php endif; ?>

        <div class="two-col">
            <section class="form-panel">
                <div class="section-heading compact-heading">
                    <div>
                        <h3 data-i18n="personnelInfoTitle">Personel Bilgileri</h3>
                        <p data-i18n="personnelInfoText">Personel künyesini düzenleyin.</p>
                    </div>
                </div>
                <form class="auditor-form" method="post" action="personnel-detail.php?id=<?= $staffId ?>">
                    <?= qmsCsrfField($csrfScope) ?>
                    <input type="hidden" name="form_type" value="update_personnel">
                    <div class="form-grid">
                        <label class="form-field"><span data-i18n="personnelFirstNameLabel">Ad</span><input type="text" name="first_name" value="<?= htmlspecialchars($staff["first_name"], ENT_QUOTES, "UTF-8") ?>" required maxlength="80"></label>
                        <label class="form-field"><span data-i18n="personnelLastNameLabel">Soyad</span><input type="text" name="last_name" value="<?= htmlspecialchars($staff["last_name"], ENT_QUOTES, "UTF-8") ?>" required maxlength="80"></label>
                        <label class="form-field"><span data-i18n="personnelCodeLabel">Personel Kodu</span><input type="text" name="employee_code" value="<?= htmlspecialchars((string) ($staff["employee_code"] ?? ""), ENT_QUOTES, "UTF-8") ?>" maxlength="60"></label>
                        <label class="form-field"><span data-i18n="personnelDepartmentLabel">Bölüm</span><input type="text" name="department" value="<?= htmlspecialchars((string) ($staff["department"] ?? ""), ENT_QUOTES, "UTF-8") ?>" maxlength="100"></label>
                        <label class="form-field"><span data-i18n="personnelPositionLabel">Pozisyon</span><input type="text" name="position" value="<?= htmlspecialchars((string) ($staff["position"] ?? ""), ENT_QUOTES, "UTF-8") ?>" maxlength="120"></label>
                        <label class="form-field"><span data-i18n="personnelEmailLabel">E-posta</span><input type="email" name="email" value="<?= htmlspecialchars((string) ($staff["email"] ?? ""), ENT_QUOTES, "UTF-8") ?>" maxlength="180"></label>
                        <label class="form-field"><span data-i18n="personnelPhoneLabel">Telefon</span><input type="text" name="phone" value="<?= htmlspecialchars((string) ($staff["phone"] ?? ""), ENT_QUOTES, "UTF-8") ?>" maxlength="60"></label>
                        <label class="form-field"><span data-i18n="personnelHiredDateLabel">İşe Alım Tarihi</span><input type="date" name="hired_date" value="<?= htmlspecialchars((string) ($staff["hired_date"] ?? ""), ENT_QUOTES, "UTF-8") ?>"></label>
                    </div>
                    <div class="form-actions">
                        <button class="primary-button" type="submit" data-i18n="savePersonnelButton">Personeli Kaydet</button>
                    </div>
                </form>
            </section>

            <section class="console-card checklist-section">
                <div class="section-heading compact-heading">
                    <div>
                        <h3 data-i18n="personnelCompetenciesTitle">Yetkinlik Matrisi</h3>
                        <p><span data-i18n="personnelCompetencyCountLabel">Toplam yetkinlik</span>: <strong><?= count($competencies) ?></strong></p>
                    </div>
                </div>

                <form class="auditor-form" method="post" action="personnel-detail.php?id=<?= $staffId ?>">
                    <?= qmsCsrfField($csrfScope) ?>
                    <input type="hidden" name="form_type" value="add_competency">
                    <div class="form-grid">
                        <label class="form-field"><span data-i18n="competencyNameLabel">Yetkinlik Adı</span><input type="text" name="competency_name" required maxlength="180"></label>
                        <label class="form-field"><span data-i18n="competencyLevelLabel">Seviye (1-5)</span><select name="level" required><?php foreach ($levelLabels as $lv => $label): ?><option value="<?= $lv ?>"><?= htmlspecialchars($label, ENT_QUOTES, "UTF-8") ?> (<?= $lv ?>)</option><?php endforeach; ?></select></label>
                        <label class="form-field"><span data-i18n="competencyAchievedDateLabel">Kazanım Tarihi</span><input type="date" name="achieved_date"></label>
                        <label class="form-field"><span data-i18n="competencyNextDateLabel">Sonraki Değerlendirme</span><input type="date" name="next_assessment_date"></label>
                        <label class="form-field form-field-wide"><span data-i18n="competencyNotesLabel">Notlar</span><input type="text" name="notes" maxlength="4000"></label>
                    </div>
                    <div class="form-actions">
                        <button class="secondary-button" type="submit" data-i18n="addCompetencyButton">Yetkinlik Ekle</button>
                    </div>
                </form>

                <div class="admin-list">
                    <?php if (!$competencies): ?>
                        <div class="empty-state" data-i18n="noCompetenciesText">Bu personel için henüz yetkinlik eklenmedi.</div>
                    <?php endif; ?>
                    <?php foreach ($competencies as $competency): ?>
                        <div class="admin-list-item">
                            <div class="list-item-main">
                                <strong><?= htmlspecialchars($competency["competency_name"], ENT_QUOTES, "UTF-8") ?></strong>
                                <span><?= htmlspecialchars($levelLabels[$competency["level"]] ?? (string) $competency["level"], ENT_QUOTES, "UTF-8") ?> · Sonraki: <?= htmlspecialchars($competency["next_assessment_date"] ?: "-", ENT_QUOTES, "UTF-8") ?></span>
                            </div>
                            <div class="list-item-side">
                                <span class="status-pill"><?= htmlspecialchars($statusLabels[$competency["status"]] ?? $competency["status"], ENT_QUOTES, "UTF-8") ?></span>
                                <form method="post" action="personnel-detail.php?id=<?= $staffId ?>" onsubmit="return confirm('Yetkinliği kaldırsın mı?');">
                                    <?= qmsCsrfField($csrfScope) ?>
                                    <input type="hidden" name="form_type" value="remove_competency">
                                    <input type="hidden" name="competency_id" value="<?= (int) $competency["id"] ?>">
                                    <button class="danger-button danger-button-sm" type="submit" data-i18n="removeCompetencyButton">Kaldır</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>
    </main>
    <script src="assets/js/theme.js"></script>
    <script src="assets/js/language.js"></script>
    <script src="assets/js/sidebar.js"></script>
    <script src="assets/js/pwa.js"></script>
</body>
</html>
