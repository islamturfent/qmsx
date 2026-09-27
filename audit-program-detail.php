<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/access.php';
qmsRequirePermission('audit_programs.manage');
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/audit-program-functions.php';
require_once __DIR__ . '/includes/app-ui.php';

$programId = (int) ($_GET["id"] ?? $_POST["program_id"] ?? 0);
$userId = (int) ($_SESSION["qms_user_id"] ?? 0);
$role = qmsCurrentRole();
$csrfScope = 'audit_program_detail';

$program = qmsAuditProgramFind($pdo, $programId, $userId, $role);
if (!$program) {
    header("Location: audit-programs.php");
    exit;
}

$companyId = (int) $program["company_id"];
$statusLabels = qmsAuditProgramStatusLabels();
$statusI18n = qmsAuditProgramStatusI18nKeys();

$linked = qmsAuditProgramAudits($pdo, $programId);
$linkedIds = array_map('intval', array_column($linked, 'id'));
$available = array_values(array_filter(
    qmsAuditProgramAvailableAudits($pdo, $programId, $companyId, $userId, $role),
    static fn(array $audit): bool => !in_array((int) $audit["id"], $linkedIds, true)
));

$formError = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    qmsCsrfVerify($csrfScope, $_POST["csrf"] ?? null);

    $formType = (string) ($_POST["form_type"] ?? "");
    $redirect = "audit-program-detail.php?id=" . $programId;

    if ($formType === "update_program") {
        $formData = [
            "title" => trim((string) ($_POST["title"] ?? "")),
            "year" => (int) ($_POST["year"] ?? 0),
            "description" => trim((string) ($_POST["description"] ?? "")),
            "status" => (string) ($_POST["status"] ?? "draft"),
        ];

        if ($formData["title"] === "") {
            $formError = "Lütfen program başlığını girin.";
        } elseif (!in_array($formData["status"], QMS_AUDIT_PROGRAM_STATUSES, true)) {
            $formError = "Geçerli bir durum seçin.";
        } elseif ($formData["year"] < 2000 || $formData["year"] > 2100) {
            $formError = "Geçerli bir plan yılı girin.";
        } else {
            $approvedDate = $program["approved_date"];
            if ($formData["status"] === "active" && !$approvedDate) {
                $approvedDate = date("Y-m-d");
            }
            if ($formData["status"] !== "active") {
                $approvedDate = null;
            }

            $pdo->prepare(
                "UPDATE audit_programs
                 SET title = :title, year = :year, description = :description,
                     status = :status, approved_date = :approved_date, updated_by = :updated_by
                 WHERE id = :id"
            )->execute([
                "title" => mb_substr($formData["title"], 0, 255),
                "year" => $formData["year"],
                "description" => $formData["description"] !== "" ? mb_substr($formData["description"], 0, 4000) : null,
                "status" => $formData["status"],
                "approved_date" => $approvedDate,
                "updated_by" => $userId ?: null,
                "id" => $programId,
            ]);

            if ($formData["status"] !== $program["status"]) {
                require_once __DIR__ . '/includes/audit-log-functions.php';
                qmsAuditLog($pdo, $companyId, $userId, 'audit_program', $programId, $formData["status"] === 'completed' ? 'complete' : 'status_change', 'Denetim programı durumu: ' . $statusLabels[$formData["status"]] . ' - ' . $formData["title"]);
            }

            header("Location: " . $redirect . "&updated=1");
            exit;
        }
    } elseif ($formType === "link_audit") {
        $auditId = (int) ($_POST["audit_id"] ?? 0);
        if (qmsAuditProgramLinkAudit($pdo, $programId, $auditId, $companyId, $userId, $role)) {
            require_once __DIR__ . '/includes/audit-log-functions.php';
            qmsAuditLog($pdo, $companyId, $userId, 'audit_program', $programId, 'update', 'Programa denetim bağlandı: ' . $auditId . ' - ' . $program["title"]);
            header("Location: " . $redirect . "&linked=1");
            exit;
        }
        $formError = "Geçersiz denetim seçimi.";
    } elseif ($formType === "unlink_audit") {
        $auditId = (int) ($_POST["audit_id"] ?? 0);
        qmsAuditProgramUnlinkAudit($pdo, $programId, $auditId);
        require_once __DIR__ . '/includes/audit-log-functions.php';
        qmsAuditLog($pdo, $companyId, $userId, 'audit_program', $programId, 'update', 'Programdan denetim çıkarıldı: ' . $auditId . ' - ' . $program["title"]);
        header("Location: " . $redirect . "&unlinked=1");
        exit;
    } else {
        $formError = "Geçersiz istek.";
    }
}

