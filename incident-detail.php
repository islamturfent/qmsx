<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/access.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/incident-functions.php';

$userId = (int) ($_SESSION["qms_user_id"] ?? 0);
$role = qmsCurrentRole();
if (qmsIsAuditor()) {
    header("Location: my-audits.php");
    exit;
}

$csrfScope = 'incident_detail';
$id = (int) ($_GET['id'] ?? 0);
$incident = qmsIncidentFind($pdo, $id, $userId, $role);
if (!$incident) {
    header("Location: incidents.php");
    exit;
}
$linkedNc = qmsIncidentLinkedNonconformity($pdo, $id);
$message = '';

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    qmsCsrfVerify($csrfScope, $_POST["csrf"] ?? null);
    if (($_POST["form_type"] ?? "") === "create_nc") {
        $nc = qmsIncidentCreateNonconformity($pdo, $id, $userId, $role);
        if ($nc !== null) {
            header("Location: nonconformity-detail.php?id=" . $nc);
            exit;
        }
        $message = 'Uygunsuzluk oluşturulamadı.';
    }
}

$activeNav = "incidents";

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="theme-color" content="#f9fafb">
    <title>QMS Olay Detayı</title><link rel="manifest" href="manifest.webmanifest"><link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml"><link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar"><div class="topbar-inner"><div class="page-title-block"><strong data-i18n="incidentDetailTitle">Olay Detayı</strong><span data-i18n="incidentDetailText">Olay bilgilerini görüntüleyin ve gerekirse uygunsuzluk oluşturun.</span></div><div class="topbar-actions"><button class="topbar-button" id="languageToggle" type="button">EN</button><button class="topbar-button" id="themeToggle" type="button" aria-label="Tema değiştir">🌙</button></div></div></header>
    <main class="page-container">
        <section class="page-heading page-heading-actions">
            <div>
                <span class="section-kicker" data-i18n="incidentsKicker">Güvenlik & Uygunluk</span>
                <h1><?= htmlspecialchars($incident['title'], ENT_QUOTES, 'UTF-8') ?></h1>
                <p>
                    <?php if ($incident['severity'] === 'critical'): ?><span class="overdue-badge" data-i18n="incidentCriticalBadge">Kritik</span><?php else: ?><span class="status-badge"><?= htmlspecialchars(qmsIncidentSeverityLabel($incident['severity']), ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                    · <?= htmlspecialchars(qmsIncidentTypeLabel($incident['incident_type']), ENT_QUOTES, 'UTF-8') ?>
                    · <?= htmlspecialchars(qmsIncidentStatusLabel($incident['status']), ENT_QUOTES, 'UTF-8') ?>
                </p>
            </div>
            <a class="secondary-button" href="incidents.php" data-i18n="incidentBackTo">Olaylara Dön</a>
        </section>

        <?php if ($message !== ""): ?><div class="form-message error"><?= htmlspecialchars($message, ENT_QUOTES, "UTF-8") ?></div><?php endif; ?>

        <section class="console-card checklist-section">
            <div class="section-heading compact-heading"><div><h3 data-i18n="incidentInfoTitle">Olay Bilgileri</h3><p>#<?= (int) $id ?></p></div></div>
            <?php if ($incident['description']): ?><p class="muted-block"><?= nl2br(htmlspecialchars((string) $incident['description'], ENT_QUOTES, 'UTF-8')) ?></p><?php endif; ?>
            <div class="table-scroll">
                <table class="data-table compact-table">
                    <tbody>
                        <tr><th data-i18n="incidentCodeLabel">Olay Kodu</th><td><?= htmlspecialchars((string) ($incident['incident_code'] ?? ''), ENT_QUOTES, 'UTF-8') ?: '-' ?></td></tr>
                        <tr><th data-i18n="incidentReportedLabel">Olay Tarihi</th><td><?= htmlspecialchars((string) ($incident['reported_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?: '-' ?></td></tr>
                        <tr><th data-i18n="incidentLocationLabel">Konum</th><td><?= htmlspecialchars((string) ($incident['location'] ?? ''), ENT_QUOTES, 'UTF-8') ?: '-' ?></td></tr>
                        <tr><th data-i18n="incidentResponsibleLabel">Sorumlu</th><td><?= htmlspecialchars((string) ($incident['responsible'] ?? ''), ENT_QUOTES, 'UTF-8') ?: '-' ?></td></tr>
                        <tr><th data-i18n="incidentNotesLabel">Not / Aksiyon</th><td><?= htmlspecialchars((string) ($incident['notes'] ?? ''), ENT_QUOTES, 'UTF-8') ?: '-' ?></td></tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="console-card checklist-section">
            <div class="section-heading compact-heading"><div><h3 data-i18n="incidentCapaTitle">Düzeltici Faaliyet (CAPA) Bağlantısı</h3></div></div>
            <?php if ($linkedNc > 0): ?>
                <p data-i18n="incidentNcLinkedText">Bu olay için uygunsuzluk oluşturulmuş durumda.</p>
                <a class="primary-button" href="nonconformity-detail.php?id=<?= (int) $linkedNc ?>" data-i18n="incidentOpenNcButton">Uygunsuzluğu Aç</a>
            <?php else: ?>
                <p data-i18n="incidentNcCreateText">Olaydan bir uygunsuzluk (ve ardından CAPA) oluşturabilirsiniz.</p>
                <form method="post" action="incident-detail.php?id=<?= (int) $id ?>"><?= qmsCsrfField($csrfScope) ?><input type="hidden" name="form_type" value="create_nc"><button class="primary-button" type="submit" data-i18n="incidentCreateNcButton">Uygunsuzluk Oluştur</button></form>
            <?php endif; ?>
        </section>
    </main>
    <script src="assets/js/theme.js"></script><script src="assets/js/language.js"></script><script src="assets/js/sidebar.js"></script><script src="assets/js/pwa.js"></script>
</body>
</html>
