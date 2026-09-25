<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/access.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/external-audit-functions.php';

$auditId = (int) ($_GET["id"] ?? 0);
if ($auditId <= 0) {
    header("Location: external-audits.php");
    exit;
}

$userId = (int) ($_SESSION["qms_user_id"] ?? 0);
$role = qmsCurrentRole();
$csrfScope = 'external_audit_detail';

// Kapsamli okuma: id degistirilerek baska sirketin denetimi acilamaz.
$audit = qmsExternalAuditFind($pdo, $auditId, $userId, $role);
if (!$audit) {
    header("Location: external-audits.php");
    exit;
}

$typeLabels = qmsExternalAuditTypeLabels();
$typeI18n = qmsExternalAuditTypeI18nKeys();
$statusLabels = qmsExternalAuditStatusLabels();
$statusI18n = qmsExternalAuditStatusI18nKeys();
$findingStatusLabels = qmsFindingStatusLabels();
$findingStatusI18n = qmsFindingStatusI18nKeys();
$findingCategoryLabels = qmsFindingCategoryLabels();

$formError = "";
$formSuccess = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    qmsCsrfVerify($csrfScope, $_POST["csrf"] ?? null);
    $formType = (string) ($_POST["form_type"] ?? "");
    $redirect = "external-audit-detail.php?id=" . $auditId;

    if ($formType === "update_audit") {
        $title = trim((string) ($_POST["title"] ?? ""));
        if ($title === "") {
            $formError = "Lütfen denetim başlığını girin.";
        } else {
            $pdo->prepare(
                "UPDATE external_audits SET title=?, audited_by=?, auditor_name=?, audit_date=?, status=?, notes=? WHERE id=?"
            )->execute([
                mb_substr($title, 0, 255),
                trim((string) ($_POST["audited_by"] ?? "")) !== "" ? mb_substr(trim((string) $_POST["audited_by"]), 0, 180) : null,
                trim((string) ($_POST["auditor_name"] ?? "")) !== "" ? mb_substr(trim((string) $_POST["auditor_name"]), 0, 180) : null,
                trim((string) ($_POST["audit_date"] ?? "")) !== "" ? trim((string) $_POST["audit_date"]) : null,
                in_array($_POST["status"] ?? "", QMS_EXTERNAL_AUDIT_STATUSES, true) ? $_POST["status"] : "planned",
                trim((string) ($_POST["notes"] ?? "")) !== "" ? mb_substr(trim((string) $_POST["notes"]), 0, 4000) : null,
                $auditId,
            ]);
            $audit = qmsExternalAuditFind($pdo, $auditId, $userId, $role);
            $formSuccess = "Dış denetim güncellendi.";
        }
    } elseif ($formType === "add_finding") {
        $newId = qmsExternalAddFinding($pdo, $auditId, [
            "finding_text" => (string) ($_POST["finding_text"] ?? ""),
            "category" => (string) ($_POST["category"] ?? ""),
            "due_date" => (string) ($_POST["due_date"] ?? ""),
            "notes" => (string) ($_POST["notes"] ?? ""),
        ], $userId, $role);

        if ($newId !== null) {
            header("Location: " . $redirect . "&f=added");
            exit;
        }
        $formError = "Bulgu eklenemedi. Geçerli bir bulgu metni ve kategori girin.";
    } elseif ($formType === "update_finding_status") {
        $findingId = (int) ($_POST["finding_id"] ?? 0);
        $status = (string) ($_POST["finding_status"] ?? "");
        if (qmsExternalUpdateFindingStatus($pdo, $auditId, $findingId, $status, $userId, $role)) {
            header("Location: " . $redirect . "&f=updated");
            exit;
        }
        $formError = "Bulgu durumu güncellenemedi.";
    } else {
        $formError = "Geçersiz istek.";
    }
}

