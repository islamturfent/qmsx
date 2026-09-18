<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/csrf.php';

$userId = (int) ($_SESSION["qms_user_id"] ?? 0);
$isSuperAdmin = ($_SESSION["qms_role"] ?? "") === "super_admin";
$csrfToken = qmsCsrfToken('profile');

$formError = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    qmsCsrfVerify('profile', $_POST["csrf"] ?? null);

    $currentPassword = (string) ($_POST["current_password"] ?? "");
    $newPassword = (string) ($_POST["new_password"] ?? "");
    $confirmPassword = (string) ($_POST["confirm_password"] ?? "");

    $passwordStmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = :id AND active = 1 LIMIT 1");
    $passwordStmt->execute(["id" => $userId]);
    $currentHash = $passwordStmt->fetchColumn();

    if ($currentHash === false) {
        $formError = "Hesap bulunamadı.";
    } elseif (!password_verify($currentPassword, (string) $currentHash)) {
        $formError = "Mevcut şifre doğru değil.";
    } elseif (strlen($newPassword) < 8) {
        $formError = "Yeni şifre en az 8 karakter olmalıdır.";
    } elseif ($newPassword !== $confirmPassword) {
        $formError = "Yeni şifreler birbiriyle uyuşmuyor.";
    } elseif (password_verify($newPassword, (string) $currentHash)) {
        $formError = "Yeni şifre mevcut şifreyle aynı olamaz.";
    } else {
        $updateStmt = $pdo->prepare("UPDATE users SET password_hash = :hash WHERE id = :id");
        $updateStmt->execute([
            "hash" => password_hash($newPassword, PASSWORD_DEFAULT),
            "id" => $userId
        ]);
        header("Location: profile.php?updated=1");
        exit;
    }
}

$userStmt = $pdo->prepare(
    "SELECT id, username, full_name, role, active, created_at
     FROM users WHERE id = :id LIMIT 1"
);
$userStmt->execute(["id" => $userId]);
$user = $userStmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    header("Location: logout.php");
    exit;
}

$roleLabels = [
    "super_admin" => "Süper Admin",
    "system_admin" => "Sistem Admini",
    "admin" => "Admin",
    "auditor" => "Denetçi",
    "company_user" => "Şirket Kullanıcısı"
];
$roleLabel = $roleLabels[$user["role"]] ?? $user["role"];

$assignedCompanies = [];
if ($isSuperAdmin) {
    $systemCounts = [
        "companies" => (int) $pdo->query("SELECT COUNT(*) FROM companies WHERE active = 1")->fetchColumn(),
        "users" => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE active = 1")->fetchColumn(),
        "auditors" => (int) $pdo->query("SELECT COUNT(*) FROM auditors WHERE active = 1")->fetchColumn(),
        "audits" => (int) $pdo->query("SELECT COUNT(*) FROM audits WHERE active = 1")->fetchColumn()
    ];
} else {
    $assignedStmt = $pdo->prepare(
        "SELECT companies.id, companies.company_name, companies.city, companies.sector
         FROM companies
         INNER JOIN company_admin_assignments ON company_admin_assignments.company_id = companies.id
         WHERE company_admin_assignments.admin_user_id = :user_id
           AND company_admin_assignments.active = 1
           AND companies.active = 1
         ORDER BY companies.company_name"
    );
    $assignedStmt->execute(["user_id" => $userId]);
    $assignedCompanies = $assignedStmt->fetchAll(PDO::FETCH_ASSOC);
}

