<?php
session_start();
if (empty($_SESSION['qms_logged_in'])) { header('Location: login.php'); exit; }
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/office/service.php';
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
$id = (int) ($_GET['id'] ?? 0);
$userId = (int) ($_SESSION['qms_user_id'] ?? 0);
try {
    $actor = qmsOfficeActor($pdo, $userId);
    $doc = qmsEditorDocument($pdo, $id, $userId, $actor['role'] === 'super_admin');
    if (!$doc) throw new RuntimeException();
} catch (Throwable $e) { http_response_code(404); exit('Doküman bulunamadı.'); }
$_SESSION['document_csrf'] ??= bin2hex(random_bytes(32));
$csrf = $_SESSION['document_csrf'];
session_write_close();
$latest = qmsEditorLatest($pdo, $id);
$versionId = (int) ($_GET['version'] ?? ($latest['id'] ?? 0));
$error = ''; $launch = null; $version = null; $enabled = false;
try {
    $config = qmsOfficeConfig();
    $enabled = $config['enabled'] && qmsOfficeSchemaReady($pdo);
    $version = qmsOfficeVersion($pdo, $id, $versionId);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        qmsOfficeCsrf($csrf, $_POST['csrf'] ?? null);
        if (!$enabled) throw new RuntimeException('Ofis entegrasyonu henüz yapılandırılmamış veya devre dışı.');
        $provider = qmsOfficeProvider($config);
        $discovery = $provider->discovery($config);
        $launch = qmsOfficeCreateSession($pdo, $config, $discovery, $id, $versionId, $userId,
            ($_POST['mode'] ?? '') === 'edit' && !isset($_GET['version']), $_SERVER['REMOTE_ADDR'] ?? '');
        $origin = qmsOfficeOrigin($config['public_url']);
        header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; frame-src " . $origin . "; form-action 'self' " . $origin . "; object-src 'none'; base-uri 'self'; frame-ancestors 'self'");
    }
} catch (Throwable $e) { $error = $e instanceof PDOException ? 'Ofis entegrasyonu şu anda kullanılamıyor.' : $e->getMessage(); }
function officeEscape($v): string { return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
$activeNav = 'documents';
?>
<!doctype html><html lang="tr"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>QMS Ofis Editörü</title><link rel="stylesheet" href="assets/css/style.css"><link rel="stylesheet" href="assets/css/office.css"></head><body class="has-sidebar">
<?php require __DIR__ . '/includes/app-sidebar.php'; ?>
<header class="topbar"><div class="topbar-inner"><div class="page-title-block"><strong><?= officeEscape($doc['document_code']) ?></strong><span><?= officeEscape($doc['company_name']) ?></span></div><div class="topbar-actions"><button class="topbar-button" id="languageToggle">EN</button><button class="topbar-button" id="themeToggle" aria-label="Tema değiştir">🌙</button></div></div></header>
<main class="page-container"><section class="page-heading page-heading-actions"><div><span class="section-kicker">Gömülü Ofis</span><h1><?= officeEscape($doc['title']) ?></h1><p><?= officeEscape($version['original_file_name'] ?? '') ?> · Rev. <?= officeEscape($version['revision_number'] ?? '') ?></p></div><a class="secondary-button" href="document-detail.php?id=<?= $id ?>" data-i18n="editorBack">Dokümana Dön</a></section>
<?php if ($error): ?><div class="form-message error" role="alert"><?= officeEscape($error) ?></div><?php endif; ?>
<?php if ($launch): ?>
<div class="form-message" id="officeStatus" role="status" data-expires="<?= $launch['expires'] ?>"><?= $launch['write'] ? 'Düzenleme oturumu açılıyor. Her farklı kayıt yeni taslak revizyon oluşturur; yayın için yeniden onay gerekir.' : 'Salt okunur oturum açılıyor. Bu sürüm veya biçim için düzenleme kapalı.' ?></div>
<p>Dosyayı ofis araç çubuğundan kaydedin ve kaydın tamamlandığını gördükten sonra dokümana dönün. Oturum süresi <?= (int) ($config['token_ttl'] / 60) ?> dakikadır.</p>
<form id="officeLaunch" method="post" action="<?= officeEscape($launch['url']) ?>" target="qmsOfficeFrame"><input type="hidden" name="access_token" value="<?= officeEscape($launch['token']) ?>"><input type="hidden" name="access_token_ttl" value="<?= $launch['expires'] * 1000 ?>"><noscript><button class="primary-button">Ofisi Aç</button></noscript></form>
<iframe id="qmsOfficeFrame" name="qmsOfficeFrame" title="Doküman ofis editörü" class="office-frame" referrerpolicy="no-referrer" allow="clipboard-read; clipboard-write; fullscreen" sandbox="allow-scripts allow-same-origin allow-forms allow-popups allow-modals allow-downloads"></iframe>
<?php else: ?>
<section class="form-panel">
<?php if (!$enabled): ?><h2>Ofis entegrasyonu devre dışı</h2><p>Collabora sunucusu yapılandırıldığında dosyayı burada açabilirsiniz. Mevcut web editörü kullanılabilir.</p><?php else: ?><p>Desteklenen dosyalar bu sayfada açılır. Eski sürümler ve incelemedeki/arşivlenmiş dokümanlar salt okunurdur. PDF düzenleme desteği sağlayıcının bildirdiği yeteneklere bağlıdır; CODE ile PDF salt okunur açılır.</p><?php endif; ?>
<?php if ($enabled && $version): ?><form method="post" class="form-actions"><input type="hidden" name="csrf" value="<?= officeEscape($csrf) ?>"><?php if (!isset($_GET['version']) && qmsOfficeWritable($pdo, $doc)): ?><button class="primary-button" name="mode" value="edit">Ofis Editöründe Aç</button><?php endif; ?><button class="secondary-button" name="mode" value="view">Salt Okunur Aç</button></form><?php endif; ?>
<div class="form-actions"><a class="secondary-button" href="document-edit.php?id=<?= $id ?>">Web Editörünü Kullan</a><?php if ($actor['role'] === 'super_admin'): ?><a class="secondary-button" href="office-settings.php">Ofis Yapılandırması</a><?php endif; ?></div>
</section>
<?php endif; ?></main><script src="assets/js/theme.js"></script><script src="assets/js/language.js"></script><script src="assets/js/sidebar.js"></script><script src="assets/js/office.js"></script></body></html>