$activeNav = "audit_programs";

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f9fafb">
    <title>QuAmi Denetim Programı Detayı</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar">
        <div class="topbar-inner">
            <div class="page-title-block">
                <strong data-i18n="auditProgramDetailTitle">Denetim Programı Detayı</strong>
                <span><?= htmlspecialchars($program["title"], ENT_QUOTES, "UTF-8") ?></span>
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
                <span class="section-kicker" data-i18n="auditProgramKicker">Denetim Planlama</span>
                <h1 data-i18n="auditProgramDetailTitle">Denetim Programı Detayı</h1>
                <p><?= htmlspecialchars($program["title"], ENT_QUOTES, "UTF-8") ?> · <?= (int) $program["year"] ?></p>
            </div>
            <a class="secondary-button" href="audit-programs.php" data-i18n="backToProgramsButton">Programlara Dön</a>
        </section>

        <section class="dashboard-grid compact-dashboard-grid">
            <div class="dashboard-card metric-blue">
                <?= appIcon("check", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="auditProgramStatusLabel">Durum</span>
                    <strong class="dashboard-card-number detail-card-value" data-i18n="<?= $statusI18n[$program["status"]] ?? "" ?>"><?= htmlspecialchars($statusLabels[$program["status"]] ?? $program["status"], ENT_QUOTES, "UTF-8") ?></strong>
                </div>
            </div>
            <div class="dashboard-card metric-violet">
                <?= appIcon("table", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="auditProgramYearLabel">Plan Yılı</span>
                    <strong class="dashboard-card-number detail-card-value"><?= (int) $program["year"] ?></strong>
                </div>
            </div>
            <div class="dashboard-card metric-teal">
                <?= appIcon("checkBadge", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="auditProgramLinkedLabel">Bağlı Denetim</span>
                    <strong class="dashboard-card-number detail-card-value"><?= count($linked) ?></strong>
                </div>
            </div>
            <div class="dashboard-card metric-orange">
                <?= appIcon("approvals", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="auditProgramApprovedLabel">Onay Tarihi</span>
                    <strong class="dashboard-card-number detail-card-value"><?= htmlspecialchars((string) ($program["approved_date"] ?? "—"), ENT_QUOTES, "UTF-8") ?></strong>
                </div>
            </div>
        </section>

        <section class="form-panel">
            <?php if (($_GET["created"] ?? "") === "1"): ?>
                <div class="form-message success" data-i18n="auditProgramCreatedMessage">Program oluşturuldu.</div>
            <?php endif; ?>
            <?php if (($_GET["updated"] ?? "") === "1"): ?>
                <div class="form-message success" data-i18n="auditProgramUpdatedMessage">Program güncellendi.</div>
            <?php elseif (($_GET["linked"] ?? "") === "1"): ?>
                <div class="form-message success" data-i18n="auditProgramLinkedMessage">Denetim programa eklendi.</div>
            <?php elseif (($_GET["unlinked"] ?? "") === "1"): ?>
                <div class="form-message success" data-i18n="auditProgramUnlinkedMessage">Denetim programdan çıkarıldı.</div>
            <?php endif; ?>
            <?php if ($formError !== ""): ?>
                <div class="form-message error"><?= htmlspecialchars($formError, ENT_QUOTES, "UTF-8") ?></div>
            <?php endif; ?>

            <form class="auditor-form" method="post" action="audit-program-detail.php?id=<?= $programId ?>">
                <?= qmsCsrfField($csrfScope) ?>
                <input type="hidden" name="form_type" value="update_program">
                <input type="hidden" name="program_id" value="<?= $programId ?>">
                <div class="form-grid">
                    <label class="form-field">
                        <span data-i18n="companySelectLabel">Şirket</span>
                        <input type="text" value="<?= htmlspecialchars($program["company_name"], ENT_QUOTES, "UTF-8") ?>" readonly>
                    </label>
                    <label class="form-field">
                        <span data-i18n="auditProgramTitleLabel">Program Başlığı</span>
                        <input type="text" name="title" maxlength="255" value="<?= htmlspecialchars($program["title"], ENT_QUOTES, "UTF-8") ?>" required>
                    </label>
                    <label class="form-field">
                        <span data-i18n="auditProgramYearLabel">Plan Yılı</span>
                        <input type="number" name="year" min="2000" max="2100" value="<?= htmlspecialchars((string) $program["year"], ENT_QUOTES, "UTF-8") ?>" required>
                    </label>
                    <label class="form-field">
                        <span data-i18n="auditProgramStatusLabel">Durum</span>
                        <select name="status">
                            <?php foreach ($statusLabels as $value => $label): ?>
                                <option value="<?= $value ?>" <?= $program["status"] === $value ? "selected" : "" ?> data-i18n="<?= $statusI18n[$value] ?>"><?= htmlspecialchars($label, ENT_QUOTES, "UTF-8") ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="form-field form-field-wide">
                        <span data-i18n="auditProgramDescriptionLabel">Açıklama</span>
                        <textarea name="description" rows="3"><?= htmlspecialchars((string) ($program["description"] ?? ""), ENT_QUOTES, "UTF-8") ?></textarea>
                    </label>
                </div>
                <div class="form-actions">
                    <button class="primary-button" type="submit" data-i18n="saveAuditProgramButton">Programı Kaydet</button>
                </div>
            </form>
        </section>

        <section class="page-section form-panel">
            <div class="section-heading compact-heading">
                <div>
                    <h3 data-i18n="auditProgramLinkTitle">Programa Denetim Ekle</h3>
                    <p data-i18n="auditProgramLinkText">Bu şirketin denetimlerinden programa denetim bağlayın.</p>
                </div>
            </div>
            <form class="auditor-form link-audit-form" method="post" action="audit-program-detail.php?id=<?= $programId ?>">
                <?= qmsCsrfField($csrfScope) ?>
                <input type="hidden" name="form_type" value="link_audit">
                <input type="hidden" name="program_id" value="<?= $programId ?>">
                <div class="form-grid">
                    <label class="form-field">
                        <span data-i18n="auditSelectLabel">Denetim</span>
                        <select name="audit_id" required>
                            <option value="0" data-i18n="selectAuditOption">Denetim seçin</option>
                            <?php if (!$available): ?>
                                <option value="0" disabled data-i18n="noAvailableAuditsText">Programa eklenebilecek denetim yok</option>
                            <?php endif; ?>
                            <?php foreach ($available as $audit): ?>
                                <option value="<?= (int) $audit["id"] ?>"><?= htmlspecialchars($audit["title"], ENT_QUOTES, "UTF-8") ?> · <?= htmlspecialchars($audit["status"], ENT_QUOTES, "UTF-8") ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>
                <div class="form-actions">
                    <button class="primary-button" type="submit" data-i18n="linkAuditButton">Denetimi Ekle</button>
                </div>
            </form>
        </section>

        <section class="console-card checklist-section">
            <div class="section-heading compact-heading">
                <div>
                    <h3 data-i18n="auditProgramLinkedAuditsTitle">Bağlı Denetimler</h3>
                    <p data-i18n="auditProgramLinkedAuditsText">Programdaki denetimlerin durumu ve denetçi sayısı.</p>
                </div>
            </div>
            <div class="admin-list">
                <?php if (!$linked): ?>
                    <div class="empty-state" data-i18n="noLinkedAuditsText">Bu programa henüz denetim bağlanmadı.</div>
                <?php endif; ?>
                <?php foreach ($linked as $audit): ?>
                    <div class="admin-list-item">
                        <div class="list-item-main">
                            <strong><?= htmlspecialchars($audit["title"], ENT_QUOTES, "UTF-8") ?></strong>
                            <span><?= htmlspecialchars((string) ($audit["audit_type"] ?? "-"), ENT_QUOTES, "UTF-8") ?> · <?= htmlspecialchars((string) ($audit["planned_date"] ?? "-"), ENT_QUOTES, "UTF-8") ?></span>
                        </div>
                        <div class="list-item-side">
                            <span class="status-pill"><?= htmlspecialchars($audit["status"], ENT_QUOTES, "UTF-8") ?></span>
                            <form method="post" action="audit-program-detail.php?id=<?= $programId ?>">
                                <?= qmsCsrfField($csrfScope) ?>
                                <input type="hidden" name="form_type" value="unlink_audit">
                                <input type="hidden" name="program_id" value="<?= $programId ?>">
                                <input type="hidden" name="audit_id" value="<?= (int) $audit["id"] ?>">
                                <button class="danger-button danger-button-sm" type="submit" data-i18n="unlinkAuditButton">Çıkar</button>
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
