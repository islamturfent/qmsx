<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';

$actionId = (int) ($_GET["id"] ?? 0);
$actionStmt = $pdo->prepare(
    "SELECT corrective_actions.*, nonconformities.title AS nonconformity_title,
            nonconformities.company_id, companies.company_name
     FROM corrective_actions
     INNER JOIN nonconformities ON nonconformities.id = corrective_actions.nonconformity_id
     INNER JOIN companies ON companies.id = nonconformities.company_id
     WHERE corrective_actions.id = :id AND corrective_actions.active = 1
     LIMIT 1"
);
$actionStmt->execute(["id" => $actionId]);
$action = $actionStmt->fetch(PDO::FETCH_ASSOC);

if (!$action) {
    header("Location: dashboard.php");
    exit;
}

$formError = "";
$allowedStatuses = ["planned", "in_progress", "verification", "completed", "closed"];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $formData = [
        "action_text" => trim($_POST["action_text"] ?? ""),
        "responsible_person" => trim($_POST["responsible_person"] ?? ""),
        "due_date" => trim($_POST["due_date"] ?? ""),
        "status" => $_POST["status"] ?? "planned",
        "evidence_note" => trim($_POST["evidence_note"] ?? ""),
        "verifier_name" => trim($_POST["verifier_name"] ?? ""),
        "verification_note" => trim($_POST["verification_note"] ?? "")
    ];

    if ($formData["action_text"] === "") {
        $formError = "Lütfen düzeltici faaliyeti açıklayın.";
    } elseif (!in_array($formData["status"], $allowedStatuses, true)) {
        $formError = "Geçerli bir durum seçin.";
    } else {
        $completedAt = in_array($formData["status"], ["completed", "closed"], true)
            ? ($action["completed_at"] ?: date("Y-m-d H:i:s"))
            : null;
        $closedAt = $formData["status"] === "closed"
            ? ($action["closed_at"] ?: date("Y-m-d H:i:s"))
            : null;

        $updateStmt = $pdo->prepare(
            "UPDATE corrective_actions
             SET action_text = :action_text,
                 responsible_person = :responsible_person,
                 due_date = :due_date,
                 status = :status,
                 evidence_note = :evidence_note,
                 verifier_name = :verifier_name,
                 verification_note = :verification_note,
                 completed_at = :completed_at,
                 closed_at = :closed_at
             WHERE id = :id"
        );
        $updateStmt->execute([
            "action_text" => $formData["action_text"],
            "responsible_person" => $formData["responsible_person"] !== "" ? $formData["responsible_person"] : null,
            "due_date" => $formData["due_date"] !== "" ? $formData["due_date"] : null,
            "status" => $formData["status"],
            "evidence_note" => $formData["evidence_note"] !== "" ? $formData["evidence_note"] : null,
            "verifier_name" => $formData["verifier_name"] !== "" ? $formData["verifier_name"] : null,
            "verification_note" => $formData["verification_note"] !== "" ? $formData["verification_note"] : null,
            "completed_at" => $completedAt,
            "closed_at" => $closedAt,
            "id" => $actionId
        ]);

        header("Location: corrective-action-detail.php?id=" . $actionId . "&updated=1");
        exit;
    }

    $action = array_merge($action, $formData);
}

