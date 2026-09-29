<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/access.php';
require_once __DIR__ . '/includes/permissions.php';
qmsRequirePermission('operations.view');
require_once __DIR__ . '/includes/capa-functions.php';
require_once __DIR__ . '/includes/root-cause-functions.php';
require_once __DIR__ . '/includes/csrf.php';

$userId = (int) ($_SESSION["qms_user_id"] ?? 0);
$role = qmsCurrentRole();

$ncId = (int) ($_GET["id"] ?? $_POST["nonconformity_id"] ?? 0);

$formError = "";
$csrfScope = 'root_cause';

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    qmsCsrfVerify($csrfScope, $_POST["csrf"] ?? null);
    $target = (int) ($_POST["nonconformity_id"] ?? 0);
    $nc = qmsNonconformityFind($pdo, $target, $userId, $role);
    if ($nc) {
        qmsRootCauseSave($pdo, $target, [
            'why1' => $_POST["why1"] ?? '', 'why2' => $_POST["why2"] ?? '', 'why3' => $_POST["why3"] ?? '',
            'why4' => $_POST["why4"] ?? '', 'why5' => $_POST["why5"] ?? '',
            'root_cause' => $_POST["root_cause"] ?? '',
            'corrective_action' => $_POST["corrective_action"] ?? '',
            'preventive_action' => $_POST["preventive_action"] ?? '',
            'status' => $_POST["status"] ?? 'open',
        ], $userId);
        header("Location: root-cause.php?id=" . $target . "&saved=1");
        exit;
    }
    $formError = "Uygunsuzluk bulunamadı veya kapsam dışı.";
}

// Sirket filtresi (liste gorunumu).
$companyScope = qmsCompanyScope('companies.id', qmsVisibleCompanyIds($pdo, $userId, $role));
$companyStmt = $pdo->prepare('SELECT companies.id, companies.company_name FROM companies WHERE companies.active = 1' . $companyScope['sql'] . ' ORDER BY companies.company_name');
$companyStmt->execute($companyScope['params']);
$companies = $companyStmt->fetchAll(PDO::FETCH_ASSOC);
$selectedCompanyId = (int) ($_GET["company_id"] ?? 0);
if (!in_array($selectedCompanyId, array_map('intval', array_column($companies, 'id')), true)) {
    $selectedCompanyId = 0;
}

// Edit gorunumu: secili uygunsuzluk.
$ncRecord = null;
$analysis = null;
if ($ncId > 0) {
    $ncRecord = qmsNonconformityFind($pdo, $ncId, $userId, $role);
    if (!$ncRecord) {
        $ncId = 0;
    } else {
        $analysis = qmsRootCauseFind($pdo, $ncId);
    }
}

$ncList = $ncId === 0 ? qmsRootCauseNcList($pdo, $userId, $role, $selectedCompanyId) : [];

