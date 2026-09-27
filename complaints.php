<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/complaint-functions.php';

$userId = (int) ($_SESSION["qms_user_id"] ?? 0);
$role = qmsCurrentRole();

$statusLabels = qmsComplaintStatusLabels();
$statusI18n = qmsComplaintStatusI18nKeys();
$severityLabels = qmsComplaintSeverityLabels();
$severityI18n = qmsComplaintSeverityI18nKeys();
$sourceLabels = qmsComplaintSourceLabels();
$sourceI18n = qmsComplaintSourceI18nKeys();

// Kapsamli liste: role gore gorunur sirketlerin sikayetleri.
$complaints = qmsComplaintList($pdo, $userId, $role);

// Ozet kartlari filtre uygulanmadan, kapsamin tamami uzerinden hesaplanir.
$summary = ["total" => count($complaints), "open" => 0, "critical" => 0, "closed" => 0];
foreach ($complaints as $complaint) {
    if (qmsComplaintIsOpen((string) $complaint["status"])) {
        $summary["open"]++;
    }
    if ($complaint["status"] === "closed") {
        $summary["closed"]++;
    }
    if ($complaint["severity"] === "critical") {
        $summary["critical"]++;
    }
}

$filters = [
    "company_id" => (int) ($_GET["company_id"] ?? 0),
    "status" => (string) ($_GET["status"] ?? ""),
    "severity" => (string) ($_GET["severity"] ?? ""),
    "source" => (string) ($_GET["source"] ?? "")
];

$visibleComplaints = array_values(array_filter($complaints, static function (array $complaint) use ($filters): bool {
    if ($filters["company_id"] > 0 && (int) $complaint["company_id"] !== $filters["company_id"]) {
        return false;
    }
    if ($filters["status"] !== "" && $complaint["status"] !== $filters["status"]) {
        return false;
    }
    if ($filters["severity"] !== "" && $complaint["severity"] !== $filters["severity"]) {
        return false;
    }
    if ($filters["source"] !== "" && $complaint["source"] !== $filters["source"]) {
        return false;
    }

    return true;
}));

$companyScope = qmsCompanyScope("companies.id", qmsVisibleCompanyIds($pdo, $userId, $role));
$companyStmt = $pdo->prepare(
    "SELECT companies.id, companies.company_name
     FROM companies
     WHERE companies.active = 1" . $companyScope["sql"] . "
     ORDER BY companies.company_name"
);
$companyStmt->execute($companyScope["params"]);
$companies = $companyStmt->fetchAll(PDO::FETCH_ASSOC);

