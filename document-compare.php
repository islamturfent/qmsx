<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/access.php';
require_once __DIR__ . '/includes/document-compare-functions.php';

$userId = (int) ($_SESSION["qms_user_id"] ?? 0);
$role = qmsCurrentRole();

$scope = qmsCompanyScope('companies.id', qmsVisibleCompanyIds($pdo, $userId, $role));
$docStmt = $pdo->prepare(
    "SELECT documents.*, companies.company_name
     FROM documents
     INNER JOIN companies ON companies.id = documents.company_id
     WHERE documents.active = 1" . $scope['sql'] . "
     ORDER BY documents.updated_at DESC, documents.created_at DESC"
);
$docStmt->execute($scope['params']);
$documents = $docStmt->fetchAll(PDO::FETCH_ASSOC);

/** Kapsam icinde belgenin revizyonlarini listeler. */
$selectedDoc = null;
$versions = [];
if (isset($_GET["document"])) {
    $docId = (int) $_GET["document"];
    foreach ($documents as $d) {
        if ((int) $d["id"] === $docId) { $selectedDoc = $d; break; }
    }
}
if ($selectedDoc) {
    $vStmt = $pdo->prepare(
        "SELECT document_versions.*, users.full_name AS uploader_name
         FROM document_versions LEFT JOIN users ON users.id = document_versions.uploaded_by
         WHERE document_versions.document_id = ? ORDER BY document_versions.id ASC"
    );
    $vStmt->execute([(int) $selectedDoc["id"]]);
    $versions = $vStmt->fetchAll(PDO::FETCH_ASSOC);
}


$activeNav = "documents";
$versionA = null;
$versionB = null;
$diffOps = [];
$htmlA = '';
$htmlB = '';
$textA = '';
$textB = '';

