<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/access.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/contract-functions.php';

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

$csrfScope = 'contracts';
$formError = '';
$editing = null;
$editId = (int) ($_GET['edit'] ?? 0);
if ($editId > 0) {
    $editing = qmsContractFind($pdo, $editId, $userId, $role);
    if (!$editing) {
        $editId = 0;
    }
}
$manageId = (int) ($_GET['manage'] ?? 0);
$manageContract = $manageId > 0 ? qmsContractFind($pdo, $manageId, $userId, $role) : [];
if ($manageId > 0 && !$manageContract) {
    $manageId = 0;
}
$manageAttachments = $manageId > 0 ? qmsContractAttachments($pdo, $manageId) : [];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    qmsCsrfVerify($csrfScope, $_POST["csrf"] ?? null);
    $formType = (string) ($_POST["form_type"] ?? "");

    if ($formType === "add" || $formType === "update") {
        $data = [
            "contract_code" => (string) ($_POST["contract_code"] ?? ""),
            "contract_name" => (string) ($_POST["contract_name"] ?? ""),
            "party_name" => (string) ($_POST["party_name"] ?? ""),
            "contract_type" => (string) ($_POST["contract_type"] ?? "customer"),
            "start_date" => (string) ($_POST["start_date"] ?? ""),
            "end_date" => (string) ($_POST["end_date"] ?? ""),
            "renewal_date" => (string) ($_POST["renewal_date"] ?? ""),
            "value_amount" => (string) ($_POST["value_amount"] ?? ""),
            "currency" => (string) ($_POST["currency"] ?? "TRY"),
            "status" => (string) ($_POST["status"] ?? "active"),
            "notes" => (string) ($_POST["notes"] ?? ""),
        ];
        if ($formType === "add") {
            $data["company_id"] = (int) ($_POST["company_id"] ?? 0);
            $newId = qmsContractAdd($pdo, $data, $userId, $role);
            if ($newId !== null) {
                header("Location: contracts.php?added=1");
                exit;
            }
            $formError = "Sözleşme eklenemedi. Geçerli bir şirket ve sözleşme adı girin.";
        } else {
            $ok = qmsContractUpdate($pdo, (int) ($_POST["id"] ?? 0), $data, $userId, $role);
            if ($ok) {
                header("Location: contracts.php?updated=1");
                exit;
            }
            $formError = "Sözleşme güncellenemedi.";
        }
    } elseif ($formType === "delete") {
        qmsContractDelete($pdo, (int) ($_POST["id"] ?? 0), $userId, $role);
        header("Location: contracts.php?deleted=1");
        exit;
    } elseif ($formType === "attachment_add") {
        $target = (int) ($_POST["contract_id"] ?? 0);
        qmsContractAddAttachment($pdo, $target, $_FILES["attachment_file"] ?? [], $userId, $role);
        header("Location: contracts.php?manage=" . $target . "&fileadded=1");
        exit;
    } elseif ($formType === "attachment_delete") {
        $target = (int) ($_POST["contract_id"] ?? 0);
        qmsContractDeleteAttachment($pdo, (int) ($_POST["attachment_id"] ?? 0), $userId, $role);
        header("Location: contracts.php?manage=" . $target);
        exit;
    }
}

$statusFilter = (string) ($_GET["status"] ?? "");
$allowedFilters = array_merge(['', 'expiring', 'expired_auto'], QMS_CONTRACT_STATUSES);
if (!in_array($statusFilter, $allowedFilters, true)) {
    $statusFilter = '';
}
$rows = qmsContractList($pdo, $userId, $role, $statusFilter);
$allRows = qmsContractList($pdo, $userId, $role);
$activeCount = 0;
$expiringCount = 0;
foreach ($allRows as $r) {
    if ($r['status'] === 'active') {
        $activeCount++;
        if ($r['end_date'] !== null && (string) $r['end_date'] < date('Y-m-d', strtotime('+60 days'))) {
            $expiringCount++;
        }
    }
}

