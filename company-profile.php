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

qmsRequirePermission('admin.companies');

$companyId = (int) ($_GET["id"] ?? 0);
if ($companyId <= 0) {
    header("Location: super-admin-companies.php");
    exit;
}

$userId = (int) ($_SESSION["qms_user_id"] ?? 0);

$loadCompany = static function () use ($pdo, $companyId, $userId) {
    $sql = "SELECT * FROM companies WHERE id = ?";
    $params = [$companyId];
    $scope = qmsCompanyScope('companies.id', qmsVisibleCompanyIds($pdo, $userId, qmsCurrentRole()));
    $sql .= $scope['sql'];
    $params = array_merge($params, $scope['params']);
    $sql .= " LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch(PDO::FETCH_ASSOC);
};

$company = $loadCompany();
if (!$company) {
    header("Location: super-admin-companies.php");
    exit;
}

$formError = "";
$formOk = "";
$csrfScope = 'company_profile';

if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["form_type"] ?? "") === "update_profile") {
    qmsCsrfVerify($csrfScope, $_POST["csrf"] ?? null);

    $name = trim($_POST["company_name"] ?? "");
    $email = trim($_POST["contact_email"] ?? "");

    if ($name === "") {
        $formError = "Şirket adı zorunludur.";
    } elseif ($email === "") {
        $formError = "Şirket e-postası zorunludur.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $formError = "Lütfen geçerli bir şirket e-posta adresi girin.";
    } else {
        // Logo yukleme (opsiyonel).
        $logoUpdate = "";
        $removeLogo = !empty($_POST["logo_remove"]) ? true : false;

        if (!$removeLogo && !empty($_FILES["logo_file"]["tmp_name"]) && is_uploaded_file($_FILES["logo_file"]["tmp_name"])) {
            $ext = strtolower(pathinfo((string) $_FILES["logo_file"]["name"], PATHINFO_EXTENSION));
            if (in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'svg', 'webp'], true)) {
                $dir = __DIR__ . '/storage/company-logos';
                if (!is_dir($dir)) {
                    @mkdir($dir, 0775, true);
                }
                $logoName = 'company-' . $companyId . '-' . bin2hex(random_bytes(6)) . '.' . $ext;
                if (@move_uploaded_file($_FILES["logo_file"]["tmp_name"], $dir . '/' . $logoName)) {
                    $logoUpdate = $logoName;
                }
            }
        }

        try {
            $fields = "company_name=:company_name, contact_email=:contact_email, tax_number=:tax_number, sector=:sector, city=:city, website=:website, phone=:phone, address=:address, contact_person=:contact_person, brand_name=:brand_name, brand_slogan=:brand_slogan, brand_description=:brand_description";
            if ($logoUpdate !== "") {
                $fields .= ", logo_file=:logo_file";
            } elseif ($removeLogo) {
                $fields .= ", logo_file=NULL";
            }

            $upd = $pdo->prepare("UPDATE companies SET $fields WHERE id=:id");
            $data = [
                "company_name" => $name,
                "contact_email" => $email,
                "tax_number" => trim($_POST["tax_number"] ?? ""),
                "sector" => trim($_POST["sector"] ?? ""),
                "city" => trim($_POST["city"] ?? ""),
                "website" => trim($_POST["website"] ?? ""),
                "phone" => trim($_POST["phone"] ?? ""),
                "address" => trim($_POST["address"] ?? ""),
                "contact_person" => trim($_POST["contact_person"] ?? ""),
                "brand_name" => trim($_POST["brand_name"] ?? ""),
                "brand_slogan" => trim($_POST["brand_slogan"] ?? ""),
                "brand_description" => trim($_POST["brand_description"] ?? ""),
                "id" => $companyId,
            ];
            if ($logoUpdate !== "") {
                $data["logo_file"] = $logoUpdate;
            }
            $upd->execute($data);

            // Eski logo dosyasini temizle.
            if (($logoUpdate !== "" || $removeLogo) && !empty($company["logo_file"])) {
                $oldPath = __DIR__ . '/storage/company-logos/' . basename((string) $company["logo_file"]);
                if (is_file($oldPath)) {
                    @unlink($oldPath);
                }
            }

            $formOk = "Şirket profili güncellendi.";
            $company = $loadCompany();
        } catch (Throwable $e) {
            $formError = "Profil güncellenemedi.";
        }
    }
}

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f9fafb">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="QuAmi">
    <title>QuAmi Şirket Profili</title>
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
                <div class="brand-text"><strong>QuAmi</strong><span>Quality Management System</span></div>
            </a>
            <div class="topbar-actions">
                <button class="topbar-button" id="languageToggle" type="button">EN</button>
                <button class="topbar-button" id="themeToggle" type="button" aria-label="Tema değiştir">🌙</button>
                <a class="topbar-button topbar-link" href="logout.php" data-i18n="logoutLabel">Çıkış</a>
            </div>
        </div>
    </header>

    <main class="page-container">
        <section class="welcome-card">
            <div class="page-heading-actions">
                <div>
                    <span class="section-kicker" data-i18n="companyProfileKicker">Şirket Profili</span>
                    <h1><?= htmlspecialchars((string) ($company["company_name"] ?? ''), ENT_QUOTES, "UTF-8") ?></h1>
                    <p data-i18n="companyProfileText">Şirket kimlik ve marka bilgilerini düzenleyin; logo yükleyin.</p>
                </div>
                <div class="page-heading-buttons">
                    <a class="secondary-button" href="company-detail.php?id=<?= (int) $companyId ?>" data-i18n="companyWorkspaceButton">Çalışma Alanı</a>
                    <a class="secondary-button" href="super-admin-companies.php" data-i18n="companiesTitle">Kayıtlı Şirketler</a>
                </div>
            </div>
        </section>

        <?php if ($formOk !== ""): ?><div class="form-message success"><?= htmlspecialchars($formOk, ENT_QUOTES, "UTF-8") ?></div><?php endif; ?>
        <?php if ($formError !== ""): ?><div class="form-message error"><?= htmlspecialchars($formError, ENT_QUOTES, "UTF-8") ?></div><?php endif; ?>

        <section class="super-admin-console">
            <div class="console-card">
                <div class="section-heading compact-heading">
                    <div>
                        <h3 data-i18n="companyBrandTitle">Şirket Kimlik & Marka Bilgileri</h3>
                        <p data-i18n="companyBrandText">Şirket adı ve e-postası zorunludur; diğer alanlar isteğe bağlıdır.</p>
                    </div>
                </div>

                <form class="auditor-form" method="post" action="company-profile.php?id=<?= (int) $companyId ?>" enctype="multipart/form-data">
                    <?= qmsCsrfField($csrfScope) ?>
                    <input type="hidden" name="form_type" value="update_profile">

                    <!-- Logo -->
                    <div class="company-logo-block">
                        <div class="company-logo-preview">
                            <?php if (!empty($company["logo_file"]) && file_exists(__DIR__ . '/storage/company-logos/' . basename((string) $company["logo_file"]))): ?>
                                <img src="storage/company-logos/<?= htmlspecialchars((string) $company["logo_file"], ENT_QUOTES, "UTF-8") ?>" alt="Şirket logosu">
                            <?php else: ?>
                                <div class="company-logo-placeholder"><?= htmlspecialchars(mb_strtoupper(mb_substr((string) ($company["company_name"] ?? 'Q'), 0, 1)), ENT_QUOTES, "UTF-8") ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="company-logo-controls">
                            <label class="form-field">
                                <span data-i18n="companyLogoLabel">Şirket Logosu</span>
                                <input type="file" name="logo_file" accept="image/png,image/jpeg,image/svg+xml,image/gif,image/webp">
                                <small data-i18n="companyLogoHint">PNG/JPG/SVG · en fazla ~2 MB</small>
                            </label>
                            <?php if (!empty($company["logo_file"])): ?>
                                <label class="toggle-field toggle-inline"><input type="checkbox" name="logo_remove" value="1"><span class="toggle-slider"></span><span data-i18n="logoRemoveLabel">Logoyu Kaldır</span></label>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="form-grid">
                        <label class="form-field">
                            <span data-i18n="companyNameLabel">Şirket Adı *</span>
                            <input type="text" name="company_name" value="<?= htmlspecialchars((string) ($company["company_name"] ?? ''), ENT_QUOTES, "UTF-8") ?>" required>
                        </label>
                        <label class="form-field">
                            <span data-i18n="companyEmailLabel">Şirket E-postası *</span>
                            <input type="email" name="contact_email" value="<?= htmlspecialchars((string) ($company["contact_email"] ?? ''), ENT_QUOTES, "UTF-8") ?>" required>
                        </label>

                        <label class="form-field"><span data-i18n="taxNumberLabel">Vergi No</span><input type="text" name="tax_number" value="<?= htmlspecialchars((string) ($company["tax_number"] ?? ''), ENT_QUOTES, "UTF-8") ?>"></label>
                        <label class="form-field"><span data-i18n="sectorLabel">Sektör</span><input type="text" name="sector" value="<?= htmlspecialchars((string) ($company["sector"] ?? ''), ENT_QUOTES, "UTF-8") ?>"></label>
                        <label class="form-field"><span data-i18n="cityLabel">Şehir</span><input type="text" name="city" value="<?= htmlspecialchars((string) ($company["city"] ?? ''), ENT_QUOTES, "UTF-8") ?>"></label>
                        <label class="form-field"><span data-i18n="companyWebsiteLabel">Web Sitesi</span><input type="text" name="website" value="<?= htmlspecialchars((string) ($company["website"] ?? ''), ENT_QUOTES, "UTF-8") ?>"></label>
                        <label class="form-field"><span data-i18n="companyPhoneLabel">Telefon</span><input type="text" name="phone" value="<?= htmlspecialchars((string) ($company["phone"] ?? ''), ENT_QUOTES, "UTF-8") ?>"></label>
                        <label class="form-field form-field-wide"><span data-i18n="companyAddressLabel">Adres</span><input type="text" name="address" value="<?= htmlspecialchars((string) ($company["address"] ?? ''), ENT_QUOTES, "UTF-8") ?>"></label>
                        <label class="form-field form-field-wide"><span data-i18n="contactPersonLabel">Yetkili Kişi</span><input type="text" name="contact_person" value="<?= htmlspecialchars((string) ($company["contact_person"] ?? ''), ENT_QUOTES, "UTF-8") ?>"></label>

                        <label class="form-field form-field-wide"><span data-i18n="brandNameLabel">Marka Adı</span><input type="text" name="brand_name" value="<?= htmlspecialchars((string) ($company["brand_name"] ?? ''), ENT_QUOTES, "UTF-8") ?>"></label>
                        <label class="form-field form-field-wide"><span data-i18n="brandSloganLabel">Marka Sloganı</span><input type="text" name="brand_slogan" value="<?= htmlspecialchars((string) ($company["brand_slogan"] ?? ''), ENT_QUOTES, "UTF-8") ?>"></label>
                        <label class="form-field form-field-wide"><span data-i18n="brandDescriptionLabel">Marka Açıklaması</span><textarea name="brand_description" rows="4"><?= htmlspecialchars((string) ($company["brand_description"] ?? ''), ENT_QUOTES, "UTF-8") ?></textarea></label>
                    </div>

                    <div class="form-actions">
                        <button class="primary-button" type="submit" data-i18n="saveProfileButton">Profili Kaydet</button>
                    </div>
                </form>
            </div>
        </section>
    </main>

    <script src="assets/js/theme.js"></script>
    <script src="assets/js/language.js"></script>
    <script src="assets/js/sidebar.js"></script>
    <script src="assets/js/pwa.js"></script>
</body>
</html>