if ($selectedDoc && $versions) {
    $ids = array_column($versions, 'id');
    $aId = (int) ($_GET["a"] ?? 0);
    $bId = (int) ($_GET["b"] ?? 0);
    if (!in_array($aId, $ids, true) || !in_array($bId, $ids, true) || $aId === $bId) {
        $bId = (int) end($ids);      // en yeni
        $aId = count($ids) >= 2 ? (int) $ids[count($ids) - 2] : $bId; // bir onceki
    }
    foreach ($versions as $v) {
        $id = (int) $v["id"];
        if ($id === $aId) $versionA = $v;
        if ($id === $bId) $versionB = $v;
    }
    if ($versionA && $versionB) {
        $bodyA = qmsCompareVersionBody((string) $versionA["stored_file_name"]);
        $bodyB = qmsCompareVersionBody((string) $versionB["stored_file_name"]);
        $htmlA = $bodyA['html'];
        $htmlB = $bodyB['html'];
        $textA = $bodyA['text'];
        $textB = $bodyB['text'];
        $diffOps = qmsDiffLines(qmsCompareTextToLines($textA), qmsCompareTextToLines($textB));
    }
}

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f9fafb">
    <title>QMS Doküman Versiyon Karşılaştırma</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar">
        <div class="topbar-inner">
            <div class="page-title-block">
                <strong data-i18n="docCompareTitle">Versiyon Karşılaştırma</strong>
                <span data-i18n="docCompareText">Doküman revizyonlarını yan yana ve fark olarak inceleyin.</span>
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
                <span class="section-kicker" data-i18n="documentWorkspaceKicker">Doküman Çalışma Alanı</span>
                <h1 data-i18n="docCompareTitle">Versiyon Karşılaştırma</h1>
                <p data-i18n="docCompareText">Bir dokümanın iki revizyonunu yan yana görüntüleyin ve metin farkını inceleyin.</p>
            </div>
        </section>

        <section class="filter-panel">
            <form class="filter-form report-filter-form" method="get" action="document-compare.php">
                <div class="filter-grid">
                    <label class="form-field">
                        <span data-i18n="selectDocumentLabel">Doküman</span>
                        <select name="document" required onchange="this.form.submit()">
                            <option value="" data-i18n="selectDocumentOption">Doküman seçin</option>
                            <?php foreach ($documents as $d): ?>
                                <option value="<?= (int) $d["id"] ?>" <?= $selectedDoc && (int) $selectedDoc["id"] === (int) $d["id"] ? "selected" : "" ?>><?= htmlspecialchars($d["document_code"] . ' — ' . $d["title"], ENT_QUOTES, "UTF-8") ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <?php if ($selectedDoc && $versions): ?>
                        <label class="form-field">
                            <span data-i18n="versionALabel">Revizyon A</span>
                            <select name="a">
                                <?php foreach ($versions as $v): ?>
                                    <option value="<?= (int) $v["id"] ?>" <?= $versionA && (int) $versionA["id"] === (int) $v["id"] ? "selected" : "" ?>><?= htmlspecialchars($v["revision_number"], ENT_QUOTES, "UTF-8") ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="form-field">
                            <span data-i18n="versionBLabel">Revizyon B</span>
                            <select name="b">
                                <?php foreach ($versions as $v): ?>
                                    <option value="<?= (int) $v["id"] ?>" <?= $versionB && (int) $versionB["id"] === (int) $v["id"] ? "selected" : "" ?>><?= htmlspecialchars($v["revision_number"], ENT_QUOTES, "UTF-8") ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <div class="form-actions">
                            <button class="primary-button" type="submit" data-i18n="compareButton">Karşılaştır</button>
                        </div>
                    <?php endif; ?>
                </div>
            </form>
        </section>

        <?php if (!$selectedDoc): ?>
            <div class="empty-state" data-i18n="selectDocumentText">Karşılaştırmak için yukarıdan bir doküman seçin.</div>
        <?php elseif (!$versions): ?>
            <div class="empty-state" data-i18n="noVersionsText">Bu doküman için henüz revizyon yok.</div>
        <?php else: ?>
            <?php if ($selectedDoc): ?>
                <section class="dashboard-grid compact-dashboard-grid">
                    <div class="dashboard-card">
                        <div class="dashboard-card-content">
                            <span class="dashboard-card-label" data-i18n="docCompareDocumentLabel">Doküman</span>
                            <strong class="dashboard-card-number detail-card-value"><?= htmlspecialchars($selectedDoc["document_code"], ENT_QUOTES, "UTF-8") ?></strong>
                            <small><?= htmlspecialchars($selectedDoc["title"], ENT_QUOTES, "UTF-8") ?> · <?= htmlspecialchars($selectedDoc["company_name"], ENT_QUOTES, "UTF-8") ?></small>
                        </div>
                    </div>
                    <div class="dashboard-card">
                        <div class="dashboard-card-content">
                            <span class="dashboard-card-label" data-i18n="versionALabel">Revizyon A</span>
                            <strong class="dashboard-card-number detail-card-value"><?= htmlspecialchars($versionA["revision_number"], ENT_QUOTES, "UTF-8") ?></strong>
                            <small><?= htmlspecialchars($versionA["change_note"] ?: '-', ENT_QUOTES, "UTF-8") ?></small>
                        </div>
                    </div>
                    <div class="dashboard-card">
                        <div class="dashboard-card-content">
                            <span class="dashboard-card-label" data-i18n="versionBLabel">Revizyon B</span>
                            <strong class="dashboard-card-number detail-card-value"><?= htmlspecialchars($versionB["revision_number"], ENT_QUOTES, "UTF-8") ?></strong>
                            <small><?= htmlspecialchars($versionB["change_note"] ?: '-', ENT_QUOTES, "UTF-8") ?></small>
                        </div>
                    </div>
                </section>
            <?php endif; ?>

            <div class="form-actions export-actions">
                <a class="secondary-button" href="document-compare-export.php?document=<?= (int) $selectedDoc['id'] ?>&amp;a=<?= (int) $versionA['id'] ?>&amp;b=<?= (int) $versionB['id'] ?>&amp;format=csv" data-i18n="exportCsvButton">CSV İndir</a>
                <a class="primary-button" href="document-compare-export.php?document=<?= (int) $selectedDoc['id'] ?>&amp;a=<?= (int) $versionA['id'] ?>&amp;b=<?= (int) $versionB['id'] ?>&amp;format=pdf" data-i18n="exportPdfButton">PDF İndir</a>
            </div>

            <section class="console-card checklist-section">
                <div class="section-heading compact-heading">
                    <div>
                        <h3 data-i18n="sideBySideTitle">Yan Yana Görünüm</h3>
                        <p data-i18n="sideBySideText">Seçilen iki revizyonun içeriği.</p>
                    </div>
                </div>
                <div class="compare-grid">
                    <div class="compare-panel">
                        <div class="compare-panel-head"><strong data-i18n="versionALabel">Revizyon A</strong><span><?= htmlspecialchars($versionA["revision_number"], ENT_QUOTES, "UTF-8") ?></span></div>
                        <div class="compare-panel-body compare-doc-body"><?= $htmlA ?></div>
                    </div>
                    <div class="compare-panel">
                        <div class="compare-panel-head"><strong data-i18n="versionBLabel">Revizyon B</strong><span><?= htmlspecialchars($versionB["revision_number"], ENT_QUOTES, "UTF-8") ?></span></div>
                        <div class="compare-panel-body compare-doc-body"><?= $htmlB ?></div>
                    </div>
                </div>
            </section>

            <section class="console-card checklist-section">
                <div class="section-heading compact-heading">
                    <div>
                        <h3 data-i18n="diffTitle">Metin Farkı</h3>
                        <p data-i18n="diffText">Revizyon A'dan B'ye satır düzeyinde değişiklikler.</p>
                    </div>
                </div>
                <?php if (!$diffOps): ?>
                    <div class="empty-state" data-i18n="noDiffText">İki revizyonun metni aynı veya karşılaştırma için çok büyük.</div>
                <?php else: ?>
                    <div class="diff-view">
                        <?php foreach ($diffOps as $op): ?>
                            <div class="diff-line diff-<?= htmlspecialchars($op["type"], ENT_QUOTES, "UTF-8") ?>"><?= htmlspecialchars($op["text"], ENT_QUOTES, "UTF-8") ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </main>
    <script src="assets/js/theme.js"></script>
    <script src="assets/js/language.js"></script>
    <script src="assets/js/sidebar.js"></script>
    <script src="assets/js/pwa.js"></script>
</body>
</html>
