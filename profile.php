<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/app-ui.php';
require_once __DIR__ . '/includes/access.php';

$userId = (int) ($_SESSION["qms_user_id"] ?? 0);
$isSuperAdmin = ($_SESSION["qms_role"] ?? "") === "super_admin";
$csrfToken = qmsCsrfToken('profile');

$formError = "";

$allowedAvatarFiles = [
    "jpg" => ["image/jpeg"],
    "jpeg" => ["image/jpeg"],
    "png" => ["image/png"],
    "webp" => ["image/webp"]
];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    qmsCsrfVerify('profile', $_POST["csrf"] ?? null);

    $formType = $_POST["form_type"] ?? "update_personal";

    if ($formType === "update_personal") {
        $firstName = trim($_POST["first_name"] ?? "");
        $lastName = trim($_POST["last_name"] ?? "");
        $email = trim($_POST["email"] ?? "");
        $phone = trim($_POST["phone"] ?? "");
        $positionTitle = trim($_POST["position_title"] ?? "");
        $bio = trim($_POST["bio"] ?? "");
        $facebook = trim($_POST["social_facebook"] ?? "");
        $xLink = trim($_POST["social_x"] ?? "");
        $linkedin = trim($_POST["social_linkedin"] ?? "");
        $instagram = trim($_POST["social_instagram"] ?? "");
        $upload = $_FILES["avatar_file"] ?? null;
        $newAvatar = null;

        if ($firstName === "") {
            $formError = "Ad alanı zorunludur.";
        } elseif ($email !== "" && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $formError = "Lütfen geçerli bir e-posta adresi girin.";
        } elseif ($upload && $upload["error"] !== UPLOAD_ERR_NO_FILE) {
            if ($upload["error"] !== UPLOAD_ERR_OK || $upload["size"] > 2 * 1024 * 1024) {
                $formError = "Profil resmi yüklenemedi veya 2 MB sınırını aşıyor.";
            } else {
                $extension = strtolower(pathinfo($upload["name"], PATHINFO_EXTENSION));
                $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($upload["tmp_name"]);
                if (!isset($allowedAvatarFiles[$extension]) || !in_array($mimeType, $allowedAvatarFiles[$extension], true)) {
                    $formError = "Profil resmi JPEG, PNG veya WEBP olmalıdır.";
                } else {
                    $newAvatar = bin2hex(random_bytes(20)) . "." . $extension;
                }
            }
        }

        if ($formError === "") {
            $fullName = trim($firstName . ' ' . $lastName);

            if ($newAvatar !== null) {
                $targetPath = __DIR__ . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'avatars' . DIRECTORY_SEPARATOR . $newAvatar;
                if (!move_uploaded_file($upload["tmp_name"], $targetPath)) {
                    $formError = "Profil resmi kaydedilemedi.";
                }
            }
        }

        if ($formError === "") {
            if ($newAvatar !== null) {
                $oldStmt = $pdo->prepare("SELECT avatar_file FROM users WHERE id = :id LIMIT 1");
                $oldStmt->execute(["id" => $userId]);
                $oldAvatar = $oldStmt->fetchColumn();
                if (is_string($oldAvatar) && $oldAvatar !== '') {
                    $oldPath = __DIR__ . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'avatars' . DIRECTORY_SEPARATOR . basename($oldAvatar);
                    if (is_file($oldPath)) {
                        unlink($oldPath);
                    }
                }
            }

            $updateStmt = $pdo->prepare(
                "UPDATE users
                 SET first_name = :first_name, last_name = :last_name, full_name = :full_name,
                     email = :email, phone = :phone, position_title = :position_title, bio = :bio,
                     social_facebook = :social_facebook, social_x = :social_x,
                     social_linkedin = :social_linkedin, social_instagram = :social_instagram"
                . ($newAvatar !== null ? ", avatar_file = :avatar_file" : "") .
                " WHERE id = :id"
            );
            $updateParams = [
                "first_name" => $firstName,
                "last_name" => $lastName !== "" ? $lastName : null,
                "full_name" => $fullName,
                "email" => $email !== "" ? $email : null,
                "phone" => $phone !== "" ? $phone : null,
                "position_title" => $positionTitle !== "" ? $positionTitle : null,
                "bio" => $bio !== "" ? $bio : null,
                "social_facebook" => $facebook !== "" ? $facebook : null,
                "social_x" => $xLink !== "" ? $xLink : null,
                "social_linkedin" => $linkedin !== "" ? $linkedin : null,
                "social_instagram" => $instagram !== "" ? $instagram : null,
                "id" => $userId
            ];
            if ($newAvatar !== null) {
                $updateParams["avatar_file"] = $newAvatar;
            }
            $updateStmt->execute($updateParams);

            $_SESSION["qms_full_name"] = $fullName;

            header("Location: profile.php?saved=personal");
            exit;
        }
    } elseif ($formType === "update_address") {
        $updateStmt = $pdo->prepare(
            "UPDATE users SET country = :country, city = :city, postal_code = :postal_code, tax_id = :tax_id WHERE id = :id"
        );
        $updateStmt->execute([
            "country" => trim($_POST["country"] ?? "") !== "" ? trim($_POST["country"]) : null,
            "city" => trim($_POST["city"] ?? "") !== "" ? trim($_POST["city"]) : null,
            "postal_code" => trim($_POST["postal_code"] ?? "") !== "" ? trim($_POST["postal_code"]) : null,
            "tax_id" => trim($_POST["tax_id"] ?? "") !== "" ? trim($_POST["tax_id"]) : null,
            "id" => $userId
        ]);

        header("Location: profile.php?saved=address");
        exit;
    } elseif ($formType === "update_password") {
        $currentPassword = (string) ($_POST["current_password"] ?? "");
        $newPassword = (string) ($_POST["new_password"] ?? "");
        $confirmPassword = (string) ($_POST["confirm_password"] ?? "");

        $passwordStmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = :id AND active = 1 LIMIT 1");
        $passwordStmt->execute(["id" => $userId]);
        $currentHash = $passwordStmt->fetchColumn();

        if ($currentHash === false) {
            $formError = "Hesap bulunamadı.";
        } elseif (!password_verify($currentPassword, (string) $currentHash)) {
            $formError = "Mevcut şifre doğru değil.";
        } elseif (strlen($newPassword) < 8) {
            $formError = "Yeni şifre en az 8 karakter olmalıdır.";
        } elseif ($newPassword !== $confirmPassword) {
            $formError = "Yeni şifreler birbiriyle uyuşmuyor.";
        } elseif (password_verify($newPassword, (string) $currentHash)) {
            $formError = "Yeni şifre mevcut şifreyle aynı olamaz.";
        } else {
            $updateStmt = $pdo->prepare("UPDATE users SET password_hash = :hash WHERE id = :id");
            $updateStmt->execute([
                "hash" => password_hash($newPassword, PASSWORD_DEFAULT),
                "id" => $userId
            ]);
            header("Location: profile.php?saved=password");
            exit;
        }
    }
}