$activeNav = "complaints";

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f9fafb">
    <title>QuAmi Şikayet Yönetimi</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar">
        <div class="topbar-inner">
            <div class="page-title-block">
                <strong data-i18n="complaintsTitle">Şikayet Yönetimi</strong>
                <span data-i18n="complaintsText">Şikayetleri kaydedin, uygunsuzlukla ilişkilendirin ve kapanışı izleyin.</span>
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
                <span class="section-kicker" data-i18n="complaintRegisterKicker">Şikayet Kayıtları</span>
                <h1 data-i18n="complaintsTitle">Şikayet Yönetimi</h1>
                <p data-i18n="complaintsText">Şikayetleri kaydedin, uygunsuzlukla ilişkilendirin ve kapanışı izleyin.</p>
            </div>
            <a class="primary-button" href="complaint-create.php" data-i18n="newComplaintButton">Yeni Şikayet</a>
        </section>

        <?php if (($_GET["complaint"] ?? "") === "created"): ?>
            <div class="form-message success" data-i18n="complaintCreatedMessage">Şikayet kaydı oluşturuldu.</div>
        <?php endif; ?>

        <section class="dashboard-grid compact-dashboard-grid">
            <div class="dashboard-card metric-blue">
                <?= appIcon("complaints", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="complaintTotalLabel">Toplam Şikayet</span>
                    <strong class="dashboard-card-number"><?= $summary["total"] ?></strong>
                </div>
            </div>
            <div class="dashboard-card metric-orange">
                <?= appIcon("clock", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="complaintOpenLabel">Açık Şikayet</span>
                    <strong class="dashboard-card-number"><?= $summary["open"] ?></strong>
                </div>
            </div>
            <div class="dashboard-card metric-red">
                <?= appIcon("warning", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="complaintCriticalLabel">Kritik</span>
                    <strong class="dashboard-card-number"><?= $summary["critical"] ?></strong>
                </div>
            </div>
            <div class="dashboard-card metric-teal">
                <?= appIcon("check", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="complaintClosedLabel">Kapatılan</span>
                    <strong class="dashboard-card-number"><?= $summary["closed"] ?></strong>
                </div>
            </div>
        </section>

        <section class="form-panel">
            <form method="get" class="filter-grid">
                <label class="form-field">
                    <span data-i18n="companySelectLabel">Şirket</span>
                    <select name="company_id">
                        <option value="0" data-i18n="allCompaniesOption">Tüm şirketler</option>
                        <?php foreach ($companies as $company): ?>
                            <option value="<?= (int) $company["id"] ?>" <?= $filters["company_id"] === (int) $company["id"] ? "selected" : "" ?>><?= htmlspecialchars($company["company_name"], ENT_QUOTES, "UTF-8") ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="form-field">
                    <span data-i18n="complaintStatusLabel">Durum</span>
                    <select name="status">
                        <option value="" data-i18n="allOption">Tümü</option>
                        <?php foreach ($statusLabels as $value => $label): ?>
                            <option value="<?= $value ?>" <?= $filters["status"] === $value ? "selected" : "" ?> data-i18n="<?= $statusI18n[$value] ?>"><?= htmlspecialchars($label, ENT_QUOTES, "UTF-8") ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="form-field">
                    <span data-i18n="complaintSeverityLabel">Önem</span>
                    <select name="severity">
                        <option value="" data-i18n="allOption">Tümü</option>
                        <?php foreach ($severityLabels as $value => $label): ?>
                            <option value="<?= $value ?>" <?= $filters["severity"] === $value ? "selected" : "" ?> data-i18n="<?= $severityI18n[$value] ?>"><?= htmlspecialchars($label, ENT_QUOTES, "UTF-8") ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="form-field">
                    <span data-i18n="complaintSourceLabel">Kaynak</span>
                    <select name="source">
                        <option value="" data-i18n="allOption">Tümü</option>
                        <?php foreach ($sourceLabels as $value => $label): ?>
                            <option value="<?= $value ?>" <?= $filters["source"] === $value ? "selected" : "" ?> data-i18n="<?= $sourceI18n[$value] ?>"><?= htmlspecialchars($label, ENT_QUOTES, "UTF-8") ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <div class="form-actions">
                    <button class="primary-button" type="submit" data-i18n="applyFiltersButton">Filtrele</button>
                    <a class="secondary-button" href="complaints.php" data-i18n="clearFiltersButton">Temizle</a>
                </div>
            </form>
        </section>

        <section class="page-section">
            <div class="record-card-grid">
                <?php if (!$visibleComplaints): ?>
                    <div class="empty-state" data-i18n="noComplaintsText">Filtrelere uygun şikayet bulunamadı.</div>
                <?php endif; ?>
                <?php foreach ($visibleComplaints as $complaint): ?>
                    <a class="record-card" href="complaint-detail.php?id=<?= (int) $complaint["id"] ?>">
                        <div class="record-card-topline">
                            <span><?= htmlspecialchars($complaint["company_name"], ENT_QUOTES, "UTF-8") ?></span>
                            <span class="status-badge status-<?= htmlspecialchars($complaint["status"], ENT_QUOTES, "UTF-8") ?>" data-i18n="<?= $statusI18n[$complaint["status"]] ?? "" ?>"><?= htmlspecialchars($statusLabels[$complaint["status"]] ?? $complaint["status"], ENT_QUOTES, "UTF-8") ?></span>
                        </div>
                        <div class="record-card-body">
                            <h3><?= htmlspecialchars($complaint["subject"], ENT_QUOTES, "UTF-8") ?></h3>
                            <p><?= htmlspecialchars($complaint["complaint_code"] ?: "-", ENT_QUOTES, "UTF-8") ?><?= $complaint["customer_name"] ? " · " . htmlspecialchars($complaint["customer_name"], ENT_QUOTES, "UTF-8") : "" ?></p>
                            <span class="record-card-meta">
                                <span data-i18n="receivedDateLabel">Alınma Tarihi</span>:
                                <?= htmlspecialchars($complaint["received_date"], ENT_QUOTES, "UTF-8") ?>
                                · <span data-i18n="<?= $severityI18n[$complaint["severity"]] ?? "" ?>"><?= htmlspecialchars($severityLabels[$complaint["severity"]] ?? $complaint["severity"], ENT_QUOTES, "UTF-8") ?></span>
                                <?php if ((int) $complaint["nonconformity_id"] > 0): ?>
                                    · <span data-i18n="complaintLinkedNonconformityLabel">Uygunsuzluğa bağlı</span>
                                    <?php if ((int) $complaint["linked_actions"] > 0): ?>
                                        · <?= (int) $complaint["linked_actions"] ?> <span data-i18n="complaintLinkedActionsLabel">düzeltici faaliyet</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </span>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    </main>
    <script src="assets/js/theme.js"></script>
    <script src="assets/js/language.js"></script>
    <script src="assets/js/sidebar.js"></script>
    <script src="assets/js/pwa.js"></script>
</body>
</html>