$activeNav = "root_cause";

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f9fafb">
    <title>QuAmi Kök Neden Analizi</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar">
        <div class="topbar-inner">
            <div class="page-title-block">
                <strong data-i18n="rootCauseTitle">Kök Neden Analizi</strong>
                <span data-i18n="rootCauseText">Uygunsuzlukların kök nedenini 5 Neden ile belgeleyin.</span>
            </div>
            <div class="topbar-actions">
                <button class="topbar-button" id="languageToggle" type="button">EN</button>
                <button class="topbar-button" id="themeToggle" type="button" aria-label="Tema değiştir">🌙</button>
            </div>
        </div>
    </header>
    <main class="page-container">
        <section class="page-heading">
            <span class="section-kicker" data-i18n="rootCauseKicker">İyileştirme</span>
            <h1 data-i18n="rootCauseTitle">Kök Neden Analizi</h1>
            <p data-i18n="rootCauseText">Uygunsuzlukların kök nedenini 5 Neden ile belgeleyin.</p>
        </section>

        <?php if (isset($_GET["saved"])): ?>
            <div class="form-message success" data-i18n="rootCauseSavedMessage">Kök neden analizi kaydedildi.</div>
        <?php endif; ?>
        <?php if ($formError !== ""): ?>
            <div class="form-message error"><?= htmlspecialchars($formError, ENT_QUOTES, "UTF-8") ?></div>
        <?php endif; ?>

        <?php if ($ncRecord): ?>
            <section class="page-section console-card">
                <div class="section-heading compact-heading">
                    <div>
                        <h3><?= appIcon("sparkles", "heading-inline-icon") ?><?= htmlspecialchars($ncRecord["title"], ENT_QUOTES, "UTF-8") ?></h3>
                        <p><?= htmlspecialchars($ncRecord["company_name"] ?? "", ENT_QUOTES, "UTF-8") ?> · <?= htmlspecialchars($ncRecord["severity"] ?? "-", ENT_QUOTES, "UTF-8") ?> · <?= htmlspecialchars($ncRecord["status"] ?? "-", ENT_QUOTES, "UTF-8") ?></p>
                    </div>
                    <a class="secondary-button" href="root-cause.php" data-i18n="backToRootCauseListButton">Listeye Dön</a>
                </div>

                <form class="auditor-form" method="post" action="root-cause.php">
                    <?= qmsCsrfField($csrfScope) ?>
                    <input type="hidden" name="nonconformity_id" value="<?= (int) $ncRecord["id"] ?>">
                    <div class="form-grid">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <label class="form-field">
                                <span>Neden <?= $i ?></span>
                                <input type="text" name="why<?= $i ?>" value="<?= htmlspecialchars((string) ($analysis["why$i"] ?? ""), ENT_QUOTES, "UTF-8") ?>">
                            </label>
                        <?php endfor; ?>
                    </div>
                    <div class="form-grid">
                        <label class="form-field form-field-wide">
                            <span data-i18n="rootCauseFieldLabel">Kök Neden</span>
                            <textarea name="root_cause" rows="3"><?= htmlspecialchars((string) ($analysis["root_cause"] ?? ""), ENT_QUOTES, "UTF-8") ?></textarea>
                        </label>
                        <label class="form-field form-field-wide">
                            <span data-i18n="rootCauseCorrectiveLabel">Düzeltici Önlem</span>
                            <textarea name="corrective_action" rows="3"><?= htmlspecialchars((string) ($analysis["corrective_action"] ?? ""), ENT_QUOTES, "UTF-8") ?></textarea>
                        </label>
                        <label class="form-field form-field-wide">
                            <span data-i18n="rootCausePreventiveLabel">Önleyici Önlem</span>
                            <textarea name="preventive_action" rows="3"><?= htmlspecialchars((string) ($analysis["preventive_action"] ?? ""), ENT_QUOTES, "UTF-8") ?></textarea>
                        </label>
                    </div>
                    <div class="form-actions">
                        <button class="primary-button" type="submit" data-i18n="rootCauseSaveButton">Kaydet</button>
                        <a class="secondary-button" href="nonconformity-detail.php?id=<?= (int) $ncRecord["id"] ?>" data-i18n="openNcButton">Uygunsuzluğu Aç</a>
                    </div>
                </form>
            </section>
        <?php else: ?>
            <section class="page-section console-card">
                <div class="export-bar">
                    <a class="secondary-button" href="root-cause-export.php?company_id=<?= $selectedCompanyId ?>&format=xlsx" data-i18n="excelDownloadLabel">Excel İndir</a>
                    <a class="secondary-button" href="root-cause-export.php?company_id=<?= $selectedCompanyId ?>&format=pdf" data-i18n="pdfDownloadLabel">PDF İndir</a>
                </div>
                <div class="filter-tabs">
                    <form class="auditor-form finding-filter-form" method="get" action="root-cause.php">
                        <select name="company_id">
                            <option value="0" data-i18n="allCompaniesOption">Tüm Şirketler</option>
                            <?php foreach ($companies as $c): ?>
                                <option value="<?= (int) $c["id"] ?>" <?= $selectedCompanyId === (int) $c["id"] ? "selected" : "" ?>><?= htmlspecialchars($c["company_name"], ENT_QUOTES, "UTF-8") ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button class="primary-button primary-button-sm" type="submit" data-i18n="applyFiltersButton">Filtrele</button>
                    </form>
                </div>

                <?php if (!$ncList): ?>
                    <div class="empty-state" data-i18n="rootCauseEmpty">Analiz edilecek uygunsuzluk yok.</div>
                <?php else: ?>
                    <div class="admin-list">
                        <?php foreach ($ncList as $nc): ?>
                            <a class="admin-list-item" href="root-cause.php?id=<?= (int) $nc["id"] ?>">
                                <div class="list-item-main">
                                    <strong><?= htmlspecialchars($nc["title"], ENT_QUOTES, "UTF-8") ?></strong>
                                    <span><?= htmlspecialchars((string) $nc["company_name"] . " · " . (string) $nc["severity"], ENT_QUOTES, "UTF-8") ?></span>
                                </div>
                                <div class="list-item-side">
                                    <?php if (!empty($nc["rc_id"])): ?>
                                        <span class="status-pill <?= ($nc["rc_status"] ?? "") === "done" ? "" : "status-open" ?>"><?= ($nc["rc_status"] ?? "open") === "done" ? "Analiz tamam" : "Analiz var" ?></span>
                                    <?php else: ?>
                                        <span class="status-pill status-open" data-i18n="rootCausePendingBadge">Analiz yok</span>
                                    <?php endif; ?>
                                    <span class="record-card-cta"><?= !empty($nc["rc_id"]) ? "Düzenle" : "Analiz et" ?> →</span>
                                </div>
                            </a>
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
