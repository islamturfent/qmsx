<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/access.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/incident-functions.php';

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

$csrfScope = 'incidents';
$formError = '';
$editing = null;
$editId = (int) ($_GET['edit'] ?? 0);
if ($editId > 0) {
    $editing = qmsIncidentFind($pdo, $editId, $userId, $role);
    if (!$editing) {
        $editId = 0;
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    qmsCsrfVerify($csrfScope, $_POST["csrf"] ?? null);
    $formType = (string) ($_POST["form_type"] ?? "");

    if ($formType === "add" || $formType === "update") {
        $data = [
            "incident_code" => (string) ($_POST["incident_code"] ?? ""),
            "title" => (string) ($_POST["title"] ?? ""),
            "description" => (string) ($_POST["description"] ?? ""),
            "location" => (string) ($_POST["location"] ?? ""),
            "incident_type" => (string) ($_POST["incident_type"] ?? "other"),
            "severity" => (string) ($_POST["severity"] ?? "medium"),
            "reported_at" => (string) ($_POST["reported_at"] ?? ""),
            "responsible" => (string) ($_POST["responsible"] ?? ""),
            "status" => (string) ($_POST["status"] ?? "open"),
            "notes" => (string) ($_POST["notes"] ?? ""),
        ];
        if ($formType === "add") {
            $data["company_id"] = (int) ($_POST["company_id"] ?? 0);
            $companyData = ($_POST["company_id"] ?? 0);
            $newId = qmsIncidentAdd($pdo, $data, $userId, $role);
            if ($newId !== null) {
                qmsIncidentNotify($pdo, (int) $companyData, (string) $data['title'], (string) $data['severity'], 'incidents.php');
                header("Location: incidents.php?added=1");
                exit;
            }
            $formError = "Olay eklenemedi. Geçerli bir şirket ve başlık girin.";
        } else {
            $ok = qmsIncidentUpdate($pdo, (int) ($_POST["id"] ?? 0), $data, $userId, $role);
            if ($ok) {
                header("Location: incidents.php?updated=1");
                exit;
            }
            $formError = "Olay güncellenemedi.";
        }
    } elseif ($formType === "delete") {
        qmsIncidentDelete($pdo, (int) ($_POST["id"] ?? 0), $userId, $role);
        header("Location: incidents.php?deleted=1");
        exit;
    }
}

$statusFilter = (string) ($_GET["status"] ?? "");
$allowedFilters = array_merge(['', 'open'], QMS_INCIDENT_STATUSES);
if (!in_array($statusFilter, $allowedFilters, true)) {
    $statusFilter = '';
}
$rows = qmsIncidentList($pdo, $userId, $role, $statusFilter);
$allRows = qmsIncidentList($pdo, $userId, $role);
$openCount = 0;
$criticalCount = 0;
foreach ($allRows as $r) {
    if ($r['status'] !== 'closed') {
        $openCount++;
    }
    if ($r['severity'] === 'critical') {
        $criticalCount++;
    }
}

$activeNav = "incidents";
$selectedCompanyId = (int) ($_GET["company_id"] ?? 0);
$prefill = $editing ?: [
    'incident_code' => '', 'title' => '', 'description' => '', 'location' => '', 'incident_type' => 'other',
    'severity' => 'medium', 'reported_at' => '', 'responsible' => '', 'status' => 'open', 'notes' => '',
];
if ($editing) {
    $selectedCompanyId = (int) $editing['company_id'];
}

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="theme-color" content="#f9fafb">
    <title>QuAmi Olay Raporlama</title><link rel="manifest" href="manifest.webmanifest"><link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml"><link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar"><div class="topbar-inner"><div class="page-title-block"><strong data-i18n="incidentsTitle">Olay Raporlama</strong><span data-i18n="incidentsText">Kaza, ramak kala ve olayları kaydedin ve izleyin.</span></div><div class="topbar-actions"><button class="topbar-button" id="languageToggle" type="button">EN</button><button class="topbar-button" id="themeToggle" type="button" aria-label="Tema değiştir">🌙</button></div></div></header>
    <main class="page-container">
        <section class="page-heading">
            <div>
                <span class="section-kicker" data-i18n="incidentsKicker">Güvenlik & Uygunluk</span>
                <h1 data-i18n="incidentsTitle">Olay Raporlama</h1>
                <p data-i18n="incidentsText">Olayları tür, şiddet ve durumlarıyla raporlayın; soruşturma ve kapanışını izleyin.</p>
            </div>
        </section>

        <?php if ($formError !== ""): ?><div class="form-message error"><?= htmlspecialchars($formError, ENT_QUOTES, "UTF-8") ?></div><?php endif; ?>
        <?php if (($_GET["added"] ?? "") === "1"): ?><div class="form-message success" data-i18n="incidentAdded">Olay eklendi.</div><?php endif; ?>
        <?php if (($_GET["updated"] ?? "") === "1"): ?><div class="form-message success" data-i18n="incidentUpdated">Olay güncellendi.</div><?php endif; ?>
        <?php if (($_GET["deleted"] ?? "") === "1"): ?><div class="form-message success" data-i18n="incidentDeleted">Olay silindi.</div><?php endif; ?>

        <div class="record-card-grid">
            <div class="dashboard-card"><div class="dashboard-card-content"><div class="dashboard-card-label" data-i18n="incidentOpenKpi">Açık Olay</div><div class="dashboard-card-number"><?= $openCount ?></div></div></div>
            <div class="dashboard-card metric-red"><div class="dashboard-card-content"><div class="dashboard-card-label" data-i18n="incidentCriticalKpi">Kritik Olay</div><div class="dashboard-card-number"><?= $criticalCount ?></div></div></div>
            <div class="dashboard-card"><div class="dashboard-card-content"><div class="dashboard-card-label" data-i18n="incidentTotalKpi">Toplam Olay</div><div class="dashboard-card-number"><?= count($allRows) ?></div></div></div>
        </div>

        <div class="two-col">
            <section class="form-panel">
                <div class="section-heading compact-heading"><div><h3><?= $editing ? 'Olayı Düzenle' : 'Yeni Olay' ?></h3><?php if ($editing): ?><p>#<?= (int) $editing['id'] ?> düzenleniyor</p><?php endif; ?></div></div>
                <?php if (!$companies): ?><div class="form-message error" data-i18n="incidentNoCompany">Önce bir şirket gerekir.</div>
                <?php else: ?>
                <form class="auditor-form" method="post" action="incidents.php<?= $editing ? '?edit=' . (int) $editing['id'] : '' ?>">
                    <?= qmsCsrfField($csrfScope) ?>
                    <input type="hidden" name="form_type" value="<?= $editing ? 'update' : 'add' ?>">
                    <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>
                    <div class="form-grid">
                        <label class="form-field"><span data-i18n="companySelectLabel">Şirket</span><select name="company_id" <?= $editing ? 'disabled' : 'required' ?>><option value="0" data-i18n="selectCompanyOption">Şirket seçin</option><?php foreach ($companies as $company): ?><option value="<?= (int) $company['id'] ?>" <?= $selectedCompanyId === (int) $company['id'] ? 'selected' : '' ?>><?= htmlspecialchars($company['company_name'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></label>
                        <label class="form-field"><span data-i18n="incidentCodeLabel">Olay Kodu (isteğe bağlı)</span><input type="text" name="incident_code" maxlength="40" value="<?= htmlspecialchars((string) $prefill['incident_code'], ENT_QUOTES, 'UTF-8') ?>"></label>
                        <label class="form-field"><span data-i18n="incidentTitleLabel">Başlık</span><input type="text" name="title" required maxlength="190" value="<?= htmlspecialchars((string) $prefill['title'], ENT_QUOTES, 'UTF-8') ?>"></label>
                        <label class="form-field"><span data-i18n="incidentTypeLabel">Tür</span><select name="incident_type"><?php foreach (QMS_INCIDENT_TYPES as $it): ?><option value="<?= $it ?>" <?= (string) $prefill['incident_type'] === $it ? 'selected' : '' ?>><?= htmlspecialchars(qmsIncidentTypeLabel($it), ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></label>
                        <label class="form-field"><span data-i18n="incidentSeverityLabel">Şiddet</span><select name="severity"><?php foreach (QMS_INCIDENT_SEVERITIES as $se): ?><option value="<?= $se ?>" <?= (string) $prefill['severity'] === $se ? 'selected' : '' ?>><?= htmlspecialchars(qmsIncidentSeverityLabel($se), ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></label>
                        <label class="form-field"><span data-i18n="incidentStatusLabel">Durum</span><select name="status"><?php foreach (QMS_INCIDENT_STATUSES as $st): ?><option value="<?= $st ?>" <?= (string) $prefill['status'] === $st ? 'selected' : '' ?>><?= htmlspecialchars(qmsIncidentStatusLabel($st), ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></label>
                        <label class="form-field"><span data-i18n="incidentLocationLabel">Konum</span><input type="text" name="location" maxlength="190" value="<?= htmlspecialchars((string) $prefill['location'], ENT_QUOTES, 'UTF-8') ?>"></label>
                        <label class="form-field"><span data-i18n="incidentReportedLabel">Olay Tarihi</span><input type="date" name="reported_at" value="<?= htmlspecialchars((string) $prefill['reported_at'], ENT_QUOTES, 'UTF-8') ?>"></label>
                        <label class="form-field"><span data-i18n="incidentResponsibleLabel">Sorumlu</span><input type="text" name="responsible" maxlength="180" value="<?= htmlspecialchars((string) $prefill['responsible'], ENT_QUOTES, 'UTF-8') ?>"></label>
                        <label class="form-field form-field-wide"><span data-i18n="incidentDescriptionLabel">Açıklama</span><textarea name="description" rows="4"><?= htmlspecialchars((string) $prefill['description'], ENT_QUOTES, 'UTF-8') ?></textarea></label>
                        <label class="form-field form-field-wide"><span data-i18n="incidentNotesLabel">Not / Aksiyon</span><textarea name="notes" rows="3"><?= htmlspecialchars((string) $prefill['notes'], ENT_QUOTES, 'UTF-8') ?></textarea></label>
                    </div>
                    <div class="form-actions"><button class="primary-button" type="submit" data-i18n="incidentSave">Kaydet</button><?php if ($editing): ?><a class="secondary-button" href="incidents.php" data-i18n="incidentCancel">İptal</a><?php endif; ?></div>
                </form>
                <?php endif; ?>
            </section>

            <section class="console-card checklist-section">
                <div class="section-heading compact-heading"><div><h3 data-i18n="incidentListTitle">Olaylar</h3><p><span data-i18n="filteredRecordsLabel">Gösterilen kayıt</span>: <strong><?= count($rows) ?></strong></p></div><div><form class="filter-inline" method="get" action="incidents.php"><select name="status"><option value="" data-i18n="incidentAllStatuses">Tüm Durumlar</option><option value="open" data-i18n="incidentFilterOpen">Açık</option><?php foreach (QMS_INCIDENT_STATUSES as $st): ?><option value="<?= $st ?>" <?= $statusFilter === $st ? 'selected' : '' ?>><?= htmlspecialchars(qmsIncidentStatusLabel($st), ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select><button class="secondary-button secondary-button-sm" type="submit" data-i18n="applyButton">Uygula</button></form></div></div>
                <div class="admin-list">
                    <?php if (!$rows): ?><div class="empty-state" data-i18n="incidentEmpty">Henüz olay kaydı yok.</div><?php endif; ?>
                    <?php foreach ($rows as $row): ?>
                        <div class="admin-list-item">
                            <div class="list-item-main">
                                <strong><?= $row['incident_code'] ? '[' . htmlspecialchars($row['incident_code'], ENT_QUOTES, 'UTF-8') . '] ' : '' ?><?= htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8') ?></strong>
                                <span>
                                    <?php if ($row['severity'] === 'critical'): ?><span class="overdue-badge" data-i18n="incidentCriticalBadge">Kritik</span><?php else: ?><span class="status-badge <?= $row['status'] === 'closed' ? 'status-pill' : '' ?>"><?= htmlspecialchars(qmsIncidentStatusLabel($row['status']), ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                                    · <?= htmlspecialchars(qmsIncidentTypeLabel($row['incident_type']), ENT_QUOTES, 'UTF-8') ?> · şiddet <?= htmlspecialchars(qmsIncidentSeverityLabel($row['severity']), ENT_QUOTES, 'UTF-8') ?><?= $row['reported_at'] ? ' · ' . htmlspecialchars((string) $row['reported_at'], ENT_QUOTES, 'UTF-8') : '' ?><?= $row['responsible'] ? ' · ' . htmlspecialchars($row['responsible'], ENT_QUOTES, 'UTF-8') : '' ?>
                                </span>
                            </div>
                            <div class="list-item-side">
                                <a class="secondary-button secondary-button-sm" href="incident-detail.php?id=<?= (int) $row['id'] ?>" data-i18n="incidentDetailButton">Detay</a>
                                <a class="secondary-button secondary-button-sm" href="incidents.php?edit=<?= (int) $row['id'] ?>" data-i18n="editButton">Düzenle</a>
                                <form method="post" action="incidents.php" onsubmit="return confirm('Olay silinsin mi?');"><?= qmsCsrfField($csrfScope) ?><input type="hidden" name="form_type" value="delete"><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><button class="danger-button danger-button-sm" type="submit" data-i18n="incidentDelete">Sil</button></form>
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
