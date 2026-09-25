<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/capa-functions.php';
require_once __DIR__ . '/includes/audit-log-functions.php';

$actionId = (int) ($_GET["id"] ?? 0);
$userId = (int) ($_SESSION["qms_user_id"] ?? 0);

// Kapsamli okuma: id degistirilerek baska sirketin faaliyeti acilamaz.
$action = qmsCorrectiveActionFind($pdo, $actionId, $userId, qmsCurrentRole());

if (!$action) {
    header("Location: dashboard.php");
    exit;
}

$_SESSION["corrective_action_csrf"] ??= bin2hex(random_bytes(32));
$csrfToken = $_SESSION["corrective_action_csrf"];

$formError = "";
$allowedStatuses = QMS_CAPA_STATUSES;
$allowedEvidenceFiles = [
    "pdf" => ["application/pdf"],
    "doc" => ["application/msword", "application/octet-stream"],
    "docx" => ["application/vnd.openxmlformats-officedocument.wordprocessingml.document", "application/zip", "application/octet-stream"],
    "xls" => ["application/vnd.ms-excel", "application/octet-stream"],
    "xlsx" => ["application/vnd.openxmlformats-officedocument.spreadsheetml.sheet", "application/zip", "application/octet-stream"],
    "jpg" => ["image/jpeg"],
    "jpeg" => ["image/jpeg"],
    "png" => ["image/png"],
    "webp" => ["image/webp"]
];

require_once __DIR__ . '/includes/notifications.php';

