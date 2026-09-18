<?php

session_start();

$isLoggedIn = isset($_SESSION["qms_logged_in"]) && $_SESSION["qms_logged_in"] === true;

?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f9fafb">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="QMS">

    <title>QMS Yönetim Sistemi</title>

    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="assets/icons/qms-icon-192.png">
    <link rel="stylesheet" href="assets/css/style.css">
</head>

<body>

    <header class="topbar">
        <div class="topbar-inner">

            <div class="brand">
                <div class="brand-icon">Q</div>

                <div class="brand-text">
                    <strong>QMS</strong>
                    <span>Quality Management System</span>
                </div>
            </div>

            <div class="topbar-actions">
                <button class="topbar-button" id="languageToggle" type="button">
                    EN
                </button>

                <button class="topbar-button" id="themeToggle" type="button" aria-label="Tema değiştir">
                    🌙
                </button>

                <a
                    class="topbar-button topbar-link"
                    href="<?= $isLoggedIn ? 'dashboard.php' : 'login.php' ?>"
                    data-i18n="<?= $isLoggedIn ? 'dashboardLinkLabel' : 'loginLinkLabel' ?>"
                >
                    <?= $isLoggedIn ? 'Dashboard' : 'Giriş' ?>
                </a>
            </div>

        </div>
    </header>

    <main class="page-container">

        <section class="landing-hero">
            <div class="landing-hero-content">
                <span class="landing-kicker" data-i18n="landingKicker">Kalite süreçleri için merkezi platform</span>

                <h1 data-i18n="landingTitle">QMS Yönetim Sistemi</h1>

                <p data-i18n="landingText">
                    Denetim, belgelendirme, uygunsuzluk ve performans süreçlerini tek ekranda takip etmek için tasarlanmış modern yönetim alanı.
                </p>

                <div class="landing-actions">
                    <a
                        class="primary-button landing-button"
                        href="<?= $isLoggedIn ? 'dashboard.php' : 'login.php' ?>"
                        data-i18n="<?= $isLoggedIn ? 'goDashboardButton' : 'goLoginButton' ?>"
                    >
                        <?= $isLoggedIn ? 'Dashboard’a Git' : 'Giriş Yap' ?>
                    </a>
                </div>
            </div>

            <div class="landing-panel" aria-label="QMS özet paneli">
                <div class="landing-panel-row">
                    <span data-i18n="landingPanelAudits">Denetim Takibi</span>
                    <strong>7/24</strong>
                </div>

                <div class="landing-panel-row">
                    <span data-i18n="landingPanelDocs">Belge Kontrolü</span>
                    <strong>ISO</strong>
                </div>

                <div class="landing-panel-row">
                    <span data-i18n="landingPanelReports">Raporlama</span>
                    <strong data-i18n="landingPanelLive">Canlı</strong>
                </div>
            </div>
        </section>

        <section class="feature-grid">
            <article class="feature-card">
                <div class="feature-icon">📋</div>
                <h2 data-i18n="featureAuditTitle">Denetim Yönetimi</h2>
                <p data-i18n="featureAuditText">Planlanan ve aktif denetimleri düzenli bir yapı içinde takip edin.</p>
            </article>

            <article class="feature-card">
                <div class="feature-icon">⚠️</div>
                <h2 data-i18n="featureRiskTitle">Uygunsuzluk Takibi</h2>
                <p data-i18n="featureRiskText">Açık aksiyonları, riskleri ve iyileştirme çalışmalarını görünür tutun.</p>
            </article>

            <article class="feature-card">
                <div class="feature-icon">📈</div>
                <h2 data-i18n="featurePerformanceTitle">Performans Görünümü</h2>
                <p data-i18n="featurePerformanceText">Kalite süreçlerinin durumunu dashboard üzerinden hızlıca okuyun.</p>
            </article>
        </section>

    </main>

    <script src="assets/js/theme.js"></script>
    <script src="assets/js/language.js"></script>
    <script src="assets/js/pwa.js"></script>
</body>
</html>
