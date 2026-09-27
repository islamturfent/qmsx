<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/access.php';
require_once __DIR__ . '/includes/training-functions.php';

$userId = (int) ($_SESSION["qms_user_id"] ?? 0);
$role = qmsCurrentRole();

// Kapsam tek kaynaktan: role gore gorunur sirketler (null = kisitlama yok).
$scope = qmsCompanyScope("trainings.company_id", qmsVisibleCompanyIds($pdo, $userId, $role));

$statusLabels = qmsTrainingStatusLabels();
$statusI18n = qmsTrainingStatusI18nKeys();

$filters = [
    "company_id" => (int) ($_GET["company_id"] ?? 0),
    "status" => (string) ($_GET["status"] ?? "")
];

$listSql = "SELECT trainings.id, trainings.title, trainings.category, trainings.provider,
                   trainings.planned_date, trainings.completed_date, trainings.status,
                   companies.company_name,
                   (SELECT COUNT(*) FROM training_participants
                     WHERE training_participants.training_id = trainings.id) AS participant_count,
                   (SELECT COUNT(*) FROM training_participants
                     WHERE training_participants.training_id = trainings.id
                       AND training_participants.status = 'completed') AS participant_completed
            FROM trainings
            INNER JOIN companies ON companies.id = trainings.company_id
            WHERE trainings.active = 1" . $scope["sql"];
$listParams = $scope["params"];

if ($filters["company_id"] > 0) {
    $listSql .= " AND trainings.company_id = ?";
    $listParams[] = $filters["company_id"];
}
if (isset($statusLabels[$filters["status"]])) {
    $listSql .= " AND trainings.status = ?";
    $listParams[] = $filters["status"];
}
$listSql .= " ORDER BY trainings.planned_date IS NULL, trainings.planned_date DESC, trainings.id DESC";

$listStmt = $pdo->prepare($listSql);
$listStmt->execute($listParams);
$trainings = $listStmt->fetchAll(PDO::FETCH_ASSOC);

// Ozet sayilari ayni kapsamla.
$summaryStmt = $pdo->prepare(
    "SELECT trainings.status, COUNT(*) AS total
     FROM trainings
     WHERE trainings.active = 1" . $scope["sql"] . "
     GROUP BY trainings.status"
);
$summaryStmt->execute($scope["params"]);
$summaryByStatus = array_column($summaryStmt->fetchAll(PDO::FETCH_ASSOC), "total", "status");

$summary = [
    "total" => array_sum($summaryByStatus),
    "planned" => (int) ($summaryByStatus["planned"] ?? 0),
    "in_progress" => (int) ($summaryByStatus["in_progress"] ?? 0),
    "completed" => (int) ($summaryByStatus["completed"] ?? 0)
];

$companyScope = qmsCompanyScope("companies.id", qmsVisibleCompanyIds($pdo, $userId, $role));
$companyStmt = $pdo->prepare(
    "SELECT companies.id, companies.company_name
     FROM companies
     WHERE companies.active = 1" . $companyScope["sql"] . "
     ORDER BY companies.company_name"
);
$companyStmt->execute($companyScope["params"]);
$companies = $companyStmt->fetchAll(PDO::FETCH_ASSOC);

