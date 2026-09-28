<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/access.php';
require_once __DIR__ . '/includes/permissions.php';

// Bu ekran şirket kullanıcısı rolü içindir; Dashboard erişim iznini kullanır.
if (!qmsCanSession('dashboard.view') || qmsCurrentRole() !== 'company_user') {
    header("Location: dashboard.php");
    exit;
}

$userId = (int) ($_SESSION["qms_user_id"] ?? 0);
$role = qmsCurrentRole();
$companyIds = qmsVisibleCompanyIds($pdo, $userId, $role);
$companyId = (int) ($companyIds[0] ?? 0);

$companyName = '';
if ($companyId > 0) {
    $nameStmt = $pdo->prepare("SELECT company_name FROM companies WHERE id = ? LIMIT 1");
    $nameStmt->execute([$companyId]);
    $companyName = (string) $nameStmt->fetchColumn();
}

// Şirket kapsamı tek şirkettir; scope tek kaynaktan gelir.
$scope = $companyId > 0 ? ' AND company_id = ' . $companyId : ' AND 1 = 0';

$scoped = static function (string $sql) use ($pdo, $scope): int {
    $stmt = $pdo->query($sql . $scope);
    return (int) $stmt->fetchColumn();
};

$kpi = [
    'nonconformities' => $scoped("SELECT COUNT(*) FROM nonconformities WHERE active = 1 AND status <> 'closed'"),
    'complaints' => $scoped("SELECT COUNT(*) FROM complaints WHERE active = 1 AND status <> 'closed'"),
    'risks' => $scoped("SELECT COUNT(*) FROM risks WHERE active = 1 AND status <> 'closed'"),
    'documents' => $scoped("SELECT COUNT(*) FROM documents WHERE active = 1"),
    'calibration_overdue' => $scoped("SELECT COUNT(*) FROM instruments WHERE active = 1 AND next_calibration_date < CURDATE()"),
    'contracts_expired' => $scoped("SELECT COUNT(*) FROM contracts WHERE active = 1 AND end_date < CURDATE()"),
    'audits' => $scoped("SELECT COUNT(*) FROM audits WHERE active = 1"),
    'suppliers' => $scoped("SELECT COUNT(*) FROM suppliers WHERE active = 1"),
];

$activeNav = "dashboard";

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f9fafb">
    <title>QuAmi Şirket Panosu</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar">
        <div class="topbar-inner">
            <div class="page-title-block">
                <strong data-i18n="companyOverviewTitle">Şirket Panosu</strong>
                <span data-i18n="companyOverviewSubtitle"><?= htmlspecialchars($companyName !== '' ? $companyName : 'Şirket özeti', ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <div class="topbar-actions">
                <button class="topbar-button" id="languageToggle" type="button">EN</button>
                <button class="topbar-button" id="themeToggle" type="button" aria-label="Tema değiştir">🌙</button>
            </div>
        </div>
    </header>
    <main class="page-container">
        <section class="page-heading">
            <span class="section-kicker" data-i18n="companyOverviewKicker">Şirket</span>
            <h1 data-i18n="companyOverviewTitle">Şirket Panosu</h1>
            <p data-i18n="companyOverviewText">Şirketinizin kalite süreçlerine dair güncel görünüm.</p>
        </section>

        <section class="dashboard-grid compact-dashboard-grid">
            <a class="dashboard-card" href="actions.php">
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="nonconformityOpenLabel">Açık Uygunsuzluk</span>
                    <strong class="dashboard-card-number"><?= $kpi['nonconformities'] ?></strong>
                </div>
            </a>
            <a class="dashboard-card" href="complaints.php">
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="complaintsTitle">Açık Şikayet</span>
                    <strong class="dashboard-card-number"><?= $kpi['complaints'] ?></strong>
                </div>
            </a>
            <a class="dashboard-card" href="risks.php">
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="riskOpenLabel">Açık Risk</span>
                    <strong class="dashboard-card-number"><?= $kpi['risks'] ?></strong>
                </div>
            </a>
            <a class="dashboard-card" href="documents.php">
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="dashboardDocumentsLabel">Aktif Doküman</span>
                    <strong class="dashboard-card-number"><?= $kpi['documents'] ?></strong>
                </div>
            </a>
            <a class="dashboard-card <?= $kpi['calibration_overdue'] > 0 ? 'metric-orange' : '' ?>" href="instruments.php">
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="overdueCalibrationLabel">Kalibrasyonu Geçen Alet</span>
                    <strong class="dashboard-card-number"><?= $kpi['calibration_overdue'] ?></strong>
                </div>
            </a>
            <a class="dashboard-card <?= $kpi['contracts_expired'] > 0 ? 'metric-orange' : '' ?>" href="contracts.php">
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="expiredContractsLabel">Süresi Dolan Sözleşme</span>
                    <strong class="dashboard-card-number"><?= $kpi['contracts_expired'] ?></strong>
                </div>
            </a>
            <a class="dashboard-card" href="audit-programs.php">
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="auditProgramsMenuLabel">Denetimler</span>
                    <strong class="dashboard-card-number"><?= $kpi['audits'] ?></strong>
                </div>
            </a>
            <a class="dashboard-card" href="suppliers.php">
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="suppliersTitle">Tedarikçiler</span>
                    <strong class="dashboard-card-number"><?= $kpi['suppliers'] ?></strong>
                </div>
            </a>
        </section>
    </main>
    <script src="assets/js/theme.js"></script>
    <script src="assets/js/language.js"></script>
    <script src="assets/js/sidebar.js"></script>
    <script src="assets/js/pwa.js"></script>
</body>
</html>
