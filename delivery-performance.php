<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/access.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/delivery-performance-functions.php';

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

$csrfScope = 'delivery_performance';
$formError = '';
$editing = null;
$editId = (int) ($_GET['edit'] ?? 0);
if ($editId > 0) {
    $editing = qmsDeliveryFind($pdo, $editId, $userId, $role);
    if (!$editing) {
        $editId = 0;
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    qmsCsrfVerify($csrfScope, $_POST["csrf"] ?? null);
    $formType = (string) ($_POST["form_type"] ?? "");

    if ($formType === "add" || $formType === "update") {
        $data = [
            "customer_name" => (string) ($_POST["customer_name"] ?? ""),
            "period" => (string) ($_POST["period"] ?? ""),
            "orders_total" => (int) ($_POST["orders_total"] ?? 0),
            "on_time_orders" => (int) ($_POST["on_time_orders"] ?? 0),
            "quantity_delivered" => (int) ($_POST["quantity_delivered"] ?? 0),
            "quantity_rejected" => (int) ($_POST["quantity_rejected"] ?? 0),
            "notes" => (string) ($_POST["notes"] ?? ""),
        ];
        if ($formType === "add") {
            $data["company_id"] = (int) ($_POST["company_id"] ?? 0);
            $newId = qmsDeliveryAdd($pdo, $data, $userId, $role);
            if ($newId !== null) {
                header("Location: delivery-performance.php?added=1");
                exit;
            }
            $formError = "Kayıt eklenemedi. Geçerli bir şirket, müşteri ve dönem (YYYY-MM) girin.";
        } else {
            $ok = qmsDeliveryUpdate($pdo, (int) ($_POST["id"] ?? 0), $data, $userId, $role);
            if ($ok) {
                header("Location: delivery-performance.php?updated=1");
                exit;
            }
            $formError = "Kayıt güncellenemedi.";
        }
    } elseif ($formType === "delete") {
        qmsDeliveryDelete($pdo, (int) ($_POST["id"] ?? 0), $userId, $role);
        header("Location: delivery-performance.php?deleted=1");
        exit;
    } elseif ($formType === "create_nc") {
        $nc = qmsDeliveryCreateNonconformity($pdo, (int) ($_POST["id"] ?? 0), $userId, $role);
        if ($nc !== null) {
            header("Location: nonconformity-detail.php?id=" . $nc);
            exit;
        }
        $formError = "Uygunsuzluk oluşturulamadı (red miktarı 0 veya kayıt bulunamadı).";
    }
}

$periodFilter = (string) ($_GET["period"] ?? "");
if ($periodFilter !== '' && !preg_match('/^\d{4}-\d{2}$/', $periodFilter)) {
    $periodFilter = '';
}
$rows = qmsDeliveryList($pdo, $userId, $role, $periodFilter);
$allRows = qmsDeliveryList($pdo, $userId, $role);
$periods = [];
foreach ($allRows as $r) {
    $periods[(string) $r['period']] = true;
}
arsort($periods);
$periods = array_keys($periods);

// Özet KPI'lar (filtreye gore).
$sumOrders = 0;
$sumOnTime = 0;
$sumDelivered = 0;
$sumRejected = 0;
foreach ($rows as $r) {
    $sumOrders += (int) $r['orders_total'];
    $sumOnTime += (int) $r['on_time_orders'];
    $sumDelivered += (int) $r['quantity_delivered'];
    $sumRejected += (int) $r['quantity_rejected'];
}
$onTimePct = $sumOrders > 0 ? round(($sumOnTime / $sumOrders) * 100, 1) : 0;
$rejectPct = $sumDelivered > 0 ? round(($sumRejected / $sumDelivered) * 100, 1) : 0;

$activeNav = "delivery_performance";
$selectedCompanyId = (int) ($_GET["company_id"] ?? 0);
$defaultPeriod = date('Y-m');
$prefill = $editing ?: [
    'customer_name' => '', 'period' => $defaultPeriod, 'orders_total' => 0, 'on_time_orders' => 0,
    'quantity_delivered' => 0, 'quantity_rejected' => 0, 'notes' => '',
];
if ($editing) {
    $selectedCompanyId = (int) $editing['company_id'];
}

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="theme-color" content="#f9fafb">
    <title>QuAmi Teslimat Performansı</title><link rel="manifest" href="manifest.webmanifest"><link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml"><link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar"><div class="topbar-inner"><div class="page-title-block"><strong data-i18n="deliveryTitle">Müşteri Teslimat Performansı</strong><span data-i18n="deliveryText">Teslimat ve kalite göstergelerini izleyin.</span></div><div class="topbar-actions"><button class="topbar-button" id="languageToggle" type="button">EN</button><button class="topbar-button" id="themeToggle" type="button" aria-label="Tema değiştir">🌙</button></div></div></header>
    <main class="page-container">
        <section class="page-heading">
            <div>
                <span class="section-kicker" data-i18n="deliveryKicker">Müşteri Odaklılık</span>
                <h1 data-i18n="deliveryTitle">Müşteri Teslimat Performans Kartı</h1>
                <p data-i18n="deliveryText">Müşteri başına dönemsel zamanında teslim oranı, teslim edilen ve reddedilen miktarı kaydedin.</p>
            </div>
        </section>

        <?php if ($formError !== ""): ?><div class="form-message error"><?= htmlspecialchars($formError, ENT_QUOTES, "UTF-8") ?></div><?php endif; ?>
        <?php if (($_GET["added"] ?? "") === "1"): ?><div class="form-message success" data-i18n="deliveryAdded">Kayıt eklendi.</div><?php endif; ?>
        <?php if (($_GET["updated"] ?? "") === "1"): ?><div class="form-message success" data-i18n="deliveryUpdated">Kayıt güncellendi.</div><?php endif; ?>
        <?php if (($_GET["deleted"] ?? "") === "1"): ?><div class="form-message success" data-i18n="deliveryDeleted">Kayıt silindi.</div><?php endif; ?>

        <div class="record-card-grid">
            <div class="dashboard-card"><div class="dashboard-card-content"><div class="dashboard-card-label" data-i18n="deliveryOnTimeKpi">Zamanında Teslim</div><div class="dashboard-card-number">%<?= $onTimePct ?></div></div></div>
            <div class="dashboard-card"><div class="dashboard-card-content"><div class="dashboard-card-label" data-i18n="deliveryOrdersKpi">Sipariş</div><div class="dashboard-card-number"><?= $sumOrders ?></div></div></div>
            <div class="dashboard-card"><div class="dashboard-card-content"><div class="dashboard-card-label" data-i18n="deliveryRejectKpi">Reddedilen</div><div class="dashboard-card-number">%<?= $rejectPct ?></div></div></div>
        </div>

        <div class="two-col">
            <section class="form-panel">
                <div class="section-heading compact-heading"><div><h3><?= $editing ? 'Kaydı Düzenle' : 'Yeni Kayıt' ?></h3><?php if ($editing): ?><p>#<?= (int) $editing['id'] ?> düzenleniyor</p><?php endif; ?></div></div>
                <?php if (!$companies): ?><div class="form-message error" data-i18n="deliveryNoCompany">Önce bir şirket gerekir.</div>
                <?php else: ?>
                <form class="auditor-form" method="post" action="delivery-performance.php<?= $editing ? '?edit=' . (int) $editing['id'] : '' ?>">
                    <?= qmsCsrfField($csrfScope) ?>
                    <input type="hidden" name="form_type" value="<?= $editing ? 'update' : 'add' ?>">
                    <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>
                    <div class="form-grid">
                        <label class="form-field"><span data-i18n="companySelectLabel">Şirket</span><select name="company_id" <?= $editing ? 'disabled' : 'required' ?>><option value="0" data-i18n="selectCompanyOption">Şirket seçin</option><?php foreach ($companies as $company): ?><option value="<?= (int) $company['id'] ?>" <?= $selectedCompanyId === (int) $company['id'] ? 'selected' : '' ?>><?= htmlspecialchars($company['company_name'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></label>
                        <label class="form-field"><span data-i18n="deliveryCustomerLabel">Müşteri</span><input type="text" name="customer_name" required maxlength="190" value="<?= htmlspecialchars((string) $prefill['customer_name'], ENT_QUOTES, 'UTF-8') ?>"></label>
                        <label class="form-field"><span data-i18n="deliveryPeriodLabel">Dönem (YYYY-MM)</span><input type="month" name="period" required value="<?= htmlspecialchars((string) $prefill['period'], ENT_QUOTES, 'UTF-8') ?>"></label>
                        <label class="form-field"><span data-i18n="deliveryOrdersLabel">Toplam Sipariş</span><input type="number" name="orders_total" min="0" value="<?= (int) $prefill['orders_total'] ?>"></label>
                        <label class="form-field"><span data-i18n="deliveryOnTimeLabel">Zamanında Teslim (sipariş)</span><input type="number" name="on_time_orders" min="0" value="<?= (int) $prefill['on_time_orders'] ?>"></label>
                        <label class="form-field"><span data-i18n="deliveryQuantityLabel">Teslim Edilen Miktar</span><input type="number" name="quantity_delivered" min="0" value="<?= (int) $prefill['quantity_delivered'] ?>"></label>
                        <label class="form-field"><span data-i18n="deliveryRejectedLabel">Reddedilen Miktar</span><input type="number" name="quantity_rejected" min="0" value="<?= (int) $prefill['quantity_rejected'] ?>"></label>
                        <label class="form-field form-field-wide"><span data-i18n="deliveryNotesLabel">Not</span><textarea name="notes" rows="3"><?= htmlspecialchars((string) $prefill['notes'], ENT_QUOTES, 'UTF-8') ?></textarea></label>
                    </div>
                    <div class="form-actions"><button class="primary-button" type="submit" data-i18n="deliverySave">Kaydet</button><?php if ($editing): ?><a class="secondary-button" href="delivery-performance.php" data-i18n="deliveryCancel">İptal</a><?php endif; ?></div>
                </form>
                <?php endif; ?>
            </section>

            <section class="console-card checklist-section">
                <div class="section-heading compact-heading"><div><h3 data-i18n="deliveryListTitle">Performans Kartları</h3><p><span data-i18n="filteredRecordsLabel">Gösterilen kayıt</span>: <strong><?= count($rows) ?></strong></p></div><div><form class="filter-inline" method="get" action="delivery-performance.php"><select name="period"><option value="" data-i18n="deliveryAllPeriods">Tüm Dönemler</option><?php foreach ($periods as $pd): ?><option value="<?= htmlspecialchars($pd, ENT_QUOTES, 'UTF-8') ?>" <?= $periodFilter === $pd ? 'selected' : '' ?>><?= htmlspecialchars($pd, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select><button class="secondary-button" type="submit" data-i18n="applyButton">Uygula</button></form></div></div>
                <div class="admin-list">
                    <?php if (!$rows): ?><div class="empty-state" data-i18n="deliveryEmpty">Henüz teslimat kaydı yok.</div><?php endif; ?>
                    <?php foreach ($rows as $row): ?>
                        <?php $rate = qmsDeliveryOnTimeRate($row); ?>
                        <?php $rowNc = (int) $row['quantity_rejected'] > 0 ? qmsDeliveryLinkedNonconformity($pdo, (int) $row['id']) : 0; ?>
                        <div class="admin-list-item">
                            <div class="list-item-main">
                                <strong><?= htmlspecialchars($row['customer_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                <span><?= htmlspecialchars((string) $row['period'], ENT_QUOTES, 'UTF-8') ?> · <?= (int) $row['orders_total'] ?> sipariş · zamanında %<?= $rate ?><?= (int) $row['quantity_rejected'] > 0 ? ' · ' . (int) $row['quantity_rejected'] . ' red' : '' ?></span>
                            </div>
                            <div class="list-item-side">
                                <div class="progress-track" style="width:130px"><div class="progress-fill" style="width:<?= min(100, $rate) ?>%"></div></div>
                                <a class="secondary-button" href="delivery-performance.php?edit=<?= (int) $row['id'] ?>" data-i18n="editButton">Düzenle</a>
                                <?php if ($rowNc > 0): ?>
                                    <a class="secondary-button" href="nonconformity-detail.php?id=<?= $rowNc ?>" data-i18n="deliveryNcOpenButton">Uygunsuzluğu Aç</a>
                                <?php elseif ((int) $row['quantity_rejected'] > 0): ?>
                                    <form method="post" action="delivery-performance.php"><?= qmsCsrfField($csrfScope) ?><input type="hidden" name="form_type" value="create_nc"><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><button class="primary-button primary-button-sm" type="submit" data-i18n="deliveryNcCreateButton">Uygunsuzluk Oluştur</button></form>
                                <?php endif; ?>
                                <form method="post" action="delivery-performance.php" onsubmit="return confirm('Kayıt silinsin mi?');"><?= qmsCsrfField($csrfScope) ?><input type="hidden" name="form_type" value="delete"><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><button class="danger-button danger-button-sm" type="submit" data-i18n="deliveryDelete">Sil</button></form>
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
