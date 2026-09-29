<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/access.php';
require_once __DIR__ . '/includes/vocabulary.php';
require_once __DIR__ . '/includes/audit-log-functions.php';
require_once __DIR__ . '/includes/notifications.php';

$nonconformityId = (int) ($_GET["id"] ?? 0);

if ($nonconformityId <= 0) {
    header("Location: dashboard.php");
    exit;
}

$userId = (int) ($_SESSION["qms_user_id"] ?? 0);
$isSuperAdmin = ($_SESSION["qms_role"] ?? "") === "super_admin";

$sql = "SELECT nonconformities.*, companies.company_name, audits.title AS audit_title,
            audit_checklist_items.item_text AS checklist_item_text,
            audit_checklist_items.requirement_ref
     FROM nonconformities
     INNER JOIN companies ON companies.id = nonconformities.company_id
     LEFT JOIN audits ON audits.id = nonconformities.audit_id
     LEFT JOIN audit_checklist_items ON audit_checklist_items.id = nonconformities.checklist_item_id
     WHERE nonconformities.id = ?";
$params = [$nonconformityId];
$scope = qmsAuditRecordScope($pdo, $userId, 'nonconformities.audit_id', 'nonconformities.company_id');
$sql .= $scope['sql'];
$params = array_merge($params, $scope['params']);
$sql .= " LIMIT 1";

$nonconformityStmt = $pdo->prepare($sql);
$nonconformityStmt->execute($params);
$nonconformity = $nonconformityStmt->fetch(PDO::FETCH_ASSOC);

if (!$nonconformity) {
    header("Location: dashboard.php");
    exit;
}

// Uygunsuzluk bir denetimden ya da sikayetten dogar; kaynak gosterimi buna baglidir.
$ncSource = (string) ($nonconformity["source"] ?? "audit");
$linkedComplaintId = 0;
if ($ncSource === 'complaint') {
    $complaintLinkStmt = $pdo->prepare(
        "SELECT id FROM complaints WHERE nonconformity_id = ? AND active = 1 LIMIT 1"
    );
    $complaintLinkStmt->execute([$nonconformityId]);
    $linkedComplaintId = (int) ($complaintLinkStmt->fetchColumn() ?: 0);
}
$linkedIncidentId = (int) ($nonconformity["incident_id"] ?? 0);
$linkedDeliveryId = (int) ($nonconformity["delivery_id"] ?? 0);

$formError = "";
$allowedSeverities = ["minor", "major", "critical"];
$allowedStatuses = ["open", "in_progress", "verification", "closed"];

require_once __DIR__ . '/includes/csrf.php';

$csrfToken = qmsCsrfToken('nonconformity');

