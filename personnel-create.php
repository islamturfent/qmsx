<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/access.php';
require_once __DIR__ . '/includes/csrf.php';

$userId = (int) ($_SESSION["qms_user_id"] ?? 0);
$role = qmsCurrentRole();
$csrfScope = 'personnel_create';

// Sirket listesi kapsamdan gelir.
$companyScope = qmsCompanyScope('companies.id', qmsVisibleCompanyIds($pdo, $userId, $role));
$companyStmt = $pdo->prepare(
    "SELECT companies.id, companies.company_name FROM companies WHERE companies.active = 1"
    . $companyScope['sql'] . ' ORDER BY companies.company_name'
);
$companyStmt->execute($companyScope['params']);
$companies = $companyStmt->fetchAll(PDO::FETCH_ASSOC);
$allowedCompanyIds = array_map('intval', array_column($companies, 'id'));

$formError = "";
$formData = [
    "company_id" => count($companies) === 1 ? (int) $companies[0]["id"] : 0,
    "first_name" => "",
    "last_name" => "",
    "employee_code" => "",
    "department" => "",
    "position" => "",
    "email" => "",
    "phone" => "",
    "hired_date" => "",
];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    qmsCsrfVerify($csrfScope, $_POST["csrf"] ?? null);

    $formData = [
        "company_id" => (int) ($_POST["company_id"] ?? 0),
        "first_name" => trim((string) ($_POST["first_name"] ?? "")),
        "last_name" => trim((string) ($_POST["last_name"] ?? "")),
        "employee_code" => trim((string) ($_POST["employee_code"] ?? "")),
        "department" => trim((string) ($_POST["department"] ?? "")),
        "position" => trim((string) ($_POST["position"] ?? "")),
        "email" => trim((string) ($_POST["email"] ?? "")),
        "phone" => trim((string) ($_POST["phone"] ?? "")),
        "hired_date" => trim((string) ($_POST["hired_date"] ?? "")),
    ];

    if ($formData["first_name"] === "" || $formData["last_name"] === "") {
        $formError = "Lütfen personel adını ve soyadını girin.";
    } elseif (!in_array($formData["company_id"], $allowedCompanyIds, true)) {
        $formError = "Geçerli bir şirket seçin.";
    } elseif ($formData["hired_date"] !== "" && preg_match('/^\d{4}-\d{2}-\d{2}$/', $formData["hired_date"]) !== 1) {
        $formError = "Geçerli bir işe alım tarihi girin.";
    } else {
        $duplicate = false;
        if ($formData["employee_code"] !== "") {
            $dup = $pdo->prepare("SELECT COUNT(*) FROM staff_members WHERE company_id = ? AND employee_code = ? AND active = 1");
            $dup->execute([$formData["company_id"], $formData["employee_code"]]);
            $duplicate = (int) $dup->fetchColumn() > 0;
        }
        if ($duplicate) {
            $formError = "Bu personel kodu şirkette zaten kayıtlı.";
        } else {
            $insert = $pdo->prepare(
                "INSERT INTO staff_members
                    (company_id, first_name, last_name, employee_code, department, position, email, phone, hired_date, active)
                 VALUES (:company_id, :first_name, :last_name, :employee_code, :department, :position, :email, :phone, :hired_date, 1)"
            );
            $insert->execute([
                "company_id" => $formData["company_id"],
                "first_name" => mb_substr($formData["first_name"], 0, 80),
                "last_name" => mb_substr($formData["last_name"], 0, 80),
                "employee_code" => $formData["employee_code"] !== "" ? mb_substr($formData["employee_code"], 0, 60) : null,
                "department" => $formData["department"] !== "" ? mb_substr($formData["department"], 0, 100) : null,
                "position" => $formData["position"] !== "" ? mb_substr($formData["position"], 0, 120) : null,
                "email" => $formData["email"] !== "" ? mb_substr($formData["email"], 0, 180) : null,
                "phone" => $formData["phone"] !== "" ? mb_substr($formData["phone"], 0, 60) : null,
                "hired_date" => $formData["hired_date"] !== "" ? $formData["hired_date"] : null,
            ]);
            $staffId = (int) $pdo->lastInsertId();
            header("Location: personnel-detail.php?id=" . $staffId . "&created=1");
            exit;
        }
    }
}

