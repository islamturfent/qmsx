<?php

use Dompdf\Dompdf;
use Dompdf\Options;

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/access.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/audit-log-functions.php';

$userId = (int) ($_SESSION["qms_user_id"] ?? 0);
$role = qmsCurrentRole();

// Denetim izi raporlama surum yonetim rollerine aciktir (RBAC tek kaynak).
if (!qmsCanSession('audit_trail.view')) {
    header("Location: dashboard.php");
    exit;
}

// Filtre: yalniz onayli degerler.
$filter = [
    "company_id" => (int) ($_GET["company_id"] ?? 0),
    "entity_type" => (string) ($_GET["entity_type"] ?? ""),
    "action" => (string) ($_GET["action"] ?? ""),
    "from" => (string) ($_GET["from"] ?? ""),
    "to" => (string) ($_GET["to"] ?? ""),
];
if ($filter["entity_type"] !== "" && !isset(qmsAuditLogEntityLabels()[$filter["entity_type"]])) {
    $filter["entity_type"] = "";
}
if ($filter["action"] !== "" && !isset(qmsAuditLogActionLabels()[$filter["action"]])) {
    $filter["action"] = "";
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter["from"]) !== 1) {
    $filter["from"] = "";
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter["to"]) !== 1) {
    $filter["to"] = "";
}

$entityLabels = qmsAuditLogEntityLabels();
$actionLabels = qmsAuditLogActionLabels();

$rows = qmsAuditLogList($pdo, $userId, $role, $filter);

// Ozet: kayit turu ve islem bazinda toplu sayilar (tek kaynak yardimci).
$auditAgg = qmsAuditLogAggregate($rows);
$entityCounts = $auditAgg['entity'];
$actionCounts = $auditAgg['action'];
$totalEntries = $auditAgg['total'];
$periodFrom = $filter["from"] !== "" ? $filter["from"] : '•';
$periodTo = $filter["to"] !== "" ? $filter["to"] : '•';

$buildQuery = static function (array $extra = []) use ($filter): string {
    return http_build_query(array_merge([
        "company_id" => $filter["company_id"],
        "entity_type" => $filter["entity_type"],
        "action" => $filter["action"],
        "from" => $filter["from"],
        "to" => $filter["to"],
    ], $extra));
};

// ---------- CSV export ----------
if (($_GET["export"] ?? "") === "csv") {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="qms-denetim-izi-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM
    fputcsv($out, ['Tarih', 'Kisi', 'Kayit turu', 'Islem', 'Ozet', 'Sirket', 'IP']);
    foreach ($rows as $entry) {
        fputcsv($out, [
            date('d.m.Y H:i', strtotime($entry['created_at'])),
            $entry['actor_name'] ?? 'Sistem',
            $entityLabels[$entry['entity_type']] ?? $entry['entity_type'],
            $actionLabels[$entry['action']] ?? $entry['action'],
            (string) $entry['summary'],
            $entry['company_name'] ?? 'Sistem',
            (string) ($entry['ip_address'] ?? ''),
        ]);
    }
    fclose($out);
    exit;
}

