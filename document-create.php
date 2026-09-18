<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';

$isSuperAdmin = ($_SESSION["qms_role"] ?? "") === "super_admin";
$userId = (int) ($_SESSION["qms_user_id"] ?? 0);
$scopeClause = "";
$scopeParams = [];
if (!$isSuperAdmin) {
    $assignmentStmt = $pdo->prepare("SELECT company_id FROM company_admin_assignments WHERE admin_user_id = :user_id AND active = 1");
    $assignmentStmt->execute(["user_id" => $userId]);
    $companyIds = array_map('intval', $assignmentStmt->fetchAll(PDO::FETCH_COLUMN));
    if ($companyIds) {
        $scopeClause = " AND companies.id IN (" . implode(',', array_fill(0, count($companyIds), '?')) . ")";
        $scopeParams = $companyIds;
    } else {
        $scopeClause = " AND 1 = 0";
    }
}

$companyStmt = $pdo->prepare("SELECT companies.id, companies.company_name FROM companies WHERE companies.active = 1" . $scopeClause . " ORDER BY companies.company_name");
$companyStmt->execute($scopeParams);
$companies = $companyStmt->fetchAll(PDO::FETCH_ASSOC);
$allowedCompanyIds = array_map('intval', array_column($companies, 'id'));

$formError = "";
$formData = [
    "company_id" => 0,
    "document_code" => "",
    "title" => "",
    "category" => "",
    "owner_name" => "",
    "current_revision" => "01",
    "status" => "draft",
    "effective_date" => "",
    "review_date" => "",
    "description" => "",
    "change_note" => "İlk yayın"
];
$allowedFiles = [
    "pdf" => ["application/pdf"],
    "doc" => ["application/msword", "application/octet-stream"],
    "docx" => ["application/vnd.openxmlformats-officedocument.wordprocessingml.document", "application/zip", "application/octet-stream"],
    "xls" => ["application/vnd.ms-excel", "application/octet-stream"],
    "xlsx" => ["application/vnd.openxmlformats-officedocument.spreadsheetml.sheet", "application/zip", "application/octet-stream"]
];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $formData = [
        "company_id" => (int) ($_POST["company_id"] ?? 0),
        "document_code" => strtoupper(trim($_POST["document_code"] ?? "")),
        "title" => trim($_POST["title"] ?? ""),
        "category" => trim($_POST["category"] ?? ""),
        "owner_name" => trim($_POST["owner_name"] ?? ""),
        "current_revision" => trim($_POST["current_revision"] ?? "01"),
        "status" => "draft",
        "effective_date" => trim($_POST["effective_date"] ?? ""),
        "review_date" => trim($_POST["review_date"] ?? ""),
        "description" => trim($_POST["description"] ?? ""),
        "change_note" => trim($_POST["change_note"] ?? "")
    ];

    if (!in_array($formData["company_id"], $allowedCompanyIds, true)) {
        $formError = "Geçerli bir şirket seçin.";
    } elseif ($formData["document_code"] === "" || $formData["title"] === "") {
        $formError = "Doküman kodu ve başlık zorunludur.";
    } elseif ($formData["current_revision"] === "") {
        $formError = "Revizyon numarası zorunludur.";
    } elseif ($formData["effective_date"] !== "" && $formData["review_date"] !== "" && $formData["review_date"] < $formData["effective_date"]) {
        $formError = "Gözden geçirme tarihi yürürlük tarihinden önce olamaz.";
    }

    $upload = $_FILES["document_file"] ?? null;
    $hasUpload = $upload && $upload["error"] !== UPLOAD_ERR_NO_FILE;
    $uploadData = null;

    if ($formError === "" && $hasUpload) {
        if ($upload["error"] !== UPLOAD_ERR_OK || $upload["size"] > 10 * 1024 * 1024) {
            $formError = "Dosya yüklenemedi veya 10 MB sınırını aşıyor.";
        } else {
            $extension = strtolower(pathinfo($upload["name"], PATHINFO_EXTENSION));
            $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($upload["tmp_name"]);
            if (!isset($allowedFiles[$extension]) || !in_array($mimeType, $allowedFiles[$extension], true)) {
                $formError = "Yalnız PDF, Word ve Excel dosyaları yüklenebilir.";
            } else {
                $uploadData = [
                    "extension" => $extension,
                    "mime_type" => $mimeType,
                    "stored_name" => bin2hex(random_bytes(20)) . "." . $extension
                ];
            }
        }
    }

    if ($formError === "") {
        $storedPath = null;
        try {
            $pdo->beginTransaction();
            $insertStmt = $pdo->prepare(
                "INSERT INTO documents
                    (company_id, document_code, title, category, owner_name, status, current_revision,
                     effective_date, review_date, description, active, created_by)
                 VALUES
                    (:company_id, :document_code, :title, :category, :owner_name, :status, :current_revision,
                     :effective_date, :review_date, :description, 1, :created_by)"
            );
            $insertStmt->execute([
                "company_id" => $formData["company_id"],
                "document_code" => $formData["document_code"],
                "title" => $formData["title"],
                "category" => $formData["category"] !== "" ? $formData["category"] : null,
                "owner_name" => $formData["owner_name"] !== "" ? $formData["owner_name"] : null,
                "status" => $formData["status"],
                "current_revision" => $formData["current_revision"],
                "effective_date" => $formData["effective_date"] !== "" ? $formData["effective_date"] : null,
                "review_date" => $formData["review_date"] !== "" ? $formData["review_date"] : null,
                "description" => $formData["description"] !== "" ? $formData["description"] : null,
                "created_by" => $userId ?: null
            ]);
            $documentId = (int) $pdo->lastInsertId();

            if ($uploadData) {
                $storageDir = __DIR__ . DIRECTORY_SEPARATOR . "storage" . DIRECTORY_SEPARATOR . "documents";
                $storedPath = $storageDir . DIRECTORY_SEPARATOR . $uploadData["stored_name"];
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
                    "revision_number" => $formData["current_revision"],
                    "original_file_name" => basename($upload["name"]),
                    "stored_file_name" => $uploadData["stored_name"],
                    "mime_type" => $uploadData["mime_type"],
                    "file_size" => (int) $upload["size"],
                    "change_note" => $formData["change_note"] !== "" ? $formData["change_note"] : null,
                    "uploaded_by" => $userId ?: null
                ]);
            }

            $pdo->commit();
            header("Location: documents.php?document=created");
            exit;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($storedPath && is_file($storedPath)) unlink($storedPath);
            $formError = $error instanceof PDOException && $error->getCode() === "23000"
                ? "Bu şirket için aynı doküman kodu veya revizyon zaten kullanılıyor."
                : "Doküman kaydedilemedi.";
        }
    }
}

