<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/complaint-functions.php';
require_once __DIR__ . '/includes/audit-log-functions.php';

$complaintId = (int) ($_GET["id"] ?? $_POST["complaint_id"] ?? 0);
$userId = (int) ($_SESSION["qms_user_id"] ?? 0);
$role = qmsCurrentRole();
$csrfScope = 'complaint_detail';

// Kapsamli okuma: id degistirilerek baska sirketin sikayeti acilamaz.
$complaint = qmsComplaintFind($pdo, $complaintId, $userId, $role);

if (!$complaint) {
    header("Location: complaints.php");
    exit;
}

$companyId = (int) $complaint["company_id"];
$statusLabels = qmsComplaintStatusLabels();
$statusI18n = qmsComplaintStatusI18nKeys();
$severityLabels = qmsComplaintSeverityLabels();
$severityI18n = qmsComplaintSeverityI18nKeys();
$sourceLabels = qmsComplaintSourceLabels();
$sourceI18n = qmsComplaintSourceI18nKeys();
$channelLabels = qmsComplaintChannelLabels();
$channelI18n = qmsComplaintChannelI18nKeys();

// Sorumlu olabilecek kullanicilar ve baglanabilecek uygunsuzluklar sirkete baglidir.
$responsibleOptions = qmsCompanyResponsibleOptions($pdo, $companyId);
$allowedResponsibleIds = array_map('intval', array_column($responsibleOptions, 'id'));
$nonconformityOptions = qmsComplaintNonconformityOptions($pdo, $companyId);
$allowedNonconformityIds = array_map('intval', array_column($nonconformityOptions, 'id'));

