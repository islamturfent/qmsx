<?php

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/document-editor.php';
require_once __DIR__ . '/includes/notifications.php';
require_once __DIR__ . '/includes/audit-log-functions.php';
$_SESSION["document_csrf"] ??= bin2hex(random_bytes(32));

$documentId = (int) ($_GET["id"] ?? 0);
$userId = (int) ($_SESSION["qms_user_id"] ?? 0);
$isSuperAdmin = ($_SESSION["qms_role"] ?? "") === "super_admin";

require_once __DIR__ . '/includes/access.php';

$documentSql = "SELECT documents.*, companies.company_name
                FROM documents INNER JOIN companies ON companies.id = documents.company_id
                WHERE documents.id = ? AND documents.active = 1";
$documentParams = [$documentId];
$scope = qmsCompanyScope('documents.company_id', qmsVisibleCompanyIds($pdo, $userId, qmsCurrentRole()));
$documentSql .= $scope['sql'];
$documentParams = array_merge($documentParams, $scope['params']);
$documentSql .= " LIMIT 1";

$documentStmt = $pdo->prepare($documentSql);
$documentStmt->execute($documentParams);
$document = $documentStmt->fetch(PDO::FETCH_ASSOC);
if (!$document) {
    header("Location: documents.php");
    exit;
}

$allowedFiles = [
    "pdf" => ["application/pdf"],
    "doc" => ["application/msword", "application/octet-stream"],
    "docx" => ["application/vnd.openxmlformats-officedocument.wordprocessingml.document", "application/zip", "application/octet-stream"],
    "xls" => ["application/vnd.ms-excel", "application/octet-stream"],
    "xlsx" => ["application/vnd.openxmlformats-officedocument.spreadsheetml.sheet", "application/zip", "application/octet-stream"]
];
$formError = "";

