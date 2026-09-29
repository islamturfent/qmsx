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
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/document-copy-functions.php';

$userId = (int) ($_SESSION["qms_user_id"] ?? 0);
$role = qmsCurrentRole();
$csrfScope = 'doc_copy_tracking';

$formError = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    qmsCsrfVerify($csrfScope, $_POST["csrf"] ?? null);
    if (($_POST["form_type"] ?? "") === "confirm") {
        $copyId = (int) ($_POST["id"] ?? 0);
        $signedBy = trim((string) ($_POST["signed_by"] ?? ""));
        if ($copyId > 0 && $signedBy !== "" && qmsDocumentCopyConfirm($pdo, $copyId, $signedBy, $userId, $role)) {
            header("Location: document-distribution-tracking.php?confirmed=1");
            exit;
        }
        $formError = "Teslim onayı kaydedilemedi. Geçerli bir onaylayan adı girin.";
    }
}

$copies = qmsDocumentCopyList($pdo, $userId, $role);
$statusLabels = qmsCopyStatusLabels();

$pendingCount = 0;
$confirmedCount = 0;
foreach ($copies as $c) {
    if ((int) ($c['received_confirmed'] ?? 0) === 1) {
        $confirmedCount++;
    } else {
        $pendingCount++;
    }
}

$activeNav = "document_tracking";

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f9fafb">
    <title>QuAmi Dağıtım & İmza Takibi</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar">
        <div class="topbar-inner">
            <div class="page-title-block">
                <strong data-i18n="docTrackingTitle">Dağıtım & İmza Takibi</strong>
                <span data-i18n="docTrackingText">Dağıtılan dokümanların teslim/onay durumunu izleyin.</span>
            </div>
            <div class="topbar-actions">
                <button class="topbar-button" id="languageToggle" type="button">EN</button>
                <button class="topbar-button" id="themeToggle" type="button" aria-label="Tema değiştir">🌙</button>
            </div>
        </div>
    </header>
    <main class="page-container">
        <section class="page-heading">
            <span class="section-kicker" data-i18n="docTrackingKicker">Doküman Kontrolü</span>
            <h1 data-i18n="docTrackingTitle">Dağıtım & İmza Takibi</h1>
            <p data-i18n="docTrackingText">Dağıtılan dokümanların teslim/onay durumunu izleyin.</p>
        </section>

        <section class="dashboard-grid compact-dashboard-grid">
            <div class="dashboard-card metric-blue">
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="docTrackingTotal">Toplam Kopya</span>
                    <strong class="dashboard-card-number"><?= count($copies) ?></strong>
                </div>
            </div>
            <div class="dashboard-card metric-orange">
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="docTrackingPending">Onay Bekleyen</span>
                    <strong class="dashboard-card-number"><?= $pendingCount ?></strong>
                </div>
            </div>
            <div class="dashboard-card metric-green">
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="docTrackingConfirmed">Teslim Alındı</span>
                    <strong class="dashboard-card-number"><?= $confirmedCount ?></strong>
                </div>
            </div>
        </section>

        <section class="page-section console-card">
            <div class="section-heading compact-heading">
                <div><h3 data-i18n="docTrackingListTitle">Dağıtılan Kopyalar</h3><p data-i18n="docTrackingListText">Alıcı bazında onay durumunu güncelleyin ve imzalı teslimi işaretleyin.</p></div>
                <a class="secondary-button" href="document-distribution.php" data-i18n="documentDistributionMenuLabel">Dağıtım Kontrolü</a>
            </div>

            <?php if ($formError !== ""): ?><div class="form-message error"><?= htmlspecialchars($formError, ENT_QUOTES, "UTF-8") ?></div><?php endif; ?>
            <?php if (($_GET["confirmed"] ?? "") === "1"): ?><div class="form-message success" data-i18n="docTrackingConfirmedMsg">Teslim alındı olarak kaydedildi.</div><?php endif; ?>

            <?php if (!$copies): ?>
                <div class="empty-state" data-i18n="docTrackingEmpty">Henüz dağıtılmış kontrollü kopya yok.</div>
            <?php else: ?>
                <div class="table-wrapper">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th data-i18n="docTrackingDocCol">Doküman</th>
                                <th data-i18n="docTrackingCopyCol">Kopya No</th>
                                <th data-i18n="docTrackingRecipientCol">Alıcı</th>
                                <th data-i18n="docTrackingStatusCol">Durum</th>
                                <th data-i18n="docTrackingConfirmCol">Teslim / Onay</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($copies as $c): ?>
                                <?php $confirmed = (int) ($c['received_confirmed'] ?? 0) === 1; ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($c["document_title"], ENT_QUOTES, "UTF-8") ?></strong><br><small><?= htmlspecialchars((string) $c["document_code"] . " · " . (string) $c["company_name"], ENT_QUOTES, "UTF-8") ?></small></td>
                                    <td><?= htmlspecialchars($c["copy_no"], ENT_QUOTES, "UTF-8") ?></td>
                                    <td><?= htmlspecialchars($c["recipient_name"], ENT_QUOTES, "UTF-8") ?><?= $c["location"] ? '<br><small>' . htmlspecialchars((string) $c["location"], ENT_QUOTES, "UTF-8") . '</small>' : '' ?></td>
                                    <td><span class="status-pill"><?= htmlspecialchars($statusLabels[$c["status"]] ?? $c["status"], ENT_QUOTES, "UTF-8") ?></span></td>
                                    <td>
                                        <?php if ($confirmed): ?>
                                            <span class="status-pill"><?= htmlspecialchars((string) $c["signed_by"] . " · " . (string) $c["received_on"], ENT_QUOTES, "UTF-8") ?></span>
                                        <?php else: ?>
                                            <form class="inline-cert-upload" method="post" action="document-distribution-tracking.php">
                                                <?= qmsCsrfField($csrfScope) ?>
                                                <input type="hidden" name="form_type" value="confirm">
                                                <input type="hidden" name="id" value="<?= (int) $c["id"] ?>">
                                                <input type="text" name="signed_by" placeholder="<?= htmlspecialchars($signedByPlaceholder ?? 'Onaylayan', ENT_QUOTES, "UTF-8") ?>" required>
                                                <button class="primary-button primary-button-sm" type="submit" data-i18n="docTrackingConfirmButton">Teslim Al</button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </main>
    <script src="assets/js/theme.js"></script>
    <script src="assets/js/language.js"></script>
    <script src="assets/js/sidebar.js"></script>
    <script src="assets/js/pwa.js"></script>
</body>
</html>
