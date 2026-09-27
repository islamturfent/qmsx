<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/access.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/audit-report-functions.php';
require_once __DIR__ . '/includes/app-ui.php';

$auditId = (int) ($_GET["id"] ?? 0);
if ($auditId <= 0) {
    header("Location: dashboard.php");
    exit;
}

$userId = (int) ($_SESSION["qms_user_id"] ?? 0);
$statusLabels = ['draft' => 'Taslak', 'final' => 'Kesinleşmiş'];

// Denetim kaydi - kapsam icinde olmazsa dashboard'a don.
$scope = qmsAuditRecordScope($pdo, $userId, 'audits.id', 'audits.company_id');
$auditStmt = $pdo->prepare(
    'SELECT audits.id, audits.company_id, audits.title, audits.audit_type,
            audits.planned_date, audits.status, companies.company_name
     FROM audits INNER JOIN companies ON companies.id = audits.company_id
     WHERE audits.id = ?' . $scope['sql'] . ' LIMIT 1'
);
$auditStmt->execute(array_merge([$auditId], $scope['params']));
$audit = $auditStmt->fetch(PDO::FETCH_ASSOC);
if (!$audit) {
    header("Location: dashboard.php");
    exit;
}

$report = qmsAuditReportFind($pdo, $auditId, $userId, qmsCurrentRole());
$snapshot = qmsAuditReportSnapshot($pdo, $auditId);
$generated = qmsGenerateAuditReportText($snapshot);

// Form alanlari: once varsa mevcut rapor, yoksa bos.
$formData = [
    "title" => $report["title"] ?? $generated["title"],
    "report_date" => $report["report_date"] ?? date("Y-m-d"),
    "status" => $report["status"] ?? "draft",
    "scope_text" => $report["scope_text"] ?? $generated["scope_text"],
    "methodology_text" => $report["methodology_text"] ?? $generated["methodology_text"],
    "findings_text" => $report["findings_text"] ?? $generated["findings_text"],
    "nonconformity_summary" => $report["nonconformity_summary"] ?? $generated["nonconformity_summary"],
    "conclusion" => $report["conclusion"] ?? $generated["conclusion"],
    "recommendations" => $report["recommendations"] ?? $generated["recommendations"],
];

$formError = "";
$formMessage = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    qmsCsrfVerify('audit_report', $_POST["csrf"] ?? null);

    $formType = (string) ($_POST["form_type"] ?? "");
    $redirect = "audit-report.php?id=" . $auditId;

    if ($formType === "generate") {
        // Taslagi yeniden uret ve kaydet (kaynak gorselini de sakla).
        $newReportId = qmsSaveAuditReport($pdo, $auditId, (int) $audit["company_id"], [
            "title" => $generated["title"],
            "report_date" => date("Y-m-d"),
            "status" => "draft",
            "scope_text" => $generated["scope_text"],
            "methodology_text" => $generated["methodology_text"],
            "findings_text" => $generated["findings_text"],
            "nonconformity_summary" => $generated["nonconformity_summary"],
            "conclusion" => $generated["conclusion"],
            "recommendations" => $generated["recommendations"],
        ], $userId);

        // Kaynak gorselini (derlenen sayilar) sakla.
        $pdo->prepare('UPDATE audit_reports SET source_snapshot = :snapshot, generated_at = NOW() WHERE id = :id')
            ->execute([
                'snapshot' => json_encode([
                    'checklist' => $snapshot['checklist'],
                    'severity_counts' => $snapshot['severity_counts'],
                    'open_nonconformities' => $snapshot['open_nonconformities'],
                    'nonconformity_count' => count($snapshot['nonconformities']),
                ], JSON_UNESCAPED_UNICODE),
                'id' => $newReportId,
            ]);

        header("Location: " . $redirect . "&generated=1");
        exit;
    }

    if ($formType === "finalize" || $formType === "save") {
        $formData = [
            "title" => qmsAuditReportNormalize(["title" => $_POST["title"] ?? ""])["title"] ?? "Denetim Raporu",
            "report_date" => trim((string) ($_POST["report_date"] ?? date("Y-m-d"))),
            "status" => (string) ($_POST["status"] ?? "draft"),
            "scope_text" => $_POST["scope_text"] ?? "",
            "methodology_text" => $_POST["methodology_text"] ?? "",
            "findings_text" => $_POST["findings_text"] ?? "",
            "nonconformity_summary" => $_POST["nonconformity_summary"] ?? "",
            "conclusion" => $_POST["conclusion"] ?? "",
            "recommendations" => $_POST["recommendations"] ?? "",
        ];

        if ($formData["title"] === "") {
            $formError = "Lütfen rapor başlığını girin.";
        } elseif (!in_array($formData["status"], QMS_AUDIT_REPORT_STATUSES, true)) {
            $formError = "Geçerli bir rapor durumu seçin.";
        } else {
            $newReportId = qmsSaveAuditReport($pdo, $auditId, (int) $audit["company_id"], $formData, $userId);
            if ($formType === "finalize") {
                qmsAuditReportFinalize($pdo, $newReportId, $userId);
            }
            header("Location: " . $redirect . "&saved=1");
            exit;
        }
    } else {
        $formError = "Geçersiz istek.";
    }
}

