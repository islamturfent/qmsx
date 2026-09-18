<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';

$companyId = (int) ($_GET["id"] ?? 0);

if ($companyId <= 0) {
    header("Location: dashboard.php");
    exit;
}

$companyStmt = $pdo->prepare(
    "SELECT id, company_name, tax_number, sector, city, contact_email, active
     FROM companies
     WHERE id = :id
     LIMIT 1"
);
$companyStmt->execute(["id" => $companyId]);
$company = $companyStmt->fetch(PDO::FETCH_ASSOC);

if (!$company) {
    header("Location: dashboard.php");
    exit;
}

$auditFormError = "";
$auditFormData = [
    "title" => "",
    "audit_type" => "",
    "planned_date" => ""
];

if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["form_type"] ?? "") === "create_audit") {
    $auditFormData = [
        "title" => trim($_POST["title"] ?? ""),
        "audit_type" => trim($_POST["audit_type"] ?? ""),
        "planned_date" => trim($_POST["planned_date"] ?? "")
    ];

    if ($auditFormData["title"] === "") {
        $auditFormError = "Lütfen denetim başlığını girin.";
    } else {
        $insertAudit = $pdo->prepare(
            "INSERT INTO audits (company_id, title, audit_type, planned_date, status, active)
             VALUES (:company_id, :title, :audit_type, :planned_date, 'planned', 1)"
        );
        $insertAudit->execute([
            "company_id" => $companyId,
            "title" => $auditFormData["title"],
            "audit_type" => $auditFormData["audit_type"],
            "planned_date" => $auditFormData["planned_date"] !== "" ? $auditFormData["planned_date"] : null
        ]);

        header("Location: company-detail.php?id=" . $companyId . "&audit=created");
        exit;
    }
}

$auditorsStmt = $pdo->prepare(
    "SELECT first_name, last_name, email, telefon, role, active
     FROM auditors
     WHERE company_id = :company_id
     ORDER BY id DESC"
);
$auditorsStmt->execute(["company_id" => $companyId]);
$auditors = $auditorsStmt->fetchAll(PDO::FETCH_ASSOC);

$auditorCount = count($auditors);

$auditsStmt = $pdo->prepare(
    "SELECT id, title, audit_type, planned_date, status
     FROM audits
     WHERE company_id = :company_id
     ORDER BY created_at DESC, id DESC"
);
$auditsStmt->execute(["company_id" => $companyId]);
$audits = $auditsStmt->fetchAll(PDO::FETCH_ASSOC);

$activeAuditCountStmt = $pdo->prepare(
    "SELECT COUNT(*)
     FROM audits
     WHERE company_id = :company_id AND active = 1"
);
$activeAuditCountStmt->execute(["company_id" => $companyId]);
$activeAuditCount = (int) $activeAuditCountStmt->fetchColumn();

$openNonconformityCountStmt = $pdo->prepare(
    "SELECT COUNT(*)
     FROM nonconformities
     WHERE company_id = :company_id AND active = 1 AND status <> 'closed'"
);
$openNonconformityCountStmt->execute(["company_id" => $companyId]);
$openNonconformityCount = (int) $openNonconformityCountStmt->fetchColumn();

