<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/access.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/quality-cost-functions.php';

$userId = (int) ($_SESSION["qms_user_id"] ?? 0);
$role = qmsCurrentRole();
$csrfScope = 'quality_cost';

// Kapsam icindeki sirketler (ekleme formu icin).
$companyScope = qmsCompanyScope('companies.id', qmsVisibleCompanyIds($pdo, $userId, $role));
$companyStmt = $pdo->prepare("SELECT companies.id, companies.company_name FROM companies WHERE companies.active = 1" . $companyScope['sql'] . ' ORDER BY companies.company_name');
$companyStmt->execute($companyScope['params']);
$companies = $companyStmt->fetchAll(PDO::FETCH_ASSOC);
$allowedCompanyIds = array_map('intval', array_column($companies, 'id'));

$filterType = (string) ($_GET["type"] ?? "");
$filterFrom = (string) ($_GET["from"] ?? "");
$filterTo = (string) ($_GET["to"] ?? "");
$list = qmsQualityCostList($pdo, $userId, $role, $filterType, $filterFrom, $filterTo);
$summary = qmsQualityCostSummary($pdo, $userId, $role, $filterFrom, $filterTo);

$typeLabels = qmsCostTypeLabels();
$typeI18n = qmsCostTypeI18nKeys();

$formError = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    qmsCsrfVerify($csrfScope, $_POST["csrf"] ?? null);
    $formType = (string) ($_POST["form_type"] ?? "");

    if ($formType === "add_cost") {
        $newId = qmsQualityCostAdd($pdo, [
            "company_id" => (int) ($_POST["company_id"] ?? 0),
            "cost_type" => (string) ($_POST["cost_type"] ?? ""),
            "title" => (string) ($_POST["title"] ?? ""),
            "amount" => (string) ($_POST["amount"] ?? ""),
            "incurred_on" => (string) ($_POST["incurred_on"] ?? ""),
            "notes" => (string) ($_POST["notes"] ?? ""),
        ], $userId, $role);

        if ($newId !== null) {
            header("Location: quality-costs.php?type=" . urlencode($filterType) . "&from=" . urlencode($filterFrom) . "&to=" . urlencode($filterTo) . "&added=1");
            exit;
        }
        $formError = "Maliyet kaydı eklenemedi. Geçerli bir şirket, tutar, başlık ve tarih girin.";
    } elseif ($formType === "delete_cost") {
        $costId = (int) ($_POST["cost_id"] ?? 0);
        qmsQualityCostDelete($pdo, $costId, $userId, $role);
        header("Location: quality-costs.php?type=" . urlencode($filterType) . "&from=" . urlencode($filterFrom) . "&to=" . urlencode($filterTo) . "&deleted=1");
        exit;
    } else {
        $formError = "Geçersiz istek.";
    }
}

