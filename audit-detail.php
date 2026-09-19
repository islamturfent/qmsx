<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/access.php';

$auditId = (int) ($_GET["id"] ?? 0);

if ($auditId <= 0) {
    header("Location: dashboard.php");
    exit;
}

$userId = (int) ($_SESSION["qms_user_id"] ?? 0);
$isSuperAdmin = ($_SESSION["qms_role"] ?? "") === "super_admin";

$sql = "SELECT audits.id, audits.company_id, audits.title, audits.audit_type,
            audits.planned_date, audits.status, audits.active, companies.company_name
     FROM audits
     INNER JOIN companies ON companies.id = audits.company_id
     WHERE audits.id = ?";
$params = [$auditId];
$scope = qmsAuditRecordScope($pdo, $userId, 'audits.id', 'audits.company_id');
$sql .= $scope['sql'];
$params = array_merge($params, $scope['params']);
$sql .= " LIMIT 1";

$auditStmt = $pdo->prepare($sql);
$auditStmt->execute($params);
$audit = $auditStmt->fetch(PDO::FETCH_ASSOC);

if (!$audit) {
    header("Location: dashboard.php");
    exit;
}

$formError = "";
$allowedResults = ["pending", "compliant", "noncompliant", "not_applicable"];

require_once __DIR__ . '/includes/csrf.php';

$csrfToken = qmsCsrfToken('audit_detail');

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    qmsCsrfVerify('audit_detail', $_POST["csrf"] ?? null);

    $formType = $_POST["form_type"] ?? "";

    if ($formType === "update_auditors") {
        $selectedIds = array_map('intval', (array) ($_POST["auditor_ids"] ?? []));
        $selectedIds = array_values(array_unique(array_filter($selectedIds)));

        // Yalnizca bu sirkette kayitli denetciler atanabilir.
        $companyAuditorStmt = $pdo->prepare("SELECT id FROM auditors WHERE company_id = :company_id AND active = 1");
        $companyAuditorStmt->execute(["company_id" => (int) $audit["company_id"]]);
        $allowedAuditorIds = array_map('intval', $companyAuditorStmt->fetchAll(PDO::FETCH_COLUMN));
        $validIds = array_values(array_intersect($selectedIds, $allowedAuditorIds));

        try {
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM audit_auditors WHERE audit_id = :audit_id")->execute(["audit_id" => $auditId]);
            if ($validIds) {
                $insertAssignment = $pdo->prepare("INSERT INTO audit_auditors (audit_id, auditor_id) VALUES (:audit_id, :auditor_id)");
                foreach ($validIds as $auditorId) {
                    $insertAssignment->execute(["audit_id" => $auditId, "auditor_id" => $auditorId]);
                }
            }
            $pdo->commit();

            header("Location: audit-detail.php?id=" . $auditId . "&auditors=updated");
            exit;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $formError = "Denetçi ataması kaydedilemedi.";
        }
    }

    if ($formType === "create_checklist_item") {
        $itemText = trim($_POST["item_text"] ?? "");
        $requirementRef = trim($_POST["requirement_ref"] ?? "");
        $notes = trim($_POST["notes"] ?? "");

        if ($itemText === "") {
            $formError = "Lütfen kontrol maddesini girin.";
        } else {
            $insertItem = $pdo->prepare(
                "INSERT INTO audit_checklist_items
                    (audit_id, item_text, requirement_ref, result_status, notes, active)
                 VALUES
                    (:audit_id, :item_text, :requirement_ref, 'pending', :notes, 1)"
            );
            $insertItem->execute([
                "audit_id" => $auditId,
                "item_text" => $itemText,
                "requirement_ref" => $requirementRef !== "" ? $requirementRef : null,
                "notes" => $notes !== "" ? $notes : null
            ]);

            header("Location: audit-detail.php?id=" . $auditId . "&checklist=created");
            exit;
        }
    }

    if ($formType === "update_checklist_item") {
        $itemId = (int) ($_POST["item_id"] ?? 0);
        $resultStatus = $_POST["result_status"] ?? "pending";
        $notes = trim($_POST["notes"] ?? "");

        if ($itemId > 0 && in_array($resultStatus, $allowedResults, true)) {
            $updateItem = $pdo->prepare(
                "UPDATE audit_checklist_items
                 SET result_status = :result_status, notes = :notes
                 WHERE id = :id AND audit_id = :audit_id"
            );
            $updateItem->execute([
                "result_status" => $resultStatus,
                "notes" => $notes !== "" ? $notes : null,
                "id" => $itemId,
                "audit_id" => $auditId
            ]);

            header("Location: audit-detail.php?id=" . $auditId . "&checklist=updated");
            exit;
        }

        $formError = "Kontrol maddesi güncellenemedi.";
    }

    if ($formType === "create_nonconformity") {
        $itemId = (int) ($_POST["item_id"] ?? 0);
        $itemStmt = $pdo->prepare(
            "SELECT id, item_text, notes
             FROM audit_checklist_items
             WHERE id = :id AND audit_id = :audit_id AND result_status = 'noncompliant'
             LIMIT 1"
        );
        $itemStmt->execute([
            "id" => $itemId,
            "audit_id" => $auditId
        ]);
        $noncompliantItem = $itemStmt->fetch(PDO::FETCH_ASSOC);

        if ($noncompliantItem) {
            $insertNonconformity = $pdo->prepare(
                "INSERT IGNORE INTO nonconformities
                    (company_id, audit_id, checklist_item_id, title, description, severity, status, active)
                 VALUES
                    (:company_id, :audit_id, :checklist_item_id, :title, :description, 'major', 'open', 1)"
            );
            $insertNonconformity->execute([
                "company_id" => $audit["company_id"],
                "audit_id" => $auditId,
                "checklist_item_id" => $noncompliantItem["id"],
                "title" => $noncompliantItem["item_text"],
                "description" => $noncompliantItem["notes"]
            ]);

            header("Location: audit-detail.php?id=" . $auditId . "&nonconformity=created");
            exit;
        }

        $formError = "Yalnızca uygunsuz kontrol maddeleri dönüştürülebilir.";
    }
}

