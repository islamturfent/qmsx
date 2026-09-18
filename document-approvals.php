<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';

$userId = (int) ($_SESSION["qms_user_id"] ?? 0);
$isSuperAdmin = ($_SESSION["qms_role"] ?? "") === "super_admin";
$decisionFilter = $_GET["decision"] ?? "";
$allowedFilters = ["pending", "approved", "rejected"];

$sql = "SELECT document_approvals.*, documents.document_code, documents.title,
               documents.current_revision, companies.company_name,
               requester.full_name AS requester_name, approver.full_name AS approver_name
        FROM document_approvals
        INNER JOIN documents ON documents.id = document_approvals.document_id
        INNER JOIN companies ON companies.id = documents.company_id
        LEFT JOIN users requester ON requester.id = document_approvals.requested_by
        INNER JOIN users approver ON approver.id = document_approvals.approver_user_id
        WHERE documents.active = 1";
$params = [];
if (!$isSuperAdmin) {
    $sql .= " AND document_approvals.approver_user_id = :user_id";
    $params["user_id"] = $userId;
}
if (in_array($decisionFilter, $allowedFilters, true)) {
    $sql .= " AND document_approvals.decision = :decision";
    $params["decision"] = $decisionFilter;
}
$sql .= " ORDER BY (document_approvals.decision = 'pending') DESC, document_approvals.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$approvals = $stmt->fetchAll(PDO::FETCH_ASSOC);

$countsSql = "SELECT decision, COUNT(*) total FROM document_approvals WHERE 1 = 1";
$countParams = [];
if (!$isSuperAdmin) {
    $countsSql .= " AND approver_user_id = :user_id";
    $countParams["user_id"] = $userId;
}
$countsSql .= " GROUP BY decision";
$countsStmt = $pdo->prepare($countsSql);
$countsStmt->execute($countParams);
$counts = ["pending" => 0, "approved" => 0, "rejected" => 0];
foreach ($countsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    if (isset($counts[$row["decision"]])) $counts[$row["decision"]] = (int) $row["total"];
}

$decisionLabels = ["pending" => "Bekliyor", "approved" => "Onaylandı", "rejected" => "Reddedildi"];
$activeNav = "document_approvals";

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="theme-color" content="#f9fafb">
    <title>QMS Doküman Onay Kutusu</title><link rel="manifest" href="manifest.webmanifest"><link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml"><link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar"><div class="topbar-inner"><div class="page-title-block"><strong data-i18n="approvalInboxTitle">Doküman Onay Kutusu</strong><span data-i18n="approvalInboxText">Bekleyen talepleri ve önceki kararları izleyin.</span></div><div class="topbar-actions"><button class="topbar-button" id="languageToggle" type="button">EN</button><button class="topbar-button" id="themeToggle" type="button" aria-label="Tema değiştir">🌙</button></div></div></header>
    <main class="page-container">
        <section class="page-heading"><span class="section-kicker" data-i18n="documentManagementTitle">Doküman Yönetimi</span><h1 data-i18n="approvalInboxTitle">Doküman Onay Kutusu</h1><p data-i18n="approvalInboxText">Bekleyen talepleri ve önceki kararları izleyin.</p></section>
        <section class="dashboard-grid compact-dashboard-grid">
            <a class="dashboard-card metric-orange" href="document-approvals.php?decision=pending"><?= appIcon("alert", "dashboard-card-icon") ?><div class="dashboard-card-content"><span class="dashboard-card-label" data-i18n="pendingApprovalsLabel">Bekleyen Onaylar</span><strong class="dashboard-card-number"><?= $counts["pending"] ?></strong></div></a>
            <a class="dashboard-card metric-teal" href="document-approvals.php?decision=approved"><?= appIcon("check", "dashboard-card-icon") ?><div class="dashboard-card-content"><span class="dashboard-card-label" data-i18n="approvedRecordsLabel">Onaylananlar</span><strong class="dashboard-card-number"><?= $counts["approved"] ?></strong></div></a>
            <a class="dashboard-card metric-red" href="document-approvals.php?decision=rejected"><?= appIcon("xmark", "dashboard-card-icon") ?><div class="dashboard-card-content"><span class="dashboard-card-label" data-i18n="rejectedRecordsLabel">Reddedilenler</span><strong class="dashboard-card-number"><?= $counts["rejected"] ?></strong></div></a>
        </section>
        <section class="page-section">
            <div class="section-heading"><div><h2 data-i18n="approvalRequestsTitle">Onay Talepleri</h2><p><span data-i18n="filteredRecordsLabel">Gösterilen kayıt</span>: <strong><?= count($approvals) ?></strong></p></div><a class="primary-button" href="document-approvals.php" data-i18n="showAllButton">Tümünü Göster</a></div>
            <?php if (!$approvals): ?><div class="empty-state" data-i18n="noApprovalRequestsText">Gösterilecek onay talebi bulunmuyor.</div><?php else: ?>
                <div class="action-list">
                    <?php foreach ($approvals as $approval): ?>
                        <a class="action-list-item" href="document-detail.php?id=<?= (int) $approval["document_id"] ?>">
                            <div class="action-list-main"><div class="action-list-badges"><span class="status-badge status-<?= htmlspecialchars($approval["decision"], ENT_QUOTES, "UTF-8") ?>"><?= htmlspecialchars($decisionLabels[$approval["decision"]] ?? $approval["decision"], ENT_QUOTES, "UTF-8") ?></span></div><h3><?= htmlspecialchars($approval["document_code"], ENT_QUOTES, "UTF-8") ?> · <?= htmlspecialchars($approval["title"], ENT_QUOTES, "UTF-8") ?></h3><p><?= htmlspecialchars($approval["company_name"], ENT_QUOTES, "UTF-8") ?> · Rev. <?= htmlspecialchars($approval["current_revision"], ENT_QUOTES, "UTF-8") ?></p></div>
                            <div class="action-list-meta"><span><strong data-i18n="requesterLabel">Talep Eden</strong><?= htmlspecialchars($approval["requester_name"] ?: "-", ENT_QUOTES, "UTF-8") ?></span><span><strong data-i18n="approverLabel">Onay Yetkilisi</strong><?= htmlspecialchars($approval["approver_name"], ENT_QUOTES, "UTF-8") ?></span><span><strong data-i18n="requestDateLabel">Talep Tarihi</strong><?= htmlspecialchars($approval["requested_at"], ENT_QUOTES, "UTF-8") ?></span></div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </main>
    <script src="assets/js/theme.js"></script><script src="assets/js/language.js"></script><script src="assets/js/sidebar.js"></script><script src="assets/js/pwa.js"></script>
</body>
</html>
