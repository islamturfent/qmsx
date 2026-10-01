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
require_once __DIR__ . '/includes/settings-functions.php';

qmsRequirePermission('admin.system');

$userId = (int) ($_SESSION["qms_user_id"] ?? 0);
$csrfScope = 'system_settings';
$formError = '';
$formOk = '';

// Sistem ayarini degistirme islemi (yalnizca yonetim).
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    qmsCsrfVerify($csrfScope, $_POST["csrf"] ?? null);
    $action = (string) ($_POST["action"] ?? "");
    if ($action === "save") {
        $values = [
            'session_timeout_min' => (string) ($_POST["session_timeout_min"] ?? ''),
            'default_theme' => in_array((string) ($_POST["default_theme"] ?? ''), ['light', 'dark'], true) ? (string) $_POST["default_theme"] : 'light',
            'default_lang' => in_array((string) ($_POST["default_lang"] ?? ''), ['tr', 'en'], true) ? (string) $_POST["default_lang"] : 'tr',
            'page_size' => (string) max(10, min(200, (int) ($_POST["page_size"] ?? 25))),
            'upload_max_mb' => (string) max(1, min(200, (int) ($_POST["upload_max_mb"] ?? 20))),
            'delivery_reject_threshold' => (string) max(0.01, min(0.99, (float) ($_POST["delivery_reject_threshold"] ?? 0.05))),
            'audit_retention_days' => (string) max(30, min(3650, (int) ($_POST["audit_retention_days"] ?? 365))),
            'report_company_name' => trim((string) ($_POST["report_company_name"] ?? '')),
            'report_footer' => trim((string) ($_POST["report_footer"] ?? '')),
            'report_confidential' => !empty($_POST["report_confidential"]) ? '1' : '0',
            'email_from_name' => trim((string) ($_POST["email_from_name"] ?? 'QuAmi')),
            'email_from_address' => trim((string) ($_POST["email_from_address"] ?? '')),
            'password_min_length' => (string) max(6, min(64, (int) ($_POST["password_min_length"] ?? 8))),
            'twofa_required' => !empty($_POST["twofa_required"]) ? '1' : '0',
        ];
        $bad = qmsSettingsSave($pdo, $values, $userId);
        $formOk = $bad === [] ? 'Sistem ayarları kaydedildi.' : 'Bazı anahtarlar tanınmadı: ' . implode(', ', $bad);
    }
}