$activeNav = "quality_costs";
$selectedCompanyId = (int) ($_GET["company_id"] ?? 0);

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f9fafb">
    <title>QuAmi Kalite Maliyeti (COQ)</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar">
        <div class="topbar-inner">
            <div class="page-title-block">
                <strong data-i18n="qualityCostTitle">Kalite Maliyeti (COQ)</strong>
                <span data-i18n="qualityCostText">Kalite maliyetlerini kategori bazında izleyin.</span>
            </div>
            <div class="topbar-actions">
                <button class="topbar-button" id="languageToggle" type="button">EN</button>
                <button class="topbar-button" id="themeToggle" type="button" aria-label="Tema değiştir">🌙</button>
            </div>
        </div>
    </header>
    <main class="page-container">
        <section class="page-heading">
            <div>
                <span class="section-kicker" data-i18n="qualityCostKicker">Kalite Yönetimi</span>
                <h1 data-i18n="qualityCostTitle">Kalite Maliyeti (COQ)</h1>
                <p data-i18n="qualityCostText">Önleme, değerlendirme ve hata maliyetlerini dönem bazında kaydedin ve toplayın.</p>
            </div>
        </section>

        <section class="dashboard-grid compact-dashboard-grid">
            <div class="dashboard-card metric-blue">
                <?= appIcon("performance", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="qualityCostTotalLabel">Toplam COQ</span>
                    <strong class="dashboard-card-number detail-card-value"><?= number_format((float) $summary["total"], 2) ?> <small>₺</small></strong>
                </div>
            </div>
            <div class="dashboard-card metric-teal">
                <?= appIcon("check", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="qualityCostPreventionLabel">Önleme</span>
                    <strong class="dashboard-card-number detail-card-value"><?= number_format((float) $summary["prevention"], 2) ?> <small>₺</small></strong>
                </div>
            </div>
            <div class="dashboard-card metric-violet">
                <?= appIcon("check", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="qualityCostAppraisalLabel">Değerlendirme</span>
                    <strong class="dashboard-card-number detail-card-value"><?= number_format((float) $summary["appraisal"], 2) ?> <small>₺</small></strong>
                </div>
            </div>
            <div class="dashboard-card metric-red">
                <?= appIcon("warning", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="qualityCostFailureLabel">Hata Maliyeti</span>
                    <strong class="dashboard-card-number detail-card-value"><?= number_format((float) $summary["failure_total"], 2) ?> <small>₺</small></strong>
                </div>
            </div>
        </section>

        <section class="filter-panel">
            <form class="filter-form report-filter-form" method="get" action="quality-costs.php">
                <div class="filter-grid">
                    <label class="form-field"><span data-i18n="qualityCostTypeFilterLabel">Kategori</span><select name="type"><option value="" data-i18n="qualityCostAllTypesOption">Tümü</option><?php foreach ($typeLabels as $key => $label): ?><option value="<?= $key ?>" <?= $filterType === $key ? "selected" : "" ?>><?= htmlspecialchars($label, ENT_QUOTES, "UTF-8") ?></option><?php endforeach; ?></select></label>
                    <label class="form-field"><span data-i18n="qualityCostFromLabel">Başlangıç</span><input type="date" name="from" value="<?= htmlspecialchars($filterFrom, ENT_QUOTES, "UTF-8") ?>"></label>
                    <label class="form-field"><span data-i18n="qualityCostToLabel">Bitiş</span><input type="date" name="to" value="<?= htmlspecialchars($filterTo, ENT_QUOTES, "UTF-8") ?>"></label>
                    <div class="form-actions"><button class="primary-button" type="submit" data-i18n="applyFilterButton">Filtrele</button></div>
                </div>
            </form>
        </section>

        <?php if ($formError !== ""): ?><div class="form-message error"><?= htmlspecialchars($formError, ENT_QUOTES, "UTF-8") ?></div><?php endif; ?>
        <?php if (($_GET["added"] ?? "") === "1"): ?><div class="form-message success" data-i18n="qualityCostAddedMessage">Maliyet kaydı eklendi.</div><?php endif; ?>
        <?php if (($_GET["deleted"] ?? "") === "1"): ?><div class="form-message success" data-i18n="qualityCostDeletedMessage">Maliyet kaydı silindi.</div><?php endif; ?>

        <div class="two-col">
            <section class="form-panel">
                <div class="section-heading compact-heading">
                    <div>
                        <h3 data-i18n="qualityCostAddTitle">Maliyet Kaydı Ekle</h3>
                        <p data-i18n="qualityCostAddText">Bir kalite maliyeti kalemini kaydedin (₺).</p>
                    </div>
                </div>
                <?php if (!$companies): ?>
                    <div class="form-message error" data-i18n="qualityCostNoCompanyText">Maliyet eklemek için önce bir şirket gerekir.</div>
                <?php else: ?>
                <form class="auditor-form" method="post" action="quality-costs.php?type=<?= urlencode($filterType) ?>&from=<?= urlencode($filterFrom) ?>&to=<?= urlencode($filterTo) ?>">
                    <?= qmsCsrfField($csrfScope) ?>
                    <input type="hidden" name="form_type" value="add_cost">
                    <div class="form-grid">
                        <label class="form-field"><span data-i18n="companySelectLabel">Şirket</span><select name="company_id" required><option value="0" data-i18n="selectCompanyOption">Şirket seçin</option><?php foreach ($companies as $company): ?><option value="<?= (int) $company["id"] ?>"><?= htmlspecialchars($company["company_name"], ENT_QUOTES, "UTF-8") ?></option><?php endforeach; ?></select></label>
                        <label class="form-field"><span data-i18n="qualityCostTypeLabel">Kategori</span><select name="cost_type" required><?php foreach ($typeLabels as $key => $label): ?><option value="<?= $key ?>"><?= htmlspecialchars($label, ENT_QUOTES, "UTF-8") ?></option><?php endforeach; ?></select></label>
                        <label class="form-field"><span data-i18n="qualityCostTitleLabel">Başlık</span><input type="text" name="title" required maxlength="255"></label>
                        <label class="form-field"><span data-i18n="qualityCostAmountLabel">Tutar (₺)</span><input type="number" step="0.01" min="0" name="amount" required></label>
                        <label class="form-field"><span data-i18n="qualityCostIncurredLabel">Tarih</span><input type="date" name="incurred_on" value="<?= date("Y-m-d") ?>" required></label>
                        <label class="form-field form-field-wide"><span data-i18n="qualityCostNotesLabel">Notlar</span><input type="text" name="notes" maxlength="4000"></label>
                    </div>
                    <div class="form-actions">
                        <button class="primary-button" type="submit" data-i18n="addQualityCostButton">Maliyet Ekle</button>
                    </div>
                </form>
                <?php endif; ?>
            </section>

            <section class="console-card checklist-section">
                <div class="section-heading compact-heading">
                    <div>
                        <h3 data-i18n="qualityCostListTitle">Maliyet Kayıtları</h3>
                        <p><span data-i18n="filteredRecordsLabel">Gösterilen kayıt</span>: <strong><?= (int) $summary["entry_count"] ?></strong></p>
                    </div>
                </div>
                <div class="admin-list">
                    <?php if (!$list): ?>
                        <div class="empty-state" data-i18n="noQualityCostsText">Filtrelere uygun maliyet kaydı bulunamadı.</div>
                    <?php endif; ?>
                    <?php foreach ($list as $cost): ?>
                        <div class="admin-list-item">
                            <div class="list-item-main">
                                <strong><?= htmlspecialchars($cost["title"], ENT_QUOTES, "UTF-8") ?></strong>
                                <span><?= htmlspecialchars($typeLabels[$cost["cost_type"]] ?? $cost["cost_type"], ENT_QUOTES, "UTF-8") ?> · <?= htmlspecialchars($cost["company_name"], ENT_QUOTES, "UTF-8") ?> · <?= htmlspecialchars($cost["incurred_on"], ENT_QUOTES, "UTF-8") ?></span>
                            </div>
                            <div class="list-item-side">
                                <span class="status-pill"><?= number_format((float) $cost["amount"], 2) ?> ₺</span>
                                <form method="post" action="quality-costs.php?type=<?= urlencode($filterType) ?>&from=<?= urlencode($filterFrom) ?>&to=<?= urlencode($filterTo) ?>" onsubmit="return confirm('Maliyet kaydını silsin mi?');">
                                    <?= qmsCsrfField($csrfScope) ?>
                                    <input type="hidden" name="form_type" value="delete_cost">
                                    <input type="hidden" name="cost_id" value="<?= (int) $cost["id"] ?>">
                                    <button class="danger-button danger-button-sm" type="submit" data-i18n="deleteQualityCostButton">Sil</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>
    </main>
    <script src="assets/js/theme.js"></script>
    <script src="assets/js/language.js"></script>
    <script src="assets/js/sidebar.js"></script>
    <script src="assets/js/pwa.js"></script>
</body>
</html>
