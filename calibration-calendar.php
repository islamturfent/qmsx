<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/access.php';
require_once __DIR__ . '/includes/permissions.php';
qmsRequirePermission('operations.view');

$userId = (int) ($_SESSION["qms_user_id"] ?? 0);
$role = qmsCurrentRole();

$companyScope = qmsCompanyScope('co.id', qmsVisibleCompanyIds($pdo, $userId, $role));
$companyStmt = $pdo->prepare('SELECT co.id, co.company_name FROM companies co WHERE co.active = 1' . $companyScope['sql'] . ' ORDER BY co.company_name');
$companyStmt->execute($companyScope['params']);
$companies = $companyStmt->fetchAll(PDO::FETCH_ASSOC);
$selectedCompanyId = (int) ($_GET["company_id"] ?? 0);
if (!in_array($selectedCompanyId, array_map('intval', array_column($companies, 'id')), true)) {
    $selectedCompanyId = 0;
}

// Mevcut yillar (sonraki kalibrasyon tarihine gore).
$yearStmt = $pdo->prepare('SELECT DISTINCT YEAR(i.next_calibration_date) AS y FROM instruments i INNER JOIN companies co ON co.id = i.company_id WHERE i.active = 1 AND i.next_calibration_date IS NOT NULL' . $companyScope['sql'] . ' ORDER BY y DESC');
$yearStmt->execute($companyScope['params']);
$availableYears = array_map('intval', array_filter(array_column($yearStmt->fetchAll(PDO::FETCH_ASSOC), 'y')));
$selectedYear = (int) ($_GET["year"] ?? (int) date("Y"));
if (!in_array($selectedYear, $availableYears, true) && $availableYears !== []) {
    $selectedYear = max($availableYears);
}
if ($selectedYear <= 0) {
    $selectedYear = (int) date("Y");
}

$params = [];
$where = "i.active = 1 AND i.next_calibration_date IS NOT NULL AND YEAR(i.next_calibration_date) = ?";
$params[] = $selectedYear;
if ($selectedCompanyId > 0) {
    $where .= ' AND i.company_id = ?';
    $params[] = $selectedCompanyId;
}

$stmt = $pdo->prepare(
    "SELECT i.id, i.name, i.instrument_code, i.instrument_type, i.next_calibration_date, i.status,
            co.company_name
     FROM instruments i
     INNER JOIN companies co ON co.id = i.company_id
     WHERE " . $where . "
     ORDER BY i.next_calibration_date ASC, i.id ASC"
);
$stmt->execute($params);
$instruments = $stmt->fetchAll(PDO::FETCH_ASSOC);

$today = date('Y-m-d');
$monthNames = [1 => 'Oca', 2 => 'Şub', 3 => 'Mar', 4 => 'Nis', 5 => 'May', 6 => 'Haz', 7 => 'Tem', 8 => 'Ağu', 9 => 'Eyl', 10 => 'Eki', 11 => 'Kas', 12 => 'Ara'];

$byMonth = array_fill(1, 12, []);
$overdueCount = 0;
$upcomingCount = 0;
foreach ($instruments as $inst) {
    $m = (int) date('n', strtotime((string) $inst['next_calibration_date']));
    $byMonth[$m][] = $inst;
    if ($inst['next_calibration_date'] < $today) {
        $overdueCount++;
    } else {
        $upcomingCount++;
    }
}

