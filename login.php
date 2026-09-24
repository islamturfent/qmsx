<?php

session_start();

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/access.php';

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

        header("Location: " . qmsLandingPage((string) $user["role"]));
        exit;
    }

    $loginError = "Kullanıcı adı veya şifre hatalı.";
}

?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f9fafb">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="QMS">

    <title>QMS Giriş</title>

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
                <div class="brand-icon"><img src="assets/icons/qms-logo.png" alt="QMS"></div>

                <div class="brand-text">
                    <strong>QMS</strong>
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
            <h1 data-i18n="loginTitle">QMS Yönetim Sistemi</h1>

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
