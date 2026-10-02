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
if (!qmsCanSession('admin.companies')) {
    header("Location: dashboard.php");
    exit;
}

$csrfToken = qmsCsrfToken('companies');

$companyFormError = "";
$companyFormData = [
    "company_name" => "",
    "tax_number" => "",
    "sector" => "",
    "city" => "",
    "contact_email" => "",
    "website" => "",
    "phone" => "",
    "address" => "",
    "contact_person" => "",
    "brand_name" => ""
];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    qmsCsrfVerify('companies', $_POST["csrf"] ?? null);

    $companyFormData = [
        "company_name" => trim($_POST["company_name"] ?? ""),
        "tax_number" => trim($_POST["tax_number"] ?? ""),
        "sector" => trim($_POST["sector"] ?? ""),
        "city" => trim($_POST["city"] ?? ""),
        "contact_email" => trim($_POST["contact_email"] ?? ""),
        "website" => trim($_POST["website"] ?? ""),
        "phone" => trim($_POST["phone"] ?? ""),
        "address" => trim($_POST["address"] ?? ""),
        "contact_person" => trim($_POST["contact_person"] ?? ""),
        "brand_name" => trim($_POST["brand_name"] ?? "")
    ];

    if ($companyFormData["company_name"] === "") {
        $companyFormError = "Lütfen şirket adını girin.";
    } elseif ($companyFormData["contact_email"] === "") {
        $companyFormError = "Lütfen şirket e-postasını girin.";
    } elseif (!filter_var($companyFormData["contact_email"], FILTER_VALIDATE_EMAIL)) {
        $companyFormError = "Lütfen geçerli bir şirket e-posta adresi girin.";
    } else {
        $insertCompany = $pdo->prepare(
            "INSERT INTO companies (company_name, tax_number, sector, city, contact_email, website, phone, address, contact_person, brand_name, active)
             VALUES (:company_name, :tax_number, :sector, :city, :contact_email, :website, :phone, :address, :contact_person, :brand_name, 1)"
        );
        $insertCompany->execute($companyFormData);

        header("Location: super-admin-companies.php?company=created");
        exit;
    }
}

$companyCountStmt = $pdo->query("SELECT COUNT(*) FROM companies");
$companyCount = (int) $companyCountStmt->fetchColumn();

$companiesStmt = $pdo->query(
    "SELECT id, company_name, tax_number, sector, city, contact_email, active
     FROM companies
     ORDER BY created_at DESC, id DESC
     LIMIT 12"
);
$companies = $companiesStmt->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f9fafb">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="QuAmi">

    <title>QuAmi Şirket Yönetimi</title>

    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="assets/icons/qms-icon-192.png">
    <link rel="stylesheet" href="assets/css/style.css">
</head>