$activeNav = "calibration_calendar";

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f9fafb">
    <title>QuAmi Kalibrasyon Takvimi</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar">
        <div class="topbar-inner">
            <div class="page-title-block">
                <strong data-i18n="calibCalendarTitle">Kalibrasyon Takvimi</strong>
                <span data-i18n="calibCalendarText">Ölçü aletlerinin sonraki kalibrasyon tarihlerini yıl bazında görün.</span>
            </div>
            <div class="topbar-actions">
                <button class="topbar-button" id="languageToggle" type="button">EN</button>
                <button class="topbar-button" id="themeToggle" type="button" aria-label="Tema değiştir">🌙</button>
            </div>
        </div>
    </header>
    <main class="page-container">
        <section class="page-heading">
            <span class="section-kicker" data-i18n="instrumentsKicker">Metroloji</span>
            <h1 data-i18n="calibCalendarTitle">Kalibrasyon Takvimi</h1>
            <p data-i18n="calibCalendarText">Ölçü aletlerinin sonraki kalibrasyon tarihlerini yıl bazında görün.</p>
        </section>

        <section class="dashboard-grid compact-dashboard-grid">
            <div class="dashboard-card metric-blue">
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="calibCalendarTotal">Tanımlı Alet</span>
                    <strong class="dashboard-card-number"><?= count($instruments) ?></strong>
                </div>
            </div>
            <div class="dashboard-card metric-green">
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="calibCalendarUpcoming">Gelecek</span>
                    <strong class="dashboard-card-number"><?= $upcomingCount ?></strong>
                </div>
            </div>
            <div class="dashboard-card metric-red">
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="calibCalendarOverdue">Gecikmiş</span>
                    <strong class="dashboard-card-number"><?= $overdueCount ?></strong>
                </div>
            </div>
        </section>

        <section class="page-section console-card">
            <div class="filter-tabs">
                <form class="auditor-form finding-filter-form" method="get" action="calibration-calendar.php">
                    <select name="year">
                        <?php foreach (array_merge([$selectedYear], $availableYears, [((int) date('Y')) + 1]) as $y): ?>
                            <?php $y = (int) $y; ?>
                            <?php if ($y > 0 && $y <= (int) date('Y') + 2): ?>
                                <option value="<?= $y ?>" <?= $y === $selectedYear ? "selected" : "" ?>><?= $y ?></option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                    <select name="company_id">
                        <option value="0" data-i18n="allCompaniesOption">Tüm Şirketler</option>
                        <?php foreach ($companies as $c): ?>
                            <option value="<?= (int) $c["id"] ?>" <?= $selectedCompanyId === (int) $c["id"] ? "selected" : "" ?>><?= htmlspecialchars($c["company_name"], ENT_QUOTES, "UTF-8") ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="primary-button primary-button-sm" type="submit" data-i18n="applyFiltersButton">Filtrele</button>
                </form>
            </div>

            <div class="calendar-grid">
                <?php for ($m = 1; $m <= 12; $m++): ?>
                    <div class="calendar-month">
                        <div class="calendar-month-head">
                            <strong><?= $monthNames[$m] ?></strong>
                            <span><?= count($byMonth[$m]) ?></span>
                        </div>
                        <div class="calendar-month-body">
                            <?php if (!$byMonth[$m]): ?>
                                <span class="calendar-empty">—</span>
                            <?php else: ?>
                                <?php foreach ($byMonth[$m] as $inst): ?>
                                    <?php $overdue = $inst['next_calibration_date'] < $today; ?>
                                    <a class="calendar-item <?= $overdue ? "calendar-overdue" : "" ?>" href="instrument-detail.php?id=<?= (int) $inst['id'] ?>">
                                        <span class="calendar-item-date"><?= htmlspecialchars(date('d.m', strtotime((string) $inst['next_calibration_date'])), ENT_QUOTES, 'UTF-8') ?></span>
                                        <span class="calendar-item-title"><?= htmlspecialchars($inst['name'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <span class="calendar-item-company"><?= htmlspecialchars((string) $inst['instrument_code'] . " · " . (string) $inst['company_name'], ENT_QUOTES, 'UTF-8') ?></span>
                                    </a>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endfor; ?>
            </div>
        </section>
    </main>
    <script src="assets/js/theme.js"></script>
    <script src="assets/js/language.js"></script>
    <script src="assets/js/sidebar.js"></script>
    <script src="assets/js/pwa.js"></script>
</body>
</html>
