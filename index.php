<?php

session_start();

$isLoggedIn = isset($_SESSION["qms_logged_in"]) && $_SESSION["qms_logged_in"] === true;
$href = $isLoggedIn ? 'dashboard.php' : 'login.php';
$ctaLabel = $isLoggedIn ? 'Dashboard' : 'Giriş Yap';

?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f9fafb">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="QuAmi">

    <title>QuAmi Yönetim Sistemi</title>

    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="assets/icons/qms-icon-192.png">
    <link rel="stylesheet" href="assets/css/style.css">
    <meta name="author" content="Islam Turfent">
</head>

<body class="landing-body">

    <header class="topbar landing-header">
        <div class="topbar-inner">
            <a class="brand" href="index.php">
                <div class="brand-icon"><img src="assets/icons/qms-logo.png" alt="QuAmi"></div>
                <div class="brand-text">
                    <strong>QuAmi</strong>
                    <span data-i18n="landingBrandTagline">Kalite Yönetim Sistemi</span>
                </div>
            </a>

            <nav class="landing-nav" aria-label="Ana menü">
                <a href="#landing-solutions" data-i18n="landingNavSolutions">Özellikler</a>
                <a href="#landing-steps" data-i18n="landingNavHow">Nasıl Çalışır?</a>
                <a href="#landing-cta" data-i18n="landingNavStart">Başla</a>
            </nav>

            <div class="topbar-actions">
                <button class="topbar-button" id="languageToggle" type="button">EN</button>
                <button class="topbar-button" id="themeToggle" type="button" aria-label="Tema değiştir">🌙</button>
                <a class="primary-button landing-cta-link" href="<?= $href ?>" data-i18n="<?= $isLoggedIn ? 'dashboardLinkLabel' : 'loginLinkLabel' ?>">
                    <?= $ctaLabel ?>
                </a>
            </div>
        </div>
    </header>

    <main class="landing-page">

        <!-- HERO -->
        <section class="landing-hero landing-wrap">
            <div class="landing-hero-content">
                <span class="landing-kicker" data-i18n="landingKicker">Kalite süreçleri için merkezi platform</span>
                <h1><span data-i18n="landingTitle">Denetimden raporlamaya, tüm kalite süreçleri</span> <em data-i18n="landingTitleEm">tek ekranda</em>.</h1>
                <p data-i18n="landingText">
                    QuAmi; doküman kontrolü, denetim, uygunsuzluk (CAPA), risk, eğitim ve performans süreçlerini uçtan uca
                    yöneten modern bir kalite yönetim sistemidir. Denetime her an hazır olun.
                </p>
                <div class="landing-actions">
                    <a class="primary-button landing-button" href="<?= $href ?>" data-i18n="<?= $isLoggedIn ? 'goDashboardButton' : 'goLoginButton' ?>">
                        <?= $isLoggedIn ? 'Dashboard’a Git' : 'Giriş Yap' ?>
                    </a>
                    <a class="secondary-button landing-button landing-ghost" href="#landing-solutions" data-i18n="landingExploreButton">Özellikleri Keşfet</a>
                </div>
                <div class="landing-trust" data-i18n="landingTrustLine">ISO uyumlu süreçler · İzlenebilirlik · Otomatik raporlama</div>
            </div>

            <div class="landing-hero-visual" aria-label="QuAmi özet paneli">
                <div class="visual-card visual-card-main">
                    <div class="visual-card-head">
                        <span class="visual-title">
                            <span class="visual-dot"></span>
                            <span data-i18n="landingPanelLive">Canlı Pano</span>
                        </span>
                        <span class="visual-badge" data-i18n="landingPanelLive2">7/24</span>
                    </div>
                    <div class="visual-card-grid">
                        <div class="visual-metric">
                            <span data-i18n="landingPanelAudits">Denetim Takibi</span>
                            <strong>12</strong>
                            <div class="visual-bar"><i style="width:64%"></i></div>
                        </div>
                        <div class="visual-metric">
                            <span data-i18n="landingPanelDocs">Aktif Doküman</span>
                            <strong>86</strong>
                            <div class="visual-bar"><i style="width:82%"></i></div>
                        </div>
                        <div class="visual-metric">
                            <span data-i18n="landingPanelReports">Açık Aksiyon</span>
                            <strong>4</strong>
                            <div class="visual-bar"><i style="width:38%"></i></div>
                        </div>
                        <div class="visual-metric">
                            <span data-i18n="landingPanelCompliance">Uyum Oranı</span>
                            <strong>%98</strong>
                            <div class="visual-bar"><i style="width:98%"></i></div>
                        </div>
                    </div>
                </div>
                <div class="visual-card visual-card-float">
                    <span class="visual-float-label" data-i18n="landingPanelReport">Aylık Rapor</span>
                    <strong>Denetime Hazır</strong>
                </div>
            </div>
        </section>

        <!-- STATS -->
        <section class="landing-stats landing-wrap">
            <div class="stat-card"><strong>20+</strong><span data-i18n="landingStatModules">Yönetim Modülü</span></div>
            <div class="stat-card"><strong>100%</strong><span data-i18n="landingStatTrace">İzlenebilirlik</span></div>
            <div class="stat-card"><strong>7/24</strong><span data-i18n="landingStatLive">Canlı Raporlama</span></div>
            <div class="stat-card"><strong>ISO</strong><span data-i18n="landingStatCompliant">Uyumlu Süreçler</span></div>
        </section>

        <!-- SOLUTIONS -->
        <section class="landing-section landing-wrap" id="landing-solutions">
            <div class="landing-section-head">
                <span class="landing-kicker" data-i18n="landingSolutionsKicker">Çözümler</span>
                <h2 data-i18n="landingSolutionsTitle">Kalitenin her aşaması tek platformda.</h2>
                <p data-i18n="landingSolutionsText">Dokümandan denetime, CAPA'dan eğitime; ekibinizi ve kuruluşunuzu denetime hazır tutan yönetim alanları.</p>
            </div>

            <div class="landing-solutions">
                <article class="solution-card">
                    <div class="solution-icon"><?= svg_icon('audit') ?></div>
                    <h3 data-i18n="landingS1Title">Denetim Yönetimi</h3>
                    <p data-i18n="landingS1Text">Plan, kontrol listesi ve bulgu yönetimiyle denetimleri uçtan uca yürütün.</p>
                </article>
                <article class="solution-card">
                    <div class="solution-icon"><?= svg_icon('doc') ?></div>
                    <h3 data-i18n="landingS2Title">Doküman Kontrolü</h3>
                    <p data-i18n="landingS2Text">Versiyonlu doküman, onay akışı ve dağıtım kontrolüyle güncel sürüm her zaman elinizde.</p>
                </article>
                <article class="solution-card">
                    <div class="solution-icon"><?= svg_icon('capa') ?></div>
                    <h3 data-i18n="landingS3Title">CAPA & İyileştirme</h3>
                    <p data-i18n="landingS3Text">Uygunsuzluk, kök neden analizi ve doğrulama ile kapanışa kadar aksiyonları takip edin.</p>
                </article>
                <article class="solution-card">
                    <div class="solution-icon"><?= svg_icon('risk') ?></div>
                    <h3 data-i18n="landingS4Title">Risk & Fırsat</h3>
                    <p data-i18n="landingS4Text">Riskleri değerlendirin, aksiyonları atayın ve süreçleri öngörüyle yönetin.</p>
                </article>
                <article class="solution-card">
                    <div class="solution-icon"><?= svg_icon('training') ?></div>
                    <h3 data-i18n="landingS5Title">Eğitim & Yetkinlik</h3>
                    <p data-i18n="landingS5Text">Eğitimleri atayın, katılımı kaydedin ve yetkinlik vadesini takip edin.</p>
                </article>
                <article class="solution-card">
                    <div class="solution-icon"><?= svg_icon('report') ?></div>
                    <h3 data-i18n="landingS6Title">Raporlama & KPI</h3>
                    <p data-i18n="landingS6Text">Excel/PDF raporları ve dönem özetiyle karar verin, paydaşlarla paylaşın.</p>
                </article>
            </div>
        </section>

        <!-- STEPS -->
        <section class="landing-section landing-wrap" id="landing-steps">
            <div class="landing-section-head">
                <span class="landing-kicker" data-i18n="landingStepsKicker">Nasıl Çalışır?</span>
                <h2 data-i18n="landingStepsTitle">Sürece 4 adımda hazır olun.</h2>
            </div>
            <div class="landing-steps">
                <div class="step-card"><span class="step-num">01</span><h3 data-i18n="landingStep1Title">Planla</h3><p data-i18n="landingStep1Text">Kalite planı ve denetim takviminizi oluşturun.</p></div>
                <div class="step-card"><span class="step-num">02</span><h3 data-i18n="landingStep2Title">Uygula</h3><p data-i18n="landingStep2Text">Doküman, denetim ve aksiyon süreçlerini yürütün.</p></div>
                <div class="step-card"><span class="step-num">03</span><h3 data-i18n="landingStep3Title">Takip Et</h3><p data-i18n="landingStep3Text">Bulguları, CAPA'yı ve eğitimleri izleyin.</p></div>
                <div class="step-card"><span class="step-num">04</span><h3 data-i18n="landingStep4Title">Raporla</h3><p data-i18n="landingStep4Text">Canlı KPI ve dönem özetiyle denetime hazır olun.</p></div>
            </div>
        </section>

        <!-- CTA -->
        <section class="landing-cta landing-wrap" id="landing-cta">
            <div class="landing-cta-inner">
                <div>
                    <h2 data-i18n="landingCtaTitle">Kalite yolculuğunuza bugün başlayın.</h2>
                    <p data-i18n="landingCtaText">Tüm modülleri tek panelden yönetin; denetime her an hazır olun.</p>
                </div>
                <a class="primary-button landing-button landing-cta-btn" href="<?= $href ?>" data-i18n="<?= $isLoggedIn ? 'goDashboardButton' : 'goLoginButton' ?>">
                    <?= $isLoggedIn ? 'Dashboard’a Git' : 'Giriş Yap' ?>
                </a>
            </div>
        </section>

    </main>

    <footer class="landing-footer">
        <div class="landing-wrap landing-footer-inner">
            <div class="footer-brand">
                <a class="brand" href="index.php">
                    <div class="brand-icon"><img src="assets/icons/qms-logo.png" alt="QuAmi"></div>
                    <div class="brand-text"><strong>QuAmi</strong><span data-i18n="landingBrandTagline">Kalite Yönetim Sistemi</span></div>
                </a>
                <p data-i18n="landingFooterText">Denetim ve belgelendirme süreçlerini tek platformda toplayan modern yönetim sistemi.</p>
            </div>
            <div class="footer-col">
                <h4 data-i18n="landingFooterModules">Modüller</h4>
                <a href="documents.php" data-i18n="landingFooterDoc">Doküman Yönetimi</a>
                <a href="audit-programs.php" data-i18n="landingFooterAudit">Denetim Yönetimi</a>
                <a href="capa.php" data-i18n="landingFooterCapa">CAPA</a>
                <a href="trainings.php" data-i18n="landingFooterTrain">Eğitim</a>
            </div>
            <div class="footer-col">
                <h4 data-i18n="landingFooterSystem">Sistem</h4>
                <a href="reports.php" data-i18n="landingFooterReport">Raporlama & KPI</a>
                <a href="notifications.php" data-i18n="landingFooterNotif">Bildirim Merkezi</a>
                <a href="profile.php" data-i18n="landingFooterProfile">Hesap</a>
            </div>
        </div>
        <div class="landing-wrap landing-footer-bottom">
            <span>© <?= date('Y') ?> QuAmi · Quality Management System</span>
        </div>
    </footer>

    <script src="assets/js/theme.js"></script>
    <script src="assets/js/language.js"></script>
    <script src="assets/js/pwa.js"></script>
