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

$userId = (int) ($_SESSION["qms_user_id"] ?? 0);
$role = qmsCurrentRole();
$csrfScope = 'approval_run_create';

// Sirketler kapsamdan gelir.
$companyScope = qmsCompanyScope('companies.id', qmsVisibleCompanyIds($pdo, $userId, $role));
$companyStmt = $pdo->prepare("SELECT companies.id, companies.company_name FROM companies WHERE companies.active = 1" . $companyScope['sql'] . ' ORDER BY companies.company_name');
$companyStmt->execute($companyScope['params']);
$companies = $companyStmt->fetchAll(PDO::FETCH_ASSOC);
$allowedCompanyIds = array_map('intval', array_column($companies, 'id'));

$approvers = qmsApprovalApproverOptions($pdo);
$entityTypes = ['', 'document', 'nonconformity', 'supplier', 'contract', 'other'];

$formError = "";
$formData = [
    "company_id" => count($companies) === 1 ? (int) $companies[0]["id"] : 0,
    "subject" => "",
    "entity_type" => "",
    "entity_id" => "",
    "steps_name" => [],
    "steps_approver" => [],
];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    qmsCsrfVerify($csrfScope, $_POST["csrf"] ?? null);

    $formData = [
        "company_id" => (int) ($_POST["company_id"] ?? 0),
        "subject" => trim((string) ($_POST["subject"] ?? "")),
        "entity_type" => (string) ($_POST["entity_type"] ?? ""),
        "entity_id" => trim((string) ($_POST["entity_id"] ?? "")),
        "steps_name" => array_map('trim', (array) ($_POST["steps_name"] ?? [])),
        "steps_approver" => array_map('intval', (array) ($_POST["steps_approver"] ?? [])),
    ];

    $steps = [];
    for ($i = 0; $i < QMS_APPROVAL_MAX_STEPS; $i++) {
        $steps[] = [
            "step_name" => $formData["steps_name"][$i] ?? "",
            "approver_user_id" => (int) ($formData["steps_approver"][$i] ?? 0),
        ];
    }

    $newId = qmsApprovalCreateRun($pdo, [
        "company_id" => $formData["company_id"],
        "subject" => $formData["subject"],
        "entity_type" => $formData["entity_type"],
        "entity_id" => (int) $formData["entity_id"],
        "steps" => $steps,
    ], $userId, $role);

    if ($newId !== null) {
        header("Location: approval-runs-detail.php?id=" . $newId . "&created=1");
        exit;
    }
    $formError = "Akış oluşturulamadı. Geçerli bir şirket, konu ve en az bir adım (ad + imzalayan) girin.";
}

$activeNav = "approvals";

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f9fafb">
    <title>QuAmi Yeni Onay Akışı</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar">
        <div class="topbar-inner">
            <div class="page-title-block">
                <strong data-i18n="createApprovalRunTitle">Yeni Onay Akışı</strong>
                <span data-i18n="approvalRunCreateText">Sıralı onay/imza adımlarıyla bir onay akışı başlatın.</span>
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
                <h1 data-i18n="createApprovalRunTitle">Yeni Onay Akışı</h1>
                <p data-i18n="approvalRunCreateText">Konuyu ve sıralı imza adımlarını tanımlayın.</p>
            </div>
            <a class="secondary-button" href="approval-runs.php" data-i18n="backToApprovalRunsButton">Onay Akışlarına Dön</a>
        </section>
        <section class="form-panel">
            <?php if ($formError !== ""): ?><div class="form-message error"><?= htmlspecialchars($formError, ENT_QUOTES, "UTF-8") ?></div><?php endif; ?>
            <?php if (!$companies): ?><div class="form-message error" data-i18n="approvalRunNoCompanyText">Onay akışı için önce bir şirket gerekir.</div>
            <?php elseif (!$approvers): ?><div class="form-message error" data-i18n="approvalRunNoApproversText">İmzalayabilecek sistem admini yok.</div>
            <?php else: ?>
            <form class="auditor-form" method="post" action="approval-runs-create.php">
                <?= qmsCsrfField($csrfScope) ?>
                <div class="form-grid">
                    <label class="form-field"><span data-i18n="companySelectLabel">Şirket</span><select name="company_id" required><option value="0" data-i18n="selectCompanyOption">Şirket seçin</option><?php foreach ($companies as $company): ?><option value="<?= (int) $company["id"] ?>" <?= $formData["company_id"] === (int) $company["id"] ? "selected" : "" ?>><?= htmlspecialchars($company["company_name"], ENT_QUOTES, "UTF-8") ?></option><?php endforeach; ?></select></label>
                    <label class="form-field form-field-wide"><span data-i18n="approvalSubjectLabel">Konu</span><input type="text" name="subject" value="<?= htmlspecialchars($formData["subject"], ENT_QUOTES, "UTF-8") ?>" required maxlength="255"></label>
                    <label class="form-field"><span data-i18n="approvalEntityTypeLabel">İlişkili Kayıt Türü</span><select name="entity_type"><?php foreach ($entityTypes as $et): ?><?php if ($et === ""): ?><option value="" data-i18n="approvalNoEntityOption">— yok</option><?php else: ?><option value="<?= $et ?>" <?= $formData["entity_type"] === $et ? "selected" : "" ?>><?= htmlspecialchars(ucfirst($et), ENT_QUOTES, "UTF-8") ?></option><?php endif; ?><?php endforeach; ?></select></label>
                    <label class="form-field"><span data-i18n="approvalEntityIdLabel">Kayıt Numarası</span><input type="number" min="1" name="entity_id" value="<?= htmlspecialchars($formData["entity_id"], ENT_QUOTES, "UTF-8") ?>"></label>
                </div>

                <div class="section-heading compact-heading">
                    <div><h3 data-i18n="approvalStepsTitle">İmza Adımları</h3><p data-i18n="approvalStepsText">En fazla <?= QMS_APPROVAL_MAX_STEPS ?> adım tanımlayın; adımlar sırayla imzalanır.</p></div>
                </div>
                <?php for ($i = 0; $i < QMS_APPROVAL_MAX_STEPS; $i++): ?>
                    <div class="form-grid approval-step-row">
                        <label class="form-field"><span><?= $i + 1 ?>. <span data-i18n="approvalStepNameLabel">Adım Adı</span></span><input type="text" name="steps_name[]" maxlength="180" value="<?= htmlspecialchars($formData["steps_name"][$i] ?? "", ENT_QUOTES, "UTF-8") ?>"></label>
                        <label class="form-field"><span data-i18n="approvalStepApproverLabel">İmzalayan</span><select name="steps_approver[]"><option value="0" data-i18n="approvalSelectApproverOption">— seçin —</option><?php foreach ($approvers as $ap): ?><option value="<?= (int) $ap["id"] ?>" <?= (int) ($formData["steps_approver"][$i] ?? 0) === (int) $ap["id"] ? "selected" : "" ?>><?= htmlspecialchars($ap["full_name"], ENT_QUOTES, "UTF-8") ?></option><?php endforeach; ?></select></label>
                    </div>
                <?php endfor; ?>

                <div class="form-actions">
                    <button class="primary-button" type="submit" data-i18n="createApprovalRunSubmit">Akışı Başlat</button>
                </div>
            </form>
            <?php endif; ?>
        </section>
    </main>
    <script src="assets/js/theme.js"></script>
    <script src="assets/js/language.js"></script>
    <script src="assets/js/sidebar.js"></script>
    <script src="assets/js/pwa.js"></script>
</body>
</html>