$formError = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    qmsCsrfVerify($csrfScope, $_POST["csrf"] ?? null);

    $formType = (string) ($_POST["form_type"] ?? "");
    $redirect = "complaint-detail.php?id=" . $complaintId;

    if ($formType !== "update_complaint") {
        $formError = "Geçersiz istek.";
    } else {
        $formData = [
            "complaint_code" => qmsComplaintText($_POST["complaint_code"] ?? "", 60),
            "subject" => qmsComplaintText($_POST["subject"] ?? "", 255),
            "received_date" => trim((string) ($_POST["received_date"] ?? "")),
            "source" => (string) ($_POST["source"] ?? "customer"),
            "channel" => (string) ($_POST["channel"] ?? ""),
            "customer_name" => qmsComplaintText($_POST["customer_name"] ?? "", 180),
            "customer_contact" => qmsComplaintText($_POST["customer_contact"] ?? "", 180),
            "severity" => (string) ($_POST["severity"] ?? "major"),
            "status" => (string) ($_POST["status"] ?? "new"),
            "responsible_user_id" => (int) ($_POST["responsible_user_id"] ?? 0),
            "responsible_person" => qmsComplaintText($_POST["responsible_person"] ?? "", 150),
            "due_date" => trim((string) ($_POST["due_date"] ?? "")),
            "nonconformity_id" => (int) ($_POST["nonconformity_id"] ?? 0),
            "description" => qmsComplaintText($_POST["description"] ?? "", 4000),
            "root_cause" => qmsComplaintText($_POST["root_cause"] ?? "", 4000),
            "action_note" => qmsComplaintText($_POST["action_note"] ?? "", 4000),
            "resolution_note" => qmsComplaintText($_POST["resolution_note"] ?? "", 4000)
        ];

        $receivedDate = qmsComplaintDate($formData["received_date"]);
        $dueDate = qmsComplaintDate($formData["due_date"]);

        // Yalnizca bu sirkette sorumlu olabilecek kullanicilar kabul edilir.
        if ($formData["responsible_user_id"] > 0 && !in_array($formData["responsible_user_id"], $allowedResponsibleIds, true)) {
            $formData["responsible_user_id"] = 0;
        }
        // Uygunsuzluk baglantisi yalnizca ayni sirketin kaydi olabilir.
        if ($formData["nonconformity_id"] > 0 && !in_array($formData["nonconformity_id"], $allowedNonconformityIds, true)) {
            $formError = "Seçilen uygunsuzluk bu şirkete ait değil.";
        }

        if ($formError === "" && $formData["subject"] === "") {
            $formError = "Lütfen şikayet konusunu girin.";
        } elseif ($formError === "" && $receivedDate === null) {
            $formError = "Geçerli bir alınma tarihi girin.";
        } elseif ($formError === "" && $formData["due_date"] !== "" && $dueDate === null) {
            $formError = "Termin tarihi geçerli değil.";
        } elseif ($formError === "" && !in_array($formData["status"], QMS_COMPLAINT_STATUSES, true)) {
            $formError = "Geçerli bir durum seçin.";
        } elseif ($formError === "" && !in_array($formData["severity"], QMS_COMPLAINT_SEVERITIES, true)) {
            $formError = "Geçerli bir önem derecesi seçin.";
        } elseif ($formError === "" && !in_array($formData["source"], QMS_COMPLAINT_SOURCES, true)) {
            $formError = "Geçerli bir kaynak seçin.";
        } elseif ($formError === "" && $formData["channel"] !== "" && !in_array($formData["channel"], QMS_COMPLAINT_CHANNELS, true)) {
            $formError = "Geçerli bir kanal seçin.";
        } else {
            $duplicateCode = false;
            if ($formError === "" && $formData["complaint_code"] !== "") {
                $duplicateStmt = $pdo->prepare(
                    "SELECT COUNT(*) FROM complaints
                     WHERE company_id = :company_id AND complaint_code = :complaint_code
                       AND id <> :id AND active = 1"
                );
                $duplicateStmt->execute([
                    "company_id" => $companyId,
                    "complaint_code" => $formData["complaint_code"],
                    "id" => $complaintId
                ]);
                $duplicateCode = (int) $duplicateStmt->fetchColumn() > 0;
            }

            if ($formError === "" && $duplicateCode) {
                $formError = "Bu şikayet numarası şirkette zaten kayıtlı.";
            } elseif ($formError === "") {
                $closedDate = qmsComplaintClosedDate($complaint, $formData["status"]);

                $updateStmt = $pdo->prepare(
                    "UPDATE complaints
                     SET complaint_code = :complaint_code,
                         subject = :subject,
                         received_date = :received_date,
                         source = :source,
                         channel = :channel,
                         customer_name = :customer_name,
                         customer_contact = :customer_contact,
                         severity = :severity,
                         status = :status,
                         responsible_user_id = :responsible_user_id,
                         responsible_person = :responsible_person,
                         due_date = :due_date,
                         nonconformity_id = :nonconformity_id,
                         description = :description,
                         root_cause = :root_cause,
                         action_note = :action_note,
                         resolution_note = :resolution_note,
                         closed_date = :closed_date,
                         updated_by = :updated_by
                     WHERE id = :id"
                );
                $updateStmt->execute([
                    "complaint_code" => $formData["complaint_code"] !== "" ? $formData["complaint_code"] : null,
                    "subject" => $formData["subject"],
                    "received_date" => $receivedDate,
                    "source" => $formData["source"],
                    "channel" => $formData["channel"] !== "" ? $formData["channel"] : null,
                    "customer_name" => $formData["customer_name"] !== "" ? $formData["customer_name"] : null,
                    "customer_contact" => $formData["customer_contact"] !== "" ? $formData["customer_contact"] : null,
                    "severity" => $formData["severity"],
                    "status" => $formData["status"],
                    "responsible_user_id" => $formData["responsible_user_id"] > 0 ? $formData["responsible_user_id"] : null,
                    "responsible_person" => $formData["responsible_person"] !== "" ? $formData["responsible_person"] : null,
                    "due_date" => $dueDate,
                    "nonconformity_id" => $formData["nonconformity_id"] > 0 ? $formData["nonconformity_id"] : null,
                    "description" => $formData["description"] !== "" ? $formData["description"] : null,
                    "root_cause" => $formData["root_cause"] !== "" ? $formData["root_cause"] : null,
                    "action_note" => $formData["action_note"] !== "" ? $formData["action_note"] : null,
                    "resolution_note" => $formData["resolution_note"] !== "" ? $formData["resolution_note"] : null,
                    "closed_date" => $closedDate,
                    "updated_by" => $userId ?: null,
                    "id" => $complaintId
                ]);

                // Bildirim kurallari tek yerde: includes/complaint-functions.php.
                qmsComplaintNotify($pdo, [
                    "company_id" => $companyId,
                    "subject" => $formData["subject"],
                    "link" => $redirect,
                    "previous_status" => (string) $complaint["status"],
                    "new_status" => $formData["status"],
                    "previous_severity" => (string) $complaint["severity"],
                    "severity" => $formData["severity"],
                    "previous_responsible_user_id" => (int) ($complaint["responsible_user_id"] ?? 0),
                    "responsible_user_id" => $formData["responsible_user_id"],
                    "actor_user_id" => $userId
                ]);

                qmsAuditLog(
                    $pdo,
                    $companyId,
                    $userId,
                    'complaint',
                    $complaintId,
                    $formData["status"] !== $complaint["status"] ? 'status_change' : 'update',
                    'Şikayet güncellendi: ' . $formData["subject"] . ' (durum: ' . $statusLabels[$formData["status"]] . ')'
                );

                header("Location: " . $redirect . "&updated=1");
                exit;
            }
        }

        // Hata halinde formu kullanicinin girdigi degerlerle yeniden ciz.
        $complaint = array_merge($complaint, $formData);
    }
}

