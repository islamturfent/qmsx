<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/access.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/audit-log-functions.php';

$userId = (int) ($_SESSION["qms_user_id"] ?? 0);
$role = qmsCurrentRole();

// Denetim izi yonetim rolleri icindir; izin tek kaynaktan (RBAC servisi) gelir.
if (!qmsCanSession('audit_trail.view')) {
    header("Location: dashboard.php");
    exit;
}

// Filtre: yalniz onayli degerler.
$filter = [
    "company_id" => (int) ($_GET["company_id"] ?? 0),
    "entity_type" => (string) ($_GET["entity_type"] ?? ""),
    "action" => (string) ($_GET["action"] ?? ""),
    "from" => (string) ($_GET["from"] ?? ""),
    "to" => (string) ($_GET["to"] ?? ""),
];
if ($filter["entity_type"] !== "" && !isset(qmsAuditLogEntityLabels()[$filter["entity_type"]])) {
    $filter["entity_type"] = "";
}
if ($filter["action"] !== "" && !isset(qmsAuditLogActionLabels()[$filter["action"]])) {
    $filter["action"] = "";
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter["from"]) !== 1) {
    $filter["from"] = "";
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter["to"]) !== 1) {
    $filter["to"] = "";
}

$entityLabels = qmsAuditLogEntityLabels();
$actionLabels = qmsAuditLogActionLabels();
$entityIcons = qmsAuditLogEntityIcons();

// Sirket filtre listesi kapsama uyar.
$companyScope = qmsCompanyScope('companies.id', qmsVisibleCompanyIds($pdo, $userId, $role));
$companyStmt = $pdo->prepare('SELECT companies.id, companies.company_name FROM companies WHERE companies.active = 1' . $companyScope['sql'] . ' ORDER BY companies.company_name');
$companyStmt->execute($companyScope['params']);
$companies = $companyStmt->fetchAll(PDO::FETCH_ASSOC);

$rows = qmsAuditLogList($pdo, $userId, $role, $filter);

