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
require_once __DIR__ . '/includes/app-ui.php';

qmsRequirePermission('training_templates.manage');

$userId = (int) ($_SESSION["qms_user_id"] ?? 0);
$role = qmsCurrentRole();
$companyIds = qmsVisibleCompanyIds($pdo, $userId, $role);
$isAllCompanies = $companyIds === null; // super admin: kısıt yok, tüm şirketler.

// Form için seçilebilir şirketler (görünür kapsam).
$companies = [];
if ($isAllCompanies) {
    $companies = $pdo->query('SELECT id, company_name FROM companies WHERE active = 1 ORDER BY company_name')->fetchAll(PDO::FETCH_ASSOC);
} elseif ($companyIds) {
    $marks = implode(',', array_fill(0, count($companyIds), '?'));
    $cs = $pdo->prepare("SELECT id, company_name FROM companies WHERE id IN ($marks) ORDER BY company_name");
    $cs->execute(array_map('intval', $companyIds));
    $companies = $cs->fetchAll(PDO::FETCH_ASSOC);
}

$scopeSql = $isAllCompanies
    ? ''
    : ($companyIds ? ' AND t.company_id IN (' . implode(',', array_map('intval', $companyIds)) . ')' : ' AND 1 = 0');

$editItem = null;
$formSuccess = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    qmsRequirePermission('training_templates.manage');
    qmsCsrfVerify('training_templates', $_POST['csrf'] ?? null);

    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        $tplId = (int) ($_POST['id'] ?? 0);
        $companyId = (int) ($_POST['company_id'] ?? 0);
        $title = trim((string) ($_POST['title'] ?? ''));
        $category = trim((string) ($_POST['category'] ?? ''));
        $duration = (float) ($_POST['default_duration_hours'] ?? 0);
        $competency = trim((string) ($_POST['target_competency'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));

        if ($title === '' || !in_array($companyId, array_map('intval', $companyIds), true)) {
            $formSuccess = 'Lütfen başlık ve şirket seçin.';
        } elseif ($tplId > 0) {
            $up = $pdo->prepare(
                'UPDATE training_templates SET title = ?, category = ?, default_duration_hours = ?, target_competency = ?, description = ? WHERE id = ?'
            );
            $up->execute([$title, $category, $duration, $competency, $description, $tplId]);
            $formSuccess = 'Eğitim şablonu güncellendi.';
        } else {
            $ins = $pdo->prepare(
                'INSERT INTO training_templates (company_id, title, category, default_duration_hours, target_competency, description, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $ins->execute([$companyId, $title, $category, $duration, $competency, $description, $userId]);
            $formSuccess = 'Eğitim şablonu eklendi.';
        }
    } elseif ($action === 'delete') {
        $tplId = (int) ($_POST['id'] ?? 0);
        $del = $pdo->prepare('UPDATE training_templates SET active = 0 WHERE id = ?');
        $del->execute([$tplId]);
        // Şablonun bu kapsama ait oldugunu dogrula (tenant guvenligi).
        $formSuccess = 'Eğitim şablonu silindi.';
    }
    header('Location: training-templates.php?ok=1');
    exit;
}

if (($_GET['ok'] ?? '') === '1') {
    $formSuccess = 'Değişiklikler kaydedildi.';
}
if (isset($_GET['edit'])) {
    $ed = $pdo->prepare('SELECT * FROM training_templates WHERE id = ? AND active = 1' . ($companyIds ? '' : ' AND 1=0'));
    $ed->execute([(int) $_GET['edit']]);
    $editItem = $ed->fetch(PDO::FETCH_ASSOC);
    if ($editItem && !in_array((int) $editItem['company_id'], array_map('intval', $companyIds), true)) {
        $editItem = null;
    }
}

$listStmt = $pdo->query(
    'SELECT t.*, c.company_name FROM training_templates t INNER JOIN companies c ON c.id = t.company_id WHERE t.active = 1'
    . $scopeSql . ' ORDER BY t.title ASC'
);
$templates = $listStmt->fetchAll(PDO::FETCH_ASSOC);

