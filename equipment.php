<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/access.php';
qmsRequirePermission('operations.view');
require_once __DIR__ . '/includes/equipment-functions.php';

$userId = (int) ($_SESSION["qms_user_id"] ?? 0);
$role = qmsCurrentRole();

// Filtre: yalniz gecerli kalibrasyon durumu.
$filterStatus = (string) ($_GET["cal_status"] ?? "");
if ($filterStatus !== "" && !in_array($filterStatus, QMS_EQUIPMENT_CAL_STATUSES, true)) {
    $filterStatus = "";
}

$equipment = qmsEquipmentList($pdo, $userId, $role);
if ($filterStatus !== "") {
    $equipment = array_values(array_filter($equipment, static fn(array $item): bool => $item["cal_status"] === $filterStatus));
}

// Ozet kartlari.
$total = count($equipment);
$counts = ['calibrated' => 0, 'due_soon' => 0, 'overdue' => 0, 'not_scheduled' => 0];
foreach ($equipment as $item) {
    $counts[$item["cal_status"]]++;
}

$calStatusLabels = qmsEquipmentCalibrationStatusLabels();
$activeNav = "equipment";

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f9fafb">
    <title>QuAmi Ekipman ve Kalibrasyon</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar">
        <div class="topbar-inner">
            <div class="page-title-block">
                <strong data-i18n="equipmentTitle">Ekipman ve Kalibrasyon</strong>
                <span data-i18n="equipmentText">Ekipman kalibrasyon terminlerini izleyin.</span>
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
                <span class="section-kicker" data-i18n="equipmentKicker">Altyapı ve Ölçüm</span>
                <h1 data-i18n="equipmentTitle">Ekipman ve Kalibrasyon</h1>
                <p data-i18n="equipmentText">Ekipman kayıtlarını ve kalibrasyon terminlerini yönetin; süresi geçenleri takip edin.</p>
            </div>
            <a class="secondary-button" href="equipment-create.php" data-i18n="createEquipmentButton">Yeni Ekipman</a>
        </section>

        <section class="dashboard-grid compact-dashboard-grid">
            <div class="dashboard-card metric-blue">
                <?= appIcon("table", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="equipmentTotalLabel">Toplam Ekipman</span>
                    <strong class="dashboard-card-number detail-card-value"><?= $total ?></strong>
                </div>
            </div>
            <div class="dashboard-card metric-teal">
                <?= appIcon("checkBadge", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="equipmentCalibratedLabel">Kalibre</span>
                    <strong class="dashboard-card-number detail-card-value"><?= $counts["calibrated"] ?></strong>
                </div>
            </div>
            <div class="dashboard-card metric-orange">
                <?= appIcon("clock", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="equipmentDueSoonLabel">Yakında</span>
                    <strong class="dashboard-card-number detail-card-value"><?= $counts["due_soon"] ?></strong>
                </div>
            </div>
            <div class="dashboard-card metric-red">
                <?= appIcon("warning", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="equipmentOverdueLabel">Süresi Geçmiş</span>
                    <strong class="dashboard-card-number detail-card-value"><?= $counts["overdue"] ?></strong>
                </div>
            </div>
        </section>

        <section class="page-section form-panel">
            <form class="auditor-form filter-form" method="get" action="equipment.php">
                <div class="form-grid filter-grid">
                    <label class="form-field">
                        <span data-i18n="equipmentCalStatusLabel">Kalibrasyon Durumu</span>
                        <select name="cal_status">
                            <option value="" data-i18n="allStatusesOption">Tümü</option>
                            <?php foreach ($calStatusLabels as $value => $label): ?>
                                <option value="<?= $value ?>" <?= $filterStatus === $value ? "selected" : "" ?>><?= htmlspecialchars($label, ENT_QUOTES, "UTF-8") ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>
                <div class="form-actions">
                    <button class="primary-button" type="submit" data-i18n="filterButton">Filtrele</button>
                    <a class="secondary-button" href="equipment.php" data-i18n="clearFilterButton">Temizle</a>
                </div>
            </form>
        </section>

        <section class="console-card checklist-section">
            <div class="section-heading compact-heading">
                <div>
                    <h3 data-i18n="equipmentListTitle">Ekipmanlar</h3>
                    <p><span data-i18n="filteredRecordsLabel">Gösterilen kayıt</span>: <strong><?= $total ?></strong></p>
                </div>
            </div>
            <div class="admin-list">
                <?php if (!$equipment): ?>
                    <div class="empty-state" data-i18n="noEquipmentText">Ekipman kaydı bulunmuyor.</div>
                <?php endif; ?>
                <?php foreach ($equipment as $item): ?>
                    <a class="admin-list-item list-link" href="equipment-detail.php?id=<?= (int) $item["id"] ?>">
                        <div class="list-item-main">
                            <strong><?= htmlspecialchars($item["name"], ENT_QUOTES, "UTF-8") ?></strong>
                            <span><?= htmlspecialchars((string) ($item["asset_code"] ?: "-"), ENT_QUOTES, "UTF-8") ?> · <?= htmlspecialchars((string) ($item["category"] ?: "-"), ENT_QUOTES, "UTF-8") ?></span>
                        </div>
                        <div class="list-item-side">
                            <span class="status-pill cal-pill-<?= $item["cal_status"] ?>"><?= htmlspecialchars($calStatusLabels[$item["cal_status"]] ?? $item["cal_status"], ENT_QUOTES, "UTF-8") ?></span>
                            <span class="list-item-count"><?= (int) $item["calibration_count"] ?>/<?= htmlspecialchars((string) ($item["next_calibration_date"] ?: "-"), ENT_QUOTES, "UTF-8") ?></span>
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
