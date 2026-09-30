<?php

// RBAC servisi: menü öğelerini cari kullanıcı iznine göre gizler.
require_once __DIR__ . '/access.php';

if (!function_exists('qmsSidebarVisible')) {
    function qmsSidebarVisible(string $action): bool
    {
        return function_exists('qmsCanSession') && qmsCanSession($action);
    }
}

$activeNav = $activeNav ?? "";
$sidebarRole = (string) ($_SESSION["qms_role"] ?? "");
// Operasyon bolumu kullaniciya verilen RBAC iznine gore render edilir (rol sabit degil).
$canSeeOperations = qmsSidebarVisible('operations.view');
$sidebarUnreadCount = 0;
if (isset($pdo, $_SESSION["qms_user_id"])) {
    $sidebarNotificationStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = :user_id AND is_read = 0");
    $sidebarNotificationStmt->execute(["user_id" => (int) $_SESSION["qms_user_id"]]);
    $sidebarUnreadCount = (int) $sidebarNotificationStmt->fetchColumn();
}
$sidebarRecentNotifications = [];
if (isset($pdo, $_SESSION["qms_user_id"])) {
    $sidebarRecentStmt = $pdo->prepare("SELECT id, title, message, link_url, is_read, created_at FROM notifications WHERE user_id = :user_id ORDER BY id DESC LIMIT 8");
    $sidebarRecentStmt->execute(["user_id" => (int) $_SESSION["qms_user_id"]]);
    $sidebarRecentNotifications = $sidebarRecentStmt->fetchAll(PDO::FETCH_ASSOC);
}
$sidebarOverdueCount = 0;
$sidebarSupplierEvalOverdue = 0;
if (!empty($pdo) && isset($_SESSION["qms_user_id"])) {
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
<span id="qmsIconSun" hidden><?= appIcon("sun", "") ?></span>
<span id="qmsIconMoon" hidden><?= appIcon("moon", "") ?></span>
<span id="qmsIconGlobe" hidden><?= appIcon("globe", "") ?></span>
<span id="qmsIconUserMenuUser" hidden><?= appIcon("user", "") ?></span>
<span id="qmsIconUserMenuCog" hidden><?= appIcon("cog", "") ?></span>
<span id="qmsIconUserMenuKey" hidden><?= appIcon("key", "") ?></span>
<span id="qmsIconUserMenuChevron" hidden><?= appIcon("chevronDown", "") ?></span>
<span id="qmsIconUserMenuLogout" hidden><?= appIcon("logout", "") ?></span>
<script type="application/json" id="qmsNotificationRecent"><?= json_encode($sidebarRecentNotifications, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>

<?php
$sidebarGroups = [
    [
        'label_key' => 'sidebarGroupGeneralLabel', 'label' => 'Genel',
        'items' => [
            ['key' => 'quality_plans', 'href' => 'quality-plan.php', 'perm' => 'operations.view', 'i18n' => 'qualityPlanMenuLabel', 'label' => 'Yıllık Kalite Planı', 'icon' => 'table'],
            ['key' => 'reviews', 'href' => 'reviews.php', 'perm' => 'operations.view', 'i18n' => 'reviewsTitle', 'label' => 'Yönetimin Gözden Geçirmesi', 'icon' => 'reviews'],
        ],
    ],
    [
        'label_key' => 'sidebarGroupDocumentsLabel', 'label' => 'Doküman Yönetimi',
        'items' => [
            ['key' => 'documents', 'href' => 'documents.php', 'perm' => 'operations.view', 'i18n' => 'documentManagementTitle', 'label' => 'Doküman Yönetimi (Ana Liste / Havuz)', 'icon' => 'documents'],
            ['key' => 'document_templates', 'href' => 'document-templates.php', 'perm' => 'operations.view', 'i18n' => 'docTemplateMenuLabel', 'label' => 'Doküman Şablonları', 'icon' => 'documents'],
            ['key' => 'approvals', 'href' => 'approval-runs.php', 'perm' => 'approvals.manage', 'i18n' => 'approvalWorkflowMenuLabel', 'label' => 'Onay & İmza Workflow', 'icon' => 'approvals'],
            ['key' => 'document_approvals', 'href' => 'document-approvals.php', 'perm' => 'document_approvals.manage', 'i18n' => 'approvalInboxTitle', 'label' => 'Doküman Onay Kutusu', 'icon' => 'approvals'],
            ['key' => 'document_reviews', 'href' => 'document-reviews.php', 'perm' => 'document_reviews.manage', 'i18n' => 'docReviewMenuLabel', 'label' => 'Doküman Gözden Geçirme', 'icon' => 'documents'],
            ['key' => 'document_copies', 'href' => 'document-distribution.php', 'perm' => 'operations.view', 'i18n' => 'documentDistributionMenuLabel', 'label' => 'Dağıtım Kontrolü', 'icon' => 'table'],
            ['key' => 'document_tracking', 'href' => 'document-distribution-tracking.php', 'perm' => 'operations.view', 'i18n' => 'docTrackingMenuLabel', 'label' => 'Dağıtım & İmza Takibi', 'icon' => 'checkBadge'],
            ['key' => 'document_compare', 'href' => 'document-compare.php', 'perm' => 'operations.view', 'i18n' => 'docCompareMenuLabel', 'label' => 'Versiyon Karşılaştırma', 'icon' => 'documents'],
        ],
    ],
    [
        'label_key' => 'sidebarGroupAuditsLabel', 'label' => 'Denetim Yönetimi',
        'items' => [
            ['key' => 'auditors', 'href' => 'auditors.php', 'perm' => 'auditors.manage', 'i18n' => 'auditorsCardLabel', 'label' => 'Denetçiler', 'icon' => 'users'],
            ['key' => 'audit_programs', 'href' => 'audit-programs.php', 'perm' => 'audit_programs.manage', 'i18n' => 'auditProgramsMenuLabel', 'label' => 'Denetim Programları', 'icon' => 'approvals'],
            ['key' => 'audit_calendar', 'href' => 'audit-calendar.php', 'perm' => 'operations.view', 'i18n' => 'auditCalendarMenuLabel', 'label' => 'Denetim Takvimi', 'icon' => 'reports'],
            ['key' => 'checklist_templates', 'href' => 'checklist-templates.php', 'perm' => 'checklist_templates.view', 'i18n' => 'checklistTemplatesMenuLabel', 'label' => 'Kontrol Listesi Şablonları', 'icon' => 'approvals'],
            ['key' => 'audit_reports', 'href' => 'audit-reports.php', 'perm' => 'operations.view', 'i18n' => 'auditReportsMenuLabel', 'label' => 'Denetim Raporları', 'icon' => 'reports'],
            ['key' => 'audit_findings', 'href' => 'audit-findings.php', 'perm' => 'operations.view', 'i18n' => 'auditFindingsMenuLabel', 'label' => 'Denetim Bulguları', 'icon' => 'alert'],
            ['key' => 'external_audits', 'href' => 'external-audits.php', 'perm' => 'external_audits.view', 'i18n' => 'externalAuditsMenuLabel', 'label' => 'Dış Denetim & Kapama', 'icon' => 'alert'],
        ],
    ],
    [
        'label_key' => 'sidebarGroupCapaLabel', 'label' => 'CAPA & İyileştirme Yönetimi',
        'items' => [
            ['key' => 'incidents', 'href' => 'incidents.php', 'perm' => 'operations.view', 'i18n' => 'incidentsMenuLabel', 'label' => 'Olay Raporlama', 'icon' => 'alert'],
            ['key' => 'actions', 'href' => 'actions.php', 'perm' => 'operations.view', 'i18n' => 'actionManagementTitle', 'label' => 'Düzeltici & Önleyici Faaliyet (CAPA)', 'icon' => 'check', 'countVar' => 'overdue'],
            ['key' => 'rca', 'href' => 'rca.php', 'perm' => 'operations.view', 'i18n' => 'rcaMenuLabel', 'label' => 'Kök Neden Analizi', 'icon' => 'table'],
            ['key' => 'verification_center', 'href' => 'verification-center.php', 'perm' => 'operations.view', 'i18n' => 'verificationCenterMenuLabel', 'label' => 'Doğrulama & Kapanış', 'icon' => 'checkBadge'],
            ['key' => 'improvements', 'href' => 'improvements.php', 'perm' => 'operations.view', 'i18n' => 'improvementsMenuLabel', 'label' => 'İyileştirme Fırsatları', 'icon' => 'sparkles'],
        ],
    ],
    [
        'label_key' => 'sidebarGroupPerformanceLabel', 'label' => 'Performans & Süreç Yönetimi',
        'items' => [
            ['key' => 'performance', 'href' => 'performance.php', 'perm' => 'operations.view', 'i18n' => 'performanceTitle', 'label' => 'Performans Yönetimi', 'icon' => 'performance'],
            ['key' => 'processes', 'href' => 'processes.php', 'perm' => 'operations.view', 'i18n' => 'processesMenuLabel', 'label' => 'Süreç Envanteri', 'icon' => 'table'],
            ['key' => 'quality_costs', 'href' => 'quality-costs.php', 'perm' => 'operations.view', 'i18n' => 'qualityCostMenuLabel', 'label' => 'Kalite Maliyeti (COQ)', 'icon' => 'table'],
            ['key' => 'quality_cost_trend', 'href' => 'quality-cost-trend.php', 'perm' => 'operations.view', 'i18n' => 'costTrendMenuLabel', 'label' => 'COQ Trendi', 'icon' => 'trend'],
        ],
    ],
    [
        'label_key' => 'sidebarGroupCustomerLabel', 'label' => 'Müşteri İlişkileri & Memnuniyet',
        'items' => [
            ['key' => 'complaints', 'href' => 'complaints.php', 'perm' => 'operations.view', 'i18n' => 'complaintsTitle', 'label' => 'Şikayet Yönetimi', 'icon' => 'complaints'],
            ['key' => 'satisfaction', 'href' => 'satisfaction-surveys.php', 'perm' => 'operations.view', 'i18n' => 'satisfactionMenuLabel', 'label' => 'Müşteri Memnuniyeti', 'icon' => 'complaints'],
            ['key' => 'customer_performance', 'href' => 'customer-delivery-performance.php', 'perm' => 'operations.view', 'i18n' => 'customerPerformanceMenuLabel', 'label' => 'Müşteri Performansı', 'icon' => 'trend'],
            ['key' => 'delivery_performance', 'href' => 'delivery-performance.php', 'perm' => 'operations.view', 'i18n' => 'deliveryMenuLabel', 'label' => 'Teslimat Performansı', 'icon' => 'checkBadge'],
        ],
    ],
    [
        'label_key' => 'sidebarGroupSurveyLabel', 'label' => 'Anket Yönetimi',
        'items' => [
            ['key' => 'internal_surveys', 'href' => 'internal-surveys.php', 'perm' => 'operations.view', 'i18n' => 'internalSurveyMenuLabel', 'label' => 'İç Memnuniyet Anketi', 'icon' => 'sparkles'],
            ['key' => 'internal_survey_fill', 'href' => 'internal-survey-fill.php', 'perm' => 'operations.view', 'i18n' => 'internalSurveyFillMenuLabel', 'label' => 'Anketi Doldur', 'icon' => 'checkBadge'],
        ],
    ],
    [
        'label_key' => 'sidebarGroupPersonnelLabel', 'label' => 'Personel & Eğitim Yönetimi',
        'items' => [
            ['key' => 'personnel', 'href' => 'personnel.php', 'perm' => 'operations.view', 'i18n' => 'personnelMenuLabel', 'label' => 'Personel & Yetkinlik', 'icon' => 'users'],
            ['key' => 'trainings', 'href' => 'trainings.php', 'perm' => 'operations.view', 'i18n' => 'trainingManagementTitle', 'label' => 'Eğitim Yönetimi', 'icon' => 'training'],
            ['key' => 'training_templates', 'href' => 'training-templates.php', 'perm' => 'training_templates.manage', 'i18n' => 'trainingTemplatesMenuLabel', 'label' => 'Eğitim Şablonları', 'icon' => 'training'],
            ['key' => 'competency_matrix', 'href' => 'competency-matrix.php', 'perm' => 'competency_matrix.view', 'i18n' => 'competencyMatrixMenuLabel', 'label' => 'Yetkinlik Matrisi', 'icon' => 'users'],
        ],
    ],
    [
        'label_key' => 'sidebarGroupSupplierLabel', 'label' => 'Tedarikçi & İş Ortakları Yönetimi',
        'items' => [
            ['key' => 'suppliers', 'href' => 'suppliers.php', 'perm' => 'operations.view', 'i18n' => 'suppliersTitle', 'label' => 'Tedarikçi Yönetimi', 'icon' => 'suppliers'],
            ['key' => 'supplier_evaluations', 'href' => 'supplier-evaluations.php', 'perm' => 'operations.view', 'i18n' => 'supplierEvalMenuLabel', 'label' => 'Değerlendirme Takvimi', 'icon' => 'checkBadge', 'countVar' => 'supplier'],
            ['key' => 'contracts', 'href' => 'contracts.php', 'perm' => 'operations.view', 'i18n' => 'contractsMenuLabel', 'label' => 'Sözleşme Yönetimi', 'icon' => 'approvals'],
        ],
    ],
    [
        'label_key' => 'sidebarGroupEquipmentLabel', 'label' => 'Ekipman & Kalibrasyon',
        'items' => [
            ['key' => 'equipment', 'href' => 'equipment.php', 'perm' => 'operations.view', 'i18n' => 'equipmentMenuLabel', 'label' => 'Ekipman ve Kalibrasyon', 'icon' => 'table'],
            ['key' => 'calibration_calendar', 'href' => 'calibration-calendar.php', 'perm' => 'operations.view', 'i18n' => 'calibCalendarMenuLabel', 'label' => 'Kalibrasyon Takvimi', 'icon' => 'reports'],
            ['key' => 'calibration_history', 'href' => 'instrument-calibrations.php', 'perm' => 'operations.view', 'i18n' => 'calibrationHistoryMenuLabel', 'label' => 'Kalibrasyon Geçmişi', 'icon' => 'checkBadge'],
            ['key' => 'instruments', 'href' => 'instruments.php', 'perm' => 'operations.view', 'i18n' => 'instrumentsMenuLabel', 'label' => 'Kalibrasyon & Metroloji', 'icon' => 'clock'],
        ],
    ],
    [
        'label_key' => 'sidebarGroupRiskLabel', 'label' => 'Risk & Güvenlik',
        'items' => [
            ['key' => 'risks', 'href' => 'risks.php', 'perm' => 'operations.view', 'i18n' => 'riskManagementTitle', 'label' => 'Risk Yönetimi', 'icon' => 'warning'],
        ],
    ],
    [
        'label_key' => 'sidebarGroupSystemLabel', 'label' => 'Sistem Ayarları & Loglar',
        'items' => [
            ['key' => 'audit_trail', 'href' => 'audit-trail.php', 'perm' => 'audit_trail.view', 'i18n' => 'auditTrailMenuLabel', 'label' => 'Denetim İzi (Audit Trail)', 'icon' => 'checkBadge'],
            ['key' => 'audit_trail_report', 'href' => 'audit-trail-report.php', 'perm' => 'audit_trail.view', 'i18n' => 'auditTrailReportTitle', 'label' => 'Denetim İzi Raporu', 'icon' => 'reports'],
        ],
    ],
];
?>

<aside class="app-sidebar" id="appSidebar">
    <a class="sidebar-brand" href="dashboard.php">
        <span class="brand-icon"><img src="assets/icons/qms-logo.png" alt="QuAmi"></span>
        <span>
            <strong>QuAmi</strong>
            <small>Quality Management</small>
        </span>
    </a>

    <nav class="sidebar-nav" aria-label="Ana menü">
        <?php foreach ($sidebarGroups as $group): ?>
            <?php
            $visible = [];
            foreach ($group['items'] as $item) {
                if (qmsSidebarVisible($item['perm'])) {
                    $visible[] = $item;
                }
            }
            if ($visible === []) {
                continue;
            }
            $groupOpen = false;
            foreach ($visible as $item) {
                if ($item['key'] === $activeNav) {
                    $groupOpen = true;
                    break;
                }
            }
            ?>
            <div class="sidebar-group">
                <button type="button" class="sidebar-group-toggle<?= $groupOpen ? ' sidebar-group-open' : '' ?>" aria-expanded="<?= $groupOpen ? 'true' : 'false' ?>">
                    <span class="sidebar-group-label" data-i18n="<?= $group['label_key'] ?>"><?= htmlspecialchars($group['label'], ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="sidebar-group-caret" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                    </span>
                </button>
                <div class="sidebar-submenu">
                    <?php foreach ($visible as $item): ?>
                        <?php
                        $countVal = 0;
                        if (($item['countVar'] ?? '') === 'overdue') {
                            $countVal = $sidebarOverdueCount;
                        } elseif (($item['countVar'] ?? '') === 'supplier') {
                            $countVal = $sidebarSupplierEvalOverdue;
                        }
                        ?>
                        <a class="<?= sidebarLinkClass($item['key'], $activeNav) ?>" href="<?= htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8') ?>">
                            <?= appIcon($item['icon'], '') ?>
                            <span data-i18n="<?= $item['i18n'] ?>"><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?></span>
                            <?php if ($countVal > 0): ?><span class="sidebar-count"><?= $countVal ?></span><?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </nav>
</aside>
<script src="assets/js/dates.js"></script>
<script src="assets/js/searchable-select.js"></script>