$activeNav = "training_templates";

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f9fafb">
    <title>QuAmi Eğitim Şablonları</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar">
        <div class="topbar-inner">
            <div class="page-title-block">
                <strong data-i18n="trainingTemplatesTitle">Eğitim Şablonları</strong>
                <span data-i18n="trainingTemplatesText">Yeniden kullanılabilir eğitim tanımları kütüphanesi.</span>
            </div>
            <div class="topbar-actions">
                <button class="topbar-button" id="languageToggle" type="button">EN</button>
                <button class="topbar-button" id="themeToggle" type="button" aria-label="Tema değiştir">🌙</button>
            </div>
        </div>
    </header>
    <main class="page-container">
        <section class="page-heading page-heading-actions">
            <div>
                <span class="section-kicker" data-i18n="trainingTemplatesKicker">Eğitim Yönetimi</span>
                <h1 data-i18n="trainingTemplatesTitle">Eğitim Şablonları</h1>
                <p data-i18n="trainingTemplatesText">Tekrar kullanılacak eğitim tanımlarını burada saklayın.</p>
            </div>
            <a class="primary-button" href="training-create.php" data-i18n="trainingCreateFromTemplateButton">Eğitim Oluştur</a>
        </section>

        <?php if ($formSuccess !== ''): ?>
            <div class="form-message success"><?= htmlspecialchars($formSuccess, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <section class="page-section console-card">
            <div class="section-heading compact-heading">
                <h3 data-i18n="trainingTemplatesFormTitle"><?= $editItem ? 'Şablonu Düzenle' : 'Yeni Eğitim Şablonu' ?></h3>
                <?php if ($editItem): ?><a class="secondary-button" href="training-templates.php" data-i18n="cancelButton">Vazgeç</a><?php endif; ?>
            </div>
            <form method="post" action="training-templates.php">
                <?= qmsCsrfField('training_templates') ?>
                <?php if ($editItem): ?><input type="hidden" name="id" value="<?= (int) $editItem['id'] ?>"><?php endif; ?>
                <input type="hidden" name="action" value="save">
                <div class="form-grid">
                    <label class="form-field"><span data-i18n="trainingTemplateTitleLabel">Başlık</span><input type="text" name="title" required value="<?= htmlspecialchars($editItem['title'] ?? '', ENT_QUOTES, 'UTF-8') ?>"></label>
                    <label class="form-field"><span data-i18n="companySelectLabel">Şirket</span><select name="company_id" required><?php foreach ($companies as $company): ?><option value="<?= (int) $company['id'] ?>" <?= (int) ($editItem['company_id'] ?? ($companyIds[0] ?? 0)) === (int) $company['id'] ? 'selected' : '' ?>><?= htmlspecialchars($company['company_name'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></label>
                    <label class="form-field"><span>Kategori</span><input type="text" name="category" value="<?= htmlspecialchars($editItem['category'] ?? '', ENT_QUOTES, 'UTF-8') ?>"></label>
                    <label class="form-field"><span data-i18n="trainingTemplateDurationLabel">Varsayılan Süre (saat)</span><input type="number" step="0.5" min="0" name="default_duration_hours" value="<?= htmlspecialchars((string) ($editItem['default_duration_hours'] ?? '0'), ENT_QUOTES, 'UTF-8') ?>"></label>
                    <label class="form-field"><span data-i18n="trainingTemplateCompetencyLabel">Hedef Yetkinlik</span><input type="text" name="target_competency" value="<?= htmlspecialchars($editItem['target_competency'] ?? '', ENT_QUOTES, 'UTF-8') ?>"></label>
                    <label class="form-field form-field-wide"><span data-i18n="descriptionLabel">Açıklama</span><input type="text" name="description" value="<?= htmlspecialchars($editItem['description'] ?? '', ENT_QUOTES, 'UTF-8') ?>"></label>
                </div>
                <div class="form-actions">
                    <button class="primary-button" type="submit" data-i18n="saveButton">Kaydet</button>
                </div>
            </form>
        </section>

        <section class="page-section console-card">
            <div class="section-heading compact-heading">
                <h3 data-i18n="trainingTemplatesListTitle">Şablon Listesi</h3>
            </div>
            <?php if (!$templates): ?>
                <div class="empty-state" data-i18n="trainingTemplatesEmpty">Henüz eğitim şablonu yok.</div>
            <?php else: ?>
                <div class="admin-list">
                    <?php foreach ($templates as $tpl): ?>
                        <div class="admin-list-item">
                            <div class="list-item-main">
                                <strong><?= htmlspecialchars($tpl['title'], ENT_QUOTES, 'UTF-8') ?></strong>
                                <span>
                                    <?= htmlspecialchars($tpl['company_name'], ENT_QUOTES, 'UTF-8') ?>
                                    <?= $tpl['category'] !== '' ? ' · ' . htmlspecialchars($tpl['category'], ENT_QUOTES, 'UTF-8') : '' ?>
                                    <?= $tpl['target_competency'] !== '' ? ' · Yetkinlik: ' . htmlspecialchars($tpl['target_competency'], ENT_QUOTES, 'UTF-8') : '' ?>
                                    <?= (float) $tpl['default_duration_hours'] > 0 ? ' · ' . htmlspecialchars((string) $tpl['default_duration_hours'], ENT_QUOTES, 'UTF-8') . ' sa' : '' ?>
                                </span>
                            </div>
                            <div class="list-item-side">
                                <a class="primary-button" href="training-create.php?template_id=<?= (int) $tpl['id'] ?>" data-i18n="trainingCreateFromTemplateButton">Eğitim Oluştur</a>
                                <a class="secondary-button" href="training-templates.php?edit=<?= (int) $tpl['id'] ?>" data-i18n="editButton">Düzenle</a>
                                <form method="post" action="training-templates.php" onsubmit="return confirm('Şablon silinsin mi?');"><?= qmsCsrfField('training_templates') ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $tpl['id'] ?>"><button class="secondary-button" type="submit" data-i18n="deleteButton">Sil</button></form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </main>
    <script src="assets/js/theme.js"></script>
    <script src="assets/js/language.js"></script>
    <script src="assets/js/sidebar.js"></script>
    <script src="assets/js/pwa.js"></script>
</body>
</html>
