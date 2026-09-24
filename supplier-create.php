<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/supplier-functions.php';

$userId = (int) ($_SESSION["qms_user_id"] ?? 0);
$role = qmsCurrentRole();
$csrfScope = 'supplier_create';

// Sirket listesi kapsamdan gelir.
$companyScope = qmsCompanyScope('companies.id', qmsVisibleCompanyIds($pdo, $userId, $role));
$companyStmt = $pdo->prepare(
    "SELECT companies.id, companies.company_name
     FROM companies
     WHERE companies.active = 1" . $companyScope['sql'] . "
     ORDER BY companies.company_name"
);
$companyStmt->execute($companyScope['params']);
$companies = $companyStmt->fetchAll(PDO::FETCH_ASSOC);
$allowedCompanyIds = array_map('intval', array_column($companies, 'id'));

$riskLabels = qmsSupplierRiskLabels();
$riskI18n = qmsSupplierRiskI18nKeys();

$formError = "";
$formData = [
    "company_id" => count($companies) === 1 ? (int) $companies[0]["id"] : 0,
    "name" => "",
    "supplier_code" => "",
    "category" => "",
    "risk_class" => "medium",
    "tax_number" => "",
    "contact_name" => "",
    "contact_email" => "",
    "contact_phone" => "",
    "city" => "",
    "notes" => ""
];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    qmsCsrfVerify($csrfScope, $_POST["csrf"] ?? null);

    $formData = [
        "company_id" => (int) ($_POST["company_id"] ?? 0),
        "name" => qmsSupplierText($_POST["name"] ?? "", 200),
        "supplier_code" => qmsSupplierText($_POST["supplier_code"] ?? "", 60),
        "category" => qmsSupplierText($_POST["category"] ?? "", 100),
        "risk_class" => (string) ($_POST["risk_class"] ?? "medium"),
        "tax_number" => qmsSupplierText($_POST["tax_number"] ?? "", 30),
        "contact_name" => qmsSupplierText($_POST["contact_name"] ?? "", 150),
        "contact_email" => qmsSupplierText($_POST["contact_email"] ?? "", 150),
        "contact_phone" => qmsSupplierText($_POST["contact_phone"] ?? "", 30),
        "city" => qmsSupplierText($_POST["city"] ?? "", 80),
        "notes" => qmsSupplierText($_POST["notes"] ?? "", 4000)
    ];

    if ($formData["name"] === "") {
        $formError = "Lütfen tedarikçi adını girin.";
    } elseif (!in_array($formData["company_id"], $allowedCompanyIds, true)) {
        $formError = "Geçerli bir şirket seçin.";
    } elseif (!in_array($formData["risk_class"], QMS_SUPPLIER_RISK_CLASSES, true)) {
        $formError = "Geçerli bir risk sınıfı seçin.";
    } elseif ($formData["contact_email"] !== "" && filter_var($formData["contact_email"], FILTER_VALIDATE_EMAIL) === false) {
        $formError = "İletişim e-postası geçerli değil.";
    } else {
        // Ayni sirkette ayni tedarikci kodu iki kez kullanilmasin.
        $duplicateCode = false;
        if ($formData["supplier_code"] !== "") {
            $duplicateStmt = $pdo->prepare(
                "SELECT COUNT(*) FROM suppliers
                 WHERE company_id = :company_id AND supplier_code = :supplier_code AND active = 1"
            );
            $duplicateStmt->execute([
                "company_id" => $formData["company_id"],
                "supplier_code" => $formData["supplier_code"]
            ]);
            $duplicateCode = (int) $duplicateStmt->fetchColumn() > 0;
        }

        if ($duplicateCode) {
            $formError = "Bu tedarikçi kodu şirkette zaten kayıtlı.";
        } else {
            $insertStmt = $pdo->prepare(
                "INSERT INTO suppliers
                    (company_id, name, supplier_code, category, risk_class, tax_number,
                     contact_name, contact_email, contact_phone, city, status, notes,
                     created_by, updated_by, active)
                 VALUES
                    (:company_id, :name, :supplier_code, :category, :risk_class, :tax_number,
                     :contact_name, :contact_email, :contact_phone, :city, 'candidate', :notes,
                     :created_by, :updated_by, 1)"
            );
            $insertStmt->execute([
                "company_id" => $formData["company_id"],
                "name" => $formData["name"],
                "supplier_code" => $formData["supplier_code"] !== "" ? $formData["supplier_code"] : null,
                "category" => $formData["category"] !== "" ? $formData["category"] : null,
                "risk_class" => $formData["risk_class"],
                "tax_number" => $formData["tax_number"] !== "" ? $formData["tax_number"] : null,
                "contact_name" => $formData["contact_name"] !== "" ? $formData["contact_name"] : null,
                "contact_email" => $formData["contact_email"] !== "" ? $formData["contact_email"] : null,
                "contact_phone" => $formData["contact_phone"] !== "" ? $formData["contact_phone"] : null,
                "city" => $formData["city"] !== "" ? $formData["city"] : null,
                "notes" => $formData["notes"] !== "" ? $formData["notes"] : null,
                "created_by" => $userId ?: null,
                "updated_by" => $userId ?: null
            ]);

            $supplierId = (int) $pdo->lastInsertId();

            header("Location: supplier-detail.php?id=" . $supplierId . "&created=1");
            exit;
        }
    }
}

