<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/access.php';
require_once __DIR__ . '/includes/permissions.php';
qmsRequirePermission('operations.view');

$userId = (int) ($_SESSION["qms_user_id"] ?? 0);
$role = qmsCurrentRole();

$companyScope = qmsCompanyScope('co.id', qmsVisibleCompanyIds($pdo, $userId, $role));
$companyStmt = $pdo->prepare('SELECT companies.id, companies.company_name FROM companies WHERE companies.active = 1' . $companyScope['sql'] . ' ORDER BY companies.company_name');
$companyStmt->execute($companyScope['params']);
$companies = $companyStmt->fetchAll(PDO::FETCH_ASSOC);
$selectedCompanyId = (int) ($_GET["company_id"] ?? 0);
if (!in_array($selectedCompanyId, array_map('intval', array_column($companies, 'id')), true)) {
    $selectedCompanyId = 0;
}

// Dogrulama bekleyen faaliyetler (CAPA durumu verification).
$vParams = $companyScope['params'];
$vWhere = "ca.active = 1 AND ca.status = 'verification'" . $companyScope['sql'];
if ($selectedCompanyId > 0) {
    $vWhere .= ' AND n.company_id = ?';
    $vParams[] = $selectedCompanyId;
}
$vSql = "SELECT ca.id, ca.action_text, ca.due_date, ca.verifier_name,
            n.id AS nc_id, n.title AS nc_title, n.severity, co.company_name
     FROM corrective_actions ca
     INNER JOIN nonconformities n ON n.id = ca.nonconformity_id
     INNER JOIN companies co ON co.id = n.company_id
     WHERE " . $vWhere . "
     ORDER BY co.company_name, ca.due_date ASC, ca.id ASC";
$vStmt = $pdo->prepare($vSql);
$vStmt->execute($vParams);
$verificationActions = $vStmt->fetchAll(PDO::FETCH_ASSOC);

// Acik uygunsuzluk kapanis hatti (kok neden + acik CAPA sayisi ile).
$nParams = $companyScope['params'];
$nWhere = "n.active = 1 AND n.status <> 'closed'" . $companyScope['sql'];
if ($selectedCompanyId > 0) {
    $nWhere .= ' AND n.company_id = ?';
    $nParams[] = $selectedCompanyId;
}
$nSql = "SELECT n.id, n.title, n.severity, n.status AS nc_status, n.due_date, co.company_name,
            (SELECT COUNT(*) FROM nc_root_cause rc WHERE rc.nonconformity_id = n.id AND rc.active = 1) AS rootcause_count,
            (SELECT COUNT(*) FROM corrective_actions ca2 WHERE ca2.nonconformity_id = n.id AND ca2.active = 1 AND ca2.status NOT IN ('completed','closed')) AS open_capa
     FROM nonconformities n
     INNER JOIN companies co ON co.id = n.company_id
     WHERE " . $nWhere . "
     ORDER BY n.due_date IS NULL, n.due_date ASC, n.id DESC";
$nStmt = $pdo->prepare($nSql);
$nStmt->execute($nParams);
$openNc = $nStmt->fetchAll(PDO::FETCH_ASSOC);

