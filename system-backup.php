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

qmsRequirePermission('admin.system');

$userId = (int) ($_SESSION["qms_user_id"] ?? 0);
$csrfScope = 'system_backup';
$activeNav = "system_backup";

// Yedek al / indir (yalnizca admin, GET + CSRF tokeni query'de degil, POST ile tetiklenir).
$backupError = '';
if ($_SERVER["REQUEST_METHOD"] === "POST" && (($_POST["action"] ?? "") === "backup")) {
    qmsCsrfVerify($csrfScope, $_POST["csrf"] ?? null);

    // DB baglanti bilgileri config/database.php'den.
    $dumpPath = '';
    foreach (['C:/xampp/mysql/bin/mysqldump.exe', '/usr/bin/mysqldump', '/usr/local/bin/mysqldump'] as $candidate) {
        if (is_file($candidate)) { $dumpPath = $candidate; break; }
    }
    if ($dumpPath === '' && function_exists('shell_exec')) {
        $which = trim((string) shell_exec('command -v mysqldump 2>/dev/null'));
        if ($which !== '') {
            $dumpPath = $which;
        }
    }

    if ($dumpPath === '') {
        $backupError = "mysqldump bulunamadı. Host'u kendi yedekleme aracıyla kullanın.";
    } else {
        $cmd = escapeshellarg($dumpPath)
            . ' --host=' . escapeshellarg($host ?? 'localhost')
            . ' --user=' . escapeshellarg($username ?? 'root')
            . ($password !== '' ? ' --password=' . escapeshellarg((string) $password) : '')
            . ' ' . escapeshellarg((string) $dbname);
        $out = shell_exec($cmd . ' 2>&1');
        if ($out === null || trim((string) $out) === '') {
            $backupError = 'Yedek üretilemedi (mysqldump çıktısı boş). Komutu host konsolunda test edin.';
        } else {
            $filename = 'qms-backup-' . date('Ymd-His') . '.sql';
            header('Content-Type: application/sql; charset=UTF-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . strlen($out));
            header('Cache-Control: private, no-store');
            echo $out;
            exit;
        }
    }
}

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="theme-color" content="#f9fafb">
    <title>QuAmi Veritabanı Yedeği</title><link rel="manifest" href="manifest.webmanifest"><link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml"><link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar"><div class="topbar-inner"><div class="page-title-block"><strong data-i18n="systemBackupTitle">Veritabanı Yedeği</strong><span data-i18n="systemBackupText">Tüm QMS veritabanının SQL yedeğini indirin.</span></div><div class="topbar-actions"><button class="topbar-button" id="languageToggle" type="button">EN</button><button class="topbar-button" id="themeToggle" type="button" aria-label="Tema değiştir">🌙</button></div></div></header>
    <main class="page-container">
        <section class="page-heading page-heading-actions">
            <div>
                <span class="section-kicker" data-i18n="systemSettingsKicker">Sistem Yönetimi</span>
                <h1 data-i18n="systemBackupTitle">Veritabanı Yedeği</h1>
                <p data-i18n="systemBackupText">mysqldump ile tüm şemayı ve veriyi tek SQL dosyası olarak indirin.</p>
            </div>
            <a class="secondary-button" href="system-settings.php" data-i18n="systemSettingsLink">Sistem Ayarları</a>
        </section>

        <?php if ($backupError !== ""): ?><div class="form-message error"><?= htmlspecialchars($backupError, ENT_QUOTES, "UTF-8") ?></div><?php endif; ?>

        <section class="page-section console-card">
            <div class="section-heading compact-heading"><div><h3 data-i18n="systemBackupTitle">Yedek Al</h3><p data-i18n="systemBackupText">İndirme, yedeği oluşturduktan sonra otomatik başlar.</p></div></div>
            <form method="post" action="system-backup.php">
                <?= qmsCsrfField($csrfScope) ?>
                <input type="hidden" name="action" value="backup">
                <div class="form-actions">
                    <button class="primary-button" type="submit" data-i18n="systemBackupButton">Yedeği İndir (.sql)</button>
                </div>
            </form>
        </section>
    </main>
    <script src="assets/js/theme.js"></script><script src="assets/js/language.js"></script><script src="assets/js/sidebar.js"></script><script src="assets/js/pwa.js"></script>
</body>
</html>
