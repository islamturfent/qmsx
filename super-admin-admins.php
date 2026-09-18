<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

if (($_SESSION["qms_role"] ?? "") !== "super_admin") {
    header("Location: dashboard.php");
    exit;
}

require_once __DIR__ . '/config/database.php';

$systemAdminFormError = "";
$systemAdminFormData = [
    "full_name" => "",
    "username" => "",
    "password" => ""
];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $systemAdminFormData = [
        "full_name" => trim($_POST["full_name"] ?? ""),
        "username" => trim($_POST["username"] ?? ""),
        "password" => trim($_POST["password"] ?? "")
    ];

    if (
        $systemAdminFormData["full_name"] === "" ||
        $systemAdminFormData["username"] === "" ||
        $systemAdminFormData["password"] === ""
    ) {
        $systemAdminFormError = "Lütfen tüm admin alanlarını doldurun.";
    } elseif (strlen($systemAdminFormData["password"]) < 6) {
        $systemAdminFormError = "Şifre en az 6 karakter olmalıdır.";
    } else {
        try {
            $insertAdmin = $pdo->prepare(
                "INSERT INTO users (username, password_hash, full_name, role, active)
                 VALUES (:username, :password_hash, :full_name, 'system_admin', 1)"
            );
            $insertAdmin->execute([
                "username" => $systemAdminFormData["username"],
                "password_hash" => password_hash($systemAdminFormData["password"], PASSWORD_DEFAULT),
                "full_name" => $systemAdminFormData["full_name"]
            ]);

            header("Location: super-admin-admins.php?system_admin=created");
            exit;
        } catch (PDOException $e) {
            $systemAdminFormError = "Bu kullanıcı adı zaten kullanılıyor.";
        }
    }
}

$systemAdminCountStmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'system_admin'");
$systemAdminCount = (int) $systemAdminCountStmt->fetchColumn();

$systemAdminsStmt = $pdo->query(
    "SELECT full_name, username, active
     FROM users
     WHERE role = 'system_admin'
     ORDER BY created_at DESC, id DESC
     LIMIT 12"
);
$systemAdmins = $systemAdminsStmt->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f9fafb">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="QMS">

    <title>QMS Sistem Adminleri</title>

    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="assets/icons/qms-icon-192.png">
    <link rel="stylesheet" href="assets/css/style.css">
</head>

<body class="has-sidebar">
    <?php $activeNav = "admins"; require __DIR__ . '/includes/app-sidebar.php'; ?>

    <header class="topbar">
        <div class="topbar-inner">
            <a class="brand" href="dashboard.php">
                <div class="brand-icon">Q</div>
                <div class="brand-text">
                    <strong>QMS</strong>
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
            <h1 data-i18n="systemAdminsTitle">Atanan Adminler</h1>
            <p><span data-i18n="systemAdminsText">Toplam sistem admini</span>: <strong><?= $systemAdminCount ?></strong></p>
        </section>

        <section class="super-admin-console">
            <div class="console-card">
                <div class="section-heading compact-heading">
                    <div>
                        <h3 data-i18n="createSystemAdminTitle">Sistem Admini Ata</h3>
                        <p data-i18n="createSystemAdminText">Bu adminler tüm şirketlerde denetim süreçlerini yönetebilir.</p>
                    </div>
                </div>

                <?php if (isset($_GET["system_admin"]) && $_GET["system_admin"] === "created"): ?>
                    <div class="form-message success" data-i18n="systemAdminCreatedMessage">Sistem admini başarıyla oluşturuldu.</div>
                <?php endif; ?>

                <?php if ($systemAdminFormError !== ""): ?>
                    <div class="form-message error"><?= htmlspecialchars($systemAdminFormError, ENT_QUOTES, "UTF-8") ?></div>
                <?php endif; ?>

                <form class="auditor-form" method="post" action="super-admin-admins.php">
                    <div class="form-grid">
                        <label class="form-field">
                            <span data-i18n="fullNameLabel">Ad Soyad</span>
                            <input type="text" name="full_name" value="<?= htmlspecialchars($systemAdminFormData["full_name"], ENT_QUOTES, "UTF-8") ?>" required>
                        </label>

                        <label class="form-field">
                            <span data-i18n="usernameLabel">Kullanıcı Adı</span>
                            <input type="text" name="username" value="<?= htmlspecialchars($systemAdminFormData["username"], ENT_QUOTES, "UTF-8") ?>" required>
                        </label>

                        <label class="form-field form-field-wide">
                            <span data-i18n="passwordLabel">Şifre</span>
                            <input type="password" name="password" required>
                        </label>
                    </div>

                    <div class="form-actions">
                        <button class="primary-button" type="submit" data-i18n="createSystemAdminButton">Admin Oluştur</button>
                    </div>
                </form>
            </div>

            <div class="console-card">
                <div class="section-heading compact-heading">
                    <div>
                        <h3 data-i18n="systemAdminsTitle">Atanan Adminler</h3>
                        <p data-i18n="createSystemAdminText">Bu adminler tüm şirketlerde denetim süreçlerini yönetebilir.</p>
                    </div>
                </div>

                <div class="admin-list">
                    <?php if (count($systemAdmins) === 0): ?>
                        <div class="empty-state" data-i18n="noSystemAdminsText">Henüz sistem admini oluşturulmadı.</div>
                    <?php endif; ?>

                    <?php foreach ($systemAdmins as $admin): ?>
                        <div class="admin-list-item">
                            <div>
                                <strong><?= htmlspecialchars($admin["full_name"], ENT_QUOTES, "UTF-8") ?></strong>
                                <span><?= htmlspecialchars($admin["username"], ENT_QUOTES, "UTF-8") ?></span>
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