$activeNav = "suppliers";

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f9fafb">
    <title>QMS Yeni Tedarikçi</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar">
        <div class="topbar-inner">
            <div class="page-title-block">
                <strong data-i18n="newSupplierTitle">Yeni Tedarikçi</strong>
                <span data-i18n="suppliersText">Tedarikçileri, onay durumunu ve değerlendirme puanlarını izleyin.</span>
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
                <span class="section-kicker" data-i18n="supplierRegisterKicker">Tedarikçi Kayıtları</span>
                <h1 data-i18n="newSupplierTitle">Yeni Tedarikçi</h1>
                <p data-i18n="newSupplierText">Tedarikçiyi kaydedin; değerlendirmeleri kaydı oluşturduktan sonra ekleyin.</p>
            </div>
            <a class="secondary-button" href="suppliers.php" data-i18n="backToSuppliersButton">Tedarikçilere Dön</a>
        </section>
        <section class="form-panel">
            <?php if ($formError !== ""): ?>
                <div class="form-message error"><?= htmlspecialchars($formError, ENT_QUOTES, "UTF-8") ?></div>
            <?php endif; ?>
            <?php if (!$companies): ?>
                <div class="form-message error" data-i18n="supplierNoCompanyText">Tedarikçi eklemek için önce bir şirket gerekir. Şirket kaydınız yoksa yöneticinizle görüşün.</div>
            <?php else: ?>
            <form class="auditor-form" method="post" action="supplier-create.php">
                <?= qmsCsrfField($csrfScope) ?>
                <div class="form-grid">
                    <label class="form-field">
                        <span data-i18n="companySelectLabel">Şirket</span>
                        <select name="company_id" required>
                            <option value="0" data-i18n="selectCompanyOption">Şirket seçin</option>
                            <?php foreach ($companies as $company): ?>
                                <option value="<?= (int) $company["id"] ?>" <?= $formData["company_id"] === (int) $company["id"] ? "selected" : "" ?>><?= htmlspecialchars($company["company_name"], ENT_QUOTES, "UTF-8") ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="form-field">
                        <span data-i18n="supplierNameLabel">Tedarikçi Adı</span>
                        <input type="text" name="name" maxlength="200" value="<?= htmlspecialchars($formData["name"], ENT_QUOTES, "UTF-8") ?>" required>
                    </label>
                    <label class="form-field">
                        <span data-i18n="supplierCodeLabel">Tedarikçi Kodu</span>
                        <input type="text" name="supplier_code" maxlength="60" value="<?= htmlspecialchars($formData["supplier_code"], ENT_QUOTES, "UTF-8") ?>">
                    </label>
                    <label class="form-field">
                        <span data-i18n="supplierCategoryLabel">Kategori</span>
                        <input type="text" name="category" maxlength="100" value="<?= htmlspecialchars($formData["category"], ENT_QUOTES, "UTF-8") ?>" placeholder="Hammadde, Lojistik, Kalibrasyon…">
                    </label>
                    <label class="form-field">
                        <span data-i18n="supplierRiskClassLabel">Risk Sınıfı</span>
                        <select name="risk_class">
                            <?php foreach ($riskLabels as $value => $label): ?>
                                <option value="<?= $value ?>" <?= $formData["risk_class"] === $value ? "selected" : "" ?> data-i18n="<?= $riskI18n[$value] ?>"><?= htmlspecialchars($label, ENT_QUOTES, "UTF-8") ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="form-field">
                        <span data-i18n="supplierTaxNumberLabel">Vergi No</span>
                        <input type="text" name="tax_number" maxlength="30" value="<?= htmlspecialchars($formData["tax_number"], ENT_QUOTES, "UTF-8") ?>">
                    </label>
                    <label class="form-field">
                        <span data-i18n="supplierContactNameLabel">İlgili Kişi</span>
                        <input type="text" name="contact_name" maxlength="150" value="<?= htmlspecialchars($formData["contact_name"], ENT_QUOTES, "UTF-8") ?>">
                    </label>
                    <label class="form-field">
                        <span data-i18n="supplierContactEmailLabel">İletişim E-postası</span>
                        <input type="email" name="contact_email" maxlength="150" value="<?= htmlspecialchars($formData["contact_email"], ENT_QUOTES, "UTF-8") ?>">
                    </label>
                    <label class="form-field">
                        <span data-i18n="supplierContactPhoneLabel">Telefon</span>
                        <input type="text" name="contact_phone" maxlength="30" value="<?= htmlspecialchars($formData["contact_phone"], ENT_QUOTES, "UTF-8") ?>">
                    </label>
                    <label class="form-field">
                        <span data-i18n="supplierCityLabel">Şehir</span>
                        <input type="text" name="city" maxlength="80" value="<?= htmlspecialchars($formData["city"], ENT_QUOTES, "UTF-8") ?>">
                    </label>
                    <label class="form-field form-field-wide">
                        <span data-i18n="supplierNotesLabel">Notlar</span>
                        <textarea name="notes" rows="4"><?= htmlspecialchars($formData["notes"], ENT_QUOTES, "UTF-8") ?></textarea>
                    </label>
                </div>
                <div class="form-actions">
                    <button class="primary-button" type="submit" data-i18n="saveSupplierButton">Tedarikçiyi Kaydet</button>
                    <a class="secondary-button" href="suppliers.php" data-i18n="backToSuppliersButton">Tedarikçilere Dön</a>
                </div>
            </form>
            <?php endif; ?>
        </section>
    </main>
    <script src="assets/js/theme.js"></script>
    <script src="assets/js/language.js"></script>
    <script src="assets/js/sidebar.js"></script>
    <script src="assets/js/pwa.js"></script>
</body>
</html>