$activeNav = "contracts";
$selectedCompanyId = (int) ($_GET["company_id"] ?? 0);
$prefill = $editing ?: [
    'contract_code' => '', 'contract_name' => '', 'party_name' => '', 'contract_type' => 'customer',
    'start_date' => '', 'end_date' => '', 'renewal_date' => '', 'value_amount' => '', 'currency' => 'TRY',
    'status' => 'active', 'notes' => '',
];
if ($editing) {
    $selectedCompanyId = (int) $editing['company_id'];
}

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="theme-color" content="#f9fafb">
    <title>QMS Sözleşme Yönetimi</title><link rel="manifest" href="manifest.webmanifest"><link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml"><link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar"><div class="topbar-inner"><div class="page-title-block"><strong data-i18n="contractsTitle">Sözleşme Yönetimi</strong><span data-i18n="contractsText">Müşteri ve tedarikçi sözleşmelerini izleyin.</span></div><div class="topbar-actions"><button class="topbar-button" id="languageToggle" type="button">EN</button><button class="topbar-button" id="themeToggle" type="button" aria-label="Tema değiştir">🌙</button></div></div></header>
    <main class="page-container">
        <section class="page-heading">
            <div>
                <span class="section-kicker" data-i18n="contractsKicker">Ticari İlişkiler</span>
                <h1 data-i18n="contractsTitle">Sözleşme Yönetimi</h1>
                <p data-i18n="contractsText">Sözleşmelerin tarihlerini ve durumunu izleyin; süresi yaklaşanları takip edin.</p>
            </div>
        </section>

        <?php if ($formError !== ""): ?><div class="form-message error"><?= htmlspecialchars($formError, ENT_QUOTES, "UTF-8") ?></div><?php endif; ?>
        <?php if (($_GET["added"] ?? "") === "1"): ?><div class="form-message success" data-i18n="contractAdded">Sözleşme eklendi.</div><?php endif; ?>
        <?php if (($_GET["updated"] ?? "") === "1"): ?><div class="form-message success" data-i18n="contractUpdated">Sözleşme güncellendi.</div><?php endif; ?>
        <?php if (($_GET["deleted"] ?? "") === "1"): ?><div class="form-message success" data-i18n="contractDeleted">Sözleşme silindi.</div><?php endif; ?>
        <?php if (($_GET["fileadded"] ?? "") === "1"): ?><div class="form-message success" data-i18n="contractFileAdded">Dosya eklendi.</div><?php endif; ?>

        <?php if ($manageId > 0): ?>
        <section class="page-heading page-heading-actions">
            <div>
                <span class="section-kicker" data-i18n="contractsKicker">Ticari İlişkiler</span>
                <h1><?= htmlspecialchars($manageContract['contract_name'], ENT_QUOTES, 'UTF-8') ?></h1>
            </div>
            <a class="secondary-button" href="contracts.php" data-i18n="contractBack">Sözleşmelere Dön</a>
        </section>
        <section class="console-card checklist-section">
            <div class="section-heading compact-heading"><div><h3 data-i18n="contractFilesTitle">Sözleşme Dosyaları</h3><p>#<?= (int) $manageId ?><?= $manageContract['party_name'] ? ' · ' . htmlspecialchars((string) $manageContract['party_name'], ENT_QUOTES, 'UTF-8') : '' ?><?= $manageContract['end_date'] ? ' · bitiş ' . htmlspecialchars((string) $manageContract['end_date'], ENT_QUOTES, 'UTF-8') : '' ?></p></div></div>
            <form class="auditor-form" method="post" action="contracts.php?manage=<?= (int) $manageId ?>" enctype="multipart/form-data">
                <?= qmsCsrfField($csrfScope) ?>
                <input type="hidden" name="form_type" value="attachment_add">
                <input type="hidden" name="contract_id" value="<?= (int) $manageId ?>">
                <div class="form-grid">
                    <label class="form-field form-field-wide"><span data-i18n="contractFileLabel">Dosya Yükle</span><input type="file" name="attachment_file" required><small data-i18n="contractFileHelp">PDF, Word, Excel veya diğer; en fazla 10 MB.</small></label>
                </div>
                <div class="form-actions"><button class="primary-button" type="submit" data-i18n="contractUpload">Yükle</button></div>
            </form>
            <div class="admin-list">
                <?php if (!$manageAttachments): ?><div class="empty-state" data-i18n="contractNoFiles">Henüz dosya yok.</div><?php endif; ?>
                <?php foreach ($manageAttachments as $att): ?>
                    <div class="admin-list-item">
                        <div class="list-item-main">
                            <strong><?= htmlspecialchars($att['original_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                            <span><?= number_format((int) $att['file_size'] / 1024, 1, ',', '.') ?> KB · <?= htmlspecialchars((string) $att['created_at'], ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                        <div class="list-item-side">
                            <a class="secondary-button secondary-button-sm" href="contract-attachment-download.php?id=<?= (int) $att['id'] ?>" data-i18n="contractDownload">İndir</a>
                            <form method="post" action="contracts.php?manage=<?= (int) $manageId ?>" onsubmit="return confirm('Dosya silinsin mi?');"><?= qmsCsrfField($csrfScope) ?><input type="hidden" name="form_type" value="attachment_delete"><input type="hidden" name="contract_id" value="<?= (int) $manageId ?>"><input type="hidden" name="attachment_id" value="<?= (int) $att['id'] ?>"><button class="danger-button danger-button-sm" type="submit" data-i18n="contractFileDelete">Sil</button></form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php else: ?>

        <div class="record-card-grid">
            <div class="dashboard-card"><div class="dashboard-card-content"><div class="dashboard-card-label" data-i18n="contractActiveKpi">Aktif Sözleşme</div><div class="dashboard-card-number"><?= $activeCount ?></div></div></div>
            <div class="dashboard-card metric-orange"><div class="dashboard-card-content"><div class="dashboard-card-label" data-i18n="contractExpiringKpi">Süresi Doluyor</div><div class="dashboard-card-number"><?= $expiringCount ?></div></div></div>
            <div class="dashboard-card"><div class="dashboard-card-content"><div class="dashboard-card-label" data-i18n="contractTotalKpi">Toplam Sözleşme</div><div class="dashboard-card-number"><?= count($allRows) ?></div></div></div>
        </div>

        <div class="two-col">
            <section class="form-panel">
                <div class="section-heading compact-heading"><div><h3><?= $editing ? 'Sözleşmeyi Düzenle' : 'Yeni Sözleşme' ?></h3><?php if ($editing): ?><p>#<?= (int) $editing['id'] ?> düzenleniyor</p><?php endif; ?></div></div>
                <?php if (!$companies): ?><div class="form-message error" data-i18n="contractNoCompany">Önce bir şirket gerekir.</div>
                <?php else: ?>
                <form class="auditor-form" method="post" action="contracts.php<?= $editing ? '?edit=' . (int) $editing['id'] : '' ?>">
                    <?= qmsCsrfField($csrfScope) ?>
                    <input type="hidden" name="form_type" value="<?= $editing ? 'update' : 'add' ?>">
                    <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>
                    <div class="form-grid">
                        <label class="form-field"><span data-i18n="companySelectLabel">Şirket</span><select name="company_id" <?= $editing ? 'disabled' : 'required' ?>><option value="0" data-i18n="selectCompanyOption">Şirket seçin</option><?php foreach ($companies as $company): ?><option value="<?= (int) $company['id'] ?>" <?= $selectedCompanyId === (int) $company['id'] ? 'selected' : '' ?>><?= htmlspecialchars($company['company_name'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></label>
                        <label class="form-field"><span data-i18n="contractCodeLabel">Sözleşme Kodu (isteğe bağlı)</span><input type="text" name="contract_code" maxlength="40" value="<?= htmlspecialchars((string) $prefill['contract_code'], ENT_QUOTES, 'UTF-8') ?>"></label>
                        <label class="form-field"><span data-i18n="contractNameLabel">Sözleşme Adı</span><input type="text" name="contract_name" required maxlength="190" value="<?= htmlspecialchars((string) $prefill['contract_name'], ENT_QUOTES, 'UTF-8') ?>"></label>
                        <label class="form-field"><span data-i18n="contractPartyLabel">Karşı Taraf</span><input type="text" name="party_name" maxlength="190" value="<?= htmlspecialchars((string) $prefill['party_name'], ENT_QUOTES, 'UTF-8') ?>"></label>
                        <label class="form-field"><span data-i18n="contractTypeLabel">Tür</span><select name="contract_type"><?php foreach (QMS_CONTRACT_TYPES as $ct): ?><option value="<?= $ct ?>" <?= (string) $prefill['contract_type'] === $ct ? 'selected' : '' ?>><?= htmlspecialchars(['customer'=>'Müşteri','supplier'=>'Tedarikçi','other'=>'Diğer'][$ct], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></label>
                        <label class="form-field"><span data-i18n="contractStatusLabel">Durum</span><select name="status"><?php foreach (QMS_CONTRACT_STATUSES as $st): ?><option value="<?= $st ?>" <?= (string) $prefill['status'] === $st ? 'selected' : '' ?>><?= htmlspecialchars(qmsContractStatusLabel($st), ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></label>
                        <label class="form-field"><span data-i18n="contractStartLabel">Başlangıç</span><input type="date" name="start_date" value="<?= htmlspecialchars((string) $prefill['start_date'], ENT_QUOTES, 'UTF-8') ?>"></label>
                        <label class="form-field"><span data-i18n="contractEndLabel">Bitiş</span><input type="date" name="end_date" value="<?= htmlspecialchars((string) $prefill['end_date'], ENT_QUOTES, 'UTF-8') ?>"></label>
                        <label class="form-field"><span data-i18n="contractRenewalLabel">Yenileme Tarihi</span><input type="date" name="renewal_date" value="<?= htmlspecialchars((string) $prefill['renewal_date'], ENT_QUOTES, 'UTF-8') ?>"></label>
                        <label class="form-field"><span data-i18n="contractValueLabel">Tutar</span><input type="number" step="0.01" name="value_amount" value="<?= htmlspecialchars((string) ($prefill['value_amount'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></label>
                        <label class="form-field"><span data-i18n="contractCurrencyLabel">Para Birimi</span><input type="text" name="currency" maxlength="8" value="<?= htmlspecialchars((string) $prefill['currency'], ENT_QUOTES, 'UTF-8') ?>"></label>
                        <label class="form-field form-field-wide"><span data-i18n="contractNotesLabel">Not</span><textarea name="notes" rows="3"><?= htmlspecialchars((string) $prefill['notes'], ENT_QUOTES, 'UTF-8') ?></textarea></label>
                    </div>
                    <div class="form-actions"><button class="primary-button" type="submit" data-i18n="contractSave">Kaydet</button><?php if ($editing): ?><a class="secondary-button" href="contracts.php" data-i18n="contractCancel">İptal</a><?php endif; ?></div>
                </form>
                <?php endif; ?>
            </section>

            <section class="console-card checklist-section">
                <div class="section-heading compact-heading"><div><h3 data-i18n="contractListTitle">Sözleşmeler</h3><p><span data-i18n="filteredRecordsLabel">Gösterilen kayıt</span>: <strong><?= count($rows) ?></strong></p></div><div><form class="filter-inline" method="get" action="contracts.php"><select name="status"><option value="" data-i18n="contractAllStatuses">Tüm Durumlar</option><option value="expiring" data-i18n="contractFilterExpiring">Süresi Doluyor</option><option value="expired_auto" data-i18n="contractFilterExpired">Süresi Doldu (Otomatik)</option><?php foreach (QMS_CONTRACT_STATUSES as $st): ?><option value="<?= $st ?>" <?= $statusFilter === $st ? 'selected' : '' ?>><?= htmlspecialchars(qmsContractStatusLabel($st), ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select><button class="secondary-button secondary-button-sm" type="submit" data-i18n="applyButton">Uygula</button></form></div></div>
                <div class="admin-list">
                    <?php if (!$rows): ?><div class="empty-state" data-i18n="contractEmpty">Henüz sözleşme kaydı yok.</div><?php endif; ?>
                    <?php foreach ($rows as $row): ?>
                        <?php $isNear = $row['status'] === 'active' && $row['end_date'] !== null && (string) $row['end_date'] < date('Y-m-d', strtotime('+60 days')); ?>
                        <div class="admin-list-item">
                            <div class="list-item-main">
                                <strong><?= $row['contract_code'] ? '[' . htmlspecialchars($row['contract_code'], ENT_QUOTES, 'UTF-8') . '] ' : '' ?><?= htmlspecialchars($row['contract_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                <span>
                                    <?php if ($isNear): ?><span class="overdue-badge" data-i18n="contractNearBadge">Süresi Doluyor</span>
                                    <?php else: ?><span class="status-badge <?= $row['status'] === 'active' ? 'status-pill' : '' ?>"><?= htmlspecialchars(qmsContractStatusLabel($row['status']), ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                                    · <?= htmlspecialchars(['customer'=>'Müşteri','supplier'=>'Tedarikçi','other'=>'Diğer'][$row['contract_type']], ENT_QUOTES, 'UTF-8') ?><?php if ($row['party_name']): ?> · <?= htmlspecialchars($row['party_name'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?>
                                    <?php if ($row['end_date']): ?> · bitiş <?= htmlspecialchars((string) $row['end_date'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?><?php if ($row['value_amount'] !== null): ?> · <?= number_format((float) $row['value_amount'], 2, ',', '.') ?> <?= htmlspecialchars($row['currency'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?>
                                </span>
                            </div>
                            <div class="list-item-side">
                                <a class="secondary-button secondary-button-sm" href="contracts.php?manage=<?= (int) $row['id'] ?>" data-i18n="contractFilesTitle">Dosyalar</a>
                                <a class="secondary-button secondary-button-sm" href="contracts.php?edit=<?= (int) $row['id'] ?>" data-i18n="editButton">Düzenle</a>
                                <form method="post" action="contracts.php" onsubmit="return confirm('Sözleşme silinsin mi?');"><?= qmsCsrfField($csrfScope) ?><input type="hidden" name="form_type" value="delete"><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><button class="danger-button danger-button-sm" type="submit" data-i18n="contractDelete">Sil</button></form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>
        <?php endif; ?>
    </main>
    <script src="assets/js/theme.js"></script><script src="assets/js/language.js"></script><script src="assets/js/sidebar.js"></script><script src="assets/js/pwa.js"></script>
</body>
</html>
