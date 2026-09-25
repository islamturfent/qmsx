<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/access.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/audit-program-functions.php';

$userId = (int) ($_SESSION["qms_user_id"] ?? 0);
$role = qmsCurrentRole();
$csrfScope = 'audit_program_create';

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
    "title" => "",
    "year" => date("Y"),
    "description" => "",
];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    qmsCsrfVerify($csrfScope, $_POST["csrf"] ?? null);

    $formData = [
        "company_id" => (int) ($_POST["company_id"] ?? 0),
        "title" => trim((string) ($_POST["title"] ?? "")),
        "year" => (int) ($_POST["year"] ?? 0),
        "description" => trim((string) ($_POST["description"] ?? "")),
    ];

    if ($formData["title"] === "") {
        $formError = "Lütfen program başlığını girin.";
    } elseif (!in_array($formData["company_id"], $allowedCompanyIds, true)) {
        $formError = "Geçerli bir şirket seçin.";
    } elseif ($formData["year"] < 2000 || $formData["year"] > 2100) {
        $formError = "Geçerli bir plan yılı girin.";
    } else {
        $insert = $pdo->prepare(
            "INSERT INTO audit_programs
                (company_id, title, year, description, status, created_by, updated_by, active)
             VALUES (:company_id, :title, :year, :description, 'draft', :created_by, :updated_by, 1)"
        );
        $insert->execute([
            "company_id" => $formData["company_id"],
            "title" => mb_substr($formData["title"], 0, 255),
            "year" => $formData["year"],
            "description" => $formData["description"] !== "" ? mb_substr($formData["description"], 0, 4000) : null,
            "created_by" => $userId ?: null,
            "updated_by" => $userId ?: null,
        ]);

        $programId = (int) $pdo->lastInsertId();
        header("Location: audit-program-detail.php?id=" . $programId . "&created=1");
        exit;
    }
}

$activeNav = "audit_programs";

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f9fafb">
    <title>QMS Yeni Denetim Programı</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar">
        <div class="topbar-inner">
            <div class="page-title-block">
                <strong data-i18n="createAuditProgramTitle">Yeni Denetim Programı</strong>
                <span data-i18n="auditProgramCreateText">Yıllık denetim planı oluşturun.</span>
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
                <span class="section-kicker" data-i18n="auditProgramKicker">Denetim Planlama</span>
                <h1 data-i18n="createAuditProgramTitle">Yeni Denetim Programı</h1>
                <p data-i18n="auditProgramCreateText">Programı oluşturun; denetimleri detay sayfasından bağlayın.</p>
            </div>
            <a class="secondary-button" href="audit-programs.php" data-i18n="backToProgramsButton">Programlara Dön</a>
        </section>
        <section class="form-panel">
            <?php if ($formError !== ""): ?>
                <div class="form-message error"><?= htmlspecialchars($formError, ENT_QUOTES, "UTF-8") ?></div>
            <?php endif; ?>
            <?php if (!$companies): ?>
                <div class="form-message error" data-i18n="auditProgramNoCompanyText">Program eklemek için önce bir şirket gerekir.</div>
            <?php else: ?>
            <form class="auditor-form" method="post" action="audit-program-create.php">
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
                        <span data-i18n="auditProgramTitleLabel">Program Başlığı</span>
                        <input type="text" name="title" maxlength="255" value="<?= htmlspecialchars($formData["title"], ENT_QUOTES, "UTF-8") ?>" required placeholder="Örn. 2026 İç Denetim Programı">
                    </label>
                    <label class="form-field">
                        <span data-i18n="auditProgramYearLabel">Plan Yılı</span>
                        <input type="number" name="year" min="2000" max="2100" value="<?= htmlspecialchars((string) $formData["year"], ENT_QUOTES, "UTF-8") ?>" required>
                    </label>
                    <label class="form-field form-field-wide">
                        <span data-i18n="auditProgramDescriptionLabel">Açıklama</span>
                        <textarea name="description" rows="4"><?= htmlspecialchars($formData["description"], ENT_QUOTES, "UTF-8") ?></textarea>
                    </label>
                </div>
                <div class="form-actions">
                    <button class="primary-button" type="submit" data-i18n="saveAuditProgramButton">Programı Kaydet</button>
                    <a class="secondary-button" href="audit-programs.php" data-i18n="backToProgramsButton">Programlara Dön</a>
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
