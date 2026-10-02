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
                <span id="qmsIconSun" hidden><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v2.25m6.364.386-1.591 1.591M21 12h-2.25m-.386 6.364-1.591-1.591M12 18.75V21m-4.773-4.227-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0Z"/></svg></span>
                <span id="qmsIconMoon" hidden><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21.752 15.002A9.72 9.72 0 0 1 18 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 0 0 3 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 0 0 9.002-5.998Z"/></svg></span>
                <button class="topbar-button" id="languageToggle" type="button">EN</button>
                <button class="topbar-button" id="themeToggle" type="button" aria-label="Tema değiştir"><span class="topbar-btn-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21.752 15.002A9.72 9.72 0 0 1 18 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 0 0 3 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 0 0 9.002-5.998Z"/></svg></span></button>
                <a class="primary-button landing-cta-link" href="<?= $href ?>" data-i18n="<?= $isLoggedIn ? 'dashboardLinkLabel' : 'loginLinkLabel' ?>">
                    <?= $ctaLabel ?>
                </a>
            </div>
        </div>
    </header>

    <main class="landing-page">

        <!-- HERO SLIDER -->
        <section class="landing-slider landing-wrap">
            <div class="landing-slides">

                <div class="landing-hero landing-slide is-active">
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
                </div>

                <div class="landing-hero landing-slide">
                    <div class="landing-hero-content">
                        <span class="landing-kicker" data-i18n="landingAiKicker">Yapay Zeka Desteği</span>
                        <h1><span data-i18n="landingAiTitle">Dokümanları yapay zekayla</span> <em data-i18n="landingAiTitleEm">anında hazırlayın</em>.</h1>
                        <p data-i18n="landingAiText">AI Doküman Stüdyosu ile dokümanı sesle veya yazıyla tarif edin; taslak saniyeler içinde oluşsun. Web editöründe düzenleyin, şablon olarak kaydedin.</p>
                        <div class="landing-actions">
                            <a class="primary-button landing-button" href="ai-document-studio.php" data-i18n="landingAiCta">AI Stüdyo</a>
                            <a class="secondary-button landing-button landing-ghost" href="<?= $href ?>" data-i18n="<?= $isLoggedIn ? 'goDashboardButton' : 'goLoginButton' ?>"><?= $isLoggedIn ? 'Dashboard’a Git' : 'Giriş Yap' ?></a>
                        </div>
                        <div class="landing-trust" data-i18n="landingAiTrust">Sesli komut · Taslak üretimi · Şablona kaydetme</div>
                    </div>

                    <div class="landing-hero-visual" aria-label="AI Doküman Stüdyosu">
                        <div class="visual-card visual-card-main">
                            <div class="visual-card-head">
                                <span class="visual-title"><span class="visual-dot"></span><span data-i18n="landingAiStudio">AI Doküman Stüdyosu</span></span>
                                <span class="visual-badge">AI</span>
                            </div>
                            <div class="ai-chat">
                                <div class="ai-bubble ai-prompt" data-i18n="landingAiPrompt">"ISO iç denetim prosedürü taslağı oluştur"</div>
                                <div class="ai-bubble ai-reply"><span data-i18n="landingAiReply">Taslak hazır</span> · <strong>Bölüm 1 — Kapsam</strong></div>
                            </div>
                            <div class="ai-chips">
                                <span class="ai-chip" data-i18n="landingAiChipDoc">Dokümana Aktar</span>
                                <span class="ai-chip" data-i18n="landingAiChipTpl">Şablon Olarak Kaydet</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="landing-hero landing-slide">
                    <div class="landing-hero-content">
                        <span class="landing-kicker" data-i18n="landingAutoKicker">Otomasyon</span>
                        <h1><span data-i18n="landingAutoTitle">Vade takibi ve bildirimler</span> <em data-i18n="landingAutoTitleEm">kendiliğinden</em>.</h1>
                        <p data-i18n="landingAutoText">Geçiken işler, sözleşme/kalibrasyon/eğitim vadeleri ve dönem özetleri otomatik işler; ekibiniz denetime her an hazır olur.</p>
                        <div class="landing-actions">
                            <a class="primary-button landing-button" href="<?= $href ?>" data-i18n="<?= $isLoggedIn ? 'goDashboardButton' : 'goLoginButton' ?>"><?= $isLoggedIn ? 'Dashboard’a Git' : 'Giriş Yap' ?></a>
                            <a class="secondary-button landing-button landing-ghost" href="#landing-steps" data-i18n="landingAutoGhost">Nasıl Çalışır?</a>
                        </div>
                        <div class="landing-trust" data-i18n="landingAutoTrust">Gecikme bildirimi · Vade uyarıları · Dönem özeti</div>
                    </div>

                    <div class="landing-hero-visual" aria-label="Otomatik bildirimler">
                        <div class="visual-card visual-card-main">
                            <div class="visual-card-head">
                                <span class="visual-title"><span class="visual-dot"></span><span data-i18n="landingAutoLive">Otomatik Bildirimler</span></span>
                                <span class="visual-badge" data-i18n="landingAutoBadge">Açık</span>
                            </div>
                            <div class="auto-list">
                                <div class="auto-row"><span data-i18n="landingAutoItem1">Geçiken Denetim</span><strong class="auto-flag">Uyarı</strong></div>
                                <div class="auto-row"><span data-i18n="landingAutoItem2">Sözleşme Bitişi</span><strong class="auto-flag">30 gün</strong></div>
                                <div class="auto-row"><span data-i18n="landingAutoItem3">Kalibrasyon Vadesi</span><strong class="auto-flag">7 gün</strong></div>
                                <div class="auto-row"><span data-i18n="landingAutoItem4">Yetkinlik Vadesi</span><strong class="auto-flag">15 gün</strong></div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>

            <div class="landing-slider-controls">
                <button class="slider-arrow slider-prev" type="button" aria-label="Önceki">‹</button>
                <div class="slider-dots">
                    <button class="slider-dot is-active" type="button" aria-label="1"></button>
                    <button class="slider-dot" type="button" aria-label="2"></button>
                    <button class="slider-dot" type="button" aria-label="3"></button>
                </div>
                <button class="slider-arrow slider-next" type="button" aria-label="Sonraki">›</button>
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

        <!-- AI SECTION -->
        <section class="landing-section landing-wrap landing-ai" id="landing-ai">
            <div class="landing-ai-panel">
                <div class="landing-ai-intro">
                    <span class="landing-kicker" data-i18n="landingAiSecKicker">Yapay Zeka Öne Çıkan</span>
                    <h2 data-i18n="landingAiSecTitle">Yapay zeka desteğiyle daha az iş, daha hızlı sonuç.</h2>
                    <p data-i18n="landingAiSecText">AI Doküman Stüdyosu; taslak üretiminden sesli komuta kadar doküman süreçlerinizi otomatikleştirir ve ekibinizi denetime hazır tutar.</p>
                    <a class="primary-button landing-button" href="ai-document-studio.php" data-i18n="landingAiSecCta">AI Stüdyo'yu Aç</a>
                </div>
                <div class="landing-ai-highlights">
                    <article class="ai-card">
                        <div class="ai-card-icon"><?= svg_icon('mic') ?></div>
                        <h3 data-i18n="landingAiCard1Title">Sesli Komut</h3>
                        <p data-i18n="landingAiCard1Text">Dokümanı konuşarak tarif edin; taslak anında hazır.</p>
                    </article>
                    <article class="ai-card">
                        <div class="ai-card-icon"><?= svg_icon('spark') ?></div>
                        <h3 data-i18n="landingAiCard2Title">Taslak Üretimi</h3>
                        <p data-i18n="landingAiCard2Text">Tarife göre kalite yönetim uyumlu taslak otomatik oluşur.</p>
                    </article>
                    <article class="ai-card">
                        <div class="ai-card-icon"><?= svg_icon('edit') ?></div>
                        <h3 data-i18n="landingAiCard3Title">Web Editörü</h3>
                        <p data-i18n="landingAiCard3Text">Taslağı çevrim içi editörde düzenleyin ve son halini verin.</p>
                    </article>
                    <article class="ai-card">
                        <div class="ai-card-icon"><?= svg_icon('file') ?></div>
                        <h3 data-i18n="landingAiCard4Title">Aktar & Kaydet</h3>
                        <p data-i18n="landingAiCard4Text">Dokümana aktarın ya da şablon olarak yeniden kullanın.</p>
                    </article>
                </div>
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
    <script>
    // Hero slider
    (function () {
        var slides = document.querySelectorAll('.landing-slide');
        if (!slides.length) return;
        var dots = [].slice.call(document.querySelectorAll('.slider-dot'));
        var idx = 0;
        var total = slides.length;
        var timer = null;

        function show(i) {
            idx = (i + total) % total;
            slides.forEach(function (s, j) { s.classList.toggle('is-active', j === idx); });
            if (dots.length) dots.forEach(function (d, j) { d.classList.toggle('is-active', j === idx); });
            restart();
        }
        function next() { show(idx + 1); }
        function prev() { show(idx - 1); }
        function restart() {
            if (timer) clearInterval(timer);
            timer = setInterval(next, 6000);
        }

        var n = document.querySelector('.slider-next');
        var p = document.querySelector('.slider-prev');
        if (n) n.addEventListener('click', next);
        if (p) p.addEventListener('click', prev);
        dots.forEach(function (d, j) { d.addEventListener('click', function () { show(j); }); });

        restart();
    })();
    </script>
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
        'mic'      => '<rect x="9" y="3" width="6" height="11" rx="3"/><path d="M5 11v1a7 7 0 0 0 14 0v-1"/><path d="M12 19v2"/>',
        'spark'    => '<path d="M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8z"/><path d="M19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8z"/>',
        'edit'     => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
        'file'     => '<path d="M7 3h7l5 5v13H7z"/><path d="M14 3v5h5"/><path d="M10 12h6M10 16h4"/>',
    ];
    $body = $paths[$name] ?? $paths['report'];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $body . '</svg>';
}
