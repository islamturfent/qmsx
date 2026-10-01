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
            // F) Yaklasan vade pencereleri
            'contract_expiring_days' => (string) max(1, min(365, (int) ($_POST["contract_expiring_days"] ?? 60))),
            'instrument_due_days' => (string) max(1, min(365, (int) ($_POST["instrument_due_days"] ?? 60))),
            'competency_due_days' => (string) max(1, min(365, (int) ($_POST["competency_due_days"] ?? 30))),
            'document_review_days' => (string) max(1, min(365, (int) ($_POST["document_review_days"] ?? 30))),
            'process_review_days' => (string) max(1, min(365, (int) ($_POST["process_review_days"] ?? 30))),
            // G) Login guvenligi
            'max_login_attempts' => (string) max(1, min(50, (int) ($_POST["max_login_attempts"] ?? 5))),
            'lockout_minutes' => (string) max(1, min(1440, (int) ($_POST["lockout_minutes"] ?? 15))),
            // I) Marka
            'app_name' => trim((string) ($_POST["app_name"] ?? 'QuAmi')),
            'login_title' => trim((string) ($_POST["login_title"] ?? '')),
            // L) Yeni hesap varsayilanlari
            'default_user_lang' => in_array((string) ($_POST["default_user_lang"] ?? 'tr'), ['tr', 'en'], true) ? (string) $_POST["default_user_lang"] : 'tr',
            'default_user_theme' => in_array((string) ($_POST["default_user_theme"] ?? 'light'), ['light', 'dark'], true) ? (string) $_POST["default_user_theme"] : 'light',
            'default_notifications_enabled' => !empty($_POST["default_notifications_enabled"]) ? '1' : '0',
            // N) E-posta sablon metni
            'mail_subject_prefix' => trim((string) ($_POST["mail_subject_prefix"] ?? '')),
            'mail_signature' => trim((string) ($_POST["mail_signature"] ?? '')),
            // O) Otomatik bakim / yedek
            'auto_backup_enabled' => !empty($_POST["auto_backup_enabled"]) ? '1' : '0',
            'auto_backup_interval_hours' => (string) max(1, min(168, (int) ($_POST["auto_backup_interval_hours"] ?? 24))),
            'auto_backup_retain' => (string) max(1, min(90, (int) ($_POST["auto_backup_retain"] ?? 7))),
            // J) Guvenlik uygulamasi
            'maintenance_mode' => !empty($_POST["maintenance_mode"]) ? '1' : '0',
            'maintenance_message' => trim((string) ($_POST["maintenance_message"] ?? '')),
            'login_ip_allow' => trim((string) ($_POST["login_ip_allow"] ?? '')),
        ];
        // K) Rapor logosu yukleme / kaldirma.
        if (!empty($_POST["report_logo_remove"]) && $_POST["report_logo_remove"] === '1') {
            $values['report_logo'] = '';
        } elseif (!empty($_FILES['report_logo']['tmp_name']) && is_uploaded_file($_FILES['report_logo']['tmp_name'])) {
            $logoExt = strtolower(pathinfo((string) $_FILES['report_logo']['name'], PATHINFO_EXTENSION));
            if (in_array($logoExt, ['png', 'jpg', 'jpeg', 'gif', 'svg', 'webp'], true)) {
                $logoName = 'logo-' . bin2hex(random_bytes(8)) . '.' . $logoExt;
                $logoPath = __DIR__ . '/storage/logos/' . $logoName;
                if (@move_uploaded_file($_FILES['report_logo']['tmp_name'], $logoPath)) {
                    $values['report_logo'] = 'storage/logos/' . $logoName;
                }
            }
        }
        $bad = qmsSettingsSave($pdo, $values, $userId);
        $formOk = $bad === [] ? 'Sistem ayarları kaydedildi.' : 'Bazı anahtarlar tanınmadı: ' . implode(', ', $bad);
    } elseif ($action === 'archive_audit') {
        // M) Denetim izi arsivle (retention oncesini).
        $retention = (int) qmsSetting('audit_retention_days', '365');
        $cutoff = date('Y-m-d H:i:s', strtotime('-' . $retention . ' days'));
        $sel = $pdo->prepare('SELECT * FROM audit_log WHERE created_at < ? ORDER BY id ASC');
        $sel->execute([$cutoff]);
        $rows = $sel->fetchAll(PDO::FETCH_ASSOC);
        if ($rows) {
            $del = $pdo->prepare('DELETE FROM audit_log WHERE created_at < ?');
            $del->execute([$cutoff]);
            $filename = 'audit-archive-' . date('Ymd-His') . '.json';
            header('Content-Type: application/json; charset=UTF-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            echo json_encode(['archived' => count($rows), 'retention_days' => $retention, 'records' => $rows], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
        $formOk = 'Arşivlenecek eski denetim izi kaydı yok.';
    } elseif ($action === 'purge_audit') {
        // H) Denetim izi saklama: sureyi asan eski kayitlari temizle (onayli).
        $retention = (int) qmsSetting('audit_retention_days', '365');
        $cutoff = date('Y-m-d H:i:s', strtotime('-' . $retention . ' days'));
        $del = $pdo->prepare('DELETE FROM audit_log WHERE created_at < ?');
        $del->execute([$cutoff]);
        $formOk = 'Denetim izi temizlendi: ' . $del->rowCount() . ' eski kayıt silindi (' . $retention . ' gün öncesi).';
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

        <form method="post" action="system-settings.php" enctype="multipart/form-data">
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
                    <label class="form-field form-field-wide"><span data-i18n="reportLogoLabel">Rapor Logosu</span><input type="file" name="report_logo" accept="image/png,image/jpeg,image/svg+xml,image/gif,image/webp"><?php if (!empty($settings['report_logo'])): ?><small>Mevcut: <?= htmlspecialchars((string) $settings['report_logo'], ENT_QUOTES, "UTF-8") ?></small> <label class="toggle-field"><input type="checkbox" name="report_logo_remove" value="1"><span class="toggle-slider"></span></label> Logo kaldır<?php endif; ?></label>
                </div>
            </section>

            <section class="page-section console-card">
                <div class="section-heading compact-heading"><div><h3 data-i18n="systemSettingsSecurityTitle">E · Güvenlik</h3><p data-i18n="systemSettingsSecurityText">Şifre politikası ve 2FA bayrağı.</p></div></div>
                <div class="form-grid">
                    <label class="form-field"><span data-i18n="passwordMinLengthLabel">Minimum Şifre Uzunluğu</span><input type="number" min="6" max="64" name="password_min_length" value="<?= (int) $settings['password_min_length'] ?>"></label>
                    <label class="form-field"><span data-i18n="twofaRequiredLabel">2FA Gerekli (kademeli)</span><label class="toggle-field"><input type="checkbox" name="twofa_required" value="1" <?= $settings['twofa_required'] === '1' ? 'checked' : '' ?>><span class="toggle-slider"></span></label></label>
                </div>
            </section>

            <section class="page-section console-card">
                <div class="section-heading compact-heading"><div><h3 data-i18n="systemSettingsDueTitle">F · Yaklaşan Vade Pencereleri</h3><p data-i18n="systemSettingsDueText">Sözleşme/kalibrasyon/yetkinlik/gözden geçirme uyarı pencereleri (gün).</p></div></div>
                <div class="form-grid">
                    <label class="form-field"><span data-i18n="contractExpiringDaysLabel">Sözleşme Bitiş (gün)</span><input type="number" min="1" max="365" name="contract_expiring_days" value="<?= (int) $settings['contract_expiring_days'] ?>"></label>
                    <label class="form-field"><span data-i18n="instrumentDueDaysLabel">Kalibrasyon Yaklaşan (gün)</span><input type="number" min="1" max="365" name="instrument_due_days" value="<?= (int) $settings['instrument_due_days'] ?>"></label>
                    <label class="form-field"><span data-i18n="competencyDueDaysLabel">Yetkinlik Vade (gün)</span><input type="number" min="1" max="365" name="competency_due_days" value="<?= (int) $settings['competency_due_days'] ?>"></label>
                    <label class="form-field"><span data-i18n="documentReviewDaysLabel">Doküman GGR (gün)</span><input type="number" min="1" max="365" name="document_review_days" value="<?= (int) $settings['document_review_days'] ?>"></label>
                    <label class="form-field"><span data-i18n="processReviewDaysLabel">Süreç GGR (gün)</span><input type="number" min="1" max="365" name="process_review_days" value="<?= (int) $settings['process_review_days'] ?>"></label>
                </div>
            </section>

            <section class="page-section console-card">
                <div class="section-heading compact-heading"><div><h3 data-i18n="systemSettingsLoginTitle">G · Login Güvenliği</h3><p data-i18n="systemSettingsLoginText">Başarısız giriş denemesi kilidi.</p></div></div>
                <div class="form-grid">
                    <label class="form-field"><span data-i18n="maxLoginAttemptsLabel">Maks. Başarısız Deneme</span><input type="number" min="1" max="50" name="max_login_attempts" value="<?= (int) $settings['max_login_attempts'] ?>"></label>
                    <label class="form-field"><span data-i18n="lockoutMinutesLabel">Kilit Süresi (dk)</span><input type="number" min="1" max="1440" name="lockout_minutes" value="<?= (int) $settings['lockout_minutes'] ?>"></label>
                </div>
            </section>

            <section class="page-section console-card">
                <div class="section-heading compact-heading"><div><h3 data-i18n="systemSettingsBrandTitle">I · Marka / Görünüm</h3><p data-i18n="systemSettingsBrandText">Uygulama adı ve login başlığı.</p></div></div>
                <div class="form-grid">
                    <label class="form-field"><span data-i18n="appNameLabel">Uygulama Adı</span><input type="text" name="app_name" maxlength="60" value="<?= htmlspecialchars((string) $settings['app_name'], ENT_QUOTES, "UTF-8") ?>"></label>
                    <label class="form-field"><span data-i18n="loginTitleLabel">Login Başlığı</span><input type="text" name="login_title" maxlength="120" value="<?= htmlspecialchars((string) $settings['login_title'], ENT_QUOTES, "UTF-8") ?>"></label>
                </div>
            </section>

            <section class="page-section console-card">
                <div class="section-heading compact-heading"><div><h3 data-i18n="systemSettingsNewAcctTitle">L · Yeni Hesap Varsayılanları</h3><p data-i18n="systemSettingsNewAcctText">Yeni kullanıcıların başlangıç dili/teması ve bildirim varsayılanı.</p></div></div>
                <div class="form-grid">
                    <label class="form-field"><span data-i18n="defaultUserLangLabel">Varsayılan Dil</span><select name="default_user_lang"><option value="tr" <?= $settings['default_user_lang'] === 'tr' ? 'selected' : '' ?>>Türkçe</option><option value="en" <?= $settings['default_user_lang'] === 'en' ? 'selected' : '' ?>>English</option></select></label>
                    <label class="form-field"><span data-i18n="defaultUserThemeLabel">Varsayılan Tema</span><select name="default_user_theme"><option value="light" <?= $settings['default_user_theme'] === 'light' ? 'selected' : '' ?>>Açık</option><option value="dark" <?= $settings['default_user_theme'] === 'dark' ? 'selected' : '' ?>>Koyu</option></select></label>
                    <label class="form-field form-field-wide"><span data-i18n="defaultNotificationsLabel">E-posta Bildirimleri</span><label class="toggle-field"><input type="checkbox" name="default_notifications_enabled" value="1" <?= $settings['default_notifications_enabled'] === '1' ? 'checked' : '' ?>><span class="toggle-slider"></span></label></label>
                </div>
            </section>

            <section class="page-section console-card">
                <div class="section-heading compact-heading"><div><h3 data-i18n="systemSettingsMailTextTitle">N · E-posta Şablon Metni</h3><p data-i18n="systemSettingsMailTextText">Bildirim e-postalarının konu öneki ve imzası.</p></div></div>
                <div class="form-grid">
                    <label class="form-field"><span data-i18n="mailSubjectPrefixLabel">Konu Öneki</span><input type="text" name="mail_subject_prefix" maxlength="60" value="<?= htmlspecialchars((string) $settings['mail_subject_prefix'], ENT_QUOTES, "UTF-8") ?>" placeholder="örn. [QMS]"></label>
                    <label class="form-field form-field-wide"><span data-i18n="mailSignatureLabel">İmza</span><input type="text" name="mail_signature" maxlength="160" value="<?= htmlspecialchars((string) $settings['mail_signature'], ENT_QUOTES, "UTF-8") ?>"></label>
                </div>
            </section>

            <section class="page-section console-card">
                <div class="section-heading compact-heading"><div><h3 data-i18n="systemSettingsBackupTitle">O · Otomatik Bakım / Yedek</h3><p data-i18n="systemSettingsBackupText">Zamanlanmış otomatik veritabanı yedeği ve saklama adedi.</p></div></div>
                <div class="form-grid">
                    <label class="form-field form-field-wide"><span data-i18n="autoBackupEnabledLabel">Otomatik Yedek</span><label class="toggle-field"><input type="checkbox" name="auto_backup_enabled" value="1" <?= $settings['auto_backup_enabled'] === '1' ? 'checked' : '' ?>><span class="toggle-slider"></span></label></label>
                    <label class="form-field"><span data-i18n="autoBackupIntervalLabel">Yedek Aralığı (saat)</span><input type="number" min="1" max="168" name="auto_backup_interval_hours" value="<?= (int) $settings['auto_backup_interval_hours'] ?>"></label>
                    <label class="form-field"><span data-i18n="autoBackupRetainLabel">Saklanacak Yedek Sayısı</span><input type="number" min="1" max="90" name="auto_backup_retain" value="<?= (int) $settings['auto_backup_retain'] ?>"></label>
                </div>
            </section>

            <section class="page-section console-card">
                <div class="section-heading compact-heading"><div><h3 data-i18n="systemSettingsSecurityApplyTitle">J · Güvenlik Uygulaması</h3><p data-i18n="systemSettingsSecurityApplyText">Bakım modu ve login IP kısıtı gerçekten uygulanır.</p></div></div>
                <div class="form-grid">
                    <label class="form-field form-field-wide"><span data-i18n="maintenanceModeLabel">Bakım Modu</span><label class="toggle-field"><input type="checkbox" name="maintenance_mode" value="1" <?= $settings['maintenance_mode'] === '1' ? 'checked' : '' ?>><span class="toggle-slider"></span></label></label>
                    <label class="form-field form-field-wide"><span data-i18n="maintenanceMessageLabel">Bakım Mesajı</span><input type="text" name="maintenance_message" maxlength="255" value="<?= htmlspecialchars((string) $settings['maintenance_message'], ENT_QUOTES, "UTF-8") ?>"></label>
                    <label class="form-field form-field-wide"><span data-i18n="loginIpAllowLabel">İzinli Login IP'leri (virgülle ayır)</span><input type="text" name="login_ip_allow" maxlength="500" value="<?= htmlspecialchars((string) $settings['login_ip_allow'], ENT_QUOTES, "UTF-8") ?>" placeholder="örn. 192.168.1.10, 10.0.0.5"><small data-i18n="loginIpAllowHint">Boş bırakılırsa kısıtlama yok; liste doluysa sadece bu IP'ler giriş sayfasına erişebilir.</small></label>
                </div>
            </section>

            <div class="form-actions" style="margin-top:20px;">
                <button class="primary-button" type="submit" data-i18n="saveButton">Kaydet</button>
            </div>
        </form>

        <section class="page-section console-card">
            <div class="section-heading compact-heading"><div><h3 data-i18n="systemSettingsRetentionTitle">H · Veri Saklama / Temizlik</h3><p data-i18n="systemSettingsRetentionText">Denetim izi, saklama süresini aşan eski kayıtları temizler (onaylı).</p></div></div>
            <div class="two-col">
            <form method="post" action="system-settings.php" onsubmit="return confirm('Eski denetim izi kayıtları silinsin mi?');">
                <?= qmsCsrfField($csrfScope) ?>
                <input type="hidden" name="action" value="purge_audit">
                <div class="form-actions"><button class="danger-button" type="submit" data-i18n="purgeAuditButton">Denetim İzi Temizle</button></div>
            </form>
            <form method="post" action="system-settings.php">
                <?= qmsCsrfField($csrfScope) ?>
                <input type="hidden" name="action" value="archive_audit">
                <div class="form-actions"><button class="secondary-button" type="submit" data-i18n="archiveAuditButton">Arşivle + Temizle (JSON)</button></div>
            </form>
            </div>
        </section>
    </main>
    <script src="assets/js/theme.js"></script><script src="assets/js/language.js"></script><script src="assets/js/sidebar.js"></script><script src="assets/js/pwa.js"></script>
</body>
</html>
