<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/access.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/equipment-functions.php';
require_once __DIR__ . '/includes/audit-log-functions.php';
require_once __DIR__ . '/includes/app-ui.php';

$equipmentId = (int) ($_GET["id"] ?? 0);
$userId = (int) ($_SESSION["qms_user_id"] ?? 0);
$role = qmsCurrentRole();
$csrfScope = 'equipment_detail';

$equipment = qmsEquipmentFind($pdo, $equipmentId, $userId, $role);
if (!$equipment) {
    header("Location: equipment.php");
    exit;
}

$companyId = (int) $equipment["company_id"];
$statusLabels = qmsEquipmentStatusLabels();
$calResultLabels = qmsEquipmentCalibrationResultLabels();

$calibrations = qmsEquipmentCalibrations($pdo, $equipmentId);
$calStatus = qmsEquipmentCalibrationStatus($equipment);
$calStatusLabels = qmsEquipmentCalibrationStatusLabels();
$responsibleOptions = qmsEquipmentResponsibleOptions($pdo, $companyId);

$formError = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    qmsCsrfVerify($csrfScope, $_POST["csrf"] ?? null);

    $formType = (string) ($_POST["form_type"] ?? "");
    $redirect = "equipment-detail.php?id=" . $equipmentId;

    if ($formType === "update_equipment") {
        $formData = [
            "asset_code" => trim((string) ($_POST["asset_code"] ?? "")),
            "name" => trim((string) ($_POST["name"] ?? "")),
            "category" => trim((string) ($_POST["category"] ?? "")),
            "manufacturer" => trim((string) ($_POST["manufacturer"] ?? "")),
            "model" => trim((string) ($_POST["model"] ?? "")),
            "serial_number" => trim((string) ($_POST["serial_number"] ?? "")),
            "location" => trim((string) ($_POST["location"] ?? "")),
            "responsible_user_id" => (int) ($_POST["responsible_user_id"] ?? 0),
            "calibration_interval_days" => trim((string) ($_POST["calibration_interval_days"] ?? "")),
            "status" => (string) ($_POST["status"] ?? "operational"),
            "notes" => trim((string) ($_POST["notes"] ?? "")),
        ];

        $interval = null;
        if ($formData["calibration_interval_days"] !== "") {
            if (preg_match('/^\d{1,4}$/', $formData["calibration_interval_days"]) === 1) {
                $interval = (int) $formData["calibration_interval_days"];
            } else {
                $formError = "Kalibrasyon aralığı geçerli bir gün sayısı olmalı.";
            }
        }

        if ($formData["name"] === "") {
            $formError = "Lütfen ekipman adını girin.";
        } elseif (!in_array($formData["status"], QMS_EQUIPMENT_STATUSES, true)) {
            $formError = "Geçerli bir durum seçin.";
        } elseif ($formError === "") {
            $pdo->prepare(
                "UPDATE equipment SET asset_code = :asset_code, name = :name, category = :category,
                    manufacturer = :manufacturer, model = :model, serial_number = :serial_number,
                    location = :location, responsible_user_id = :responsible_user_id,
                    calibration_interval_days = :calibration_interval_days, notes = :notes,
                    status = :status, updated_by = :updated_by WHERE id = :id"
            )->execute([
                "asset_code" => $formData["asset_code"] !== "" ? mb_substr($formData["asset_code"], 0, 60) : null,
                "name" => mb_substr($formData["name"], 0, 255),
                "category" => $formData["category"] !== "" ? mb_substr($formData["category"], 0, 100) : null,
                "manufacturer" => $formData["manufacturer"] !== "" ? mb_substr($formData["manufacturer"], 0, 150) : null,
                "model" => $formData["model"] !== "" ? mb_substr($formData["model"], 0, 150) : null,
                "serial_number" => $formData["serial_number"] !== "" ? mb_substr($formData["serial_number"], 0, 120) : null,
                "location" => $formData["location"] !== "" ? mb_substr($formData["location"], 0, 150) : null,
                "responsible_user_id" => $formData["responsible_user_id"] > 0 ? $formData["responsible_user_id"] : null,
                "calibration_interval_days" => $interval,
                "notes" => $formData["notes"] !== "" ? mb_substr($formData["notes"], 0, 4000) : null,
                "status" => $formData["status"],
                "updated_by" => $userId ?: null,
                "id" => $equipmentId,
            ]);
            qmsAuditLog($pdo, $companyId, $userId, 'equipment', $equipmentId, 'update', 'Ekipman güncellendi: ' . $formData["name"]);
            header("Location: " . $redirect . "&updated=1");
            exit;
        }
    } elseif ($formType === "add_calibration" || $formType === "update_calibration" || $formType === "remove_calibration") {
        $calibrationId = (int) ($_POST["calibration_id"] ?? 0);

        if ($formType === "remove_calibration") {
            $calibration = qmsCalibrationFind($pdo, $calibrationId, $userId, $role);
            if (!$calibration || (int) $calibration["equipment_id"] !== $equipmentId) {
                $formError = "Kalibrasyon kaydı bulunamadı.";
            } else {
                $pdo->prepare("DELETE FROM calibrations WHERE id = :id")->execute(["id" => $calibrationId]);
                qmsRecalculateEquipmentCalibration($pdo, $equipmentId);
                qmsAuditLog($pdo, $companyId, $userId, 'calibration', $calibrationId, 'delete', 'Kalibrasyon kaydı silindi: ' . $equipment["name"]);
                header("Location: " . $redirect . "&cal=removed");
                exit;
            }
        }

        if ($formType !== "remove_calibration" && $formError === "") {
            $calData = [
                "calibrated_on" => trim((string) ($_POST["calibrated_on"] ?? "")),
                "next_date" => trim((string) ($_POST["next_date"] ?? "")),
                "result" => (string) ($_POST["result"] ?? "ok"),
                "certificate_no" => trim((string) ($_POST["certificate_no"] ?? "")),
                "performed_by" => trim((string) ($_POST["performed_by"] ?? "")),
                "note" => trim((string) ($_POST["note"] ?? "")),
            ];

            $calibratedOn = preg_match('/^\d{4}-\d{2}-\d{2}$/', $calData["calibrated_on"]) === 1 ? $calData["calibrated_on"] : null;
            $nextDate = $calData["next_date"] !== "" ? (preg_match('/^\d{4}-\d{2}-\d{2}$/', $calData["next_date"]) === 1 ? $calData["next_date"] : null) : null;

            if ($calibratedOn === null) {
                $formError = "Lütfen kalibrasyon tarihini girin.";
            } elseif (!in_array($calData["result"], QMS_CALIBRATION_RESULTS, true)) {
                $formError = "Geçerli bir sonuç seçin.";
            } elseif ($calData["next_date"] !== "" && $nextDate === null) {
                $formError = "Sonraki termin tarihi geçerli değil.";
            } else {
                // Sonraki termin aralik verilmisse, aralik uzerinden otomatik onerilir.
                if ($nextDate === null && (int) ($equipment["calibration_interval_days"] ?? 0) > 0) {
                    $nextDate = date('Y-m-d', strtotime($calibratedOn . ' +' . (int) $equipment["calibration_interval_days"] . ' days'));
                }

                $stmt = $formType === "add_calibration"
                    ? $pdo->prepare("INSERT INTO calibrations (equipment_id, calibrated_on, next_date, result, certificate_no, performed_by, note, created_by, active) VALUES (?,?,?,?,?,?,?,?,1)")
                    : $pdo->prepare("UPDATE calibrations SET calibrated_on = ?, next_date = ?, result = ?, certificate_no = ?, performed_by = ?, note = ? WHERE id = ?");
                $params = $formType === "add_calibration"
                    ? [$equipmentId, $calibratedOn, $nextDate, $calData["result"], $calData["certificate_no"] !== "" ? $calData["certificate_no"] : null, $calData["performed_by"] !== "" ? $calData["performed_by"] : null, $calData["note"] !== "" ? $calData["note"] : null, $userId ?: null]
                    : [$calibratedOn, $nextDate, $calData["result"], $calData["certificate_no"] !== "" ? $calData["certificate_no"] : null, $calData["performed_by"] !== "" ? $calData["performed_by"] : null, $calData["note"] !== "" ? $calData["note"] : null, $calibrationId];
                $stmt->execute($params);

                qmsRecalculateEquipmentCalibration($pdo, $equipmentId);

                if ($formType === "add_calibration" && $calData["result"] === "failed") {
                    qmsNotifyCompanyAdmins($pdo, $companyId, 'calibration_failed', 'Kalibrasyon uygun değil', $equipment["name"] . ' kalibrasyonu uygun değil bulundu.', $redirect, $userId);
                }
                qmsAuditLog($pdo, $companyId, $userId, 'calibration', (int) ($formType === "add_calibration" ? $pdo->lastInsertId() : $calibrationId), $calData["result"] === "failed" ? 'status_change' : 'create', 'Kalibrasyon kaydı: ' . $equipment["name"] . ' (' . $calResultLabels[$calData["result"]] . ')');

                header("Location: " . $redirect . "&cal=" . ($formType === "add_calibration" ? "added" : "updated"));
                exit;
            }
        }
    } else {
        $formError = "Geçersiz istek.";
    }
}

