<?php

$activeNav = $activeNav ?? "";
$sidebarRole = (string) ($_SESSION["qms_role"] ?? "");
$isSuperAdminNav = $sidebarRole === "super_admin";
$isManagementNav = in_array($sidebarRole, ["super_admin", "system_admin"], true);
$isAuditorNav = $sidebarRole === "auditor";
// Denetci operasyon modullerini ve raporlari gormez; yalniz kendi denetimleri.
$canSeeOperations = !$isAuditorNav;
$sidebarUnreadCount = 0;
if (isset($pdo, $_SESSION["qms_user_id"])) {
    $sidebarNotificationStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = :user_id AND is_read = 0");
    $sidebarNotificationStmt->execute(["user_id" => (int) $_SESSION["qms_user_id"]]);
    $sidebarUnreadCount = (int) $sidebarNotificationStmt->fetchColumn();
}
$sidebarOverdueCount = 0;
$sidebarSupplierEvalOverdue = 0;
if (!empty($pdo) && !$isAuditorNav && isset($_SESSION["qms_user_id"])) {
    require_once __DIR__ . '/due-workbench-functions.php';
    require_once __DIR__ . '/supplier-eval-schedule-functions.php';
    $sidebarOverdueCount = qmsOverdueActionCount($pdo, (int) $_SESSION["qms_user_id"], qmsCurrentRole());
    $sidebarSupplierEvalOverdue = qmsSupplierEvalScheduleOverdueCount($pdo, (int) $_SESSION["qms_user_id"], qmsCurrentRole());
}

function sidebarLinkClass(string $key, string $activeNav): string
{
    return $key === $activeNav ? "sidebar-link active" : "sidebar-link";
}

require_once __DIR__ . '/app-ui.php';

?>

<button class="sidebar-mobile-toggle" id="sidebarToggle" type="button" aria-label="Menüyü aç veya kapat">☰</button>
<span id="qmsNotificationState" data-count="<?= $sidebarUnreadCount ?>" hidden></span>
<span id="qmsNotificationIcon" hidden><?= appIcon("notifications", "") ?></span>
<?php
$headerUserName = (string) ($_SESSION["qms_full_name"] ?? ($_SESSION["qms_username"] ?? ""));
$headerRoleLabel = appRoleLabel((string) ($_SESSION["qms_role"] ?? ""));
$headerHasAvatar = false;
if (isset($pdo, $_SESSION["qms_user_id"])) {
    $headerAvatarStmt = $pdo->prepare("SELECT avatar_file FROM users WHERE id = :id LIMIT 1");
    $headerAvatarStmt->execute(["id" => (int) $_SESSION["qms_user_id"]]);
    $headerAvatarFile = $headerAvatarStmt->fetchColumn();
    $headerHasAvatar = is_string($headerAvatarFile) && $headerAvatarFile !== '';
}
?>
<span id="qmsUserMenuState" hidden
      data-name="<?= htmlspecialchars($headerUserName, ENT_QUOTES, "UTF-8") ?>"
      data-role="<?= htmlspecialchars($headerRoleLabel, ENT_QUOTES, "UTF-8") ?>"
      data-initials="<?= htmlspecialchars(appInitials($headerUserName), ENT_QUOTES, "UTF-8") ?>"
      data-avatar="<?= $headerHasAvatar ? "1" : "0" ?>"></span>

