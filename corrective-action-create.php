<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/capa-functions.php';

$nonconformityId = (int) ($_GET["nonconformity_id"] ?? $_POST["nonconformity_id"] ?? 0);

$userId = (int) ($_SESSION["qms_user_id"] ?? 0);

// Kapsamli okuma: id degistirilerek baska sirketin uygunsuzlugu acilamaz.
$nonconformity = qmsNonconformityFind($pdo, $nonconformityId, $userId, qmsCurrentRole());

if (!$nonconformity) {
    header("Location: dashboard.php");
    exit;
}

// Sorumlu olabilecek kullanicilar: sirketin kendi kullanicilari ve o sirkete
// atanmis sistem adminleri. Serbest metin alani disaridan gelen kisiler icin kalir.
$responsibleOptions = qmsCompanyResponsibleOptions($pdo, (int) $nonconformity["company_id"]);
$allowedResponsibleIds = array_map('intval', array_column($responsibleOptions, 'id'));

$_SESSION["corrective_action_csrf"] ??= bin2hex(random_bytes(32));
$csrfToken = $_SESSION["corrective_action_csrf"];

$formError = "";
$formData = [
    "action_type" => "corrective",
    "action_text" => "",
    "responsible_person" => "",
    "responsible_user_id" => 0,
    "due_date" => ""
];
$capaTypeLabels = qmsCapaTypeLabels();
$capaTypeI18n = qmsCapaTypeI18nKeys();

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!hash_equals($csrfToken, (string) ($_POST["csrf"] ?? ""))) {
        http_response_code(403);
        exit("Geçersiz istek.");
    }

    $formData = [
        "action_type" => (string) ($_POST["action_type"] ?? "corrective"),
        "action_text" => trim($_POST["action_text"] ?? ""),
        "responsible_person" => trim($_POST["responsible_person"] ?? ""),
        "responsible_user_id" => (int) ($_POST["responsible_user_id"] ?? 0),
        "due_date" => trim($_POST["due_date"] ?? "")
    ];
    if (!in_array($formData["action_type"], QMS_CAPA_TYPES, true)) {
        $formData["action_type"] = "corrective";
    }

    // Yalnizca bu sirkette sorumlu olabilecek kullanicilar kabul edilir.
    if ($formData["responsible_user_id"] > 0 && !in_array($formData["responsible_user_id"], $allowedResponsibleIds, true)) {
        $formData["responsible_user_id"] = 0;
    }

    if ($formData["action_text"] === "") {
        $formError = "Lütfen düzeltici faaliyeti açıklayın.";
    } else {
        $insertStmt = $pdo->prepare(
            "INSERT INTO corrective_actions
                (nonconformity_id, action_type, action_text, responsible_person, responsible_user_id, due_date, status, active)
             VALUES
                (:nonconformity_id, :action_type, :action_text, :responsible_person, :responsible_user_id, :due_date, 'planned', 1)"
        );
        $insertStmt->execute([
            "nonconformity_id" => $nonconformityId,
            "action_type" => $formData["action_type"],
            "action_text" => $formData["action_text"],
            "responsible_person" => $formData["responsible_person"] !== "" ? $formData["responsible_person"] : null,
            "responsible_user_id" => $formData["responsible_user_id"] > 0 ? $formData["responsible_user_id"] : null,
            "due_date" => $formData["due_date"] !== "" ? $formData["due_date"] : null
        ]);

        // Atama bildirimi tek kural setinden gelir (includes/capa-functions.php).
        qmsCapaNotifyStatusChange($pdo, [
            "company_id" => (int) $nonconformity["company_id"],
            "action_text" => $formData["action_text"],
            "link" => "corrective-action-detail.php?id=" . (int) $pdo->lastInsertId(),
            "previous_status" => "planned",
            "new_status" => "planned",
            "previous_responsible_user_id" => 0,
            "responsible_user_id" => (int) $formData["responsible_user_id"],
            "actor_user_id" => $userId
        ]);

        header("Location: nonconformity-detail.php?id=" . $nonconformityId . "&action=created");
        exit;
    }
}