$activeNav = "personnel";

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f9fafb">
    <title>QMS Yeni Personel</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar">
        <div class="topbar-inner">
            <div class="page-title-block">
                <strong data-i18n="createPersonnelTitle">Yeni Personel</strong>
                <span data-i18n="personnelCreateText">Personel kaydı oluşturun; yetkinlikleri detay sayfasından ekleyin.</span>
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
                <span class="section-kicker" data-i18n="personnelKicker">Kaynak Yönetimi</span>
                <h1 data-i18n="createPersonnelTitle">Yeni Personel</h1>
                <p data-i18n="personnelCreateText">Personeli oluşturun; yetkinlikleri detay sayfasından ekleyin.</p>
            </div>
            <a class="secondary-button" href="personnel.php" data-i18n="backToPersonnelButton">Personel Listesine Dön</a>
        </section>
        <section class="form-panel">
            <?php if ($formError !== ""): ?>
                <div class="form-message error"><?= htmlspecialchars($formError, ENT_QUOTES, "UTF-8") ?></div>
            <?php endif; ?>
            <?php if (!$companies): ?>
                <div class="form-message error" data-i18n="personnelNoCompanyText">Personel eklemek için önce bir şirket gerekir.</div>
            <?php else: ?>
            <form class="auditor-form" method="post" action="personnel-create.php">
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
                    <label class="form-field"><span data-i18n="personnelFirstNameLabel">Ad</span><input type="text" name="first_name" value="<?= htmlspecialchars($formData["first_name"], ENT_QUOTES, "UTF-8") ?>" required maxlength="80"></label>
                    <label class="form-field"><span data-i18n="personnelLastNameLabel">Soyad</span><input type="text" name="last_name" value="<?= htmlspecialchars($formData["last_name"], ENT_QUOTES, "UTF-8") ?>" required maxlength="80"></label>
                    <label class="form-field"><span data-i18n="personnelCodeLabel">Personel Kodu</span><input type="text" name="employee_code" value="<?= htmlspecialchars($formData["employee_code"], ENT_QUOTES, "UTF-8") ?>" maxlength="60"></label>
                    <label class="form-field"><span data-i18n="personnelDepartmentLabel">Bölüm</span><input type="text" name="department" value="<?= htmlspecialchars($formData["department"], ENT_QUOTES, "UTF-8") ?>" maxlength="100"></label>
                    <label class="form-field"><span data-i18n="personnelPositionLabel">Pozisyon</span><input type="text" name="position" value="<?= htmlspecialchars($formData["position"], ENT_QUOTES, "UTF-8") ?>" maxlength="120"></label>
                    <label class="form-field"><span data-i18n="personnelEmailLabel">E-posta</span><input type="email" name="email" value="<?= htmlspecialchars($formData["email"], ENT_QUOTES, "UTF-8") ?>" maxlength="180"></label>
                    <label class="form-field"><span data-i18n="personnelPhoneLabel">Telefon</span><input type="text" name="phone" value="<?= htmlspecialchars($formData["phone"], ENT_QUOTES, "UTF-8") ?>" maxlength="60"></label>
                    <label class="form-field"><span data-i18n="personnelHiredDateLabel">İşe Alım Tarihi</span><input type="date" name="hired_date" value="<?= htmlspecialchars($formData["hired_date"], ENT_QUOTES, "UTF-8") ?>"></label>
                </div>
                <div class="form-actions">
                    <button class="primary-button" type="submit" data-i18n="createPersonnelSubmit">Personeli Oluştur</button>
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