<aside class="app-sidebar" id="appSidebar">
    <a class="sidebar-brand" href="dashboard.php">
        <span class="brand-icon"><img src="assets/icons/qms-logo.png" alt="QMS"></span>
        <span>
            <strong>QMS</strong>
            <small>Quality Management</small>
        </span>
    </a>

    <nav class="sidebar-nav" aria-label="Ana menü">
        <span class="sidebar-section-label" data-i18n="sidebarOverviewLabel">Genel</span>
        <?php if ($isAuditorNav): ?>
            <a class="<?= sidebarLinkClass("my_audits", $activeNav) ?>" href="my-audits.php">
                <?= appIcon("check") ?>
                <span data-i18n="myAuditsTitle">Denetimlerim</span>
            </a>
        <?php else: ?>
            <a class="<?= sidebarLinkClass("dashboard", $activeNav) ?>" href="dashboard.php">
                <?= appIcon("dashboard") ?>
                <span data-i18n="dashboardLinkLabel">Dashboard</span>
            </a>
        <?php endif; ?>
        <?php if (!$isAuditorNav): ?>
        <a class="<?= sidebarLinkClass("my_assignments", $activeNav) ?>" href="my-assignments.php">
            <?= appIcon("checkBadge") ?>
            <span data-i18n="myAssignmentsMenuLabel">Bana Atanmışlar</span>
        </a>
        <?php endif; ?>
        <a class="<?= sidebarLinkClass("notifications", $activeNav) ?>" href="notifications.php">
            <?= appIcon("notifications") ?>
            <span data-i18n="notificationCenterTitle">Bildirim Merkezi</span>
            <?php if ($sidebarUnreadCount > 0): ?><span class="sidebar-count"><?= $sidebarUnreadCount ?></span><?php endif; ?>
        </a>
        <?php if (!$isAuditorNav): ?>
        <a class="<?= sidebarLinkClass("reports", $activeNav) ?>" href="reports.php">
            <?= appIcon("reports") ?>
            <span data-i18n="reportingTitle">Raporlama ve KPI</span>
        </a>
        <a class="<?= sidebarLinkClass("overdue", $activeNav) ?>" href="overdue.php">
            <?= appIcon("alert") ?>
            <span data-i18n="overdueMenuLabel">Vadesi Gelen İşler</span>
        </a>
        <?php endif; ?>

        <?php if ($canSeeOperations): ?>
        <span class="sidebar-section-label" data-i18n="sidebarOperationsLabel">Operasyonlar</span>
        <a class="<?= sidebarLinkClass("search", $activeNav) ?>" href="search.php">
            <?= appIcon("search") ?>
            <span data-i18n="searchMenuLabel">Arama</span>
        </a>
        <a class="<?= sidebarLinkClass("announcements", $activeNav) ?>" href="announcements.php">
            <?= appIcon("complaints") ?>
            <span data-i18n="announcementsMenuLabel">Duyuru Merkezi</span>
        </a>
        <?php if ($isManagementNav): ?>
        <a class="<?= sidebarLinkClass("auditors", $activeNav) ?>" href="auditors.php">
            <?= appIcon("users") ?>
            <span data-i18n="auditorsCardLabel">Denetçiler</span>
        </a>
        <a class="<?= sidebarLinkClass("audit_programs", $activeNav) ?>" href="audit-programs.php">
            <?= appIcon("approvals") ?>
            <span data-i18n="auditProgramsMenuLabel">Denetim Programları</span>
        </a>
        <a class="<?= sidebarLinkClass("checklist_templates", $activeNav) ?>" href="checklist-templates.php">
            <?= appIcon("approvals") ?>
            <span data-i18n="checklistTemplatesMenuLabel">Kontrol Listesi Şablonları</span>
        </a>
        <a class="<?= sidebarLinkClass("external_audits", $activeNav) ?>" href="external-audits.php">
            <?= appIcon("alert") ?>
            <span data-i18n="externalAuditsMenuLabel">Dış Denetim & Kapama</span>
        </a>
        <?php endif; ?>
        <a class="<?= sidebarLinkClass("actions", $activeNav) ?>" href="actions.php">
            <?= appIcon("check") ?>
            <span data-i18n="actionManagementTitle">Düzeltici & Önleyici Faaliyet (CAPA)</span>
            <?php if ($sidebarOverdueCount > 0): ?><span class="sidebar-count"><?= $sidebarOverdueCount ?></span><?php endif; ?>
        </a>
        <a class="<?= sidebarLinkClass("risks", $activeNav) ?>" href="risks.php">
            <?= appIcon("warning") ?>
            <span data-i18n="riskManagementTitle">Risk Yönetimi</span>
        </a>
        <a class="<?= sidebarLinkClass("trainings", $activeNav) ?>" href="trainings.php">
            <?= appIcon("training") ?>
            <span data-i18n="trainingManagementTitle">Eğitim Yönetimi</span>
        </a>
        <a class="<?= sidebarLinkClass("personnel", $activeNav) ?>" href="personnel.php">
            <?= appIcon("users") ?>
            <span data-i18n="personnelMenuLabel">Personel & Yetkinlik</span>
        </a>
        <a class="<?= sidebarLinkClass("suppliers", $activeNav) ?>" href="suppliers.php">
            <?= appIcon("suppliers") ?>
            <span data-i18n="suppliersTitle">Tedarikçi Yönetimi</span>
        </a>
        <a class="<?= sidebarLinkClass("supplier_evaluations", $activeNav) ?>" href="supplier-evaluations.php">
            <?= appIcon("checkBadge") ?>
            <span data-i18n="supplierEvalMenuLabel">Değerlendirme Takvimi</span>
            <?php if ($sidebarSupplierEvalOverdue > 0): ?><span class="sidebar-count"><?= $sidebarSupplierEvalOverdue ?></span><?php endif; ?>
        </a>
        <a class="<?= sidebarLinkClass("complaints", $activeNav) ?>" href="complaints.php">
            <?= appIcon("complaints") ?>
            <span data-i18n="complaintsTitle">Şikayet Yönetimi</span>
        </a>
        <a class="<?= sidebarLinkClass("satisfaction", $activeNav) ?>" href="satisfaction-surveys.php">
            <?= appIcon("complaints") ?>
            <span data-i18n="satisfactionMenuLabel">Müşteri Memnuniyeti</span>
        </a>
        <a class="<?= sidebarLinkClass("internal_surveys", $activeNav) ?>" href="internal-surveys.php">
            <?= appIcon("sparkles") ?>
            <span data-i18n="internalSurveyMenuLabel">İç Memnuniyet Anketi</span>
        </a>
        <a class="<?= sidebarLinkClass("internal_surveys", $activeNav) ?>" href="internal-survey-fill.php">
            <?= appIcon("checkBadge") ?>
            <span data-i18n="internalSurveyFillMenuLabel">Anketi Doldur</span>
        </a>
        <a class="<?= sidebarLinkClass("performance", $activeNav) ?>" href="performance.php">
            <?= appIcon("performance") ?>
            <span data-i18n="performanceTitle">Performans Yönetimi</span>
        </a>
        <a class="<?= sidebarLinkClass("quality_costs", $activeNav) ?>" href="quality-costs.php">
            <?= appIcon("table") ?>
            <span data-i18n="qualityCostMenuLabel">Kalite Maliyeti (COQ)</span>
        </a>
        <a class="<?= sidebarLinkClass("quality_costs", $activeNav) ?>" href="quality-cost-trend.php">
            <?= appIcon("trend") ?>
            <span data-i18n="costTrendMenuLabel">COQ Trendi</span>
        </a>
        <a class="<?= sidebarLinkClass("reviews", $activeNav) ?>" href="reviews.php">
            <?= appIcon("reviews") ?>
            <span data-i18n="reviewsTitle">Yönetimin Gözden Geçirmesi</span>
        </a>
        <a class="<?= sidebarLinkClass("quality_plans", $activeNav) ?>" href="quality-plan.php">
            <?= appIcon("table") ?>
            <span data-i18n="qualityPlanMenuLabel">Yıllık Kalite Planı</span>
        </a>
        <a class="<?= sidebarLinkClass("improvements", $activeNav) ?>" href="improvements.php">
            <?= appIcon("sparkles") ?>
            <span data-i18n="improvementsMenuLabel">İyileştirme Fırsatları</span>
        </a>
        <a class="<?= sidebarLinkClass("processes", $activeNav) ?>" href="processes.php">
            <?= appIcon("table") ?>
            <span data-i18n="processesMenuLabel">Süreç Envanteri</span>
        </a>
        <a class="<?= sidebarLinkClass("contracts", $activeNav) ?>" href="contracts.php">
            <?= appIcon("approvals") ?>
            <span data-i18n="contractsMenuLabel">Sözleşme Yönetimi</span>
        </a>
        <a class="<?= sidebarLinkClass("incidents", $activeNav) ?>" href="incidents.php">
            <?= appIcon("alert") ?>
            <span data-i18n="incidentsMenuLabel">Olay Raporlama</span>
        </a>
        <a class="<?= sidebarLinkClass("documents", $activeNav) ?>" href="documents.php">
            <?= appIcon("documents") ?>
            <span data-i18n="documentManagementTitle">Doküman Yönetimi</span>
        </a>
        <a class="<?= sidebarLinkClass("document_copies", $activeNav) ?>" href="document-distribution.php">
            <?= appIcon("table") ?>
            <span data-i18n="documentDistributionMenuLabel">Dağıtım Kontrolü</span>
        </a>
        <a class="<?= sidebarLinkClass("documents", $activeNav) ?>" href="document-compare.php">
            <?= appIcon("documents") ?>
            <span data-i18n="docCompareMenuLabel">Versiyon Karşılaştırma</span>
        </a>
        <a class="<?= sidebarLinkClass("document_templates", $activeNav) ?>" href="document-templates.php">
            <?= appIcon("documents") ?>
            <span data-i18n="docTemplateMenuLabel">Doküman Şablonları</span>
        </a>
        <a class="<?= sidebarLinkClass("equipment", $activeNav) ?>" href="equipment.php">
            <?= appIcon("table") ?>
            <span data-i18n="equipmentMenuLabel">Ekipman ve Kalibrasyon</span>
        </a>
        <?php if ($isManagementNav): ?>
        <a class="<?= sidebarLinkClass("approvals", $activeNav) ?>" href="approval-runs.php">
            <?= appIcon("approvals") ?>
            <span data-i18n="approvalWorkflowMenuLabel">Onay & İmza Workflow</span>
        </a>
        <a class="<?= sidebarLinkClass("document_reviews", $activeNav) ?>" href="document-reviews.php">
            <?= appIcon("documents") ?>
            <span data-i18n="docReviewMenuLabel">Doküman Gözden Geçirme</span>
        </a>
        <a class="<?= sidebarLinkClass("document_approvals", $activeNav) ?>" href="document-approvals.php">
            <?= appIcon("approvals") ?>
            <span data-i18n="approvalInboxTitle">Doküman Onay Kutusu</span>
        </a>
        <a class="<?= sidebarLinkClass("audit_trail", $activeNav) ?>" href="audit-trail.php">
            <?= appIcon("checkBadge") ?>
            <span data-i18n="auditTrailMenuLabel">Denetim İzi</span>
        </a>
        <?php endif; ?>
        <?php endif; /* canSeeOperations */ ?>

        <?php if ($isSuperAdminNav): ?>
            <span class="sidebar-section-label" data-i18n="sidebarManagementLabel">Sistem Yönetimi</span>
            <a class="<?= sidebarLinkClass("office_settings", $activeNav) ?>" href="office-settings.php">
                <?= appIcon("office") ?>
                <span data-i18n="officeSettingsTitle">Ofis Entegrasyonu</span>
            </a>
            <a class="<?= sidebarLinkClass("mail_settings", $activeNav) ?>" href="mail-settings.php">
                <?= appIcon("notifications") ?>
                <span data-i18n="mailSettingsMenuLabel">E-posta Ayarları</span>
            </a>
            <a class="<?= sidebarLinkClass("companies", $activeNav) ?>" href="super-admin-companies.php">
                <?= appIcon("companies") ?>
                <span data-i18n="manageCompaniesButton">Şirketler</span>
            </a>
            <a class="<?= sidebarLinkClass("admins", $activeNav) ?>" href="super-admin-admins.php">
                <?= appIcon("admins") ?>
                <span data-i18n="accountsTitle">Kullanıcı Hesapları</span>
            </a>
            <a class="<?= sidebarLinkClass("assignments", $activeNav) ?>" href="super-admin-assignments.php">
                <?= appIcon("assignments") ?>
                <span data-i18n="manageAssignmentsButton">Admin Atamaları</span>
            </a>
            <a class="<?= sidebarLinkClass("permissions", $activeNav) ?>" href="permissions.php">
                <?= appIcon("checkBadge") ?>
                <span data-i18n="permissionsMenuLabel">İzinler</span>
            </a>
        <?php endif; ?>
    </nav>

    <div class="sidebar-footer">
        <a class="sidebar-link" href="logout.php">
            <?= appIcon("logout") ?>
            <span data-i18n="logoutLabel">Çıkış</span>
        </a>
    </div>
</aside>
