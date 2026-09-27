<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/access.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/supplier-eval-schedule-functions.php';

$userId = (int) ($_SESSION["qms_user_id"] ?? 0);
$role = qmsCurrentRole();
if (qmsIsAuditor()) {
    header("Location: my-audits.php");
    exit;
}

$csrfScope = 'supplier_evaluations';
$formError = '';
$editing = null;
$editId = (int) ($_GET['edit'] ?? 0);
if ($editId > 0) {
    $editing = qmsSupplierEvalScheduleFind($pdo, $editId, $userId, $role);
    if (!$editing) {
        $editId = 0;
    }
}

$supplierScope = qmsCompanyScope('s.company_id', qmsVisibleCompanyIds($pdo, $userId, $role));
$supplierStmt = $pdo->prepare('SELECT s.id, s.name, s.company_id FROM suppliers s WHERE s.active = 1 AND s.status != "removed"' . $supplierScope['sql'] . ' ORDER BY s.name');
$supplierStmt->execute($supplierScope['params']);
$suppliers = $supplierStmt->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    qmsCsrfVerify($csrfScope, $_POST["csrf"] ?? null);
    $formType = (string) ($_POST["form_type"] ?? "");

    if ($formType === "add" || $formType === "update") {
        if ($formType === "add") {
            $newId = qmsSupplierEvalScheduleAdd($pdo, [
                "supplier_id" => (int) ($_POST["supplier_id"] ?? 0),
                "cycle_label" => (string) ($_POST["cycle_label"] ?? ""),
                "due_date" => (string) ($_POST["due_date"] ?? ""),
                "status" => (string) ($_POST["status"] ?? "planned"),
                "result" => (string) ($_POST["result"] ?? ""),
                "notes" => (string) ($_POST["notes"] ?? ""),
            ], $userId, $role);
            if ($newId !== null) {
                header("Location: supplier-evaluations.php?added=1");
                exit;
            }
            $formError = "Değerlendirme randevusu eklenemedi. Geçerli bir tedarikçi ve döngü seçin.";
        } else {
            $ok = qmsSupplierEvalScheduleUpdate($pdo, (int) ($_POST["id"] ?? 0), [
                "cycle_label" => (string) ($_POST["cycle_label"] ?? ""),
                "due_date" => (string) ($_POST["due_date"] ?? ""),
                "status" => (string) ($_POST["status"] ?? "planned"),
                "result" => (string) ($_POST["result"] ?? ""),
                "notes" => (string) ($_POST["notes"] ?? ""),
            ], $userId, $role);
            if ($ok) {
                header("Location: supplier-evaluations.php?updated=1");
                exit;
            }
            $formError = "Değerlendirme güncellenemedi.";
        }
    } elseif ($formType === "delete") {
        qmsSupplierEvalScheduleDelete($pdo, (int) ($_POST["id"] ?? 0), $userId, $role);
        header("Location: supplier-evaluations.php?deleted=1");
        exit;
    } elseif ($formType === "set_status") {
        $targetId = (int) ($_POST["id"] ?? 0);
        $target = qmsSupplierEvalScheduleFind($pdo, $targetId, $userId, $role);
        if ($target) {
            $new = (string) ($_POST["status"] ?? "");
            if (in_array($new, ['done', 'skipped'], true)) {
                qmsSupplierEvalScheduleUpdate($pdo, $targetId, [
                    "cycle_label" => (string) $target['cycle_label'],
                    "due_date" => (string) ($target['due_date'] ?? ''),
                    "status" => $new,
                    "result" => (string) ($target['result'] ?? ''),
                    "notes" => (string) ($target['notes'] ?? ''),
                ], $userId, $role);
            }
        }
        header("Location: supplier-evaluations.php");
        exit;
    }
}

