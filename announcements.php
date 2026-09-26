<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/access.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/announcement-functions.php';

$userId = (int) ($_SESSION["qms_user_id"] ?? 0);
$role = qmsCurrentRole();
if (qmsIsAuditor()) {
    header("Location: my-audits.php");
    exit;
}

$scope = qmsCompanyScope('companies.id', qmsVisibleCompanyIds($pdo, $userId, $role));
$companyStmt = $pdo->prepare('SELECT companies.id, companies.company_name FROM companies WHERE companies.active = 1' . $scope['sql'] . ' ORDER BY companies.company_name');
$companyStmt->execute($scope['params']);
$companies = $companyStmt->fetchAll(PDO::FETCH_ASSOC);

$csrfScope = 'announcements';
$formError = '';
$editing = null;
$editId = (int) ($_GET['edit'] ?? 0);
if ($editId > 0) {
    $editing = qmsAnnouncementFind($pdo, $editId, $userId, $role);
    if (!$editing) {
        $editId = 0;
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    qmsCsrfVerify($csrfScope, $_POST["csrf"] ?? null);
    $formType = (string) ($_POST["form_type"] ?? "");

    if ($formType === "add" || $formType === "update") {
        $companyId = (int) ($_POST["company_id"] ?? 0);
        if ($formType === "add") {
            $newId = qmsAnnouncementAdd($pdo, [
                "company_id" => $companyId,
                "title" => (string) ($_POST["title"] ?? ""),
                "body" => (string) ($_POST["body"] ?? ""),
                "published" => isset($_POST["published"]),
            ], $userId, $role);
            if ($newId !== null) {
                if (isset($_POST["published"])) {
                    qmsAnnouncementNotifyCompany($pdo, $companyId, $newId, (string) ($_POST["title"] ?? ""));
                }
                header("Location: announcements.php?added=1");
                exit;
            }
            $formError = "Duyuru eklenemedi. Geçerli bir şirket ve başlık girin.";
        } else {
            $ok = qmsAnnouncementUpdate($pdo, (int) ($_POST["id"] ?? 0), [
                "title" => (string) ($_POST["title"] ?? ""),
                "body" => (string) ($_POST["body"] ?? ""),
                "published" => isset($_POST["published"]),
            ], $userId, $role);
            if ($ok) {
                header("Location: announcements.php?updated=1");
                exit;
            }
            $formError = "Duyuru güncellenemedi.";
        }
    } elseif ($formType === "delete") {
        qmsAnnouncementDelete($pdo, (int) ($_POST["id"] ?? 0), $userId, $role);
        header("Location: announcements.php?deleted=1");
        exit;
    } elseif ($formType === "publish") {
        $targetId = (int) ($_POST["id"] ?? 0);
        $target = qmsAnnouncementFind($pdo, $targetId, $userId, $role);
        if ($target) {
            $wasPublished = (int) $target["published"] === 1;
            qmsAnnouncementUpdate($pdo, $targetId, [
                "title" => (string) $target["title"],
                "body" => (string) $target["body"],
                "published" => !$wasPublished,
            ], $userId, $role);
            if (!$wasPublished) {
                qmsAnnouncementNotifyCompany($pdo, (int) $target["company_id"], $targetId, (string) $target["title"]);
            }
        }
        header("Location: announcements.php");
        exit;
    }
}

$announcements = qmsAnnouncementList($pdo, $userId, $role);
$activeNav = "announcements";
$selectedCompanyId = (int) ($_GET["company_id"] ?? 0);
$prefill = $editing ?: ['title' => '', 'body' => '', 'published' => true];
if ($editing) {
    $selectedCompanyId = (int) $editing['company_id'];
}

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="theme-color" content="#f9fafb">
    <title>QMS Duyuru Merkezi</title><link rel="manifest" href="manifest.webmanifest"><link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml"><link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar"><div class="topbar-inner"><div class="page-title-block"><strong data-i18n="announcementsTitle">Duyuru Merkezi</strong><span data-i18n="announcementsText">Şirket içi duyuruları yayınlayın ve yönetin.</span></div><div class="topbar-actions"><button class="topbar-button" id="languageToggle" type="button">EN</button><button class="topbar-button" id="themeToggle" type="button" aria-label="Tema değiştir">🌙</button></div></div></header>
    <main class="page-container">
        <section class="page-heading">
            <div>
                <span class="section-kicker" data-i18n="announcementsKicker">İletişim</span>
                <h1 data-i18n="announcementsTitle">Duyuru Merkezi</h1>
                <p data-i18n="announcementsText">Önemli duyuruları tek yerden yayınlayın, taslak olarak saklayın veya anında kullanıcılara gösterin.</p>
            </div>
        </section>

        <?php if ($formError !== ""): ?><div class="form-message error"><?= htmlspecialchars($formError, ENT_QUOTES, "UTF-8") ?></div><?php endif; ?>
        <?php if (($_GET["added"] ?? "") === "1"): ?><div class="form-message success" data-i18n="announcementAdded">Duyuru eklendi.</div><?php endif; ?>
        <?php if (($_GET["updated"] ?? "") === "1"): ?><div class="form-message success" data-i18n="announcementUpdated">Duyuru güncellendi.</div><?php endif; ?>
        <?php if (($_GET["deleted"] ?? "") === "1"): ?><div class="form-message success" data-i18n="announcementDeleted">Duyuru silindi.</div><?php endif; ?>

        <div class="two-col">
            <section class="form-panel">
                <div class="section-heading compact-heading"><div><h3><?= $editing ? 'Duyuruyu Düzenle' : 'Yeni Duyuru' ?></h3><?php if ($editing): ?><p>#<?= (int) $editing['id'] ?> düzenleniyor</p><?php endif; ?></div></div>
                <?php if (!$companies): ?><div class="form-message error" data-i18n="announcementNoCompany">Önce bir şirket gerekir.</div>
                <?php else: ?>
                <form class="auditor-form" method="post" action="announcements.php<?= $editing ? '?edit=' . (int) $editing['id'] : '' ?>">
                    <?= qmsCsrfField($csrfScope) ?>
                    <input type="hidden" name="form_type" value="<?= $editing ? 'update' : 'add' ?>">
                    <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>
                    <div class="form-grid">
                        <label class="form-field"><span data-i18n="companySelectLabel">Şirket</span><select name="company_id" <?= $editing ? 'disabled' : 'required' ?>><option value="0" data-i18n="selectCompanyOption">Şirket seçin</option><?php foreach ($companies as $company): ?><option value="<?= (int) $company['id'] ?>" <?= $selectedCompanyId === (int) $company['id'] ? 'selected' : '' ?>><?= htmlspecialchars($company['company_name'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></label>
                        <label class="form-field"><span data-i18n="announcementTitleLabel">Başlık</span><input type="text" name="title" required maxlength="180" value="<?= htmlspecialchars((string) $prefill['title'], ENT_QUOTES, 'UTF-8') ?>"></label>
                        <label class="form-field form-field-wide"><span data-i18n="announcementBodyLabel">İçerik</span><textarea name="body" rows="6"><?= htmlspecialchars((string) ($prefill['body'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea></label>
                        <label class="form-field form-field-wide checkbox-field"><input type="checkbox" name="published" value="1" <?= $prefill['published'] ? 'checked' : '' ?>> <span data-i18n="announcementPublishLabel">Yayında (kullanıcılar görebilir)</span></label>
                    </div>
                    <div class="form-actions"><button class="primary-button" type="submit" data-i18n="announcementSave">Kaydet</button><?php if ($editing): ?><a class="secondary-button" href="announcements.php" data-i18n="announcementCancel">İptal</a><?php endif; ?></div>
                </form>
                <?php endif; ?>
            </section>

            <section class="console-card checklist-section">
                <div class="section-heading compact-heading"><div><h3 data-i18n="announcementListTitle">Duyurular</h3><p><span data-i18n="filteredRecordsLabel">Gösterilen kayıt</span>: <strong><?= count($announcements) ?></strong></p></div></div>
                <div class="admin-list">
                    <?php if (!$announcements): ?><div class="empty-state" data-i18n="announcementEmpty">Henüz duyuru yok.</div><?php endif; ?>
                    <?php foreach ($announcements as $ann): ?>
                        <div class="admin-list-item">
                            <div class="list-item-main">
                                <strong><?= htmlspecialchars($ann['title'], ENT_QUOTES, 'UTF-8') ?></strong>
                                <span>
                                    <?php if ((int) $ann['published'] === 1): ?><span class="status-badge status-pill" data-i18n="announcementPublishedBadge">Yayında</span><?php else: ?><span class="status-badge" data-i18n="announcementDraftBadge">Taslak</span><?php endif; ?>
                                    <?php if ($ann['creator_name']): ?> · <?= htmlspecialchars($ann['creator_name'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?>
                                    · <?= htmlspecialchars((string) $ann['created_at'], ENT_QUOTES, 'UTF-8') ?>
                                </span>
                                <?php if ($ann['body']): ?><p><?= htmlspecialchars(mb_substr((string) $ann['body'], 0, 180), ENT_QUOTES, 'UTF-8') ?><?= mb_strlen((string) $ann['body']) > 180 ? '…' : '' ?></p><?php endif; ?>
                            </div>
                            <div class="list-item-side">
                                <form method="post" action="announcements.php"><?= qmsCsrfField($csrfScope) ?><input type="hidden" name="form_type" value="publish"><input type="hidden" name="id" value="<?= (int) $ann['id'] ?>"><button class="secondary-button secondary-button-sm" type="submit"><?= (int) $ann['published'] === 1 ? 'Yayından Kaldır' : 'Yayınla' ?></button></form>
                                <a class="secondary-button secondary-button-sm" href="announcements.php?edit=<?= (int) $ann['id'] ?>" data-i18n="editButton">Düzenle</a>
                                <form method="post" action="announcements.php" onsubmit="return confirm('Duyuru silinsin mi?');"><?= qmsCsrfField($csrfScope) ?><input type="hidden" name="form_type" value="delete"><input type="hidden" name="id" value="<?= (int) $ann['id'] ?>"><button class="danger-button danger-button-sm" type="submit" data-i18n="announcementDelete">Sil</button></form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>
    </main>
    <script src="assets/js/theme.js"></script><script src="assets/js/language.js"></script><script src="assets/js/sidebar.js"></script><script src="assets/js/pwa.js"></script>
</body>
</html>
