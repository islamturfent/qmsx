<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/access.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/app-ui.php';

qmsRequirePermission('competency_matrix.view');

$csrfScope = 'competency_matrix';

// Personel -> kullanici eslestirmesi (yetkinlik senkronu icin deterministik).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'link_user') {
    qmsRequirePermission('competency_matrix.view');
    qmsCsrfVerify($csrfScope, $_POST['csrf'] ?? null);
    $staffId = (int) ($_POST['staff_id'] ?? 0);
    $userId = (int) ($_POST['user_id'] ?? 0);
    if ($staffId > 0) {
        $st = $pdo->prepare('UPDATE staff_members SET user_id = NULLIF(?, 0) WHERE id = ? AND active = 1');
        $st->execute([$userId, $staffId]);
    }
    header('Location: competency-matrix.php?linked=1');
    exit;
}

$userId = (int) ($_SESSION["qms_user_id"] ?? 0);
$role = qmsCurrentRole();
$companyIds = qmsVisibleCompanyIds($pdo, $userId, $role);
$isAllCompanies = $companyIds === null; // super admin: kısıt yok.

$scopeSql = $isAllCompanies
    ? ''
    : ($companyIds ? ' AND s.company_id IN (' . implode(',', array_map('intval', $companyIds)) . ')' : ' AND 1 = 0');

// Personel + yetkinlikleri.
$stmt = $pdo->query(
    'SELECT s.id AS staff_id, s.first_name, s.last_name, s.department, s.position, s.user_id, s.email, c.company_name,
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
            'user_id' => (int) $row['user_id'],
            'email' => $row['email'],
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

// Yetkinlik vadesi yaklasan/gecen listesi (dashboard widget'i ile tutarli).
$dueItems = [];
$upcomingCount = 0;
$dueLimit = date('Y-m-d', strtotime('+30 days'));
$today = date('Y-m-d');
foreach ($people as $sid => $person) {
    foreach ($person['competencies'] as $comp) {
        $next = (string) ($comp['next'] ?? '');
        if ($next === '') {
            continue;
        }
        if ($next <= $dueLimit) {
            $isOverdue = $next < $today;
            $dueItems[] = [
                'person' => $person['name'],
                'company' => $person['company'],
                'comp' => $comp['name'],
                'next' => $next,
                'overdue' => $isOverdue,
            ];
            if (!$isOverdue) {
                $upcomingCount++;
            }
        }
    }
}
usort($dueItems, static fn($a, $b) => strcmp($a['next'], $b['next']));

// Kullanici eslestirme secenekleri (gorunur sirketlerin kullanicilari).
$userOptions = [];
if ($companyIds !== null) {
    $uScope = $companyIds !== []
        ? ' AND u.company_id IN (' . implode(',', array_map('intval', $companyIds)) . ')'
        : ' AND 1 = 0';
} else {
    $uScope = '';
}
$userOptions = $companyIds === null ? $pdo->query(
    'SELECT u.id, u.full_name FROM users u WHERE u.active = 1 AND u.company_id IS NOT NULL ORDER BY u.full_name ASC'
)->fetchAll(PDO::FETCH_ASSOC) : $pdo->query(
    'SELECT u.id, u.full_name FROM users u WHERE u.active = 1 AND u.company_id IS NOT NULL' . $uScope . ' ORDER BY u.full_name ASC'
)->fetchAll(PDO::FETCH_ASSOC);

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
            <div class="dashboard-card metric-blue">
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label">Yaklaşan Vade</span>
                    <strong class="dashboard-card-number"><?= $upcomingCount ?></strong>
                </div>
            </div>
        </section>

        <section class="page-section console-card">
            <div class="section-heading compact-heading">
                <div>
                    <h3 data-i18n="dashboardCompetencyTitle">Yetkinlik Vadesi (Yaklaşan/Geçen)</h3>
                    <p data-i18n="dashboardCompetencyText">Son 30 gün içinde gözden geçirilmesi gereken yetkinlik değerlendirmeleri.</p>
                </div>
            </div>
            <?php if (!$dueItems): ?>
                <div class="empty-state" data-i18n="dashboardCompetencyEmpty">Yaklaşan/geçen yetkinlik yok.</div>
            <?php else: ?>
                <div class="admin-list">
                    <?php foreach ($dueItems as $d): ?>
                        <div class="admin-list-item">
                            <div class="list-item-main">
                                <strong><?= htmlspecialchars($d['person'], ENT_QUOTES, 'UTF-8') ?></strong>
                                <span><?= htmlspecialchars((string) $d['comp'] . ' · ' . (string) $d['company'] . ' · vade: ' . (string) $d['next'], ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                            <div class="list-item-side"><span class="status-pill <?= $d['overdue'] ? '' : 'on-track' ?>"><?= $d['overdue'] ? 'Vadesi geçti' : 'Yaklaşan vade' ?></span></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="page-section">
            <div class="section-heading"><h2 data-i18n="competencyMatrixListTitle">Personel Yetkinlikleri</h2></div>
            <?php if (!$people): ?>
                <div class="empty-state" data-i18n="competencyMatrixEmpty">Henüz kayıtlı personel veya yetkinlik yok.</div>
            <?php else: ?>
                <div class="record-card-grid">
                    <?php foreach ($people as $sid => $person): ?>
                        <div class="record-card document-card">
                            <div class="record-card-topline">
                                <span><?= htmlspecialchars($person['company'], ENT_QUOTES, 'UTF-8') ?></span>
                                <?php if ($person['department'] !== '' || $person['position'] !== ''): ?>
                                    <span class="status-badge"><?= htmlspecialchars(trim($person['department'] . ' / ' . $person['position']), ENT_QUOTES, 'UTF-8') ?></span>
                                <?php endif; ?>
                            </div>
                            <h3><?= htmlspecialchars($person['name'], ENT_QUOTES, 'UTF-8') ?></h3>
                            <form class="comp-user-link" method="post" action="competency-matrix.php">
                                <?= qmsCsrfField($csrfScope) ?>
                                <input type="hidden" name="form_type" value="link_user">
                                <input type="hidden" name="staff_id" value="<?= (int) $sid ?>">
                                <select name="user_id">
                                    <option value="0" data-i18n="compUserLinkNone">Kullanıcı seç</option>
                                    <?php foreach ($userOptions as $uo): ?>
                                        <option value="<?= (int) $uo['id'] ?>" <?= (int) $person['user_id'] === (int) $uo['id'] ? "selected" : "" ?>>
                                            <?= htmlspecialchars($uo['full_name'], ENT_QUOTES, 'UTF-8') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button class="primary-button primary-button-sm" type="submit">
                                    <span data-i18n="compUserLinkSave">Eşle</span>
                                </button>
                            </form>
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
