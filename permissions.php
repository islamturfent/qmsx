<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/access.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/app-ui.php';

// Izin kaydı yalniz super admin icindir.
qmsRequirePermission('permissions.view');

$labels = qmsPermissionActionLabels();
$roleLabels = qmsPermissionRoleLabels();
$roles = array_keys($roleLabels);

$formMessage = '';

// POST: super admin, ya toggle'lari kaydeder ya da tum override'lari sifirlar.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    qmsRequirePermission('permissions.view');
    qmsCsrfVerify('permissions', $_POST['csrf'] ?? null);

    if (($_POST['action'] ?? '') === 'reset') {
        // Tum izinler kod varsayilanlarina doner.
        qmsPermissionResetOverrides($pdo);
        header('Location: permissions.php?reset=1');
        exit;
    }

    $posted = $_POST['perm'] ?? [];
    $sets = [];
    foreach (array_keys(qmsPermissions()) as $action) {
        foreach ($roles as $role) {
            // Super admin rolu override edilmez: kendini kitleyemez.
            if ($role === 'super_admin') {
                continue;
            }
            $sets[$action][$role] = isset($posted[$action][$role]) && (string) $posted[$action][$role] === '1';
        }
    }

    qmsPermissionSaveOverrides($pdo, $sets);
    $_SESSION['qmsCsrfPermissions'] = null; // token'i yenile
    header('Location: permissions.php?saved=1');
    exit;
}

if (($_GET['saved'] ?? '') === '1') {
    $formMessage = 'İzinler güncellendi.';
}
if (($_GET['reset'] ?? '') === '1') {
    $formMessage = 'İzinler varsayılanlara döndürüldü.';
}

$matrix = qmsPermissionMatrix();

$activeNav = "permissions";

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f9fafb">
    <title>QuAmi İzinler</title>
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
                <span data-i18n="permissionsText">Rol bazlı erişim matrisi.</span>
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
            <p data-i18n="permissionsText">Rol bazlı erişim matrisi. Süper Admin sütunu kilitlidir (her zaman yetkili); diğer roller her eylem için toggle ile açılıp kapatılabilir.</p>
        </section>

        <?php if ($formMessage !== ''): ?>
            <div class="form-message success"><?= htmlspecialchars($formMessage, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <form method="post" action="permissions.php">
            <?= qmsCsrfField('permissions') ?>
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
                                            <?php if ($role === 'super_admin'): ?>
                                                <label class="toggle-field rbac-toggle">
                                                    <input type="checkbox" checked disabled>
                                                    <span class="toggle-slider"></span>
                                                </label>
                                            <?php else: ?>
                                                <label class="toggle-field rbac-toggle">
                                                    <input type="checkbox" name="perm[<?= htmlspecialchars($action, ENT_QUOTES, 'UTF-8') ?>][<?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8') ?>]" value="1" <?= $permits[$role] ? 'checked' : '' ?>>
                                                    <span class="toggle-slider"></span>
                                                </label>
                                            <?php endif; ?>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="form-actions">
                    <button class="primary-button" type="submit" name="action" value="save" data-i18n="permissionsSaveButton">İzinleri Kaydet</button>
                    <button class="secondary-button" type="submit" name="action" value="reset" data-i18n="permissionsResetButton" onclick="return confirm('Tüm izinler varsayılanlara mı döndürülsün?');">Varsayılanlara Dön</button>
                </div>
            </section>
        </form>
    </main>
    <script src="assets/js/theme.js"></script>
    <script src="assets/js/language.js"></script>
    <script src="assets/js/sidebar.js"></script>
    <script src="assets/js/pwa.js"></script>
</body>
</html>