if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["form_type"] ?? "") === "update_nonconformity") {
    qmsCsrfVerify('nonconformity', $_POST["csrf"] ?? null);

    $formData = [
        "title" => trim($_POST["title"] ?? ""),
        "description" => trim($_POST["description"] ?? ""),
        "root_cause" => trim($_POST["root_cause"] ?? ""),
        "severity" => $_POST["severity"] ?? "major",
        "status" => $_POST["status"] ?? "open",
        "due_date" => trim($_POST["due_date"] ?? ""),
        "responsible_person" => trim($_POST["responsible_person"] ?? "")
    ];

    if ($formData["title"] === "") {
        $formError = "Lütfen uygunsuzluk başlığını girin.";
    } elseif (!in_array($formData["severity"], $allowedSeverities, true)) {
        $formError = "Geçerli bir önem seviyesi seçin.";
    } elseif (!in_array($formData["status"], $allowedStatuses, true)) {
        $formError = "Geçerli bir durum seçin.";
    } else {
        $updateStmt = $pdo->prepare(
            "UPDATE nonconformities
             SET title = :title,
                 description = :description,
                 root_cause = :root_cause,
                 severity = :severity,
                 status = :status,
                 due_date = :due_date,
                 responsible_person = :responsible_person
             WHERE id = :id"
        );
        $updateStmt->execute([
            "title" => $formData["title"],
            "description" => $formData["description"] !== "" ? $formData["description"] : null,
            "root_cause" => $formData["root_cause"] !== "" ? $formData["root_cause"] : null,
            "severity" => $formData["severity"],
            "status" => $formData["status"],
            "due_date" => $formData["due_date"] !== "" ? $formData["due_date"] : null,
            "responsible_person" => $formData["responsible_person"] !== "" ? $formData["responsible_person"] : null,
            "id" => $nonconformityId
        ]);

        $ncAction = $formData["status"] === "closed"
            ? 'close'
            : ($formData["status"] !== $nonconformity["status"] ? 'status_change' : 'update');
        qmsAuditLog($pdo, (int) $nonconformity["company_id"], $userId, 'nonconformity', $nonconformityId, $ncAction, 'Uygunsuzluk güncellendi: ' . $formData["title"] . ' (durum: ' . $formData["status"] . ')');

        // Durum degisiminde sirket adminlerine bildirim (kapanis ayri tiptir).
        if ($formData["status"] !== (string) ($nonconformity["status"] ?? '')) {
            $ncLink = 'nonconformity-detail.php?id=' . $nonconformityId;
            $ncMessage = 'Uygunsuzluk: ' . $formData["title"] . ' (durum: ' . $formData["status"] . ')';
            if ($formData["status"] === "closed") {
                qmsNotifyCompanyAdmins($pdo, (int) $nonconformity["company_id"], 'nc_closed', 'Uygunsuzluk kapatıldı', $ncMessage, $ncLink, $userId);
            } else {
                qmsNotifyCompanyAdmins($pdo, (int) $nonconformity["company_id"], 'nc_status_changed', 'Uygunsuzluk durumu değişti', $ncMessage, $ncLink, $userId);
            }
        }

        header("Location: nonconformity-detail.php?id=" . $nonconformityId . "&updated=1");
        exit;
    }

    $nonconformity = array_merge($nonconformity, $formData);
}

$statusLabels = [
    "open" => "Açık",
    "in_progress" => "Çalışılıyor",
    "verification" => "Doğrulama",
    "closed" => "Kapalı"
];

// Onem sozlugu tek kaynaktan gelir (includes/vocabulary.php).
$severityLabels = qmsSeverityLabels();

$correctiveActionsStmt = $pdo->prepare(
    "SELECT * FROM corrective_actions
     WHERE nonconformity_id = :nonconformity_id AND active = 1
     ORDER BY created_at DESC, id DESC"
);
$correctiveActionsStmt->execute(["nonconformity_id" => $nonconformityId]);
$correctiveActions = $correctiveActionsStmt->fetchAll(PDO::FETCH_ASSOC);

$actionStatusLabels = [
    "planned" => "Planlandı",
    "in_progress" => "Çalışılıyor",
    "verification" => "Doğrulama",
    "completed" => "Tamamlandı",
    "closed" => "Kapalı"
];

$closedActionCount = count(array_filter($correctiveActions, static function ($action) {
    return in_array($action["status"], ["completed", "closed"], true);
}));

