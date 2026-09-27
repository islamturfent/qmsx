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

// Erisim tek kaynaktan gelir (RBAC servisi).
if (!qmsCanSession('admin.assignments')) {
    header("Location: dashboard.php");
    exit;
}

$csrfToken = qmsCsrfToken('admin_assignments');

$assignmentError = "";
$assignmentData = [
    "company_id" => "",
    "admin_user_id" => ""
];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    qmsCsrfVerify('admin_assignments', $_POST["csrf"] ?? null);

    $assignmentData = [
        "company_id" => (int) ($_POST["company_id"] ?? 0),
        "admin_user_id" => (int) ($_POST["admin_user_id"] ?? 0)
    ];

    if ($assignmentData["company_id"] <= 0 || $assignmentData["admin_user_id"] <= 0) {
        $assignmentError = "Lütfen şirket ve admin seçin.";
    } else {
        try {
            $insertAssignment = $pdo->prepare(
                "INSERT INTO company_admin_assignments (company_id, admin_user_id, active)
                 VALUES (:company_id, :admin_user_id, 1)"
            );
            $insertAssignment->execute($assignmentData);

            header("Location: super-admin-assignments.php?assignment=created");
            exit;
        } catch (PDOException $e) {
            $assignmentError = "Bu admin zaten seçilen şirkete atanmış.";
        }
    }
}

$companiesStmt = $pdo->query(
    "SELECT id, company_name
     FROM companies
     WHERE active = 1
     ORDER BY company_name ASC"
);
$companies = $companiesStmt->fetchAll(PDO::FETCH_ASSOC);

$adminsStmt = $pdo->query(
    "SELECT id, full_name, username
     FROM users
     WHERE role = 'system_admin' AND active = 1
     ORDER BY full_name ASC"
);
$admins = $adminsStmt->fetchAll(PDO::FETCH_ASSOC);

$assignmentsStmt = $pdo->query(
    "SELECT c.company_name, u.full_name, u.username, a.active
     FROM company_admin_assignments a
     INNER JOIN companies c ON c.id = a.company_id
     INNER JOIN users u ON u.id = a.admin_user_id
     ORDER BY a.created_at DESC, a.id DESC
     LIMIT 20"
);
$assignments = $assignmentsStmt->fetchAll(PDO::FETCH_ASSOC);

$assignmentCountStmt = $pdo->query("SELECT COUNT(*) FROM company_admin_assignments");
$assignmentCount = (int) $assignmentCountStmt->fetchColumn();

?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f9fafb">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="QuAmi">

    <title>QuAmi Admin Atamaları</title>

    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="assets/icons/qms-icon-192.png">
    <link rel="stylesheet" href="assets/css/style.css">
</head>

<body class="has-sidebar">
    <?php $activeNav = "assignments"; require __DIR__ . '/includes/app-sidebar.php'; ?>

    <header class="topbar">
        <div class="topbar-inner">
            <a class="brand" href="dashboard.php">
                <div class="brand-icon"><img src="assets/icons/qms-logo.png" alt="QuAmi"></div>
                <div class="brand-text">
                    <strong>QuAmi</strong>
                    <span>Quality Management System</span>
                </div>
            </a>

            <div class="topbar-actions">
                <button class="topbar-button" id="languageToggle" type="button">EN</button>
                <button class="topbar-button" id="themeToggle" type="button" aria-label="Tema değiştir">🌙</button>
                <a class="topbar-button topbar-link" href="dashboard.php" data-i18n="dashboardLinkLabel">Dashboard</a>
                <a class="topbar-button topbar-link" href="logout.php" data-i18n="logoutLabel">Çıkış</a>
            </div>
        </div>
    </header>

    <main class="page-container">
        <section class="welcome-card">
            <span class="section-kicker" data-i18n="superAdminKicker">Sistem Üst Yönetimi</span>
            <h1 data-i18n="assignmentsTitle">Admin Atamaları</h1>
            <p><span data-i18n="assignmentsText">Toplam atama</span>: <strong><?= $assignmentCount ?></strong></p>
        </section>

        <section class="super-admin-console">
            <div class="console-card">
                <div class="section-heading compact-heading">
                    <div>
                        <h3 data-i18n="createAssignmentTitle">Admini Şirkete Ata</h3>
                        <p data-i18n="createAssignmentText">Sistem adminlerini sorumlu olacakları şirketlerle eşleştirin.</p>
                    </div>
                </div>

                <?php if (isset($_GET["assignment"]) && $_GET["assignment"] === "created"): ?>
                    <div class="form-message success" data-i18n="assignmentCreatedMessage">Admin şirkete başarıyla atandı.</div>
                <?php endif; ?>

                <?php if ($assignmentError !== ""): ?>
                    <div class="form-message error"><?= htmlspecialchars($assignmentError, ENT_QUOTES, "UTF-8") ?></div>
                <?php endif; ?>

                <form class="auditor-form" method="post" action="super-admin-assignments.php">
                <?= qmsCsrfField('admin_assignments') ?>
                    <div class="form-grid">
                        <label class="form-field">
                            <span data-i18n="companySelectLabel">Şirket</span>
                            <select name="company_id" required>
                                <option value="" data-i18n="selectCompanyOption">Şirket seçin</option>
                                <?php foreach ($companies as $company): ?>
                                    <option value="<?= (int) $company["id"] ?>">
                                        <?= htmlspecialchars($company["company_name"], ENT_QUOTES, "UTF-8") ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>

                        <label class="form-field">
                            <span data-i18n="adminSelectLabel">Sistem Admini</span>
                            <select name="admin_user_id" required>
                                <option value="" data-i18n="selectAdminOption">Admin seçin</option>
                                <?php foreach ($admins as $admin): ?>
                                    <option value="<?= (int) $admin["id"] ?>">
                                        <?= htmlspecialchars($admin["full_name"] . " (" . $admin["username"] . ")", ENT_QUOTES, "UTF-8") ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </div>

                    <div class="form-actions">
                        <button class="primary-button" type="submit" data-i18n="createAssignmentButton">Atama Oluştur</button>
                    </div>
                </form>
            </div>

            <div class="console-card">
                <div class="section-heading compact-heading">
                    <div>
                        <h3 data-i18n="assignmentsTitle">Admin Atamaları</h3>
                        <p data-i18n="createAssignmentText">Sistem adminlerini sorumlu olacakları şirketlerle eşleştirin.</p>
                    </div>
                </div>

                <div class="admin-list">
                    <?php if (count($assignments) === 0): ?>
                        <div class="empty-state" data-i18n="noAssignmentsText">Henüz admin ataması yapılmadı.</div>
                    <?php endif; ?>

                    <?php foreach ($assignments as $assignment): ?>
                        <div class="admin-list-item">
                            <div>
                                <strong><?= htmlspecialchars($assignment["company_name"], ENT_QUOTES, "UTF-8") ?></strong>
                                <span><?= htmlspecialchars($assignment["full_name"] . " (" . $assignment["username"] . ")", ENT_QUOTES, "UTF-8") ?></span>
                            </div>
                            <span class="status-pill" data-i18n="activeStatusLabel">Aktif</span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
    </main>

    <script src="assets/js/theme.js"></script>
    <script src="assets/js/language.js"></script>
    <script src="assets/js/sidebar.js"></script>
    <script src="assets/js/pwa.js"></script>
</body>
</html>