$linkedActions = 0;
if ((int) $complaint["nonconformity_id"] > 0) {
    $linkedActionsStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM corrective_actions
         WHERE nonconformity_id = :nonconformity_id AND active = 1"
    );
    $linkedActionsStmt->execute(["nonconformity_id" => (int) $complaint["nonconformity_id"]]);
    $linkedActions = (int) $linkedActionsStmt->fetchColumn();
}

$activeNav = "complaints";

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f9fafb">
    <title>QMS Şikayet Detayı</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar">
        <div class="topbar-inner">
            <div class="page-title-block">
                <strong data-i18n="complaintDetailTitle">Şikayet Detayı</strong>
                <span><?= htmlspecialchars($complaint["company_name"], ENT_QUOTES, "UTF-8") ?> · <?= htmlspecialchars($complaint["subject"], ENT_QUOTES, "UTF-8") ?></span>
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
                <span class="section-kicker" data-i18n="complaintWorkspaceKicker">Şikayet Çalışma Alanı</span>
                <h1 data-i18n="complaintDetailTitle">Şikayet Detayı</h1>
                <p><?= htmlspecialchars($complaint["complaint_code"] ?: "-", ENT_QUOTES, "UTF-8") ?> · <?= htmlspecialchars($complaint["subject"], ENT_QUOTES, "UTF-8") ?></p>
            </div>
            <a class="secondary-button" href="complaints.php" data-i18n="backToComplaintsButton">Şikayetlere Dön</a>
        </section>

        <section class="dashboard-grid compact-dashboard-grid">
            <div class="dashboard-card metric-blue">
                <?= appIcon("complaints", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="complaintStatusLabel">Durum</span>
                    <strong class="dashboard-card-number detail-card-value" data-i18n="<?= $statusI18n[$complaint["status"]] ?? "" ?>"><?= htmlspecialchars($statusLabels[$complaint["status"]] ?? $complaint["status"], ENT_QUOTES, "UTF-8") ?></strong>
                </div>
            </div>
            <div class="dashboard-card metric-red">
                <?= appIcon("warning", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="complaintSeverityLabel">Önem</span>
                    <strong class="dashboard-card-number detail-card-value" data-i18n="<?= $severityI18n[$complaint["severity"]] ?? "" ?>"><?= htmlspecialchars($severityLabels[$complaint["severity"]] ?? $complaint["severity"], ENT_QUOTES, "UTF-8") ?></strong>
                </div>
            </div>
            <div class="dashboard-card metric-violet">
                <?= appIcon("clock", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="receivedDateLabel">Alınma Tarihi</span>
                    <strong class="dashboard-card-number detail-card-value"><?= htmlspecialchars($complaint["received_date"], ENT_QUOTES, "UTF-8") ?></strong>
                </div>
            </div>
            <div class="dashboard-card metric-teal">
                <?= appIcon("check", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="complaintClosedDateLabel">Kapanış Tarihi</span>
                    <strong class="dashboard-card-number detail-card-value"><?= htmlspecialchars((string) ($complaint["closed_date"] ?: "-"), ENT_QUOTES, "UTF-8") ?></strong>
                </div>
            </div>
        </section>

        <?php if ((int) $complaint["nonconformity_id"] > 0): ?>
            <section class="form-panel">
                <div class="section-heading compact-heading">
                    <div>
                        <h3 data-i18n="complaintLinkedRecordTitle">İlişkili Uygunsuzluk</h3>
                        <p data-i18n="complaintLinkedRecordText">Şikayet bu uygunsuzluğa bağlıdır; düzeltici faaliyetler uygunsuzluk üzerinden izlenir.</p>
                    </div>
                </div>
                <div class="revision-list">
                    <div class="revision-item">
                        <div>
                            <strong><?= htmlspecialchars((string) ($complaint["nonconformity_title"] ?? "-"), ENT_QUOTES, "UTF-8") ?></strong>
                            <span><?= $linkedActions ?> <span data-i18n="complaintLinkedActionsLabel">düzeltici faaliyet</span></span>
                        </div>
                        <div class="workflow-buttons">
                            <a class="secondary-button" href="nonconformity-detail.php?id=<?= (int) $complaint["nonconformity_id"] ?>" data-i18n="openNonconformityButton">Uygunsuzluğa Git</a>
                            <a class="secondary-button" href="actions.php" data-i18n="openActionsButton">Aksiyonlara Git</a>
                        </div>
                    </div>
                </div>
            </section>
        <?php endif; ?>

        <section class="form-panel">
            <?php if (($_GET["created"] ?? "") === "1"): ?>
                <div class="form-message success" data-i18n="complaintCreatedMessage">Şikayet kaydı oluşturuldu.</div>
            <?php endif; ?>
            <?php if (($_GET["updated"] ?? "") === "1"): ?>
                <div class="form-message success" data-i18n="complaintUpdatedMessage">Şikayet kaydı güncellendi.</div>
            <?php endif; ?>
            <?php if ($formError !== ""): ?>
                <div class="form-message error"><?= htmlspecialchars($formError, ENT_QUOTES, "UTF-8") ?></div>
            <?php endif; ?>

            <form class="auditor-form" method="post" action="complaint-detail.php?id=<?= $complaintId ?>">
                <?= qmsCsrfField($csrfScope) ?>
                <input type="hidden" name="form_type" value="update_complaint">
                <input type="hidden" name="complaint_id" value="<?= $complaintId ?>">
                <div class="form-grid">
                    <label class="form-field">
                        <span data-i18n="companySelectLabel">Şirket</span>
                        <input type="text" value="<?= htmlspecialchars($complaint["company_name"], ENT_QUOTES, "UTF-8") ?>" readonly>
                    </label>
                    <label class="form-field">
                        <span data-i18n="complaintSubjectLabel">Şikayet Konusu</span>
                        <input type="text" name="subject" maxlength="255" value="<?= htmlspecialchars($complaint["subject"], ENT_QUOTES, "UTF-8") ?>" required>
                    </label>
                    <label class="form-field">
                        <span data-i18n="complaintCodeLabel">Şikayet Numarası</span>
                        <input type="text" name="complaint_code" maxlength="60" value="<?= htmlspecialchars((string) ($complaint["complaint_code"] ?? ""), ENT_QUOTES, "UTF-8") ?>">
                    </label>
                    <label class="form-field">
                        <span data-i18n="receivedDateLabel">Alınma Tarihi</span>
                        <input type="date" name="received_date" value="<?= htmlspecialchars($complaint["received_date"], ENT_QUOTES, "UTF-8") ?>" required>
                    </label>
                    <label class="form-field">
                        <span data-i18n="complaintSourceLabel">Kaynak</span>
                        <select name="source">
                            <?php foreach ($sourceLabels as $value => $label): ?>
                                <option value="<?= $value ?>" <?= $complaint["source"] === $value ? "selected" : "" ?> data-i18n="<?= $sourceI18n[$value] ?>"><?= htmlspecialchars($label, ENT_QUOTES, "UTF-8") ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="form-field">
                        <span data-i18n="complaintChannelLabel">Bildirim Kanalı</span>
                        <select name="channel">
                            <option value="" data-i18n="complaintChannelNoneOption">— belirtilmedi</option>
                            <?php foreach ($channelLabels as $value => $label): ?>
                                <option value="<?= $value ?>" <?= $complaint["channel"] === $value ? "selected" : "" ?> data-i18n="<?= $channelI18n[$value] ?>"><?= htmlspecialchars($label, ENT_QUOTES, "UTF-8") ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="form-field">
                        <span data-i18n="complaintCustomerLabel">Şikayet Eden</span>
                        <input type="text" name="customer_name" maxlength="180" value="<?= htmlspecialchars((string) ($complaint["customer_name"] ?? ""), ENT_QUOTES, "UTF-8") ?>">
                    </label>
                    <label class="form-field">
                        <span data-i18n="complaintContactLabel">İletişim Bilgisi</span>
                        <input type="text" name="customer_contact" maxlength="180" value="<?= htmlspecialchars((string) ($complaint["customer_contact"] ?? ""), ENT_QUOTES, "UTF-8") ?>">
                    </label>
                    <label class="form-field">
                        <span data-i18n="complaintSeverityLabel">Önem</span>
                        <select name="severity">
                            <?php foreach ($severityLabels as $value => $label): ?>
                                <option value="<?= $value ?>" <?= $complaint["severity"] === $value ? "selected" : "" ?> data-i18n="<?= $severityI18n[$value] ?>"><?= htmlspecialchars($label, ENT_QUOTES, "UTF-8") ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="form-field">
                        <span data-i18n="complaintStatusLabel">Durum</span>
                        <select name="status">
                            <?php foreach ($statusLabels as $value => $label): ?>
                                <option value="<?= $value ?>" <?= $complaint["status"] === $value ? "selected" : "" ?> data-i18n="<?= $statusI18n[$value] ?>"><?= htmlspecialchars($label, ENT_QUOTES, "UTF-8") ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="form-field">
                        <span data-i18n="responsibleUserLabel">Sorumlu Kullanıcı (isteğe bağlı)</span>
                        <select name="responsible_user_id">
                            <option value="0" data-i18n="responsibleUserNoneOption">— seçilmedi (bildirim gönderilmez)</option>
                            <?php foreach ($responsibleOptions as $responsibleOption): ?>
                                <option value="<?= (int) $responsibleOption["id"] ?>" <?= (int) ($complaint["responsible_user_id"] ?? 0) === (int) $responsibleOption["id"] ? "selected" : "" ?>><?= htmlspecialchars($responsibleOption["full_name"], ENT_QUOTES, "UTF-8") ?> · <?= htmlspecialchars(appRoleLabel((string) $responsibleOption["role"]), ENT_QUOTES, "UTF-8") ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small data-i18n="complaintResponsibleHelp">Seçilirse atama ve kapanış bildirimi bu kullanıcıya gider.</small>
                    </label>
                    <label class="form-field">
                        <span data-i18n="complaintResponsiblePersonLabel">Sorumlu (serbest metin)</span>
                        <input type="text" name="responsible_person" maxlength="150" value="<?= htmlspecialchars((string) ($complaint["responsible_person"] ?? ""), ENT_QUOTES, "UTF-8") ?>">
                    </label>
                    <label class="form-field">
                        <span data-i18n="dueDateLabel">Termin Tarihi</span>
                        <input type="date" name="due_date" value="<?= htmlspecialchars((string) ($complaint["due_date"] ?? ""), ENT_QUOTES, "UTF-8") ?>">
                    </label>
                    <label class="form-field">
                        <span data-i18n="complaintLinkedNonconformityLabel">Uygunsuzluk Bağlantısı</span>
                        <select name="nonconformity_id">
                            <option value="0" data-i18n="complaintNoLinkOption">— bağlantı yok</option>
                            <?php foreach ($nonconformityOptions as $option): ?>
                                <option value="<?= (int) $option["id"] ?>" <?= (int) ($complaint["nonconformity_id"] ?? 0) === (int) $option["id"] ? "selected" : "" ?>><?= htmlspecialchars($option["title"], ENT_QUOTES, "UTF-8") ?><?= $option["audit_title"] ? " · " . htmlspecialchars($option["audit_title"], ENT_QUOTES, "UTF-8") : "" ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (!$nonconformityOptions): ?>
                            <small data-i18n="complaintNoNonconformityHelp">Bu şirkette uygunsuzluk kaydı yok; bağlamak için önce uygunsuzluk oluşturun.</small>
                        <?php endif; ?>
                    </label>
                    <label class="form-field form-field-wide">
                        <span data-i18n="complaintDescriptionLabel">Şikayet Açıklaması</span>
                        <textarea name="description" rows="4"><?= htmlspecialchars((string) ($complaint["description"] ?? ""), ENT_QUOTES, "UTF-8") ?></textarea>
                    </label>
                    <label class="form-field form-field-wide">
                        <span data-i18n="complaintRootCauseLabel">Kök Neden</span>
                        <textarea name="root_cause" rows="3"><?= htmlspecialchars((string) ($complaint["root_cause"] ?? ""), ENT_QUOTES, "UTF-8") ?></textarea>
                    </label>
                    <label class="form-field form-field-wide">
                        <span data-i18n="complaintActionNoteLabel">Aksiyon / Düzeltici Faaliyet Notu</span>
                        <textarea name="action_note" rows="3"><?= htmlspecialchars((string) ($complaint["action_note"] ?? ""), ENT_QUOTES, "UTF-8") ?></textarea>
                    </label>
                    <label class="form-field form-field-wide">
                        <span data-i18n="complaintResolutionNoteLabel">Çözüm Notu</span>
                        <textarea name="resolution_note" rows="3"><?= htmlspecialchars((string) ($complaint["resolution_note"] ?? ""), ENT_QUOTES, "UTF-8") ?></textarea>
                    </label>
                </div>
                <div class="form-actions">
                    <button class="primary-button" type="submit" data-i18n="saveComplaintButton">Şikayeti Kaydet</button>
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