$activeNav = "trainings";

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f9fafb">
    <title>QuAmi Eğitim Yönetimi</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar">
        <div class="topbar-inner">
            <div class="page-title-block">
                <strong data-i18n="trainingManagementTitle">Eğitim Yönetimi</strong>
                <span data-i18n="trainingManagementText">Eğitimleri planlayın, katılımcıları ve tamamlanma durumunu izleyin.</span>
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
                <span class="section-kicker" data-i18n="trainingRegisterKicker">Eğitim Kayıtları</span>
                <h1 data-i18n="trainingManagementTitle">Eğitim Yönetimi</h1>
                <p data-i18n="trainingManagementText">Eğitimleri planlayın, katılımcıları ve tamamlanma durumunu izleyin.</p>
            </div>
            <a class="primary-button" href="training-create.php" data-i18n="newTrainingButton">Yeni Eğitim</a>
        </section>

        <?php if (($_GET["training"] ?? "") === "created"): ?>
            <div class="form-message success" data-i18n="trainingCreatedMessage">Eğitim kaydı oluşturuldu.</div>
        <?php endif; ?>

        <section class="dashboard-grid compact-dashboard-grid">
            <div class="dashboard-card metric-blue">
                <?= appIcon("training", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="trainingTotalLabel">Toplam Eğitim</span>
                    <strong class="dashboard-card-number"><?= $summary["total"] ?></strong>
                </div>
            </div>
            <div class="dashboard-card metric-orange">
                <?= appIcon("clock", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="trainingPlannedLabel">Planlanan</span>
                    <strong class="dashboard-card-number"><?= $summary["planned"] ?></strong>
                </div>
            </div>
            <div class="dashboard-card metric-violet">
                <?= appIcon("trend", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="trainingInProgressLabel">Devam Eden</span>
                    <strong class="dashboard-card-number"><?= $summary["in_progress"] ?></strong>
                </div>
            </div>
            <div class="dashboard-card metric-teal">
                <?= appIcon("check", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="trainingCompletedLabel">Tamamlanan</span>
                    <strong class="dashboard-card-number"><?= $summary["completed"] ?></strong>
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
                    <span data-i18n="trainingStatusLabel">Durum</span>
                    <select name="status">
                        <option value="" data-i18n="allOption">Tümü</option>
                        <?php foreach ($statusLabels as $value => $label): ?>
                            <option value="<?= $value ?>" <?= $filters["status"] === $value ? "selected" : "" ?> data-i18n="<?= $statusI18n[$value] ?>"><?= htmlspecialchars($label, ENT_QUOTES, "UTF-8") ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <div class="form-actions">
                    <button class="primary-button" type="submit" data-i18n="applyFiltersButton">Filtrele</button>
                    <a class="secondary-button" href="trainings.php" data-i18n="clearFiltersButton">Temizle</a>
                </div>
            </form>
        </section>

        <section class="page-section">
            <div class="record-card-grid">
                <?php if (!$trainings): ?>
                    <div class="empty-state" data-i18n="noTrainingsText">Filtrelere uygun eğitim bulunamadı.</div>
                <?php endif; ?>
                <?php foreach ($trainings as $training): ?>
                    <a class="record-card" href="training-detail.php?id=<?= (int) $training["id"] ?>">
                        <div class="record-card-topline">
                            <span><?= htmlspecialchars($training["company_name"], ENT_QUOTES, "UTF-8") ?></span>
                            <span class="status-badge status-<?= htmlspecialchars($training["status"], ENT_QUOTES, "UTF-8") ?>" data-i18n="<?= $statusI18n[$training["status"]] ?? "" ?>"><?= htmlspecialchars($statusLabels[$training["status"]] ?? $training["status"], ENT_QUOTES, "UTF-8") ?></span>
                        </div>
                        <div class="record-card-body">
                            <h3><?= htmlspecialchars($training["title"], ENT_QUOTES, "UTF-8") ?></h3>
                            <p><?= htmlspecialchars($training["provider"] ?: "-", ENT_QUOTES, "UTF-8") ?><?= $training["category"] ? " · " . htmlspecialchars($training["category"], ENT_QUOTES, "UTF-8") : "" ?></p>
                            <span class="record-card-meta">
                                <span data-i18n="plannedDateLabel">Planlanan Tarih</span>:
                                <?= htmlspecialchars($training["planned_date"] ?: "-", ENT_QUOTES, "UTF-8") ?>
                                · <?= (int) $training["participant_completed"] ?>/<?= (int) $training["participant_count"] ?>
                                <span data-i18n="trainingParticipantsLabel">katılımcı</span>
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