$activeNav = "";

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f9fafb">
    <title>QMS Profil</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar">
        <div class="topbar-inner">
            <div class="page-title-block">
                <strong data-i18n="profileTitle">Profil</strong>
                <span><?= htmlspecialchars($user["username"], ENT_QUOTES, "UTF-8") ?> · <?= htmlspecialchars($roleLabel, ENT_QUOTES, "UTF-8") ?></span>
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
                <span class="section-kicker" data-i18n="profileKicker">Hesap</span>
                <h1><?= htmlspecialchars($user["full_name"], ENT_QUOTES, "UTF-8") ?></h1>
                <p><?= htmlspecialchars($user["username"], ENT_QUOTES, "UTF-8") ?> · <?= htmlspecialchars($roleLabel, ENT_QUOTES, "UTF-8") ?></p>
            </div>
            <a class="secondary-button" href="dashboard.php" data-i18n="dashboardLinkLabel">Panel</a>
        </section>

        <section class="dashboard-grid compact-dashboard-grid">
            <div class="dashboard-card metric-blue">
                <?= appIcon("users", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="profileUsernameLabel">Kullanıcı Adı</span>
                    <strong class="dashboard-card-number detail-card-value"><?= htmlspecialchars($user["username"], ENT_QUOTES, "UTF-8") ?></strong>
                </div>
            </div>
            <div class="dashboard-card metric-violet">
                <?= appIcon("admins", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="profileRoleLabel">Rol</span>
                    <strong class="dashboard-card-number detail-card-value"><?= htmlspecialchars($roleLabel, ENT_QUOTES, "UTF-8") ?></strong>
                </div>
            </div>
            <div class="dashboard-card metric-teal">
                <?= appIcon("check", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="profileStatusLabel">Durum</span>
                    <strong class="dashboard-card-number detail-card-value"><?= (int) $user["active"] === 1 ? '<span data-i18n="profileActiveValue">Aktif</span>' : '<span data-i18n="profileInactiveValue">Pasif</span>' ?></strong>
                </div>
            </div>
            <div class="dashboard-card metric-orange">
                <?= appIcon("clock", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="profileCreatedAtLabel">Kayıt Tarihi</span>
                    <strong class="dashboard-card-number detail-card-value"><?= htmlspecialchars((string) $user["created_at"], ENT_QUOTES, "UTF-8") ?></strong>
                </div>
            </div>
        </section>

        <section class="page-section form-panel">
            <div class="section-heading compact-heading"><div><h2 data-i18n="profilePasswordTitle">Şifre Değiştir</h2><p data-i18n="profilePasswordText">Hesabınızın şifresini buradan güncelleyebilirsiniz. Yeni şifre en az 8 karakter olmalıdır.</p></div></div>
            <?php if (isset($_GET["updated"]) && $_GET["updated"] === "1"): ?>
                <div class="form-message success" data-i18n="profileUpdatedMessage">Şifreniz güncellendi.</div>
            <?php endif; ?>
            <?php if ($formError !== ""): ?>
                <div class="form-message error"><?= htmlspecialchars($formError, ENT_QUOTES, "UTF-8") ?></div>
            <?php endif; ?>
            <form class="auditor-form" method="post" action="profile.php">
                <?= qmsCsrfField('profile') ?>
                <div class="form-grid">
                    <label class="form-field form-field-wide">
                        <span data-i18n="currentPasswordLabel">Mevcut Şifre</span>
                        <input type="password" name="current_password" autocomplete="current-password" required>
                    </label>
                    <label class="form-field">
                        <span data-i18n="newPasswordLabel">Yeni Şifre</span>
                        <input type="password" name="new_password" autocomplete="new-password" minlength="8" required>
                    </label>
                    <label class="form-field">
                        <span data-i18n="confirmPasswordLabel">Yeni Şifre (Tekrar)</span>
                        <input type="password" name="confirm_password" autocomplete="new-password" minlength="8" required>
                    </label>
                </div>
                <div class="form-actions">
                    <button class="primary-button" type="submit" data-i18n="changePasswordButton">Şifreyi Güncelle</button>
                </div>
            </form>
        </section>

        <?php if ($isSuperAdmin): ?>
            <section class="page-section">
                <div class="section-heading"><div><h2 data-i18n="profileSuperPanelTitle">Sistem Özeti</h2><p data-i18n="profileSuperPanelText">Tüm şirketler ve hesaplar üzerindeki genel durum.</p></div></div>
                <div class="record-card-grid">
                    <a class="record-card" href="super-admin-companies.php">
                        <span class="record-card-icon"><?= appIcon("companies", "") ?></span>
                        <div class="record-card-body">
                            <span class="record-card-eyebrow" data-i18n="companiesTitle">Şirketler</span>
                            <h2><?= $systemCounts["companies"] ?></h2>
                            <p data-i18n="profileSuperCompaniesText">Aktif müşteri şirketi.</p>
                        </div>
                    </a>
                    <a class="record-card" href="super-admin-admins.php">
                        <span class="record-card-icon"><?= appIcon("admins", "") ?></span>
                        <div class="record-card-body">
                            <span class="record-card-eyebrow" data-i18n="manageUsersButton">Sistem Adminleri</span>
                            <h2><?= $systemCounts["users"] ?></h2>
                            <p data-i18n="profileSuperUsersText">Aktif yönetim hesabı.</p>
                        </div>
                    </a>
                    <a class="record-card" href="auditors.php">
                        <span class="record-card-icon"><?= appIcon("users", "") ?></span>
                        <div class="record-card-body">
                            <span class="record-card-eyebrow" data-i18n="auditorsCardLabel">Denetçiler</span>
                            <h2><?= $systemCounts["auditors"] ?></h2>
                            <p data-i18n="profileSuperAuditorsText">Kayıtlı denetçi.</p>
                        </div>
                    </a>
                    <a class="record-card" href="dashboard.php">
                        <span class="record-card-icon"><?= appIcon("reports", "") ?></span>
                        <div class="record-card-body">
                            <span class="record-card-eyebrow" data-i18n="activeAuditsCardLabel">Aktif Denetimler</span>
                            <h2><?= $systemCounts["audits"] ?></h2>
                            <p data-i18n="profileSuperAuditsText">Devam eden denetim kaydı.</p>
                        </div>
                    </a>
                </div>
            </section>
        <?php else: ?>
            <section class="page-section">
                <div class="section-heading"><div><h2 data-i18n="profileAdminPanelTitle">Atandığım Şirketler</h2><p data-i18n="profileAdminPanelText">Yalnız atandığınız şirketlerin kayıtlarına erişebilirsiniz.</p></div></div>
                <?php if (!$assignedCompanies): ?>
                    <div class="empty-state" data-i18n="profileNoCompanyText">Henüz bir şirkete atanmadınız. Sistem yöneticinizle görüşün.</div>
                <?php else: ?>
                    <div class="revision-list">
                        <?php foreach ($assignedCompanies as $assigned): ?>
                            <div class="revision-item">
                                <div>
                                    <strong><?= htmlspecialchars($assigned["company_name"], ENT_QUOTES, "UTF-8") ?></strong>
                                    <span><?= htmlspecialchars(trim(($assigned["city"] ?? "") . " " . ($assigned["sector"] ?? "")), ENT_QUOTES, "UTF-8") ?></span>
                                </div>
                                <a class="secondary-button" href="company-detail.php?id=<?= (int) $assigned["id"] ?>" data-i18n="openRecordButton">Kaydı Aç</a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </main>
    <script src="assets/js/theme.js"></script>
    <script src="assets/js/language.js"></script>
    <script src="assets/js/sidebar.js"></script>
    <script src="assets/js/pwa.js"></script>
</body>
</html>
