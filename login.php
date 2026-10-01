<?php

session_start();

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/access.php';
require_once __DIR__ . '/includes/settings-functions.php';

$sysLoginLock = [
    'max' => (int) qmsSetting('max_login_attempts', '5'),
    'minutes' => (int) qmsSetting('lockout_minutes', '15'),
];
$loginIp = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

// I) Marka / gorunum.
$sysAppName = trim(qmsSetting('app_name', 'QuAmi'));
$sysLoginTitle = trim(qmsSetting('login_title', 'Kalite Yönetim Sistemi'));

if (isset($_SESSION["qms_logged_in"]) && $_SESSION["qms_logged_in"] === true) {
    header("Location: " . qmsLandingPage((string) ($_SESSION["qms_role"] ?? "")));
    exit;
}

$loginError = "";

// Giris formu icin CSRF token'i. Bu, "giris CSRF" saldirisini engeller: saldirgan
// kurbanin tarayicisini kendi hesabina giris yapmaya zorlayip sonra kurbanin
// girdigi verileri okuyamaz.
$_SESSION["qms_login_csrf"] ??= bin2hex(random_bytes(32));

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $expectedLoginToken = (string) ($_SESSION["qms_login_csrf"] ?? "");
    if ($expectedLoginToken === "" || !hash_equals($expectedLoginToken, (string) ($_POST["csrf"] ?? ""))) {
        http_response_code(403);
        exit("Geçersiz istek.");
    }

    $username = trim($_POST["username"] ?? "");
    $password = trim($_POST["password"] ?? "");

    // G) Login kilit: kullanici adi/IP icin son kilit penceresindeki hatali denemeler.
    $lockWindowStart = date('Y-m-d H:i:s', strtotime('-' . $sysLoginLock['minutes'] . ' minutes'));
    $cntStmt = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE attempted_at >= ? AND (username = ? OR (ip_address IS NOT NULL AND ip_address = ?))');
    $cntStmt->execute([$lockWindowStart, $username, $loginIp]);
    $failed = (int) $cntStmt->fetchColumn();
    if ($failed >= $sysLoginLock['max']) {
        $loginError = 'Çok fazla hatalı deneme. ' . $sysLoginLock['minutes'] . ' dakika sonra tekrar deneyin.';
    } else {

    $stmt = $pdo->prepare(
        "SELECT id, username, password_hash, full_name, role
         FROM users
         WHERE username = :username AND active = 1
         LIMIT 1"
    );
    $stmt->execute(["username" => $username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($password, $user["password_hash"])) {
        // Oturum sabitlemeyi onlemek icin giris aninda oturum kimligi yenilenir
        // (oturum verisi korunur, yalnizca id degisir).
        session_regenerate_id(true);

        $_SESSION["qms_logged_in"] = true;
        $_SESSION["qms_user_id"] = (int) $user["id"];
        $_SESSION["qms_username"] = $user["username"];
        $_SESSION["qms_full_name"] = $user["full_name"];
        $_SESSION["qms_role"] = $user["role"];

        // Basarili giris: kullanici adinin hatali gecmisini temizle.
        $clear = $pdo->prepare('DELETE FROM login_attempts WHERE username = ?');
        $clear->execute([$username]);

        header("Location: " . qmsLandingPage((string) $user["role"]));
        exit;
    }

    // Basarisiz giris: denemeyi kaydet (kilit icin).
    $ins = $pdo->prepare('INSERT INTO login_attempts (username, ip_address) VALUES (?, ?)');
    $ins->execute([$username, $loginIp]);
    $loginError = "Kullanıcı adı veya şifre hatalı.";
    } // G kilit kapanisi
}

?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f9fafb">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="<?= htmlspecialchars($sysAppName, ENT_QUOTES, "UTF-8") ?>">

    <title><?= htmlspecialchars($sysAppName, ENT_QUOTES, "UTF-8") ?> Giriş</title>

    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="assets/icons/qms-icon-192.png">
    <link rel="stylesheet" href="assets/css/style.css">
</head>

<body>

    <header class="topbar">
        <div class="topbar-inner">

            <div class="brand">
                <a class="brand" href="index.php">
                <div class="brand-icon"><img src="assets/icons/qms-logo.png" alt="<?= htmlspecialchars($sysAppName, ENT_QUOTES, "UTF-8") ?>"></div>

                <div class="brand-text">
                    <strong><?= htmlspecialchars($sysAppName, ENT_QUOTES, "UTF-8") ?></strong>
                    <span>Quality Management System</span>
                </div>
            </a>
            </div>

            <div class="topbar-actions">
                <button class="topbar-button" id="languageToggle" type="button">
                    EN
                </button>

                <button class="topbar-button" id="themeToggle" type="button" aria-label="Tema değiştir">
                    🌙
                </button>
            </div>

        </div>
    </header>

    <main class="page-container auth-page">

        <section class="welcome-card auth-intro">
            <h1 data-i18n="loginTitle"><?= htmlspecialchars($sysLoginTitle ?: $sysAppName, ENT_QUOTES, "UTF-8") ?></h1>

            <p data-i18n="loginText">
                Dashboard'a erişmek için güvenli giriş yapın.
            </p>
        </section>

        <section class="login-card">
            <h2 data-i18n="loginFormTitle">Giriş Yap</h2>

            <?php if ($loginError !== ""): ?>
                <div class="form-message error" data-i18n="loginErrorMessage">
                    <?= htmlspecialchars($loginError, ENT_QUOTES, "UTF-8") ?>
                </div>
            <?php endif; ?>

            <form class="login-form" method="post" action="login.php">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars((string) $_SESSION["qms_login_csrf"], ENT_QUOTES, "UTF-8") ?>">
                <label class="form-field">
                    <span data-i18n="usernameLabel">Kullanıcı Adı</span>
                    <input type="text" name="username" autocomplete="username" required>
                </label>

                <label class="form-field">
                    <span data-i18n="passwordLabel">Şifre</span>
                    <input type="password" name="password" autocomplete="current-password" required>
                </label>

                <button class="primary-button" type="submit" data-i18n="loginButton">
                    Giriş
                </button>
            </form>
        </section>

    </main>

    <script src="assets/js/theme.js"></script>
    <script src="assets/js/language.js"></script>
    <script src="assets/js/pwa.js"></script>
</body>
</html>