$statusLabels = [
    "planned" => "Planlandı",
    "in_progress" => "Çalışılıyor",
    "verification" => "Doğrulama",
    "completed" => "Tamamlandı",
    "closed" => "Kapalı"
];
$activeNav = "companies";

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f9fafb">
    <title>QMS Düzeltici Faaliyet Detayı</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar">
        <div class="topbar-inner">
            <div class="page-title-block">
                <strong data-i18n="correctiveActionDetailTitle">Düzeltici Faaliyet Detayı</strong>
                <span><?= htmlspecialchars($action["company_name"], ENT_QUOTES, "UTF-8") ?> · <?= htmlspecialchars($action["nonconformity_title"], ENT_QUOTES, "UTF-8") ?></span>
            </div>
            <div class="topbar-actions">
                <button class="topbar-button" id="languageToggle" type="button">EN</button>
                <button class="topbar-button" id="themeToggle" type="button" aria-label="Tema değiştir">🌙</button>
                <a class="topbar-button topbar-link" href="nonconformity-detail.php?id=<?= (int) $action["nonconformity_id"] ?>" data-i18n="backToNonconformityButton">Uygunsuzluğa Dön</a>
            </div>
        </div>
    </header>
    <main class="page-container narrow-page">
        <section class="page-heading">
            <span class="section-kicker" data-i18n="correctiveActionsKicker">İyileştirme Takibi</span>
            <h1 data-i18n="correctiveActionDetailTitle">Düzeltici Faaliyet Detayı</h1>
            <p><?= htmlspecialchars($action["action_text"], ENT_QUOTES, "UTF-8") ?></p>
        </section>
        <section class="dashboard-grid compact-dashboard-grid">
            <div class="dashboard-card">
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="actionStatusLabel">Durum</span>
                    <strong class="dashboard-card-number detail-card-value"><?= htmlspecialchars($statusLabels[$action["status"]] ?? $action["status"], ENT_QUOTES, "UTF-8") ?></strong>
                </div>
            </div>
            <div class="dashboard-card">
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="dueDateLabel">Termin Tarihi</span>
                    <strong class="dashboard-card-number detail-card-value"><?= htmlspecialchars($action["due_date"] ?: "-", ENT_QUOTES, "UTF-8") ?></strong>
                </div>
            </div>
        </section>
        <section class="form-panel">
            <?php if (isset($_GET["updated"]) && $_GET["updated"] === "1"): ?>
                <div class="form-message success" data-i18n="correctiveActionUpdatedMessage">Düzeltici faaliyet güncellendi.</div>
            <?php endif; ?>
            <?php if ($formError !== ""): ?>
                <div class="form-message error"><?= htmlspecialchars($formError, ENT_QUOTES, "UTF-8") ?></div>
            <?php endif; ?>
            <form class="auditor-form" method="post" action="corrective-action-detail.php?id=<?= $actionId ?>">
                <div class="form-grid">
                    <label class="form-field form-field-wide">
                        <span data-i18n="actionTextLabel">Faaliyet Açıklaması</span>
                        <textarea name="action_text" rows="4" required><?= htmlspecialchars($action["action_text"], ENT_QUOTES, "UTF-8") ?></textarea>
                    </label>
                    <label class="form-field">
                        <span data-i18n="responsiblePersonLabel">Sorumlu Kişi</span>
                        <input type="text" name="responsible_person" value="<?= htmlspecialchars($action["responsible_person"] ?? "", ENT_QUOTES, "UTF-8") ?>">
                    </label>
                    <label class="form-field">
                        <span data-i18n="dueDateLabel">Termin Tarihi</span>
                        <input type="date" name="due_date" value="<?= htmlspecialchars($action["due_date"] ?? "", ENT_QUOTES, "UTF-8") ?>">
                    </label>
                    <label class="form-field form-field-wide">
                        <span data-i18n="actionStatusLabel">Durum</span>
                        <select name="status">
                            <?php foreach ($statusLabels as $value => $label): ?>
                                <option value="<?= $value ?>" <?= $action["status"] === $value ? "selected" : "" ?> data-i18n="actionStatus<?= str_replace(' ', '', ucwords(str_replace('_', ' ', $value))) ?>Label"><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="form-field form-field-wide">
                        <span data-i18n="actionEvidenceLabel">Uygulama Kanıtı / Notu</span>
                        <textarea name="evidence_note" rows="4"><?= htmlspecialchars($action["evidence_note"] ?? "", ENT_QUOTES, "UTF-8") ?></textarea>
                    </label>
                    <label class="form-field">
                        <span data-i18n="verifierNameLabel">Doğrulayan Kişi</span>
                        <input type="text" name="verifier_name" value="<?= htmlspecialchars($action["verifier_name"] ?? "", ENT_QUOTES, "UTF-8") ?>">
                    </label>
                    <label class="form-field form-field-wide">
                        <span data-i18n="verificationNoteLabel">Doğrulama Notu</span>
                        <textarea name="verification_note" rows="4"><?= htmlspecialchars($action["verification_note"] ?? "", ENT_QUOTES, "UTF-8") ?></textarea>
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