?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f9fafb">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="QuAmi">

    <title>QuAmi Uygunsuzluk Detayı</title>

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
                <div class="brand-icon"><img src="assets/icons/qms-logo.png" alt="QuAmi"></div>
                <div class="brand-text">
                    <strong>QuAmi</strong>
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
                    <span class="section-kicker" data-i18n="nonconformityWorkspaceKicker">Uygunsuzluk Çalışma Alanı</span>
                    <h1><?= htmlspecialchars($nonconformity["title"], ENT_QUOTES, "UTF-8") ?></h1>
                    <p>
                        <?= htmlspecialchars($nonconformity["company_name"], ENT_QUOTES, "UTF-8") ?>
                        <?php if ($ncSource === 'complaint'): ?>
                        · <span data-i18n="ncComplaintSourceLabel">Şikayet Kaynağı</span>
                        <?php elseif ($ncSource === 'incident'): ?>
                        · <span data-i18n="ncIncidentSourceLabel">Olay Kaynağı</span>
                        <?php elseif ($ncSource === 'delivery'): ?>
                        · <span data-i18n="ncDeliverySourceLabel">Teslimat Kaynağı</span>
                        <?php else: ?>
                        · <?= htmlspecialchars($nonconformity["audit_title"], ENT_QUOTES, "UTF-8") ?>
                        <?php endif; ?>
                    </p>
                </div>
                <?php if ($linkedComplaintId > 0): ?>
                    <a class="secondary-button" href="complaint-detail.php?id=<?= $linkedComplaintId ?>" data-i18n="backToComplaintButton">Şikayete Dön</a>
                <?php elseif ($linkedIncidentId > 0): ?>
                    <a class="secondary-button" href="incident-detail.php?id=<?= $linkedIncidentId ?>" data-i18n="backToIncidentButton">Olay Detayına Dön</a>
                <?php elseif ($linkedDeliveryId > 0): ?>
                    <a class="secondary-button" href="delivery-performance.php?edit=<?= $linkedDeliveryId ?>" data-i18n="backToDeliveryButton">Teslimata Dön</a>
                <?php else: ?>
                    <a class="secondary-button" href="audit-detail.php?id=<?= (int) $nonconformity["audit_id"] ?>" data-i18n="backToAuditButton">Denetime Dön</a>
                <?php endif; ?>
            </div>
        </section>

        <section class="dashboard-grid">
            <div class="dashboard-card">
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="severityLabel">Önem Seviyesi</span>
                    <strong class="dashboard-card-number detail-card-value"><?= htmlspecialchars($severityLabels[$nonconformity["severity"]] ?? $nonconformity["severity"], ENT_QUOTES, "UTF-8") ?></strong>
                </div>
            </div>
            <div class="dashboard-card">
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="nonconformityStatusLabel">Durum</span>
                    <strong class="dashboard-card-number detail-card-value"><?= htmlspecialchars($statusLabels[$nonconformity["status"]] ?? $nonconformity["status"], ENT_QUOTES, "UTF-8") ?></strong>
                </div>
            </div>
            <div class="dashboard-card">
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="dueDateLabel">Termin Tarihi</span>
                    <strong class="dashboard-card-number detail-card-value"><?= htmlspecialchars($nonconformity["due_date"] ?: "-", ENT_QUOTES, "UTF-8") ?></strong>
                </div>
            </div>
        </section>

        <section class="page-section">
            <div class="section-heading">
                <div>
                    <span class="section-kicker" data-i18n="correctiveActionsKicker">İyileştirme Takibi</span>
                    <h2 data-i18n="correctiveActionsTitle">Düzeltici Faaliyetler</h2>
                    <p data-i18n="correctiveActionsText">Uygunsuzluğu gidermek ve tekrarını önlemek için planlanan faaliyetler.</p>
                </div>
                <a class="primary-button" href="corrective-action-create.php?nonconformity_id=<?= $nonconformityId ?>" data-i18n="createCorrectiveActionButton">Yeni Faaliyet</a>
                <a class="secondary-button" href="root-cause.php?id=<?= $nonconformityId ?>" data-i18n="rootCauseMenuLabel">Kök Neden Analizi</a>
                <a class="secondary-button" href="closure-package-export.php?nonconformity_id=<?= $nonconformityId ?>" data-i18n="closurePackageButton">Kapanış Paketi (PDF)</a>
            </div>

            <?php if (isset($_GET["action"]) && $_GET["action"] === "created"): ?>
                <div class="form-message success" data-i18n="correctiveActionCreatedMessage">Düzeltici faaliyet oluşturuldu.</div>
            <?php endif; ?>

            <div class="dashboard-grid compact-dashboard-grid">
                <div class="dashboard-card">
                    <div class="dashboard-card-content">
                        <span class="dashboard-card-label" data-i18n="correctiveActionTotalLabel">Toplam Faaliyet</span>
                        <strong class="dashboard-card-number"><?= count($correctiveActions) ?></strong>
                    </div>
                </div>
                <div class="dashboard-card">
                    <div class="dashboard-card-content">
                        <span class="dashboard-card-label" data-i18n="correctiveActionOpenLabel">Devam Eden</span>
                        <strong class="dashboard-card-number"><?= count($correctiveActions) - $closedActionCount ?></strong>
                    </div>
                </div>
                <div class="dashboard-card">
                    <div class="dashboard-card-content">
                        <span class="dashboard-card-label" data-i18n="correctiveActionClosedLabel">Tamamlanan</span>
                        <strong class="dashboard-card-number"><?= $closedActionCount ?></strong>
                    </div>
                </div>
            </div>

            <?php if (!$correctiveActions): ?>
                <div class="empty-state" data-i18n="noCorrectiveActionsText">Henüz düzeltici faaliyet oluşturulmadı.</div>
            <?php else: ?>
                <div class="record-card-grid">
                    <?php foreach ($correctiveActions as $action): ?>
                        <a class="record-card" href="corrective-action-detail.php?id=<?= (int) $action["id"] ?>">
                            <div class="record-card-topline">
                                <span class="status-badge status-<?= htmlspecialchars($action["status"], ENT_QUOTES, "UTF-8") ?>">
                                    <?= htmlspecialchars($actionStatusLabels[$action["status"]] ?? $action["status"], ENT_QUOTES, "UTF-8") ?>
                                </span>
                                <span><?= htmlspecialchars($action["due_date"] ?: "-", ENT_QUOTES, "UTF-8") ?></span>
                            </div>
                            <h3><?= htmlspecialchars($action["action_text"], ENT_QUOTES, "UTF-8") ?></h3>
                            <p><?= htmlspecialchars($action["responsible_person"] ?: "Sorumlu atanmadı", ENT_QUOTES, "UTF-8") ?></p>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="super-admin-console">
            <div class="console-card">
                <div class="section-heading compact-heading">
                    <div>
                        <h3 data-i18n="manageNonconformityTitle">Uygunsuzluğu Yönet</h3>
                        <p data-i18n="manageNonconformityText">Sorumluluk, termin ve kapanış sürecini güncelleyin.</p>
                    </div>
                </div>

                <?php if (isset($_GET["updated"]) && $_GET["updated"] === "1"): ?>
                    <div class="form-message success" data-i18n="nonconformityUpdatedMessage">Uygunsuzluk güncellendi.</div>
                <?php endif; ?>

                <?php if ($formError !== ""): ?>
                    <div class="form-message error"><?= htmlspecialchars($formError, ENT_QUOTES, "UTF-8") ?></div>
                <?php endif; ?>

                <form class="auditor-form" method="post" action="nonconformity-detail.php?id=<?= $nonconformityId ?>">
                    <?= qmsCsrfField('nonconformity') ?>
                    <input type="hidden" name="form_type" value="update_nonconformity">
                    <div class="form-grid">
                        <label class="form-field form-field-wide">
                            <span data-i18n="nonconformityTitleLabel">Uygunsuzluk Başlığı</span>
                            <input type="text" name="title" value="<?= htmlspecialchars($nonconformity["title"], ENT_QUOTES, "UTF-8") ?>" required>
                        </label>
                        <label class="form-field form-field-wide">
                            <span data-i18n="descriptionLabel">Açıklama</span>
                            <textarea name="description" rows="4"><?= htmlspecialchars($nonconformity["description"] ?? "", ENT_QUOTES, "UTF-8") ?></textarea>
                        </label>
                        <label class="form-field">
                            <span data-i18n="severityLabel">Önem Seviyesi</span>
                            <select name="severity">
                                <option value="minor" <?= $nonconformity["severity"] === "minor" ? "selected" : "" ?> data-i18n="severityMinorLabel">Minör</option>
                                <option value="major" <?= $nonconformity["severity"] === "major" ? "selected" : "" ?> data-i18n="severityMajorLabel">Majör</option>
                                <option value="critical" <?= $nonconformity["severity"] === "critical" ? "selected" : "" ?> data-i18n="severityCriticalLabel">Kritik</option>
                            </select>
                        </label>
                        <label class="form-field">
                            <span data-i18n="nonconformityStatusLabel">Durum</span>
                            <select name="status">
                                <option value="open" <?= $nonconformity["status"] === "open" ? "selected" : "" ?> data-i18n="statusOpenLabel">Açık</option>
                                <option value="in_progress" <?= $nonconformity["status"] === "in_progress" ? "selected" : "" ?> data-i18n="statusInProgressLabel">Çalışılıyor</option>
                                <option value="verification" <?= $nonconformity["status"] === "verification" ? "selected" : "" ?> data-i18n="statusVerificationLabel">Doğrulama</option>
                                <option value="closed" <?= $nonconformity["status"] === "closed" ? "selected" : "" ?> data-i18n="statusClosedLabel">Kapalı</option>
                            </select>
                        </label>
                        <label class="form-field">
                            <span data-i18n="responsiblePersonLabel">Sorumlu Kişi</span>
                            <input type="text" name="responsible_person" value="<?= htmlspecialchars($nonconformity["responsible_person"] ?? "", ENT_QUOTES, "UTF-8") ?>">
                        </label>
                        <label class="form-field">
                            <span data-i18n="dueDateLabel">Termin Tarihi</span>
                            <input type="date" name="due_date" value="<?= htmlspecialchars($nonconformity["due_date"] ?? "", ENT_QUOTES, "UTF-8") ?>">
                        </label>
                        <label class="form-field form-field-wide">
                            <span data-i18n="rootCauseLabel">Kök Neden</span>
                            <textarea name="root_cause" rows="4"><?= htmlspecialchars($nonconformity["root_cause"] ?? "", ENT_QUOTES, "UTF-8") ?></textarea>
                        </label>
                    </div>
                    <div class="form-actions">
                        <button class="primary-button" type="submit" data-i18n="saveNonconformityButton">Değişiklikleri Kaydet</button>
                    </div>
                </form>
            </div>

            <div class="console-card">
                <div class="section-heading compact-heading">
                    <div>
                        <h3 data-i18n="sourceRecordTitle">Kaynak Kayıt</h3>
                        <p data-i18n="sourceRecordText">Uygunsuzluğun oluştuğu denetim maddesi.</p>
                    </div>
                </div>
                <div class="admin-list">
                    <div class="admin-list-item">
                        <div>
                            <strong data-i18n="companySelectLabel">Şirket</strong>
                            <span><?= htmlspecialchars($nonconformity["company_name"], ENT_QUOTES, "UTF-8") ?></span>
                        </div>
                    </div>
                    <?php if ($ncSource === 'complaint' && $linkedComplaintId > 0): ?>
                        <a class="admin-list-item" href="complaint-detail.php?id=<?= $linkedComplaintId ?>">
                            <div>
                                <strong data-i18n="sourceLabel">Kaynak</strong>
                                <span data-i18n="ncComplaintSourceValueLabel">Şikayet</span>
                            </div>
                        </a>
                    <?php elseif ($ncSource === 'incident' && $linkedIncidentId > 0): ?>
                        <a class="admin-list-item" href="incident-detail.php?id=<?= $linkedIncidentId ?>">
                            <div>
                                <strong data-i18n="sourceLabel">Kaynak</strong>
                                <span data-i18n="ncIncidentSourceValueLabel">Olay</span>
                            </div>
                        </a>
                    <?php elseif ($ncSource === 'delivery' && $linkedDeliveryId > 0): ?>
                        <a class="admin-list-item" href="delivery-performance.php?edit=<?= $linkedDeliveryId ?>">
                            <div>
                                <strong data-i18n="sourceLabel">Kaynak</strong>
                                <span data-i18n="ncDeliverySourceValueLabel">Teslimat Performansı</span>
                            </div>
                        </a>
                    <?php else: ?>
                        <a class="admin-list-item" href="audit-detail.php?id=<?= (int) $nonconformity["audit_id"] ?>">
                            <div>
                                <strong data-i18n="auditTitleLabel">Denetim Başlığı</strong>
                                <span><?= htmlspecialchars($nonconformity["audit_title"], ENT_QUOTES, "UTF-8") ?></span>
                            </div>
                        </a>
                        <div class="admin-list-item">
                            <div>
                                <strong data-i18n="checklistItemLabel">Kontrol Maddesi</strong>
                                <span><?= htmlspecialchars($nonconformity["checklist_item_text"] ?: "-", ENT_QUOTES, "UTF-8") ?></span>
                            </div>
                        </div>
                        <div class="admin-list-item">
                            <div>
                                <strong data-i18n="requirementRefLabel">Referans / Madde</strong>
                                <span><?= htmlspecialchars($nonconformity["requirement_ref"] ?: "-", ENT_QUOTES, "UTF-8") ?></span>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    </main>

    <script src="assets/js/theme.js"></script>
    <script src="assets/js/language.js"></script>
    <script src="assets/js/sidebar.js"></script>
    <script src="assets/js/pwa.js"></script>
</body>
</html>
