<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/csrf.php';

$csrfToken = qmsCsrfToken('auditor_create');

$userId = (int) ($_SESSION["qms_user_id"] ?? 0);
$isSuperAdmin = ($_SESSION["qms_role"] ?? "") === "super_admin";

// Sistem admini yalniz atandigi sirketlere denetci ekleyebilir.
$companySql = "SELECT id, company_name FROM companies WHERE active = 1";
$companyParams = [];
if (!$isSuperAdmin) {
    $companySql .= " AND EXISTS (SELECT 1 FROM company_admin_assignments
                WHERE company_admin_assignments.company_id = companies.id
                  AND company_admin_assignments.admin_user_id = :user_id
                  AND company_admin_assignments.active = 1)";
    $companyParams["user_id"] = $userId;
}
$companySql .= " ORDER BY company_name ASC";

$companiesStmt = $pdo->prepare($companySql);
$companiesStmt->execute($companyParams);
$companies = $companiesStmt->fetchAll(PDO::FETCH_ASSOC);
$allowedCompanyIds = array_map('intval', array_column($companies, 'id'));

$formError = "";
$formData = [
    "company_id" => "",
    "first_name" => "",
    "last_name" => "",
    "email" => "",
    "telefon" => "",
    "role" => ""
];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    qmsCsrfVerify('auditor_create', $_POST["csrf"] ?? null);

    $formData = [
        "company_id" => (int) ($_POST["company_id"] ?? 0),
        "first_name" => trim($_POST["first_name"] ?? ""),
        "last_name" => trim($_POST["last_name"] ?? ""),
        "email" => trim($_POST["email"] ?? ""),
        "telefon" => trim($_POST["telefon"] ?? ""),
        "role" => trim($_POST["role"] ?? "")
    ];

    if (
        $formData["company_id"] <= 0 || !in_array($formData["company_id"], $allowedCompanyIds, true) ||
        $formData["first_name"] === "" ||
        $formData["last_name"] === "" || $formData["email"] === "" ||
        $formData["telefon"] === "" || $formData["role"] === ""
    ) {
        $formError = "Lütfen şirket dahil tüm alanları doldurun.";
    } elseif (!filter_var($formData["email"], FILTER_VALIDATE_EMAIL)) {
        $formError = "Lütfen geçerli bir e-posta adresi girin.";
    } else {
        $insertStmt = $pdo->prepare(
            "INSERT INTO auditors (company_id, first_name, last_name, email, telefon, role, active)
             VALUES (:company_id, :first_name, :last_name, :email, :telefon, :role, 1)"
        );
        $insertStmt->execute($formData);
        header("Location: auditors.php?auditor=created");
        exit;
    }
}

$activeNav = "auditors";

?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f9fafb">
    <title>QMS Yeni Denetçi</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/style.css">
</head>

<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>

    <header class="topbar">
        <div class="topbar-inner">
            <div class="page-title-block">
                <strong data-i18n="newAuditorTitle">Yeni Denetçi</strong>
                <span data-i18n="newAuditorText">Denetçi bilgilerini girerek sisteme yeni kayıt ekleyin.</span>
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
                <span class="section-kicker" data-i18n="sidebarOperationsLabel">Operasyonlar</span>
                <h1 data-i18n="newAuditorTitle">Yeni Denetçi</h1>
                <p data-i18n="newAuditorText">Denetçi bilgilerini girerek sisteme yeni kayıt ekleyin.</p>
            </div>
            <a class="secondary-button" href="auditors.php" data-i18n="backToListButton">Listeye Dön</a>
        </section>

        <section class="form-panel">
            <?php if ($formError !== ""): ?>
                <div class="form-message error"><?= htmlspecialchars($formError, ENT_QUOTES, "UTF-8") ?></div>
            <?php endif; ?>

            <form class="auditor-form" method="post" action="auditor-create.php">
                <?= qmsCsrfField('auditor_create') ?>
                <div class="form-grid">
                    <label class="form-field form-field-wide">
                        <span data-i18n="companySelectLabel">Şirket</span>
                        <select name="company_id" required>
                            <option value="" data-i18n="selectCompanyOption">Şirket seçin</option>
                            <?php foreach ($companies as $company): ?>
                                <option value="<?= (int) $company["id"] ?>" <?= (int) $formData["company_id"] === (int) $company["id"] ? "selected" : "" ?>>
                                    <?= htmlspecialchars($company["company_name"], ENT_QUOTES, "UTF-8") ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="form-field">
                        <span data-i18n="firstNameLabel">Ad</span>
                        <input type="text" name="first_name" value="<?= htmlspecialchars($formData["first_name"], ENT_QUOTES, "UTF-8") ?>" required>
                    </label>
                    <label class="form-field">
                        <span data-i18n="lastNameLabel">Soyad</span>
                        <input type="text" name="last_name" value="<?= htmlspecialchars($formData["last_name"], ENT_QUOTES, "UTF-8") ?>" required>
                    </label>
                    <label class="form-field">
                        <span data-i18n="emailLabel">E-posta</span>
                        <input type="email" name="email" value="<?= htmlspecialchars($formData["email"], ENT_QUOTES, "UTF-8") ?>" required>
                    </label>
                    <label class="form-field">
                        <span data-i18n="phoneLabel">Telefon</span>
                        <input type="text" name="telefon" value="<?= htmlspecialchars($formData["telefon"], ENT_QUOTES, "UTF-8") ?>" required>
                    </label>
                    <label class="form-field form-field-wide">
                        <span data-i18n="roleLabel">Rol</span>
                        <input type="text" name="role" value="<?= htmlspecialchars($formData["role"], ENT_QUOTES, "UTF-8") ?>" required>
                    </label>
                </div>
                <div class="form-actions">
                    <button class="primary-button" type="submit" data-i18n="saveAuditorButton">Denetçi Kaydet</button>
                </div>
            </form>
        </section>
    </main>

    <script src="assets/js/theme.js"></script>
    <script src="assets/js/language.js"></script>
    <script src="assets/js/sidebar.js"></script>
    <script src="assets/js/pwa.js"></script>
</body>
</html>
