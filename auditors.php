<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/access.php';

$userId = (int) ($_SESSION["qms_user_id"] ?? 0);
$isSuperAdmin = ($_SESSION["qms_role"] ?? "") === "super_admin";

// Kapsam eklenirken sorgunun bir WHERE'i olmasi gerekir; "WHERE 1 = 1" bunu saglar.
$sql = "SELECT auditors.id, auditors.first_name, auditors.last_name, auditors.email,
            auditors.telefon, auditors.role, auditors.active, companies.company_name
     FROM auditors
     LEFT JOIN companies ON companies.id = auditors.company_id
     WHERE 1 = 1";
$scope = qmsCompanyScope('auditors.company_id', qmsVisibleCompanyIds($pdo, $userId, qmsCurrentRole()));
$sql .= $scope['sql'];
$params = $scope['params'];
$sql .= " ORDER BY auditors.id DESC";

$auditorsStmt = $pdo->prepare($sql);
$auditorsStmt->execute($params);
$auditors = $auditorsStmt->fetchAll(PDO::FETCH_ASSOC);
$activeNav = "auditors";

?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f9fafb">
    <title>QMS Denetçiler</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/style.css">
</head>

<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>

    <header class="topbar">
        <div class="topbar-inner">
            <div class="page-title-block">
                <strong data-i18n="auditorsCardLabel">Denetçiler</strong>
                <span data-i18n="auditorsListText">Tüm şirketlerdeki denetçi kayıtlarını yönetin.</span>
            </div>
            <div class="topbar-actions">
                <button class="topbar-button" id="languageToggle" type="button">EN</button>
                <button class="topbar-button" id="themeToggle" type="button" aria-label="Tema değiştir">🌙</button>
            </div>
        </div>
    </header>

    <main class="page-container">
        <section class="page-heading page-heading-actions">
            <div>
                <span class="section-kicker" data-i18n="sidebarOperationsLabel">Operasyonlar</span>
                <h1 data-i18n="auditorsCardLabel">Denetçiler</h1>
                <p><span data-i18n="auditorsTotalText">Toplam denetçi</span>: <strong><?= count($auditors) ?></strong></p>
            </div>
            <a class="primary-button" href="auditor-create.php" data-i18n="newAuditorTitle">Yeni Denetçi</a>
        </section>

        <?php if (isset($_GET["auditor"]) && $_GET["auditor"] === "created"): ?>
            <div class="form-message success" data-i18n="auditorCreatedMessage">Denetçi başarıyla eklendi.</div>
        <?php endif; ?>

        <section class="record-grid">
            <?php if (count($auditors) === 0): ?>
                <div class="empty-state" data-i18n="noAuditorsText">Henüz denetçi kaydı yok.</div>
            <?php endif; ?>

            <?php foreach ($auditors as $auditor): ?>
                <article class="record-card">
                    <div class="record-card-icon metric-blue">◎</div>
                    <div class="record-card-body">
                        <span class="record-card-eyebrow"><?= htmlspecialchars($auditor["company_name"] ?: "-", ENT_QUOTES, "UTF-8") ?></span>
                        <h2><?= htmlspecialchars($auditor["first_name"] . " " . $auditor["last_name"], ENT_QUOTES, "UTF-8") ?></h2>
                        <p><?= htmlspecialchars($auditor["role"], ENT_QUOTES, "UTF-8") ?></p>
                        <span class="record-card-meta"><?= htmlspecialchars($auditor["email"], ENT_QUOTES, "UTF-8") ?></span>
                    </div>
                    <span class="status-pill" data-i18n="activeStatusLabel">Aktif</span>
                </article>
            <?php endforeach; ?>
        </section>
    </main>

    <script src="assets/js/theme.js"></script>
    <script src="assets/js/language.js"></script>
    <script src="assets/js/sidebar.js"></script>
    <script src="assets/js/pwa.js"></script>
</body>
</html>
