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
require_once __DIR__ . '/includes/instrument-calibration-functions.php';

$userId = (int) ($_SESSION["qms_user_id"] ?? 0);
$role = qmsCurrentRole();
if (qmsIsAuditor()) {
    header("Location: my-audits.php");
    exit;
}

$csrfScope = 'calibration_history';

// Sertifika indirme uc noktasi: giris + kapsam dogrulamasi yapilir.
$downloadId = (int) ($_GET['download'] ?? 0);
if ($downloadId > 0) {
    $dl = qmsInstCalibDownload($pdo, $downloadId, $userId, $role);
    if (!$dl) {
        header("Location: instrument-calibrations.php");
        exit;
    }
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $dl['name'] . '"');
    header('Content-Length: ' . filesize($dl['path']));
    readfile($dl['path']);
    exit;
}

$instruments = qmsInstrumentList($pdo, $userId, $role);
$formError = '';
$message = '';

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    qmsCsrfVerify($csrfScope, $_POST["csrf"] ?? null);
    if (($_POST["form_type"] ?? "") === "add") {
        $data = [
            "instrument_id" => (int) ($_POST["instrument_id"] ?? 0),
            "calibration_date" => (string) ($_POST["calibration_date"] ?? ""),
            "due_date" => (string) ($_POST["due_date"] ?? ""),
            "result" => (string) ($_POST["result"] ?? "pass"),
            "cert_number" => (string) ($_POST["cert_number"] ?? ""),
            "lab_name" => (string) ($_POST["lab_name"] ?? ""),
            "performed_by" => (string) ($_POST["performed_by"] ?? ""),
            "notes" => (string) ($_POST["notes"] ?? ""),
        ];
        $file = isset($_FILES['certificate']) ? $_FILES['certificate'] : [];
        $newId = qmsInstCalibAdd($pdo, $data, $file, $userId, $role);
        if ($newId !== null) {
            header("Location: instrument-calibrations.php?added=1");
            exit;
        }
        $formError = "Kalibrasyon kaydı eklenemedi. Geçerli bir alet, tarih ve dosya kontrol edin.";
    } elseif (($_POST["form_type"] ?? "") === "delete") {
        qmsInstCalibDelete($pdo, (int) ($_POST["id"] ?? 0), $userId, $role);
        header("Location: instrument-calibrations.php?deleted=1");
        exit;
    }
}

$filterInstrument = (int) ($_GET['instrument_id'] ?? 0);
$resultFilter = (string) ($_GET['result'] ?? '');
if ($resultFilter !== '' && !in_array($resultFilter, QMS_INSTRUMENT_CALIB_RESULTS, true)) {
    $resultFilter = '';
}
$rows = qmsInstCalibList($pdo, $userId, $role, $filterInstrument, $resultFilter);

