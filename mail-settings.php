<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/mail.php';
require_once __DIR__ . '/includes/access.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/notifications.php';
require_once __DIR__ . '/includes/mailer.php';

$role = (string) ($_SESSION["qms_role"] ?? "");
if ($role !== "super_admin" && $role !== "system_admin") {
    http_response_code(403);
    exit("Yetkisiz erişim.");
}

$csrfScope = 'mail_settings';
$config = qmsMailConfig();
$settingsPath = __DIR__ . '/storage/mail/settings.json';
$message = "";
$messageType = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    qmsCsrfVerify($csrfScope, $_POST["csrf"] ?? null);
    $formType = (string) ($_POST["form_type"] ?? "");

    if ($formType === "save") {
        $saved = qmsMailDefaults();
        $saved['enabled'] = isset($_POST["enabled"]);
        $saved['from_email'] = trim((string) ($_POST["from_email"] ?? ""));
        $saved['from_name'] = trim((string) ($_POST["from_name"] ?? "QMS"));
        $saved['base_url'] = rtrim(trim((string) ($_POST["base_url"] ?? '')), '/') . '/';
        $saved['host'] = trim((string) ($_POST["host"] ?? ""));
        $saved['port'] = (int) ($_POST["port"] ?? 587);
        $saved['username'] = trim((string) ($_POST["username"] ?? ""));
        $password = (string) ($_POST["password"] ?? "");
        if ($password !== "") {
            $saved['password'] = $password;
        } else {
            $saved['password'] = $config['password'] ?? '';
        }
        $saved['encryption'] = in_array($_POST["encryption"] ?? 'tls', ['none', 'tls', 'ssl'], true) ? $_POST["encryption"] : 'tls';
        $saved['timeout'] = max(5, min(120, (int) ($_POST["timeout"] ?? 15)));
        $saved['debug'] = isset($_POST["debug"]);

        $dir = dirname($settingsPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        file_put_contents($settingsPath, json_encode($saved, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        $config = qmsMailConfig();
        $message = "E-posta ayarları kaydedildi.";
        $messageType = "success";
    } elseif ($formType === "test") {
        $userId = (int) ($_SESSION["qms_user_id"] ?? 0);
        $stmt = $pdo->prepare("SELECT email, full_name FROM users WHERE id = ? AND active = 1 LIMIT 1");
        $stmt->execute([$userId]);
        $me = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$me || trim((string) ($me["email"] ?? "")) === "") {
            $message = "Bu hesapta e-posta adresi tanımlı değil. Önce kullanıcı profiline e-posta ekleyin.";
            $messageType = "error";
        } else {
            $content = qmsMailNotificationContent("QMS Test E-postası", "Bu bir e-posta bildirim testidir. Sistem SMTP üzerinden e-posta gönderebiliyor.", $_GET['_test_url'] ?? null);
            $sent = qmsMailSend((string) $me["email"], (string) ($me["full_name"] ?? '') !== '' ? (string) $me["full_name"] : null, $content['subject'], $content['html'], $content['plain']);
            $message = $sent ? "Test e-postası gönderildi: " . htmlspecialchars((string) $me["email"], ENT_QUOTES, "UTF-8") : "Test e-postası gönderilemedi (SMTP yapılandırmasını kontrol edin).";
            $messageType = $sent ? "success" : "error";
        }
    }
}

$activeNav = "mail_settings";
$encryptionOptions = ['none' => 'Şifresiz', 'tls' => 'TLS (önerilen)', 'ssl' => 'SSL'];

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="theme-color" content="#f9fafb">
    <title>QMS E-posta Ayarları</title><link rel="manifest" href="manifest.webmanifest"><link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml"><link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar"><div class="topbar-inner"><div class="page-title-block"><strong data-i18n="mailSettingsTitle">E-posta Ayarları</strong><span data-i18n="mailSettingsText">SMTP ile bildirim e-postası gönderimini yapılandırın.</span></div><div class="topbar-actions"><button class="topbar-button" id="languageToggle" type="button">EN</button><button class="topbar-button" id="themeToggle" type="button" aria-label="Tema değiştir">🌙</button></div></div></header>
    <main class="page-container">
        <section class="page-heading page-heading-actions">
            <div>
                <span class="section-kicker" data-i18n="mailSettingsKicker">Sistem Kurulumu</span>
                <h1 data-i18n="mailSettingsTitle">E-posta Ayarları</h1>
                <p data-i18n="mailSettingsText">Bildirimler uygulama içinde görünür; SMTP etkinleştirilirse alıcılara e-posta da gönderilir.</p>
            </div>
            <span class="status-pill <?= $config['enabled'] ? 'on-track' : 'no-target' ?>"><?= $config['enabled'] ? 'E-posta AÇIK' : 'E-posta KAPALI' ?></span>
        </section>

        <?php if ($message !== ""): ?><div class="form-message <?= $messageType ?>"><?= $message ?></div><?php endif; ?>

        <section class="form-panel">
            <div class="section-heading compact-heading"><div><h3 data-i18n="mailSettingsFormTitle">SMTP Yapılandırması</h3><p data-i18n="mailSettingsFormText">Gmail: host=smtp.gmail.com, port=587, encryption=TLS, kullanıcı=adres, şifre=uygulama şifresi.</p></div></div>
            <form class="auditor-form" method="post" action="mail-settings.php">
                <?= qmsCsrfField($csrfScope) ?>
                <input type="hidden" name="form_type" value="save">
                <div class="form-grid">
                    <label class="form-field form-field-wide"><span class="switch-label"><input type="checkbox" name="enabled" <?= $config['enabled'] ? 'checked' : '' ?>> <span data-i18n="mailEnabledLabel">E-posta gönderimini etkinleştir</span></span></label>
                    <label class="form-field"><span data-i18n="mailFromEmailLabel">Gönderen E-posta</span><input type="email" name="from_email" value="<?= htmlspecialchars((string) $config['from_email'], ENT_QUOTES, 'UTF-8') ?>"></label>
                    <label class="form-field"><span data-i18n="mailFromNameLabel">Gönderen Adı</span><input type="text" name="from_name" value="<?= htmlspecialchars((string) $config['from_name'], ENT_QUOTES, 'UTF-8') ?>"></label>
                    <label class="form-field"><span data-i18n="mailHostLabel">SMTP Sunucu</span><input type="text" name="host" value="<?= htmlspecialchars((string) $config['host'], ENT_QUOTES, 'UTF-8') ?>" placeholder="smtp.example.com"></label>
                    <label class="form-field"><span data-i18n="mailPortLabel">Port</span><input type="number" name="port" min="1" max="65535" value="<?= (int) $config['port'] ?>"></label>
                    <label class="form-field form-field-wide"><span data-i18n="mailBaseUrlLabel">Uygulama URL'si (mail linkleri için)</span><input type="text" name="base_url" value="<?= htmlspecialchars((string) $config['base_url'], ENT_QUOTES, 'UTF-8') ?>" placeholder="http://localhost/qmsx/"></label>
                    <label class="form-field"><span data-i18n="mailEncryptionLabel">Şifreleme</span><select name="encryption"><?php foreach ($encryptionOptions as $ek => $el): ?><option value="<?= $ek ?>" <?= $config['encryption'] === $ek ? 'selected' : '' ?>><?= htmlspecialchars($el, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></label>
                    <label class="form-field"><span data-i18n="mailUsernameLabel">Kullanıcı Adı</span><input type="text" name="username" value="<?= htmlspecialchars((string) $config['username'], ENT_QUOTES, 'UTF-8') ?>" autocomplete="off"></label>
                    <label class="form-field"><span data-i18n="mailPasswordLabel">Şifre</span><input type="password" name="password" placeholder="••••••••" autocomplete="new-password"></label>
                    <label class="form-field"><span data-i18n="mailTimeoutLabel">Zaman Aşımı (sn)</span><input type="number" name="timeout" min="5" max="120" value="<?= (int) $config['timeout'] ?>"></label>
                    <label class="form-field form-field-wide"><span class="switch-label"><input type="checkbox" name="debug" <?= $config['debug'] ? 'checked' : '' ?>> <span data-i18n="mailDebugLabel">Hata ayıklama</span></span></label>
                </div>
                <div class="form-actions">
                    <button class="primary-button" type="submit" data-i18n="mailSaveButton">Kaydet</button>
                </div>
            </form>
        </section>

        <section class="form-panel">
            <div class="section-heading compact-heading"><div><h3 data-i18n="mailTestTitle">Test E-postası Gönder</h3><p data-i18n="mailTestText">Geçerli ayarlarla bu hesabın e-posta adresine bir deneme mesajı yollar.</p></div></div>
            <form class="auditor-form" method="post" action="mail-settings.php">
                <?= qmsCsrfField($csrfScope) ?>
                <input type="hidden" name="form_type" value="test">
                <div class="form-actions">
                    <button class="secondary-button" type="submit" data-i18n="mailTestButton">Test E-postası Gönder</button>
                </div>
            </form>
        </section>
    </main>
    <script src="assets/js/theme.js"></script><script src="assets/js/language.js"></script><script src="assets/js/sidebar.js"></script><script src="assets/js/pwa.js"></script>
</body>
</html>
