<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/access.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/quality-plan-functions.php';

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

$csrfScope = 'quality_plans';
$formError = '';
$editing = null;
$editId = (int) ($_GET['edit'] ?? 0);
if ($editId > 0) {
    $editing = qmsQualityPlanFind($pdo, $editId, $userId, $role);
    if (!$editing) {
        $editId = 0;
    }
}
$manageId = (int) ($_GET['manage'] ?? 0);
$managePlan = $manageId > 0 ? qmsQualityPlanFind($pdo, $manageId, $userId, $role) : [];
if ($manageId > 0 && !$managePlan) {
    $manageId = 0;
}
$editItemId = (int) ($_GET['edititem'] ?? 0);
$editItem = null;
if ($manageId > 0 && $editItemId > 0) {
    foreach (qmsQualityPlanItems($pdo, $manageId) as $it) {
        if ((int) $it['id'] === $editItemId) {
            $editItem = $it;
        }
    }
    if (!$editItem) {
        $editItemId = 0;
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    qmsCsrfVerify($csrfScope, $_POST["csrf"] ?? null);
    $formType = (string) ($_POST["form_type"] ?? "");

    if ($formType === "add" || $formType === "update") {
        $companyId = (int) ($_POST["company_id"] ?? 0);
        if ($formType === "add") {
            $newId = qmsQualityPlanAdd($pdo, [
                "company_id" => $companyId,
                "plan_year" => (int) ($_POST["plan_year"] ?? 0),
                "title" => (string) ($_POST["title"] ?? ""),
                "description" => (string) ($_POST["description"] ?? ""),
            ], $userId, $role);
            if ($newId !== null) {
                header("Location: quality-plan.php?manage=" . $newId . "&added=1");
                exit;
            }
            $formError = "Plan eklenemedi. Geçerli bir şirket, yıl ve başlık girin (aynı şirket+yıl tekrarı reddedilir).";
        } else {
            $ok = qmsQualityPlanUpdate($pdo, (int) ($_POST["id"] ?? 0), [
                "title" => (string) ($_POST["title"] ?? ""),
                "description" => (string) ($_POST["description"] ?? ""),
            ], $userId, $role);
            if ($ok) {
                header("Location: quality-plan.php?updated=1");
                exit;
            }
            $formError = "Plan güncellenemedi.";
        }
    } elseif ($formType === "delete") {
        qmsQualityPlanDelete($pdo, (int) ($_POST["id"] ?? 0), $userId, $role);
        header("Location: quality-plan.php?deleted=1");
        exit;
    } elseif ($formType === "item_add" || $formType === "item_update") {
        $target = (int) ($_POST["plan_id"] ?? 0);
        $itemData = [
            "category" => (string) ($_POST["category"] ?? ""),
            "objective" => (string) ($_POST["objective"] ?? ""),
            "target" => (string) ($_POST["target"] ?? ""),
            "responsible" => (string) ($_POST["responsible"] ?? ""),
            "due_date" => (string) ($_POST["due_date"] ?? ""),
            "status" => (string) ($_POST["status"] ?? "not_started"),
            "progress" => (int) ($_POST["progress"] ?? 0),
        ];
        if ($formType === "item_add") {
            qmsQualityPlanAddItem($pdo, $target, $itemData, $userId, $role);
            header("Location: quality-plan.php?manage=" . $target . "&iadded=1");
            exit;
        } else {
            qmsQualityPlanUpdateItem($pdo, (int) ($_POST["item_id"] ?? 0), $itemData, $userId, $role);
            header("Location: quality-plan.php?manage=" . $target . "&iupdated=1");
            exit;
        }
    } elseif ($formType === "item_delete") {
        $target = (int) ($_POST["plan_id"] ?? 0);
        qmsQualityPlanDeleteItem($pdo, (int) ($_POST["item_id"] ?? 0), $userId, $role);
        header("Location: quality-plan.php?manage=" . $target);
        exit;
    }
}

$yearFilter = (int) ($_GET["year"] ?? 0);
$plans = qmsQualityPlanList($pdo, $userId, $role);
if ($yearFilter > 0) {
    $plans = array_values(array_filter($plans, fn($p) => (int) $p['plan_year'] === $yearFilter));
}
$planYears = [];
foreach (qmsQualityPlanList($pdo, $userId, $role) as $pl) {
    $planYears[(int) $pl['plan_year']] = true;
}
arsort($planYears);
$planYears = array_keys($planYears);

$activeNav = "quality_plans";
$selectedCompanyId = (int) ($_GET["company_id"] ?? 0);
$prefill = $editing ?: ['title' => '', 'description' => '', 'plan_year' => (int) date('Y')];
if ($editing) {
    $selectedCompanyId = (int) $editing['company_id'];
    $prefill['plan_year'] = (int) $editing['plan_year'];
}
$manageItems = [];
if ($manageId > 0) {
    $manageItems = qmsQualityPlanItems($pdo, $manageId);
}

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="theme-color" content="#f9fafb">
    <title>QMS Yıllık Kalite Planı</title><link rel="manifest" href="manifest.webmanifest"><link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml"><link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar"><div class="topbar-inner"><div class="page-title-block"><strong data-i18n="qualityPlanTitle">Yıllık Kalite Planı</strong><span data-i18n="qualityPlanText">Yıllık kalite hedeflerini ve ilerlemeyi yönetin.</span></div><div class="topbar-actions"><button class="topbar-button" id="languageToggle" type="button">EN</button><button class="topbar-button" id="themeToggle" type="button" aria-label="Tema değiştir">🌙</button></div></div></header>
    <main class="page-container">
        <?php if ($manageId > 0): ?>
        <section class="page-heading page-heading-actions">
            <div>
                <span class="section-kicker" data-i18n="qualityPlanKicker">Planlama</span>
                <h1><?= htmlspecialchars($managePlan['title'], ENT_QUOTES, 'UTF-8') ?> (<?= (int) $managePlan['plan_year'] ?>)</h1>
                <p><span data-i18n="qualityPlanItemTotalLabel">Kalem</span>: <strong><?= count($manageItems) ?></strong></p>
            </div>
            <a class="secondary-button" href="quality-plan.php" data-i18n="qualityPlanBack">Planlara Dön</a>
        </section>
        <?php else: ?>
        <section class="page-heading">
            <div>
                <span class="section-kicker" data-i18n="qualityPlanKicker">Planlama</span>
                <h1 data-i18n="qualityPlanTitle">Yıllık Kalite Planı</h1>
                <p data-i18n="qualityPlanText">Şirketinizin yıllık kalite hedeflerini tanımlayın ve her kalemin ilerlemesini izleyin.</p>
            </div>
        </section>
        <?php endif; ?>

        <?php if ($formError !== ""): ?><div class="form-message error"><?= htmlspecialchars($formError, ENT_QUOTES, "UTF-8") ?></div><?php endif; ?>
        <?php if (($_GET["added"] ?? "") === "1"): ?><div class="form-message success" data-i18n="qualityPlanAdded">Plan eklendi. Şimdi kalemleri tanımlayın.</div><?php endif; ?>
        <?php if (($_GET["updated"] ?? "") === "1"): ?><div class="form-message success" data-i18n="qualityPlanUpdated">Plan güncellendi.</div><?php endif; ?>
        <?php if (($_GET["deleted"] ?? "") === "1"): ?><div class="form-message success" data-i18n="qualityPlanDeleted">Plan silindi.</div><?php endif; ?>
        <?php if (($_GET["iadded"] ?? "") === "1"): ?><div class="form-message success" data-i18n="qualityPlanItemAdded">Kalem eklendi.</div><?php endif; ?>
        <?php if (($_GET["iupdated"] ?? "") === "1"): ?><div class="form-message success" data-i18n="qualityPlanItemUpdated">Kalem güncellendi.</div><?php endif; ?>

        <?php if ($manageId > 0): ?>
            <div class="two-col">
                <section class="console-card checklist-section">
                    <div class="section-heading compact-heading"><div><h3 data-i18n="qualityPlanAddItemTitle">Kalem Ekle / Düzenle</h3></div></div>
                    <form class="auditor-form" method="post" action="quality-plan.php?manage=<?= (int) $manageId ?><?= $editItem ? '&edititem=' . (int) $editItemId : '' ?>">
                        <?= qmsCsrfField($csrfScope) ?>
                        <input type="hidden" name="form_type" value="<?= $editItem ? 'item_update' : 'item_add' ?>">
                        <input type="hidden" name="plan_id" value="<?= (int) $manageId ?>">
                        <?php if ($editItem): ?><input type="hidden" name="item_id" value="<?= (int) $editItem['id'] ?>"><?php endif; ?>
                        <div class="form-grid">
                            <label class="form-field"><span data-i18n="qualityPlanCategoryLabel">Kategori (isteğe bağlı)</span><input type="text" name="category" maxlength="120" value="<?= htmlspecialchars((string) ($editItem['category'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></label>
                            <label class="form-field"><span data-i18n="qualityPlanObjectiveLabel">Hedef</span><input type="text" name="objective" required maxlength="500" value="<?= htmlspecialchars((string) ($editItem['objective'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></label>
                            <label class="form-field"><span data-i18n="qualityPlanTargetLabel">Hedef Değer (isteğe bağlı)</span><input type="text" name="target" maxlength="500" value="<?= htmlspecialchars((string) ($editItem['target'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></label>
                            <label class="form-field"><span data-i18n="qualityPlanResponsibleLabel">Sorumlu</span><input type="text" name="responsible" maxlength="180" value="<?= htmlspecialchars((string) ($editItem['responsible'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></label>
                            <label class="form-field"><span data-i18n="qualityPlanDueLabel">Bitiş Tarihi</span><input type="date" name="due_date" value="<?= htmlspecialchars((string) ($editItem['due_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></label>
                            <label class="form-field"><span data-i18n="qualityPlanStatusLabel">Durum</span><select name="status"><?php foreach (QMS_PLAN_STATUSES as $st): ?><option value="<?= $st ?>" <?= (string) ($editItem['status'] ?? 'not_started') === $st ? 'selected' : '' ?>><?= htmlspecialchars(qmsPlanStatusLabel($st), ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></label>
                            <label class="form-field"><span data-i18n="qualityPlanProgressLabel">İlerleme (%)</span><input type="number" name="progress" min="0" max="100" value="<?= (int) ($editItem['progress'] ?? 0) ?>"></label>
                        </div>
                        <div class="form-actions"><button class="primary-button" type="submit"><?= $editItem ? 'Güncelle' : 'Ekle' ?></button><?php if ($editItem): ?><a class="secondary-button" href="quality-plan.php?manage=<?= (int) $manageId ?>" data-i18n="qualityPlanCancel">İptal</a><?php endif; ?></div>
                    </form>
                </section>

                <section class="console-card checklist-section">
                    <div class="section-heading compact-heading"><div><h3 data-i18n="qualityPlanItemsTitle">Kalemler</h3><p><span data-i18n="filteredRecordsLabel">Kalem</span>: <strong><?= count($manageItems) ?></strong></p></div></div>
                    <div class="admin-list">
                        <?php if (!$manageItems): ?><div class="empty-state" data-i18n="qualityPlanNoItems">Henüz kalem eklenmedi.</div><?php endif; ?>
                        <?php foreach ($manageItems as $it): ?>
                            <div class="admin-list-item">
                                <div class="list-item-main">
                                    <strong><?= htmlspecialchars($it['objective'], ENT_QUOTES, 'UTF-8') ?></strong>
                                    <span><?= htmlspecialchars(qmsPlanStatusLabel($it['status']), ENT_QUOTES, 'UTF-8') ?> · %<?= (int) $it['progress'] ?><?php if ($it['category']): ?> · <?= htmlspecialchars($it['category'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?><?php if ($it['responsible']): ?> · <?= htmlspecialchars($it['responsible'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?></span>
                                </div>
                                <div class="list-item-side">
                                    <a class="secondary-button secondary-button-sm" href="quality-plan.php?manage=<?= (int) $manageId ?>&edititem=<?= (int) $it['id'] ?>" data-i18n="editButton">Düzenle</a>
                                    <form method="post" action="quality-plan.php?manage=<?= (int) $manageId ?>" onsubmit="return confirm('Kalem silinsin mi?');"><?= qmsCsrfField($csrfScope) ?><input type="hidden" name="form_type" value="item_delete"><input type="hidden" name="plan_id" value="<?= (int) $manageId ?>"><input type="hidden" name="item_id" value="<?= (int) $it['id'] ?>"><button class="danger-button danger-button-sm" type="submit" data-i18n="qualityPlanDeleteItem">Sil</button></form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            </div>
        <?php else: ?>

        <div class="two-col">
            <section class="form-panel">
                <div class="section-heading compact-heading"><div><h3><?= $editing ? 'Planı Düzenle' : 'Yeni Plan' ?></h3><?php if ($editing): ?><p>#<?= (int) $editing['id'] ?> düzenleniyor</p><?php endif; ?></div></div>
                <?php if (!$companies): ?><div class="form-message error" data-i18n="qualityPlanNoCompany">Önce bir şirket gerekir.</div>
                <?php else: ?>
                <form class="auditor-form" method="post" action="quality-plan.php<?= $editing ? '?edit=' . (int) $editing['id'] : '' ?>">
                    <?= qmsCsrfField($csrfScope) ?>
                    <input type="hidden" name="form_type" value="<?= $editing ? 'update' : 'add' ?>">
                    <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>
                    <div class="form-grid">
                        <label class="form-field"><span data-i18n="companySelectLabel">Şirket</span><select name="company_id" <?= $editing ? 'disabled' : 'required' ?>><option value="0" data-i18n="selectCompanyOption">Şirket seçin</option><?php foreach ($companies as $company): ?><option value="<?= (int) $company['id'] ?>" <?= $selectedCompanyId === (int) $company['id'] ? 'selected' : '' ?>><?= htmlspecialchars($company['company_name'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></label>
                        <label class="form-field"><span data-i18n="qualityPlanYearLabel">Yıl</span><select name="plan_year" <?= $editing ? 'disabled' : 'required' ?>><?php for ($y = (int) date('Y') - 1; $y <= (int) date('Y') + 1; $y++): ?><option value="<?= $y ?>" <?= (int) $prefill['plan_year'] === $y ? 'selected' : '' ?>><?= $y ?></option><?php endfor; ?></select></label>
                        <label class="form-field form-field-wide"><span data-i18n="qualityPlanTitleLabel">Başlık</span><input type="text" name="title" required maxlength="190" value="<?= htmlspecialchars((string) $prefill['title'], ENT_QUOTES, 'UTF-8') ?>"></label>
                        <label class="form-field form-field-wide"><span data-i18n="qualityPlanDescriptionLabel">Açıklama</span><textarea name="description" rows="4"><?= htmlspecialchars((string) ($prefill['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea></label>
                    </div>
                    <div class="form-actions"><button class="primary-button" type="submit" data-i18n="qualityPlanSave">Kaydet</button><?php if ($editing): ?><a class="secondary-button" href="quality-plan.php" data-i18n="qualityPlanCancel">İptal</a><?php endif; ?></div>
                </form>
                <?php endif; ?>
            </section>

            <section class="console-card checklist-section">
                <div class="section-heading compact-heading"><div><h3 data-i18n="qualityPlanListTitle">Planlar</h3><p><span data-i18n="filteredRecordsLabel">Gösterilen kayıt</span>: <strong><?= count($plans) ?></strong></p></div><div><form class="filter-inline" method="get" action="quality-plan.php"><select name="year"><option value="0" data-i18n="qualityPlanAllYears">Tüm Yıllar</option><?php foreach ($planYears as $py): ?><option value="<?= (int) $py ?>" <?= $yearFilter === (int) $py ? 'selected' : '' ?>><?= (int) $py ?></option><?php endforeach; ?></select><button class="secondary-button secondary-button-sm" type="submit" data-i18n="applyButton">Uygula</button></form></div></div>
                <div class="admin-list">
                    <?php if (!$plans): ?><div class="empty-state" data-i18n="qualityPlanEmpty">Henüz plan yok.</div><?php endif; ?>
                    <?php foreach ($plans as $pl): ?>
                        <div class="admin-list-item">
                            <div class="list-item-main">
                                <strong><?= htmlspecialchars($pl['title'], ENT_QUOTES, 'UTF-8') ?> (<?= (int) $pl['plan_year'] ?>)</strong>
                                <span><?= (int) $pl['item_completed'] ?>/<?= (int) $pl['item_total'] ?> tamamlandı<?= $pl['avg_progress'] !== null ? ' · ilerleme %' . (int) $pl['avg_progress'] : '' ?></span>
                            </div>
                            <div class="list-item-side">
                                <a class="secondary-button secondary-button-sm" href="quality-plan.php?manage=<?= (int) $pl['id'] ?>" data-i18n="qualityPlanManage">Yönet</a>
                                <a class="secondary-button secondary-button-sm" href="quality-plan.php?edit=<?= (int) $pl['id'] ?>" data-i18n="editButton">Düzenle</a>
                                <form method="post" action="quality-plan.php" onsubmit="return confirm('Plan silinsin mi?');"><?= qmsCsrfField($csrfScope) ?><input type="hidden" name="form_type" value="delete"><input type="hidden" name="id" value="<?= (int) $pl['id'] ?>"><button class="danger-button danger-button-sm" type="submit" data-i18n="qualityPlanDelete">Sil</button></form>
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
