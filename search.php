<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/access.php';
require_once __DIR__ . '/includes/search-functions.php';
require_once __DIR__ . '/includes/app-ui.php';

$userId = (int) ($_SESSION["qms_user_id"] ?? 0);
$role = qmsCurrentRole();

$query = trim((string) ($_GET["q"] ?? ""));
$type = (string) ($_GET["type"] ?? "");
if ($type !== "" && !isset(qmsSearchTypes()[$type])) {
    $type = "";
}

$searchTypes = qmsSearchTypes();
$keywords = qmsSearchKeywords($query);
$results = $query !== "" ? qmsSearchRecords($pdo, $userId, $role, $query, $type !== "" ? $type : null) : [];

// Benzer vakalar: en ilgili sonucun anahtar kelimelerini paylasan diger kayitlar.
$similar = [];
if ($results !== [] && $query !== "") {
    $top = $results[0];
    $similar = qmsSimilarCases($pdo, $userId, $role, $query, $top["type"], (int) $top["id"]);
}

/** Basit snippet: anahtar kelime cevresinden ozet cikarir. */
function qmsSearchSnippet(array $row, array $keywords): string
{
    $body = (string) ($row["body"] ?? "");
    if (trim($body) === "") {
        return mb_substr((string) $row["title"], 0, 100);
    }
    $lower = mb_strtolower($body);
    foreach ($keywords as $keyword) {
        $pos = mb_strpos($lower, $keyword);
        if ($pos !== false) {
            $start = max(0, $pos - 40);
            return "…" . mb_substr($body, $start, 130) . "…";
        }
    }
    return mb_substr($body, 0, 130);
}

$activeNav = "search";

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f9fafb">
    <title>QuAmi Arama</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar">
        <div class="topbar-inner">
            <div class="page-title-block">
                <strong data-i18n="searchTitle">Arama</strong>
                <span data-i18n="searchText">Kayıtlar arasında arayın ve benzer vakaları bulun.</span>
            </div>
            <div class="topbar-actions">
                <button class="topbar-button" id="languageToggle" type="button">EN</button>
                <button class="topbar-button" id="themeToggle" type="button" aria-label="Tema değiştir">🌙</button>
            </div>
        </div>
    </header>
    <main class="page-container narrow-page">
        <section class="page-heading">
            <span class="section-kicker" data-i18n="searchKicker">Kayıt Arama</span>
            <h1 data-i18n="searchTitle">Arama</h1>
            <p data-i18n="searchText">Uygunsuzluk, şikayet, risk, faaliyet, doküman, tedarikçi, eğitim ve ekipman kayıtlarını tek ekrandan arayın.</p>
        </section>

        <section class="page-section form-panel">
            <form class="auditor-form search-form" method="get" action="search.php">
                <div class="form-grid search-grid">
                    <label class="form-field form-field-wide">
                        <span data-i18n="searchQueryLabel">Arama</span>
                        <input type="text" name="q" value="<?= htmlspecialchars($query, ENT_QUOTES, "UTF-8") ?>" placeholder="Örn. tartı, kalibrasyon, kritik uygunsuzluk" autofocus>
                    </label>
                    <label class="form-field">
                        <span data-i18n="searchTypeLabel">Kayıt Türü</span>
                        <select name="type">
                            <option value="" data-i18n="allTypesOption">Tümü</option>
                            <?php foreach ($searchTypes as $value => $def): ?>
                                <option value="<?= $value ?>" <?= $type === $value ? "selected" : "" ?>><?= htmlspecialchars($def["label"], ENT_QUOTES, "UTF-8") ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>
                <div class="form-actions">
                    <button class="primary-button" type="submit" data-i18n="searchButton">Ara</button>
                </div>
            </form>
        </section>

        <?php if ($query !== "" && $keywords === []): ?>
            <div class="form-message error" data-i18n="searchShortQueryText">En az 3 karakterlik bir arama terimi girin.</div>
        <?php elseif ($query !== ""): ?>
            <section class="page-section console-card">
                <div class="section-heading compact-heading">
                    <div>
                        <h3 data-i18n="searchResultsLabel">Sonuçlar</h3>
                        <p><span data-i18n="filteredRecordsLabel">Gösterilen kayıt</span>: <strong><?= count($results) ?></strong></p>
                    </div>
                </div>
                <?php if (!$results): ?>
                    <div class="empty-state" data-i18n="searchNoResultsText">Bu aramayla eşleşen kayıt bulunamadı.</div>
                <?php else: ?>
                <div class="admin-list">
                    <?php foreach ($results as $row): ?>
                        <?php $def = $searchTypes[$row["type"]]; ?>
                        <a class="admin-list-item list-link" href="<?= htmlspecialchars($def["link"] . $row["id"], ENT_QUOTES, "UTF-8") ?>">
                            <div class="list-item-main">
                                <div class="search-result-head">
                                    <?= appIcon($def["icon"], "search-result-icon") ?>
                                    <strong><?= htmlspecialchars($row["title"], ENT_QUOTES, "UTF-8") ?></strong>
                                </div>
                                <span class="search-snippet"><?= htmlspecialchars(qmsSearchSnippet($row, $keywords), ENT_QUOTES, "UTF-8") ?></span>
                            </div>
                            <div class="list-item-side">
                                <span class="status-pill"><?= htmlspecialchars($def["label"], ENT_QUOTES, "UTF-8") ?></span>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </section>

            <?php if ($similar): ?>
            <section class="page-section console-card">
                <div class="section-heading compact-heading">
                    <div>
                        <h3 data-i18n="similarCasesTitle">Benzer Vakalar</h3>
                        <p data-i18n="similarCasesText">En ilgili sonuçla anahtar kelimeleri paylaşan diğer kayıtlar.</p>
                    </div>
                </div>
                <div class="admin-list">
                    <?php foreach ($similar as $row): ?>
                        <?php $def = $searchTypes[$row["type"]]; ?>
                        <a class="admin-list-item list-link" href="<?= htmlspecialchars($def["link"] . $row["id"], ENT_QUOTES, "UTF-8") ?>">
                            <div class="list-item-main">
                                <div class="search-result-head">
                                    <?= appIcon($def["icon"], "search-result-icon") ?>
                                    <strong><?= htmlspecialchars($row["title"], ENT_QUOTES, "UTF-8") ?></strong>
                                </div>
                                <span class="search-snippet"><?= htmlspecialchars(qmsSearchSnippet($row, $keywords), ENT_QUOTES, "UTF-8") ?></span>
                            </div>
                            <div class="list-item-side">
                                <span class="status-pill"><?= htmlspecialchars($def["label"], ENT_QUOTES, "UTF-8") ?></span>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
            <?php endif; ?>
        <?php endif; ?>
    </main>
    <script src="assets/js/theme.js"></script>
    <script src="assets/js/language.js"></script>
    <script src="assets/js/sidebar.js"></script>
    <script src="assets/js/pwa.js"></script>
</body>
</html>
