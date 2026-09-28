<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/access.php';
qmsRequirePermission('operations.view');
require_once __DIR__ . '/includes/quality-cost-functions.php';

$userId = (int) ($_SESSION["qms_user_id"] ?? 0);
$role = qmsCurrentRole();

// Kapsamdaki sirketler (filtre icin).
$companyScope = qmsCompanyScope('companies.id', qmsVisibleCompanyIds($pdo, $userId, $role));
$companyStmt = $pdo->prepare("SELECT companies.id, companies.company_name FROM companies WHERE companies.active = 1" . $companyScope['sql'] . ' ORDER BY companies.company_name');
$companyStmt->execute($companyScope['params']);
$companies = $companyStmt->fetchAll(PDO::FETCH_ASSOC);

// Mevcut yillar (filtre icin). Sorgu quality_costs uzerinde calistigi icin kapsam
// c.company_id uzerinden kurulur (companies.id tablosu bu sorguda yok).
$yearScope = qmsCompanyScope('c.company_id', qmsVisibleCompanyIds($pdo, $userId, $role));
$yearStmt = $pdo->prepare('SELECT DISTINCT YEAR(incurred_on) AS y FROM quality_costs c WHERE c.active = 1' . $yearScope['sql'] . ' ORDER BY y DESC');
$yearStmt->execute($yearScope['params']);
$availableYears = array_map('intval', array_filter(array_column($yearStmt->fetchAll(PDO::FETCH_ASSOC), 'y')));

$selectedYear = (int) ($_GET["year"] ?? (int) date("Y"));
if (!in_array($selectedYear, $availableYears, true) && $availableYears !== []) {
    $selectedYear = max($availableYears);
}
$selectedYear = max(2000, min(2100, $selectedYear));
$selectedCompanyId = (int) ($_GET["company_id"] ?? 0);
if (!in_array($selectedCompanyId, array_map('intval', array_column($companies, 'id')), true)) {
    $selectedCompanyId = 0;
}

$trend = qmsQualityCostMonthlyTrend($pdo, $userId, $role, $selectedYear, $selectedCompanyId);

$annualTotals = ['prevention' => 0.0, 'appraisal' => 0.0, 'internal_failure' => 0.0, 'external_failure' => 0.0];
$monthTotal = [];
foreach ($trend as $m => $val) {
    foreach ($annualTotals as $k => $unused) {
        $annualTotals[$k] += $val[$k];
    }
    $monthTotal[$m] = $val['total'];
}
$annualFailure = $annualTotals['internal_failure'] + $annualTotals['external_failure'];
$annualTotal = round(array_sum($annualTotals), 2);

$monthShortNames = [1 => 'Oca', 2 => 'Şub', 3 => 'Mar', 4 => 'Nis', 5 => 'May', 6 => 'Haz', 7 => 'Tem', 8 => 'Ağu', 9 => 'Eyl', 10 => 'Eki', 11 => 'Kas', 12 => 'Ara'];
$typeLabels = qmsCostTypeLabels();
$typeI18n = qmsCostTypeI18nKeys();
$activeNav = "quality_cost_trend";

