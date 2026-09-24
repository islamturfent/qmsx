<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/supplier-functions.php';

$userId = (int) ($_SESSION["qms_user_id"] ?? 0);
$role = qmsCurrentRole();

$statusLabels = qmsSupplierStatusLabels();
$statusI18n = qmsSupplierStatusI18nKeys();
$riskLabels = qmsSupplierRiskLabels();
$riskI18n = qmsSupplierRiskI18nKeys();

// Kapsamli liste: role gore gorunur sirketlerin tedarikcileri.
$suppliers = qmsSupplierList($pdo, $userId, $role);

// Degerlendirmeler tek sorguda cekilir; ozet ayni yardimcidan hesaplanir.
$supplierIds = array_map('intval', array_column($suppliers, 'id'));
$evaluationsBySupplier = [];
if ($supplierIds) {
    $marks = implode(',', array_fill(0, count($supplierIds), '?'));
    $evaluationStmt = $pdo->prepare(
        "SELECT * FROM supplier_evaluations
         WHERE supplier_id IN ($marks)
         ORDER BY evaluated_on DESC, id DESC"
    );
    $evaluationStmt->execute($supplierIds);
    foreach ($evaluationStmt->fetchAll(PDO::FETCH_ASSOC) as $evaluation) {
        $evaluationsBySupplier[(int) $evaluation["supplier_id"]][] = $evaluation;
    }
}

$summaries = [];
foreach ($suppliers as $supplier) {
    $summaries[(int) $supplier["id"]] = qmsSupplierEvaluationSummary($evaluationsBySupplier[(int) $supplier["id"]] ?? []);
}

// Ozet kartlari filtre uygulanmadan, kapsamin tamami uzerinden hesaplanir.
$summary = ["total" => count($suppliers), "approved" => 0, "high_risk" => 0];
$scoreTotal = 0.0;
$scoreCount = 0;
foreach ($suppliers as $supplier) {
    if ($supplier["status"] === "approved") {
        $summary["approved"]++;
    }
    if ($supplier["risk_class"] === "high") {
        $summary["high_risk"]++;
    }
    $average = $summaries[(int) $supplier["id"]]["average"];
    if ($average !== null) {
        $scoreTotal += $average;
        $scoreCount++;
    }
}
$summary["average_score"] = $scoreCount > 0 ? round($scoreTotal / $scoreCount, 1) : null;

$filters = [
    "company_id" => (int) ($_GET["company_id"] ?? 0),
    "status" => (string) ($_GET["status"] ?? ""),
    "risk_class" => (string) ($_GET["risk_class"] ?? "")
];