// Sorumlu olabilecek kullanicilar: sirketin kullanicilari ve atanmis sistem adminleri.
$responsibleOptions = qmsCompanyResponsibleOptions($pdo, (int) $action["company_id"]);
$allowedResponsibleIds = array_map('intval', array_column($responsibleOptions, 'id'));

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $formType = $_POST["form_type"] ?? "update_action";

    if (!hash_equals($csrfToken, (string) ($_POST["csrf"] ?? ""))) {
        http_response_code(403);
        exit("Geçersiz istek.");
    }

    if ($formType === "upload_evidence") {
        $note = trim($_POST["note"] ?? "");
        $upload = $_FILES["evidence_file"] ?? null;

        if (!$upload || $upload["error"] !== UPLOAD_ERR_OK || $upload["size"] > 10 * 1024 * 1024) {
            $formError = "Kanıt dosyası yüklenemedi veya 10 MB sınırını aşıyor.";
        } else {
            $extension = strtolower(pathinfo($upload["name"], PATHINFO_EXTENSION));
            $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($upload["tmp_name"]);
            if (!isset($allowedEvidenceFiles[$extension]) || !in_array($mimeType, $allowedEvidenceFiles[$extension], true)) {
                $formError = "Bu dosya türü kanıt olarak yüklenemez. PDF, Word, Excel veya görsel kullanın.";
            } else {
                $storedName = bin2hex(random_bytes(20)) . "." . $extension;
                $storedPath = qmsCapaEvidencePath($storedName);
                try {
                    if (!move_uploaded_file($upload["tmp_name"], $storedPath)) {
                        throw new RuntimeException("Kanıt dosyası depoya taşınamadı.");
                    }
                    $evidenceInsert = $pdo->prepare(
                        "INSERT INTO corrective_action_evidence
                            (corrective_action_id, original_file_name, stored_file_name, mime_type, file_size, note, uploaded_by)
                         VALUES (:action_id, :original_file_name, :stored_file_name, :mime_type, :file_size, :note, :uploaded_by)"
                    );
                    $evidenceInsert->execute([
                        "action_id" => $actionId,
                        "original_file_name" => basename($upload["name"]),
                        "stored_file_name" => $storedName,
                        "mime_type" => $mimeType,
                        "file_size" => (int) $upload["size"],
                        "note" => $note !== "" ? $note : null,
                        "uploaded_by" => $userId ?: null
                    ]);
                    header("Location: corrective-action-detail.php?id=" . $actionId . "&evidence=uploaded");
                    exit;
                } catch (Throwable $error) {
                    if (is_file($storedPath)) {
                        unlink($storedPath);
                    }
                    $formError = "Kanıt dosyası kaydedilemedi.";
                }
            }
        }
    } elseif ($formType === "delete_evidence") {
        $evidenceId = (int) ($_POST["evidence_id"] ?? 0);
        // Kapsam kontrolu dahil: baska sirketin kaniti silinemez.
        $evidence = qmsCapaEvidenceFind($pdo, $evidenceId, $userId, qmsCurrentRole());

        if (!$evidence || (int) $evidence["corrective_action_id"] !== $actionId) {
            $formError = "Kanıt dosyası bulunamadı.";
        } else {
            $pdo->prepare("UPDATE corrective_action_evidence SET active = 0 WHERE id = :id")
                ->execute(["id" => $evidenceId]);
            $filePath = qmsCapaEvidencePath((string) $evidence["stored_file_name"]);
            if (is_file($filePath)) {
                unlink($filePath);
            }
            header("Location: corrective-action-detail.php?id=" . $actionId . "&evidence=deleted");
            exit;
        }
    } elseif ($formType === "update_action") {
    $formData = [
        "action_type" => (string) ($_POST["action_type"] ?? ($action["action_type"] ?? "corrective")),
        "action_text" => trim($_POST["action_text"] ?? ""),
        "responsible_person" => trim($_POST["responsible_person"] ?? ""),
        "responsible_user_id" => (int) ($_POST["responsible_user_id"] ?? 0),
        "due_date" => trim($_POST["due_date"] ?? ""),
        "status" => $_POST["status"] ?? "planned",
        "evidence_note" => trim($_POST["evidence_note"] ?? ""),
        "verifier_name" => trim($_POST["verifier_name"] ?? ""),
        "verification_note" => trim($_POST["verification_note"] ?? "")
    ];

    if ($formData["responsible_user_id"] > 0 && !in_array($formData["responsible_user_id"], $allowedResponsibleIds, true)) {
        $formData["responsible_user_id"] = 0;
    }
    if (!in_array($formData["action_type"], QMS_CAPA_TYPES, true)) {
        $formData["action_type"] = "corrective";
    }

    if ($formData["action_text"] === "") {
        $formError = "Lütfen düzeltici faaliyeti açıklayın.";
    } elseif (!in_array($formData["status"], $allowedStatuses, true)) {
        $formError = "Geçerli bir durum seçin.";
    } else {
        $timestamps = qmsCapaStatusTimestamps($action, $formData["status"]);
        $completedAt = $timestamps["completed_at"];
        $closedAt = $timestamps["closed_at"];

        $updateStmt = $pdo->prepare(
            "UPDATE corrective_actions
             SET action_type = :action_type,
                 action_text = :action_text,
                 responsible_person = :responsible_person,
                 responsible_user_id = :responsible_user_id,
                 due_date = :due_date,
                 status = :status,
                 evidence_note = :evidence_note,
                 verifier_name = :verifier_name,
                 verification_note = :verification_note,
                 completed_at = :completed_at,
                 closed_at = :closed_at
             WHERE id = :id"
        );
        $updateStmt->execute([
            "action_type" => $formData["action_type"],
            "action_text" => $formData["action_text"],
            "responsible_person" => $formData["responsible_person"] !== "" ? $formData["responsible_person"] : null,
            "responsible_user_id" => $formData["responsible_user_id"] > 0 ? $formData["responsible_user_id"] : null,
            "due_date" => $formData["due_date"] !== "" ? $formData["due_date"] : null,
            "status" => $formData["status"],
            "evidence_note" => $formData["evidence_note"] !== "" ? $formData["evidence_note"] : null,
            "verifier_name" => $formData["verifier_name"] !== "" ? $formData["verifier_name"] : null,
            "verification_note" => $formData["verification_note"] !== "" ? $formData["verification_note"] : null,
            "completed_at" => $completedAt,
            "closed_at" => $closedAt,
            "id" => $actionId
        ]);

        // Bildirim kurallari tek yerde: includes/capa-functions.php.
        qmsCapaNotifyStatusChange($pdo, [
            "company_id" => (int) $action["company_id"],
            "action_text" => $formData["action_text"],
            "link" => "corrective-action-detail.php?id=" . $actionId,
            "previous_status" => (string) $action["status"],
            "new_status" => $formData["status"],
            "previous_responsible_user_id" => (int) ($action["responsible_user_id"] ?? 0),
            "responsible_user_id" => (int) $formData["responsible_user_id"],
            "actor_user_id" => $userId
        ]);

        qmsAuditLog(
            $pdo,
            (int) ($action["company_id"] ?? 0),
            $userId,
            'corrective_action',
            $actionId,
            $formData["status"] !== $action["status"] ? 'status_change' : 'update',
            'Düzeltici faaliyet güncellendi: ' . $formData["action_text"] . " (durum: " . $formData["status"] . ")"
        );

        header("Location: corrective-action-detail.php?id=" . $actionId . "&updated=1");
        exit;
    }

    $action = array_merge($action, $formData);
    }
}