$activeNav = "calibration_history";

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="theme-color" content="#f9fafb">
    <title>QuAmi Kalibrasyon Geçmişi</title><link rel="manifest" href="manifest.webmanifest"><link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml"><link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar"><div class="topbar-inner"><div class="page-title-block"><strong data-i18n="calibrationHistoryTitle">Kalibrasyon Geçmişi</strong><span data-i18n="calibrationHistoryText">Alet bazında kalibrasyon kayıtları ve sertifikalar.</span></div><div class="topbar-actions"><button class="topbar-button" id="languageToggle" type="button">EN</button><button class="topbar-button" id="themeToggle" type="button" aria-label="Tema değiştir">🌙</button></div></div></header>
    <main class="page-container">
        <section class="page-heading">
            <div>
                <span class="section-kicker" data-i18n="instrumentsKicker">Metroloji</span>
                <h1 data-i18n="calibrationHistoryTitle">Kalibrasyon Geçmişi</h1>
                <p data-i18n="calibrationHistoryText">Her ölçü aletinin kalibrasyon kayıtlarını ve sertifika dosyalarını izleyin.</p>
            </div>
            <a class="secondary-button" href="instruments.php" data-i18n="instrumentsMenuLabel">Ölçü Aletleri</a>
        </section>

        <?php if ($formError !== ""): ?><div class="form-message error"><?= htmlspecialchars($formError, ENT_QUOTES, "UTF-8") ?></div><?php endif; ?>
        <?php if (($_GET["added"] ?? "") === "1"): ?><div class="form-message success" data-i18n="calibrationAdded">Kalibrasyon kaydı eklendi.</div><?php endif; ?>
        <?php if (($_GET["deleted"] ?? "") === "1"): ?><div class="form-message success" data-i18n="calibrationDeleted">Kalibrasyon kaydı silindi.</div><?php endif; ?>

        <section class="filter-panel">
            <form class="filter-form" method="get" action="instrument-calibrations.php">
                <label><span data-i18n="instrumentSelectLabel">Ölçü Aleti</span><select name="instrument_id"><option value="0" data-i18n="allInstrumentsOption">Tüm aletler</option><?php foreach ($instruments as $i): ?><option value="<?= (int) $i['id'] ?>" <?= $filterInstrument === (int) $i['id'] ? 'selected' : '' ?>><?= htmlspecialchars($i['name'], ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars($i['instrument_code'] ?: '-', ENT_QUOTES, 'UTF-8') ?>)</option><?php endforeach; ?></select></label>
                <label><span data-i18n="calibrationResultLabel">Sonuç</span><select name="result"><option value="" data-i18n="allResultsOption">Tümü</option><option value="pass" <?= $resultFilter === 'pass' ? 'selected' : '' ?> data-i18n="calibrationResultPass">Başarılı</option><option value="fail" <?= $resultFilter === 'fail' ? 'selected' : '' ?> data-i18n="calibrationResultFail">Başarısız</option></select></label>
                <div class="filter-actions"><button class="primary-button" type="submit" data-i18n="filterButton">Filtrele</button><a class="secondary-button" href="instrument-calibrations.php" data-i18n="clearFilterButton">Temizle</a></div>
            </form>
        </section>

        <div class="two-col">
            <section class="form-panel">
                <div class="section-heading compact-heading"><div><h3 data-i18n="calibrationNewTitle">Kalibrasyon Kaydı Ekle</h3><p data-i18n="calibrationNewText">Bir alete kalibrasyon tarihi ve sertifika ekleyin.</p></div></div>
                <?php if (!$instruments): ?><div class="form-message error" data-i18n="instrumentNoCompany">Önce bir ölçü aleti gerekir.</div>
                <?php else: ?>
                <form class="auditor-form" method="post" action="instrument-calibrations.php" enctype="multipart/form-data">
                    <?= qmsCsrfField($csrfScope) ?>
                    <input type="hidden" name="form_type" value="add">
                    <div class="form-grid">
                        <label class="form-field form-field-wide"><span data-i18n="instrumentSelectLabel">Ölçü Aleti</span><select name="instrument_id" required><option value="0" data-i18n="selectInstrumentOption">Alet seçin</option><?php foreach ($instruments as $i): ?><option value="<?= (int) $i['id'] ?>" <?= $filterInstrument === (int) $i['id'] ? 'selected' : '' ?>><?= htmlspecialchars($i['name'], ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars($i['instrument_code'] ?: '-', ENT_QUOTES, 'UTF-8') ?>)</option><?php endforeach; ?></select></label>
                        <label class="form-field"><span data-i18n="calibrationDateLabel">Kalibrasyon Tarihi</span><input type="date" name="calibration_date" required></label>
                        <label class="form-field"><span data-i18n="calibrationDueLabel">Sonraki Tarih</span><input type="date" name="due_date"></label>
                        <label class="form-field"><span data-i18n="calibrationResultLabel">Sonuç</span><select name="result"><option value="pass" data-i18n="calibrationResultPass">Başarılı</option><option value="fail" data-i18n="calibrationResultFail">Başarısız</option></select></label>
                        <label class="form-field"><span data-i18n="calibrationCertLabel">Sertifika No</span><input type="text" name="cert_number" maxlength="80"></label>
                        <label class="form-field"><span data-i18n="calibrationLabLabel">Laboratuvar</span><input type="text" name="lab_name" maxlength="180"></label>
                        <label class="form-field"><span data-i18n="calibrationPerformedLabel">Uygulayan</span><input type="text" name="performed_by" maxlength="180"></label>
                        <label class="form-field form-field-wide"><span data-i18n="calibrationCertFileLabel">Sertifika Dosyası (en fazla 10 MB)</span><input type="file" name="certificate" accept=".pdf,.jpg,.jpeg,.png"></label>
                        <label class="form-field form-field-wide"><span data-i18n="calibrationNotesLabel">Not</span><textarea name="notes" rows="3"></textarea></label>
                    </div>
                    <div class="form-actions"><button class="primary-button" type="submit" data-i18n="calibrationSave">Kaydet</button></div>
                </form>
                <?php endif; ?>
            </section>

            <section class="console-card checklist-section">
                <div class="section-heading compact-heading"><div><h3 data-i18n="calibrationHistoryTitle">Kalibrasyon Geçmişi</h3><p><span data-i18n="filteredRecordsLabel">Gösterilen kayıt</span>: <strong><?= count($rows) ?></strong></p></div></div>
                <div class="admin-list">
                    <?php if (!$rows): ?><div class="empty-state" data-i18n="calibrationEmpty">Henüz kalibrasyon kaydı yok.</div><?php endif; ?>
                    <?php foreach ($rows as $row): ?>
                        <div class="admin-list-item">
                            <div class="list-item-main">
                                <strong><?= htmlspecialchars($row['instrument_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                <span><?= htmlspecialchars((string) $row['calibration_date'], ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars(qmsInstCalibResultLabel((string) $row['result']), ENT_QUOTES, 'UTF-8') ?><?= $row['lab_name'] ? ' · ' . htmlspecialchars($row['lab_name'], ENT_QUOTES, 'UTF-8') : '' ?><?= $row['cert_number'] ? ' · ' . htmlspecialchars($row['cert_number'], ENT_QUOTES, 'UTF-8') : '' ?></span>
                            </div>
                            <div class="list-item-side">
                                <?php if ($row['certificate_file']): ?>
                                    <a class="secondary-button" href="instrument-calibrations.php?download=<?= (int) $row['id'] ?>" data-i18n="calibrationDownload">Sertifika</a>
                                <?php endif; ?>
                                <form method="post" action="instrument-calibrations.php" onsubmit="return confirm('Kayıt silinsin mi?');"><?= qmsCsrfField($csrfScope) ?><input type="hidden" name="form_type" value="delete"><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><button class="danger-button danger-button-sm" type="submit" data-i18n="calibrationDelete">Sil</button></form>
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
