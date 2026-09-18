<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';

$nonconformityId = (int) ($_GET["nonconformity_id"] ?? $_POST["nonconformity_id"] ?? 0);

$nonconformityStmt = $pdo->prepare(
    "SELECT nonconformities.id, nonconformities.title, companies.company_name
     FROM nonconformities
     INNER JOIN companies ON companies.id = nonconformities.company_id
     WHERE nonconformities.id = :id AND nonconformities.active = 1
     LIMIT 1"
);
$nonconformityStmt->execute(["id" => $nonconformityId]);
$nonconformity = $nonconformityStmt->fetch(PDO::FETCH_ASSOC);

if (!$nonconformity) {
    header("Location: dashboard.php");
    exit;
}

$formError = "";
$formData = [
    "action_text" => "",
    "responsible_person" => "",
    "due_date" => ""
];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $formData = [
        "action_text" => trim($_POST["action_text"] ?? ""),
        "responsible_person" => trim($_POST["responsible_person"] ?? ""),
        "due_date" => trim($_POST["due_date"] ?? "")
    ];

    if ($formData["action_text"] === "") {
        $formError = "Lütfen düzeltici faaliyeti açıklayın.";
    } else {
        $insertStmt = $pdo->prepare(
            "INSERT INTO corrective_actions
                (nonconformity_id, action_text, responsible_person, due_date, status, active)
             VALUES
                (:nonconformity_id, :action_text, :responsible_person, :due_date, 'planned', 1)"
        );
        $insertStmt->execute([
            "nonconformity_id" => $nonconformityId,
            "action_text" => $formData["action_text"],
            "responsible_person" => $formData["responsible_person"] !== "" ? $formData["responsible_person"] : null,
            "due_date" => $formData["due_date"] !== "" ? $formData["due_date"] : null
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
                <div class="form-grid">
                    <label class="form-field form-field-wide">
                        <span data-i18n="actionTextLabel">Faaliyet Açıklaması</span>
                        <textarea name="action_text" rows="5" required><?= htmlspecialchars($formData["action_text"], ENT_QUOTES, "UTF-8") ?></textarea>
                    </label>
                    <label class="form-field">
                        <span data-i18n="responsiblePersonLabel">Sorumlu Kişi</span>
                        <input type="text" name="responsible_person" value="<?= htmlspecialchars($formData["responsible_person"], ENT_QUOTES, "UTF-8") ?>">
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