// Silme sonrasi ekipmanin son/sonraki kalibrasyon tarihlerini yeniden hesaplar.
function qmsRecalculateEquipmentCalibration(PDO $pdo, int $equipmentId): void
{
    $latest = $pdo->prepare('SELECT calibrated_on, next_date FROM calibrations WHERE equipment_id = ? AND active = 1 ORDER BY calibrated_on DESC, id DESC LIMIT 1');
    $latest->execute([$equipmentId]);
    $last = $latest->fetch(PDO::FETCH_ASSOC);
    $pdo->prepare('UPDATE equipment SET last_calibration_date = :last, next_calibration_date = :next WHERE id = :id')
        ->execute([
            'last' => $last ? $last['calibrated_on'] : null,
            'next' => $last ? $last['next_date'] : null,
            'id' => $equipmentId,
        ]);
}

$calibrations = qmsEquipmentCalibrations($pdo, $equipmentId);
$calStatus = qmsEquipmentCalibrationStatus($equipment);
$activeNav = "equipment";

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f9fafb">
    <title>QMS Ekipman Detayı</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar">
        <div class="topbar-inner">
            <div class="page-title-block">
                <strong data-i18n="equipmentDetailTitle">Ekipman Detayı</strong>
                <span><?= htmlspecialchars($equipment["name"], ENT_QUOTES, "UTF-8") ?> · <?= htmlspecialchars($equipment["company_name"], ENT_QUOTES, "UTF-8") ?></span>
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
                <h1 data-i18n="equipmentDetailTitle">Ekipman Detayı</h1>
                <p><?= htmlspecialchars($equipment["name"], ENT_QUOTES, "UTF-8") ?></p>
            </div>
            <a class="secondary-button" href="equipment.php" data-i18n="backToEquipmentButton">Ekipmana Dön</a>
        </section>

        <section class="dashboard-grid compact-dashboard-grid">
            <div class="dashboard-card metric-blue">
                <?= appIcon("table", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="equipmentStatusLabel">Durum</span>
                    <strong class="dashboard-card-number detail-card-value"><?= htmlspecialchars($statusLabels[$equipment["status"]] ?? $equipment["status"], ENT_QUOTES, "UTF-8") ?></strong>
                </div>
            </div>
            <div class="dashboard-card metric-<?= $calStatus === "overdue" ? "red" : ($calStatus === "due_soon" ? "orange" : "teal") ?>">
                <?= appIcon($calStatus === "overdue" ? "warning" : "checkBadge", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="equipmentCalStatusLabel">Kalibrasyon</span>
                    <strong class="dashboard-card-number detail-card-value"><?= htmlspecialchars($calStatusLabels[$calStatus] ?? $calStatus, ENT_QUOTES, "UTF-8") ?></strong>
                </div>
            </div>
            <div class="dashboard-card metric-violet">
                <?= appIcon("clock", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="needNextCalLabel">Sonraki Kalibrasyon</span>
                    <strong class="dashboard-card-number detail-card-value"><?= htmlspecialchars((string) ($equipment["next_calibration_date"] ?? "—"), ENT_QUOTES, "UTF-8") ?></strong>
                </div>
            </div>
            <div class="dashboard-card metric-orange">
                <?= appIcon("check", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="calibrationCountLabel">Kalibrasyon Sayısı</span>
                    <strong class="dashboard-card-number detail-card-value"><?= count($calibrations) ?></strong>
                </div>
            </div>
        </section>

        <section class="form-panel">
            <?php if (($_GET["created"] ?? "") === "1"): ?>
                <div class="form-message success" data-i18n="equipmentCreatedMessage">Ekipman kaydı oluşturuldu.</div>
            <?php endif; ?>
            <?php if (($_GET["updated"] ?? "") === "1"): ?>
                <div class="form-message success" data-i18n="equipmentUpdatedMessage">Ekipman güncellendi.</div>
            <?php elseif (($_GET["cal"] ?? "") === "added"): ?>
                <div class="form-message success" data-i18n="calibrationAddedMessage">Kalibrasyon kaydı eklendi.</div>
            <?php elseif (($_GET["cal"] ?? "") === "updated"): ?>
                <div class="form-message success" data-i18n="calibrationUpdatedMessage">Kalibrasyon kaydı güncellendi.</div>
            <?php elseif (($_GET["cal"] ?? "") === "removed"): ?>
                <div class="form-message success" data-i18n="calibrationRemovedMessage">Kalibrasyon kaydı silindi.</div>
            <?php endif; ?>
            <?php if ($formError !== ""): ?>
                <div class="form-message error"><?= htmlspecialchars($formError, ENT_QUOTES, "UTF-8") ?></div>
            <?php endif; ?>

            <form class="auditor-form" method="post" action="equipment-detail.php?id=<?= $equipmentId ?>">
                <?= qmsCsrfField($csrfScope) ?>
                <input type="hidden" name="form_type" value="update_equipment">
                <input type="hidden" name="equipment_id" value="<?= $equipmentId ?>">
                <div class="form-grid">
                    <label class="form-field">
                        <span data-i18n="companySelectLabel">Şirket</span>
                        <input type="text" value="<?= htmlspecialchars($equipment["company_name"], ENT_QUOTES, "UTF-8") ?>" readonly>
                    </label>
                    <label class="form-field">
                        <span data-i18n="equipmentAssetCodeLabel">Demirbaş Kodu</span>
                        <input type="text" name="asset_code" maxlength="60" value="<?= htmlspecialchars((string) ($equipment["asset_code"] ?? ""), ENT_QUOTES, "UTF-8") ?>">
                    </label>
                    <label class="form-field">
                        <span data-i18n="equipmentNameLabel">Ekipman Adı</span>
                        <input type="text" name="name" maxlength="255" value="<?= htmlspecialchars($equipment["name"], ENT_QUOTES, "UTF-8") ?>" required>
                    </label>
                    <label class="form-field">
                        <span data-i18n="equipmentCategoryLabel">Kategori</span>
                        <input type="text" name="category" maxlength="100" value="<?= htmlspecialchars((string) ($equipment["category"] ?? ""), ENT_QUOTES, "UTF-8") ?>">
                    </label>
                    <label class="form-field">
                        <span data-i18n="equipmentManufacturerLabel">Üretici</span>
                        <input type="text" name="manufacturer" maxlength="150" value="<?= htmlspecialchars((string) ($equipment["manufacturer"] ?? ""), ENT_QUOTES, "UTF-8") ?>">
                    </label>
                    <label class="form-field">
                        <span data-i18n="equipmentModelLabel">Model</span>
                        <input type="text" name="model" maxlength="150" value="<?= htmlspecialchars((string) ($equipment["model"] ?? ""), ENT_QUOTES, "UTF-8") ?>">
                    </label>
                    <label class="form-field">
                        <span data-i18n="equipmentSerialLabel">Seri No</span>
                        <input type="text" name="serial_number" maxlength="120" value="<?= htmlspecialchars((string) ($equipment["serial_number"] ?? ""), ENT_QUOTES, "UTF-8") ?>">
                    </label>
                    <label class="form-field">
                        <span data-i18n="equipmentLocationLabel">Konum</span>
                        <input type="text" name="location" maxlength="150" value="<?= htmlspecialchars((string) ($equipment["location"] ?? ""), ENT_QUOTES, "UTF-8") ?>">
                    </label>
                    <label class="form-field">
                        <span data-i18n="equipmentResponsibleLabel">Sorumlu (isteğe bağlı)</span>
                        <select name="responsible_user_id">
                            <option value="0" data-i18n="responsibleUserNoneOption">— seçilmedi</option>
                            <?php foreach ($responsibleOptions as $option): ?>
                                <option value="<?= (int) $option["id"] ?>" <?= (int) ($equipment["responsible_user_id"] ?? 0) === (int) $option["id"] ? "selected" : "" ?>><?= htmlspecialchars($option["full_name"], ENT_QUOTES, "UTF-8") ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="form-field">
                        <span data-i18n="equipmentIntervalLabel">Kalibrasyon Aralığı (gün)</span>
                        <input type="text" name="calibration_interval_days" value="<?= htmlspecialchars((string) ($equipment["calibration_interval_days"] ?? ""), ENT_QUOTES, "UTF-8") ?>" placeholder="Örn. 365">
                    </label>
                    <label class="form-field">
                        <span data-i18n="equipmentStatusLabel">Durum</span>
                        <select name="status">
                            <?php foreach ($statusLabels as $value => $label): ?>
                                <option value="<?= $value ?>" <?= $equipment["status"] === $value ? "selected" : "" ?>><?= htmlspecialchars($label, ENT_QUOTES, "UTF-8") ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="form-field form-field-wide">
                        <span data-i18n="equipmentNotesLabel">Notlar</span>
                        <textarea name="notes" rows="3"><?= htmlspecialchars((string) ($equipment["notes"] ?? ""), ENT_QUOTES, "UTF-8") ?></textarea>
                    </label>
                </div>
                <div class="form-actions">
                    <button class="primary-button" type="submit" data-i18n="saveEquipmentButton">Ekipmanı Kaydet</button>
                </div>
            </form>
        </section>

        <section class="page-section form-panel">
            <div class="section-heading compact-heading">
                <div>
                    <h3 data-i18n="addCalibrationTitle">Kalibrasyon Ekle</h3>
                    <p data-i18n="addCalibrationText">Kalibrasyon sonucu ve sonraki termini kaydedin; uygun değil sonucu yöneticilere bildirilir.</p>
                </div>
            </div>
            <form class="auditor-form" method="post" action="equipment-detail.php?id=<?= $equipmentId ?>">
                <?= qmsCsrfField($csrfScope) ?>
                <input type="hidden" name="form_type" value="add_calibration">
                <input type="hidden" name="equipment_id" value="<?= $equipmentId ?>">
                <div class="form-grid">
                    <label class="form-field">
                        <span data-i18n="calibrationDateLabel">Kalibrasyon Tarihi</span>
                        <input type="date" name="calibrated_on" required>
                    </label>
                    <label class="form-field">
                        <span data-i18n="calibrationNextLabel">Sonraki Termin</span>
                        <input type="date" name="next_date">
                    </label>
                    <label class="form-field">
                        <span data-i18n="calibrationResultLabel">Sonuç</span>
                        <select name="result">
                            <?php foreach ($calResultLabels as $value => $label): ?>
                                <option value="<?= $value ?>"><?= htmlspecialchars($label, ENT_QUOTES, "UTF-8") ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="form-field">
                        <span data-i18n="calibrationCertificateLabel">Sertifika No</span>
                        <input type="text" name="certificate_no" maxlength="120">
                    </label>
                    <label class="form-field">
                        <span data-i18n="calibrationPerformedByLabel">Yapan</span>
                        <input type="text" name="performed_by" maxlength="150">
                    </label>
                    <label class="form-field form-field-wide">
                        <span data-i18n="calibrationNoteLabel">Not</span>
                        <input type="text" name="note">
                    </label>
                </div>
                <div class="form-actions">
                    <button class="primary-button" type="submit" data-i18n="saveCalibrationButton">Kalibrasyonu Kaydet</button>
                </div>
            </form>
        </section>

        <section class="console-card checklist-section">
            <div class="section-heading compact-heading">
                <div>
                    <h3 data-i18n="calibrationHistoryTitle">Kalibrasyon Geçmişi</h3>
                    <p data-i18n="calibrationHistoryText">Ekipmanın kalibrasyon kayıtları.</p>
                </div>
            </div>
            <div class="admin-list">
                <?php if (!$calibrations): ?>
                    <div class="empty-state" data-i18n="noCalibrationRecordsText">Bu ekipman için kalibrasyon kaydı yok.</div>
                <?php endif; ?>
                <?php foreach ($calibrations as $cal): ?>
                    <div class="admin-list-item">
                        <div class="list-item-main">
                            <strong><?= htmlspecialchars((string) $cal["calibrated_on"], ENT_QUOTES, "UTF-8") ?> → <?= htmlspecialchars((string) ($cal["next_date"] ?: "-"), ENT_QUOTES, "UTF-8") ?></strong>
                            <span><?= htmlspecialchars($calResultLabels[$cal["result"]] ?? $cal["result"], ENT_QUOTES, "UTF-8") ?><?= $cal["certificate_no"] ? " · " . htmlspecialchars($cal["certificate_no"], ENT_QUOTES, "UTF-8") : "" ?><?= $cal["performed_by"] ? " · " . htmlspecialchars($cal["performed_by"], ENT_QUOTES, "UTF-8") : "" ?></span>
                        </div>
                        <div class="list-item-side">
                            <form method="post" action="equipment-detail.php?id=<?= $equipmentId ?>">
                                <?= qmsCsrfField($csrfScope) ?>
                                <input type="hidden" name="form_type" value="remove_calibration">
                                <input type="hidden" name="equipment_id" value="<?= $equipmentId ?>">
                                <input type="hidden" name="calibration_id" value="<?= (int) $cal["id"] ?>">
                                <button class="danger-button danger-button-sm" type="submit" data-i18n="removeCalibrationButton">Sil</button>
                            </form>
                        </div>
                    </div>
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