$activeNav = "audit_trail";

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f9fafb">
    <title>QuAmi Denetim İzi</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar">
        <div class="topbar-inner">
            <div class="page-title-block">
                <strong data-i18n="auditTrailTitle">Denetim İzi</strong>
                <span data-i18n="auditTrailText">Kim, neyi, ne zaman değiştirdi — salt-okunur kayıt.</span>
            </div>
            <div class="topbar-actions">
                <button class="topbar-button" id="languageToggle" type="button">EN</button>
                <button class="topbar-button" id="themeToggle" type="button" aria-label="Tema değiştir">🌙</button>
            </div>
        </div>
    </header>
    <main class="page-container">
        <section class="page-heading">
            <span class="section-kicker" data-i18n="auditTrailKicker">Sistem Yönetimi</span>
            <h1 data-i18n="auditTrailTitle">Denetim İzi</h1>
            <p data-i18n="auditTrailText">Kim, neyi, ne zaman değiştirdi bilgisini tutan, değiştirilemeyen kayıt. Bu kayıtlar salt-okunurdur ve silinemez.</p>
        </section>

        <section class="page-section form-panel">
            <form class="auditor-form filter-form" method="get" action="audit-trail.php">
                <div class="form-grid filter-grid">
                    <label class="form-field">
                        <span data-i18n="companySelectLabel">Şirket</span>
                        <select name="company_id">
                            <option value="0" data-i18n="allCompaniesOption">Tüm Şirketler</option>
                            <?php foreach ($companies as $company): ?>
                                <option value="<?= (int) $company["id"] ?>" <?= $filter["company_id"] === (int) $company["id"] ? "selected" : "" ?>><?= htmlspecialchars($company["company_name"], ENT_QUOTES, "UTF-8") ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="form-field">
                        <span data-i18n="auditEntityTypeLabel">Kayıt Türü</span>
                        <select name="entity_type">
                            <option value="" data-i18n="allTypesOption">Tümü</option>
                            <?php foreach ($entityLabels as $value => $label): ?>
                                <option value="<?= $value ?>" <?= $filter["entity_type"] === $value ? "selected" : "" ?>><?= htmlspecialchars($label, ENT_QUOTES, "UTF-8") ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="form-field">
                        <span data-i18n="auditActionLabel">İşlem</span>
                        <select name="action">
                            <option value="" data-i18n="allActionsOption">Tümü</option>
                            <?php foreach ($actionLabels as $value => $label): ?>
                                <option value="<?= $value ?>" <?= $filter["action"] === $value ? "selected" : "" ?>><?= htmlspecialchars($label, ENT_QUOTES, "UTF-8") ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="form-field">
                        <span data-i18n="fromDateLabel">Başlangıç</span>
                        <input type="date" name="from" value="<?= htmlspecialchars($filter["from"], ENT_QUOTES, "UTF-8") ?>">
                    </label>
                    <label class="form-field">
                        <span data-i18n="toDateLabel">Bitiş</span>
                        <input type="date" name="to" value="<?= htmlspecialchars($filter["to"], ENT_QUOTES, "UTF-8") ?>">
                    </label>
                </div>
                <div class="form-actions">
                    <button class="primary-button" type="submit" data-i18n="filterButton">Filtrele</button>
                    <a class="secondary-button" href="audit-trail.php" data-i18n="clearFilterButton">Temizle</a>
                </div>
            </form>
        </section>

        <section class="console-card checklist-section">
            <div class="section-heading compact-heading">
                <div>
                    <h3 data-i18n="auditTrailEntriesLabel">Denetim İzi Kayıtları</h3>
                    <p><span data-i18n="filteredRecordsLabel">Gösterilen kayıt</span>: <strong><?= count($rows) ?></strong></p>
                </div>
            </div>

            <?php if (!$rows): ?>
                <div class="empty-state" data-i18n="noAuditTrailEntriesText">Bu filtre için denetim izi kaydı bulunmuyor.</div>
            <?php else: ?>
            <div class="admin-list">
                <?php foreach ($rows as $row): ?>
                    <div class="audit-trail-row">
                        <span class="audit-trail-icon"><?= appIcon($entityIcons[$row["entity_type"]] ?? "table", "sidebar-link-icon") ?></span>
                        <div class="audit-trail-main">
                            <div class="audit-trail-head">
                                <strong><?= htmlspecialchars($entityLabels[$row["entity_type"]] ?? $row["entity_type"], ENT_QUOTES, "UTF-8") ?></strong>
                                <span class="status-pill"><?= htmlspecialchars($actionLabels[$row["action"]] ?? $row["action"], ENT_QUOTES, "UTF-8") ?></span>
                            </div>
                            <p class="audit-trail-summary"><?= htmlspecialchars($row["summary"], ENT_QUOTES, "UTF-8") ?></p>
                            <div class="audit-trail-meta">
                                <span><?= htmlspecialchars((string) ($row["actor_name"] ?? "—"), ENT_QUOTES, "UTF-8") ?></span>
                                <span><?= htmlspecialchars((string) ($row["company_name"] ?? "Sistem"), ENT_QUOTES, "UTF-8") ?></span>
                                <span><?= htmlspecialchars(date("d.m.Y H:i", strtotime($row["created_at"])), ENT_QUOTES, "UTF-8") ?></span>
                                <?php if ($row["details"]): ?><span class="audit-trail-toggle" data-details="<?= htmlspecialchars((string) $row["details"], ENT_QUOTES, "UTF-8") ?>" data-i18n="showDetailsLabel">Ayrıntı</span><?php endif; ?>
                            </div>
                            <?php if ($row["details"]): ?><pre class="audit-trail-details" hidden></pre><?php endif; ?>
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
    <script>
        document.querySelectorAll(".audit-trail-toggle").forEach(function (toggle) {
            toggle.addEventListener("click", function () {
                var details = this.nextElementSibling;
                if (!details) return;
                if (details.hasAttribute("hidden")) {
                    try {
                        var parsed = JSON.parse(this.getAttribute("data-details"));
                        details.textContent = JSON.stringify(parsed, null, 2);
                    } catch (e) {
                        details.textContent = this.getAttribute("data-details");
                    }
                    details.removeAttribute("hidden");
                } else {
                    details.setAttribute("hidden", "");
                }
            });
        });
    </script>
</body>
</html>
