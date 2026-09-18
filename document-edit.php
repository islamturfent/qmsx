<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['qms_logged_in'])) { header('Location: login.php'); exit; }
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/document-editor.php';
$id = (int) ($_GET['id'] ?? 0);
$userId = (int) ($_SESSION['qms_user_id'] ?? 0);
$super = ($_SESSION['qms_role'] ?? '') === 'super_admin';
$document = qmsEditorDocument($pdo, $id, $userId, $super);
if (!$document) { http_response_code(404); exit('Doküman bulunamadı.'); }
$_SESSION['document_csrf'] ??= bin2hex(random_bytes(32));
$latest = qmsEditorLatest($pdo, $id);
$version = $latest;
$readOnly = isset($_GET['version']) || !in_array($document['status'], ['draft', 'approved', 'published'], true);
if (isset($_GET['version'])) {
    $stmt = $pdo->prepare('SELECT * FROM document_versions WHERE id = ? AND document_id = ?');
    $stmt->execute([(int) $_GET['version'], $id]);
    $version = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$version) { http_response_code(404); exit('Revizyon bulunamadı.'); }
}
$html = '';
$error = '';
$isWebVersion = ($version['mime_type'] ?? '') === 'text/html';
if ($isWebVersion) {
    $path = __DIR__ . '/storage/documents/' . basename($version['stored_file_name']);
    if (!is_file($path)) { http_response_code(404); exit('Revizyon dosyası bulunamadı.'); }
    $html = qmsEditorHtml(file_get_contents($path));
}
$baseId = (int) ($latest['id'] ?? 0);
$revision = '';
$note = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $revision = trim((string) ($_POST['revision_number'] ?? ''));
    $note = trim((string) ($_POST['change_note'] ?? ''));
    $raw = (string) ($_POST['content'] ?? '');
    $baseId = (int) ($_POST['base_version'] ?? -1);
    if (strlen($raw) > 1024 * 1024) {
        $error = 'İçerik 1 MB sınırını aşıyor.';
    } else {
        $html = qmsEditorHtml($raw);
        if (!hash_equals($_SESSION['document_csrf'], (string) ($_POST['csrf'] ?? ''))) {
            http_response_code(403);
            $error = 'Oturum doğrulanamadı. Sayfayı yenileyin.';
        } elseif ($readOnly) {
            $error = 'Bu revizyon salt okunur.';
        } else {
            try {
                qmsSaveEditorRevision($pdo, $id, $userId, $super, $baseId, $revision, $html, $note);
                header('Location: document-detail.php?id=' . $id . '&revision=created'); exit;
            } catch (RuntimeException $e) { $error = $e->getMessage(); }
        }
    }
}
function editorEscape($value): string { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
$activeNav = 'documents';
?>
<!doctype html>
<html lang="tr"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>QMS Web Doküman Editörü</title><link rel="stylesheet" href="assets/css/style.css"><link rel="stylesheet" href="assets/css/document-editor.css"></head>
<body class="has-sidebar">
<?php require __DIR__ . '/includes/app-sidebar.php'; ?>
<header class="topbar"><div class="topbar-inner"><div class="page-title-block"><strong><?= editorEscape($document['document_code']) ?></strong><span><?= editorEscape($document['company_name']) ?></span></div><div class="topbar-actions"><button class="topbar-button" id="languageToggle" type="button">EN</button><button class="topbar-button" id="themeToggle" type="button" aria-label="Tema değiştir">🌙</button><a class="topbar-button" href="document-detail.php?id=<?= $id ?>" data-i18n="editorBack">Dokümana Dön</a></div></div></header>
<main class="page-container">
<section class="page-heading"><span class="section-kicker" data-i18n="webEditorTitle">Web Doküman Editörü</span><h1><?= editorEscape($document['title']) ?></h1><p><?= editorEscape($document['company_name']) ?> · Rev. <?= editorEscape($version['revision_number'] ?? $document['current_revision']) ?></p></section>
<?php if ($error !== ''): ?><div class="form-message error" role="alert"><?= editorEscape($error) ?></div><?php endif; ?>
<section class="form-panel">
<?php if ($readOnly): ?>
    <p data-i18n="editorReadOnly">Revizyon salt okunur olarak görüntüleniyor.</p>
    <?php if ($isWebVersion): ?><article class="document-editor-content"><?= $html ?></article><?php elseif ($version): ?><a class="secondary-button" href="document-download.php?id=<?= (int) $version['id'] ?>" data-i18n="downloadFileButton">İndir</a><?php endif; ?>
<?php else: ?>
    <p data-i18n="editorRevisionHelp">Her kayıt yeni bir taslak revizyon oluşturur. Önceki sürümler korunur; yayın için yeniden onay gerekir.</p>
    <?php if ($latest && !$isWebVersion): ?><p class="form-message" data-i18n="editorFileHelp">Mevcut PDF, Word veya Excel dosyası korunur. Web içeriğini aşağıda oluşturabilirsiniz; dosya içeriği otomatik aktarılmaz.</p><?php endif; ?>
    <form method="post" id="documentEditorForm" class="auditor-form">
        <input type="hidden" name="csrf" value="<?= editorEscape($_SESSION['document_csrf']) ?>">
        <input type="hidden" name="base_version" value="<?= $baseId ?>">
        <label for="editorSource" id="editorContentLabel" data-i18n="editorContent">Doküman içeriği</label>
        <textarea id="editorSource" name="content" rows="18"><?= editorEscape($html) ?></textarea>
        <div class="form-grid">
            <label class="form-field"><span data-i18n="revisionLabel">Revizyon</span><input name="revision_number" maxlength="30" value="<?= editorEscape($revision) ?>" required></label>
            <label class="form-field form-field-wide"><span data-i18n="changeNoteLabel">Revizyon Notu</span><textarea name="change_note" maxlength="10000" rows="3"><?= editorEscape($note) ?></textarea></label>
        </div>
        <div class="form-actions"><button class="primary-button" type="submit" data-i18n="editorSave">Yeni Revizyon Olarak Kaydet</button></div>
    </form>
<?php endif; ?>
</section></main>
<script src="assets/js/theme.js"></script><script src="assets/js/language.js"></script><script src="assets/js/sidebar.js"></script><script src="assets/vendor/tinymce/tinymce.min.js"></script><script src="assets/js/document-editor.js"></script>
</body></html>