// Ay bazinda yigili bar yuksekliklerini hesapla (maksimuma gore normalize).
$maxMonthTotal = max(1.0, max(array_column($trend, 'total')));
$segmentKeys = ['prevention', 'appraisal', 'internal_failure', 'external_failure'];

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f9fafb">
    <title>QuAmi COQ Trendi</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar">
        <div class="topbar-inner">
            <div class="page-title-block">
                <strong data-i18n="costTrendTitle">COQ Trendi</strong>
                <span data-i18n="costTrendText">Kalite maliyetlerini aylık ve kategori bazında analiz edin.</span>
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
                <h1 data-i18n="costTrendTitle">COQ Trendi</h1>
                <p data-i18n="costTrendText">Önleme, değerlendirme ve hata maliyetlerinin yıl boyunca dağılımı ve trendi.</p>
            </div>
        </section>

        <section class="filter-panel">
            <form class="filter-form report-filter-form" method="get" action="quality-cost-trend.php">
                <div class="filter-grid">
                    <label class="form-field">
                        <span data-i18n="selectYearLabel">Yıl</span>
                        <select name="year">
                            <?php if ($availableYears === []): ?>
                                <option value="<?= date("Y") ?>" selected><?= date("Y") ?></option>
                            <?php endif; ?>
                            <?php foreach ($availableYears as $y): ?>
                                <option value="<?= $y ?>" <?= $y === $selectedYear ? "selected" : "" ?>><?= $y ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="form-field">
                        <span data-i18n="companySelectLabel">Şirket</span>
                        <select name="company_id">
                            <option value="0" <?= $selectedCompanyId === 0 ? "selected" : "" ?> data-i18n="allCompaniesOption">Tüm Şirketler</option>
                            <?php foreach ($companies as $company): ?>
                                <option value="<?= (int) $company["id"] ?>" <?= (int) $company["id"] === $selectedCompanyId ? "selected" : "" ?>><?= htmlspecialchars($company["company_name"], ENT_QUOTES, "UTF-8") ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <div class="form-actions">
                        <button class="primary-button" type="submit" data-i18n="applyFilterButton">Filtrele</button>
                    </div>
                </div>
            </form>
        </section>

        <section class="dashboard-grid compact-dashboard-grid">
            <div class="dashboard-card metric-blue">
                <?= appIcon("performance", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="annualTotalCostLabel">Yıllık Toplam COQ</span>
                    <strong class="dashboard-card-number detail-card-value"><?= number_format($annualTotal, 2) ?> <small>₺</small></strong>
                </div>
            </div>
            <div class="dashboard-card metric-teal">
                <?= appIcon("check", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="qualityCostPreventionLabel">Önleme</span>
                    <strong class="dashboard-card-number detail-card-value"><?= number_format($annualTotals["prevention"], 2) ?> <small>₺</small></strong>
                </div>
            </div>
            <div class="dashboard-card metric-violet">
                <?= appIcon("check", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="qualityCostAppraisalLabel">Değerlendirme</span>
                    <strong class="dashboard-card-number detail-card-value"><?= number_format($annualTotals["appraisal"], 2) ?> <small>₺</small></strong>
                </div>
            </div>
            <div class="dashboard-card metric-red">
                <?= appIcon("warning", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="qualityCostFailureLabel">Hata Maliyeti</span>
                    <strong class="dashboard-card-number detail-card-value"><?= number_format($annualFailure, 2) ?> <small>₺</small></strong>
                </div>
            </div>
        </section>

        <article class="report-panel report-panel-wide">
            <div class="section-heading compact-heading">
                <div>
                    <h2 data-i18n="monthlyCostTrendTitle">Aylık COQ Trendi</h2>
                    <p data-i18n="monthlyCostTrendText">Seçilen yıl için kategori bazında aylık maliyet dağılımı.</p>
                </div>
            </div>
            <div class="trend-chart">
                <?php foreach ($trend as $m => $val): ?>
                    <div class="trend-column">
                        <div class="cost-trend-bars">
                            <div class="cost-bar-stack" title="<?= $typeLabels ? '' : '' ?>Aylık toplam: <?= number_format($val["total"], 2) ?> ₺">
                                <?php foreach ($segmentKeys as $seg): if ($val[$seg] <= 0) { continue; } ?>
                                    <span class="cost-stack-seg seg-<?= $seg === "internal_failure" ? "internal" : ($seg === "external_failure" ? "external" : $seg) ?>" style="height: <?= max(2, ($val[$seg] / $maxMonthTotal) * 100) ?>%;" title="<?= htmlspecialchars(($typeLabels[$seg] ?? $seg), ENT_QUOTES, "UTF-8") ?>: <?= number_format($val[$seg], 2) ?> ₺"></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <small><?= $monthShortNames[$m] ?></small>
                        <div class="cost-trend-total"><strong><?= number_format($monthTotal[$m], 0) ?></strong></div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="chart-legend">
                <span><i class="legend-blue"></i><span data-i18n="costTypePreventionLabel">Önleme</span></span>
                <span><i class="legend-teal"></i><span data-i18n="costTypeAppraisalLabel">Değerlendirme</span></span>
                <span><i class="legend-orange"></i><span data-i18n="costTypeInternalFailureLabel">İç Hata</span></span>
                <span><i class="legend-red"></i><span data-i18n="costTypeExternalFailureLabel">Dış Hata</span></span>
            </div>
        </article>

        <div class="two-col">
            <article class="report-panel">
                <div class="section-heading compact-heading">
                    <div>
                        <h2 data-i18n="annualCategoryTitle">Yıllık Kategori Dağılımı</h2>
                        <p data-i18n="annualCategoryText">Seçilen yıldaki toplam maliyet dağılımı.</p>
                    </div>
                </div>
                <div class="status-chart">
                    <?php foreach ($segmentKeys as $seg): ?>
                        <?php $share = $annualTotal > 0 ? round(($annualTotals[$seg] / $annualTotal) * 100, 1) : 0; ?>
                        <div class="status-chart-row">
                            <span data-i18n="<?= $typeI18n[$seg] ?>"><?= htmlspecialchars($typeLabels[$seg] ?? $seg, ENT_QUOTES, "UTF-8") ?></span>
                            <div><i style="width: <?= max(0, min(100, $share)) ?>%; background: <?= $seg === "prevention" ? "var(--primary-color)" : ($seg === "appraisal" ? "var(--success-color)" : ($seg === "internal_failure" ? "var(--warning-color)" : "var(--danger-color)")) ?>"></i></div>
                            <strong><?= number_format($annualTotals[$seg], 0) ?> ₺</strong>
                        </div>
                    <?php endforeach; ?>
                    <div class="status-chart-row">
                        <span data-i18n="annualTotalCostLabel">Toplam</span>
                        <div><i style="width: 100%; background: var(--muted-color);"></i></div>
                        <strong><?= number_format($annualTotal, 0) ?> ₺</strong>
                    </div>
                </div>
            </article>

            <article class="report-panel">
                <div class="section-heading compact-heading">
                    <div>
                        <h2 data-i18n="monthlyDetailTitle">Aylık Detay</h2>
                        <p data-i18n="monthlyDetailText">Her ay için kategori toplamları (₺).</p>
                    </div>
                </div>
                <div class="table-scroll">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th data-i18n="monthLabel">Ay</th>
                                <th data-i18n="costTypePreventionLabel">Önleme</th>
                                <th data-i18n="costTypeAppraisalLabel">Değerlendirme</th>
                                <th data-i18n="costTypeInternalFailureLabel">İç Hata</th>
                                <th data-i18n="costTypeExternalFailureLabel">Dış Hata</th>
                                <th data-i18n="annualTotalCostLabel">Toplam</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($trend as $m => $val): ?>
                                <tr>
                                    <td><?= $monthShortNames[$m] ?></td>
                                    <td><?= number_format($val["prevention"], 0) ?></td>
                                    <td><?= number_format($val["appraisal"], 0) ?></td>
                                    <td><?= number_format($val["internal_failure"], 0) ?></td>
                                    <td><?= number_format($val["external_failure"], 0) ?></td>
                                    <td><strong><?= number_format($val["total"], 0) ?></strong></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr>
                                <td data-i18n="annualTotalLabel"><strong>Yıllık</strong></td>
                                <td><strong><?= number_format($annualTotals["prevention"], 0) ?></strong></td>
                                <td><strong><?= number_format($annualTotals["appraisal"], 0) ?></strong></td>
                                <td><strong><?= number_format($annualTotals["internal_failure"], 0) ?></strong></td>
                                <td><strong><?= number_format($annualTotals["external_failure"], 0) ?></strong></td>
                                <td><strong><?= number_format($annualTotal, 0) ?></strong></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </article>
        </div>
    </main>
    <script src="assets/js/theme.js"></script>
    <script src="assets/js/language.js"></script>
    <script src="assets/js/sidebar.js"></script>
    <script src="assets/js/pwa.js"></script>
</body>
</html>