<body class="has-sidebar">
    <?php $activeNav = "companies"; require __DIR__ . '/includes/app-sidebar.php'; ?>

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
            <h1 data-i18n="companiesTitle">Kayıtlı Şirketler</h1>
            <p><span data-i18n="companiesText">Toplam şirket</span>: <strong><?= $companyCount ?></strong></p>
        </section>

        <section class="super-admin-console">
            <div class="console-card">
                <div class="section-heading compact-heading">
                    <div>
                        <h3 data-i18n="createCompanyTitle">Şirket Oluştur</h3>
                        <p data-i18n="createCompanyText">SaaS yapısı için sisteme yeni müşteri şirket ekleyin.</p>
                    </div>
                </div>

                <?php if (isset($_GET["company"]) && $_GET["company"] === "created"): ?>
                    <div class="form-message success" data-i18n="companyCreatedMessage">
                        Şirket başarıyla oluşturuldu.
                    </div>
                <?php endif; ?>

                <?php if ($companyFormError !== ""): ?>
                    <div class="form-message error">
                        <?= htmlspecialchars($companyFormError, ENT_QUOTES, "UTF-8") ?>
                    </div>
                <?php endif; ?>

                <form class="auditor-form" method="post" action="super-admin-companies.php">
                <?= qmsCsrfField('companies') ?>
                    <div class="form-grid">
                        <label class="form-field">
                            <span data-i18n="companyNameLabel">Şirket Adı</span>
                            <input type="text" name="company_name" value="<?= htmlspecialchars($companyFormData["company_name"], ENT_QUOTES, "UTF-8") ?>" required>
                        </label>

                        <label class="form-field">
                            <span data-i18n="taxNumberLabel">Vergi No</span>
                            <input type="text" name="tax_number" value="<?= htmlspecialchars($companyFormData["tax_number"], ENT_QUOTES, "UTF-8") ?>">
                        </label>

                        <label class="form-field">
                            <span data-i18n="sectorLabel">Sektör</span>
                            <input type="text" name="sector" value="<?= htmlspecialchars($companyFormData["sector"], ENT_QUOTES, "UTF-8") ?>">
                        </label>

                        <label class="form-field">
                            <span data-i18n="cityLabel">Şehir</span>
                            <input type="text" name="city" value="<?= htmlspecialchars($companyFormData["city"], ENT_QUOTES, "UTF-8") ?>">
                        </label>

                        <label class="form-field form-field-wide">
                            <span data-i18n="companyEmailLabel">Şirket E-postası *</span>
                            <input type="email" name="contact_email" value="<?= htmlspecialchars($companyFormData["contact_email"], ENT_QUOTES, "UTF-8") ?>" required>
                        </label>

                        <label class="form-field"><span data-i18n="companyWebsiteLabel">Web Sitesi</span><input type="text" name="website" value="<?= htmlspecialchars($companyFormData["website"], ENT_QUOTES, "UTF-8") ?>"></label>
                        <label class="form-field"><span data-i18n="companyPhoneLabel">Telefon</span><input type="text" name="phone" value="<?= htmlspecialchars($companyFormData["phone"], ENT_QUOTES, "UTF-8") ?>"></label>
                        <label class="form-field form-field-wide"><span data-i18n="companyAddressLabel">Adres</span><input type="text" name="address" value="<?= htmlspecialchars($companyFormData["address"], ENT_QUOTES, "UTF-8") ?>"></label>
                        <label class="form-field"><span data-i18n="contactPersonLabel">Yetkili Kişi</span><input type="text" name="contact_person" value="<?= htmlspecialchars($companyFormData["contact_person"], ENT_QUOTES, "UTF-8") ?>"></label>
                        <label class="form-field"><span data-i18n="brandNameLabel">Marka Adı</span><input type="text" name="brand_name" value="<?= htmlspecialchars($companyFormData["brand_name"], ENT_QUOTES, "UTF-8") ?>"></label>
                        <small class="form-wide-hint" data-i18n="companyOptionalHint">Şirket adı ve e-postası zorunludur; diğer alanlar isteğe bağlıdır.</small>
                    </div>

                    <div class="form-actions">
                        <button class="primary-button" type="submit" data-i18n="createCompanyButton">Şirket Oluştur</button>
                    </div>
                </form>
            </div>

            <div class="console-card">
                <div class="section-heading compact-heading">
                    <div>
                        <h3 data-i18n="companiesTitle">Kayıtlı Şirketler</h3>
                        <p data-i18n="createCompanyText">SaaS yapısı için sisteme yeni müşteri şirket ekleyin.</p>
                    </div>
                </div>

                <div class="admin-list">
                    <?php if (count($companies) === 0): ?>
                        <div class="empty-state" data-i18n="noCompaniesText">Henüz şirket oluşturulmadı.</div>
                    <?php endif; ?>

                    <?php foreach ($companies as $company): ?>
                        <div class="admin-list-item">
                            <div>
                                <strong><?= htmlspecialchars($company["company_name"], ENT_QUOTES, "UTF-8") ?></strong>
                                <span><?= htmlspecialchars(trim(($company["city"] ?? "") . " " . ($company["sector"] ?? "")), ENT_QUOTES, "UTF-8") ?></span>
                            </div>
                            <div class="admin-list-actions">
                                <span class="status-pill" data-i18n="activeStatusLabel">Aktif</span>
                                <a class="secondary-button" href="company-profile.php?id=<?= (int) $company["id"] ?>" data-i18n="companyProfileButton">Profil</a>
                                <a class="secondary-button" href="company-detail.php?id=<?= (int) $company["id"] ?>" data-i18n="companyWorkspaceButton">Çalışma Alanı</a>
                            </div>
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
