<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/access.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/process-functions.php';

$userId = (int) ($_SESSION["qms_user_id"] ?? 0);
$role = qmsCurrentRole();
if (qmsIsAuditor()) {
    header("Location: my-audits.php");
    exit;
}

$scope = qmsCompanyScope('companies.id', qmsVisibleCompanyIds($pdo, $userId, $role));
$companyStmt = $pdo->prepare('SELECT companies.id, companies.company_name FROM companies WHERE companies.active = 1' . $scope['sql'] . ' ORDER BY companies.company_name');
$companyStmt->execute($scope['params']);
$companies = $companyStmt->fetchAll(PDO::FETCH_ASSOC);

$csrfScope = 'processes';
$formError = '';
$editing = null;
$editId = (int) ($_GET['edit'] ?? 0);
if ($editId > 0) {
    $editing = qmsProcessFind($pdo, $editId, $userId, $role);
    if (!$editing) {
        $editId = 0;
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    qmsCsrfVerify($csrfScope, $_POST["csrf"] ?? null);
    $formType = (string) ($_POST["form_type"] ?? "");

    if ($formType === "add" || $formType === "update") {
        $data = [
            "process_code" => (string) ($_POST["process_code"] ?? ""),
            "process_name" => (string) ($_POST["process_name"] ?? ""),
            "department" => (string) ($_POST["department"] ?? ""),
            "owner_name" => (string) ($_POST["owner_name"] ?? ""),
            "objective" => (string) ($_POST["objective"] ?? ""),
            "inputs" => (string) ($_POST["inputs"] ?? ""),
            "outputs" => (string) ($_POST["outputs"] ?? ""),
            "kpi" => (string) ($_POST["kpi"] ?? ""),
            "review_date" => (string) ($_POST["review_date"] ?? ""),
            "status" => (string) ($_POST["status"] ?? "active"),
        ];
        if ($formType === "add") {
            $data["company_id"] = (int) ($_POST["company_id"] ?? 0);
            $newId = qmsProcessAdd($pdo, $data, $userId, $role);
            if ($newId !== null) {
                header("Location: processes.php?added=1");
                exit;
            }
            $formError = "Süreç eklenemedi. Geçerli bir şirket ve süreç adı girin.";
        } else {
            $ok = qmsProcessUpdate($pdo, (int) ($_POST["id"] ?? 0), $data, $userId, $role);
            if ($ok) {
                header("Location: processes.php?updated=1");
                exit;
            }
            $formError = "Süreç güncellenemedi.";
        }
    } elseif ($formType === "delete") {
        qmsProcessDelete($pdo, (int) ($_POST["id"] ?? 0), $userId, $role);
        header("Location: processes.php?deleted=1");
        exit;
    }
}

$allRows = qmsProcessList($pdo, $userId, $role);
$statusFilter = (string) ($_GET["status"] ?? "");
if ($statusFilter !== '' && !in_array($statusFilter, QMS_PROCESS_STATUSES, true)) {
    $statusFilter = '';
}
$rows = $statusFilter === '' ? $allRows : array_values(array_filter($allRows, static fn($r) => $r['status'] === $statusFilter));
$activeCount = 0;
foreach ($allRows as $r) {
    if ($r['status'] === 'active') {
        $activeCount++;
    }
}

$activeNav = "processes";
$selectedCompanyId = (int) ($_GET["company_id"] ?? 0);
$prefill = $editing ?: [
    'process_code' => '', 'process_name' => '', 'department' => '', 'owner_name' => '',
    'objective' => '', 'inputs' => '', 'outputs' => '', 'kpi' => '', 'review_date' => '', 'status' => 'active',
];
if ($editing) {
    $selectedCompanyId = (int) $editing['company_id'];
}

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="theme-color" content="#f9fafb">
    <title>QuAmi Süreç Envanteri</title><link rel="manifest" href="manifest.webmanifest"><link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml"><link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar"><div class="topbar-inner"><div class="page-title-block"><strong data-i18n="processesTitle">Süreç Envanteri</strong><span data-i18n="processesText">Şirket süreçlerini ve sahiplerini yönetin.</span></div><div class="topbar-actions"><button class="topbar-button" id="languageToggle" type="button">EN</button><button class="topbar-button" id="themeToggle" type="button" aria-label="Tema değiştir">🌙</button></div></div></header>
    <main class="page-container">
        <section class="page-heading">
            <div>
                <span class="section-kicker" data-i18n="processesKicker">Proses Yönetimi</span>
                <h1 data-i18n="processesTitle">Süreç Envanteri</h1>
                <p data-i18n="processesText">Süreçlerinizi tanımlayın; sahip, departman, girdi-çıktı, KPI ve gözden geçirme tarihini tek yerden izleyin.</p>
            </div>
        </section>

        <?php if ($formError !== ""): ?><div class="form-message error"><?= htmlspecialchars($formError, ENT_QUOTES, "UTF-8") ?></div><?php endif; ?>
        <?php if (($_GET["added"] ?? "") === "1"): ?><div class="form-message success" data-i18n="processAdded">Süreç eklendi.</div><?php endif; ?>
        <?php if (($_GET["updated"] ?? "") === "1"): ?><div class="form-message success" data-i18n="processUpdated">Süreç güncellendi.</div><?php endif; ?>
        <?php if (($_GET["deleted"] ?? "") === "1"): ?><div class="form-message success" data-i18n="processDeleted">Süreç silindi.</div><?php endif; ?>

        <div class="record-card-grid">
            <div class="dashboard-card"><div class="dashboard-card-content"><div class="dashboard-card-label" data-i18n="processActiveKpi">Aktif Süreç</div><div class="dashboard-card-number"><?= $activeCount ?></div></div></div>
            <div class="dashboard-card"><div class="dashboard-card-content"><div class="dashboard-card-label" data-i18n="processTotalKpi">Toplam Süreç</div><div class="dashboard-card-number"><?= count($allRows) ?></div></div></div>
        </div>

        <div class="two-col">
            <section class="form-panel">
                <div class="section-heading compact-heading"><div><h3><?= $editing ? 'Süreci Düzenle' : 'Yeni Süreç' ?></h3><?php if ($editing): ?><p>#<?= (int) $editing['id'] ?> düzenleniyor</p><?php endif; ?></div></div>
                <?php if (!$companies): ?><div class="form-message error" data-i18n="processNoCompany">Önce bir şirket gerekir.</div>
                <?php else: ?>
                <form class="auditor-form" method="post" action="processes.php<?= $editing ? '?edit=' . (int) $editing['id'] : '' ?>">
                    <?= qmsCsrfField($csrfScope) ?>
                    <input type="hidden" name="form_type" value="<?= $editing ? 'update' : 'add' ?>">
                    <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>
                    <div class="form-grid">
                        <label class="form-field"><span data-i18n="companySelectLabel">Şirket</span><select name="company_id" <?= $editing ? 'disabled' : 'required' ?>><option value="0" data-i18n="selectCompanyOption">Şirket seçin</option><?php foreach ($companies as $company): ?><option value="<?= (int) $company['id'] ?>" <?= $selectedCompanyId === (int) $company['id'] ? 'selected' : '' ?>><?= htmlspecialchars($company['company_name'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></label>
                        <label class="form-field"><span data-i18n="processCodeLabel">Süreç Kodu (isteğe bağlı)</span><input type="text" name="process_code" maxlength="40" value="<?= htmlspecialchars((string) $prefill['process_code'], ENT_QUOTES, 'UTF-8') ?>"></label>
                        <label class="form-field"><span data-i18n="processNameLabel">Süreç Adı</span><input type="text" name="process_name" required maxlength="190" value="<?= htmlspecialchars((string) $prefill['process_name'], ENT_QUOTES, 'UTF-8') ?>"></label>
                        <label class="form-field"><span data-i18n="processDepartmentLabel">Departman</span><input type="text" name="department" maxlength="120" value="<?= htmlspecialchars((string) $prefill['department'], ENT_QUOTES, 'UTF-8') ?>"></label>
                        <label class="form-field"><span data-i18n="processOwnerLabel">Süreç Sahibi</span><input type="text" name="owner_name" maxlength="180" value="<?= htmlspecialchars((string) $prefill['owner_name'], ENT_QUOTES, 'UTF-8') ?>"></label>
                        <label class="form-field"><span data-i18n="processStatusLabel">Durum</span><select name="status"><?php foreach (QMS_PROCESS_STATUSES as $st): ?><option value="<?= $st ?>" <?= (string) $prefill['status'] === $st ? 'selected' : '' ?>><?= htmlspecialchars(qmsProcessStatusLabel($st), ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></label>
                        <label class="form-field"><span data-i18n="processReviewDateLabel">Gözden Geçirme Tarihi</span><input type="date" name="review_date" value="<?= htmlspecialchars((string) $prefill['review_date'], ENT_QUOTES, 'UTF-8') ?>"></label>
                        <label class="form-field form-field-wide"><span data-i18n="processObjectiveLabel">Amaç</span><textarea name="objective" rows="3"><?= htmlspecialchars((string) $prefill['objective'], ENT_QUOTES, 'UTF-8') ?></textarea></label>
                        <label class="form-field form-field-wide"><span data-i18n="processInputsLabel">Girdiler</span><textarea name="inputs" rows="2"><?= htmlspecialchars((string) $prefill['inputs'], ENT_QUOTES, 'UTF-8') ?></textarea></label>
                        <label class="form-field form-field-wide"><span data-i18n="processOutputsLabel">Çıktılar</span><textarea name="outputs" rows="2"><?= htmlspecialchars((string) $prefill['outputs'], ENT_QUOTES, 'UTF-8') ?></textarea></label>
                        <label class="form-field form-field-wide"><span data-i18n="processKpiLabel">KPI / Gösterge</span><input type="text" name="kpi" maxlength="500" value="<?= htmlspecialchars((string) $prefill['kpi'], ENT_QUOTES, 'UTF-8') ?>"></label>
                    </div>
                    <div class="form-actions"><button class="primary-button" type="submit" data-i18n="processSave">Kaydet</button><?php if ($editing): ?><a class="secondary-button" href="processes.php" data-i18n="processCancel">İptal</a><?php endif; ?></div>
                </form>
                <?php endif; ?>
            </section>

            <section class="console-card checklist-section">
                <div class="section-heading compact-heading"><div><h3 data-i18n="processListTitle">Süreçler</h3><p><span data-i18n="filteredRecordsLabel">Gösterilen kayıt</span>: <strong><?= count($rows) ?></strong></p></div><div><form class="filter-inline" method="get" action="processes.php"><select name="status"><option value="" data-i18n="processAllStatuses">Tüm Durumlar</option><?php foreach (QMS_PROCESS_STATUSES as $st): ?><option value="<?= $st ?>" <?= $statusFilter === $st ? 'selected' : '' ?>><?= htmlspecialchars(qmsProcessStatusLabel($st), ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select><button class="secondary-button secondary-button-sm" type="submit" data-i18n="applyButton">Uygula</button></form></div></div>
                <div class="admin-list">
                    <?php if (!$rows): ?><div class="empty-state" data-i18n="processEmpty">Henüz süreç tanımlanmadı.</div><?php endif; ?>
                    <?php foreach ($rows as $row): ?>
                        <div class="admin-list-item">
                            <div class="list-item-main">
                                <strong><?= $row['process_code'] ? '[' . htmlspecialchars($row['process_code'], ENT_QUOTES, 'UTF-8') . '] ' : '' ?><?= htmlspecialchars($row['process_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                <span>
                                    <span class="status-badge <?= $row['status'] === 'active' ? 'status-pill' : '' ?>"><?= htmlspecialchars(qmsProcessStatusLabel($row['status']), ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php if ($row['department']): ?> · <?= htmlspecialchars($row['department'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?><?php if ($row['owner_name']): ?> · <?= htmlspecialchars($row['owner_name'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?><?= $row['review_date'] ? ' · gözden geçirme ' . htmlspecialchars((string) $row['review_date'], ENT_QUOTES, 'UTF-8') : '' ?>
                                </span>
                            </div>
                            <div class="list-item-side">
                                <a class="secondary-button secondary-button-sm" href="processes.php?edit=<?= (int) $row['id'] ?>" data-i18n="editButton">Düzenle</a>
                                <form method="post" action="processes.php" onsubmit="return confirm('Süreç silinsin mi?');"><?= qmsCsrfField($csrfScope) ?><input type="hidden" name="form_type" value="delete"><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><button class="danger-button danger-button-sm" type="submit" data-i18n="processDelete">Sil</button></form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>
    </main>
    <script src="assets/js/theme.js"></script><script src="assets/js/language.js"></script><script src="assets/js/sidebar.js"></script><script src="assets/js/pwa.js"></script>
</body>
</html>