$settings = qmsSettings();
$activeNav = "system_settings";

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="theme-color" content="#f9fafb">
    <title>QuAmi Sistem Ayarları</title><link rel="manifest" href="manifest.webmanifest"><link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml"><link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar"><div class="topbar-inner"><div class="page-title-block"><strong data-i18n="systemSettingsTitle">Sistem Ayarları</strong><span data-i18n="systemSettingsText">Genel, güvenlik, raporlama ve gönderici ayarları.</span></div><div class="topbar-actions"><button class="topbar-button" id="languageToggle" type="button">EN</button><button class="topbar-button" id="themeToggle" type="button" aria-label="Tema değiştir">🌙</button></div></div></header>
    <main class="page-container">
        <section class="page-heading page-heading-actions">
            <div>
                <span class="section-kicker" data-i18n="systemSettingsKicker">Sistem Yönetimi</span>
                <h1 data-i18n="systemSettingsTitle">Sistem Ayarları</h1>
                <p data-i18n="systemSettingsText">Uygulama genelini etkileyen yapılandırmaları buradan yönetin.</p>
            </div>
            <a class="primary-button" href="system-backup.php" data-i18n="systemBackupLink">Veritabanı Yedeği</a>
        </section>

        <?php if ($formError !== ""): ?><div class="form-message error"><?= htmlspecialchars($formError, ENT_QUOTES, "UTF-8") ?></div><?php endif; ?>
        <?php if ($formOk !== ""): ?><div class="form-message success"><?= htmlspecialchars($formOk, ENT_QUOTES, "UTF-8") ?></div><?php endif; ?>

        <form method="post" action="system-settings.php">
            <?= qmsCsrfField($csrfScope) ?>
            <input type="hidden" name="action" value="save">

            <section class="page-section console-card">
                <div class="section-heading compact-heading"><div><h3 data-i18n="systemSettingsGeneralTitle">A · Genel</h3><p data-i18n="systemSettingsGeneralText">Oturum, görünüm, liste ve yükleme varsayılanları.</p></div></div>
                <div class="form-grid">
                    <label class="form-field"><span data-i18n="sessionTimeoutLabel">Oturum Zaman Aşımı (dk)</span><input type="number" min="5" max="1440" name="session_timeout_min" value="<?= (int) $settings['session_timeout_min'] ?>"></label>
                    <label class="form-field"><span data-i18n="defaultThemeLabel">Varsayılan Tema</span><select name="default_theme"><option value="light" <?= $settings['default_theme'] === 'light' ? 'selected' : '' ?>>Açık</option><option value="dark" <?= $settings['default_theme'] === 'dark' ? 'selected' : '' ?>>Koyu</option></select></label>
                    <label class="form-field"><span data-i18n="defaultLangLabel">Varsayılan Dil</span><select name="default_lang"><option value="tr" <?= $settings['default_lang'] === 'tr' ? 'selected' : '' ?>>Türkçe</option><option value="en" <?= $settings['default_lang'] === 'en' ? 'selected' : '' ?>>English</option></select></label>
                    <label class="form-field"><span data-i18n="pageSizeLabel">Sayfa Başına Kayıt</span><input type="number" min="10" max="200" name="page_size" value="<?= (int) $settings['page_size'] ?>"></label>
                    <label class="form-field"><span data-i18n="uploadMaxMbLabel">Dosya Yükleme Limiti (MB)</span><input type="number" min="1" max="200" name="upload_max_mb" value="<?= (int) $settings['upload_max_mb'] ?>"></label>
                </div>
            </section>

            <section class="page-section console-card">
                <div class="section-heading compact-heading"><div><h3 data-i18n="systemSettingsOpsTitle">A2 · İşletimsel Eşikler</h3><p data-i18n="systemSettingsOpsText">Teslimat eşiği ve denetim izi saklama.</p></div></div>
                <div class="form-grid">
                    <label class="form-field"><span data-i18n="deliveryThresholdLabel">Teslimat Red Eşiği (0-1, örn. 0.05)</span><input type="number" step="0.01" min="0.01" max="0.99" name="delivery_reject_threshold" value="<?= htmlspecialchars((string) $settings['delivery_reject_threshold'], ENT_QUOTES, "UTF-8") ?>"></label>
                    <label class="form-field"><span data-i18n="auditRetentionLabel">Denetim İzi Saklama (gün)</span><input type="number" min="30" max="3650" name="audit_retention_days" value="<?= (int) $settings['audit_retention_days'] ?>"></label>
                </div>
            </section>

            <section class="page-section console-card">
                <div class="section-heading compact-heading"><div><h3 data-i18n="systemSettingsReportTitle">C · Raporlama Görünümü</h3><p data-i18n="systemSettingsReportText">Excel/PDF raporların üst/alt bilgisi.</p></div></div>
                <div class="form-grid">
                    <label class="form-field"><span data-i18n="reportCompanyNameLabel">Rapor Şirket Adı</span><input type="text" name="report_company_name" maxlength="160" value="<?= htmlspecialchars((string) $settings['report_company_name'], ENT_QUOTES, "UTF-8") ?>" placeholder="örn. ACME Kalite A.Ş."></label>
                    <label class="form-field"><span data-i18n="reportFooterLabel">Rapor Alt Not</span><input type="text" name="report_footer" maxlength="255" value="<?= htmlspecialchars((string) $settings['report_footer'], ENT_QUOTES, "UTF-8") ?>"></label>
                    <label class="form-field form-field-wide"><span data-i18n="reportConfidentialLabel">Gizlilik Notu Ekle</span><label class="toggle-field"><input type="checkbox" name="report_confidential" value="1" <?= $settings['report_confidential'] === '1' ? 'checked' : '' ?>><span class="toggle-slider"></span></label></label>
                </div>
            </section>

            <section class="page-section console-card">
                <div class="section-heading compact-heading"><div><h3 data-i18n="systemSettingsMailTitle">D · E-posta / Gönderici</h3><p data-i18n="systemSettingsMailText">Gönderen görünen ad/adres; SMTP ayrıca E-posta Ayarları'ndadır.</p></div></div>
                <div class="form-grid">
                    <label class="form-field"><span data-i18n="emailFromNameLabel">Gönderen Adı</span><input type="text" name="email_from_name" maxlength="120" value="<?= htmlspecialchars((string) $settings['email_from_name'], ENT_QUOTES, "UTF-8") ?>"></label>
                    <label class="form-field"><span data-i18n="emailFromAddressLabel">Gönderen Adresi (boş = SMTP ayarı)</span><input type="email" name="email_from_address" maxlength="160" value="<?= htmlspecialchars((string) $settings['email_from_address'], ENT_QUOTES, "UTF-8") ?>"></label>
                </div>
            </section>

            <section class="page-section console-card">
                <div class="section-heading compact-heading"><div><h3 data-i18n="systemSettingsSecurityTitle">E · Güvenlik</h3><p data-i18n="systemSettingsSecurityText">Şifre politikası ve 2FA bayrağı.</p></div></div>
                <div class="form-grid">
                    <label class="form-field"><span data-i18n="passwordMinLengthLabel">Minimum Şifre Uzunluğu</span><input type="number" min="6" max="64" name="password_min_length" value="<?= (int) $settings['password_min_length'] ?>"></label>
                    <label class="form-field"><span data-i18n="twofaRequiredLabel">2FA Gerekli (kademeli)</span><label class="toggle-field"><input type="checkbox" name="twofa_required" value="1" <?= $settings['twofa_required'] === '1' ? 'checked' : '' ?>><span class="toggle-slider"></span></label></label>
                </div>
            </section>

            <div class="form-actions">
                <button class="primary-button" type="submit" data-i18n="saveButton">Kaydet</button>
            </div>
        </form>
    </main>
    <script src="assets/js/theme.js"></script><script src="assets/js/language.js"></script><script src="assets/js/sidebar.js"></script><script src="assets/js/pwa.js"></script>
</body>
</html>