$userStmt = $pdo->prepare(
    "SELECT id, username, full_name, first_name, last_name, email, phone, position_title, bio,
            social_facebook, social_x, social_linkedin, social_instagram,
            country, city, postal_code, tax_id, avatar_file, role, active, created_at
     FROM users WHERE id = :id LIMIT 1"
);
$userStmt->execute(["id" => $userId]);
$user = $userStmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    header("Location: logout.php");
    exit;
}

$roleLabel = appRoleLabel((string) $user["role"]);

$displayName = trim((string) ($user["first_name"] ?? '') . ' ' . (string) ($user["last_name"] ?? ''));
if ($displayName === '') {
    $displayName = (string) $user["full_name"];
}

$initials = appInitials($displayName);

$location = trim(implode(', ', array_filter([
    (string) ($user["city"] ?? ''),
    (string) ($user["country"] ?? '')
])));

$hasAvatar = is_string($user["avatar_file"]) && $user["avatar_file"] !== '' && is_file(__DIR__ . '/storage/avatars/' . basename((string) $user["avatar_file"]));

$systemCounts = [];
$assignedCompanies = [];
if ($isSuperAdmin) {
    $systemCounts = [
        "companies" => (int) $pdo->query("SELECT COUNT(*) FROM companies WHERE active = 1")->fetchColumn(),
        "users" => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE active = 1")->fetchColumn(),
        "auditors" => (int) $pdo->query("SELECT COUNT(*) FROM auditors WHERE active = 1")->fetchColumn(),
        "audits" => (int) $pdo->query("SELECT COUNT(*) FROM audits WHERE active = 1")->fetchColumn()
    ];
} else {
    // Kapsam tek kaynaktan: sistem admini atandigi sirketler, sirket kullanicisi
    // kendi sirketi, denetci atandigi denetimlerin sirketleri.
    $profileScope = qmsCompanyScope('companies.id', qmsVisibleCompanyIds($pdo, $userId, qmsCurrentRole()));
    $assignedStmt = $pdo->prepare(
        "SELECT companies.id, companies.company_name, companies.city, companies.sector
         FROM companies
         WHERE companies.active = 1" . $profileScope['sql'] . "
         ORDER BY companies.company_name"
    );
    $assignedStmt->execute($profileScope['params']);
    $assignedCompanies = $assignedStmt->fetchAll(PDO::FETCH_ASSOC);
}

