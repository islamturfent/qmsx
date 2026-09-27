<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/access.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/equipment-functions.php';

$userId = (int) ($_SESSION["qms_user_id"] ?? 0);
$role = qmsCurrentRole();
$csrfScope = 'equipment_create';

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
    "asset_code" => "",
    "name" => "",
    "category" => "",
    "manufacturer" => "",
    "model" => "",
    "serial_number" => "",
    "location" => "",
    "responsible_user_id" => 0,
    "calibration_interval_days" => "",
    "status" => "operational",
    "notes" => "",
];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    qmsCsrfVerify($csrfScope, $_POST["csrf"] ?? null);

    $formData = [
        "company_id" => (int) ($_POST["company_id"] ?? 0),
        "asset_code" => trim((string) ($_POST["asset_code"] ?? "")),
        "name" => trim((string) ($_POST["name"] ?? "")),
        "category" => trim((string) ($_POST["category"] ?? "")),
        "manufacturer" => trim((string) ($_POST["manufacturer"] ?? "")),
        "model" => trim((string) ($_POST["model"] ?? "")),
        "serial_number" => trim((string) ($_POST["serial_number"] ?? "")),
        "location" => trim((string) ($_POST["location"] ?? "")),
        "responsible_user_id" => (int) ($_POST["responsible_user_id"] ?? 0),
        "calibration_interval_days" => trim((string) ($_POST["calibration_interval_days"] ?? "")),
        "status" => (string) ($_POST["status"] ?? "operational"),
        "notes" => trim((string) ($_POST["notes"] ?? "")),
    ];

    $interval = null;
    if ($formData["calibration_interval_days"] !== "") {
        if (preg_match('/^\d{1,4}$/', $formData["calibration_interval_days"]) === 1) {
            $interval = (int) $formData["calibration_interval_days"];
        } else {
            $formError = "Kalibrasyon aralığı geçerli bir gün sayısı olmalı.";
        }
    }

    if ($formData["name"] === "") {
        $formError = "Lütfen ekipman adını girin.";
    } elseif (!in_array($formData["company_id"], $allowedCompanyIds, true)) {
        $formError = "Geçerli bir şirket seçin.";
    } elseif (!in_array($formData["status"], QMS_EQUIPMENT_STATUSES, true)) {
        $formError = "Geçerli bir durum seçin.";
    } elseif ($formError === "") {
        $insert = $pdo->prepare(
            "INSERT INTO equipment
                (company_id, asset_code, name, category, manufacturer, model, serial_number,
                 location, responsible_user_id, calibration_interval_days, notes, status,
                 created_by, updated_by, active)
             VALUES
                (:company_id, :asset_code, :name, :category, :manufacturer, :model, :serial_number,
                 :location, :responsible_user_id, :calibration_interval_days, :notes, :status,
                 :created_by, :updated_by, 1)"
        );
        $insert->execute([
            "company_id" => $formData["company_id"],
            "asset_code" => $formData["asset_code"] !== "" ? mb_substr($formData["asset_code"], 0, 60) : null,
            "name" => mb_substr($formData["name"], 0, 255),
            "category" => $formData["category"] !== "" ? mb_substr($formData["category"], 0, 100) : null,
            "manufacturer" => $formData["manufacturer"] !== "" ? mb_substr($formData["manufacturer"], 0, 150) : null,
            "model" => $formData["model"] !== "" ? mb_substr($formData["model"], 0, 150) : null,
            "serial_number" => $formData["serial_number"] !== "" ? mb_substr($formData["serial_number"], 0, 120) : null,
            "location" => $formData["location"] !== "" ? mb_substr($formData["location"], 0, 150) : null,
            "responsible_user_id" => $formData["responsible_user_id"] > 0 ? $formData["responsible_user_id"] : null,
            "calibration_interval_days" => $interval,
            "notes" => $formData["notes"] !== "" ? mb_substr($formData["notes"], 0, 4000) : null,
            "status" => $formData["status"],
            "created_by" => $userId ?: null,
            "updated_by" => $userId ?: null,
        ]);

        $equipmentId = (int) $pdo->lastInsertId();
        header("Location: equipment-detail.php?id=" . $equipmentId . "&created=1");
        exit;
    }
}

