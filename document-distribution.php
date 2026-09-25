<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/access.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/document-copy-functions.php';

$userId = (int) ($_SESSION["qms_user_id"] ?? 0);
$role = qmsCurrentRole();
$csrfScope = 'document_distribution';

// Ekleme formu icin kapsam icindeki dokümanlar.
$docScope = qmsCompanyScope('documents.company_id', qmsVisibleCompanyIds($pdo, $userId, $role));
$docStmt = $pdo->prepare(
    "SELECT documents.id, documents.document_code, documents.title, documents.company_id,
            companies.company_name
     FROM documents INNER JOIN companies ON companies.id = documents.company_id
     WHERE documents.active = 1" . $docScope['sql'] . ' ORDER BY documents.document_code'
);
$docStmt->execute($docScope['params']);
$documents = $docStmt->fetchAll(PDO::FETCH_ASSOC);
$allowedDocumentIds = array_map('intval', array_column($documents, 'id'));

$filterStatus = (string) ($_GET["status"] ?? "");
$list = qmsDocumentCopyList($pdo, $userId, $role, $filterStatus);
$all = qmsDocumentCopyList($pdo, $userId, $role);

$statusLabels = qmsCopyStatusLabels();
$statusI18n = qmsCopyStatusI18nKeys();

$formError = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    qmsCsrfVerify($csrfScope, $_POST["csrf"] ?? null);
    $formType = (string) ($_POST["form_type"] ?? "");

    if ($formType === "add_copy") {
        $newId = qmsDocumentCopyAdd($pdo, [
            "document_id" => (int) ($_POST["document_id"] ?? 0),
            "copy_no" => (string) ($_POST["copy_no"] ?? ""),
            "recipient_name" => (string) ($_POST["recipient_name"] ?? ""),
            "location" => (string) ($_POST["location"] ?? ""),
            "distributed_on" => (string) ($_POST["distributed_on"] ?? ""),
            "notes" => (string) ($_POST["notes"] ?? ""),
        ], $userId, $role);

        if ($newId !== null) {
            header("Location: document-distribution.php?added=1");
            exit;
        }
        $formError = "Kopya eklenemedi. Geçerli bir doküman, kopya numarası, alıcı ve tarih girin.";
    } elseif ($formType === "update_copy_status") {
        $copyId = (int) ($_POST["copy_id"] ?? 0);
        $status = (string) ($_POST["copy_status"] ?? "");
        $returnDate = trim((string) ($_POST["returned_on"] ?? ""));
        if ($returnDate !== "" && preg_match('/^\d{4}-\d{2}-\d{2}$/', $returnDate) !== 1) {
            $returnDate = "";
        }
        qmsDocumentCopyUpdateStatus($pdo, $copyId, $status, $returnDate !== "" ? $returnDate : null, $userId, $role);
        header("Location: document-distribution.php?status=" . urlencode($filterStatus) . "&updated=1");
        exit;
    } else {
        $formError = "Geçersiz istek.";
    }
}

$summary = ['total' => count($all), 'distributed' => 0, 'returned' => 0, 'obsolete' => 0];
foreach ($all as $copy) {
    if (isset($summary[$copy["status"]])) {
        $summary[$copy["status"]]++;
    }
}