// Onaylayici listesi erisim kapsami degil, onay yetkinligidir: sirkete atanmis
// sistem adminleri ve super admin onaylayabilir, bu yuzden kendi sorgusu kalir.
$approversStmt = $pdo->prepare(
    "SELECT DISTINCT users.id, users.full_name, users.role
     FROM users
     LEFT JOIN company_admin_assignments ON company_admin_assignments.admin_user_id = users.id
     WHERE users.active = 1
       AND (users.role = 'super_admin'
            OR (users.role = 'system_admin'
                AND company_admin_assignments.company_id = :company_id
                AND company_admin_assignments.active = 1))
     ORDER BY users.full_name"
);
$approversStmt->execute(["company_id" => (int) $document["company_id"]]);
$approvers = $approversStmt->fetchAll(PDO::FETCH_ASSOC);
$approverIds = array_map('intval', array_column($approvers, 'id'));

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!hash_equals($_SESSION["document_csrf"], (string) ($_POST["csrf"] ?? ""))) {
        http_response_code(403); exit("Oturum doğrulanamadı. Sayfayı yenileyin.");
    }
    $pdo->beginTransaction();
    $document = qmsEditorDocument($pdo, $documentId, $userId, $isSuperAdmin, true);
    if (!$document) { $pdo->rollBack(); http_response_code(404); exit("Doküman bulunamadı."); }
    try { qmsOfficeAssertUnlocked($pdo, $documentId); }
    catch (RuntimeException $e) { $pdo->rollBack(); http_response_code(409); exit(htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')); }
    $latestVersion = qmsEditorLatest($pdo, $documentId);
    if ((int) ($_POST["base_version"] ?? -1) !== (int) ($latestVersion["id"] ?? 0)) {
        $pdo->rollBack(); http_response_code(409); exit("Doküman revizyonu değişti. Sayfayı yenileyin.");
    }
    $formType = $_POST["form_type"] ?? "";

    if ($formType === "update_document") {
        $formData = [
            "title" => trim($_POST["title"] ?? ""),
            "category" => trim($_POST["category"] ?? ""),
            "owner_name" => trim($_POST["owner_name"] ?? ""),
            "effective_date" => trim($_POST["effective_date"] ?? ""),
            "review_date" => trim($_POST["review_date"] ?? ""),
            "description" => trim($_POST["description"] ?? "")
        ];

        if ($document["status"] !== "draft") {
            $formError = "Doküman bilgileri yalnız taslak aşamasında düzenlenebilir.";
        } elseif ($formData["title"] === "") {
            $formError = "Doküman başlığı zorunludur.";
        } elseif ($formData["effective_date"] !== "" && $formData["review_date"] !== "" && $formData["review_date"] < $formData["effective_date"]) {
            $formError = "Gözden geçirme tarihi yürürlük tarihinden önce olamaz.";
        } else {
            $updateStmt = $pdo->prepare(
                "UPDATE documents SET title = :title, category = :category, owner_name = :owner_name,
                 effective_date = :effective_date, review_date = :review_date,
                 description = :description WHERE id = :id"
            );
            $updateStmt->execute([
                "title" => $formData["title"],
                "category" => $formData["category"] !== "" ? $formData["category"] : null,
                "owner_name" => $formData["owner_name"] !== "" ? $formData["owner_name"] : null,
                "effective_date" => $formData["effective_date"] !== "" ? $formData["effective_date"] : null,
                "review_date" => $formData["review_date"] !== "" ? $formData["review_date"] : null,
                "description" => $formData["description"] !== "" ? $formData["description"] : null,
                "id" => $documentId
            ]);
            $pdo->commit();
            header("Location: document-detail.php?id=" . $documentId . "&updated=1");
            exit;
        }
        $document = array_merge($document, $formData);
    }

    if ($formType === "upload_revision") {
        $revisionNumber = trim($_POST["revision_number"] ?? "");
        $changeNote = trim($_POST["change_note"] ?? "");
        $upload = $_FILES["document_file"] ?? null;

        if (in_array($document["status"], ["review", "archived"], true)) {
            $formError = "İncelemedeki veya arşivlenmiş dokümana revizyon yüklenemez.";
        } elseif ($revisionNumber === "") {
            $formError = "Revizyon numarası zorunludur.";
        } elseif (!$upload || $upload["error"] !== UPLOAD_ERR_OK || $upload["size"] > 10 * 1024 * 1024) {
            $formError = "Dosya yüklenemedi veya 10 MB sınırını aşıyor.";
        } else {
            $extension = strtolower(pathinfo($upload["name"], PATHINFO_EXTENSION));
            $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($upload["tmp_name"]);
            if (!isset($allowedFiles[$extension]) || !in_array($mimeType, $allowedFiles[$extension], true)) {
                $formError = "Yalnız PDF, Word ve Excel dosyaları yüklenebilir.";
            } else {
                $storedName = bin2hex(random_bytes(20)) . "." . $extension;
                $storedPath = __DIR__ . DIRECTORY_SEPARATOR . "storage" . DIRECTORY_SEPARATOR . "documents" . DIRECTORY_SEPARATOR . $storedName;
                try {
                    
                    if (!move_uploaded_file($upload["tmp_name"], $storedPath)) {
                        throw new RuntimeException("Dosya depoya taşınamadı.");
                    }
                    $versionStmt = $pdo->prepare(
                        "INSERT INTO document_versions
                            (document_id, revision_number, original_file_name, stored_file_name, mime_type, file_size, change_note, uploaded_by)
                         VALUES (:document_id, :revision_number, :original_file_name, :stored_file_name, :mime_type, :file_size, :change_note, :uploaded_by)"
                    );
                    $versionStmt->execute([
                        "document_id" => $documentId,
                        "revision_number" => $revisionNumber,
                        "original_file_name" => basename($upload["name"]),
                        "stored_file_name" => $storedName,
                        "mime_type" => $mimeType,
                        "file_size" => (int) $upload["size"],
                        "change_note" => $changeNote !== "" ? $changeNote : null,
                        "uploaded_by" => $userId ?: null
                    ]);
                    $pdo->prepare("UPDATE documents SET current_revision = :revision, status = 'draft' WHERE id = :id")
                        ->execute(["revision" => $revisionNumber, "id" => $documentId]);
                    $pdo->commit();
                    header("Location: document-detail.php?id=" . $documentId . "&revision=created");
                    exit;
                } catch (Throwable $error) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    if (is_file($storedPath)) unlink($storedPath);
                    $formError = $error instanceof PDOException && $error->getCode() === "23000"
                        ? "Bu revizyon numarası daha önce kullanılmış."
                        : "Revizyon yüklenemedi.";
                }
            }
        }
    }

    if ($formType === "request_approval") {
        $approverId = (int) ($_POST["approver_user_id"] ?? 0);
        $requestNote = trim($_POST["request_note"] ?? "");
        $versionCountStmt = $pdo->prepare("SELECT COUNT(*) FROM document_versions WHERE document_id = :document_id");
        $versionCountStmt->execute(["document_id" => $documentId]);
        $versionCount = (int) $versionCountStmt->fetchColumn();
        $pendingStmt = $pdo->prepare("SELECT COUNT(*) FROM document_approvals WHERE document_id = :document_id AND decision = 'pending'");
        $pendingStmt->execute(["document_id" => $documentId]);

        if ($document["status"] !== "draft") {
            $formError = "Yalnız taslak dokümanlar onaya gönderilebilir.";
        } elseif ($versionCount === 0) {
            $formError = "Onaya göndermeden önce en az bir revizyon dosyası yükleyin.";
        } elseif (!in_array($approverId, $approverIds, true)) {
            $formError = "Geçerli bir onay yetkilisi seçin.";
        } elseif ((int) $pendingStmt->fetchColumn() > 0) {
            $formError = "Bu doküman için zaten bekleyen bir onay isteği var.";
        } else {
            
            $approvalStmt = $pdo->prepare(
                "INSERT INTO document_approvals (document_id, requested_by, approver_user_id, decision, request_note)
                 VALUES (:document_id, :requested_by, :approver_user_id, 'pending', :request_note)"
            );
            $approvalStmt->execute([
                "document_id" => $documentId,
                "requested_by" => $userId ?: null,
                "approver_user_id" => $approverId,
                "request_note" => $requestNote !== "" ? $requestNote : null
            ]);
            qmsNotify(
                $pdo,
                $approverId,
                "document_approval_request",
                "Yeni doküman onay talebi",
                $document["document_code"] . " · " . $document["title"],
                "document-detail.php?id=" . $documentId
            );
            $pdo->prepare("UPDATE documents SET status = 'review' WHERE id = :id")
                ->execute(["id" => $documentId]);
            $pdo->commit();
            header("Location: document-detail.php?id=" . $documentId . "&workflow=requested");
            exit;
        }
    }

    if ($formType === "decide_approval") {
        $approvalId = (int) ($_POST["approval_id"] ?? 0);
        $decision = $_POST["decision"] ?? "";
        $decisionNote = trim($_POST["decision_note"] ?? "");
        $approvalStmt = $pdo->prepare(
            "SELECT * FROM document_approvals
             WHERE id = :id AND document_id = :document_id AND decision = 'pending' LIMIT 1"
        );
        $approvalStmt->execute(["id" => $approvalId, "document_id" => $documentId]);
        $approval = $approvalStmt->fetch(PDO::FETCH_ASSOC);

        if ($document["status"] !== "review" || !$approval || (!$isSuperAdmin && (int) $approval["approver_user_id"] !== $userId)) {
            $formError = "Bu onay isteği için karar verme yetkiniz yok.";
        } elseif (!in_array($decision, ["approved", "rejected"], true)) {
            $formError = "Geçerli bir karar seçin.";
        } else {
            
            $pdo->prepare(
                "UPDATE document_approvals SET decision = :decision, decision_note = :decision_note, decided_at = NOW() WHERE id = :id"
            )->execute([
                "decision" => $decision,
                "decision_note" => $decisionNote !== "" ? $decisionNote : null,
                "id" => $approvalId
            ]);
            qmsNotify(
                $pdo,
                (int) ($approval["requested_by"] ?? 0),
                "document_approval_decision",
                $decision === "approved" ? "Doküman onaylandı" : "Doküman düzeltme için geri gönderildi",
                $document["document_code"] . " · " . $document["title"],
                "document-detail.php?id=" . $documentId
            );
            $nextStatus = $decision === "approved" ? "approved" : "draft";
            $pdo->prepare("UPDATE documents SET status = :status WHERE id = :id")
                ->execute(["status" => $nextStatus, "id" => $documentId]);
            $pdo->commit();
            header("Location: document-detail.php?id=" . $documentId . "&workflow=" . $decision);
            exit;
        }
    }

    if ($formType === "publish_document") {
        if (!$isSuperAdmin || $document["status"] !== "approved") {
            $formError = "Yalnız onaylanmış dokümanlar süper admin tarafından yayımlanabilir.";
        } else {
            
            $pdo->prepare("UPDATE documents SET status = 'published', effective_date = COALESCE(effective_date, CURDATE()) WHERE id = :id")
                ->execute(["id" => $documentId]);
            qmsAuditLog($pdo, (int) $document["company_id"], $userId, 'document', $documentId, 'publish', 'Doküman yayımlandı: ' . $document["title"]);
            $requesterStmt = $pdo->prepare(
                "SELECT requested_by FROM document_approvals
                 WHERE document_id = :document_id AND decision = 'approved' AND requested_by IS NOT NULL
                 ORDER BY id DESC LIMIT 1"
            );
            $requesterStmt->execute(["document_id" => $documentId]);
            $requesterId = (int) ($requesterStmt->fetchColumn() ?: 0);
            qmsNotify(
                $pdo,
                $requesterId,
                "document_published",
                "Doküman yayımlandı",
                $document["document_code"] . " · " . $document["title"],
                "document-detail.php?id=" . $documentId
            );
            $pdo->commit();
            header("Location: document-detail.php?id=" . $documentId . "&workflow=published");
            exit;
        }
    }

    if ($formType === "archive_document") {
        if (!$isSuperAdmin || $document["status"] !== "published") {
            $formError = "Yalnız yayındaki dokümanlar süper admin tarafından arşivlenebilir.";
        } else {
            $pdo->prepare("UPDATE documents SET status = 'archived' WHERE id = :id")
                ->execute(["id" => $documentId]);
            qmsAuditLog($pdo, (int) $document["company_id"], $userId, 'document', $documentId, 'archive', 'Doküman arşivlendi: ' . $document["title"]);
            $pdo->commit();
            header("Location: document-detail.php?id=" . $documentId . "&workflow=archived");
            exit;
        }
    }
}

