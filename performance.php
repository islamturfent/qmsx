<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/access.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/performance-functions.php';
require_once __DIR__ . '/includes/report-export-data.php';

$userId = (int) ($_SESSION["qms_user_id"] ?? 0);
$isSuperAdmin = ($_SESSION["qms_role"] ?? "") === "super_admin";
$role = qmsCurrentRole();
$csrfScope = 'performance';

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

$currentYear = (int) date("Y");
$selectedCompanyId = (int) ($_GET["company_id"] ?? 0);
if ($selectedCompanyId > 0 && !in_array($selectedCompanyId, $allowedCompanyIds, true)) {
    $selectedCompanyId = 0;
}
$selectedYear = (int) ($_GET["year"] ?? $currentYear);
if ($selectedYear < $currentYear - 2 || $selectedYear > $currentYear + 2) {
    $selectedYear = $currentYear;
}

$formMessage = "";
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    qmsCsrfVerify($csrfScope, $_POST["csrf"] ?? null);

    $kpiKey = (string) ($_POST["kpi_key"] ?? "");
    $rawTarget = str_replace(",", ".", trim((string) ($_POST["target_value"] ?? "")));
    $targetCompanyId = (int) ($_POST["company_id"] ?? 0);

    try {
        if (!in_array($targetCompanyId, $allowedCompanyIds, true)) {
            throw new InvalidArgumentException("Geçerli bir şirket seçin.");
        }
        if (!in_array($kpiKey, qmsPerformanceKpiKeys(), true)) {
            throw new InvalidArgumentException("Geçersiz KPI seçildi.");
        }
        if ($rawTarget === "" || !is_numeric($rawTarget)) {
            throw new InvalidArgumentException("Lütfen geçerli bir hedef değeri girin.");
        }
        $targetValue = (float) $rawTarget;
        $note = trim((string) ($_POST["note"] ?? ""));

        qmsPerformanceTargetSave($pdo, $targetCompanyId, $kpiKey, $selectedYear, $targetValue, $note !== "" ? $note : null, $userId);
        $selectedCompanyId = $targetCompanyId;
        $formMessage = "Hedef kaydedildi.";
    } catch (InvalidArgumentException $error) {
        $formMessage = $error->getMessage();
    }
}

// Gerceklesen degerler rapor motorundan gelir (tek kaynak).
$report = null;
$targets = [];
$actualByKpi = [];
if ($selectedCompanyId > 0) {
    $start = sprintf("%04d-01-01", $selectedYear);
    $end = sprintf("%04d-12-31", $selectedYear);
    $report = buildReportExportData($pdo, $userId, $isSuperAdmin, [
        "company_id" => $selectedCompanyId,
        "start_date" => $start,
        "end_date" => $end,
    ]);
    $targets = qmsPerformanceTargets($pdo, $selectedCompanyId, $selectedYear);

    foreach (qmsPerformanceKpis() as $key => $definition) {
        $actualByKpi[$key] = isset($report["metrics"][$key]) ? $report["metrics"][$key] : null;
    }
}