$findings = qmsExternalFindings($pdo, $auditId);
$activeNav = "external_audits";

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f9fafb">
    <title>QMS Dış Denetim Detayı</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar">
        <div class="topbar-inner">
            <div class="page-title-block">
                <strong data-i18n="externalAuditDetailTitle">Dış Denetim Detayı</strong>
                <span><?= htmlspecialchars($audit["company_name"], ENT_QUOTES, "UTF-8") ?> · <?= htmlspecialchars($audit["title"], ENT_QUOTES, "UTF-8") ?></span>
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
                <span class="section-kicker" data-i18n="externalAuditsKicker">Uygunluk & Kapama</span>
                <h1 data-i18n="externalAuditDetailTitle">Dış Denetim Detayı</h1>
                <p><?= htmlspecialchars($audit["title"], ENT_QUOTES, "UTF-8") ?> · <?= htmlspecialchars($typeLabels[$audit["audit_type"]] ?? $audit["audit_type"], ENT_QUOTES, "UTF-8") ?></p>
            </div>
            <a class="secondary-button" href="external-audits.php" data-i18n="backToExternalAuditsButton">Dış Denetimlere Dön</a>
        </section>

        <section class="dashboard-grid compact-dashboard-grid">
            <div class="dashboard-card metric-blue">
                <?= appIcon("approvals", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="externalAuditStatusLabel">Durum</span>
                    <strong class="dashboard-card-number detail-card-value" data-i18n="<?= $statusI18n[$audit["status"]] ?? "" ?>"><?= htmlspecialchars($statusLabels[$audit["status"]] ?? $audit["status"], ENT_QUOTES, "UTF-8") ?></strong>
                </div>
            </div>
            <div class="dashboard-card metric-violet">
                <?= appIcon("warning", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="externalFindingOpenLabel">Açık Bulgu</span>
                    <strong class="dashboard-card-number detail-card-value"><?= (int) $audit["open_findings"] ?></strong>
                </div>
            </div>
            <div class="dashboard-card metric-red">
                <?= appIcon("clock", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="externalFindingOverdueLabel">Geciken Bulgu</span>
                    <strong class="dashboard-card-number detail-card-value"><?= (int) $audit["overdue_findings"] ?></strong>
                </div>
            </div>
        </section>

        <?php if ($formSuccess !== ""): ?>
            <div class="form-message success"><?= htmlspecialchars($formSuccess, ENT_QUOTES, "UTF-8") ?></div>
        <?php endif; ?>
        <?php if ($formError !== ""): ?>
            <div class="form-message error"><?= htmlspecialchars($formError, ENT_QUOTES, "UTF-8") ?></div>
        <?php endif; ?>
        <?php if (($_GET["f"] ?? "") === "added"): ?><div class="form-message success" data-i18n="externalFindingAddedMessage">Bulgu eklendi.</div><?php endif; ?>
        <?php if (($_GET["f"] ?? "") === "updated"): ?><div class="form-message success" data-i18n="externalFindingUpdatedMessage">Bulgu durumu güncellendi.</div><?php endif; ?>

        <section class="form-panel">
            <div class="section-heading compact-heading">
                <div>
                    <h3 data-i18n="externalAuditInfoTitle">Dış Denetim Bilgileri</h3>
                    <p data-i18n="externalAuditInfoText">Denetim künyesini ve durumunu düzenleyin.</p>
                </div>
            </div>
            <form class="auditor-form" method="post" action="external-audit-detail.php?id=<?= $auditId ?>">
                <?= qmsCsrfField($csrfScope) ?>
                <input type="hidden" name="form_type" value="update_audit">
                <div class="form-grid">
                    <label class="form-field"><span data-i18n="externalAuditTypeLabel">Denetim Kaynağı</span><select name="audit_type" disabled><?php foreach ($typeLabels as $key => $label): ?><option value="<?= $key ?>" <?= $audit["audit_type"] === $key ? "selected" : "" ?>><?= htmlspecialchars($label, ENT_QUOTES, "UTF-8") ?></option><?php endforeach; ?></select></label>
                    <label class="form-field"><span data-i18n="externalAuditStatusLabel">Durum</span><select name="status"><?php foreach ($statusLabels as $key => $label): ?><option value="<?= $key ?>" <?= $audit["status"] === $key ? "selected" : "" ?>><?= htmlspecialchars($label, ENT_QUOTES, "UTF-8") ?></option><?php endforeach; ?></select></label>
                    <label class="form-field"><span data-i18n="externalAuditTitleLabel">Denetim Başlığı</span><input type="text" name="title" value="<?= htmlspecialchars($audit["title"], ENT_QUOTES, "UTF-8") ?>" required maxlength="255"></label>
                    <label class="form-field"><span data-i18n="externalAuditedByLabel">Denetleyen Kuruluş</span><input type="text" name="audited_by" value="<?= htmlspecialchars((string) ($audit["audited_by"] ?? ""), ENT_QUOTES, "UTF-8") ?>" maxlength="180"></label>
                    <label class="form-field"><span data-i18n="externalAuditorNameLabel">Denetçi Adı</span><input type="text" name="auditor_name" value="<?= htmlspecialchars((string) ($audit["auditor_name"] ?? ""), ENT_QUOTES, "UTF-8") ?>" maxlength="180"></label>
                    <label class="form-field"><span data-i18n="externalAuditDateLabel">Denetim Tarihi</span><input type="date" name="audit_date" value="<?= htmlspecialchars((string) ($audit["audit_date"] ?? ""), ENT_QUOTES, "UTF-8") ?>"></label>
                    <label class="form-field form-field-wide"><span data-i18n="externalAuditNotesLabel">Notlar</span><textarea name="notes" rows="3"><?= htmlspecialchars((string) ($audit["notes"] ?? ""), ENT_QUOTES, "UTF-8") ?></textarea></label>
                </div>
                <div class="form-actions">
                    <button class="primary-button" type="submit" data-i18n="saveExternalAuditButton">Dış Denetimi Kaydet</button>
                </div>
            </form>
        </section>

        <section class="console-card checklist-section">
            <div class="section-heading compact-heading">
                <div>
                    <h3 data-i18n="externalFindingsTitle">Bulgular (Kapama Takibi)</h3>
                    <p><span data-i18n="externalFindingCountLabel">Toplam bulgu</span>: <strong><?= count($findings) ?></strong></p>
                </div>
            </div>

            <form class="auditor-form" method="post" action="external-audit-detail.php?id=<?= $auditId ?>">
                <?= qmsCsrfField($csrfScope) ?>
                <input type="hidden" name="form_type" value="add_finding">
                <div class="form-grid">
                    <label class="form-field form-field-wide"><span data-i18n="externalFindingTextLabel">Bulgu Metni</span><input type="text" name="finding_text" required maxlength="255"></label>
                    <label class="form-field"><span data-i18n="externalFindingCategoryLabel">Kategori</span><select name="category" required><?php foreach ($findingCategoryLabels as $key => $label): ?><option value="<?= $key ?>"><?= htmlspecialchars($label, ENT_QUOTES, "UTF-8") ?></option><?php endforeach; ?></select></label>
                    <label class="form-field"><span data-i18n="externalFindingDueDateLabel">Termin</span><input type="date" name="due_date"></label>
                    <label class="form-field form-field-wide"><span data-i18n="externalFindingNotesLabel">Notlar</span><input type="text" name="notes" maxlength="4000"></label>
                </div>
                <div class="form-actions">
                    <button class="secondary-button" type="submit" data-i18n="addExternalFindingButton">Bulgu Ekle</button>
                </div>
            </form>

            <div class="admin-list">
                <?php if (!$findings): ?>
                    <div class="empty-state" data-i18n="noExternalFindingsText">Bu denetim için henüz bulgu eklenmedi.</div>
                <?php endif; ?>
                <?php foreach ($findings as $finding): ?>
                    <div class="admin-list-item">
                        <div class="list-item-main">
                            <strong><?= htmlspecialchars($finding["finding_text"], ENT_QUOTES, "UTF-8") ?></strong>
                            <span><?= htmlspecialchars($findingCategoryLabels[$finding["category"]] ?? $finding["category"], ENT_QUOTES, "UTF-8") ?> · Termin: <?= htmlspecialchars($finding["due_date"] ?: "-", ENT_QUOTES, "UTF-8") ?><?= $finding["overdue"] ? ' · <span class="status-pill" data-i18n="externalFindingOverduePill">Gecikti</span>' : '' ?></span>
                        </div>
                        <div class="list-item-side">
                            <span class="status-pill" data-i18n="<?= $findingStatusI18n[$finding["status"]] ?? "" ?>"><?= htmlspecialchars($findingStatusLabels[$finding["status"]] ?? $finding["status"], ENT_QUOTES, "UTF-8") ?></span>
                            <form method="post" action="external-audit-detail.php?id=<?= $auditId ?>">
                                <?= qmsCsrfField($csrfScope) ?>
                                <input type="hidden" name="form_type" value="update_finding_status">
                                <input type="hidden" name="finding_id" value="<?= (int) $finding["id"] ?>">
                                <select name="finding_status" onchange="this.form.submit()">
                                    <?php foreach ($findingStatusLabels as $key => $label): ?>
                                        <option value="<?= $key ?>" <?= $finding["status"] === $key ? "selected" : "" ?>><?= htmlspecialchars($label, ENT_QUOTES, "UTF-8") ?></option>
                                    <?php endforeach; ?>
                                </select>
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
