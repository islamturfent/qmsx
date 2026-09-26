<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/access.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/instrument-functions.php';

$userId = (int) ($_SESSION["qms_user_id"] ?? 0);
$role = qmsCurrentRole();
if (qmsIsAuditor()) {
    header("Location: my-audits.php");
    exit;
}

$scope = qmsCompanyScope('companies.id', qmsVisibleCompanyIds($pdo, $userId, $role));
$companyStmt = $pdo->prepare('SELECT companies.id, companies.company_name FROM companies WHERE companies.active = 1' . $scope['sql'] . ' ORDER BY companies.company_name');
$companyStmt->execute($scope['params']);
$companies = $companyStmt->fetchAll(PDO::FETCH_ASSOC);

$csrfScope = 'instruments';
$formError = '';
$editing = null;
$editId = (int) ($_GET['edit'] ?? 0);
if ($editId > 0) {
    $editing = qmsInstrumentFind($pdo, $editId, $userId, $role);
    if (!$editing) {
        $editId = 0;
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    qmsCsrfVerify($csrfScope, $_POST["csrf"] ?? null);
    $formType = (string) ($_POST["form_type"] ?? "");

    if ($formType === "add" || $formType === "update") {
        $data = [
            "instrument_code" => (string) ($_POST["instrument_code"] ?? ""),
            "name" => (string) ($_POST["name"] ?? ""),
            "instrument_type" => (string) ($_POST["instrument_type"] ?? ""),
            "location" => (string) ($_POST["location"] ?? ""),
            "interval_months" => (int) ($_POST["interval_months"] ?? 12),
            "last_calibration_date" => (string) ($_POST["last_calibration_date"] ?? ""),
            "next_calibration_date" => (string) ($_POST["next_calibration_date"] ?? ""),
            "responsible" => (string) ($_POST["responsible"] ?? ""),
            "status" => (string) ($_POST["status"] ?? "active"),
            "notes" => (string) ($_POST["notes"] ?? ""),
        ];
        if ($formType === "add") {
            $data["company_id"] = (int) ($_POST["company_id"] ?? 0);
            $newId = qmsInstrumentAdd($pdo, $data, $userId, $role);
            if ($newId !== null) {
                header("Location: instruments.php?added=1");
                exit;
            }
            $formError = "Alet eklenemedi. Geçerli bir şirket ve alet adı girin.";
        } else {
            $ok = qmsInstrumentUpdate($pdo, (int) ($_POST["id"] ?? 0), $data, $userId, $role);
            if ($ok) {
                header("Location: instruments.php?updated=1");
                exit;
            }
            $formError = "Alet güncellenemedi.";
        }
    } elseif ($formType === "calibrate") {
        $target = (int) ($_POST["id"] ?? 0);
        qmsInstrumentCalibrate($pdo, $target, $userId, $role);
        header("Location: instruments.php?calibrated=1");
        exit;
    } elseif ($formType === "delete") {
        qmsInstrumentDelete($pdo, (int) ($_POST["id"] ?? 0), $userId, $role);
        header("Location: instruments.php?deleted=1");
        exit;
    }
}

$statusFilter = (string) ($_GET["status"] ?? "");
$allowedFilters = array_merge(['', 'overdue'], QMS_INSTRUMENT_STATUSES);
if (!in_array($statusFilter, $allowedFilters, true)) {
    $statusFilter = '';
}
$rows = qmsInstrumentList($pdo, $userId, $role, $statusFilter);
$allRows = qmsInstrumentList($pdo, $userId, $role);
$activeCount = 0;
$overdueCount = 0;
foreach ($allRows as $r) {
    if ($r['status'] === 'active') {
        $activeCount++;
        if ($r['next_calibration_date'] !== null && (string) $r['next_calibration_date'] < date('Y-m-d')) {
            $overdueCount++;
        }
    }
}

$activeNav = "instruments";
$selectedCompanyId = (int) ($_GET["company_id"] ?? 0);
$prefill = $editing ?: [
    'instrument_code' => '', 'name' => '', 'instrument_type' => '', 'location' => '', 'interval_months' => 12,
    'last_calibration_date' => '', 'next_calibration_date' => '', 'responsible' => '', 'status' => 'active', 'notes' => '',
];
if ($editing) {
    $selectedCompanyId = (int) $editing['company_id'];
}

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="theme-color" content="#f9fafb">
    <title>QMS Kalibrasyon & Metroloji</title><link rel="manifest" href="manifest.webmanifest"><link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml"><link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar"><div class="topbar-inner"><div class="page-title-block"><strong data-i18n="instrumentsTitle">Kalibrasyon & Metroloji</strong><span data-i18n="instrumentsText">Ölçü aletlerinin kalibrasyon takvimini izleyin.</span></div><div class="topbar-actions"><button class="topbar-button" id="languageToggle" type="button">EN</button><button class="topbar-button" id="themeToggle" type="button" aria-label="Tema değiştir">🌙</button></div></div></header>
    <main class="page-container">
        <section class="page-heading">
            <div>
                <span class="section-kicker" data-i18n="instrumentsKicker">Metroloji</span>
                <h1 data-i18n="instrumentsTitle">Kalibrasyon & Metroloji Takvimi</h1>
                <p data-i18n="instrumentsText">Ölçü aletlerinin sonraki kalibrasyon tarihlerini ve durumlarını izleyin.</p>
            </div>
        </section>

        <?php if ($formError !== ""): ?><div class="form-message error"><?= htmlspecialchars($formError, ENT_QUOTES, "UTF-8") ?></div><?php endif; ?>
        <?php if (($_GET["added"] ?? "") === "1"): ?><div class="form-message success" data-i18n="instrumentAdded">Alet eklendi.</div><?php endif; ?>
        <?php if (($_GET["updated"] ?? "") === "1"): ?><div class="form-message success" data-i18n="instrumentUpdated">Alet güncellendi.</div><?php endif; ?>
        <?php if (($_GET["calibrated"] ?? "") === "1"): ?><div class="form-message success" data-i18n="instrumentCalibrated">Kalibrasyon kaydedildi.</div><?php endif; ?>
        <?php if (($_GET["deleted"] ?? "") === "1"): ?><div class="form-message success" data-i18n="instrumentDeleted">Alet silindi.</div><?php endif; ?>

        <div class="record-card-grid">
            <div class="dashboard-card"><div class="dashboard-card-content"><div class="dashboard-card-label" data-i18n="instrumentActiveKpi">Aktif Alet</div><div class="dashboard-card-number"><?= $activeCount ?></div></div></div>
            <div class="dashboard-card metric-red"><div class="dashboard-card-content"><div class="dashboard-card-label" data-i18n="instrumentOverdueKpi">Kalibrasyonu Geçen</div><div class="dashboard-card-number"><?= $overdueCount ?></div></div></div>
            <div class="dashboard-card"><div class="dashboard-card-content"><div class="dashboard-card-label" data-i18n="instrumentTotalKpi">Toplam Alet</div><div class="dashboard-card-number"><?= count($allRows) ?></div></div></div>
        </div>

        <div class="two-col">
            <section class="form-panel">
                <div class="section-heading compact-heading"><div><h3><?= $editing ? 'Aleti Düzenle' : 'Yeni Alet' ?></h3><?php if ($editing): ?><p>#<?= (int) $editing['id'] ?> düzenleniyor</p><?php endif; ?></div></div>
                <?php if (!$companies): ?><div class="form-message error" data-i18n="instrumentNoCompany">Önce bir şirket gerekir.</div>
                <?php else: ?>
                <form class="auditor-form" method="post" action="instruments.php<?= $editing ? '?edit=' . (int) $editing['id'] : '' ?>">
                    <?= qmsCsrfField($csrfScope) ?>
                    <input type="hidden" name="form_type" value="<?= $editing ? 'update' : 'add' ?>">
                    <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>
                    <div class="form-grid">
                        <label class="form-field"><span data-i18n="companySelectLabel">Şirket</span><select name="company_id" <?= $editing ? 'disabled' : 'required' ?>><option value="0" data-i18n="selectCompanyOption">Şirket seçin</option><?php foreach ($companies as $company): ?><option value="<?= (int) $company['id'] ?>" <?= $selectedCompanyId === (int) $company['id'] ? 'selected' : '' ?>><?= htmlspecialchars($company['company_name'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></label>
                        <label class="form-field"><span data-i18n="instrumentCodeLabel">Alet Kodu (isteğe bağlı)</span><input type="text" name="instrument_code" maxlength="40" value="<?= htmlspecialchars((string) $prefill['instrument_code'], ENT_QUOTES, 'UTF-8') ?>"></label>
                        <label class="form-field"><span data-i18n="instrumentNameLabel">Alet Adı</span><input type="text" name="name" required maxlength="190" value="<?= htmlspecialchars((string) $prefill['name'], ENT_QUOTES, 'UTF-8') ?>"></label>
                        <label class="form-field"><span data-i18n="instrumentTypeLabel">Tip</span><input type="text" name="instrument_type" maxlength="120" value="<?= htmlspecialchars((string) $prefill['instrument_type'], ENT_QUOTES, 'UTF-8') ?>"></label>
                        <label class="form-field"><span data-i18n="instrumentLocationLabel">Konum</span><input type="text" name="location" maxlength="190" value="<?= htmlspecialchars((string) $prefill['location'], ENT_QUOTES, 'UTF-8') ?>"></label>
                        <label class="form-field"><span data-i18n="instrumentIntervalLabel">Kalibrasyon Aralığı (ay)</span><input type="number" name="interval_months" min="1" max="120" value="<?= (int) $prefill['interval_months'] ?>"></label>
                        <label class="form-field"><span data-i18n="instrumentLastLabel">Son Kalibrasyon</span><input type="date" name="last_calibration_date" value="<?= htmlspecialchars((string) $prefill['last_calibration_date'], ENT_QUOTES, 'UTF-8') ?>"></label>
                        <label class="form-field"><span data-i18n="instrumentNextLabel">Sonraki Kalibrasyon</span><input type="date" name="next_calibration_date" value="<?= htmlspecialchars((string) $prefill['next_calibration_date'], ENT_QUOTES, 'UTF-8') ?>"></label>
                        <label class="form-field"><span data-i18n="instrumentResponsibleLabel">Sorumlu</span><input type="text" name="responsible" maxlength="180" value="<?= htmlspecialchars((string) $prefill['responsible'], ENT_QUOTES, 'UTF-8') ?>"></label>
                        <label class="form-field"><span data-i18n="instrumentStatusLabel">Durum</span><select name="status"><?php foreach (QMS_INSTRUMENT_STATUSES as $st): ?><option value="<?= $st ?>" <?= (string) $prefill['status'] === $st ? 'selected' : '' ?>><?= htmlspecialchars(qmsInstrumentStatusLabel($st), ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></label>
                        <label class="form-field form-field-wide"><span data-i18n="instrumentNotesLabel">Not</span><textarea name="notes" rows="3"><?= htmlspecialchars((string) $prefill['notes'], ENT_QUOTES, 'UTF-8') ?></textarea></label>
                    </div>
                    <div class="form-actions"><button class="primary-button" type="submit" data-i18n="instrumentSave">Kaydet</button><?php if ($editing): ?><a class="secondary-button" href="instruments.php" data-i18n="instrumentCancel">İptal</a><?php endif; ?></div>
                </form>
                <?php endif; ?>
            </section>

            <section class="console-card checklist-section">
                <div class="section-heading compact-heading"><div><h3 data-i18n="instrumentListTitle">Ölçü Aletleri</h3><p><span data-i18n="filteredRecordsLabel">Gösterilen kayıt</span>: <strong><?= count($rows) ?></strong></p></div><div><form class="filter-inline" method="get" action="instruments.php"><select name="status"><option value="" data-i18n="instrumentAllStatuses">Tüm Durumlar</option><option value="overdue" data-i18n="instrumentFilterOverdue">Kalibrasyonu Geçen</option><?php foreach (QMS_INSTRUMENT_STATUSES as $st): ?><option value="<?= $st ?>" <?= $statusFilter === $st ? 'selected' : '' ?>><?= htmlspecialchars(qmsInstrumentStatusLabel($st), ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select><button class="secondary-button secondary-button-sm" type="submit" data-i18n="applyButton">Uygula</button></form></div></div>
                <div class="admin-list">
                    <?php if (!$rows): ?><div class="empty-state" data-i18n="instrumentEmpty">Henüz ölçü aleti tanımlanmadı.</div><?php endif; ?>
                    <?php foreach ($rows as $row): ?>
                        <?php $isOverdue = $row['status'] === 'active' && $row['next_calibration_date'] !== null && (string) $row['next_calibration_date'] < date('Y-m-d'); ?>
                        <div class="admin-list-item">
                            <div class="list-item-main">
                                <strong><?= $row['instrument_code'] ? '[' . htmlspecialchars($row['instrument_code'], ENT_QUOTES, 'UTF-8') . '] ' : '' ?><?= htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                <span>
                                    <?php if ($isOverdue): ?><span class="overdue-badge" data-i18n="instrumentOverdueBadge">Kalibrasyonu Geçti</span>
                                    <?php else: ?><span class="status-badge <?= $row['status'] === 'active' ? 'status-pill' : '' ?>"><?= htmlspecialchars(qmsInstrumentStatusLabel($row['status']), ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                                    <?php if ($row['instrument_type']): ?> · <?= htmlspecialchars($row['instrument_type'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?><?php if ($row['location']): ?> · <?= htmlspecialchars($row['location'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?>
                                    <?php if ($row['next_calibration_date']): ?> · sonraki <?= htmlspecialchars((string) $row['next_calibration_date'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?><?= $row['responsible'] ? ' · ' . htmlspecialchars($row['responsible'], ENT_QUOTES, 'UTF-8') : '' ?>
                                </span>
                            </div>
                            <div class="list-item-side">
                                <form method="post" action="instruments.php"><?= qmsCsrfField($csrfScope) ?><input type="hidden" name="form_type" value="calibrate"><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><button class="secondary-button secondary-button-sm" type="submit" data-i18n="instrumentCalibrateButton">Kalibre Et</button></form>
                                <a class="secondary-button secondary-button-sm" href="instruments.php?edit=<?= (int) $row['id'] ?>" data-i18n="editButton">Düzenle</a>
                                <form method="post" action="instruments.php" onsubmit="return confirm('Alet silinsin mi?');"><?= qmsCsrfField($csrfScope) ?><input type="hidden" name="form_type" value="delete"><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><button class="danger-button danger-button-sm" type="submit" data-i18n="instrumentDelete">Sil</button></form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>
    </main>
    <script src="assets/js/theme.js"></script><script src="assets/js/language.js"></script><script src="assets/js/sidebar.js"></script><script src="assets/js/pwa.js"></script>
</body>
</html>
