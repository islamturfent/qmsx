<?php
session_start();
if (empty($_SESSION['qms_logged_in'])) { header('Location: login.php'); exit; }
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/office/service.php';
try { $actor = qmsOfficeActor($pdo, (int) ($_SESSION['qms_user_id'] ?? 0)); }
catch (Throwable $e) { http_response_code(403); exit('Erişim yetkiniz yok.'); }
if ($actor['role'] !== 'super_admin') { http_response_code(403); exit('Yalnız süper admin erişebilir.'); }
header('Cache-Control: no-store, private');
$_SESSION['document_csrf'] ??= bin2hex(random_bytes(32));
$config = qmsOfficeConfig(); $error = ''; $message = ''; $health = null;
$schemaReady = qmsOfficeSchemaReady($pdo);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        qmsOfficeCsrf($_SESSION['document_csrf'], $_POST['csrf'] ?? null);
        if (!$schemaReady) throw new RuntimeException('Önce scripts/migrate-office.php komutunu çalıştırın.');
        if (($_POST['action'] ?? '') === 'save') {
            $next = qmsOfficeDefaults();
            foreach ($next as $key => $default) {
                if (getenv('QMS_OFFICE_' . strtoupper($key)) !== false) { $next[$key] = $config[$key]; continue; }
                $next[$key] = is_bool($default) ? isset($_POST[$key]) : (is_int($default) ? (int) ($_POST[$key] ?? $default) : trim((string) ($_POST[$key] ?? $default)));
            }
            qmsOfficeValidateConfig($next);
            qmsOfficeAudit($pdo, 'config_change', 'requested', ['user_id' => $actor['id']], $_SERVER['REMOTE_ADDR'] ?? '', 'provider=' . $next['provider'] . '; enabled=' . (int) $next['enabled']);
            $target = __DIR__ . '/storage/office/settings.json';
            $tmp = $target . '.' . bin2hex(random_bytes(6)) . '.tmp';
            if (file_put_contents($tmp, json_encode($next, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX) === false || !rename($tmp, $target)) {
                if (is_file($tmp)) unlink($tmp);
                throw new RuntimeException('Ayarlar yazılamadı. storage/office izinlerini kontrol edin.');
            }
            $config = qmsOfficeConfig();
            $message = 'Ayarlar kaydedildi. Değişen yapılandırmaya ait eski ofis oturumları artık kullanılamaz.';
            qmsOfficeAudit($pdo, 'config_change', 'ok', ['user_id' => $actor['id']], $_SERVER['REMOTE_ADDR'] ?? '');
        } elseif (($_POST['action'] ?? '') === 'health') {
            $health = qmsOfficeProvider($config)->discovery($config, true);
            $message = 'Discovery bağlantısı başarılı. Bu kontrol, ofis sunucusundan QuAmi’ye dönüş bağlantısını veya gerçek dosya kaydını henüz doğrulamaz.';
            qmsOfficeAudit($pdo, 'health_check', 'ok', ['user_id' => $actor['id']], $_SERVER['REMOTE_ADDR'] ?? '');
        }
    } catch (Throwable $e) { $error = $e instanceof PDOException ? 'Ofis ayarları işlenemedi.' : $e->getMessage(); }
}
function officeSettingsEscape($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
$labels = ['public_url' => 'Ofis sunucusu — tarayıcı adresi', 'discovery_url' => 'Discovery XML adresi', 'qms_url' => 'Ofis sunucusunun erişebildiği QuAmi adresi', 'host_url' => 'QuAmi — tarayıcı adresi',
    'callback_ips' => 'İzin verilen callback IP adresleri', 'token_ttl' => 'Oturum süresi (saniye)', 'max_bytes' => 'Dosya boyut sınırı (bayt)'];
$activeNav = 'office_settings';
?>
<!doctype html><html lang="tr"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>QuAmi Ofis Entegrasyonu</title><link rel="stylesheet" href="assets/css/style.css"></head><body class="has-sidebar">
<?php require __DIR__ . '/includes/app-sidebar.php'; ?>
<header class="topbar"><div class="topbar-inner"><div class="page-title-block"><strong>Ofis Entegrasyonu</strong><span>Sunucu yapılandırması ve bağlantı kontrolü</span></div><div class="topbar-actions"><button class="topbar-button" id="languageToggle">EN</button><button class="topbar-button" id="themeToggle" aria-label="Tema değiştir">🌙</button></div></div></header>
<main class="page-container narrow-page"><section class="page-heading"><span class="section-kicker">Sistem Yönetimi</span><h1>Ofis Entegrasyonu</h1><p><?= $config['enabled'] ? 'Etkin — bağlantı testi ile sunucu durumunu kontrol edin.' : 'Devre dışı — mevcut web editörü kullanılabilir.' ?></p></section>
<?php if ($error): ?><div class="form-message error" role="alert"><?= officeSettingsEscape($error) ?></div><?php endif; ?>
<?php if ($message): ?><div class="form-message success" role="status"><?= officeSettingsEscape($message) ?></div><?php endif; ?>
<?php if (!$schemaReady): ?><div class="form-message error">Veritabanı kurulumu gerekli: <code>php scripts/migrate-office.php</code></div><?php endif; ?>
<section class="form-panel"><p>İlk geliştirme ve doğrulama için ücretsiz Collabora CODE kullanılır. Ticari SaaS üretiminde destekli Collabora veya uygun ticari sağlayıcıya geçilir. <a href="docs/OFFICE-INTEGRATION.md">Mimari karar ve kurulum rehberi</a></p>
<form method="post" class="auditor-form"><input type="hidden" name="csrf" value="<?= officeSettingsEscape($_SESSION['document_csrf']) ?>"><input type="hidden" name="action" value="save">
<div class="form-grid"><label class="form-field"><span>Sağlayıcı</span><select name="provider" <?= getenv('QMS_OFFICE_PROVIDER') !== false ? 'disabled' : '' ?>><option value="collabora" <?= $config['provider'] === 'collabora' ? 'selected' : '' ?>>Collabora CODE / destekli Collabora</option><option value="onlyoffice-wopi" <?= $config['provider'] === 'onlyoffice-wopi' ? 'selected' : '' ?>>ONLYOFFICE (WOPI)</option></select></label>
<?php foreach ($labels as $key => $label): ?><label class="form-field"><span><?= $label ?></span><input type="<?= is_int($config[$key]) ? 'number' : 'text' ?>" name="<?= $key ?>" value="<?= officeSettingsEscape($config[$key]) ?>" <?= getenv('QMS_OFFICE_' . strtoupper($key)) !== false ? 'readonly' : '' ?>><small><?= getenv('QMS_OFFICE_' . strtoupper($key)) !== false ? 'Ortam değişkeni tarafından yönetiliyor.' : '' ?></small></label><?php endforeach; ?>
<?php foreach (['enabled' => 'Ofis entegrasyonunu etkinleştir', 'require_proof' => 'WOPI imza doğrulamasını zorunlu tut', 'allow_http' => 'Yalnız yerel deneme için HTTP / imzasız callback seçeneğine izin ver'] as $key => $label): ?><label class="form-field form-field-wide"><span><input type="checkbox" name="<?= $key ?>" <?= $config[$key] ? 'checked' : '' ?> <?= getenv('QMS_OFFICE_' . strtoupper($key)) !== false ? 'disabled' : '' ?>> <?= $label ?></span></label><?php endforeach; ?>
</div><p>Ayar değişiklikleri açık ofis oturumlarını geçersiz kılar. Kaydetmeden önce açık düzenlemeleri tamamlayın. IP listesinde joker karakter veya istemciden gelen proxy başlıkları kullanılmaz.</p><div class="form-actions"><button class="primary-button" type="submit">Ayarları Kaydet</button></div></form>
<form method="post" class="form-actions"><input type="hidden" name="csrf" value="<?= officeSettingsEscape($_SESSION['document_csrf']) ?>"><button class="secondary-button" name="action" value="health">Kaydedilmiş Ayarlarla Bağlantıyı Test Et</button></form>
<?php if ($health): ?><ul><?php foreach (['doc', 'docx', 'xls', 'xlsx', 'pdf'] as $ext): ?><li><?= strtoupper($ext) ?>: <?= isset($health['actions'][$ext]['edit']) ? 'Düzenleme' : (isset($health['actions'][$ext]) ? 'Görüntüleme' : 'Desteklenmiyor') ?></li><?php endforeach; ?></ul><?php endif; ?>
</section></main><script src="assets/js/theme.js"></script><script src="assets/js/language.js"></script><script src="assets/js/sidebar.js"></script></body></html>
