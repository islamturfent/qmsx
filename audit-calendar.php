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

// Sirket kapsami.
$companyScope = qmsCompanyScope('companies.id', qmsVisibleCompanyIds($pdo, $userId, $role));
$companyStmt = $pdo->prepare('SELECT companies.id, companies.company_name FROM companies WHERE companies.active = 1' . $companyScope['sql'] . ' ORDER BY companies.company_name');
$companyStmt->execute($companyScope['params']);
$companies = $companyStmt->fetchAll(PDO::FETCH_ASSOC);
$selectedCompanyId = (int) ($_GET["company_id"] ?? 0);
if (!in_array($selectedCompanyId, array_map('intval', array_column($companies, 'id')), true)) {
    $selectedCompanyId = 0;
}

// Mevcut yillar.
$yearStmt = $pdo->prepare('SELECT DISTINCT YEAR(a.planned_date) AS y FROM audits a INNER JOIN companies ON companies.id = a.company_id WHERE a.active = 1 AND a.planned_date IS NOT NULL' . $companyScope['sql'] . ' ORDER BY y DESC');
$yearStmt->execute($companyScope['params']);
$availableYears = array_map('intval', array_filter(array_column($yearStmt->fetchAll(PDO::FETCH_ASSOC), 'y')));
$selectedYear = (int) ($_GET["year"] ?? (int) date("Y"));
if (!in_array($selectedYear, $availableYears, true) && $availableYears !== []) {
    $selectedYear = max($availableYears);
}
if ($selectedYear <= 0) {
    $selectedYear = (int) date("Y");
}

// Yil + sirket filtresine gore denetimler.
$params = [];
$where = 'a.active = 1 AND a.planned_date IS NOT NULL AND YEAR(a.planned_date) = ?';
$params[] = $selectedYear;
if ($selectedCompanyId > 0) {
    $where .= ' AND a.company_id = ?';
    $params[] = $selectedCompanyId;
}

// Sirket kapsami: sirket kullanicisi/denetci/sistem admini yalnizca gordugu sirketlerin denetimlerini gorur.
$auditScope = qmsCompanyScope('a.company_id', qmsVisibleCompanyIds($pdo, $userId, $role));
$where .= $auditScope['sql'];
$params = array_merge($params, $auditScope['params']);

$auditStmt = $pdo->prepare(
    "SELECT a.id, a.title, a.audit_type, a.planned_date, a.status, companies.company_name,
            (SELECT COUNT(*) FROM audit_checklist_items ci WHERE ci.audit_id = a.id AND ci.active = 1 AND ci.result_status <> 'pending') AS done,
            (SELECT COUNT(*) FROM audit_checklist_items ci WHERE ci.audit_id = a.id AND ci.active = 1) AS total
     FROM audits a
     INNER JOIN companies ON companies.id = a.company_id
     WHERE " . $where . "
     ORDER BY a.planned_date ASC, a.id ASC"
);
$auditStmt->execute($params);
$audits = $auditStmt->fetchAll(PDO::FETCH_ASSOC);

$today = date('Y-m-d');
$monthNames = [1 => 'Oca', 2 => 'Şub', 3 => 'Mar', 4 => 'Nis', 5 => 'May', 6 => 'Haz', 7 => 'Tem', 8 => 'Ağu', 9 => 'Eyl', 10 => 'Eki', 11 => 'Kas', 12 => 'Ara'];
$statusLabels = ['planned' => 'Planlandı', 'in_progress' => 'Devam Ediyor', 'done' => 'Tamamlandı'];

$byMonth = array_fill(1, 12, []);
$counts = ['total' => count($audits), 'planned' => 0, 'in_progress' => 0, 'done' => 0, 'overdue' => 0];
foreach ($audits as $a) {
    $m = (int) date('n', strtotime((string) $a['planned_date']));
    $byMonth[$m][] = $a;
    $counts[$a['status']] = ($counts[$a['status']] ?? 0) + 1;
    $doneCnt = (int) $a['done'];
    $totCnt = (int) $a['total'];
    if ($a['status'] !== 'done' && $totCnt > 0 && $doneCnt < $totCnt && $a['planned_date'] < $today) {
        $counts['overdue']++;
    }
}

$activeNav = "audit_calendar";

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f9fafb">
    <title>QuAmi Denetim Yıllık Takvimi</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar">
        <div class="topbar-inner">
            <div class="page-title-block">
                <strong data-i18n="auditCalendarTitle">Denetim Yıllık Takvimi</strong>
                <span data-i18n="auditCalendarText">Planlanmış ve gerçekleşen denetimleri yıl bazında görün.</span>
            </div>
            <div class="topbar-actions">
                <button class="topbar-button" id="languageToggle" type="button">EN</button>
                <button class="topbar-button" id="themeToggle" type="button" aria-label="Tema değiştir">🌙</button>
            </div>
        </div>
    </header>
    <main class="page-container">
        <section class="page-heading">
            <span class="section-kicker" data-i18n="auditCalendarKicker">Denetim Planı</span>
            <h1 data-i18n="auditCalendarTitle">Denetim Yıllık Takvimi</h1>
            <p data-i18n="auditCalendarText">Planlanmış ve gerçekleşen denetimleri yıl bazında görün.</p>
        </section>

        <section class="dashboard-grid compact-dashboard-grid">
            <div class="dashboard-card metric-blue">
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="auditCalendarTotalAudits">Toplam Denetim</span>
                    <strong class="dashboard-card-number"><?= $counts['total'] ?></strong>
                </div>
            </div>
            <div class="dashboard-card metric-orange">
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="auditCalendarPlanned">Planlandı</span>
                    <strong class="dashboard-card-number"><?= $counts['planned'] ?></strong>
                </div>
            </div>
            <div class="dashboard-card metric-orange">
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="auditCalendarInProgress">Devam Ediyor</span>
                    <strong class="dashboard-card-number"><?= $counts['in_progress'] ?></strong>
                </div>
            </div>
            <div class="dashboard-card metric-green">
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="auditCalendarDone">Tamamlandı</span>
                    <strong class="dashboard-card-number"><?= $counts['done'] ?></strong>
                </div>
            </div>
            <div class="dashboard-card metric-red">
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="auditCalendarOverdue">Vadeyi Geçen</span>
                    <strong class="dashboard-card-number"><?= $counts['overdue'] ?></strong>
                </div>
            </div>
        </section>

        <section class="page-section console-card">
            <div class="filter-tabs">
                <form class="auditor-form finding-filter-form" method="get" action="audit-calendar.php">
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
                                <?php foreach ($byMonth[$m] as $a): ?>
                                    <a class="calendar-item calendar-<?= htmlspecialchars($a['status'], ENT_QUOTES, 'UTF-8') ?>" href="audit-detail.php?id=<?= (int) $a['id'] ?>">
                                        <span class="calendar-item-date"><?= htmlspecialchars(date('d.m', strtotime((string) $a['planned_date'])), ENT_QUOTES, 'UTF-8') ?></span>
                                        <span class="calendar-item-title"><?= htmlspecialchars($a['title'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <span class="calendar-item-company"><?= htmlspecialchars($a['company_name'], ENT_QUOTES, 'UTF-8') ?></span>
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