?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f9fafb">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="QMS">

    <title>QMS Şirket Detayı</title>

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
                <div class="brand-icon">Q</div>
                <div class="brand-text">
                    <strong>QMS</strong>
                    <span>Quality Management System</span>
                </div>
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
                    <span class="section-kicker" data-i18n="companyWorkspaceKicker">Şirket Çalışma Alanı</span>
                    <h1><?= htmlspecialchars($company["company_name"], ENT_QUOTES, "UTF-8") ?></h1>
                    <p>
                        <?= htmlspecialchars(trim(($company["city"] ?? "") . " " . ($company["sector"] ?? "")), ENT_QUOTES, "UTF-8") ?>
                    </p>
                </div>
                <a class="secondary-button" href="super-admin-companies.php" data-i18n="companiesTitle">Kayıtlı Şirketler</a>
            </div>
        </section>

        <section class="dashboard-grid">
            <div class="dashboard-card">
                <?= appIcon("users", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="auditorsCardLabel">Denetçiler</span>
                    <strong class="dashboard-card-number"><?= $auditorCount ?></strong>
                </div>
            </div>

            <div class="dashboard-card">
                <?= appIcon("approvals", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="activeAuditsCardLabel">Aktif Denetimler</span>
                    <strong class="dashboard-card-number"><?= $activeAuditCount ?></strong>
                </div>
            </div>

            <div class="dashboard-card">
                <?= appIcon("warning", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="openNonconformitiesCardLabel">Açık Uygunsuzluklar</span>
                    <strong class="dashboard-card-number"><?= $openNonconformityCount ?></strong>
                </div>
            </div>

            <div class="dashboard-card">
                <?= appIcon("sparkles", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="aiReportsButton">Yapay Zeka Raporlama</span>
                    <strong class="dashboard-card-number">0</strong>
                </div>
            </div>
        </section>

        <section class="super-admin-console">
            <div class="console-card">
                <div class="section-heading compact-heading">
                    <div>
                        <h3 data-i18n="createAuditTitle">Denetim Oluştur</h3>
                        <p data-i18n="createAuditText">Bu şirket için yeni bir denetim planlayın.</p>
                    </div>
                </div>

                <?php if (isset($_GET["audit"]) && $_GET["audit"] === "created"): ?>
                    <div class="form-message success" data-i18n="auditCreatedMessage">Denetim başarıyla oluşturuldu.</div>
                <?php endif; ?>

                <?php if ($auditFormError !== ""): ?>
                    <div class="form-message error"><?= htmlspecialchars($auditFormError, ENT_QUOTES, "UTF-8") ?></div>
                <?php endif; ?>

                <form class="auditor-form" method="post" action="company-detail.php?id=<?= $companyId ?>">
                    <input type="hidden" name="form_type" value="create_audit">

                    <div class="form-grid">
                        <label class="form-field form-field-wide">
                            <span data-i18n="auditTitleLabel">Denetim Başlığı</span>
                            <input
                                type="text"
                                name="title"
                                value="<?= htmlspecialchars($auditFormData["title"], ENT_QUOTES, "UTF-8") ?>"
                                required
                            >
                        </label>

                        <label class="form-field">
                            <span data-i18n="auditTypeLabel">Denetim Türü</span>
                            <input
                                type="text"
                                name="audit_type"
                                value="<?= htmlspecialchars($auditFormData["audit_type"], ENT_QUOTES, "UTF-8") ?>"
                            >
                        </label>

                        <label class="form-field">
                            <span data-i18n="plannedDateLabel">Planlanan Tarih</span>
                            <input
                                type="date"
                                name="planned_date"
                                value="<?= htmlspecialchars($auditFormData["planned_date"], ENT_QUOTES, "UTF-8") ?>"
                            >
                        </label>
                    </div>

                    <div class="form-actions">
                        <button class="primary-button" type="submit" data-i18n="createAuditButton">Denetim Oluştur</button>
                    </div>
                </form>
            </div>

            <div class="console-card">
                <div class="section-heading compact-heading">
                    <div>
                        <h3 data-i18n="companyInfoTitle">Şirket Bilgileri</h3>
                        <p data-i18n="companyInfoText">Bu çalışma alanındaki temel şirket kayıtları.</p>
                    </div>
                </div>

                <div class="admin-list">
                    <div class="admin-list-item">
                        <div>
                            <strong data-i18n="taxNumberLabel">Vergi No</strong>
                            <span><?= htmlspecialchars($company["tax_number"] ?: "-", ENT_QUOTES, "UTF-8") ?></span>
                        </div>
                    </div>

                    <div class="admin-list-item">
                        <div>
                            <strong data-i18n="companyEmailLabel">Şirket E-postası</strong>
                            <span><?= htmlspecialchars($company["contact_email"] ?: "-", ENT_QUOTES, "UTF-8") ?></span>
                        </div>
                    </div>

                    <div class="admin-list-item">
                        <div>
                            <strong data-i18n="activeStatusLabel">Aktif</strong>
                            <span><?= (int) $company["active"] === 1 ? "Aktif" : "Pasif" ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="console-card">
                <div class="section-heading compact-heading">
                    <div>
                        <h3 data-i18n="companyAuditsTitle">Şirket Denetimleri</h3>
                        <p data-i18n="companyAuditsText">Bu şirket için planlanan denetim kayıtları.</p>
                    </div>
                </div>

                <div class="admin-list">
                    <?php if (count($audits) === 0): ?>
                        <div class="empty-state" data-i18n="noCompanyAuditsText">Bu şirkete bağlı denetim yok.</div>
                    <?php endif; ?>

                    <?php foreach ($audits as $audit): ?>
                        <a class="admin-list-item" href="audit-detail.php?id=<?= (int) $audit["id"] ?>">
                            <div>
                                <strong><?= htmlspecialchars($audit["title"], ENT_QUOTES, "UTF-8") ?></strong>
                                <span>
                                    <?= htmlspecialchars(trim(($audit["audit_type"] ?? "") . " " . ($audit["planned_date"] ?? "")), ENT_QUOTES, "UTF-8") ?>
                                </span>
                            </div>
                            <span class="status-pill"><?= htmlspecialchars($audit["status"], ENT_QUOTES, "UTF-8") ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="console-card">
                <div class="section-heading compact-heading">
                    <div>
                        <h3 data-i18n="companyAuditorsTitle">Şirket Denetçileri</h3>
                        <p data-i18n="companyAuditorsText">Bu şirkete bağlı denetçi kayıtları.</p>
                    </div>
                </div>

                <div class="admin-list">
                    <?php if (count($auditors) === 0): ?>
                        <div class="empty-state" data-i18n="noCompanyAuditorsText">Bu şirkete bağlı denetçi yok.</div>
                    <?php endif; ?>

                    <?php foreach ($auditors as $auditor): ?>
                        <div class="admin-list-item">
                            <div>
                                <strong><?= htmlspecialchars($auditor["first_name"] . " " . $auditor["last_name"], ENT_QUOTES, "UTF-8") ?></strong>
                                <span><?= htmlspecialchars($auditor["role"] . " · " . $auditor["email"], ENT_QUOTES, "UTF-8") ?></span>
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
