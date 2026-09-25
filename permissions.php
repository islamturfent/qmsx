<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/access.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/app-ui.php';

// Izin kaydı yalniz super admin icindir.
qmsRequirePermission('permissions.view');

$labels = qmsPermissionActionLabels();
$roleLabels = qmsPermissionRoleLabels();
$matrix = qmsPermissionMatrix();
$roles = array_keys($roleLabels);

$activeNav = "permissions";

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f9fafb">
    <title>QMS İzinler</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar">
        <div class="topbar-inner">
            <div class="page-title-block">
                <strong data-i18n="permissionsTitle">İzinler (RBAC)</strong>
                <span data-i18n="permissionsText">Rol bazlı erişim matrisi — salt-okunur.</span>
            </div>
            <div class="topbar-actions">
                <button class="topbar-button" id="languageToggle" type="button">EN</button>
                <button class="topbar-button" id="themeToggle" type="button" aria-label="Tema değiştir">🌙</button>
            </div>
        </div>
    </header>
    <main class="page-container">
        <section class="page-heading">
            <span class="section-kicker" data-i18n="permissionsKicker">Sistem Yönetimi</span>
            <h1 data-i18n="permissionsTitle">İzinler (RBAC)</h1>
            <p data-i18n="permissionsText">Uygulamanın tek izin kaynağından türetilen rol bazlı erişim matrisi. Bu kayıt salt-okunurdur ve eylem bazlı izinleri gösterir.</p>
        </section>

        <section class="page-section console-card">
            <div class="report-table-wrap">
                <table class="report-table">
                    <thead>
                        <tr>
                            <th data-i18n="permissionActionLabel">Eylem</th>
                            <?php foreach ($roles as $role): ?>
                                <th class="rbac-role-col"><?= htmlspecialchars($roleLabels[$role], ENT_QUOTES, "UTF-8") ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($matrix as $action => $permits): ?>
                            <tr>
                                <td><?= htmlspecialchars($labels[$action] ?? $action, ENT_QUOTES, "UTF-8") ?></td>
                                <?php foreach ($roles as $role): ?>
                                    <td class="rbac-role-col">
                                        <?php if ($permits[$role]): ?>
                                            <span class="rbac-yes" data-i18n="permissionYes">✓</span>
                                        <?php else: ?>
                                            <span class="rbac-no">—</span>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
    <script src="assets/js/theme.js"></script>
    <script src="assets/js/language.js"></script>
    <script src="assets/js/sidebar.js"></script>
    <script src="assets/js/pwa.js"></script>
</body>
</html>