$activeNav = "audits";
$reportExists = (bool) $report;

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f9fafb">
    <title>QuAmi Denetim Raporu</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar">
        <div class="topbar-inner">
            <div class="page-title-block">
                <strong data-i18n="auditReportTitle">Denetim Raporu</strong>
                <span><?= htmlspecialchars($audit["title"], ENT_QUOTES, "UTF-8") ?> · <?= htmlspecialchars($audit["company_name"], ENT_QUOTES, "UTF-8") ?></span>
            </div>
            <div class="topbar-actions">
                <button class="topbar-button" id="languageToggle" type="button">EN</button>
                <button class="topbar-button" id="themeToggle" type="button" aria-label="Tema değiştir">🌙</button>
            </div>
        </div>
    </header>
    <main class="page-container narrow-page">
        <section class="page-heading page-heading-actions">
            <div>
                <span class="section-kicker" data-i18n="auditReportKicker">Denetim Çalışma Alanı</span>
                <h1 data-i18n="auditReportTitle">Denetim Raporu</h1>
                <p data-i18n="auditReportText">Denetim, kontrol listesi ve uygunsuzlukları kurallı bir taslak rapora derler; düzenleyip kaydedin, PDF'e verin.</p>
            </div>
            <a class="secondary-button" href="audit-detail.php?id=<?= (int) $auditId ?>" data-i18n="backToAuditButton">Denetime Dön</a>
        </section>

        <section class="page-section form-panel">
            <?php if ($formError !== ""): ?>
                <div class="form-message error"><?= htmlspecialchars($formError, ENT_QUOTES, "UTF-8") ?></div>
            <?php endif; ?>
            <?php if (($_GET["generated"] ?? "") === "1"): ?>
                <div class="form-message success" data-i18n="auditReportGeneratedMessage">Rapor taslağı, denetim verisinden üretildi.</div>
            <?php elseif (($_GET["saved"] ?? "") === "1"): ?>
                <div class="form-message success" data-i18n="auditReportSavedMessage">Rapor kaydedildi.</div>
            <?php endif; ?>

            <div class="page-section-headline">
                <strong><?= htmlspecialchars($audit["title"], ENT_QUOTES, "UTF-8") ?></strong>
                <span class="status-pill"><?= htmlspecialchars($statusLabels[$audit["status"]] ?? $audit["status"], ENT_QUOTES, "UTF-8") ?></span>
            </div>
            <div class="report-stat-row">
                <div><span data-i18n="auditTypeLabel">Tür</span><strong><?= htmlspecialchars((string) ($audit["audit_type"] ?? "-"), ENT_QUOTES, "UTF-8") ?: "-" ?></strong></div>
                <div><span data-i18n="plannedDateLabel">Planlanan</span><strong><?= htmlspecialchars((string) ($audit["planned_date"] ?? "-"), ENT_QUOTES, "UTF-8") ?></strong></div>
                <div><span data-i18n="auditChecklistTotalLabel">Kontrol Maddesi</span><strong><?= (int) ($snapshot["checklist"]["total"] ?? 0) ?>/<?= (int) ($snapshot["checklist"]["total"] ?? 0) ?></strong></div>
                <div><span data-i18n="auditNcTotalLabel">Uygunsuzluk</span><strong><?= count($snapshot["nonconformities"] ?? []) ?></strong></div>
            </div>

            <form class="auditor-form" method="post" action="audit-report.php?id=<?= (int) $auditId ?>">
                <?= qmsCsrfField('audit_report') ?>
                <div class="form-actions report-bar">
                    <button class="primary-button" type="submit" name="form_type" value="generate" data-i18n="generateAuditReportButton">Raporu Üret (Taslak)</button>
                    <button class="secondary-button" type="submit" name="form_type" value="save" data-i18n="saveReportButton">Kaydet</button>
                    <button class="success-button" type="submit" name="form_type" value="finalize" data-i18n="finalizeReportButton">Yayınla</button>
                    <?php if ($reportExists): ?>
                        <a class="secondary-button" href="audit-report-export-pdf.php?id=<?= (int) $report["id"] ?>" data-i18n="downloadReportPdfButton">PDF İndir</a>
                    <?php endif; ?>
                </div>

                <div class="form-grid">
                    <label class="form-field">
                        <span data-i18n="reportTitleLabel">Rapor Başlığı</span>
                        <input type="text" name="title" maxlength="255" value="<?= htmlspecialchars($formData["title"] ?? "", ENT_QUOTES, "UTF-8") ?>" required>
                    </label>
                    <label class="form-field">
                        <span data-i18n="reportDateLabel">Rapor Tarihi</span>
                        <input type="date" name="report_date" value="<?= htmlspecialchars($formData["report_date"] ?? "", ENT_QUOTES, "UTF-8") ?>">
                    </label>
                    <label class="form-field">
                        <span data-i18n="reportStatusLabel">Durum</span>
                        <select name="status">
                            <?php foreach ($statusLabels as $value => $label): ?>
                                <option value="<?= $value ?>" <?= $formData["status"] === $value ? "selected" : "" ?>><?= htmlspecialchars($label, ENT_QUOTES, "UTF-8") ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="form-field form-field-wide">
                        <span data-i18n="reportScopeLabel">Kapsam</span>
                        <textarea name="scope_text" rows="3"><?= htmlspecialchars((string) ($formData["scope_text"] ?? ""), ENT_QUOTES, "UTF-8") ?></textarea>
                    </label>
                    <label class="form-field form-field-wide">
                        <span data-i18n="reportMethodLabel">Yöntem</span>
                        <textarea name="methodology_text" rows="3"><?= htmlspecialchars((string) ($formData["methodology_text"] ?? ""), ENT_QUOTES, "UTF-8") ?></textarea>
                    </label>
                    <label class="form-field form-field-wide">
                        <span data-i18n="reportFindingsLabel">Bulgular</span>
                        <textarea name="findings_text" rows="4"><?= htmlspecialchars((string) ($formData["findings_text"] ?? ""), ENT_QUOTES, "UTF-8") ?></textarea>
                    </label>
                    <label class="form-field form-field-wide">
                        <span data-i18n="reportNcSummaryLabel">Uygunsuzluk Özeti</span>
                        <textarea name="nonconformity_summary" rows="3"><?= htmlspecialchars((string) ($formData["nonconformity_summary"] ?? ""), ENT_QUOTES, "UTF-8") ?></textarea>
                    </label>
                    <label class="form-field form-field-wide">
                        <span data-i18n="reportConclusionLabel">Sonuç</span>
                        <textarea name="conclusion" rows="3"><?= htmlspecialchars((string) ($formData["conclusion"] ?? ""), ENT_QUOTES, "UTF-8") ?></textarea>
                    </label>
                    <label class="form-field form-field-wide">
                        <span data-i18n="reportRecommendationsLabel">Öneriler</span>
                        <textarea name="recommendations" rows="4"><?= htmlspecialchars((string) ($formData["recommendations"] ?? ""), ENT_QUOTES, "UTF-8") ?></textarea>
                    </label>
                </div>
            </form>
        </section>
    </main>
    <script src="assets/js/theme.js"></script>
    <script src="assets/js/language.js"></script>
    <script src="assets/js/sidebar.js"></script>
    <script src="assets/js/pwa.js"></script>
</body>
</html>
