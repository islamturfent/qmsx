<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/app-ui.php';
require_once __DIR__ . '/includes/notifications.php';

$userId = (int) ($_SESSION["qms_user_id"] ?? 0);
$csrfToken = qmsCsrfToken('profile');

$formError = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    qmsCsrfVerify('profile', $_POST["csrf"] ?? null);
    if (($_POST["form_type"] ?? "") === "save_prefs") {
        $emailEnabled = isset($_POST["email_notifications"]);
        $categories = isset($_POST["email_categories"]) ? (array) $_POST["email_categories"] : null;
        $allowedGroups = array_keys(qmsNotificationGroupLabels());
        if ($categories !== null) {
            $categories = array_values(array_filter(array_map('strval', $categories), fn($c) => in_array($c, $allowedGroups, true)));
        }
        qmsMailPrefsSave($pdo, $userId, $emailEnabled, $categories);
        header("Location: account-settings.php?prefs=1");
        exit;
    }
    $formError = "Geçersiz istek.";
}

$mailPrefs = qmsMailPrefs($pdo, $userId);
$mailGroups = qmsNotificationGroupLabels();
$prefsSaved = ($_GET['prefs'] ?? '') === '1';

$activeNav = "profile";

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f9fafb">
    <title>QuAmi Hesap Ayarları</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar">
        <div class="topbar-inner">
            <div class="page-title-block">
                <strong data-i18n="accountSettingsTitle">Hesap Ayarları</strong>
                <span data-i18n="accountSettingsText">E-posta bildirim tercihlerinizi yönetin.</span>
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
                <span class="section-kicker" data-i18n="profileKicker">Hesap</span>
                <h1 data-i18n="accountSettingsTitle">Hesap Ayarları</h1>
                <p data-i18n="accountSettingsText">E-posta bildirimlerini aç/kapat ve hangi kategoriden e-posta alacağını seç.</p>
            </div>
            <a class="secondary-button" href="profile.php" data-i18n="profileTitle">Profil</a>
        </section>

        <?php if ($formError !== ""): ?>
            <div class="form-message error"><?= htmlspecialchars($formError, ENT_QUOTES, "UTF-8") ?></div>
        <?php endif; ?>
        <?php if ($prefsSaved): ?>
            <div class="form-message success" data-i18n="notificationPrefsSaved">Bildirim tercihleri kaydedildi.</div>
        <?php endif; ?>

        <div class="settings-layout">
            <section class="profile-card">
                <div class="profile-card-header">
                    <div>
                        <h2 data-i18n="notificationPrefsTitle">Bildirim Tercihleri</h2>
                        <p data-i18n="notificationPrefsText">E-posta bildirimlerini aç/kapat ve hangi kategoriden e-posta alacağını seç.</p>
                    </div>
                </div>
                <div class="profile-card-body">
                    <form class="auditor-form" method="post" action="account-settings.php">
                        <?= qmsCsrfField('profile') ?>
                        <input type="hidden" name="form_type" value="save_prefs">
                        <div class="form-grid">
                            <label class="toggle-field form-field-wide"><input type="checkbox" name="email_notifications" <?= $mailPrefs['email_enabled'] ? 'checked' : '' ?>><span class="toggle-slider"></span><span data-i18n="notificationPrefsEmailEnabled">E-posta bildirimleri al</span></label>
                            <label class="form-field form-field-wide">
                                <span data-i18n="notificationPrefsCategories">E-posta alınacak kategoriler (boş = tümü)</span>
                                <div class="pref-checks">
                                    <?php foreach ($mailGroups as $gKey => $gLabel): $checked = $mailPrefs['categories'] === null || in_array($gKey, $mailPrefs['categories'], true); ?>
                                        <label class="pref-check"><input type="checkbox" name="email_categories[]" value="<?= htmlspecialchars($gKey, ENT_QUOTES, 'UTF-8') ?>" <?= $checked ? 'checked' : '' ?>><span class="box"></span><span><?= htmlspecialchars($gLabel, ENT_QUOTES, 'UTF-8') ?></span></label>
                                    <?php endforeach; ?>
                                </div>
                            </label>
                        </div>
                        <div class="form-actions">
                            <button class="primary-button" type="submit" data-i18n="notificationPrefsSave">Kaydet</button>
                        </div>
                    </form>
                </div>
            </section>
        </div>
    </main>
    <script src="assets/js/theme.js"></script>
    <script src="assets/js/language.js"></script>
    <script src="assets/js/sidebar.js"></script>
    <script src="assets/js/modal.js"></script>
    <script src="assets/js/pwa.js"></script>
</body>
</html>
