<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/access.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/app-ui.php';

qmsRequirePermission('competency_matrix.view');

$userId = (int) ($_SESSION["qms_user_id"] ?? 0);
$role = qmsCurrentRole();
$companyIds = qmsVisibleCompanyIds($pdo, $userId, $role);

$scopeSql = $companyIds
    ? ' AND s.company_id IN (' . implode(',', array_map('intval', $companyIds)) . ')'
    : ' AND 1 = 0';

// Personel + yetkinlikleri.
$stmt = $pdo->query(
    'SELECT s.id AS staff_id, s.first_name, s.last_name, s.department, s.position, c.company_name,
            sc.id AS comp_id, sc.competency_name, sc.level, sc.achieved_date, sc.next_assessment_date, sc.active AS comp_active
     FROM staff_members s
     INNER JOIN companies c ON c.id = s.company_id
     LEFT JOIN staff_competencies sc ON sc.staff_id = s.id AND sc.active = 1
     WHERE s.active = 1' . $scopeSql . '
     ORDER BY s.first_name ASC, s.last_name ASC, sc.competency_name ASC'
);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$people = [];
$overdueCount = 0;
$competencyCount = 0;
foreach ($rows as $row) {
    $sid = (int) $row['staff_id'];
    if (!isset($people[$sid])) {
        $people[$sid] = [
            'name' => trim($row['first_name'] . ' ' . $row['last_name']),
            'department' => $row['department'],
            'position' => $row['position'],
            'company' => $row['company_name'],
            'competencies' => [],
        ];
    }
    if ($row['comp_id'] !== null) {
        $people[$sid]['competencies'][] = [
            'id' => (int) $row['comp_id'],
            'name' => $row['competency_name'],
            'level' => $row['level'],
            'next' => $row['next_assessment_date'],
            'overdue' => !empty($row['next_assessment_date']) && $row['next_assessment_date'] < date('Y-m-d'),
        ];
        if (!empty($row['next_assessment_date']) && $row['next_assessment_date'] < date('Y-m-d')) {
            $overdueCount++;
        }
        $competencyCount++;
    }
}

$activeNav = "competency_matrix";

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f9fafb">
    <title>QuAmi Yetkinlik Matrisi</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar">
        <div class="topbar-inner">
            <div class="page-title-block">
                <strong data-i18n="competencyMatrixTitle">Yetkinlik Matrisi</strong>
                <span data-i18n="competencyMatrixText">Personel yetkinlikleri ve gözden geçirme durumu.</span>
            </div>
            <div class="topbar-actions">
                <button class="topbar-button" id="languageToggle" type="button">EN</button>
                <button class="topbar-button" id="themeToggle" type="button" aria-label="Tema değiştir">🌙</button>
            </div>
        </div>
    </header>
    <main class="page-container">
        <section class="page-heading">
            <span class="section-kicker" data-i18n="competencyMatrixKicker">Personel & Yetkinlik</span>
            <h1 data-i18n="competencyMatrixTitle">Yetkinlik Matrisi</h1>
            <p data-i18n="competencyMatrixText">Personel bazında yetkinlikler ve vadesi geçen değerlendirmeler.</p>
        </section>

        <section class="dashboard-grid compact-dashboard-grid">
            <div class="dashboard-card metric-blue">
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label">Personel</span>
                    <strong class="dashboard-card-number"><?= count($people) ?></strong>
                </div>
            </div>
            <div class="dashboard-card metric-blue">
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label">Toplam Yetkinlik</span>
                    <strong class="dashboard-card-number"><?= $competencyCount ?></strong>
                </div>
            </div>
            <div class="dashboard-card <?= $overdueCount > 0 ? 'metric-red' : '' ?>">
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label">Vadesi Geçen Yetkinlik</span>
                    <strong class="dashboard-card-number"><?= $overdueCount ?></strong>
                </div>
            </div>
        </section>

        <section class="page-section">
            <div class="section-heading"><h2 data-i18n="competencyMatrixListTitle">Personel Yetkinlikleri</h2></div>
            <?php if (!$people): ?>
                <div class="empty-state" data-i18n="competencyMatrixEmpty">Henüz kayıtlı personel veya yetkinlik yok.</div>
            <?php else: ?>
                <div class="record-card-grid">
                    <?php foreach ($people as $person): ?>
                        <div class="record-card document-card">
                            <div class="record-card-topline">
                                <span><?= htmlspecialchars($person['company'], ENT_QUOTES, 'UTF-8') ?></span>
                                <?php if ($person['department'] !== '' || $person['position'] !== ''): ?>
                                    <span class="status-badge"><?= htmlspecialchars(trim($person['department'] . ' / ' . $person['position']), ENT_QUOTES, 'UTF-8') ?></span>
                                <?php endif; ?>
                            </div>
                            <h3><?= htmlspecialchars($person['name'], ENT_QUOTES, 'UTF-8') ?></h3>
                            <?php if (!$person['competencies']): ?>
                                <p class="muted-color">Yetkinlik tanımlı değil.</p>
                            <?php else: ?>
                                <div class="competency-chips">
                                    <?php foreach ($person['competencies'] as $comp): ?>
                                        <span class="competency-chip <?= $comp['overdue'] ? 'is-overdue' : '' ?>">
                                            <?= htmlspecialchars($comp['name'], ENT_QUOTES, 'UTF-8') ?>
                                            <?= $comp['level'] !== '' && $comp['level'] !== null ? ' · ' . htmlspecialchars((string) $comp['level'], ENT_QUOTES, 'UTF-8') : '' ?>
                                            <?= !empty($comp['next']) ? ' · ' . htmlspecialchars((string) $comp['next'], ENT_QUOTES, 'UTF-8') : '' ?>
                                            <?= $comp['overdue'] ? ' · Vadesi geçti' : '' ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </main>
    <script src="assets/js/theme.js"></script>
    <script src="assets/js/language.js"></script>
    <script src="assets/js/sidebar.js"></script>
    <script src="assets/js/pwa.js"></script>
</body>
</html>