$statusFilter = (string) ($_GET["status"] ?? "");
if (!in_array($statusFilter, QMS_SUPPLIER_EVAL_FILTERS, true)) {
    $statusFilter = '';
}
$rows = qmsSupplierEvalScheduleList($pdo, $userId, $role, $statusFilter);
$allRows = qmsSupplierEvalScheduleList($pdo, $userId, $role, '');
$countOverdue = 0;
foreach ($allRows as $r) {
    if ($r['eff_status'] === 'overdue') {
        $countOverdue++;
    }
}
$activeNav = "supplier_evaluations";
$prefill = $editing;

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="theme-color" content="#f9fafb">
    <title>QuAmi Tedarikçi Değerlendirme Takvimi</title><link rel="manifest" href="manifest.webmanifest"><link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml"><link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar"><div class="topbar-inner"><div class="page-title-block"><strong data-i18n="supplierEvalTitle">Tedarikçi Değerlendirme Takvimi</strong><span data-i18n="supplierEvalText">Periyodik tedarikçi değerlendirmelerini planlayın ve izleyin.</span></div><div class="topbar-actions"><button class="topbar-button" id="languageToggle" type="button">EN</button><button class="topbar-button" id="themeToggle" type="button" aria-label="Tema değiştir">🌙</button></div></div></header>
    <main class="page-container">
        <section class="page-heading">
            <div>
                <span class="section-kicker" data-i18n="supplierEvalKicker">Tedarikçi Yönetimi</span>
                <h1 data-i18n="supplierEvalTitle">Tedarikçi Değerlendirme Takvimi</h1>
                <p data-i18n="supplierEvalText">Tedarikçiler için planlanan değerlendirme döngülerini takip edin, vadesi geçenleri görün.</p>
            </div>
        </section>

        <?php if ($formError !== ""): ?><div class="form-message error"><?= htmlspecialchars($formError, ENT_QUOTES, "UTF-8") ?></div><?php endif; ?>
        <?php if (($_GET["added"] ?? "") === "1"): ?><div class="form-message success" data-i18n="supplierEvalAdded">Değerlendirme randevusu eklendi.</div><?php endif; ?>
        <?php if (($_GET["updated"] ?? "") === "1"): ?><div class="form-message success" data-i18n="supplierEvalUpdated">Değerlendirme güncellendi.</div><?php endif; ?>
        <?php if (($_GET["deleted"] ?? "") === "1"): ?><div class="form-message success" data-i18n="supplierEvalDeleted">Değerlendirme silindi.</div><?php endif; ?>

        <div class="record-card-grid">
            <div class="dashboard-card"><div class="dashboard-card-content"><div class="dashboard-card-label" data-i18n="supplierEvalTotalKpi">Toplam Randevu</div><div class="dashboard-card-number"><?= count($allRows) ?></div></div></div>
            <div class="dashboard-card"><div class="dashboard-card-content"><div class="dashboard-card-label" data-i18n="supplierEvalOverdueKpi">Vadesi Geçen</div><div class="dashboard-card-number"><?= $countOverdue ?></div></div></div>
            <div class="dashboard-card"><div class="dashboard-card-content"><div class="dashboard-card-label" data-i18n="supplierEvalDoneKpi">Yapıldı</div><div class="dashboard-card-number"><?= (int) array_sum(array_map(fn($r) => $r['eff_status'] === 'done' ? 1 : 0, $allRows)) ?></div></div></div>
        </div>

        <div class="two-col">
            <section class="form-panel">
                <div class="section-heading compact-heading"><div><h3><?= $editing ? 'Randevuyu Düzenle' : 'Yeni Değerlendirme' ?></h3><?php if ($editing): ?><p>#<?= (int) $editing['id'] ?> düzenleniyor</p><?php endif; ?></div></div>
                <?php if (!$suppliers): ?><div class="form-message error" data-i18n="supplierEvalNoSupplier">Randevu için önce bir tedarikçi gerekir.</div>
                <?php else: ?>
                <form class="auditor-form" method="post" action="supplier-evaluations.php<?= $editing ? '?edit=' . (int) $editing['id'] : '' ?>">
                    <?= qmsCsrfField($csrfScope) ?>
                    <input type="hidden" name="form_type" value="<?= $editing ? 'update' : 'add' ?>">
                    <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>
                    <div class="form-grid">
                        <label class="form-field"><span data-i18n="supplierEvalSupplierLabel">Tedarikçi</span><?php if ($editing): ?><strong><?= htmlspecialchars($editing['supplier_name'], ENT_QUOTES, 'UTF-8') ?></strong><?php else: ?><select name="supplier_id" required><option value="0" data-i18n="selectSupplierOption">Tedarikçi seçin</option><?php foreach ($suppliers as $sup): ?><option value="<?= (int) $sup['id'] ?>"><?= htmlspecialchars($sup['name'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select><?php endif; ?></label>
                        <label class="form-field"><span data-i18n="supplierEvalCycleLabel">Döngü (örn. Q1 2026)</span><input type="text" name="cycle_label" required maxlength="80" value="<?= htmlspecialchars((string) ($prefill['cycle_label'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></label>
                        <label class="form-field"><span data-i18n="supplierEvalDueLabel">Bitiş Tarihi</span><input type="date" name="due_date" value="<?= htmlspecialchars((string) ($prefill['due_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></label>
                        <label class="form-field"><span data-i18n="supplierEvalStatusLabel">Durum</span><select name="status"><?php foreach (QMS_SUPPLIER_EVAL_STATUSES as $st): ?><option value="<?= $st ?>" <?= (string) ($prefill['status'] ?? 'planned') === $st ? 'selected' : '' ?>><?= htmlspecialchars(qmsSupplierEvalStatusLabel($st), ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></label>
                        <label class="form-field"><span data-i18n="supplierEvalResultLabel">Sonuç</span><input type="text" name="result" maxlength="100" value="<?= htmlspecialchars((string) ($prefill['result'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></label>
                        <label class="form-field form-field-wide"><span data-i18n="supplierEvalNotesLabel">Not</span><textarea name="notes" rows="3"><?= htmlspecialchars((string) ($prefill['notes'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea></label>
                    </div>
                    <div class="form-actions"><button class="primary-button" type="submit" data-i18n="supplierEvalSave">Kaydet</button><?php if ($editing): ?><a class="secondary-button" href="supplier-evaluations.php" data-i18n="supplierEvalCancel">İptal</a><?php endif; ?></div>
                </form>
                <?php endif; ?>
            </section>

            <section class="console-card checklist-section">
                <div class="section-heading compact-heading"><div><h3 data-i18n="supplierEvalListTitle">Değerlendirmeler</h3><p><span data-i18n="filteredRecordsLabel">Gösterilen kayıt</span>: <strong><?= count($rows) ?></strong></p></div><div><form class="filter-inline" method="get" action="supplier-evaluations.php"><select name="status"><option value="" data-i18n="supplierEvalAllStatuses">Tüm Durumlar</option><?php foreach (QMS_SUPPLIER_EVAL_FILTERS as $sf): ?><option value="<?= $sf ?>" <?= $statusFilter === $sf ? 'selected' : '' ?>><?= htmlspecialchars(qmsSupplierEvalStatusLabel($sf), ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select><button class="secondary-button" type="submit" data-i18n="applyButton">Uygula</button></form></div></div>
                <div class="admin-list">
                    <?php if (!$rows): ?><div class="empty-state" data-i18n="supplierEvalEmpty">Henüz değerlendirme kaydı yok.</div><?php endif; ?>
                    <?php foreach ($rows as $row): ?>
                        <?php $isOverdue = $row['eff_status'] === 'overdue'; ?>
                        <div class="admin-list-item">
                            <div class="list-item-main">
                                <strong><?= htmlspecialchars($row['supplier_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                <span>
                                    <?php if ($isOverdue): ?><span class="overdue-badge" data-i18n="supplierEvalOverdueBadge">Vadesi Geçti</span>
                                    <?php elseif ($row['eff_status'] === 'done'): ?><span class="status-badge status-pill" data-i18n="supplierEvalDoneBadge">Yapıldı</span>
                                    <?php else: ?><span class="status-badge" data-i18n="supplierEvalPlannedBadge">Planlandı</span><?php endif; ?>
                                    · <?= htmlspecialchars($row['cycle_label'], ENT_QUOTES, 'UTF-8') ?><?= $row['due_date'] ? ' · ' . htmlspecialchars((string) $row['due_date'], ENT_QUOTES, 'UTF-8') : '' ?><?php if ($row['result']): ?> · <?= htmlspecialchars($row['result'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?>
                                </span>
                            </div>
                            <div class="list-item-side">
                                <?php if ($row['eff_status'] !== 'done'): ?>
                                <form method="post" action="supplier-evaluations.php"><?= qmsCsrfField($csrfScope) ?><input type="hidden" name="form_type" value="set_status"><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><input type="hidden" name="status" value="done"><button class="secondary-button" type="submit" data-i18n="supplierEvalMarkDone">Yapıldı</button></form>
                                <?php endif; ?>
                                <a class="secondary-button" href="supplier-evaluations.php?edit=<?= (int) $row['id'] ?>" data-i18n="editButton">Düzenle</a>
                                <form method="post" action="supplier-evaluations.php" onsubmit="return confirm('Kayıt silinsin mi?');"><?= qmsCsrfField($csrfScope) ?><input type="hidden" name="form_type" value="delete"><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><button class="danger-button danger-button-sm" type="submit" data-i18n="supplierEvalDelete">Sil</button></form>
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
