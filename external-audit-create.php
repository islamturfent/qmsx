<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/access.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/external-audit-functions.php';

$userId = (int) ($_SESSION["qms_user_id"] ?? 0);
$role = qmsCurrentRole();
$csrfScope = 'external_audit_create';

// Sirket listesi kapsamdan gelir.
$companyScope = qmsCompanyScope('companies.id', qmsVisibleCompanyIds($pdo, $userId, $role));
$companyStmt = $pdo->prepare(
    "SELECT companies.id, companies.company_name FROM companies WHERE companies.active = 1"
    . $companyScope['sql'] . ' ORDER BY companies.company_name'
);
$companyStmt->execute($companyScope['params']);
$companies = $companyStmt->fetchAll(PDO::FETCH_ASSOC);
$allowedCompanyIds = array_map('intval', array_column($companies, 'id'));

$typeLabels = qmsExternalAuditTypeLabels();
$typeI18n = qmsExternalAuditTypeI18nKeys();

$formError = "";
$formData = [
    "company_id" => count($companies) === 1 ? (int) $companies[0]["id"] : 0,
    "audit_type" => "customer",
    "title" => "",
    "audited_by" => "",
    "auditor_name" => "",
    "audit_date" => "",
    "notes" => "",
];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    qmsCsrfVerify($csrfScope, $_POST["csrf"] ?? null);

    $formData = [
        "company_id" => (int) ($_POST["company_id"] ?? 0),
        "audit_type" => (string) ($_POST["audit_type"] ?? "customer"),
        "title" => trim((string) ($_POST["title"] ?? "")),
        "audited_by" => trim((string) ($_POST["audited_by"] ?? "")),
        "auditor_name" => trim((string) ($_POST["auditor_name"] ?? "")),
        "audit_date" => trim((string) ($_POST["audit_date"] ?? "")),
        "notes" => trim((string) ($_POST["notes"] ?? "")),
    ];

    if ($formData["title"] === "") {
        $formError = "Lütfen denetim başlığını girin.";
    } elseif (!in_array($formData["company_id"], $allowedCompanyIds, true)) {
        $formError = "Geçerli bir şirket seçin.";
    } elseif (!in_array($formData["audit_type"], QMS_EXTERNAL_AUDIT_TYPES, true)) {
        $formError = "Geçerli bir denetim kaynağı seçin.";
    } elseif ($formData["audit_date"] !== "" && preg_match('/^\d{4}-\d{2}-\d{2}$/', $formData["audit_date"]) !== 1) {
        $formError = "Geçerli bir denetim tarihi girin.";
    } else {
        $insert = $pdo->prepare(
            "INSERT INTO external_audits
                (company_id, audit_type, title, audited_by, auditor_name, audit_date, status, notes, active)
             VALUES (:company_id, :audit_type, :title, :audited_by, :auditor_name, :audit_date, 'planned', :notes, 1)"
        );
        $insert->execute([
            "company_id" => $formData["company_id"],
            "audit_type" => $formData["audit_type"],
            "title" => mb_substr($formData["title"], 0, 255),
            "audited_by" => $formData["audited_by"] !== "" ? mb_substr($formData["audited_by"], 0, 180) : null,
            "auditor_name" => $formData["auditor_name"] !== "" ? mb_substr($formData["auditor_name"], 0, 180) : null,
            "audit_date" => $formData["audit_date"] !== "" ? $formData["audit_date"] : null,
            "notes" => $formData["notes"] !== "" ? mb_substr($formData["notes"], 0, 4000) : null,
        ]);
        $auditId = (int) $pdo->lastInsertId();
        header("Location: external-audit-detail.php?id=" . $auditId . "&created=1");
        exit;
    }
}

$activeNav = "external_audits";

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f9fafb">
    <title>QuAmi Yeni Dış Denetim</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar">
        <div class="topbar-inner">
            <div class="page-title-block">
                <strong data-i18n="createExternalAuditTitle">Yeni Dış Denetim</strong>
                <span data-i18n="externalAuditCreateText">Dış denetim kaydı oluşturun.</span>
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
                <span class="section-kicker" data-i18n="externalAuditsKicker">Uygunluk & Kapama</span>
                <h1 data-i18n="createExternalAuditTitle">Yeni Dış Denetim</h1>
                <p data-i18n="externalAuditCreateText">Denetimi oluşturun; bulguları detay sayfasından ekleyin.</p>
            </div>
            <a class="secondary-button" href="external-audits.php" data-i18n="backToExternalAuditsButton">Dış Denetimlere Dön</a>
        </section>
        <section class="form-panel">
            <?php if ($formError !== ""): ?>
                <div class="form-message error"><?= htmlspecialchars($formError, ENT_QUOTES, "UTF-8") ?></div>
            <?php endif; ?>
            <?php if (!$companies): ?>
                <div class="form-message error" data-i18n="externalAuditNoCompanyText">Dış denetim eklemek için önce bir şirket gerekir.</div>
            <?php else: ?>
            <form class="auditor-form" method="post" action="external-audit-create.php">
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
                        <span data-i18n="externalAuditTypeLabel">Denetim Kaynağı</span>
                        <select name="audit_type">
                            <?php foreach ($typeLabels as $key => $label): ?>
                                <option value="<?= $key ?>" <?= $formData["audit_type"] === $key ? "selected" : "" ?> data-i18n="<?= $typeI18n[$key] ?? "" ?>"><?= htmlspecialchars($label, ENT_QUOTES, "UTF-8") ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="form-field"><span data-i18n="externalAuditTitleLabel">Denetim Başlığı</span><input type="text" name="title" value="<?= htmlspecialchars($formData["title"], ENT_QUOTES, "UTF-8") ?>" required maxlength="255"></label>
                    <label class="form-field"><span data-i18n="externalAuditedByLabel">Denetleyen Kuruluş</span><input type="text" name="audited_by" value="<?= htmlspecialchars($formData["audited_by"], ENT_QUOTES, "UTF-8") ?>" maxlength="180"></label>
                    <label class="form-field"><span data-i18n="externalAuditorNameLabel">Denetçi Adı</span><input type="text" name="auditor_name" value="<?= htmlspecialchars($formData["auditor_name"], ENT_QUOTES, "UTF-8") ?>" maxlength="180"></label>
                    <label class="form-field"><span data-i18n="externalAuditDateLabel">Denetim Tarihi</span><input type="date" name="audit_date" value="<?= htmlspecialchars($formData["audit_date"], ENT_QUOTES, "UTF-8") ?>"></label>
                    <label class="form-field form-field-wide"><span data-i18n="externalAuditNotesLabel">Notlar</span><textarea name="notes" rows="3"><?= htmlspecialchars($formData["notes"], ENT_QUOTES, "UTF-8") ?></textarea></label>
                </div>
                <div class="form-actions">
                    <button class="primary-button" type="submit" data-i18n="createExternalAuditSubmit">Dış Denetimi Oluştur</button>
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