</body>
</html>
<?php

/** İkonları tek yerden üretir (stroke tabanlı, temaya uyar). */
function svg_icon(string $name): string
{
    $paths = [
        'audit'    => '<rect x="3" y="4" width="18" height="15" rx="2"/><path d="M8 9h4M8 13h8M16 17h1"/><path d="M7 5l1.2 3L8 11"/>',
        'doc'      => '<path d="M7 3h7l5 5v13H7z"/><path d="M14 3v5h5"/><path d="M10 13h6M10 17h4"/>',
        'capa'     => '<path d="M12 3l7 4v5c0 4-2.8 7-7 9-4.2-2-7-5-7-9V7z"/><path d="M9 12l2 2 4-4"/>',
        'risk'     => '<path d="M12 3l9 16H3z"/><path d="M12 9v4M12 16h.01"/>',
        'training' => '<rect x="3" y="6" width="13" height="12" rx="2"/><path d="M16 10h4v6h-4"/><path d="M7 10h5M7 14h3"/>',
        'report'   => '<path d="M3 4h18M5 4v16h14V4"/><rect x="7" y="8" width="3" height="6" fill="currentColor" stroke="none"/><rect x="12" y="8" width="3" height="9" fill="currentColor" stroke="none"/><rect x="17" y="8" width="3" height="3" fill="currentColor" stroke="none"/>',
    ];
    $body = $paths[$name] ?? $paths['report'];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $body . '</svg>';
}