if ($pdo->inTransaction()) $pdo->rollBack();

$versionsStmt = $pdo->prepare(
    "SELECT document_versions.*, users.full_name AS uploader_name
     FROM document_versions LEFT JOIN users ON users.id = document_versions.uploaded_by
     WHERE document_versions.document_id = :document_id ORDER BY document_versions.id DESC"
);
$versionsStmt->execute(["document_id" => $documentId]);
$versions = $versionsStmt->fetchAll(PDO::FETCH_ASSOC);

$approvalsStmt = $pdo->prepare(
    "SELECT document_approvals.*, requester.full_name AS requester_name, approver.full_name AS approver_name
     FROM document_approvals
     LEFT JOIN users requester ON requester.id = document_approvals.requested_by
     INNER JOIN users approver ON approver.id = document_approvals.approver_user_id
     WHERE document_approvals.document_id = :document_id ORDER BY document_approvals.id DESC"
);
$approvalsStmt->execute(["document_id" => $documentId]);
$approvals = $approvalsStmt->fetchAll(PDO::FETCH_ASSOC);
$pendingApproval = null;
foreach ($approvals as $approvalRecord) {
    if ($approvalRecord["decision"] === "pending") {
        $pendingApproval = $approvalRecord;
        break;
    }
}

$statusLabels = ["draft" => "Taslak", "review" => "İncelemede", "approved" => "Onaylandı", "published" => "Yayında", "archived" => "Arşivlendi"];
$activeNav = "documents";

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="theme-color" content="#f9fafb">
    <title>QuAmi Doküman Detayı</title><link rel="manifest" href="manifest.webmanifest"><link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml"><link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar"><div class="topbar-inner"><div class="page-title-block"><strong><?= htmlspecialchars($document["document_code"], ENT_QUOTES, "UTF-8") ?></strong><span><?= htmlspecialchars($document["company_name"], ENT_QUOTES, "UTF-8") ?></span></div><div class="topbar-actions"><button class="topbar-button" id="languageToggle" type="button">EN</button><button class="topbar-button" id="themeToggle" type="button" aria-label="Tema değiştir">🌙</button></div></div></header>
    <main class="page-container">
        <section class="page-heading page-heading-actions">
            <div>
                <span class="section-kicker" data-i18n="documentWorkspaceKicker">Doküman Çalışma Alanı</span>
                <h1><?= htmlspecialchars($document["title"], ENT_QUOTES, "UTF-8") ?></h1>
                <p><?= htmlspecialchars($document["company_name"], ENT_QUOTES, "UTF-8") ?> · <?= htmlspecialchars($document["document_code"], ENT_QUOTES, "UTF-8") ?></p>
            </div>
            <a class="secondary-button" href="documents.php" data-i18n="backToDocumentsButton">Dokümanlara Dön</a>
        </section>
        <section class="dashboard-grid compact-dashboard-grid">
            <div class="dashboard-card"><div class="dashboard-card-content"><span class="dashboard-card-label" data-i18n="documentStatusLabel">Durum</span><strong class="dashboard-card-number detail-card-value"><?= htmlspecialchars($statusLabels[$document["status"]] ?? $document["status"], ENT_QUOTES, "UTF-8") ?></strong></div></div>
            <div class="dashboard-card"><div class="dashboard-card-content"><span class="dashboard-card-label" data-i18n="revisionLabel">Revizyon</span><strong class="dashboard-card-number detail-card-value"><?= htmlspecialchars($document["current_revision"], ENT_QUOTES, "UTF-8") ?></strong></div></div>
            <div class="dashboard-card"><div class="dashboard-card-content"><span class="dashboard-card-label" data-i18n="reviewDateLabel">Gözden Geçirme</span><strong class="dashboard-card-number detail-card-value"><?= htmlspecialchars($document["review_date"] ?: "-", ENT_QUOTES, "UTF-8") ?></strong></div></div>
        </section>
        <?php if (isset($_GET["updated"])): ?><div class="form-message success" data-i18n="documentUpdatedMessage">Doküman güncellendi.</div><?php endif; ?>
        <?php if (isset($_GET["revision"])): ?><div class="form-message success" data-i18n="revisionCreatedMessage">Yeni revizyon yüklendi.</div><?php endif; ?>
        <?php if (($_GET["workflow"] ?? "") === "requested"): ?><div class="form-message success" data-i18n="approvalRequestedMessage">Doküman onaya gönderildi.</div><?php endif; ?>
        <?php if (($_GET["workflow"] ?? "") === "approved"): ?><div class="form-message success" data-i18n="documentApprovedMessage">Doküman onaylandı.</div><?php endif; ?>
        <?php if (($_GET["workflow"] ?? "") === "rejected"): ?><div class="form-message success" data-i18n="documentRejectedMessage">Doküman düzeltme için taslağa döndürüldü.</div><?php endif; ?>
        <?php if (($_GET["workflow"] ?? "") === "published"): ?><div class="form-message success" data-i18n="documentPublishedMessage">Doküman yayımlandı.</div><?php endif; ?>
        <?php if (($_GET["workflow"] ?? "") === "archived"): ?><div class="form-message success" data-i18n="documentArchivedMessage">Doküman arşivlendi.</div><?php endif; ?>
        <?php if ($formError !== ""): ?><div class="form-message error"><?= htmlspecialchars($formError, ENT_QUOTES, "UTF-8") ?></div><?php endif; ?>

        <section class="page-section form-panel">
            <div class="section-heading compact-heading"><div><span class="section-kicker" data-i18n="editorSectionKicker">Düzenleme</span><h2 data-i18n="editorSectionTitle">Dokümanı Düzenle</h2><p data-i18n="editorSectionText">Web editöründe doğrudan düzenleyin veya dosyayı Ofis uygulamasında açın.</p></div></div>
            <div class="workflow-buttons">
                <a class="primary-button" href="document-office.php?id=<?= $documentId ?>" data-i18n="officeEditorTitle">Ofis Editörü</a>
                <a class="secondary-button" href="document-edit.php?id=<?= $documentId ?>" data-i18n="webEditorTitle">Web Doküman Editörü</a>
            </div>
        </section>
        <section class="page-section workflow-panel">
            <div class="section-heading compact-heading"><div><span class="section-kicker" data-i18n="approvalWorkflowKicker">Kontrollü Yayın</span><h2 data-i18n="approvalWorkflowTitle">Onay ve Yayın Akışı</h2><p data-i18n="approvalWorkflowText">Dokümanı incelemeye gönderin, kararı kaydedin ve yalnız onaydan sonra yayımlayın.</p></div></div>

            <?php if ($document["status"] === "draft"): ?>
                <form class="workflow-form" method="post" action="document-detail.php?id=<?= $documentId ?>">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION["document_csrf"], ENT_QUOTES, "UTF-8") ?>"><input type="hidden" name="base_version" value="<?= (int) ($versions[0]["id"] ?? 0) ?>"><input type="hidden" name="form_type" value="request_approval">
                    <label class="form-field"><span data-i18n="approverLabel">Onay Yetkilisi</span><select name="approver_user_id" required><option value="" data-i18n="selectApproverOption">Yetkili seçin</option><?php foreach ($approvers as $approver): ?><option value="<?= (int) $approver["id"] ?>"><?= htmlspecialchars($approver["full_name"], ENT_QUOTES, "UTF-8") ?> · <?= htmlspecialchars($approver["role"], ENT_QUOTES, "UTF-8") ?></option><?php endforeach; ?></select></label>
                    <label class="form-field"><span data-i18n="requestNoteLabel">Onay Notu</span><input type="text" name="request_note"></label>
                    <button class="primary-button" type="submit" data-i18n="sendForApprovalButton">Onaya Gönder</button>
                </form>
            <?php elseif ($document["status"] === "review" && $pendingApproval): ?>
                <div class="workflow-current">
                    <div><span data-i18n="pendingApprovalLabel">Onay Bekliyor</span><strong><?= htmlspecialchars($pendingApproval["approver_name"], ENT_QUOTES, "UTF-8") ?></strong><small><?= htmlspecialchars($pendingApproval["request_note"] ?: "-", ENT_QUOTES, "UTF-8") ?></small></div>
                    <?php if ($isSuperAdmin || (int) $pendingApproval["approver_user_id"] === $userId): ?>
                        <form class="workflow-decision-form" method="post" action="document-detail.php?id=<?= $documentId ?>">
                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION["document_csrf"], ENT_QUOTES, "UTF-8") ?>"><input type="hidden" name="base_version" value="<?= (int) ($versions[0]["id"] ?? 0) ?>"><input type="hidden" name="form_type" value="decide_approval"><input type="hidden" name="approval_id" value="<?= (int) $pendingApproval["id"] ?>">
                            <label class="form-field"><span data-i18n="decisionNoteLabel">Karar Notu</span><input type="text" name="decision_note"></label>
                            <div class="workflow-buttons"><button class="primary-button" type="submit" name="decision" value="approved" data-i18n="approveDocumentButton">Onayla</button><button class="danger-button" type="submit" name="decision" value="rejected" data-i18n="rejectDocumentButton">Reddet</button></div>
                        </form>
                    <?php endif; ?>
                </div>
            <?php elseif ($document["status"] === "approved" && $isSuperAdmin): ?>
                <form method="post" action="document-detail.php?id=<?= $documentId ?>"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION["document_csrf"], ENT_QUOTES, "UTF-8") ?>"><input type="hidden" name="base_version" value="<?= (int) ($versions[0]["id"] ?? 0) ?>"><input type="hidden" name="form_type" value="publish_document"><button class="primary-button" type="submit" data-i18n="publishDocumentButton">Dokümanı Yayımla</button></form>
            <?php elseif ($document["status"] === "published" && $isSuperAdmin): ?>
                <form method="post" action="document-detail.php?id=<?= $documentId ?>"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION["document_csrf"], ENT_QUOTES, "UTF-8") ?>"><input type="hidden" name="base_version" value="<?= (int) ($versions[0]["id"] ?? 0) ?>"><input type="hidden" name="form_type" value="archive_document"><button class="secondary-button" type="submit" data-i18n="archiveDocumentButton">Arşivle</button></form>
            <?php else: ?>
                <div class="workflow-complete"><span data-i18n="workflowCurrentStatusLabel">Mevcut aşama</span><strong><?= htmlspecialchars($statusLabels[$document["status"]] ?? $document["status"], ENT_QUOTES, "UTF-8") ?></strong></div>
            <?php endif; ?>
        </section>

        <section class="super-admin-console document-console">
            <div class="console-card">
                <div class="section-heading compact-heading"><div><h3 data-i18n="manageDocumentTitle">Dokümanı Yönet</h3><p data-i18n="manageDocumentText">Sorumlu, tarihler ve yayın durumunu güncelleyin.</p></div></div>
                <form class="auditor-form" method="post" action="document-detail.php?id=<?= $documentId ?>">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION["document_csrf"], ENT_QUOTES, "UTF-8") ?>"><input type="hidden" name="base_version" value="<?= (int) ($versions[0]["id"] ?? 0) ?>"><input type="hidden" name="form_type" value="update_document">
                    <div class="form-grid">
                        <label class="form-field form-field-wide"><span data-i18n="documentTitleLabel">Doküman Başlığı</span><input type="text" name="title" value="<?= htmlspecialchars($document["title"], ENT_QUOTES, "UTF-8") ?>" required></label>
                        <label class="form-field"><span data-i18n="documentCategoryLabel">Kategori</span><input type="text" name="category" value="<?= htmlspecialchars($document["category"] ?? "", ENT_QUOTES, "UTF-8") ?>"></label>
                        <label class="form-field"><span data-i18n="documentOwnerLabel">Doküman Sorumlusu</span><input type="text" name="owner_name" value="<?= htmlspecialchars($document["owner_name"] ?? "", ENT_QUOTES, "UTF-8") ?>"></label>
                        <label class="form-field"><span data-i18n="effectiveDateLabel">Yürürlük Tarihi</span><input type="date" name="effective_date" value="<?= htmlspecialchars($document["effective_date"] ?? "", ENT_QUOTES, "UTF-8") ?>"></label>
                        <label class="form-field"><span data-i18n="reviewDateLabel">Gözden Geçirme Tarihi</span><input type="date" name="review_date" value="<?= htmlspecialchars($document["review_date"] ?? "", ENT_QUOTES, "UTF-8") ?>"></label>
                        <label class="form-field form-field-wide"><span data-i18n="descriptionLabel">Açıklama</span><textarea name="description" rows="4"><?= htmlspecialchars($document["description"] ?? "", ENT_QUOTES, "UTF-8") ?></textarea></label>
                    </div>
                    <div class="form-actions"><button class="primary-button" type="submit" data-i18n="saveChangesButton">Değişiklikleri Kaydet</button></div>
                </form>
            </div>
            <div class="console-card">
                <div class="section-heading compact-heading"><div><h3 data-i18n="uploadRevisionTitle">Yeni Revizyon Yükle</h3><p data-i18n="uploadRevisionText">Yeni dosya geçmiş sürümleri silmeden eklenir.</p></div></div>
                <form class="auditor-form" method="post" action="document-detail.php?id=<?= $documentId ?>" enctype="multipart/form-data">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION["document_csrf"], ENT_QUOTES, "UTF-8") ?>"><input type="hidden" name="base_version" value="<?= (int) ($versions[0]["id"] ?? 0) ?>"><input type="hidden" name="form_type" value="upload_revision">
                    <div class="form-grid">
                        <label class="form-field"><span data-i18n="revisionLabel">Revizyon</span><input type="text" name="revision_number" required></label>
                        <label class="form-field form-field-wide"><span data-i18n="documentFileLabel">Doküman Dosyası</span><input type="file" name="document_file" accept=".pdf,.doc,.docx,.xls,.xlsx" required><small data-i18n="documentFileHelp">PDF, Word veya Excel; en fazla 10 MB.</small></label>
                        <label class="form-field form-field-wide"><span data-i18n="changeNoteLabel">Revizyon Notu</span><textarea name="change_note" rows="3"></textarea></label>
                    </div>
                    <div class="form-actions"><button class="primary-button" type="submit" data-i18n="uploadRevisionButton">Revizyonu Yükle</button></div>
                </form>
            </div>
        </section>

        <section class="page-section">
            <div class="section-heading"><div><h2 data-i18n="approvalHistoryTitle">Onay Geçmişi</h2><p data-i18n="approvalHistoryText">Tüm talepler, kararlar ve karar notları.</p></div></div>
            <?php if (!$approvals): ?><div class="empty-state" data-i18n="noApprovalHistoryText">Henüz onay kaydı bulunmuyor.</div><?php else: ?>
                <div class="approval-history">
                    <?php foreach ($approvals as $approvalRecord): ?>
                        <div class="approval-history-item"><span class="status-badge status-<?= htmlspecialchars($approvalRecord["decision"], ENT_QUOTES, "UTF-8") ?>"><?= htmlspecialchars($approvalRecord["decision"], ENT_QUOTES, "UTF-8") ?></span><div><strong><?= htmlspecialchars($approvalRecord["requester_name"] ?: "-", ENT_QUOTES, "UTF-8") ?> → <?= htmlspecialchars($approvalRecord["approver_name"], ENT_QUOTES, "UTF-8") ?></strong><span><?= htmlspecialchars($approvalRecord["decision_note"] ?: $approvalRecord["request_note"] ?: "-", ENT_QUOTES, "UTF-8") ?></span></div></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="page-section">
            <div class="section-heading"><div><h2 data-i18n="revisionHistoryTitle">Revizyon Geçmişi</h2><p data-i18n="revisionHistoryText">Dokümana ait tüm kontrollü dosya sürümleri.</p></div></div>
            <?php if (!$versions): ?><div class="empty-state" data-i18n="noRevisionsText">Henüz dosya revizyonu yüklenmedi.</div><?php else: ?>
                <div class="revision-list">
                    <?php foreach ($versions as $version): ?>
                        <div class="revision-item"><div><strong>Rev. <?= htmlspecialchars($version["revision_number"], ENT_QUOTES, "UTF-8") ?> · <?= htmlspecialchars($version["original_file_name"], ENT_QUOTES, "UTF-8") ?></strong><span><?= htmlspecialchars($version["change_note"] ?: "-", ENT_QUOTES, "UTF-8") ?> · <?= number_format(((int) $version["file_size"]) / 1024, 1) ?> KB</span></div><?php if (in_array(strtolower(pathinfo($version["original_file_name"], PATHINFO_EXTENSION)), ["doc", "docx", "xls", "xlsx", "pdf"], true)): ?><a class="secondary-button" href="document-office.php?id=<?= $documentId ?>&amp;version=<?= (int) $version["id"] ?>" data-i18n="officeViewVersion">Ofiste Görüntüle</a><?php endif; ?><?php if ($version["mime_type"] === "text/html"): ?><a class="secondary-button" href="document-edit.php?id=<?= $documentId ?>&amp;version=<?= (int) $version["id"] ?>" data-i18n="editorView">Görüntüle</a><?php endif; ?><a class="secondary-button" href="document-download.php?id=<?= (int) $version["id"] ?>" data-i18n="downloadFileButton">İndir</a></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </main>
    <script src="assets/js/theme.js"></script><script src="assets/js/language.js"></script><script src="assets/js/sidebar.js"></script><script src="assets/js/pwa.js"></script>
</body>
</html>
