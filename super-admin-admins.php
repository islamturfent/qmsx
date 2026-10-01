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
if (!qmsCanSession('admin.admins')) {
    header("Location: dashboard.php");
    exit;
}

$csrfToken = qmsCsrfToken('system_admins');

// Super admin; sistem admini, denetci ve sirket kullanicisi hesaplarini acar.
$allowedAccountRoles = ["system_admin", "auditor", "company_user"];
$rolesNeedingCompany = ["auditor", "company_user"];

$systemAdminFormError = "";
$systemAdminFormData = [
    "full_name" => "",
    "username" => "",
    "email" => "",
    "role" => "system_admin",
    "company_id" => 0,
    "password" => ""
];

$companyOptionsStmt = $pdo->query("SELECT id, company_name FROM companies WHERE active = 1 ORDER BY company_name");
$companyOptions = $companyOptionsStmt->fetchAll(PDO::FETCH_ASSOC);
$companyNames = array_column($companyOptions, 'company_name', 'id');

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    qmsCsrfVerify('system_admins', $_POST["csrf"] ?? null);
    $action = (string) ($_POST["action"] ?? "");

    if ($action === "create") {
    $systemAdminFormData = [
        "full_name" => trim($_POST["full_name"] ?? ""),
        "username" => trim($_POST["username"] ?? ""),
        "email" => trim($_POST["email"] ?? ""),
        "role" => (string) ($_POST["role"] ?? "system_admin"),
        "company_id" => (int) ($_POST["company_id"] ?? 0),
        "password" => (string) ($_POST["password"] ?? "")
    ];

    $needsCompany = in_array($systemAdminFormData["role"], $rolesNeedingCompany, true);

    if (
        $systemAdminFormData["full_name"] === "" ||
        $systemAdminFormData["username"] === "" ||
        $systemAdminFormData["password"] === ""
    ) {
        $systemAdminFormError = "Lütfen tüm hesap alanlarını doldurun.";
    } elseif (!in_array($systemAdminFormData["role"], $allowedAccountRoles, true)) {
        $systemAdminFormError = "Geçerli bir rol seçin.";
    } elseif ($needsCompany && !isset($companyNames[$systemAdminFormData["company_id"]])) {
        $systemAdminFormError = "Bu rol için geçerli bir şirket seçin.";
    } elseif ($systemAdminFormData["email"] !== "" && !filter_var($systemAdminFormData["email"], FILTER_VALIDATE_EMAIL)) {
        $systemAdminFormError = "Lütfen geçerli bir e-posta adresi girin.";
    } elseif (strlen($systemAdminFormData["password"]) < 8) {
        $systemAdminFormError = "Şifre en az 8 karakter olmalıdır.";
    } else {
        try {
            $pdo->beginTransaction();

            $insertAdmin = $pdo->prepare(
                "INSERT INTO users (username, password_hash, full_name, role, company_id, active)
                 VALUES (:username, :password_hash, :full_name, :role, :company_id, 1)"
            );
            $insertAdmin->execute([
                "username" => $systemAdminFormData["username"],
                "password_hash" => password_hash($systemAdminFormData["password"], PASSWORD_DEFAULT),
                "full_name" => $systemAdminFormData["full_name"],
                "role" => $systemAdminFormData["role"],
                "company_id" => $needsCompany ? $systemAdminFormData["company_id"] : null
            ]);

            $newUserId = (int) $pdo->lastInsertId();

            // Denetci hesabi icin rehber kaydi da olusturulur; boylece denetime
            // atanabilir hale gelir ve denetci listesinde gorunur.
            if ($systemAdminFormData["role"] === "auditor") {
                $nameParts = preg_split('/\s+/', trim($systemAdminFormData["full_name"])) ?: [];
                $firstName = $nameParts[0] ?? $systemAdminFormData["full_name"];
                $lastName = count($nameParts) > 1 ? implode(' ', array_slice($nameParts, 1)) : '';

                $insertAuditor = $pdo->prepare(
                    "INSERT INTO auditors (company_id, first_name, last_name, email, telefon, role, user_id, active)
                     VALUES (:company_id, :first_name, :last_name, :email, :telefon, :role, :user_id, 1)"
                );
                $insertAuditor->execute([
                    "company_id" => $systemAdminFormData["company_id"],
                    "first_name" => $firstName,
                    "last_name" => $lastName,
                    "email" => $systemAdminFormData["email"],
                    "telefon" => "",
                    "role" => "Denetçi",
                    "user_id" => $newUserId
                ]);
            }

            $pdo->commit();

            header("Location: super-admin-admins.php?account=created");
            exit;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $systemAdminFormError = "Bu kullanıcı adı zaten kullanılıyor.";
        }
        }
    } elseif ($action === "update") {
        $editId = (int) ($_POST["id"] ?? 0);
        $updateData = [
            "full_name" => trim($_POST["full_name"] ?? ""),
            "username" => trim($_POST["username"] ?? ""),
            "email" => trim($_POST["email"] ?? ""),
            "role" => (string) ($_POST["role"] ?? "system_admin"),
            "company_id" => (int) ($_POST["company_id"] ?? 0),
            "active" => !empty($_POST["active"]) ? 1 : 0,
            "password" => (string) ($_POST["password"] ?? "")
        ];

        $targetStmt = $pdo->prepare("SELECT * FROM users WHERE id = :id AND role <> 'super_admin'");
        $targetStmt->execute(["id" => $editId]);
        $target = $targetStmt->fetch(PDO::FETCH_ASSOC);

        if (!$target) {
            $systemAdminFormError = "Düzenlenecek hesap bulunamadı.";
        } else {
            $needsCompany = in_array($updateData["role"], $rolesNeedingCompany, true);

            if ($updateData["full_name"] === "" || $updateData["username"] === "") {
                $systemAdminFormError = "Lütfen ad soyad ve kullanıcı adını doldurun.";
            } elseif (!in_array($updateData["role"], $allowedAccountRoles, true)) {
                $systemAdminFormError = "Geçerli bir rol seçin.";
            } elseif ($needsCompany && !isset($companyNames[$updateData["company_id"]])) {
                $systemAdminFormError = "Bu rol için geçerli bir şirket seçin.";
            } elseif ($updateData["email"] !== "" && !filter_var($updateData["email"], FILTER_VALIDATE_EMAIL)) {
                $systemAdminFormError = "Lütfen geçerli bir e-posta adresi girin.";
            } elseif ($updateData["password"] !== "" && strlen($updateData["password"]) < 8) {
                $systemAdminFormError = "Yeni şifre en az 8 karakter olmalıdır.";
            } else {
                try {
                    $pdo->beginTransaction();

                    $dupStmt = $pdo->prepare("SELECT id FROM users WHERE username = :u AND id <> :id");
                    $dupStmt->execute(["u" => $updateData["username"], "id" => $editId]);
                    if ($dupStmt->fetchColumn()) {
                        throw new RuntimeException("duplicate");
                    }

                    if ($updateData["password"] !== "") {
                        $upd = $pdo->prepare("UPDATE users SET full_name=:full_name, username=:username, email=:email, role=:role, company_id=:company_id, active=:active, password_hash=:password_hash WHERE id=:id");
                        $upd->execute([
                            "full_name" => $updateData["full_name"],
                            "username" => $updateData["username"],
                            "email" => $updateData["email"],
                            "role" => $updateData["role"],
                            "company_id" => $needsCompany ? $updateData["company_id"] : null,
                            "active" => $updateData["active"],
                            "password_hash" => password_hash($updateData["password"], PASSWORD_DEFAULT),
                            "id" => $editId
                        ]);
                    } else {
                        $upd = $pdo->prepare("UPDATE users SET full_name=:full_name, username=:username, email=:email, role=:role, company_id=:company_id, active=:active WHERE id=:id");
                        $upd->execute([
                            "full_name" => $updateData["full_name"],
                            "username" => $updateData["username"],
                            "email" => $updateData["email"],
                            "role" => $updateData["role"],
                            "company_id" => $needsCompany ? $updateData["company_id"] : null,
                            "active" => $updateData["active"],
                            "id" => $editId
                        ]);
                    }

                    // Denetçi rehber kaydını eşzamanlı tut.
                    if ($updateData["role"] === "auditor") {
                        $nameParts = preg_split('/\s+/', trim($updateData["full_name"])) ?: [];
                        $firstName = $nameParts[0] ?? $updateData["full_name"];
                        $lastName = count($nameParts) > 1 ? implode(' ', array_slice($nameParts, 1)) : '';
                        $audStmt = $pdo->prepare("SELECT id FROM auditors WHERE user_id = :uid LIMIT 1");
                        $audStmt->execute(["uid" => $editId]);
                        if ($audStmt->fetchColumn()) {
                            $updAud = $pdo->prepare("UPDATE auditors SET company_id=:company_id, first_name=:first_name, last_name=:last_name, email=:email, active=:active WHERE user_id=:uid");
                            $updAud->execute([
                                "company_id" => $updateData["company_id"],
                                "first_name" => $firstName,
                                "last_name" => $lastName,
                                "email" => $updateData["email"],
                                "active" => $updateData["active"],
                                "uid" => $editId
                            ]);
                        } else {
                            $insAud = $pdo->prepare("INSERT INTO auditors (company_id, first_name, last_name, email, telefon, role, user_id, active) VALUES (:company_id,:first_name,:last_name,:email,'','Denetçi',:uid,:active)");
                            $insAud->execute([
                                "company_id" => $updateData["company_id"],
                                "first_name" => $firstName,
                                "last_name" => $lastName,
                                "email" => $updateData["email"],
                                "uid" => $editId,
                                "active" => $updateData["active"]
                            ]);
                        }
                    }

                    $pdo->commit();
                    header("Location: super-admin-admins.php?account=updated&edit=" . $editId);
                    exit;
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $systemAdminFormError = "Bu kullanıcı adı zaten kullanılıyor.";
                }
            }
        }
    }
}