$statusLabels = ["draft" => "Taslak", "review" => "İncelemede", "approved" => "Onaylandı", "published" => "Yayında", "archived" => "Arşivlendi"];
$activeNav = "documents";

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="theme-color" content="#f9fafb">
    <title>QMS Yeni Doküman</title><link rel="manifest" href="manifest.webmanifest"><link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml"><link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar"><div class="topbar-inner"><div class="page-title-block"><strong data-i18n="createDocumentTitle">Yeni Doküman</strong><span data-i18n="createDocumentText">Kontrollü dokümanın temel bilgilerini ve ilk revizyonunu oluşturun.</span></div><div class="topbar-actions"><button class="topbar-button" id="languageToggle" type="button">EN</button><button class="topbar-button" id="themeToggle" type="button" aria-label="Tema değiştir">🌙</button></div></div></header>
    <main class="page-container narrow-page">
        <section class="page-heading page-heading-actions">
            <div>
                <span class="section-kicker" data-i18n="documentManagementTitle">Doküman Yönetimi</span>
                <h1 data-i18n="createDocumentTitle">Yeni Doküman</h1>
                <p data-i18n="createDocumentText">Kontrollü dokümanın temel bilgilerini ve ilk revizyonunu oluşturun.</p>
            </div>
            <a class="secondary-button" href="documents.php" data-i18n="backToDocumentsButton">Dokümanlara Dön</a>
        </section>
        <section class="form-panel">
            <?php if ($formError !== ""): ?><div class="form-message error"><?= htmlspecialchars($formError, ENT_QUOTES, "UTF-8") ?></div><?php endif; ?>
            <form class="auditor-form" method="post" action="document-create.php" enctype="multipart/form-data">
                <div class="form-grid">
                    <label class="form-field form-field-wide"><span data-i18n="companySelectLabel">Şirket</span><select name="company_id" required><option value="">Şirket seçin</option><?php foreach ($companies as $company): ?><option value="<?= (int) $company["id"] ?>" <?= (int) $formData["company_id"] === (int) $company["id"] ? "selected" : "" ?>><?= htmlspecialchars($company["company_name"], ENT_QUOTES, "UTF-8") ?></option><?php endforeach; ?></select></label>
                    <label class="form-field"><span data-i18n="documentCodeLabel">Doküman Kodu</span><input type="text" name="document_code" value="<?= htmlspecialchars($formData["document_code"], ENT_QUOTES, "UTF-8") ?>" required></label>
                    <label class="form-field"><span data-i18n="documentTitleLabel">Doküman Başlığı</span><input type="text" name="title" value="<?= htmlspecialchars($formData["title"], ENT_QUOTES, "UTF-8") ?>" required></label>
                    <label class="form-field"><span data-i18n="documentCategoryLabel">Kategori</span><input type="text" name="category" value="<?= htmlspecialchars($formData["category"], ENT_QUOTES, "UTF-8") ?>"></label>
                    <label class="form-field"><span data-i18n="documentOwnerLabel">Doküman Sorumlusu</span><input type="text" name="owner_name" value="<?= htmlspecialchars($formData["owner_name"], ENT_QUOTES, "UTF-8") ?>"></label>
                    <label class="form-field"><span data-i18n="revisionLabel">Revizyon</span><input type="text" name="current_revision" value="<?= htmlspecialchars($formData["current_revision"], ENT_QUOTES, "UTF-8") ?>" required></label>
                    <label class="form-field"><span data-i18n="effectiveDateLabel">Yürürlük Tarihi</span><input type="date" name="effective_date" value="<?= htmlspecialchars($formData["effective_date"], ENT_QUOTES, "UTF-8") ?>"></label>
                    <label class="form-field"><span data-i18n="reviewDateLabel">Gözden Geçirme Tarihi</span><input type="date" name="review_date" value="<?= htmlspecialchars($formData["review_date"], ENT_QUOTES, "UTF-8") ?>"></label>
                    <label class="form-field form-field-wide"><span data-i18n="descriptionLabel">Açıklama</span><textarea name="description" rows="4"><?= htmlspecialchars($formData["description"], ENT_QUOTES, "UTF-8") ?></textarea></label>
                    <label class="form-field form-field-wide"><span data-i18n="documentFileLabel">İlk Revizyon Dosyası</span><input type="file" name="document_file" accept=".pdf,.doc,.docx,.xls,.xlsx"><small data-i18n="documentFileHelp">PDF, Word veya Excel; en fazla 10 MB.</small></label>
                    <label class="form-field form-field-wide"><span data-i18n="changeNoteLabel">Revizyon Notu</span><textarea name="change_note" rows="3"><?= htmlspecialchars($formData["change_note"], ENT_QUOTES, "UTF-8") ?></textarea></label>
                </div>
                <div class="form-actions"><button class="primary-button" type="submit" data-i18n="saveDocumentButton">Dokümanı Kaydet</button></div>
            </form>
        </section>
    </main>
    <script src="assets/js/theme.js"></script><script src="assets/js/language.js"></script><script src="assets/js/sidebar.js"></script><script src="assets/js/pwa.js"></script>
</body>
</html>
