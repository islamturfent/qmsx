<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/access.php';
qmsRequirePermission('operations.view');
require_once __DIR__ . '/includes/app-ui.php';

$userId = (int) ($_SESSION["qms_user_id"] ?? 0);
$role = qmsCurrentRole();
if (qmsIsAuditor()) {
    header("Location: my-audits.php");
    exit;
}

$companyIds = qmsVisibleCompanyIds($pdo, $userId, $role);
$isAll = $companyIds === null;
$scopeSql = $isAll ? '' : ($companyIds ? ' AND d.company_id IN (' . implode(',', array_map('intval', $companyIds)) . ')' : ' AND 1 = 0');

// Red oranı eşiği varsayılan %5; QMS_DELIVERY_REJECT_THRESHOLD ile ayarlanabilir.
$rejectThreshold = 0.05;
$env = getenv('QMS_DELIVERY_REJECT_THRESHOLD');
if ($env !== false && is_numeric($env)) {
    $rejectThreshold = max(0.01, min(0.99, (float) $env));
}

$stmt = $pdo->query(
    "SELECT d.customer_name, d.company_id, co.company_name,
            COUNT(*) AS periods,
            COALESCE(SUM(d.orders_total), 0) AS total_orders,
            COALESCE(SUM(d.on_time_orders), 0) AS on_time_orders,
            COALESCE(SUM(d.quantity_delivered), 0) AS qty_delivered,
            COALESCE(SUM(d.quantity_rejected), 0) AS qty_rejected
     FROM delivery_performance d
     INNER JOIN companies co ON co.id = d.company_id
     WHERE d.active = 1" . $scopeSql . "
     GROUP BY d.customer_name, d.company_id
     ORDER BY d.customer_name ASC"
);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$cards = [];
$totalOnTimeRateSum = 0.0;
$totalRejectRateSum = 0.0;
$lowPerformanceCount = 0;
foreach ($rows as $r) {
    $totalOrders = (int) $r['total_orders'];
    $qtyDelivered = (int) $r['qty_delivered'];
    $onTimeRate = $totalOrders > 0 ? ($r['on_time_orders'] / $totalOrders) * 100 : null;
    $rejectRate = $qtyDelivered > 0 ? ($r['qty_rejected'] / $qtyDelivered) * 100 : null;
    if ($onTimeRate !== null) { $totalOnTimeRateSum += $onTimeRate; }
    if ($rejectRate !== null) { $totalRejectRateSum += $rejectRate; }
    $low = $rejectRate !== null && $rejectRate > $rejectThreshold * 100;
    if ($low) { $lowPerformanceCount++; }
    $cards[] = [
        'customer' => $r['customer_name'],
        'company' => $r['company_name'],
        'periods' => (int) $r['periods'],
        'total_orders' => $totalOrders,
        'on_time_orders' => (int) $r['on_time_orders'],
        'on_time_rate' => $onTimeRate,
        'reject_rate' => $rejectRate,
        'low' => $low,
    ];
}

$customerCount = count($cards);
$avgOnTime = $customerCount > 0 ? round($totalOnTimeRateSum / $customerCount, 1) : 0;
$avgReject = $customerCount > 0 ? round($totalRejectRateSum / $customerCount, 1) : 0;

$activeNav = "customer_performance";

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f9fafb">
    <title>QuAmi Müşteri Teslimat / Performans</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar">
        <div class="topbar-inner">
            <div class="page-title-block">
                <strong data-i18n="customerPerformanceTitle">Müşteri Teslimat / Performans</strong>
                <span data-i18n="customerPerformanceText">Müşteri bazlı teslimat ve red performansı.</span>
            </div>
            <div class="topbar-actions">
                <button class="topbar-button" id="languageToggle" type="button">EN</button>
                <button class="topbar-button" id="themeToggle" type="button" aria-label="Tema değiştir">🌙</button>
            </div>
        </div>
    </header>
    <main class="page-container">
        <section class="page-heading">
            <span class="section-kicker" data-i18n="customerPerformanceKicker">Teslimat Performansı</span>
            <h1 data-i18n="customerPerformanceTitle">Müşteri Teslimat / Performans</h1>
            <p data-i18n="customerPerformanceText">Her müşterinin zamanında teslimat ve red oranının güncel görünümü.</p>
        </section>

        <section class="dashboard-grid compact-dashboard-grid">
            <div class="dashboard-card metric-blue">
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label">Müşteri</span>
                    <strong class="dashboard-card-number"><?= $customerCount ?></strong>
                </div>
            </div>
            <div class="dashboard-card metric-teal">
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label">Ort. Zamanında Teslimat</span>
                    <strong class="dashboard-card-number">%<?= $avgOnTime ?></strong>
                </div>
            </div>
            <div class="dashboard-card metric-orange">
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label">Ort. Red Oranı</span>
                    <strong class="dashboard-card-number">%<?= $avgReject ?></strong>
                </div>
            </div>
            <div class="dashboard-card <?= $lowPerformanceCount > 0 ? 'metric-red' : '' ?>">
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label">Düşük Performanslı Müşteri</span>
                    <strong class="dashboard-card-number"><?= $lowPerformanceCount ?></strong>
                </div>
            </div>
        </section>

        <section class="page-section">
            <div class="section-heading"><h2 data-i18n="customerPerformanceListTitle">Müşteri Kartları</h2></div>
            <?php if (!$cards): ?>
                <div class="empty-state" data-i18n="customerPerformanceEmpty">Henüz teslimat performansı kaydı yok.</div>
            <?php else: ?>
                <div class="record-card-grid">
                    <?php foreach ($cards as $card): ?>
                        <div class="record-card document-card <?= $card['low'] ? 'card-overdue' : '' ?>">
                            <div class="record-card-topline">
                                <span><?= htmlspecialchars($card['company'], ENT_QUOTES, 'UTF-8') ?></span>
                                <?php if ($card['low']): ?><span class="status-badge">Düşük Performans</span><?php endif; ?>
                            </div>
                            <h3><?= htmlspecialchars($card['customer'], ENT_QUOTES, 'UTF-8') ?></h3>
                            <div class="metric-mini-row">
                                <div>
                                    <span class="metric-mini-label">Zamanında Teslimat</span>
                                    <strong><?= $card['on_time_rate'] !== null ? '%' . round($card['on_time_rate'], 1) : '—' ?></strong>
                                </div>
                                <div>
                                    <span class="metric-mini-label">Red Oranı</span>
                                    <strong><?= $card['reject_rate'] !== null ? '%' . round($card['reject_rate'], 1) : '—' ?></strong>
                                </div>
                                <div>
                                    <span class="metric-mini-label">Sipariş</span>
                                    <strong><?= (int) $card['total_orders'] ?></strong>
                                </div>
                                <div>
                                    <span class="metric-mini-label">Dönem</span>
                                    <strong><?= (int) $card['periods'] ?></strong>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </main>
    <script src="assets/js/theme.js"></script>
    <script src="assets/js/language.js"></script>
    <script src="assets/js/sidebar.js"></script>
    <script src="assets/js/pwa.js"></script>
</body>
</html>
