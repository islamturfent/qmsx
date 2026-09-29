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
require_once __DIR__ . '/includes/instrument-functions.php';
require_once __DIR__ . '/includes/instrument-calibration-functions.php';

$userId = (int) ($_SESSION["qms_user_id"] ?? 0);
$role = qmsCurrentRole();

$instrumentId = (int) ($_GET["id"] ?? 0);
$instrument = qmsInstrumentFind($pdo, $instrumentId, $userId, $role);
if (!$instrument) {
    header("Location: instruments.php");
    exit;
}

$calibrations = qmsInstCalibList($pdo, $userId, $role, $instrumentId);

$today = date('Y-m-d');
$overdue = !empty($instrument["next_calibration_date"]) && $instrument["next_calibration_date"] < $today;
$lastCalibration = $calibrations ? $calibrations[0] : null;

$activeNav = "instruments";

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f9fafb">
    <title>QuAmi Ölçü Aleti Detayı</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar">
        <div class="topbar-inner">
            <div class="page-title-block">
                <strong data-i18n="instrumentDetailTitle">Ölçü Aleti Detayı</strong>
                <span><?= htmlspecialchars($instrument["name"], ENT_QUOTES, "UTF-8") ?> · <?= htmlspecialchars((string) $instrument["instrument_code"], ENT_QUOTES, "UTF-8") ?></span>
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
                <span class="section-kicker" data-i18n="instrumentsKicker">Metroloji</span>
                <h1><?= htmlspecialchars($instrument["name"], ENT_QUOTES, "UTF-8") ?></h1>
                <p><?= htmlspecialchars($instrument["instrument_code"] . ($instrument["instrument_type"] ? " · " . $instrument["instrument_type"] : ""), ENT_QUOTES, "UTF-8") ?></p>
            </div>
            <a class="secondary-button" href="instrument-calibrations.php?instrument_id=<?= $instrumentId ?>" data-i18n="calibrationHistoryMenuLabel">Kalibrasyon Geçmişi</a>
        </section>

        <?php if ($overdue): ?>
            <div class="form-message warning" data-i18n="instrumentOverdueWarning">Kalibrasyon termin tarihi geçti; kalibrasyon planlayın.</div>
        <?php endif; ?>

        <section class="dashboard-grid compact-dashboard-grid">
            <div class="dashboard-card metric-blue">
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="instrumentDetailLastCalib">Son Kalibrasyon</span>
                    <strong class="dashboard-card-number"><?= $lastCalibration ? htmlspecialchars((string) $lastCalibration["calibration_date"], ENT_QUOTES, "UTF-8") : "-" ?></strong>
                </div>
            </div>
            <div class="dashboard-card <?= $overdue ? "metric-red" : "metric-green" ?>">
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="instrumentDetailNextCalib">Sonraki Kalibrasyon</span>
                    <strong class="dashboard-card-number"><?= htmlspecialchars((string) ($instrument["next_calibration_date"] ?? "-"), ENT_QUOTES, "UTF-8") ?></strong>
                </div>
            </div>
            <div class="dashboard-card metric-teal">
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="instrumentDetailCalibCount">Kalibrasyon Kaydı</span>
                    <strong class="dashboard-card-number"><?= count($calibrations) ?></strong>
                </div>
            </div>
        </section>

        <section class="page-section console-card">
            <div class="section-heading compact-heading">
                <div><h3 data-i18n="instrumentDetailInfoTitle">Alet Bilgileri</h3><p data-i18n="instrumentDetailInfoText">Temel alet bilgileri ve durum.</p></div>
            </div>
            <div class="admin-list">
                <div class="admin-list-item"><div><strong>Alet Kodu</strong><span><?= htmlspecialchars($instrument["instrument_code"] ?: "-", ENT_QUOTES, "UTF-8") ?></span></div></div>
                <div class="admin-list-item"><div><strong data-i18n="instrumentDetailType">Tür</strong><span><?= htmlspecialchars($instrument["instrument_type"] ?: "-", ENT_QUOTES, "UTF-8") ?></span></div></div>
                <div class="admin-list-item"><div><strong data-i18n="instrumentDetailLocation">Konum</strong><span><?= htmlspecialchars($instrument["location"] ?: "-", ENT_QUOTES, "UTF-8") ?></span></div></div>
                <div class="admin-list-item"><div><strong data-i18n="instrumentDetailInterval">Kalibrasyon Aralığı</strong><span><?= htmlspecialchars((string) $instrument["interval_months"] . " ay", ENT_QUOTES, "UTF-8") ?></span></div></div>
                <div class="admin-list-item"><div><strong data-i18n="instrumentDetailStatus">Durum</strong><span class="status-pill"><?= htmlspecialchars($instrument["status"] ?: "-", ENT_QUOTES, "UTF-8") ?></span></div></div>
                <div class="admin-list-item"><div><strong data-i18n="instrumentDetailResponsible">Sorumlu</strong><span><?= htmlspecialchars($instrument["responsible"] ?: "-", ENT_QUOTES, "UTF-8") ?></span></div></div>
            </div>
        </section>

        <section class="page-section console-card">
            <div class="section-heading compact-heading">
                <div><h3 data-i18n="calibrationHistoryTitle">Kalibrasyon Geçmişi & Sertifikalar</h3><p data-i18n="instrumentDetailCalibText">Bu aletin kalibrasyon kayıtları ve sertifika dosyaları.</p></div>
            </div>
            <?php if (!$calibrations): ?>
                <div class="empty-state" data-i18n="calibrationEmpty">Henüz kalibrasyon kaydı yok.</div>
            <?php else: ?>
                <div class="admin-list">
                    <?php foreach ($calibrations as $c): ?>
                        <div class="admin-list-item">
                            <div class="list-item-main">
                                <strong><?= htmlspecialchars((string) $c["calibration_date"], ENT_QUOTES, "UTF-8") ?> · <?= htmlspecialchars(qmsInstCalibResultLabel((string) $c["result"]), ENT_QUOTES, "UTF-8") ?></strong>
                                <span><?= htmlspecialchars(trim((string) $c["cert_number"] . ($c["lab_name"] ? " · " . $c["lab_name"] : "") . ($c["due_date"] ? " · sonraki: " . $c["due_date"] : "")), ENT_QUOTES, "UTF-8") ?></span>
                            </div>
                            <div class="list-item-side">
                                <?php if (($c["certificate_file"] ?? "") !== ""): ?>
                                    <a class="secondary-button" href="instrument-calibrations.php?download=<?= (int) $c["id"] ?>" data-i18n="calibrationDownload">Sertifika</a>
                                <?php endif; ?>
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
