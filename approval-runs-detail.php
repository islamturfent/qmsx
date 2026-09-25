<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/access.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/approval-workflow-functions.php';

$runId = (int) ($_GET["id"] ?? 0);
if ($runId <= 0) {
    header("Location: approval-runs.php");
    exit;
}

$userId = (int) ($_SESSION["qms_user_id"] ?? 0);
$role = qmsCurrentRole();
$csrfScope = 'approval_run_detail';

$run = qmsApprovalRunFind($pdo, $runId, $userId, $role);
if (!$run) {
    header("Location: approval-runs.php");
    exit;
}

$steps = qmsApprovalSteps($pdo, $runId);
$current = qmsApprovalCurrentStep($pdo, $runId);
$canSign = $current !== [] && (int) $current["approver_user_id"] === $userId;

$statusLabels = qmsApprovalStatusLabels();
$statusI18n = qmsApprovalStatusI18nKeys();
$decisionLabels = qmsStepDecisionLabels();
$decisionI18n = qmsStepDecisionI18nKeys();

$formError = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    qmsCsrfVerify($csrfScope, $_POST["csrf"] ?? null);
    $formType = (string) ($_POST["form_type"] ?? "");
    if ($formType === "sign_step") {
        $decision = (string) ($_POST["decision"] ?? "");
        $ok = qmsApprovalSign($pdo, $runId, (int) $current["id"], $decision, (string) ($_POST["comment"] ?? ""), $userId, $role);
        if ($ok) {
            header("Location: approval-runs-detail.php?id=" . $runId . "&signed=1");
            exit;
        }
        $formError = "İmza işlemi gerçekleştirilemedi.";
    } else {
        $formError = "Geçersiz istek.";
    }
}

$steps = qmsApprovalSteps($pdo, $runId);
$current = qmsApprovalCurrentStep($pdo, $runId);
$canSign = $current !== [] && (int) $current["approver_user_id"] === $userId;

$activeNav = "approvals";

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f9fafb">
    <title>QMS Onay Akışı Detayı</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar">
        <div class="topbar-inner">
            <div class="page-title-block">
                <strong data-i18n="approvalRunDetailTitle">Onay Akışı Detayı</strong>
                <span><?= htmlspecialchars($run["company_name"], ENT_QUOTES, "UTF-8") ?> · <?= htmlspecialchars($run["subject"], ENT_QUOTES, "UTF-8") ?></span>
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
                <span class="section-kicker" data-i18n="approvalWorkflowKicker">Kayıt Onayı</span>
                <h1 data-i18n="approvalRunDetailTitle">Onay Akışı Detayı</h1>
                <p><?= htmlspecialchars($run["subject"], ENT_QUOTES, "UTF-8") ?> · <?= htmlspecialchars($statusLabels[$run["status"]] ?? $run["status"], ENT_QUOTES, "UTF-8") ?></p>
            </div>
            <a class="secondary-button" href="approval-runs.php" data-i18n="backToApprovalRunsButton">Onay Akışlarına Dön</a>
        </section>

        <?php if ($formError !== ""): ?><div class="form-message error"><?= htmlspecialchars($formError, ENT_QUOTES, "UTF-8") ?></div><?php endif; ?>
        <?php if (($_GET["created"] ?? "") === "1"): ?><div class="form-message success" data-i18n="approvalRunCreatedMessage">Onay akışı oluşturuldu.</div><?php endif; ?>
        <?php if (($_GET["signed"] ?? "") === "1"): ?><div class="form-message success" data-i18n="approvalRunSignedMessage">Adım imzalandı.</div><?php endif; ?>

        <section class="console-card">
            <div class="section-heading compact-heading">
                <div>
                    <h3 data-i18n="approvalRunInfoTitle">Akış Bilgileri</h3>
                    <p data-i18n="approvalRunInfoText">Konu, ilişkili kayıt ve genel durum.</p>
                </div>
            </div>
            <div class="admin-list">
                <div class="admin-list-item"><div><strong data-i18n="approvalSubjectLabel">Konu</strong><span><?= htmlspecialchars($run["subject"], ENT_QUOTES, "UTF-8") ?></span></div></div>
                <div class="admin-list-item"><div><strong data-i18n="approvalRunStatusLabel">Durum</strong><span class="status-pill" data-i18n="<?= $statusI18n[$run["status"]] ?? "" ?>"><?= htmlspecialchars($statusLabels[$run["status"]] ?? $run["status"], ENT_QUOTES, "UTF-8") ?></span></div></div>
                <?php if ($run["entity_type"]): ?><div class="admin-list-item"><div><strong data-i18n="approvalEntityTypeLabel">İlişkili Kayıt</strong><span><?= htmlspecialchars($run["entity_type"], ENT_QUOTES, "UTF-8") ?><?= $run["entity_id"] ? ' #' . (int) $run["entity_id"] : '' ?></span></div></div><?php endif; ?>
            </div>
        </section>

        <section class="console-card checklist-section">
            <div class="section-heading compact-heading">
                <div>
                    <h3 data-i18n="approvalStepsTimelineTitle">İmza Adımları</h3>
                    <p data-i18n="approvalStepsTimelineText">Her adım, atanan imzalayan tarafından sırayla onaylanır.</p>
                </div>
            </div>

            <?php if ($canSign): ?>
            <form class="auditor-form review-submit-form" method="post" action="approval-runs-detail.php?id=<?= $runId ?>">
                <?= qmsCsrfField($csrfScope) ?>
                <input type="hidden" name="form_type" value="sign_step">
                <div class="form-grid">
                    <label class="form-field form-field-wide"><span data-i18n="approvalSignCommentLabel">İmza Notu</span><input type="text" name="comment" maxlength="4000"></label>
                </div>
                <div class="form-actions">
                    <button class="primary-button" type="submit" name="decision" value="approved" data-i18n="approvalApproveButton">Onayla ve İmzala</button>
                    <button class="danger-button" type="submit" name="decision" value="rejected" data-i18n="approvalRejectButton">Reddet</button>
                </div>
            </form>
            <?php endif; ?>

            <div class="admin-list">
                <?php foreach ($steps as $i => $step): ?>
                    <div class="admin-list-item">
                        <div class="list-item-main">
                            <strong><?= $i + 1 ?>. <?= htmlspecialchars($step["step_name"], ENT_QUOTES, "UTF-8") ?></strong>
                            <span><?= htmlspecialchars($step["approver_name"], ENT_QUOTES, "UTF-8") ?><?= $step["signed_at"] ? ' · ' . htmlspecialchars($step["signed_at"], ENT_QUOTES, "UTF-8") : '' ?></span>
                            <?php if ($step["comment"]): ?><span class="list-item-date"><?= htmlspecialchars($step["comment"], ENT_QUOTES, "UTF-8") ?></span><?php endif; ?>
                        </div>
                        <div class="list-item-side">
                            <span class="status-pill" data-i18n="<?= $decisionI18n[$step["decision"]] ?? "" ?>"><?= htmlspecialchars($decisionLabels[$step["decision"]] ?? $step["decision"], ENT_QUOTES, "UTF-8") ?></span>
                        </div>
                    </div>
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
