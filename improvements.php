<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/access.php';
qmsRequirePermission('operations.view');
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/improvement-functions.php';

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

$csrfScope = 'improvements';
$formError = '';
$editing = null;
$editId = (int) ($_GET['edit'] ?? 0);
if ($editId > 0) {
    $editing = qmsImprovementFind($pdo, $editId, $userId, $role);
    if (!$editing) {
        $editId = 0;
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    qmsCsrfVerify($csrfScope, $_POST["csrf"] ?? null);
    $formType = (string) ($_POST["form_type"] ?? "");

    if ($formType === "add" || $formType === "update") {
        $data = [
            "title" => (string) ($_POST["title"] ?? ""),
            "description" => (string) ($_POST["description"] ?? ""),
            "category" => (string) ($_POST["category"] ?? ""),
            "benefit_type" => (string) ($_POST["benefit_type"] ?? "quality"),
            "impact" => (string) ($_POST["impact"] ?? "medium"),
            "priority" => (string) ($_POST["priority"] ?? "normal"),
            "responsible" => (string) ($_POST["responsible"] ?? ""),
            "target_date" => (string) ($_POST["target_date"] ?? ""),
            "status" => (string) ($_POST["status"] ?? "submitted"),
            "eval_score" => (string) ($_POST["eval_score"] ?? ""),
            "result" => (string) ($_POST["result"] ?? ""),
        ];
        if ($formType === "add") {
            $data["company_id"] = (int) ($_POST["company_id"] ?? 0);
            $data["suggested_by"] = $userId;
            $newId = qmsImprovementAdd($pdo, $data, $userId, $role);
            if ($newId !== null) {
                qmsImprovementNotify($pdo, $data["company_id"], 'improvement_submitted', 'Yeni öneri: ' . (string) $data['title'], 'improvements.php');
                header("Location: improvements.php?added=1");
                exit;
            }
            $formError = "Öneri eklenemedi. Geçerli bir şirket ve başlık girin.";
        } else {
            $updateId = (int) ($_POST["id"] ?? 0);
            $prior = qmsImprovementFind($pdo, $updateId, $userId, $role);
            $priorStatus = !empty($prior) ? (string) $prior['status'] : '';
            $data["suggested_by"] = 0;
            $ok = qmsImprovementUpdate($pdo, $updateId, $data, $userId, $role);
            if ($ok) {
                if ($priorStatus !== 'implemented' && (string) ($data['status'] ?? '') === 'implemented') {
                    qmsImprovementNotify($pdo, (int) ($prior['company_id'] ?? 0), 'improvement_implemented', 'Uygulandı: ' . (string) $data['title'], 'improvements.php');
                }
                header("Location: improvements.php?updated=1");
                exit;
            }
            $formError = "Öneri güncellenemedi.";
        }
    } elseif ($formType === "delete") {
        qmsImprovementDelete($pdo, (int) ($_POST["id"] ?? 0), $userId, $role);
        header("Location: improvements.php?deleted=1");
        exit;
    }
}

$statusFilter = (string) ($_GET["status"] ?? "");
if (!in_array($statusFilter, QMS_IMPROVEMENT_FILTERS, true)) {
    $statusFilter = '';
}
$rows = qmsImprovementList($pdo, $userId, $role, $statusFilter);
$allRows = qmsImprovementList($pdo, $userId, $role, '');
$openCount = 0;
$implementedCount = 0;
foreach ($allRows as $r) {
    if (!in_array($r['status'], ['rejected', 'closed', 'implemented'], true)) {
        $openCount++;
    }
    if ($r['status'] === 'implemented') {
        $implementedCount++;
    }
}

$activeNav = "improvements";
$selectedCompanyId = (int) ($_GET["company_id"] ?? 0);
$prefill = $editing ?: [
    'title' => '', 'description' => '', 'category' => '', 'benefit_type' => 'quality',
    'impact' => 'medium', 'priority' => 'normal', 'responsible' => '', 'target_date' => '',
    'status' => 'submitted', 'eval_score' => '', 'result' => '',
];
if ($editing) {
    $selectedCompanyId = (int) $editing['company_id'];
}

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="theme-color" content="#f9fafb">
    <title>QuAmi İyileştirme Fırsatları</title><link rel="manifest" href="manifest.webmanifest"><link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml"><link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar"><div class="topbar-inner"><div class="page-title-block"><strong data-i18n="improvementsTitle">İyileştirme Fırsatları</strong><span data-i18n="improvementsText">Sürekli iyileştirme önerilerini toplayın ve izleyin.</span></div><div class="topbar-actions"><button class="topbar-button" id="languageToggle" type="button">EN</button><button class="topbar-button" id="themeToggle" type="button" aria-label="Tema değiştir">🌙</button></div></div></header>
    <main class="page-container">
        <section class="page-heading">
            <div>
                <span class="section-kicker" data-i18n="improvementsKicker">Sürekli İyileştirme</span>
                <h1 data-i18n="improvementsTitle">İyileştirme Fırsatları</h1>
                <p data-i18n="improvementsText">Kalite, maliyet, güvenlik ve verimlilik odaklı iyileştirme önerilerini toplayın, onaylayın ve uygulanmasını izleyin.</p>
            </div>
        </section>

        <?php if ($formError !== ""): ?><div class="form-message error"><?= htmlspecialchars($formError, ENT_QUOTES, "UTF-8") ?></div><?php endif; ?>
        <?php if (($_GET["added"] ?? "") === "1"): ?><div class="form-message success" data-i18n="improvementAdded">Öneri eklendi.</div><?php endif; ?>
        <?php if (($_GET["updated"] ?? "") === "1"): ?><div class="form-message success" data-i18n="improvementUpdated">Öneri güncellendi.</div><?php endif; ?>
        <?php if (($_GET["deleted"] ?? "") === "1"): ?><div class="form-message success" data-i18n="improvementDeleted">Öneri silindi.</div><?php endif; ?>

        <div class="record-card-grid">
            <div class="dashboard-card"><div class="dashboard-card-content"><div class="dashboard-card-label" data-i18n="improvementOpenKpi">Açık Fırsat</div><div class="dashboard-card-number"><?= $openCount ?></div></div></div>
            <div class="dashboard-card"><div class="dashboard-card-content"><div class="dashboard-card-label" data-i18n="improvementImplementedKpi">Uygulanan</div><div class="dashboard-card-number"><?= $implementedCount ?></div></div></div>
            <div class="dashboard-card"><div class="dashboard-card-content"><div class="dashboard-card-label" data-i18n="improvementTotalKpi">Toplam</div><div class="dashboard-card-number"><?= count($allRows) ?></div></div></div>
        </div>

        <div class="two-col">
            <section class="form-panel">
                <div class="section-heading compact-heading"><div><h3><?= $editing ? 'Öneriyi Düzenle' : 'Yeni Öneri' ?></h3><?php if ($editing): ?><p>#<?= (int) $editing['id'] ?> düzenleniyor</p><?php endif; ?></div></div>
                <?php if (!$companies): ?><div class="form-message error" data-i18n="improvementNoCompany">Önce bir şirket gerekir.</div>
                <?php else: ?>
                <form class="auditor-form" method="post" action="improvements.php<?= $editing ? '?edit=' . (int) $editing['id'] : '' ?>">
                    <?= qmsCsrfField($csrfScope) ?>
                    <input type="hidden" name="form_type" value="<?= $editing ? 'update' : 'add' ?>">
                    <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>
                    <div class="form-grid">
                        <label class="form-field"><span data-i18n="companySelectLabel">Şirket</span><select name="company_id" <?= $editing ? 'disabled' : 'required' ?>><option value="0" data-i18n="selectCompanyOption">Şirket seçin</option><?php foreach ($companies as $company): ?><option value="<?= (int) $company['id'] ?>" <?= $selectedCompanyId === (int) $company['id'] ? 'selected' : '' ?>><?= htmlspecialchars($company['company_name'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></label>
                        <label class="form-field"><span data-i18n="improvementTitleLabel">Başlık</span><input type="text" name="title" required maxlength="190" value="<?= htmlspecialchars((string) $prefill['title'], ENT_QUOTES, 'UTF-8') ?>"></label>
                        <label class="form-field"><span data-i18n="improvementCategoryLabel">Kategori (isteğe bağlı)</span><input type="text" name="category" maxlength="120" value="<?= htmlspecialchars((string) $prefill['category'], ENT_QUOTES, 'UTF-8') ?>"></label>
                        <label class="form-field"><span data-i18n="improvementBenefitLabel">Fayda Türü</span><select name="benefit_type"><?php foreach (['quality','cost','safety','efficiency'] as $bt): ?><option value="<?= $bt ?>" <?= (string) $prefill['benefit_type'] === $bt ? 'selected' : '' ?>><?= htmlspecialchars(qmsImprovementBenefitLabel($bt), ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></label>
                        <label class="form-field"><span data-i18n="improvementImpactLabel">Etki</span><select name="impact"><?php foreach (['low','medium','high'] as $lv): ?><option value="<?= $lv ?>" <?= (string) $prefill['impact'] === $lv ? 'selected' : '' ?>><?= htmlspecialchars(qmsImprovementLevelLabel($lv), ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></label>
                        <label class="form-field"><span data-i18n="improvementPriorityLabel">Öncelik</span><select name="priority"><?php foreach (['low','normal','high'] as $pr): ?><option value="<?= $pr ?>" <?= (string) $prefill['priority'] === $pr ? 'selected' : '' ?>><?= htmlspecialchars(qmsImprovementLevelLabel($pr), ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></label>
                        <label class="form-field"><span data-i18n="improvementResponsibleLabel">Sorumlu</span><input type="text" name="responsible" maxlength="180" value="<?= htmlspecialchars((string) $prefill['responsible'], ENT_QUOTES, 'UTF-8') ?>"></label>
                        <label class="form-field"><span data-i18n="improvementTargetDateLabel">Hedef Tarih</span><input type="date" name="target_date" value="<?= htmlspecialchars((string) $prefill['target_date'], ENT_QUOTES, 'UTF-8') ?>"></label>
                        <label class="form-field"><span data-i18n="improvementStatusLabel">Durum</span><select name="status"><?php foreach (QMS_IMPROVEMENT_STATUSES as $st): ?><option value="<?= $st ?>" <?= (string) $prefill['status'] === $st ? 'selected' : '' ?>><?= htmlspecialchars(qmsImprovementStatusLabel($st), ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></label>
                        <label class="form-field"><span data-i18n="improvementEvalScoreLabel">Değerlendirme Puanı (1-5)</span><input type="number" name="eval_score" min="1" max="5" value="<?= htmlspecialchars((string) ($prefill['eval_score'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></label>
                        <label class="form-field form-field-wide"><span data-i18n="improvementDescriptionLabel">Açıklama</span><textarea name="description" rows="4"><?= htmlspecialchars((string) $prefill['description'], ENT_QUOTES, 'UTF-8') ?></textarea></label>
                        <label class="form-field form-field-wide"><span data-i18n="improvementResultLabel">Sonuç / Gerçekleşme</span><textarea name="result" rows="3"><?= htmlspecialchars((string) $prefill['result'], ENT_QUOTES, 'UTF-8') ?></textarea></label>
                    </div>
                    <div class="form-actions"><button class="primary-button" type="submit" data-i18n="improvementSave">Kaydet</button><?php if ($editing): ?><a class="secondary-button" href="improvements.php" data-i18n="improvementCancel">İptal</a><?php endif; ?></div>
                </form>
                <?php endif; ?>
            </section>

            <section class="console-card checklist-section">
                <div class="section-heading compact-heading"><div><h3 data-i18n="improvementListTitle">Öneriler</h3><p><span data-i18n="filteredRecordsLabel">Gösterilen kayıt</span>: <strong><?= count($rows) ?></strong></p></div><div><form class="filter-inline" method="get" action="improvements.php"><select name="status"><option value="" data-i18n="improvementAllStatuses">Tüm Durumlar</option><?php foreach (QMS_IMPROVEMENT_FILTERS as $sf): ?><option value="<?= $sf ?>" <?= $statusFilter === $sf ? 'selected' : '' ?>><?= qmsImprovementStatusLabel(['open'=>'Açık','implemented'=>'Uygulanan','closed'=>'Kapandı'][$sf]) ?></option><?php endforeach; ?></select><button class="secondary-button" type="submit" data-i18n="applyButton">Uygula</button></form></div></div>
                <div class="admin-list">
                    <?php if (!$rows): ?><div class="empty-state" data-i18n="improvementEmpty">Henüz iyileştirme önerisi yok.</div><?php endif; ?>
                    <?php foreach ($rows as $row): ?>
                        <div class="admin-list-item">
                            <div class="list-item-main">
                                <strong><?= htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8') ?></strong>
                                <span>
                                    <span class="status-badge"><?= htmlspecialchars(qmsImprovementStatusLabel($row['status']), ENT_QUOTES, 'UTF-8') ?></span>
                                    · <?= htmlspecialchars(qmsImprovementBenefitLabel($row['benefit_type']), ENT_QUOTES, 'UTF-8') ?>
                                    · etki <?= htmlspecialchars(qmsImprovementLevelLabel($row['impact']), ENT_QUOTES, 'UTF-8') ?><?php if ($row['responsible']): ?> · <?= htmlspecialchars($row['responsible'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?><?= $row['suggester_name'] ? ' · ' . htmlspecialchars($row['suggester_name'], ENT_QUOTES, 'UTF-8') : '' ?>
                                </span>
                            </div>
                            <div class="list-item-side">
                                <a class="secondary-button" href="improvements.php?edit=<?= (int) $row['id'] ?>" data-i18n="editButton">Düzenle</a>
                                <form method="post" action="improvements.php" onsubmit="return confirm('Öneri silinsin mi?');"><?= qmsCsrfField($csrfScope) ?><input type="hidden" name="form_type" value="delete"><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><button class="danger-button danger-button-sm" type="submit" data-i18n="improvementDelete">Sil</button></form>
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