$statusLabels = qmsCapaStatusLabels();
$capaTypeLabels = qmsCapaTypeLabels();
$statusI18n = qmsCapaStatusI18nKeys();
$evidenceFiles = qmsCapaEvidenceList($pdo, $actionId);

$activeNav = "companies";

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f9fafb">
    <title>QMS Düzeltici Faaliyet Detayı</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar">
        <div class="topbar-inner">
            <div class="page-title-block">
                <strong data-i18n="correctiveActionDetailTitle">Düzeltici Faaliyet Detayı</strong>
                <span><?= htmlspecialchars($action["company_name"], ENT_QUOTES, "UTF-8") ?> · <?= htmlspecialchars($action["nonconformity_title"], ENT_QUOTES, "UTF-8") ?></span>
            </div>
            <div class="topbar-actions">
                <button class="topbar-button" id="languageToggle" type="button">EN</button>
                <button class="topbar-button" id="themeToggle" type="button" aria-label="Tema değiştir">🌙</button>
            </div>
        </div>
    </header>
    <main class="page-container narrow-page">
        <section class="page-heading page-heading-actions">
            <div>
                <span class="section-kicker" data-i18n="correctiveActionsKicker">İyileştirme Takibi</span>
                <h1 data-i18n="correctiveActionDetailTitle">Düzeltici Faaliyet Detayı</h1>
                <p><?= htmlspecialchars($action["action_text"], ENT_QUOTES, "UTF-8") ?></p>
            </div>
            <a class="secondary-button" href="nonconformity-detail.php?id=<?= (int) $action["nonconformity_id"] ?>" data-i18n="backToNonconformityButton">Uygunsuzluğa Dön</a>
        </section>
        <section class="dashboard-grid compact-dashboard-grid">
            <div class="dashboard-card">
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="actionStatusLabel">Durum</span>
                    <strong class="dashboard-card-number detail-card-value"><?= htmlspecialchars($statusLabels[$action["status"]] ?? $action["status"], ENT_QUOTES, "UTF-8") ?></strong>
                </div>
            </div>
            <div class="dashboard-card">
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="dueDateLabel">Termin Tarihi</span>
                    <strong class="dashboard-card-number detail-card-value"><?= htmlspecialchars($action["due_date"] ?: "-", ENT_QUOTES, "UTF-8") ?></strong>
                </div>
            </div>
            <div class="dashboard-card">
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="actionTypeLabel">Faaliyet Türü</span>
                    <strong class="dashboard-card-number detail-card-value"><?= htmlspecialchars($capaTypeLabels[$action["action_type"] ?? "corrective"] ?? ($action["action_type"] ?? "corrective"), ENT_QUOTES, "UTF-8") ?></strong>
                </div>
            </div>
        </section>
        <section class="form-panel">
            <?php if (isset($_GET["updated"]) && $_GET["updated"] === "1"): ?>
                <div class="form-message success" data-i18n="correctiveActionUpdatedMessage">Düzeltici faaliyet güncellendi.</div>
            <?php endif; ?>
            <?php if (($_GET["evidence"] ?? "") === "uploaded"): ?>
                <div class="form-message success" data-i18n="evidenceUploadedMessage">Kanıt dosyası yüklendi.</div>
            <?php elseif (($_GET["evidence"] ?? "") === "deleted"): ?>
                <div class="form-message success" data-i18n="evidenceDeletedMessage">Kanıt dosyası silindi.</div>
            <?php endif; ?>
            <?php if ($formError !== ""): ?>
                <div class="form-message error"><?= htmlspecialchars($formError, ENT_QUOTES, "UTF-8") ?></div>
            <?php endif; ?>
            <form class="auditor-form" method="post" action="corrective-action-detail.php?id=<?= $actionId ?>">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, "UTF-8") ?>">
                <input type="hidden" name="form_type" value="update_action">
                <div class="form-grid">
                    <label class="form-field">
                        <span data-i18n="actionTypeLabel">Faaliyet Türü</span>
                        <select name="action_type">
                            <?php foreach ($capaTypeLabels as $typeKey => $typeLabel): ?>
                                <option value="<?= $typeKey ?>" <?= ($action["action_type"] ?? "corrective") === $typeKey ? "selected" : "" ?>><?= htmlspecialchars($typeLabel, ENT_QUOTES, "UTF-8") ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="form-field form-field-wide">
                        <span data-i18n="actionTextLabel">Faaliyet Açıklaması</span>
                        <textarea name="action_text" rows="4" required><?= htmlspecialchars($action["action_text"], ENT_QUOTES, "UTF-8") ?></textarea>
                    </label>
                    <label class="form-field">
                        <span data-i18n="responsiblePersonLabel">Sorumlu Kişi</span>
                        <input type="text" name="responsible_person" value="<?= htmlspecialchars($action["responsible_person"] ?? "", ENT_QUOTES, "UTF-8") ?>">
                    </label>
                    <label class="form-field form-field-wide">
                        <span data-i18n="responsibleUserLabel">Sorumlu Kullanıcı (isteğe bağlı)</span>
                        <select name="responsible_user_id">
                            <option value="0" data-i18n="responsibleUserNoneOption">— seçilmedi (bildirim gönderilmez)</option>
                            <?php foreach ($responsibleOptions as $responsibleOption): ?>
                                <option value="<?= (int) $responsibleOption["id"] ?>" <?= (int) ($action["responsible_user_id"] ?? 0) === (int) $responsibleOption["id"] ? "selected" : "" ?>><?= htmlspecialchars($responsibleOption["full_name"], ENT_QUOTES, "UTF-8") ?> · <?= htmlspecialchars(appRoleLabel((string) $responsibleOption["role"]), ENT_QUOTES, "UTF-8") ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small data-i18n="responsibleUserHelp">Seçilirse faaliyet atandığında bu kullanıcıya bildirim gider.</small>
                    </label>
                    <label class="form-field">
                        <span data-i18n="dueDateLabel">Termin Tarihi</span>
                        <input type="date" name="due_date" value="<?= htmlspecialchars($action["due_date"] ?? "", ENT_QUOTES, "UTF-8") ?>">
                    </label>
                    <label class="form-field form-field-wide">
                        <span data-i18n="actionStatusLabel">Durum</span>
                        <select name="status">
                            <?php foreach ($statusLabels as $value => $label): ?>
                                <option value="<?= $value ?>" <?= $action["status"] === $value ? "selected" : "" ?> data-i18n="<?= $statusI18n[$value] ?>"><?= htmlspecialchars($label, ENT_QUOTES, "UTF-8") ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="form-field form-field-wide">
                        <span data-i18n="actionEvidenceLabel">Uygulama Kanıtı / Notu</span>
                        <textarea name="evidence_note" rows="4"><?= htmlspecialchars($action["evidence_note"] ?? "", ENT_QUOTES, "UTF-8") ?></textarea>
                    </label>
                    <label class="form-field">
                        <span data-i18n="verifierNameLabel">Doğrulayan Kişi</span>
                        <input type="text" name="verifier_name" value="<?= htmlspecialchars($action["verifier_name"] ?? "", ENT_QUOTES, "UTF-8") ?>">
                    </label>
                    <label class="form-field form-field-wide">
                        <span data-i18n="verificationNoteLabel">Doğrulama Notu</span>
                        <textarea name="verification_note" rows="4"><?= htmlspecialchars($action["verification_note"] ?? "", ENT_QUOTES, "UTF-8") ?></textarea>
                    </label>
                </div>
                <div class="form-actions">
                    <button class="primary-button" type="submit" data-i18n="saveCorrectiveActionButton">Faaliyeti Kaydet</button>
                </div>
            </form>
        </section>
        <section class="page-section">
            <div class="section-heading"><div><h2 data-i18n="evidenceSectionTitle">Kanıt Dosyaları</h2><p data-i18n="evidenceSectionText">Faaliyetin tamamlandığını gösteren dosyaları indirin.</p></div></div>
            <?php if (!$evidenceFiles): ?>
                <div class="empty-state" data-i18n="noEvidenceText">Henüz kanıt dosyası yüklenmedi.</div>
            <?php else: ?>
                <div class="revision-list">
                    <?php foreach ($evidenceFiles as $evidence): ?>
                        <div class="revision-item">
                            <div>
                                <strong><?= htmlspecialchars($evidence["original_file_name"], ENT_QUOTES, "UTF-8") ?></strong>
                                <span><?= htmlspecialchars($evidence["note"] ?: "-", ENT_QUOTES, "UTF-8") ?> · <?= number_format(((int) $evidence["file_size"]) / 1024, 1) ?> KB · <?= htmlspecialchars((string) ($evidence["uploaded_by_name"] ?? "-"), ENT_QUOTES, "UTF-8") ?> · <?= htmlspecialchars((string) $evidence["created_at"], ENT_QUOTES, "UTF-8") ?></span>
                            </div>
                            <div class="workflow-buttons">
                                <a class="secondary-button" href="corrective-action-evidence-download.php?id=<?= (int) $evidence["id"] ?>" data-i18n="downloadFileButton">İndir</a>
                                <form method="post" action="corrective-action-detail.php?id=<?= $actionId ?>">
                                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, "UTF-8") ?>">
                                    <input type="hidden" name="form_type" value="delete_evidence">
                                    <input type="hidden" name="evidence_id" value="<?= (int) $evidence["id"] ?>">
                                    <button class="danger-button" type="submit" data-i18n="deleteEvidenceButton">Sil</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
        <section class="page-section form-panel">
            <div class="section-heading compact-heading"><div><h3 data-i18n="evidenceUploadTitle">Yeni Kanıt Ekle</h3><p data-i18n="evidenceUploadText">PDF, Word, Excel veya görsel yükleyebilirsiniz; dosya başına sınır 10 MB.</p></div></div>
            <form class="auditor-form" method="post" action="corrective-action-detail.php?id=<?= $actionId ?>" enctype="multipart/form-data">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, "UTF-8") ?>">
                <input type="hidden" name="form_type" value="upload_evidence">
                <div class="form-grid">
                    <label class="form-field">
                        <span data-i18n="evidenceFileLabel">Kanıt Dosyası</span>
                        <input type="file" name="evidence_file" required>
                    </label>
                    <label class="form-field">
                        <span data-i18n="evidenceNoteLabel">Açıklama (isteğe bağlı)</span>
                        <input type="text" name="note" maxlength="255">
                    </label>
                </div>
                <div class="form-actions">
                    <button class="primary-button" type="submit" data-i18n="uploadEvidenceButton">Kanıt Yükle</button>
                </div>
            </form>
        </section>
    </main>
    <script src="assets/js/theme.js"></script>
    <script src="assets/js/language.js"></script>
    <script src="assets/js/sidebar.js"></script>
    <script src="assets/js/pwa.js"></script>
</body>
</html>