$activeNav = "";

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f9fafb">
    <title>QMS Profil</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar">
        <div class="topbar-inner">
            <div class="page-title-block">
                <strong data-i18n="profileTitle">Profil</strong>
                <span><?= htmlspecialchars($user["username"], ENT_QUOTES, "UTF-8") ?> · <?= htmlspecialchars($roleLabel, ENT_QUOTES, "UTF-8") ?></span>
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
                <span class="section-kicker" data-i18n="profileKicker">Hesap</span>
                <h1 data-i18n="profileMyProfileTitle">Profilim</h1>
                <p data-i18n="profileMyProfileText">Hesap bilgilerinizi, adresinizi ve şifrenizi buradan yönetin.</p>
            </div>
            <a class="secondary-button" href="dashboard.php" data-i18n="dashboardLinkLabel">Panel</a>
        </section>

        <?php if (isset($_GET["saved"])): ?>
            <div class="form-message success" data-i18n="profileSavedMessage">Değişiklikleriniz kaydedildi.</div>
        <?php endif; ?>
        <?php if ($formError !== ""): ?>
            <div class="form-message error"><?= htmlspecialchars($formError, ENT_QUOTES, "UTF-8") ?></div>
        <?php endif; ?>

        <div class="profile-layout">
            <section class="profile-card">
                <div class="profile-cover"></div>
                <div class="profile-avatar">
                    <?php if ($hasAvatar): ?>
                        <img src="avatar.php" alt="<?= htmlspecialchars($displayName, ENT_QUOTES, "UTF-8") ?>">
                    <?php else: ?>
                        <?= htmlspecialchars($initials, ENT_QUOTES, "UTF-8") ?>
                    <?php endif; ?>
                </div>
                <div class="profile-name">
                    <h2><?= htmlspecialchars($displayName, ENT_QUOTES, "UTF-8") ?></h2>
                    <p><?= htmlspecialchars($user["position_title"] ?: $roleLabel, ENT_QUOTES, "UTF-8") ?></p>
                    <?php if ($location !== ''): ?>
                        <p><?= htmlspecialchars($location, ENT_QUOTES, "UTF-8") ?></p>
                    <?php endif; ?>
                </div>
                <dl class="profile-details">
                    <div class="profile-detail">
                        <dt data-i18n="profileFirstNameLabel">Ad</dt>
                        <dd><?= htmlspecialchars($user["first_name"] ?: "-", ENT_QUOTES, "UTF-8") ?></dd>
                    </div>
                    <div class="profile-detail">
                        <dt data-i18n="profileLastNameLabel">Soyad</dt>
                        <dd><?= htmlspecialchars($user["last_name"] ?: "-", ENT_QUOTES, "UTF-8") ?></dd>
                    </div>
                    <div class="profile-detail">
                        <dt data-i18n="profileEmailLabel">E-posta Adresi</dt>
                        <dd><?= htmlspecialchars($user["email"] ?: "-", ENT_QUOTES, "UTF-8") ?></dd>
                    </div>
                    <div class="profile-detail">
                        <dt data-i18n="profilePhoneLabel">Telefon</dt>
                        <dd><?= htmlspecialchars($user["phone"] ?: "-", ENT_QUOTES, "UTF-8") ?></dd>
                    </div>
                    <div class="profile-detail">
                        <dt data-i18n="profileBioLabel">Hakkında</dt>
                        <dd><?= nl2br(htmlspecialchars($user["bio"] ?: "-", ENT_QUOTES, "UTF-8")) ?></dd>
                    </div>
                    <div class="profile-detail">
                        <dt data-i18n="profileSocialLabel">Sosyal Bağlantılar</dt>
                        <dd>
                            <?php
                            $socials = array_filter([
                                "Facebook" => (string) ($user["social_facebook"] ?? ''),
                                "X" => (string) ($user["social_x"] ?? ''),
                                "LinkedIn" => (string) ($user["social_linkedin"] ?? ''),
                                "Instagram" => (string) ($user["social_instagram"] ?? '')
                            ]);
                            ?>
                            <?php if (!$socials): ?>
                                -
                            <?php else: ?>
                                <?php $first = true; foreach ($socials as $socialName => $socialUrl): ?><?= $first ? '' : ' · ' ?><a href="<?= htmlspecialchars($socialUrl, ENT_QUOTES, "UTF-8") ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars($socialName, ENT_QUOTES, "UTF-8") ?></a><?php $first = false; endforeach; ?>
                            <?php endif; ?>
                        </dd>
                    </div>
                </dl>
                <div class="profile-card-body">
                    <button class="secondary-button" type="button" data-modal-open="editPersonal" data-i18n="editButton">Düzenle</button>
                </div>
            </section>

            <div class="profile-stack">
                <section class="profile-card">
                    <div class="profile-card-header">
                        <div>
                            <h2 data-i18n="profileAddressTitle">Adres</h2>
                            <p data-i18n="profileAddressText">Fatura ve iletişim bilgileri.</p>
                        </div>
                        <button class="secondary-button" type="button" data-modal-open="editAddress" data-i18n="editButton">Düzenle</button>
                    </div>
                    <dl class="profile-details">
                        <div class="profile-detail">
                            <dt data-i18n="profileCountryLabel">Ülke</dt>
                            <dd><?= htmlspecialchars($user["country"] ?: "-", ENT_QUOTES, "UTF-8") ?></dd>
                        </div>
                        <div class="profile-detail">
                            <dt data-i18n="profileCityLabel">Şehir / Bölge</dt>
                            <dd><?= htmlspecialchars($user["city"] ?: "-", ENT_QUOTES, "UTF-8") ?></dd>
                        </div>
                        <div class="profile-detail">
                            <dt data-i18n="profilePostalCodeLabel">Posta Kodu</dt>
                            <dd><?= htmlspecialchars($user["postal_code"] ?: "-", ENT_QUOTES, "UTF-8") ?></dd>
                        </div>
                        <div class="profile-detail">
                            <dt data-i18n="profileTaxIdLabel">Vergi No</dt>
                            <dd><?= htmlspecialchars($user["tax_id"] ?: "-", ENT_QUOTES, "UTF-8") ?></dd>
                        </div>
                    </dl>
                </section>

                <section class="profile-card">
                    <div class="profile-card-header">
                        <div>
                            <h2 data-i18n="profileSecurityTitle">Güvenlik</h2>
                            <p data-i18n="profileSecurityText">Şifrenizi buradan güncelleyebilirsiniz.</p>
                        </div>
                        <button class="primary-button" type="button" data-modal-open="editPassword" data-i18n="profileChangePasswordButton">Şifre Değiştir</button>
                    </div>
                    <div class="profile-card-body">
                        <p class="profile-detail" style="margin:0;color:var(--muted-color);font-size:14px;" data-i18n="profileSecurityNote">İki adımlı doğrulama henüz desteklenmiyor.</p>
                    </div>
                </section>

                <?php if ($isSuperAdmin): ?>
                    <section class="profile-card">
                        <div class="profile-card-header">
                            <div>
                                <h2 data-i18n="profileSuperPanelTitle">Sistem Özeti</h2>
                                <p data-i18n="profileSuperPanelText">Tüm şirketler ve hesaplar üzerindeki genel durum.</p>
                            </div>
                        </div>
                        <div class="profile-card-body">
                            <div class="record-card-grid">
                                <a class="record-card" href="super-admin-companies.php">
                                    <span class="record-card-icon"><?= appIcon("companies", "") ?></span>
                                    <div class="record-card-body">
                                        <span class="record-card-eyebrow" data-i18n="companiesTitle">Şirketler</span>
                                        <h2><?= $systemCounts["companies"] ?></h2>
                                    </div>
                                </a>
                                <a class="record-card" href="super-admin-admins.php">
                                    <span class="record-card-icon"><?= appIcon("admins", "") ?></span>
                                    <div class="record-card-body">
                                        <span class="record-card-eyebrow" data-i18n="manageUsersButton">Sistem Adminleri</span>
                                        <h2><?= $systemCounts["users"] ?></h2>
                                    </div>
                                </a>
                                <a class="record-card" href="auditors.php">
                                    <span class="record-card-icon"><?= appIcon("users", "") ?></span>
                                    <div class="record-card-body">
                                        <span class="record-card-eyebrow" data-i18n="auditorsCardLabel">Denetçiler</span>
                                        <h2><?= $systemCounts["auditors"] ?></h2>
                                    </div>
                                </a>
                                <a class="record-card" href="dashboard.php">
                                    <span class="record-card-icon"><?= appIcon("reports", "") ?></span>
                                    <div class="record-card-body">
                                        <span class="record-card-eyebrow" data-i18n="activeAuditsCardLabel">Aktif Denetimler</span>
                                        <h2><?= $systemCounts["audits"] ?></h2>
                                    </div>
                                </a>
                            </div>
                        </div>
                    </section>
                <?php else: ?>
                    <section class="profile-card">
                        <div class="profile-card-header">
                            <div>
                                <h2 data-i18n="profileAdminPanelTitle">Atandığım Şirketler</h2>
                                <p data-i18n="profileAdminPanelText">Yalnız atandığınız şirketlerin kayıtlarına erişebilirsiniz.</p>
                            </div>
                        </div>
                        <div class="profile-card-body">
                            <?php if (!$assignedCompanies): ?>
                                <div class="empty-state" data-i18n="profileNoCompanyText">Henüz bir şirkete atanmadınız. Sistem yöneticinizle görüşün.</div>
                            <?php else: ?>
                                <div class="revision-list">
                                    <?php foreach ($assignedCompanies as $assigned): ?>
                                        <div class="revision-item">
                                            <div>
                                                <strong><?= htmlspecialchars($assigned["company_name"], ENT_QUOTES, "UTF-8") ?></strong>
                                                <span><?= htmlspecialchars(trim(($assigned["city"] ?? "") . " " . ($assigned["sector"] ?? "")), ENT_QUOTES, "UTF-8") ?></span>
                                            </div>
                                            <a class="secondary-button" href="company-detail.php?id=<?= (int) $assigned["id"] ?>" data-i18n="openRecordButton">Kaydı Aç</a>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </section>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <div class="modal-overlay" id="editPersonal" hidden>
        <div class="modal-panel" role="dialog" aria-modal="true" aria-labelledby="editPersonalTitle">
            <div class="modal-header">
                <div>
                    <h2 id="editPersonalTitle" data-i18n="editPersonalTitle">Kişisel Bilgileri Düzenle</h2>
                    <p data-i18n="editPersonalText">Profilinizi güncel tutmak için bilgilerinizi düzenleyin.</p>
                </div>
                <button class="modal-close" type="button" data-modal-close aria-label="Kapat">✕</button>
            </div>
            <form method="post" action="profile.php" enctype="multipart/form-data">
                <?= qmsCsrfField('profile') ?>
                <input type="hidden" name="form_type" value="update_personal">
                <div class="modal-body">
                    <div class="section-heading compact-heading"><div><h3 data-i18n="changeProfilePictureTitle">Profil Resmini Değiştir</h3><p data-i18n="changeProfilePictureText">Kare bir JPEG, PNG veya WEBP görsel yükleyin (önerilen 200×200 px, en fazla 2 MB).</p></div></div>
                    <label class="form-field form-field-wide">
                        <span data-i18n="profilePictureLabel">Profil Resmi</span>
                        <input type="file" name="avatar_file" accept="image/jpeg,image/png,image/webp">
                    </label>
                    <div class="section-heading compact-heading"><div><h3 data-i18n="personalInformationTitle">Kişisel Bilgiler</h3></div></div>
                    <div class="form-grid">
                        <label class="form-field">
                            <span data-i18n="profileFirstNameLabel">Ad</span>
                            <input type="text" name="first_name" maxlength="100" value="<?= htmlspecialchars((string) $user["first_name"], ENT_QUOTES, "UTF-8") ?>" required>
                        </label>
                        <label class="form-field">
                            <span data-i18n="profileLastNameLabel">Soyad</span>
                            <input type="text" name="last_name" maxlength="100" value="<?= htmlspecialchars((string) $user["last_name"], ENT_QUOTES, "UTF-8") ?>">
                        </label>
                        <label class="form-field">
                            <span data-i18n="profileEmailLabel">E-posta Adresi</span>
                            <input type="email" name="email" maxlength="150" value="<?= htmlspecialchars((string) $user["email"], ENT_QUOTES, "UTF-8") ?>">
                        </label>
                        <label class="form-field">
                            <span data-i18n="profilePhoneLabel">Telefon</span>
                            <input type="text" name="phone" maxlength="30" value="<?= htmlspecialchars((string) $user["phone"], ENT_QUOTES, "UTF-8") ?>">
                        </label>
                        <label class="form-field form-field-wide">
                            <span data-i18n="profilePositionLabel">Görev / Ünvan</span>
                            <input type="text" name="position_title" maxlength="150" value="<?= htmlspecialchars((string) $user["position_title"], ENT_QUOTES, "UTF-8") ?>">
                        </label>
                        <label class="form-field form-field-wide">
                            <span data-i18n="profileBioLabel">Hakkında</span>
                            <textarea name="bio" rows="4"><?= htmlspecialchars((string) $user["bio"], ENT_QUOTES, "UTF-8") ?></textarea>
                        </label>
                    </div>
                    <div class="section-heading compact-heading"><div><h3 data-i18n="socialLinksTitle">Sosyal Bağlantılar</h3></div></div>
                    <div class="form-grid">
                        <label class="form-field">
                            <span>Facebook</span>
                            <input type="url" name="social_facebook" maxlength="255" value="<?= htmlspecialchars((string) $user["social_facebook"], ENT_QUOTES, "UTF-8") ?>">
                        </label>
                        <label class="form-field">
                            <span>X</span>
                            <input type="url" name="social_x" maxlength="255" value="<?= htmlspecialchars((string) $user["social_x"], ENT_QUOTES, "UTF-8") ?>">
                        </label>
                        <label class="form-field">
                            <span>LinkedIn</span>
                            <input type="url" name="social_linkedin" maxlength="255" value="<?= htmlspecialchars((string) $user["social_linkedin"], ENT_QUOTES, "UTF-8") ?>">
                        </label>
                        <label class="form-field">
                            <span>Instagram</span>
                            <input type="url" name="social_instagram" maxlength="255" value="<?= htmlspecialchars((string) $user["social_instagram"], ENT_QUOTES, "UTF-8") ?>">
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="secondary-button" type="button" data-modal-close data-i18n="closeButton">Kapat</button>
                    <button class="primary-button" type="submit" data-i18n="saveChangesButton">Değişiklikleri Kaydet</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal-overlay" id="editAddress" hidden>
        <div class="modal-panel" role="dialog" aria-modal="true" aria-labelledby="editAddressTitle">
            <div class="modal-header">
                <div>
                    <h2 id="editAddressTitle" data-i18n="editAddressTitle">Adresi Düzenle</h2>
                    <p data-i18n="editAddressText">Fatura ve iletişim bilgilerinizi güncelleyin.</p>
                </div>
                <button class="modal-close" type="button" data-modal-close aria-label="Kapat">✕</button>
            </div>
            <form method="post" action="profile.php">
                <?= qmsCsrfField('profile') ?>
                <input type="hidden" name="form_type" value="update_address">
                <div class="modal-body">
                    <div class="form-grid">
                        <label class="form-field">
                            <span data-i18n="profileCountryLabel">Ülke</span>
                            <input type="text" name="country" maxlength="100" value="<?= htmlspecialchars((string) $user["country"], ENT_QUOTES, "UTF-8") ?>">
                        </label>
                        <label class="form-field">
                            <span data-i18n="profileCityLabel">Şehir / Bölge</span>
                            <input type="text" name="city" maxlength="150" value="<?= htmlspecialchars((string) $user["city"], ENT_QUOTES, "UTF-8") ?>">
                        </label>
                        <label class="form-field">
                            <span data-i18n="profilePostalCodeLabel">Posta Kodu</span>
                            <input type="text" name="postal_code" maxlength="30" value="<?= htmlspecialchars((string) $user["postal_code"], ENT_QUOTES, "UTF-8") ?>">
                        </label>
                        <label class="form-field">
                            <span data-i18n="profileTaxIdLabel">Vergi No</span>
                            <input type="text" name="tax_id" maxlength="50" value="<?= htmlspecialchars((string) $user["tax_id"], ENT_QUOTES, "UTF-8") ?>">
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="secondary-button" type="button" data-modal-close data-i18n="closeButton">Kapat</button>
                    <button class="primary-button" type="submit" data-i18n="saveChangesButton">Değişiklikleri Kaydet</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal-overlay" id="editPassword" hidden>
        <div class="modal-panel" role="dialog" aria-modal="true" aria-labelledby="editPasswordTitle">
            <div class="modal-header">
                <div>
                    <h2 id="editPasswordTitle" data-i18n="profilePasswordTitle">Şifre Değiştir</h2>
                    <p data-i18n="profilePasswordText">Hesabınızın şifresini buradan güncelleyebilirsiniz. Yeni şifre en az 8 karakter olmalıdır.</p>
                </div>
                <button class="modal-close" type="button" data-modal-close aria-label="Kapat">✕</button>
            </div>
            <form method="post" action="profile.php">
                <?= qmsCsrfField('profile') ?>
                <input type="hidden" name="form_type" value="update_password">
                <div class="modal-body">
                    <div class="form-grid">
                        <label class="form-field form-field-wide">
                            <span data-i18n="currentPasswordLabel">Mevcut Şifre</span>
                            <input type="password" name="current_password" autocomplete="current-password" required>
                        </label>
                        <label class="form-field">
                            <span data-i18n="newPasswordLabel">Yeni Şifre</span>
                            <input type="password" name="new_password" autocomplete="new-password" minlength="8" required>
                        </label>
                        <label class="form-field">
                            <span data-i18n="confirmPasswordLabel">Yeni Şifre (Tekrar)</span>
                            <input type="password" name="confirm_password" autocomplete="new-password" minlength="8" required>
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="secondary-button" type="button" data-modal-close data-i18n="closeButton">Kapat</button>
                    <button class="primary-button" type="submit" data-i18n="changePasswordButton">Şifreyi Güncelle</button>
                </div>
            </form>
        </div>
    </div>

    <script src="assets/js/theme.js"></script>
    <script src="assets/js/language.js"></script>
    <script src="assets/js/sidebar.js"></script>
    <script src="assets/js/modal.js"></script>
    <script src="assets/js/pwa.js"></script>
</body>
</html>
