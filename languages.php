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
require_once __DIR__ . '/includes/language-functions.php';

qmsRequirePermission('admin.system'); // super admin
$userId = (int) ($_SESSION["qms_user_id"] ?? 0);
$csrfScope = 'languages';
$message = '';

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    qmsCsrfVerify($csrfScope, $_POST["csrf"] ?? null);
    $act = (string) ($_POST["action"] ?? "");
    if ($act === 'add' && qmsLanguageUpsert($pdo, (string) ($_POST["code"] ?? ''), (string) ($_POST["name"] ?? ''), (string) ($_POST["native_name"] ?? ''))) {
        $message = 'Dil eklendi: ' . htmlspecialchars(trim((string) $_POST["name"]), ENT_QUOTES, 'UTF-8');
    } elseif ($act === 'translate') {
        $lang = strtolower(trim((string) ($_POST["lang"] ?? '')));
        $keys = (array) ($_POST["tkey"] ?? []);
        $vals = (array) ($_POST["tval"] ?? []);
        $count = 0;
        for ($i = 0; $i < count($keys); $i++) {
            $key = trim((string) ($keys[$i] ?? ''));
            if ($key !== '') {
                qmsTranslationSave($pdo, $lang, $key, (string) ($vals[$i] ?? ''));
                $count++;
            }
        }
        $message = "$count çeviri kaydedildi.";
    } elseif ($act === 'export') {
        $lang = strtolower(trim((string) ($_POST["lang"] ?? '')));
        $data = qmsLanguageTranslations($pdo, $lang);
        header('Content-Type: application/json; charset=UTF-8');
        header('Content-Disposition: attachment; filename="lang-' . $lang . '.json"');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
    }
}

$languages = qmsLanguages($pdo);
$editLang = strtolower(trim((string) ($_GET["edit"] ?? '')));
$editTranslations = $editLang !== '' ? qmsLanguageTranslations($pdo, $editLang) : [];
$activeNav = "languages";

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="theme-color" content="#f9fafb">
    <title>QuAmi Dil Yönetimi</title><link rel="manifest" href="manifest.webmanifest"><link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml"><link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar"><div class="topbar-inner"><div class="page-title-block"><strong data-i18n="languagesTitle">Dil Yönetimi</strong><span data-i18n="languagesText">Yeni diller ekleyin ve çevirileri yönetin.</span></div><div class="topbar-actions"><button class="topbar-button" id="languageToggle" type="button">EN</button><button class="topbar-button" id="themeToggle" type="button" aria-label="Tema değiştir">🌙</button></div></div></header>
    <main class="page-container">
        <section class="page-heading"><span class="section-kicker" data-i18n="systemSettingsKicker">Sistem Yönetimi</span><h1 data-i18n="languagesTitle">Dil Yönetimi</h1><p data-i18n="languagesText">Yeni diller ekleyin; çevirileri anahtar bazlı girin.</p></section>

        <?php if ($message !== ""): ?><div class="form-message success"><?= $message ?></div><?php endif; ?>

        <section class="page-section console-card">
            <div class="section-heading compact-heading"><div><h3 data-i18n="languagesAddTitle">Yeni Dil Ekle</h3></div></div>
            <form method="post" action="languages.php" class="auditor-form">
                <?= qmsCsrfField($csrfScope) ?>
                <input type="hidden" name="action" value="add">
                <div class="form-grid">
                    <label class="form-field"><span data-i18n="langCodeLabel">Dil Kodu (örn. de)</span><input type="text" name="code" maxlength="8" required placeholder="de"></label>
                    <label class="form-field"><span data-i18n="langNameLabel">Dil Adı (İngilizce)</span><input type="text" name="name" maxlength="80" required placeholder="German"></label>
                    <label class="form-field"><span data-i18n="langNativeLabel">Yerel Ad</span><input type="text" name="native_name" maxlength="80" placeholder="Deutsch"></label>
                </div>
                <div class="form-actions"><button class="primary-button" type="submit" data-i18n="languagesAddButton">Dil Ekle</button></div>
            </form>
        </section>

        <section class="page-section console-card">
            <div class="section-heading compact-heading"><div><h3 data-i18n="languagesListTitle">Diller</h3></div></div>
            <?php if (!$languages): ?><div class="empty-state">Hiç dil yok.</div>
            <?php else: ?>
                <div class="admin-list">
                    <?php foreach ($languages as $lang): ?>
                        <div class="admin-list-item">
                            <div class="list-item-main"><strong><?= htmlspecialchars($lang['native_name'], ENT_QUOTES, 'UTF-8') ?></strong><span><?= htmlspecialchars($lang['code'] . ' · ' . $lang['name'], ENT_QUOTES, 'UTF-8') ?></span></div>
                            <div class="list-item-side"><a class="secondary-button" href="languages.php?edit=<?= htmlspecialchars($lang['code'], ENT_QUOTES, 'UTF-8') ?>">Çevirileri Düzenle</a></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <?php if ($editLang !== ''): ?>
        <section class="page-section console-card">
            <div class="section-heading compact-heading">
                <div><h3 data-i18n="languagesTranslateTitle">Çeviriler · <?= htmlspecialchars($editLang, ENT_QUOTES, 'UTF-8') ?></h3></div>
                <form method="post" action="languages.php" style="display:inline">
                    <?= qmsCsrfField($csrfScope) ?>
                    <input type="hidden" name="action" value="export"><input type="hidden" name="lang" value="<?= htmlspecialchars($editLang, ENT_QUOTES, 'UTF-8') ?>">
                    <button class="secondary-button" type="submit">Dışa Aktar (JSON)</button>
                </form>
            </div>
            <form method="post" action="languages.php">
                <?= qmsCsrfField($csrfScope) ?>
                <input type="hidden" name="action" value="translate"><input type="hidden" name="lang" value="<?= htmlspecialchars($editLang, ENT_QUOTES, 'UTF-8') ?>">
                <div class="report-table-wrap"><table class="report-table">
                    <thead><tr><th data-i18n="langKeyLabel">Anahtar</th><th data-i18n="langValueLabel">Çeviri</th></tr></thead>
                    <tbody>
                        <?php if (!$editTranslations): ?><tr><td colspan="2" class="muted-color">Henüz çeviri yok — aşağıya anahtar + çeviri ekleyin.</td></tr><?php endif; ?>
                        <?php foreach ($editTranslations as $k => $v): ?>
                            <tr><td><input type="text" name="tkey[]" value="<?= htmlspecialchars((string) $k, ENT_QUOTES, 'UTF-8') ?>" readonly></td><td><input type="text" name="tval[]" value="<?= htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8') ?>"></td></tr>
                        <?php endforeach; ?>
                        <tr><td><input type="text" name="tkey[]" placeholder="ornek: saveButton"></td><td><input type="text" name="tval[]" placeholder="çeviri"></td></tr>
                        <tr><td><input type="text" name="tkey[]" placeholder="ornek: dashboardLinkLabel"></td><td><input type="text" name="tval[]" placeholder="çeviri"></td></tr>
                    </tbody>
                </table></div>
                <div class="form-actions"><button class="primary-button" type="submit">Çevirileri Kaydet</button></div>
            </form>
        </section>
        <?php endif; ?>
    </main>
    <script src="assets/js/theme.js"></script><script src="assets/js/language.js"></script><script src="assets/js/sidebar.js"></script><script src="assets/js/pwa.js"></script>
</body>
</html>