// Denetci atamalari: bu denetime atanmis denetciler ve sirketin tum denetcileri.
$assignedAuditorStmt = $pdo->prepare(
    "SELECT auditors.id, auditors.first_name, auditors.last_name, auditors.email
     FROM audit_auditors
     INNER JOIN auditors ON auditors.id = audit_auditors.auditor_id
     WHERE audit_auditors.audit_id = :audit_id AND auditors.active = 1
     ORDER BY auditors.first_name, auditors.last_name"
);
$assignedAuditorStmt->execute(["audit_id" => $auditId]);
$assignedAuditors = $assignedAuditorStmt->fetchAll(PDO::FETCH_ASSOC);
$assignedAuditorIds = array_map('intval', array_column($assignedAuditors, 'id'));

$companyAuditorStmt = $pdo->prepare(
    "SELECT id, first_name, last_name, email
     FROM auditors
     WHERE company_id = :company_id AND active = 1
     ORDER BY first_name, last_name"
);
$companyAuditorStmt->execute(["company_id" => (int) $audit["company_id"]]);
$companyAuditors = $companyAuditorStmt->fetchAll(PDO::FETCH_ASSOC);

// Denetci kendi atamasini degistiremez; yalniz yonetim rolleri atama yapar.
$canAssignAuditors = in_array($_SESSION["qms_role"] ?? "", ["super_admin", "system_admin"], true);

$checklistStmt = $pdo->prepare(
    "SELECT id, item_text, requirement_ref, result_status, notes
     FROM audit_checklist_items
     WHERE audit_id = :audit_id AND active = 1
     ORDER BY id ASC"
);
$checklistStmt->execute(["audit_id" => $auditId]);
$checklistItems = $checklistStmt->fetchAll(PDO::FETCH_ASSOC);

$checklistCounts = [
    "total" => count($checklistItems),
    "compliant" => 0,
    "noncompliant" => 0
];

foreach ($checklistItems as $item) {
    if ($item["result_status"] === "compliant") {
        $checklistCounts["compliant"]++;
    }

    if ($item["result_status"] === "noncompliant") {
        $checklistCounts["noncompliant"]++;
    }
}

$nonconformitiesStmt = $pdo->prepare(
    "SELECT id, checklist_item_id, title, severity, status, due_date, responsible_person
     FROM nonconformities
     WHERE audit_id = :audit_id AND active = 1
     ORDER BY created_at DESC, id DESC"
);
$nonconformitiesStmt->execute(["audit_id" => $auditId]);
$nonconformities = $nonconformitiesStmt->fetchAll(PDO::FETCH_ASSOC);
$nonconformityItemIds = [];

foreach ($nonconformities as $nonconformity) {
    if ($nonconformity["checklist_item_id"] !== null) {
        $nonconformityItemIds[(int) $nonconformity["checklist_item_id"]] = true;
    }
}

$resultLabels = [
    "pending" => "Bekliyor",
    "compliant" => "Uygun",
    "noncompliant" => "Uygunsuz",
    "not_applicable" => "Uygulanamaz"
];