$visibleSuppliers = array_values(array_filter($suppliers, static function (array $supplier) use ($filters): bool {
    if ($filters["company_id"] > 0 && (int) $supplier["company_id"] !== $filters["company_id"]) {
        return false;
    }
    if ($filters["status"] !== "" && $supplier["status"] !== $filters["status"]) {
        return false;
    }
    if ($filters["risk_class"] !== "" && $supplier["risk_class"] !== $filters["risk_class"]) {
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

$activeNav = "suppliers";

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f9fafb">
    <title>QMS Tedarikçi Yönetimi</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar">
        <div class="topbar-inner">
            <div class="page-title-block">
                <strong data-i18n="suppliersTitle">Tedarikçi Yönetimi</strong>
                <span data-i18n="suppliersText">Tedarikçileri, onay durumunu ve değerlendirme puanlarını izleyin.</span>
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
                <span class="section-kicker" data-i18n="supplierRegisterKicker">Tedarikçi Kayıtları</span>
                <h1 data-i18n="suppliersTitle">Tedarikçi Yönetimi</h1>
                <p data-i18n="suppliersText">Tedarikçileri, onay durumunu ve değerlendirme puanlarını izleyin.</p>
            </div>
            <a class="primary-button" href="supplier-create.php" data-i18n="newSupplierButton">Yeni Tedarikçi</a>
        </section>

        <?php if (($_GET["supplier"] ?? "") === "created"): ?>
            <div class="form-message success" data-i18n="supplierCreatedMessage">Tedarikçi kaydı oluşturuldu.</div>
        <?php endif; ?>

        <section class="dashboard-grid compact-dashboard-grid">
            <div class="dashboard-card metric-blue">
                <?= appIcon("suppliers", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="supplierTotalLabel">Toplam Tedarikçi</span>
                    <strong class="dashboard-card-number"><?= $summary["total"] ?></strong>
                </div>
            </div>
            <div class="dashboard-card metric-teal">
                <?= appIcon("check", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="supplierApprovedLabel">Onaylı</span>
                    <strong class="dashboard-card-number"><?= $summary["approved"] ?></strong>
                </div>
            </div>
            <div class="dashboard-card metric-red">
                <?= appIcon("warning", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="supplierHighRiskLabel">Yüksek Riskli</span>
                    <strong class="dashboard-card-number"><?= $summary["high_risk"] ?></strong>
                </div>
            </div>
            <div class="dashboard-card metric-violet">
                <?= appIcon("trend", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="supplierAverageScoreLabel">Ortalama Puan</span>
                    <strong class="dashboard-card-number"><?= $summary["average_score"] === null ? "-" : htmlspecialchars((string) $summary["average_score"], ENT_QUOTES, "UTF-8") ?></strong>
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
                    <span data-i18n="supplierStatusLabel">Onay Durumu</span>
                    <select name="status">
                        <option value="" data-i18n="allOption">Tümü</option>
                        <?php foreach ($statusLabels as $value => $label): ?>
                            <option value="<?= $value ?>" <?= $filters["status"] === $value ? "selected" : "" ?> data-i18n="<?= $statusI18n[$value] ?>"><?= htmlspecialchars($label, ENT_QUOTES, "UTF-8") ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="form-field">
                    <span data-i18n="supplierRiskClassLabel">Risk Sınıfı</span>
                    <select name="risk_class">
                        <option value="" data-i18n="allOption">Tümü</option>
                        <?php foreach ($riskLabels as $value => $label): ?>
                            <option value="<?= $value ?>" <?= $filters["risk_class"] === $value ? "selected" : "" ?> data-i18n="<?= $riskI18n[$value] ?>"><?= htmlspecialchars($label, ENT_QUOTES, "UTF-8") ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <div class="form-actions">
                    <button class="primary-button" type="submit" data-i18n="applyFiltersButton">Filtrele</button>
                    <a class="secondary-button" href="suppliers.php" data-i18n="clearFiltersButton">Temizle</a>
                </div>
            </form>
        </section>

        <section class="page-section">
            <div class="record-card-grid">
                <?php if (!$visibleSuppliers): ?>
                    <div class="empty-state" data-i18n="noSuppliersText">Filtrelere uygun tedarikçi bulunamadı.</div>
                <?php endif; ?>
                <?php foreach ($visibleSuppliers as $supplier): $supplierSummary = $summaries[(int) $supplier["id"]]; ?>
                    <a class="record-card" href="supplier-detail.php?id=<?= (int) $supplier["id"] ?>">
                        <div class="record-card-topline">
                            <span><?= htmlspecialchars($supplier["company_name"], ENT_QUOTES, "UTF-8") ?></span>
                            <span class="status-badge status-<?= htmlspecialchars($supplier["status"], ENT_QUOTES, "UTF-8") ?>" data-i18n="<?= $statusI18n[$supplier["status"]] ?? "" ?>"><?= htmlspecialchars($statusLabels[$supplier["status"]] ?? $supplier["status"], ENT_QUOTES, "UTF-8") ?></span>
                        </div>
                        <div class="record-card-body">
                            <h3><?= htmlspecialchars($supplier["name"], ENT_QUOTES, "UTF-8") ?></h3>
                            <p><?= htmlspecialchars($supplier["supplier_code"] ?: "-", ENT_QUOTES, "UTF-8") ?><?= $supplier["category"] ? " · " . htmlspecialchars($supplier["category"], ENT_QUOTES, "UTF-8") : "" ?></p>
                            <span class="record-card-meta">
                                <span data-i18n="supplierRiskClassLabel">Risk Sınıfı</span>:
                                <span data-i18n="<?= $riskI18n[$supplier["risk_class"]] ?? "" ?>"><?= htmlspecialchars($riskLabels[$supplier["risk_class"]] ?? $supplier["risk_class"], ENT_QUOTES, "UTF-8") ?></span>
                                · <span data-i18n="supplierScoreLabel">Puan</span>:
                                <?= $supplierSummary["average"] === null ? '<span data-i18n="supplierNotEvaluatedValue">Değerlendirilmedi</span>' : htmlspecialchars((string) $supplierSummary["average"], ENT_QUOTES, "UTF-8") ?>
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
