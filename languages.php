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
    } elseif ($act === 'import') {
        $lang = strtolower(trim((string) ($_POST["lang"] ?? '')));
        $raw = '';
        if (!empty($_FILES['import_file']['tmp_name']) && is_uploaded_file($_FILES['import_file']['tmp_name'])) {
            $raw = (string) file_get_contents($_FILES['import_file']['tmp_name']);
        } else {
            $raw = trim((string) ($_POST["import_json"] ?? ''));
        }
        $data = json_decode($raw, true);
        $count = 0;
        if (is_array($data)) {
            foreach ($data as $k => $v) {
                if (is_string($k) && is_string($v)) { qmsTranslationSave($pdo, $lang, $k, $v); $count++; }
            }
            $message = "$count çeviri içe aktarıldı.";
        } else {
            $message = 'Geçersiz JSON.';
        }
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
$allKeys = qmsTranslationKeys();
$trRef = qmsTranslationRef();
$translatedCount = 0;
foreach ($allKeys as $ak) { if (array_key_exists($ak, $editTranslations) && $editTranslations[$ak] !== '') { $translatedCount++; } }
$totalKeys = count($allKeys);
$pct = $totalKeys > 0 ? (int) round($translatedCount / $totalKeys * 100) : 0;
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
                <div>
                    <h3 data-i18n="languagesTranslateTitle">Çeviriler · <?= htmlspecialchars($editLang, ENT_QUOTES, 'UTF-8') ?></h3>
                    <p><?= (int) $translatedCount ?> / <?= (int) $totalKeys ?> tamamlandı · %<?= (int) $pct ?></p>
                </div>
                <div class="report-export-actions">
                    <form method="post" action="languages.php" style="display:inline"><?= qmsCsrfField($csrfScope) ?><input type="hidden" name="action" value="export"><input type="hidden" name="lang" value="<?= htmlspecialchars($editLang, ENT_QUOTES, 'UTF-8') ?>"><button class="secondary-button" type="submit">Dışa Aktar</button></form>
                    <form method="post" action="languages.php" enctype="multipart/form-data" style="display:flex;align-items:center;gap:8px"><?= qmsCsrfField($csrfScope) ?><input type="hidden" name="action" value="import"><input type="hidden" name="lang" value="<?= htmlspecialchars($editLang, ENT_QUOTES, 'UTF-8') ?>"><input type="file" name="import_file" accept=".json,application/json" style="font-size:12px"><button class="secondary-button" type="submit">İçe Aktar (JSON)</button></form>
                </div>
            </div>
            <div style="height:10px;background:var(--surface-hover);border-radius:999px;overflow:hidden;margin:4px 0 12px"><div style="height:100%;width:<?= (int) $pct ?>%;background:#465fff;border-radius:999px"></div></div>
            <form method="post" action="languages.php">
                <?= qmsCsrfField($csrfScope) ?>
                <input type="hidden" name="action" value="translate"><input type="hidden" name="lang" value="<?= htmlspecialchars($editLang, ENT_QUOTES, 'UTF-8') ?>">
                <div class="report-table-wrap"><table class="report-table" style="table-layout:fixed">
                    <thead><tr><th style="width:34%">Anahtar</th><th style="width:22%">TR (referans)</th><th data-i18n="langValueLabel">Çeviri</th></tr></thead>
                    <tbody>
                        <?php foreach ($allKeys as $ak): ?>
                            <?php $has = array_key_exists($ak, $editTranslations) && $editTranslations[$ak] !== ''; ?>
                            <tr<?= $has ? '' : ' style="background:var(--surface-hover)"' ?>>
                                <td><code style="color:var(--text-body)"><?= htmlspecialchars($ak, ENT_QUOTES, 'UTF-8') ?></code></td>
                                <td class="muted-color"><?= htmlspecialchars((string) ($trRef[$ak] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                                <td style="display:block!important"><input type="hidden" name="tkey[]" value="<?= htmlspecialchars($ak, ENT_QUOTES, 'UTF-8') ?>"><input type="text" name="tval[]" value="<?= htmlspecialchars((string) ($editTranslations[$ak] ?? ''), ENT_QUOTES, 'UTF-8') ?>" style="width:100%;background:var(--surface-color);color:var(--text-body);border:1px solid var(--border-subtle);border-radius:6px;padding:7px 10px" placeholder="<?= htmlspecialchars('Çevir: ' . ($trRef[$ak] ?? $ak), ENT_QUOTES, 'UTF-8') ?>"></td>
                            </tr>
                        <?php endforeach; ?>
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
