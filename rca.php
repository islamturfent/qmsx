<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/access.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/rca-functions.php';

$userId = (int) ($_SESSION["qms_user_id"] ?? 0);
$role = qmsCurrentRole();
if (qmsIsAuditor()) {
    header("Location: my-audits.php");
    exit;
}

$csrfScope = 'rca';
$formError = '';
$formSuccess = ($_GET['ok'] ?? '') === '1';

$scope = qmsCompanyScope('companies.id', qmsVisibleCompanyIds($pdo, $userId, $role));
$companyStmt = $pdo->prepare('SELECT companies.id, companies.company_name FROM companies WHERE companies.active = 1' . $scope['sql'] . ' ORDER BY companies.company_name');
$companyStmt->execute($scope['params']);
$companies = $companyStmt->fetchAll(PDO::FETCH_ASSOC);

$editId = (int) ($_GET['edit'] ?? 0);
$editing = $editId > 0 ? qmsRcaFind($pdo, $editId, $userId, $role) : [];
if (!$editing) {
    $editId = 0;
}

$sourceType = $editing ? (string) $editing['source_type'] : (string) ($_GET['source_type'] ?? 'nonconformity');
if (!in_array($sourceType, QMS_RCA_SOURCE_TYPES, true)) {
    $sourceType = 'nonconformity';
}
$sourceOptions = qmsRcaSourceOptions($pdo, $sourceType, $userId, $role);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    qmsCsrfVerify($csrfScope, $_POST['csrf'] ?? null);
    $formType = (string) ($_POST['form_type'] ?? '');

    if ($formType === 'open_capa') {
        $rcaId = (int) ($_POST['id'] ?? 0);
        $actionId = qmsRcaOpenCapa($pdo, $rcaId, $userId, $role);
        if ($actionId !== null) {
            header('Location: corrective-action-detail.php?id=' . $actionId);
            exit;
        }
        $formError = 'CAPA açılamadı: RCA bir uygunsuzluğa bağlı olmalı.';
    } elseif ($formType === 'delete') {
        qmsRcaDelete($pdo, (int) ($_POST['id'] ?? 0), $userId, $role);
        header('Location: rca.php?ok=1');
        exit;
    } elseif ($formType === 'add' || $formType === 'update') {
        $data = [
            'company_id' => (int) ($_POST['company_id'] ?? 0),
            'source_type' => (string) ($_POST['source_type'] ?? 'nonconformity'),
            'source_id' => (int) ($_POST['source_id'] ?? 0),
            'title' => (string) ($_POST['title'] ?? ''),
            'description' => (string) ($_POST['description'] ?? ''),
            'five_why' => (string) ($_POST['five_why'] ?? ''),
            'root_cause' => (string) ($_POST['root_cause'] ?? ''),
            'corrective_action_text' => (string) ($_POST['corrective_action_text'] ?? ''),
            'owner' => (string) ($_POST['owner'] ?? ''),
            'status' => (string) ($_POST['status'] ?? 'draft'),
        ];
        if ($formType === 'add') {
            $newId = qmsRcaAdd($pdo, $data, $userId, $role);
            if ($newId !== null) {
                header('Location: rca.php?ok=1');
                exit;
            }
            $formError = 'RCA eklenemedi. Geçerli bir şirket ve başlık girin.';
        } else {
            $ok = qmsRcaUpdate($pdo, (int) ($_POST['id'] ?? 0), $data, $userId, $role);
            if ($ok) {
                header('Location: rca.php?ok=1');
                exit;
            }
            $formError = 'RCA güncellenemedi.';
        }
    }
}

$rows = qmsRcaList($pdo, $userId, $role);
$statusLabels = qmsRcaStatusLabels();
$statusI18n = qmsRcaStatusI18nKeys();
$sourceTypeLabels = qmsRcaSourceTypeLabels();

$prefill = $editing ?: [
    'company_id' => count($companies) === 1 ? (int) $companies[0]['id'] : 0,
    'source_type' => 'nonconformity', 'source_id' => 0, 'title' => '', 'description' => '',
    'five_why' => '', 'root_cause' => '', 'corrective_action_text' => '', 'owner' => '', 'status' => 'draft',
];