// ---------- PDF export ----------
if (($_GET["export"] ?? "") === "pdf") {
    require_once __DIR__ . '/includes/app-ui.php';
    require_once __DIR__ . '/lib/dompdf/autoload.inc.php';

    $escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

    $entityRows = '';
    foreach ($entityLabels as $key => $label) {
        $entityRows .= '<tr><td>' . $escape($label) . '</td><td>' . (int) $entityCounts[$key] . '</td></tr>';
    }
    $actionRows = '';
    foreach ($actionLabels as $key => $label) {
        $actionRows .= '<tr><td>' . $escape($label) . '</td><td>' . (int) $actionCounts[$key] . '</td></tr>';
    }
    $detailRows = '';
    foreach ($rows as $entry) {
        $detailRows .= '<tr><td>' . $escape(date('d.m.Y H:i', strtotime($entry['created_at']))) . '</td>'
            . '<td>' . $escape($entry['actor_name'] ?? 'Sistem') . '</td>'
            . '<td>' . $escape($entityLabels[$entry['entity_type']] ?? $entry['entity_type']) . '</td>'
            . '<td>' . $escape($actionLabels[$entry['action']] ?? $entry['action']) . '</td>'
            . '<td>' . $escape((string) $entry['summary']) . '</td>'
            . '<td>' . $escape($entry['company_name'] ?? 'Sistem') . '</td></tr>';
    }

    $html = '<!DOCTYPE html><html lang="tr"><head><meta charset="UTF-8"><style>
        body { font-family: "DejaVu Sans", sans-serif; font-size: 12px; color: #1d2433; }
        h1 { font-size: 20px; color: #1d2433; margin: 0 0 2px; }
        h2 { font-size: 14px; color: #1d2433; margin: 22px 0 8px; }
        .meta { color: #64748b; font-size: 11px; margin: 0 0 18px; }
        table.data { width: 100%; border-collapse: collapse; margin-top: 6px; }
        table.data th { background: #465fff; color: #fff; padding: 6px; text-align: left; }
        table.data td { border: 1px solid #e4e7ec; padding: 6px; }
        .footer { margin-top: 24px; font-size: 10px; color: #94a3b8; text-align: center; }
    </style></head><body>
        <h1>Denetim İzi Raporu</h1>
        <p class="meta">Dönem: ' . $escape($periodFrom) . ' - ' . $escape($periodTo) . ' &middot; Toplam kayıt: ' . (int) $totalEntries . '
            &middot; Oluşturulma: ' . date('d.m.Y H:i') . '</p>
        <h2>Kayıt Türü Özeti</h2>
        <table class="data"><thead><tr><th>Kayıt Türü</th><th>Adet</th></tr></thead><tbody>' . $entityRows . '</tbody></table>
        <h2>İşlem Özeti</h2>
        <table class="data"><thead><tr><th>İşlem</th><th>Adet</th></tr></thead><tbody>' . $actionRows . '</tbody></table>
        <h2>Kayıtlar</h2>
        <table class="data"><thead><tr><th>Tarih</th><th>Kişi</th><th>Kayıt Türü</th><th>İşlem</th><th>Özet</th><th>Şirket</th></tr></thead><tbody>' . $detailRows . '</tbody></table>
        <div class="footer">QuAmi tarafından yetkili kullanıcı için oluşturulmuştur.</div>
    </body></html>';

    $options = new Options();
    $options->set('defaultFont', 'DejaVu Sans');
    $options->set('isRemoteEnabled', false);
    $options->setChroot(__DIR__);
    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();
    $dompdf->stream('qms-denetim-izi-' . date('Y-m-d') . '.pdf', ['Attachment' => true]);
    exit;
}

$companyScope = qmsCompanyScope('companies.id', qmsVisibleCompanyIds($pdo, $userId, $role));
$companyStmt = $pdo->prepare('SELECT companies.id, companies.company_name FROM companies WHERE companies.active = 1' . $companyScope['sql'] . ' ORDER BY companies.company_name');
$companyStmt->execute($companyScope['params']);
$companies = $companyStmt->fetchAll(PDO::FETCH_ASSOC);

$activeNav = "audit-trail";

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f9fafb">
    <title>QuAmi Denetim İzi Raporu</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar">
        <div class="topbar-inner">
            <div class="page-title-block">
                <strong data-i18n="auditTrailReportTitle">Denetim İzi Raporu</strong>
                <span data-i18n="auditTrailReportText">Salt-okunur kayıtların özet ve ihraç raporu.</span>
            </div>
            <div class="topbar-actions">
                <button class="topbar-button" id="languageToggle" type="button">EN</button>
                <button class="topbar-button" id="themeToggle" type="button" aria-label="Tema değiştir">🌙</button>
            </div>
        </div>
    </header>
    <main class="page-container">
        <section class="page-heading page-heading-actions">
            <div>
                <span class="section-kicker" data-i18n="auditTrailKicker">Sistem Yönetimi</span>
                <h1 data-i18n="auditTrailReportTitle">Denetim İzi Raporu</h1>
                <p data-i18n="auditTrailReportText">Değiştirilemeyen kayıtların filtreli özeti; CSV/PDF olarak dışa aktarılabilir.</p>
            </div>
            <div class="report-export-actions">
                <a class="primary-button" href="audit-trail-report.php?<?= htmlspecialchars($buildQuery(['export' => 'csv']), ENT_QUOTES, 'UTF-8') ?>" data-i18n="downloadCsvButton">CSV İndir</a>
                <a class="primary-button" href="audit-trail-report.php?<?= htmlspecialchars($buildQuery(['export' => 'pdf']), ENT_QUOTES, 'UTF-8') ?>" data-i18n="downloadPdfButton">PDF İndir</a>
            </div>
        </section>

        <section class="filter-panel">
            <form class="filter-form" method="get" action="audit-trail-report.php">
                <label><span data-i18n="companySelectLabel">Şirket</span><select name="company_id"><option value="0" data-i18n="allCompaniesOption">Tümü</option><?php foreach ($companies as $company): ?><option value="<?= (int) $company['id'] ?>" <?= $filter['company_id'] === (int) $company['id'] ? 'selected' : '' ?>><?= htmlspecialchars($company['company_name'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></label>
                <label><span data-i18n="auditEntityTypeLabel">Kayıt Türü</span><select name="entity_type"><option value="" data-i18n="allTypesOption">Tümü</option><?php foreach ($entityLabels as $key => $label): ?><option value="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>" <?= $filter['entity_type'] === $key ? 'selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></label>
                <label><span data-i18n="auditActionLabel">İşlem</span><select name="action"><option value="" data-i18n="allActionsOption">Tümü</option><?php foreach ($actionLabels as $key => $label): ?><option value="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>" <?= $filter['action'] === $key ? 'selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></label>
                <label><span data-i18n="fromDateLabel">Başlangıç</span><input type="date" name="from" value="<?= htmlspecialchars($filter['from'], ENT_QUOTES, 'UTF-8') ?>"></label>
                <label><span data-i18n="toDateLabel">Bitiş</span><input type="date" name="to" value="<?= htmlspecialchars($filter['to'], ENT_QUOTES, 'UTF-8') ?>"></label>
                <div class="filter-actions">
                    <button class="primary-button" type="submit" data-i18n="filterButton">Filtrele</button>
                    <a class="secondary-button" href="audit-trail-report.php" data-i18n="clearFilterButton">Temizle</a>
                </div>
            </form>
        </section>

        <section class="page-section">
            <div class="section-heading">
                <div><h2 data-i18n="auditTrailSummaryTitle">Özet</h2><p data-i18n="auditTrailSummaryText">Toplam kayıt: <?= (int) $totalEntries ?> · <?= htmlspecialchars($periodFrom, ENT_QUOTES, 'UTF-8') ?> - <?= htmlspecialchars($periodTo, ENT_QUOTES, 'UTF-8') ?></p></div>
            </div>
            <div class="dashboard-grid report-kpi-grid">
                <div class="dashboard-card metric-blue"><?= appIcon("reports", "dashboard-card-icon") ?><div class="dashboard-card-content"><span class="dashboard-card-label" data-i18n="auditTrailEntriesLabel">Kayıt</span><strong class="dashboard-card-number"><?= (int) $totalEntries ?></strong></div></div>
                <div class="dashboard-card metric-violet"><?= appIcon("users", "dashboard-card-icon") ?><div class="dashboard-card-content"><span class="dashboard-card-label" data-i18n="auditTrailEntitySummaryLabel">Kayıt Türü</span><strong class="dashboard-card-number"><?= count(array_filter($entityCounts)) ?></strong></div></div>
                <div class="dashboard-card metric-teal"><?= appIcon("checkBadge", "dashboard-card-icon") ?><div class="dashboard-card-content"><span class="dashboard-card-label" data-i18n="auditTrailActionSummaryLabel">İşlem Türü</span><strong class="dashboard-card-number"><?= count(array_filter($actionCounts)) ?></strong></div></div>
            </div>
        </section>

        <section class="page-section">
            <div class="section-heading"><div><h2 data-i18n="auditTrailEntitySummaryTitle">Kayıt Türü Dağılımı</h2></div></div>
            <div class="status-chart">
                <?php foreach ($entityLabels as $key => $label): ?>
                    <?php if (($entityCounts[$key] ?? 0) > 0): $mx = max(1, max($entityCounts)); ?>
                        <div class="status-chart-row"><span><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span><div><i style="width: <?= (int) round(($entityCounts[$key] / $mx) * 100) ?>%"></i></div><strong><?= (int) $entityCounts[$key] ?></strong></div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="page-section">
            <div class="section-heading"><div><h2 data-i18n="auditTrailActionSummaryTitle">İşlem Dağılımı</h2></div></div>
            <div class="status-chart">
                <?php foreach ($actionLabels as $key => $label): ?>
                    <?php if (($actionCounts[$key] ?? 0) > 0): $mx = max(1, max($actionCounts)); ?>
                        <div class="status-chart-row"><span><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span><div><i style="width: <?= (int) round(($actionCounts[$key] / $mx) * 100) ?>%"></i></div><strong><?= (int) $actionCounts[$key] ?></strong></div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="page-section">
            <div class="section-heading"><div><h2 data-i18n="auditTrailEntriesLabel">Denetim İzi Kayıtları</h2></div></div>
            <?php if (!$rows): ?>
                <div class="empty-state" data-i18n="noAuditTrailEntriesText">Bu filtre için denetim izi kaydı bulunmuyor.</div>
            <?php else: ?>
                <div class="report-table-wrap"><table class="report-table"><thead><tr><th data-i18n="auditTrailDateLabel">Tarih</th><th data-i18n="auditTrailActorLabel">Kişi</th><th data-i18n="auditEntityTypeLabel">Kayıt Türü</th><th data-i18n="auditActionLabel">İşlem</th><th data-i18n="auditTrailSummaryText">Özet</th><th data-i18n="companyNameLabel">Şirket</th></tr></thead><tbody>
                    <?php foreach ($rows as $entry): ?>
                        <tr>
                            <td><?= htmlspecialchars(date('d.m.Y H:i', strtotime($entry['created_at'])), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string) ($entry['actor_name'] ?? 'Sistem'), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($entityLabels[$entry['entity_type']] ?? $entry['entity_type'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($actionLabels[$entry['action']] ?? $entry['action'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string) $entry['summary'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string) ($entry['company_name'] ?? 'Sistem'), ENT_QUOTES, 'UTF-8') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody></table></div>
            <?php endif; ?>
        </section>
    </main>
    <script src="assets/js/theme.js"></script>
    <script src="assets/js/language.js"></script>
    <script src="assets/js/sidebar.js"></script>
    <script src="assets/js/pwa.js"></script>
</body>
</html>