$statusLabels = ['open' => 'Açık', 'in_progress' => 'Çalışılıyor', 'verification' => 'Doğrulama', 'closed' => 'Kapalı'];
$activeNav = "verification_center";

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f9fafb">
    <title>QuAmi Doğrulama & Kapanış Merkezi</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar">
        <div class="topbar-inner">
            <div class="page-title-block">
                <strong data-i18n="verificationCenterTitle">Doğrulama & Kapanış Merkezi</strong>
                <span data-i18n="verificationCenterText">CAPA doğrulama ve uygunsuzluk kapanışını izleyin.</span>
            </div>
            <div class="topbar-actions">
                <button class="topbar-button" id="languageToggle" type="button">EN</button>
                <button class="topbar-button" id="themeToggle" type="button" aria-label="Tema değiştir">🌙</button>
            </div>
        </div>
    </header>
    <main class="page-container">
        <section class="page-heading">
            <span class="section-kicker" data-i18n="verificationCenterKicker">Kapanış Takibi</span>
            <h1 data-i18n="verificationCenterTitle">Doğrulama & Kapanış Merkezi</h1>
            <p data-i18n="verificationCenterText">CAPA doğrulama ve uygunsuzluk kapanışını izleyin.</p>
        </section>

        <section class="dashboard-grid compact-dashboard-grid">
            <div class="dashboard-card metric-orange">
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="verificationCenterPendingVerify">Doğrulama Bekleyen</span>
                    <strong class="dashboard-card-number"><?= count($verificationActions) ?></strong>
                </div>
            </div>
            <div class="dashboard-card metric-blue">
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="verificationCenterOpenNc">Açık Uygunsuzluk</span>
                    <strong class="dashboard-card-number"><?= count($openNc) ?></strong>
                </div>
            </div>
        </section>

        <section class="page-section console-card">
            <div class="filter-tabs">
                <form class="auditor-form finding-filter-form" method="get" action="verification-center.php">
                    <select name="company_id">
                        <option value="0" data-i18n="allCompaniesOption">Tüm Şirketler</option>
                        <?php foreach ($companies as $c): ?>
                            <option value="<?= (int) $c["id"] ?>" <?= $selectedCompanyId === (int) $c["id"] ? "selected" : "" ?>><?= htmlspecialchars($c["company_name"], ENT_QUOTES, "UTF-8") ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="primary-button primary-button-sm" type="submit" data-i18n="applyFiltersButton">Filtrele</button>
                </form>
            </div>

            <div class="section-heading compact-heading">
                <div><h3 data-i18n="verificationCenterVerifyTitle">Doğrulama Bekleyen Faaliyetler</h3><p data-i18n="verificationCenterVerifyText">Kanıt yüklenmiş, doğrulama bekleyen düzeltici faaliyetler.</p></div>
            </div>
            <?php if (!$verificationActions): ?>
                <div class="empty-state" data-i18n="verificationCenterNoVerify">Doğrulama bekleyen faaliyet yok.</div>
            <?php else: ?>
                <div class="admin-list">
                    <?php foreach ($verificationActions as $a): ?>
                        <a class="admin-list-item" href="corrective-action-detail.php?id=<?= (int) $a["id"] ?>">
                            <div class="list-item-main">
                                <strong><?= htmlspecialchars($a["action_text"], ENT_QUOTES, "UTF-8") ?></strong>
                                <span><?= htmlspecialchars((string) $a["company_name"] . " · " . (string) $a["nc_title"] . " (" . (string) $a["severity"] . ")", ENT_QUOTES, "UTF-8") ?></span>
                            </div>
                            <div class="list-item-side"><span class="status-pill"><?= htmlspecialchars((string) ($a["due_date"] ?? "-"), ENT_QUOTES, "UTF-8") ?></span></div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="section-heading compact-heading">
                <div><h3 data-i18n="verificationCenterClosureTitle">Açık Uygunsuzluk Kapanış Hattı</h3><p data-i18n="verificationCenterClosureText">Kök neden + açık CAPA + kapanış paketi durumu.</p></div>
            </div>
            <?php if (!$openNc): ?>
                <div class="empty-state" data-i18n="verificationCenterNoNc">Açık uygunsuzluk yok.</div>
            <?php else: ?>
                <div class="admin-list">
                    <?php foreach ($openNc as $nc): ?>
                        <div class="admin-list-item">
                            <a class="list-item-main" href="nonconformity-detail.php?id=<?= (int) $nc["id"] ?>">
                                <strong><?= htmlspecialchars($nc["title"], ENT_QUOTES, "UTF-8") ?></strong>
                                <span><?= htmlspecialchars((string) $nc["company_name"] . " · " . (string) $statusLabels[$nc["nc_status"]] . " · " . (string) $nc["severity"], ENT_QUOTES, "UTF-8") ?></span>
                            </a>
                            <div class="list-item-side">
                                <span class="status-pill"><?= (int) $nc["rootcause_count"] > 0 ? "Kök Neden ✓" : "Kök Neden ✗" ?></span>
                                <span class="status-pill"><?= (int) $nc["open_capa"] ?> açık CAPA</span>
                                <a class="secondary-button secondary-button-sm" href="closure-package-export.php?nonconformity_id=<?= (int) $nc["id"] ?>" data-i18n="closurePackageButton">Paket (PDF)</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </main>
    <script src="assets/js/theme.js"></script>
    <script src="assets/js/language.js"></script>
    <script src="assets/js/sidebar.js"></script>
    <script src="assets/js/pwa.js"></script>
</body>
</html>