$editUserId = (int) ($_GET["edit"] ?? 0);
$editingUser = null;
if ($editUserId > 0) {
    $eStmt = $pdo->prepare("SELECT * FROM users WHERE id = :id AND role <> 'super_admin'");
    $eStmt->execute(["id" => $editUserId]);
    $editingUser = $eStmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$accountCounts = [
    "total" => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role <> 'super_admin' AND active = 1")->fetchColumn(),
    "system_admin" => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'system_admin' AND active = 1")->fetchColumn(),
    "auditor" => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'auditor' AND active = 1")->fetchColumn(),
    "company_user" => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'company_user' AND active = 1")->fetchColumn()
];

$accountsStmt = $pdo->query(
    "SELECT users.id AS user_id, users.full_name, users.username, users.role, users.active, companies.company_name
     FROM users
     LEFT JOIN companies ON companies.id = users.company_id
     WHERE users.role <> 'super_admin'
     ORDER BY users.created_at DESC, users.id DESC
     LIMIT 20"
);
$accounts = $accountsStmt->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f9fafb">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="QuAmi">

    <title>QuAmi Sistem Adminleri</title>

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
            <h1 data-i18n="accountsTitle">Kullanıcı Hesapları</h1>
            <p><span data-i18n="accountsTotalLabel">Toplam hesap</span>: <strong><?= $accountCounts["total"] ?></strong> · <span data-i18n="manageUsersButton">Sistem Adminleri</span>: <strong><?= $accountCounts["system_admin"] ?></strong> · <span data-i18n="auditorsCardLabel">Denetçiler</span>: <strong><?= $accountCounts["auditor"] ?></strong> · <span data-i18n="roleCompanyUserOption">Şirket Kullanıcısı</span>: <strong><?= $accountCounts["company_user"] ?></strong></p>
        </section>

        <section class="super-admin-console">
            <div class="console-card">
                <?php if (!$editingUser): ?>
                <div class="section-heading compact-heading">
                    <div>
                        <h3 data-i18n="createAccountTitle">Hesap Oluştur</h3>
                        <p data-i18n="createAccountText">Sistem admini, denetçi veya şirket kullanıcısı hesabı açın.</p>
                    </div>
                </div>

                <?php if (isset($_GET["account"]) && $_GET["account"] === "created"): ?>
                    <div class="form-message success" data-i18n="accountCreatedMessage">Hesap oluşturuldu.</div>
                <?php endif; ?>
                <?php if (isset($_GET["account"]) && $_GET["account"] === "updated"): ?>
                    <div class="form-message success" data-i18n="accountUpdatedMessage">Hesap güncellendi.</div>
                <?php endif; ?>

                <?php if ($systemAdminFormError !== ""): ?>
                    <div class="form-message error"><?= htmlspecialchars($systemAdminFormError, ENT_QUOTES, "UTF-8") ?></div>
                <?php endif; ?>

                <form class="auditor-form" method="post" action="super-admin-admins.php">
                <?= qmsCsrfField('system_admins') ?>
                <input type="hidden" name="action" value="create">
                    <div class="form-grid">
                        <label class="form-field">
                            <span data-i18n="fullNameLabel">Ad Soyad</span>
                            <input type="text" name="full_name" value="<?= htmlspecialchars($systemAdminFormData["full_name"], ENT_QUOTES, "UTF-8") ?>" required>
                        </label>

                        <label class="form-field">
                            <span data-i18n="usernameLabel">Kullanıcı Adı</span>
                            <input type="text" name="username" value="<?= htmlspecialchars($systemAdminFormData["username"], ENT_QUOTES, "UTF-8") ?>" required>
                        </label>

                        <label class="form-field">
                            <span data-i18n="accountRoleLabel">Rol</span>
                            <select name="role" required>
                                <option value="system_admin" <?= $systemAdminFormData["role"] === "system_admin" ? "selected" : "" ?> data-i18n="roleSystemAdminOption">Sistem Admini</option>
                                <option value="auditor" <?= $systemAdminFormData["role"] === "auditor" ? "selected" : "" ?> data-i18n="roleAuditorOption">Denetçi</option>
                                <option value="company_user" <?= $systemAdminFormData["role"] === "company_user" ? "selected" : "" ?> data-i18n="roleCompanyUserOption">Şirket Kullanıcısı</option>
                            </select>
                        </label>

                        <label class="form-field">
                            <span data-i18n="accountCompanyLabel">Şirket</span>
                            <select name="company_id">
                                <option value="0" data-i18n="accountNoCompanyOption">— (sistem admini için gerekmez)</option>
                                <?php foreach ($companyOptions as $companyOption): ?>
                                    <option value="<?= (int) $companyOption["id"] ?>" <?= (int) $systemAdminFormData["company_id"] === (int) $companyOption["id"] ? "selected" : "" ?>><?= htmlspecialchars($companyOption["company_name"], ENT_QUOTES, "UTF-8") ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small data-i18n="accountCompanyHelp">Denetçi ve şirket kullanıcısı için zorunludur.</small>
                        </label>

                        <label class="form-field form-field-wide">
                            <span data-i18n="accountEmailLabel">E-posta (isteğe bağlı)</span>
                            <input type="email" name="email" value="<?= htmlspecialchars($systemAdminFormData["email"], ENT_QUOTES, "UTF-8") ?>">
                        </label>

                        <label class="form-field form-field-wide">
                            <span data-i18n="passwordLabel">Şifre</span>
                            <input type="password" name="password" minlength="8" required>
                        </label>
                    </div>

                    <div class="form-actions">
                        <button class="primary-button" type="submit" data-i18n="createAccountButton">Hesap Oluştur</button>
                    </div>
                </form>
                <?php else: ?>
                <div class="section-heading compact-heading">
                    <div>
                        <h3 data-i18n="editAccountTitle">Hesabı Düzenle</h3>
                        <p data-i18n="editAccountText">Bilgileri, rolü, durumu ve şifreyi (eski şifre gerekmez) güncelleyin.</p>
                    </div>
                </div>

                <?php if (isset($_GET["account"]) && $_GET["account"] === "updated"): ?>
                    <div class="form-message success" data-i18n="accountUpdatedMessage">Hesap güncellendi.</div>
                <?php endif; ?>

                <?php if ($systemAdminFormError !== ""): ?>
                    <div class="form-message error"><?= htmlspecialchars($systemAdminFormError, ENT_QUOTES, "UTF-8") ?></div>
                <?php endif; ?>

                <form class="auditor-form" method="post" action="super-admin-admins.php">
                <?= qmsCsrfField('system_admins') ?>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" value="<?= (int) $editingUser['id'] ?>">
                    <div class="form-grid">
                        <label class="form-field">
                            <span data-i18n="fullNameLabel">Ad Soyad</span>
                            <input type="text" name="full_name" value="<?= htmlspecialchars((string) $editingUser['full_name'], ENT_QUOTES, "UTF-8") ?>" required>
                        </label>

                        <label class="form-field">
                            <span data-i18n="usernameLabel">Kullanıcı Adı</span>
                            <input type="text" name="username" value="<?= htmlspecialchars((string) $editingUser['username'], ENT_QUOTES, "UTF-8") ?>" required>
                        </label>

                        <label class="form-field">
                            <span data-i18n="accountRoleLabel">Rol</span>
                            <select name="role" required>
                                <option value="system_admin" <?= ($editingUser['role'] ?? '') === "system_admin" ? "selected" : "" ?> data-i18n="roleSystemAdminOption">Sistem Admini</option>
                                <option value="auditor" <?= ($editingUser['role'] ?? '') === "auditor" ? "selected" : "" ?> data-i18n="roleAuditorOption">Denetçi</option>
                                <option value="company_user" <?= ($editingUser['role'] ?? '') === "company_user" ? "selected" : "" ?> data-i18n="roleCompanyUserOption">Şirket Kullanıcısı</option>
                            </select>
                        </label>

                        <label class="form-field">
                            <span data-i18n="accountCompanyLabel">Şirket</span>
                            <select name="company_id">
                                <option value="0" data-i18n="accountNoCompanyOption">— (sistem admini için gerekmez)</option>
                                <?php foreach ($companyOptions as $companyOption): ?>
                                    <option value="<?= (int) $companyOption["id"] ?>" <?= (int) ($editingUser['company_id'] ?? 0) === (int) $companyOption["id"] ? "selected" : "" ?>><?= htmlspecialchars($companyOption["company_name"], ENT_QUOTES, "UTF-8") ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small data-i18n="accountCompanyHelp">Denetçi ve şirket kullanıcısı için zorunludur.</small>
                        </label>

                        <label class="form-field form-field-wide">
                            <span data-i18n="accountEmailLabel">E-posta (isteğe bağlı)</span>
                            <input type="email" name="email" value="<?= htmlspecialchars((string) $editingUser['email'], ENT_QUOTES, "UTF-8") ?>">
                        </label>

                        <label class="form-field form-field-wide">
                            <span data-i18n="newPasswordLabel">Yeni Şifre (boş bırakılırsa korunur)</span>
                            <input type="password" name="password" minlength="8" autocomplete="new-password">
                        </label>

                        <label class="form-field form-field-wide"><span data-i18n="accountActiveLabel">Aktif Hesap</span><label class="toggle-field"><input type="checkbox" name="active" value="1" <?= (int) ($editingUser['active'] ?? 0) === 1 ? 'checked' : '' ?>><span class="toggle-slider"></span></label></label>
                    </div>

                    <div class="form-actions" style="gap:12px;">
                        <a class="secondary-button" href="super-admin-admins.php" data-i18n="editCancelButton">İptal</a>
                        <button class="primary-button" type="submit" data-i18n="editAccountSaveButton">Hesabı Güncelle</button>
                    </div>
                </form>
                <?php endif; ?>
            </div>

            <div class="console-card">
                <div class="section-heading compact-heading">
                    <div>
                        <h3 data-i18n="accountsTitle">Kullanıcı Hesapları</h3>
                        <p data-i18n="accountsText">Sistem admini, denetçi ve şirket kullanıcısı hesapları.</p>
                    </div>
                </div>

                <div class="admin-list">
                    <?php if (count($accounts) === 0): ?>
                        <div class="empty-state" data-i18n="noAccountsText">Henüz hesap oluşturulmadı.</div>
                    <?php endif; ?>

                    <?php foreach ($accounts as $account): ?>
                        <div class="admin-list-item">
                            <div>
                                <strong><?= htmlspecialchars($account["full_name"], ENT_QUOTES, "UTF-8") ?></strong>
                                <span><?= htmlspecialchars($account["username"], ENT_QUOTES, "UTF-8") ?> · <?= htmlspecialchars(appRoleLabel((string) $account["role"]), ENT_QUOTES, "UTF-8") ?><?= $account["company_name"] ? " · " . htmlspecialchars($account["company_name"], ENT_QUOTES, "UTF-8") : "" ?></span>
                            </div>
                            <?php if ((int) $account["active"] === 1): ?>
                                <span class="status-pill" data-i18n="activeStatusLabel">Aktif</span>
                            <?php else: ?>
                                <span class="status-badge" data-i18n="profileInactiveValue">Pasif</span>
                            <?php endif; ?>
                            <a class="secondary-button" href="super-admin-admins.php?edit=<?= (int) $account['user_id'] ?>" data-i18n="editAccountButton">Düzenle</a>
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