?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f9fafb">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="QMS">

    <title>QMS Denetim Detayı</title>

    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="assets/icons/qms-icon-192.png">
    <link rel="stylesheet" href="assets/css/style.css">
</head>

<body class="has-sidebar">
    <?php $activeNav = "companies"; require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar">
        <div class="topbar-inner">
            <a class="brand" href="dashboard.php">
                <div class="brand-icon">Q</div>
                <div class="brand-text">
                    <strong>QMS</strong>
                    <span>Quality Management System</span>
                </div>
            </a>

            <div class="topbar-actions">
                <button class="topbar-button" id="languageToggle" type="button">EN</button>
                <button class="topbar-button" id="themeToggle" type="button" aria-label="Tema değiştir">🌙</button>
                <a class="topbar-button topbar-link" href="logout.php" data-i18n="logoutLabel">Çıkış</a>
            </div>
        </div>
    </header>

    <main class="page-container">
        <section class="welcome-card">
            <div class="page-heading-actions">
                <div>
                    <span class="section-kicker" data-i18n="auditWorkspaceKicker">Denetim Çalışma Alanı</span>
                    <h1><?= htmlspecialchars($audit["title"], ENT_QUOTES, "UTF-8") ?></h1>
                    <p>
                        <?= htmlspecialchars($audit["company_name"], ENT_QUOTES, "UTF-8") ?>
                        · <?= htmlspecialchars($audit["audit_type"] ?: "-", ENT_QUOTES, "UTF-8") ?>
                        · <?= htmlspecialchars($audit["planned_date"] ?: "-", ENT_QUOTES, "UTF-8") ?>
                    </p>
                </div>
                <a class="secondary-button" href="company-detail.php?id=<?= (int) $audit["company_id"] ?>" data-i18n="backToCompanyButton">Şirkete Dön</a>
            </div>
        </section>

        <section class="dashboard-grid">
            <div class="dashboard-card">
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="checklistTotalLabel">Kontrol Maddesi</span>
                    <strong class="dashboard-card-number"><?= $checklistCounts["total"] ?></strong>
                </div>
            </div>
            <div class="dashboard-card">
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="compliantCountLabel">Uygun</span>
                    <strong class="dashboard-card-number"><?= $checklistCounts["compliant"] ?></strong>
                </div>
            </div>
            <div class="dashboard-card">
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="noncompliantCountLabel">Uygunsuz</span>
                    <strong class="dashboard-card-number"><?= $checklistCounts["noncompliant"] ?></strong>
                </div>
            </div>
        </section>

        <section class="super-admin-console">
            <div class="console-card">
                <div class="section-heading compact-heading">
                    <div>
                        <h3 data-i18n="addChecklistItemTitle">Kontrol Maddesi Ekle</h3>
                        <p data-i18n="addChecklistItemText">Denetimde doğrulanacak gerekliliği tanımlayın.</p>
                    </div>
                </div>

                <?php if (isset($_GET["checklist"]) && $_GET["checklist"] === "created"): ?>
                    <div class="form-message success" data-i18n="checklistCreatedMessage">Kontrol maddesi eklendi.</div>
                <?php endif; ?>

                <?php if (isset($_GET["checklist"]) && $_GET["checklist"] === "updated"): ?>
                    <div class="form-message success" data-i18n="checklistUpdatedMessage">Kontrol maddesi güncellendi.</div>
                <?php endif; ?>

                <?php if (isset($_GET["nonconformity"]) && $_GET["nonconformity"] === "created"): ?>
                    <div class="form-message success" data-i18n="nonconformityCreatedMessage">Uygunsuzluk kaydı oluşturuldu.</div>
                <?php endif; ?>

                <?php if ($formError !== ""): ?>
                    <div class="form-message error"><?= htmlspecialchars($formError, ENT_QUOTES, "UTF-8") ?></div>
                <?php endif; ?>

                <form class="auditor-form" method="post" action="audit-detail.php?id=<?= $auditId ?>">
                    <?= qmsCsrfField('audit_detail') ?>
                    <input type="hidden" name="form_type" value="create_checklist_item">
                    <div class="form-grid">
                        <label class="form-field form-field-wide">
                            <span data-i18n="checklistItemLabel">Kontrol Maddesi</span>
                            <input type="text" name="item_text" required>
                        </label>
                        <label class="form-field">
                            <span data-i18n="requirementRefLabel">Referans / Madde</span>
                            <input type="text" name="requirement_ref">
                        </label>
                        <label class="form-field">
                            <span data-i18n="notesLabel">Notlar</span>
                            <input type="text" name="notes">
                        </label>
                    </div>
                    <div class="form-actions">
                        <button class="primary-button" type="submit" data-i18n="addChecklistItemButton">Madde Ekle</button>
                    </div>
                </form>
            </div>

            <div class="console-card">
                <div class="section-heading compact-heading">
                    <div>
                        <h3 data-i18n="auditInfoTitle">Denetim Bilgileri</h3>
                        <p data-i18n="auditInfoText">Plan ve mevcut denetim durumu.</p>
                    </div>
                </div>
                <div class="admin-list">
                    <div class="admin-list-item">
                        <div>
                            <strong data-i18n="auditTypeLabel">Denetim Türü</strong>
                            <span><?= htmlspecialchars($audit["audit_type"] ?: "-", ENT_QUOTES, "UTF-8") ?></span>
                        </div>
                    </div>
                    <div class="admin-list-item">
                        <div>
                            <strong data-i18n="plannedDateLabel">Planlanan Tarih</strong>
                            <span><?= htmlspecialchars($audit["planned_date"] ?: "-", ENT_QUOTES, "UTF-8") ?></span>
                        </div>
                    </div>
                    <div class="admin-list-item">
                        <div>
                            <strong data-i18n="auditStatusLabel">Denetim Durumu</strong>
                            <span><?= htmlspecialchars($audit["status"], ENT_QUOTES, "UTF-8") ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="console-card">
            <div class="section-heading compact-heading">
                <div>
                    <h3 data-i18n="auditAuditorsTitle">Denetçiler</h3>
                    <p data-i18n="auditAuditorsText">Bu denetime atanmış denetçiler. Birden fazla denetçi atanabilir.</p>
                </div>
            </div>
            <?php if (isset($_GET["auditors"]) && $_GET["auditors"] === "updated"): ?>
                <div class="form-message success" data-i18n="auditAuditorsUpdatedMessage">Denetçi ataması güncellendi.</div>
            <?php endif; ?>

            <?php if (!$canAssignAuditors): ?>
                <div class="admin-list">
                    <?php if (!$assignedAuditors): ?>
                        <div class="empty-state" data-i18n="noAssignedAuditorsText">Bu denetime henüz denetçi atanmadı.</div>
                    <?php else: ?>
                        <?php foreach ($assignedAuditors as $assignedAuditor): ?>
                            <div class="admin-list-item">
                                <div>
                                    <strong><?= htmlspecialchars(trim($assignedAuditor["first_name"] . " " . $assignedAuditor["last_name"]), ENT_QUOTES, "UTF-8") ?></strong>
                                    <span><?= htmlspecialchars($assignedAuditor["email"] ?: "-", ENT_QUOTES, "UTF-8") ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            <?php elseif (!$companyAuditors): ?>
                <div class="empty-state" data-i18n="noCompanyAuditorsText">Bu şirkette kayıtlı denetçi yok. Önce Denetçiler bölümünden denetçi ekleyin.</div>
            <?php else: ?>
                <form class="auditor-form" method="post" action="audit-detail.php?id=<?= $auditId ?>">
                    <?= qmsCsrfField('audit_detail') ?>
                    <input type="hidden" name="form_type" value="update_auditors">
                    <div class="form-grid">
                        <label class="form-field form-field-wide">
                            <span data-i18n="auditAuditorsSelectLabel">Atanacak Denetçiler</span>
                            <select name="auditor_ids[]" multiple size="<?= min(6, max(2, count($companyAuditors))) ?>">
                                <?php foreach ($companyAuditors as $companyAuditor): ?>
                                    <option value="<?= (int) $companyAuditor["id"] ?>" <?= in_array((int) $companyAuditor["id"], $assignedAuditorIds, true) ? "selected" : "" ?>><?= htmlspecialchars(trim($companyAuditor["first_name"] . " " . $companyAuditor["last_name"]), ENT_QUOTES, "UTF-8") ?><?= $companyAuditor["email"] !== "" ? " · " . htmlspecialchars($companyAuditor["email"], ENT_QUOTES, "UTF-8") : "" ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small data-i18n="auditAuditorsSelectHelp">Birden fazla seçmek için Ctrl (Mac'te Cmd) tuşuna basılı tutun.</small>
                        </label>
                    </div>
                    <div class="form-actions">
                        <button class="primary-button" type="submit" data-i18n="saveAuditorsButton">Atamayı Kaydet</button>
                    </div>
                </form>
            <?php endif; ?>
        </section>

        <section class="console-card checklist-section">
            <div class="section-heading compact-heading">
                <div>
                    <h3 data-i18n="auditChecklistTitle">Denetim Kontrol Listesi</h3>
                    <p data-i18n="auditChecklistText">Her maddeyi değerlendirip sonucunu kaydedin.</p>
                </div>
            </div>

            <div class="admin-list">
                <?php if (count($checklistItems) === 0): ?>
                    <div class="empty-state" data-i18n="noChecklistItemsText">Henüz kontrol maddesi eklenmedi.</div>
                <?php endif; ?>

                <?php foreach ($checklistItems as $item): ?>
                    <form class="checklist-item-form" method="post" action="audit-detail.php?id=<?= $auditId ?>">
                        <?= qmsCsrfField('audit_detail') ?>
                        <input type="hidden" name="item_id" value="<?= (int) $item["id"] ?>">
                        <div class="checklist-item-heading">
                            <div>
                                <strong><?= htmlspecialchars($item["item_text"], ENT_QUOTES, "UTF-8") ?></strong>
                                <span><?= htmlspecialchars($item["requirement_ref"] ?: "-", ENT_QUOTES, "UTF-8") ?></span>
                            </div>
                            <span class="status-pill"><?= htmlspecialchars($resultLabels[$item["result_status"]] ?? $item["result_status"], ENT_QUOTES, "UTF-8") ?></span>
                        </div>
                        <div class="form-grid checklist-edit-grid">
                            <label class="form-field">
                                <span data-i18n="resultStatusLabel">Sonuç</span>
                                <select name="result_status">
                                    <option value="pending" <?= $item["result_status"] === "pending" ? "selected" : "" ?> data-i18n="resultPendingLabel">Bekliyor</option>
                                    <option value="compliant" <?= $item["result_status"] === "compliant" ? "selected" : "" ?> data-i18n="resultCompliantLabel">Uygun</option>
                                    <option value="noncompliant" <?= $item["result_status"] === "noncompliant" ? "selected" : "" ?> data-i18n="resultNoncompliantLabel">Uygunsuz</option>
                                    <option value="not_applicable" <?= $item["result_status"] === "not_applicable" ? "selected" : "" ?> data-i18n="resultNotApplicableLabel">Uygulanamaz</option>
                                </select>
                            </label>
                            <label class="form-field form-field-wide">
                                <span data-i18n="notesLabel">Notlar</span>
                                <input type="text" name="notes" value="<?= htmlspecialchars($item["notes"] ?? "", ENT_QUOTES, "UTF-8") ?>">
                            </label>
                        </div>
                        <div class="form-actions">
                            <button class="primary-button" type="submit" name="form_type" value="update_checklist_item" data-i18n="saveChecklistResultButton">Sonucu Kaydet</button>
                            <?php if ($item["result_status"] === "noncompliant" && !isset($nonconformityItemIds[(int) $item["id"]])): ?>
                                <button class="secondary-button" type="submit" name="form_type" value="create_nonconformity" data-i18n="createNonconformityButton">Uygunsuzluğa Dönüştür</button>
                            <?php endif; ?>
                            <?php if (isset($nonconformityItemIds[(int) $item["id"]])): ?>
                                <span class="linked-record-label" data-i18n="nonconformityLinkedLabel">Uygunsuzluk kaydı bağlı</span>
                            <?php endif; ?>
                        </div>
                    </form>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="console-card checklist-section">
            <div class="section-heading compact-heading">
                <div>
                    <h3 data-i18n="auditNonconformitiesTitle">Denetim Uygunsuzlukları</h3>
                    <p data-i18n="auditNonconformitiesText">Bu denetimde açılan uygunsuzluk kayıtları.</p>
                </div>
            </div>

            <div class="admin-list">
                <?php if (count($nonconformities) === 0): ?>
                    <div class="empty-state" data-i18n="noAuditNonconformitiesText">Henüz uygunsuzluk kaydı yok.</div>
                <?php endif; ?>

                <?php foreach ($nonconformities as $nonconformity): ?>
                    <a class="admin-list-item" href="nonconformity-detail.php?id=<?= (int) $nonconformity["id"] ?>">
                        <div>
                            <strong><?= htmlspecialchars($nonconformity["title"], ENT_QUOTES, "UTF-8") ?></strong>
                            <span><?= htmlspecialchars($nonconformity["severity"], ENT_QUOTES, "UTF-8") ?></span>
                        </div>
                        <span class="status-pill"><?= htmlspecialchars($nonconformity["status"], ENT_QUOTES, "UTF-8") ?></span>
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