$activeNav = "companies";

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f9fafb">
    <title>QMS Yeni Düzeltici Faaliyet</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar">
        <div class="topbar-inner">
            <div class="page-title-block">
                <strong data-i18n="createCorrectiveActionTitle">Yeni Düzeltici Faaliyet</strong>
                <span><?= htmlspecialchars($nonconformity["company_name"], ENT_QUOTES, "UTF-8") ?> · <?= htmlspecialchars($nonconformity["title"], ENT_QUOTES, "UTF-8") ?></span>
            </div>
            <div class="topbar-actions">
                <button class="topbar-button" id="languageToggle" type="button">EN</button>
                <button class="topbar-button" id="themeToggle" type="button" aria-label="Tema değiştir">🌙</button>
            </div>
        </div>
    </header>
    <main class="page-container narrow-page">
        <section class="page-heading page-heading-actions">
            <div>
                <span class="section-kicker" data-i18n="correctiveActionsKicker">İyileştirme Takibi</span>
                <h1 data-i18n="createCorrectiveActionTitle">Yeni Düzeltici Faaliyet</h1>
                <p data-i18n="createCorrectiveActionText">Uygunsuzluğun kök nedenini ortadan kaldıracak faaliyeti planlayın.</p>
            </div>
            <a class="secondary-button" href="nonconformity-detail.php?id=<?= $nonconformityId ?>" data-i18n="backToNonconformityButton">Uygunsuzluğa Dön</a>
        </section>
        <section class="form-panel">
            <?php if ($formError !== ""): ?>
                <div class="form-message error"><?= htmlspecialchars($formError, ENT_QUOTES, "UTF-8") ?></div>
            <?php endif; ?>
            <form class="auditor-form" method="post" action="corrective-action-create.php?nonconformity_id=<?= $nonconformityId ?>">
                <input type="hidden" name="nonconformity_id" value="<?= $nonconformityId ?>">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, "UTF-8") ?>">
                <div class="form-grid">
                    <label class="form-field">
                        <span data-i18n="actionTypeLabel">Faaliyet Türü</span>
                        <select name="action_type">
                            <?php foreach ($capaTypeLabels as $typeKey => $typeLabel): ?>
                                <option value="<?= $typeKey ?>" <?= $formData["action_type"] === $typeKey ? "selected" : "" ?>><?= htmlspecialchars($typeLabel, ENT_QUOTES, "UTF-8") ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="form-field form-field-wide">
                        <span data-i18n="actionTextLabel">Faaliyet Açıklaması</span>
                        <textarea name="action_text" rows="5" required><?= htmlspecialchars($formData["action_text"], ENT_QUOTES, "UTF-8") ?></textarea>
                    </label>
                    <label class="form-field">
                        <span data-i18n="responsiblePersonLabel">Sorumlu Kişi</span>
                        <input type="text" name="responsible_person" value="<?= htmlspecialchars($formData["responsible_person"], ENT_QUOTES, "UTF-8") ?>">
                    </label>
                    <label class="form-field form-field-wide">
                        <span data-i18n="responsibleUserLabel">Sorumlu Kullanıcı (isteğe bağlı)</span>
                        <select name="responsible_user_id">
                            <option value="0" data-i18n="responsibleUserNoneOption">— seçilmedi (bildirim gönderilmez)</option>
                            <?php foreach ($responsibleOptions as $responsibleOption): ?>
                                <option value="<?= (int) $responsibleOption["id"] ?>" <?= (int) $formData["responsible_user_id"] === (int) $responsibleOption["id"] ? "selected" : "" ?>><?= htmlspecialchars($responsibleOption["full_name"], ENT_QUOTES, "UTF-8") ?> · <?= htmlspecialchars(appRoleLabel((string) $responsibleOption["role"]), ENT_QUOTES, "UTF-8") ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small data-i18n="responsibleUserHelp">Seçilirse faaliyet atandığında bu kullanıcıya bildirim gider.</small>
                    </label>
                    <label class="form-field">
                        <span data-i18n="dueDateLabel">Termin Tarihi</span>
                        <input type="date" name="due_date" value="<?= htmlspecialchars($formData["due_date"], ENT_QUOTES, "UTF-8") ?>">
                    </label>
                </div>
                <div class="form-actions">
                    <button class="primary-button" type="submit" data-i18n="saveCorrectiveActionButton">Faaliyeti Kaydet</button>
                </div>
            </form>
        </section>
    </main>
    <script src="assets/js/theme.js"></script>
    <script src="assets/js/language.js"></script>
    <script src="assets/js/sidebar.js"></script>
    <script src="assets/js/pwa.js"></script>
</body>
</html>