$activeNav = "equipment";

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f9fafb">
    <title>QuAmi Yeni Ekipman</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar">
        <div class="topbar-inner">
            <div class="page-title-block">
                <strong data-i18n="createEquipmentTitle">Yeni Ekipman</strong>
                <span data-i18n="equipmentCreateText">Ekipman kaydı oluşturun.</span>
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
                <span class="section-kicker" data-i18n="equipmentKicker">Altyapı ve Ölçüm</span>
                <h1 data-i18n="createEquipmentTitle">Yeni Ekipman</h1>
                <p data-i18n="equipmentCreateText">Ekipmanı kaydedin; kalibrasyon geçmişini detay sayfasından ekleyin.</p>
            </div>
            <a class="secondary-button" href="equipment.php" data-i18n="backToEquipmentButton">Ekipmana Dön</a>
        </section>
        <section class="form-panel">
            <?php if ($formError !== ""): ?>
                <div class="form-message error"><?= htmlspecialchars($formError, ENT_QUOTES, "UTF-8") ?></div>
            <?php endif; ?>
            <?php if (!$companies): ?>
                <div class="form-message error" data-i18n="equipmentNoCompanyText">Ekipman eklemek için önce bir şirket gerekir.</div>
            <?php else: ?>
            <form class="auditor-form" method="post" action="equipment-create.php">
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
                        <span data-i18n="equipmentAssetCodeLabel">Demirbaş Kodu</span>
                        <input type="text" name="asset_code" maxlength="60" value="<?= htmlspecialchars($formData["asset_code"], ENT_QUOTES, "UTF-8") ?>">
                    </label>
                    <label class="form-field">
                        <span data-i18n="equipmentNameLabel">Ekipman Adı</span>
                        <input type="text" name="name" maxlength="255" value="<?= htmlspecialchars($formData["name"], ENT_QUOTES, "UTF-8") ?>" required>
                    </label>
                    <label class="form-field">
                        <span data-i18n="equipmentCategoryLabel">Kategori</span>
                        <input type="text" name="category" maxlength="100" value="<?= htmlspecialchars($formData["category"], ENT_QUOTES, "UTF-8") ?>">
                    </label>
                    <label class="form-field">
                        <span data-i18n="equipmentManufacturerLabel">Üretici</span>
                        <input type="text" name="manufacturer" maxlength="150" value="<?= htmlspecialchars($formData["manufacturer"], ENT_QUOTES, "UTF-8") ?>">
                    </label>
                    <label class="form-field">
                        <span data-i18n="equipmentModelLabel">Model</span>
                        <input type="text" name="model" maxlength="150" value="<?= htmlspecialchars($formData["model"], ENT_QUOTES, "UTF-8") ?>">
                    </label>
                    <label class="form-field">
                        <span data-i18n="equipmentSerialLabel">Seri No</span>
                        <input type="text" name="serial_number" maxlength="120" value="<?= htmlspecialchars($formData["serial_number"], ENT_QUOTES, "UTF-8") ?>">
                    </label>
                    <label class="form-field">
                        <span data-i18n="equipmentLocationLabel">Konum</span>
                        <input type="text" name="location" maxlength="150" value="<?= htmlspecialchars($formData["location"], ENT_QUOTES, "UTF-8") ?>">
                    </label>
                    <label class="form-field">
                        <span data-i18n="equipmentResponsibleLabel">Sorumlu (isteğe bağlı)</span>
                        <select name="responsible_user_id">
                            <option value="0" data-i18n="responsibleUserNoneOption">— seçilmedi</option>
                            <?php foreach (qmsEquipmentResponsibleOptions($pdo, $formData["company_id"] ?: 0) as $option): ?>
                                <option value="<?= (int) $option["id"] ?>" <?= $formData["responsible_user_id"] === (int) $option["id"] ? "selected" : "" ?>><?= htmlspecialchars($option["full_name"], ENT_QUOTES, "UTF-8") ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="form-field">
                        <span data-i18n="equipmentIntervalLabel">Kalibrasyon Aralığı (gün)</span>
                        <input type="text" name="calibration_interval_days" value="<?= htmlspecialchars($formData["calibration_interval_days"], ENT_QUOTES, "UTF-8") ?>" placeholder="Örn. 365">
                    </label>
                    <label class="form-field">
                        <span data-i18n="equipmentStatusLabel">Durum</span>
                        <select name="status">
                            <?php foreach (qmsEquipmentStatusLabels() as $value => $label): ?>
                                <option value="<?= $value ?>" <?= $formData["status"] === $value ? "selected" : "" ?>><?= htmlspecialchars($label, ENT_QUOTES, "UTF-8") ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="form-field form-field-wide">
                        <span data-i18n="equipmentNotesLabel">Notlar</span>
                        <textarea name="notes" rows="3"><?= htmlspecialchars($formData["notes"], ENT_QUOTES, "UTF-8") ?></textarea>
                    </label>
                </div>
                <div class="form-actions">
                    <button class="primary-button" type="submit" data-i18n="saveEquipmentButton">Ekipmanı Kaydet</button>
                    <a class="secondary-button" href="equipment.php" data-i18n="backToEquipmentButton">Ekipmana Dön</a>
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