$activeNav = "document_copies";

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f9fafb">
    <title>QMS Doküman Dağıtım Kontrolü</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar">
        <div class="topbar-inner">
            <div class="page-title-block">
                <strong data-i18n="documentDistributionTitle">Doküman Dağıtım Kontrolü</strong>
                <span data-i18n="documentDistributionText">Kontrollü kopyaları ve dağıtım durumunu izleyin.</span>
            </div>
            <div class="topbar-actions">
                <button class="topbar-button" id="languageToggle" type="button">EN</button>
                <button class="topbar-button" id="themeToggle" type="button" aria-label="Tema değiştir">🌙</button>
            </div>
        </div>
    </header>
    <main class="page-container">
        <section class="page-heading">
            <div>
                <span class="section-kicker" data-i18n="documentDistributionKicker">Doküman Kontrolü</span>
                <h1 data-i18n="documentDistributionTitle">Doküman Dağıtım Kontrolü</h1>
                <p data-i18n="documentDistributionText">Hangi dokümanın kime, hangi kopya numarasıyla dağıtıldığını ve iade durumunu takip edin.</p>
            </div>
        </section>

        <section class="dashboard-grid compact-dashboard-grid">
            <div class="dashboard-card metric-blue">
                <?= appIcon("documents", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="copyTotalLabel">Toplam Kopya</span>
                    <strong class="dashboard-card-number detail-card-value"><?= $summary["total"] ?></strong>
                </div>
            </div>
            <div class="dashboard-card metric-teal">
                <?= appIcon("check", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="copyDistributedLabel">Dağıtılmış</span>
                    <strong class="dashboard-card-number detail-card-value"><?= $summary["distributed"] ?></strong>
                </div>
            </div>
            <div class="dashboard-card metric-violet">
                <?= appIcon("check", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="copyReturnedLabel">İade Edilen</span>
                    <strong class="dashboard-card-number detail-card-value"><?= $summary["returned"] ?></strong>
                </div>
            </div>
            <div class="dashboard-card metric-orange">
                <?= appIcon("warning", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="copyObsoleteLabel">Geçersiz</span>
                    <strong class="dashboard-card-number detail-card-value"><?= $summary["obsolete"] ?></strong>
                </div>
            </div>
        </section>

        <section class="filter-bar">
            <a class="<?= $filterStatus === "" ? "filter-pill active" : "filter-pill" ?>" href="document-distribution.php" data-i18n="copyAllFilter">Tümü</a>
            <a class="<?= $filterStatus === "distributed" ? "filter-pill active" : "filter-pill" ?>" href="document-distribution.php?status=distributed" data-i18n="copyDistributedFilter">Dağıtılmış</a>
            <a class="<?= $filterStatus === "returned" ? "filter-pill active" : "filter-pill" ?>" href="document-distribution.php?status=returned" data-i18n="copyReturnedFilter">İade</a>
            <a class="<?= $filterStatus === "obsolete" ? "filter-pill active" : "filter-pill" ?>" href="document-distribution.php?status=obsolete" data-i18n="copyObsoleteFilter">Geçersiz</a>
        </section>

        <?php if ($formError !== ""): ?><div class="form-message error"><?= htmlspecialchars($formError, ENT_QUOTES, "UTF-8") ?></div><?php endif; ?>
        <?php if (($_GET["added"] ?? "") === "1"): ?><div class="form-message success" data-i18n="copyAddedMessage">Kopya kaydı eklendi.</div><?php endif; ?>
        <?php if (($_GET["updated"] ?? "") === "1"): ?><div class="form-message success" data-i18n="copyUpdatedMessage">Kopya durumu güncellendi.</div><?php endif; ?>

        <div class="two-col">
            <section class="form-panel">
                <div class="section-heading compact-heading">
                    <div>
                        <h3 data-i18n="copyAddTitle">Kontrollü Kopya Ekle</h3>
                        <p data-i18n="copyAddText">Bir dokümanın kontrollü kopyasını dağıtıma kaydedin.</p>
                    </div>
                </div>
                <?php if (!$documents): ?>
                    <div class="form-message error" data-i18n="copyNoDocumentText">Kopya eklemek için önce bir doküman gerekir.</div>
                <?php else: ?>
                <form class="auditor-form" method="post" action="document-distribution.php">
                    <?= qmsCsrfField($csrfScope) ?>
                    <input type="hidden" name="form_type" value="add_copy">
                    <div class="form-grid">
                        <label class="form-field form-field-wide"><span data-i18n="copyDocumentSelectLabel">Doküman</span><select name="document_id" required><option value="0" data-i18n="copySelectDocumentOption">Doküman seçin</option><?php foreach ($documents as $doc): ?><option value="<?= (int) $doc["id"] ?>"><?= htmlspecialchars($doc["document_code"] . " — " . $doc["title"], ENT_QUOTES, "UTF-8") ?></option><?php endforeach; ?></select></label>
                        <label class="form-field"><span data-i18n="copyNoLabel">Kopya No</span><input type="text" name="copy_no" required maxlength="40"></label>
                        <label class="form-field"><span data-i18n="copyRecipientLabel">Alıcı</span><input type="text" name="recipient_name" required maxlength="180"></label>
                        <label class="form-field"><span data-i18n="copyLocationLabel">Konum</span><input type="text" name="location" maxlength="180"></label>
                        <label class="form-field"><span data-i18n="copyDistributedDateLabel">Dağıtım Tarihi</span><input type="date" name="distributed_on" value="<?= date("Y-m-d") ?>" required></label>
                        <label class="form-field form-field-wide"><span data-i18n="copyNotesLabel">Notlar</span><input type="text" name="notes" maxlength="4000"></label>
                    </div>
                    <div class="form-actions">
                        <button class="primary-button" type="submit" data-i18n="addCopyButton">Kopya Ekle</button>
                    </div>
                </form>
                <?php endif; ?>
            </section>

            <section class="console-card checklist-section">
                <div class="section-heading compact-heading">
                    <div>
                        <h3 data-i18n="copyListTitle">Dağıtılmış Kopyalar</h3>
                        <p><span data-i18n="filteredRecordsLabel">Gösterilen kayıt</span>: <strong><?= count($list) ?></strong></p>
                    </div>
                </div>
                <div class="admin-list">
                    <?php if (!$list): ?>
                        <div class="empty-state" data-i18n="noCopiesText">Filtrelere uygun kopya kaydı bulunamadı.</div>
                    <?php endif; ?>
                    <?php foreach ($list as $copy): ?>
                        <div class="admin-list-item">
                            <div class="list-item-main">
                                <strong><?= htmlspecialchars($copy["copy_no"], ENT_QUOTES, "UTF-8") ?> · <?= htmlspecialchars($copy["recipient_name"], ENT_QUOTES, "UTF-8") ?></strong>
                                <a href="document-detail.php?id=<?= (int) $copy["document_id"] ?>">
                                    <span><?= htmlspecialchars($copy["document_code"], ENT_QUOTES, "UTF-8") ?> — <?= htmlspecialchars($copy["document_title"], ENT_QUOTES, "UTF-8") ?></span>
                                </a>
                            </div>
                            <div class="list-item-side">
                                <span class="status-pill" data-i18n="<?= $statusI18n[$copy["status"]] ?? "" ?>"><?= htmlspecialchars($statusLabels[$copy["status"]] ?? $copy["status"], ENT_QUOTES, "UTF-8") ?></span>
                                <span class="list-item-date"><?= htmlspecialchars($copy["distributed_on"] ?: "-", ENT_QUOTES, "UTF-8") ?></span>
                                <form method="post" action="document-distribution.php?status=<?= urlencode($filterStatus) ?>" onsubmit="return confirm('Kopya durumunu güncellesin mi?');">
                                    <?= qmsCsrfField($csrfScope) ?>
                                    <input type="hidden" name="form_type" value="update_copy_status">
                                    <input type="hidden" name="copy_id" value="<?= (int) $copy["id"] ?>">
                                    <select name="copy_status" onchange="this.form.submit()">
                                        <?php foreach ($statusLabels as $key => $label): ?>
                                            <option value="<?= $key ?>" <?= $copy["status"] === $key ? "selected" : "" ?>><?= htmlspecialchars($label, ENT_QUOTES, "UTF-8") ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="hidden" name="returned_on" value="<?= ($copy["status"] === "returned" && $copy["returned_on"]) ? htmlspecialchars($copy["returned_on"], ENT_QUOTES, "UTF-8") : date("Y-m-d") ?>">
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>
    </main>
    <script src="assets/js/theme.js"></script>
    <script src="assets/js/language.js"></script>
    <script src="assets/js/sidebar.js"></script>
    <script src="assets/js/pwa.js"></script>
</body>
</html>