$years = range($currentYear - 2, $currentYear + 2);
$activeNav = "performance";

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f9fafb">
    <title>QMS Performans Yönetimi</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar">
        <div class="topbar-inner">
            <div class="page-title-block">
                <strong data-i18n="performanceTitle">Performans Yönetimi</strong>
                <span data-i18n="performanceText">Şirket KPI hedeflerini belirleyin ve gerçekleşmeyi izleyin.</span>
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
                <span class="section-kicker" data-i18n="performanceKicker">Hedef Takibi</span>
                <h1 data-i18n="performanceTitle">Performans Yönetimi</h1>
                <p data-i18n="performanceText">Şirket KPI hedeflerini belirleyin ve gerçekleşmeyi izleyin.</p>
            </div>
        </section>

        <section class="form-panel">
            <form method="get" class="filter-grid">
                <label class="form-field">
                    <span data-i18n="companySelectLabel">Şirket</span>
                    <select name="company_id">
                        <option value="0" data-i18n="selectCompanyOption">Şirket seçin</option>
                        <?php foreach ($companies as $company): ?>
                            <option value="<?= (int) $company["id"] ?>" <?= $selectedCompanyId === (int) $company["id"] ? "selected" : "" ?>><?= htmlspecialchars($company["company_name"], ENT_QUOTES, "UTF-8") ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="form-field">
                    <span data-i18n="performanceYearLabel">Yıl</span>
                    <select name="year">
                        <?php foreach ($years as $year): ?>
                            <option value="<?= $year ?>" <?= $selectedYear === $year ? "selected" : "" ?>><?= $year ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <div class="form-actions">
                    <button class="primary-button" type="submit" data-i18n="applyFiltersButton">Göster</button>
                </div>
            </form>
        </section>

        <?php if ($formMessage !== ""): ?>
            <div class="form-message success"><?= htmlspecialchars($formMessage, ENT_QUOTES, "UTF-8") ?></div>
        <?php endif; ?>

        <?php if ($selectedCompanyId === 0): ?>
            <section class="page-section">
                <div class="empty-state" data-i18n="performanceNoCompanyText">Hedef görüntülemek için yukarıdan bir şirket seçin.</div>
            </section>
        <?php else: ?>
            <section class="dashboard-grid performance-grid">
                <?php foreach (qmsPerformanceKpis() as $kpiKey => $definition): $target = $targets[$kpiKey] ?? null; $actual = $actualByKpi[$kpiKey]; ?>
                    <?php
                        $actualValue = $actual === null ? null : (float) $actual;
                        $onTrack = qmsPerformanceOnTrack($target ? $target["target_value"] : null, $actualValue, $definition["higher_better"]);
                    ?>
                    <div class="dashboard-card performance-card">
                        <div class="dashboard-card-content">
                            <?= appIcon($definition["icon"], "dashboard-card-icon") ?>
                            <span class="dashboard-card-label" data-i18n="<?= $definition["label_key"] ?>"><?= htmlspecialchars(qmsPerformanceKpiLabels()[$kpiKey] ?? $kpiKey, ENT_QUOTES, "UTF-8") ?></span>
                            <strong class="dashboard-card-number">
                                <?= $actual === null ? "-" : htmlspecialchars((string) $actual, ENT_QUOTES, "UTF-8") ?><?= htmlspecialchars($definition["unit"], ENT_QUOTES, "UTF-8") ?>
                            </strong>
                            <?php if ($target): ?>
                                <span class="performance-target-value" data-i18n="performanceTargetLabel">Hedef</span>: <span class="performance-target-value"><?= $target["target_value"] ?><?= htmlspecialchars($definition["unit"], ENT_QUOTES, "UTF-8") ?></span>
                            <?php endif; ?>
                            <?php if ($onTrack === true): ?>
                                <span class="status-pill on-track" data-i18n="performanceOnTrackLabel">Hedefte</span>
                            <?php elseif ($onTrack === false): ?>
                                <span class="status-pill off-track" data-i18n="performanceOffTrackLabel">Hedef Dışı</span>
                            <?php else: ?>
                                <span class="status-pill no-target" data-i18n="performanceNoTargetLabel">Hedef Yok</span>
                            <?php endif; ?>
                        </div>
                        <form class="performance-target-form" method="post" action="performance.php?company_id=<?= $selectedCompanyId ?>&year=<?= $selectedYear ?>">
                            <?= qmsCsrfField($csrfScope) ?>
                            <input type="hidden" name="kpi_key" value="<?= $kpiKey ?>">
                            <input type="hidden" name="company_id" value="<?= $selectedCompanyId ?>">
                            <label class="form-field">
                                <span data-i18n="performanceTargetLabel">Hedef</span>
                                <input type="number" name="target_value" min="0" step="0.01" value="<?= $target ? htmlspecialchars((string) $target["target_value"], ENT_QUOTES, "UTF-8") : "" ?>" placeholder="<?= htmlspecialchars($actual === null ? "-" : (string) $actual, ENT_QUOTES, "UTF-8") ?>">
                            </label>
                            <button class="primary-button" type="submit" data-i18n="saveTargetButton">Kaydet</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </section>
            <section class="page-section">
                <div class="form-message info" data-i18n="performanceHelpText">Gerçekleşen değerler rapor dönemine göre hesaplanır; hedefler yalnızca bu ekranda düzenlenir.</div>
            </section>
        <?php endif; ?>
    </main>
    <script src="assets/js/theme.js"></script>
    <script src="assets/js/language.js"></script>
    <script src="assets/js/sidebar.js"></script>
    <script src="assets/js/pwa.js"></script>
</body>
</html>