$activeNav = "rca";

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="theme-color" content="#f9fafb">
    <title>QuAmi Kök Neden Analizi</title><link rel="manifest" href="manifest.webmanifest"><link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml"><link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar"><div class="topbar-inner"><div class="page-title-block"><strong data-i18n="rcaTitle">Kök Neden Analizi</strong><span data-i18n="rcaText">Olay ve uygunsuzluklar için 5-Neden ile yapılandırılmış kök neden oturumu.</span></div><div class="topbar-actions"><button class="topbar-button" id="languageToggle" type="button">EN</button><button class="topbar-button" id="themeToggle" type="button" aria-label="Tema değiştir">🌙</button></div></div></header>
    <main class="page-container">
        <section class="page-heading">
            <div>
                <span class="section-kicker" data-i18n="rcaKicker">Sürekli İyileştirme</span>
                <h1 data-i18n="rcaTitle">Kök Neden Analizi</h1>
                <p data-i18n="rcaText">Olay, uygunsuzluk ve iyileştirme fırsatları için 5-Neden ile kök nedeni belirleyin.</p>
            </div>
        </section>

        <?php if ($formError !== ''): ?><div class="form-message error"><?= htmlspecialchars($formError, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
        <?php if ($formSuccess): ?><div class="form-message success" data-i18n="rcaSaved">Kaydedildi.</div><?php endif; ?>

        <div class="record-card-grid">
            <div class="dashboard-card"><div class="dashboard-card-content"><div class="dashboard-card-label" data-i18n="rcaTotalKpi">Toplam RCA</div><div class="dashboard-card-number"><?= count($rows) ?></div></div></div>
        </div>

        <div class="two-col">
            <section class="form-panel">
                <div class="section-heading compact-heading"><div><h3><?= $editing ? 'RCA Düzenle' : 'Yeni RCA' ?></h3><?php if ($editing): ?><p>#<?= (int) $editing['id'] ?> düzenleniyor</p><?php endif; ?></div></div>
                <?php if (!$companies): ?><div class="form-message error">Önce bir şirket gerekir.</div>
                <?php else: ?>
                <form class="auditor-form" method="post" action="rca.php<?= $editing ? '?edit=' . (int) $editing['id'] : '' ?>">
                    <?= qmsCsrfField($csrfScope) ?>
                    <input type="hidden" name="form_type" value="<?= $editing ? 'update' : 'add' ?>">
                    <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>
                    <div class="form-grid">
                        <label class="form-field"><span data-i18n="companySelectLabel">Şirket</span><select name="company_id" <?= $editing ? 'disabled' : 'required' ?>><option value="0">Şirket seçin</option><?php foreach ($companies as $company): ?><option value="<?= (int) $company['id'] ?>" <?= (int) $prefill['company_id'] === (int) $company['id'] ? 'selected' : '' ?>><?= htmlspecialchars($company['company_name'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></label>
                        <label class="form-field"><span data-i18n="rcaSourceTypeLabel">Kaynak Türü</span><select name="source_type" onchange="this.form.submit()"><?php foreach (QMS_RCA_SOURCE_TYPES as $st): ?><option value="<?= $st ?>" <?= $sourceType === $st ? 'selected' : '' ?>><?= htmlspecialchars($sourceTypeLabels[$st], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></label>
                        <label class="form-field"><span data-i18n="rcaSourceLabel">Kaynak Kayıt</span><select name="source_id"><option value="0" data-i18n="rcaSourceNone">— Seçilmedi —</option><?php foreach ($sourceOptions as $so): ?><option value="<?= (int) $so['id'] ?>" <?= (int) ($prefill['source_id'] ?? 0) === (int) $so['id'] ? 'selected' : '' ?>><?= htmlspecialchars($so['label'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></label>
                        <label class="form-field"><span data-i18n="rcaTitleLabel">Başlık</span><input type="text" name="title" required maxlength="255" value="<?= htmlspecialchars((string) $prefill['title'], ENT_QUOTES, 'UTF-8') ?>"></label>
                        <label class="form-field"><span data-i18n="rcaOwnerLabel">Sorumlu</span><input type="text" name="owner" maxlength="180" value="<?= htmlspecialchars((string) $prefill['owner'], ENT_QUOTES, 'UTF-8') ?>"></label>
                        <label class="form-field"><span data-i18n="rcaStatusLabel">Durum</span><select name="status"><?php foreach (QMS_RCA_STATUSES as $st): ?><option value="<?= $st ?>" <?= (string) $prefill['status'] === $st ? 'selected' : '' ?>><?= htmlspecialchars($statusLabels[$st], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></label>
                        <label class="form-field form-field-wide"><span data-i18n="rcaDescriptionLabel">Sorun Tanımı</span><textarea name="description" rows="3"><?= htmlspecialchars((string) $prefill['description'], ENT_QUOTES, 'UTF-8') ?></textarea></label>
                        <label class="form-field form-field-wide"><span data-i18n="rcaFiveWhyLabel">5-Neden</span><textarea name="five_why" rows="5" placeholder="1. Neden 1&#10;2. Neden 2&#10;3. Neden 3&#10;4. Neden 4&#10;5. Neden 5"><?= htmlspecialchars((string) $prefill['five_why'], ENT_QUOTES, 'UTF-8') ?></textarea></label>
                        <label class="form-field form-field-wide"><span data-i18n="rcaRootCauseLabel">Kök Neden</span><textarea name="root_cause" rows="2"><?= htmlspecialchars((string) $prefill['root_cause'], ENT_QUOTES, 'UTF-8') ?></textarea></label>
                        <label class="form-field form-field-wide"><span data-i18n="rcaCapaTextLabel">Önerilen Düzeltici Faaliyet</span><textarea name="corrective_action_text" rows="2"><?= htmlspecialchars((string) $prefill['corrective_action_text'], ENT_QUOTES, 'UTF-8') ?></textarea></label>
                    </div>
                    <div class="form-actions"><button class="primary-button" type="submit" data-i18n="rcaSave">Kaydet</button><?php if ($editing): ?><a class="secondary-button" href="rca.php" data-i18n="rcaCancel">İptal</a><?php endif; ?></div>
                </form>
                <?php endif; ?>
            </section>

            <section class="console-card checklist-section">
                <div class="section-heading compact-heading"><div><h3 data-i18n="rcaListTitle">RCA Kayıtları</h3><p><span data-i18n="filteredRecordsLabel">Gösterilen kayıt</span>: <strong><?= count($rows) ?></strong></p></div></div>
                <div class="admin-list">
                    <?php if (!$rows): ?><div class="empty-state" data-i18n="rcaEmpty">Henüz RCA kaydı yok.</div><?php endif; ?>
                    <?php foreach ($rows as $row): ?>
                        <div class="admin-list-item">
                            <div class="list-item-main">
                                <strong><?= htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8') ?></strong>
                                <span>
                                    <span class="status-badge"><?= htmlspecialchars($statusLabels[$row['status']] ?? $row['status'], ENT_QUOTES, 'UTF-8') ?></span>
                                    · <?= htmlspecialchars($sourceTypeLabels[$row['source_type']] ?? $row['source_type'], ENT_QUOTES, 'UTF-8') ?> #<?= (int) $row['source_id'] ?>
                                    <?= $row['owner'] ? ' · ' . htmlspecialchars($row['owner'], ENT_QUOTES, 'UTF-8') : '' ?>
                                    · <?= htmlspecialchars($row['root_cause'] ? 'Kök: ' . $row['root_cause'] : 'Kök neden bekleniyor', ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </div>
                            <div class="list-item-side">
                                <?php if ($row['source_type'] === 'nonconformity' && $row['status'] !== 'closed'): ?>
                                <form method="post" action="rca.php"><?= qmsCsrfField($csrfScope) ?><input type="hidden" name="form_type" value="open_capa"><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><button class="primary-button primary-button-sm" type="submit" data-i18n="rcaOpenCapaButton">CAPA Aç</button></form>
                                <?php endif; ?>
                                <a class="secondary-button" href="rca.php?edit=<?= (int) $row['id'] ?>" data-i18n="editButton">Düzenle</a>
                                <form method="post" action="rca.php" onsubmit="return confirm('RCA silinsin mi?');"><?= qmsCsrfField($csrfScope) ?><input type="hidden" name="form_type" value="delete"><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><button class="danger-button danger-button-sm" type="submit" data-i18n="improvementDelete">Sil</button></form>
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
