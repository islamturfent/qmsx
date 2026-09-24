<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/complaint-functions.php';

$userId = (int) ($_SESSION["qms_user_id"] ?? 0);
$role = qmsCurrentRole();
$csrfScope = 'complaint_create';

// Sirket listesi kapsamdan gelir.
$companyScope = qmsCompanyScope('companies.id', qmsVisibleCompanyIds($pdo, $userId, $role));
$companyStmt = $pdo->prepare(
    "SELECT companies.id, companies.company_name
     FROM companies
     WHERE companies.active = 1" . $companyScope['sql'] . "
     ORDER BY companies.company_name"
);
$companyStmt->execute($companyScope['params']);
$companies = $companyStmt->fetchAll(PDO::FETCH_ASSOC);
$allowedCompanyIds = array_map('intval', array_column($companies, 'id'));

$severityLabels = qmsComplaintSeverityLabels();
$severityI18n = qmsComplaintSeverityI18nKeys();
$sourceLabels = qmsComplaintSourceLabels();
$sourceI18n = qmsComplaintSourceI18nKeys();
$channelLabels = qmsComplaintChannelLabels();
$channelI18n = qmsComplaintChannelI18nKeys();

$formError = "";
$formData = [
    "company_id" => count($companies) === 1 ? (int) $companies[0]["id"] : 0,
    "complaint_code" => "",
    "subject" => "",
    "received_date" => date("Y-m-d"),
    "source" => "customer",
    "channel" => "email",
    "customer_name" => "",
    "customer_contact" => "",
    "severity" => "major",
    "due_date" => "",
    "description" => ""
];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    qmsCsrfVerify($csrfScope, $_POST["csrf"] ?? null);

    $formData = [
        "company_id" => (int) ($_POST["company_id"] ?? 0),
        "complaint_code" => qmsComplaintText($_POST["complaint_code"] ?? "", 60),
        "subject" => qmsComplaintText($_POST["subject"] ?? "", 255),
        "received_date" => trim((string) ($_POST["received_date"] ?? "")),
        "source" => (string) ($_POST["source"] ?? "customer"),
        "channel" => (string) ($_POST["channel"] ?? ""),
        "customer_name" => qmsComplaintText($_POST["customer_name"] ?? "", 180),
        "customer_contact" => qmsComplaintText($_POST["customer_contact"] ?? "", 180),
        "severity" => (string) ($_POST["severity"] ?? "major"),
        "due_date" => trim((string) ($_POST["due_date"] ?? "")),
        "description" => qmsComplaintText($_POST["description"] ?? "", 4000)
    ];

    $receivedDate = qmsComplaintDate($formData["received_date"]);
    $dueDate = qmsComplaintDate($formData["due_date"]);

    if ($formData["subject"] === "") {
        $formError = "Lütfen şikayet konusunu girin.";
    } elseif (!in_array($formData["company_id"], $allowedCompanyIds, true)) {
        $formError = "Geçerli bir şirket seçin.";
    } elseif ($receivedDate === null) {
        $formError = "Geçerli bir alınma tarihi girin.";
    } elseif ($formData["due_date"] !== "" && $dueDate === null) {
        $formError = "Termin tarihi geçerli değil.";
    } elseif (!in_array($formData["source"], QMS_COMPLAINT_SOURCES, true)) {
        $formError = "Geçerli bir kaynak seçin.";
    } elseif ($formData["channel"] !== "" && !in_array($formData["channel"], QMS_COMPLAINT_CHANNELS, true)) {
        $formError = "Geçerli bir kanal seçin.";
    } elseif (!in_array($formData["severity"], QMS_COMPLAINT_SEVERITIES, true)) {
        $formError = "Geçerli bir önem derecesi seçin.";
    } else {
        $duplicateCode = false;
        if ($formData["complaint_code"] !== "") {
            $duplicateStmt = $pdo->prepare(
                "SELECT COUNT(*) FROM complaints
                 WHERE company_id = :company_id AND complaint_code = :complaint_code AND active = 1"
            );
            $duplicateStmt->execute([
                "company_id" => $formData["company_id"],
                "complaint_code" => $formData["complaint_code"]
            ]);
            $duplicateCode = (int) $duplicateStmt->fetchColumn() > 0;
        }

        if ($duplicateCode) {
            $formError = "Bu şikayet numarası şirkette zaten kayıtlı.";
        } else {
            $insertStmt = $pdo->prepare(
                "INSERT INTO complaints
                    (company_id, complaint_code, source, channel, customer_name, customer_contact,
                     received_date, subject, description, severity, status, due_date,
                     created_by, updated_by, active)
                 VALUES
                    (:company_id, :complaint_code, :source, :channel, :customer_name, :customer_contact,
                     :received_date, :subject, :description, :severity, 'new', :due_date,
                     :created_by, :updated_by, 1)"
            );
            $insertStmt->execute([
                "company_id" => $formData["company_id"],
                "complaint_code" => $formData["complaint_code"] !== "" ? $formData["complaint_code"] : null,
                "source" => $formData["source"],
                "channel" => $formData["channel"] !== "" ? $formData["channel"] : null,
                "customer_name" => $formData["customer_name"] !== "" ? $formData["customer_name"] : null,
                "customer_contact" => $formData["customer_contact"] !== "" ? $formData["customer_contact"] : null,
                "received_date" => $receivedDate,
                "subject" => $formData["subject"],
                "description" => $formData["description"] !== "" ? $formData["description"] : null,
                "severity" => $formData["severity"],
                "due_date" => $dueDate,
                "created_by" => $userId ?: null,
                "updated_by" => $userId ?: null
            ]);

            $complaintId = (int) $pdo->lastInsertId();

            // Kritik sikayet yonetimin bilmesi gereken bir kayittir.
            qmsComplaintNotify($pdo, [
                "company_id" => $formData["company_id"],
                "subject" => $formData["subject"],
                "link" => "complaint-detail.php?id=" . $complaintId,
                "previous_status" => "new",
                "new_status" => "new",
                "previous_severity" => "",
                "severity" => $formData["severity"],
                "previous_responsible_user_id" => 0,
                "responsible_user_id" => 0,
                "actor_user_id" => $userId
            ]);

            header("Location: complaint-detail.php?id=" . $complaintId . "&created=1");
            exit;
        }
    }
}

$activeNav = "complaints";

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f9fafb">
    <title>QMS Yeni Şikayet</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar">
        <div class="topbar-inner">
            <div class="page-title-block">
                <strong data-i18n="newComplaintTitle">Yeni Şikayet</strong>
                <span data-i18n="complaintsText">Şikayetleri kaydedin, uygunsuzlukla ilişkilendirin ve kapanışı izleyin.</span>
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
                <span class="section-kicker" data-i18n="complaintRegisterKicker">Şikayet Kayıtları</span>
                <h1 data-i18n="newComplaintTitle">Yeni Şikayet</h1>
                <p data-i18n="newComplaintText">Şikayeti kaydedin; sorumlu atamasını ve uygunsuzluk bağlantısını detay sayfasından yapın.</p>
            </div>
            <a class="secondary-button" href="complaints.php" data-i18n="backToComplaintsButton">Şikayetlere Dön</a>
        </section>
        <section class="form-panel">
            <?php if ($formError !== ""): ?>
                <div class="form-message error"><?= htmlspecialchars($formError, ENT_QUOTES, "UTF-8") ?></div>
            <?php endif; ?>
            <?php if (!$companies): ?>
                <div class="form-message error" data-i18n="complaintNoCompanyText">Şikayet eklemek için önce bir şirket gerekir. Şirket kaydınız yoksa yöneticinizle görüşün.</div>
            <?php else: ?>
            <form class="auditor-form" method="post" action="complaint-create.php">
                <?= qmsCsrfField($csrfScope) ?>
                <div class="form-grid">
                    <label class="form-field">
                        <span data-i18n="companySelectLabel">Şirket</span>
                        <select name="company_id" required>
                            <option value="0" data-i18n="selectCompanyOption">Şirket seçin</option>
                            <?php foreach ($companies as $company): ?>
                                <option value="<?= (int) $company["id"] ?>" <?= $formData["company_id"] === (int) $company["id"] ? "selected" : "" ?>><?= htmlspecialchars($company["company_name"], ENT_QUOTES, "UTF-8") ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="form-field">
                        <span data-i18n="complaintSubjectLabel">Şikayet Konusu</span>
                        <input type="text" name="subject" maxlength="255" value="<?= htmlspecialchars($formData["subject"], ENT_QUOTES, "UTF-8") ?>" required>
                    </label>
                    <label class="form-field">
                        <span data-i18n="complaintCodeLabel">Şikayet Numarası</span>
                        <input type="text" name="complaint_code" maxlength="60" value="<?= htmlspecialchars($formData["complaint_code"], ENT_QUOTES, "UTF-8") ?>" placeholder="ŞK-2026-001">
                    </label>
                    <label class="form-field">
                        <span data-i18n="receivedDateLabel">Alınma Tarihi</span>
                        <input type="date" name="received_date" value="<?= htmlspecialchars($formData["received_date"], ENT_QUOTES, "UTF-8") ?>" required>
                    </label>
                    <label class="form-field">
                        <span data-i18n="complaintSourceLabel">Kaynak</span>
                        <select name="source">
                            <?php foreach ($sourceLabels as $value => $label): ?>
                                <option value="<?= $value ?>" <?= $formData["source"] === $value ? "selected" : "" ?> data-i18n="<?= $sourceI18n[$value] ?>"><?= htmlspecialchars($label, ENT_QUOTES, "UTF-8") ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="form-field">
                        <span data-i18n="complaintChannelLabel">Bildirim Kanalı</span>
                        <select name="channel">
                            <option value="" data-i18n="complaintChannelNoneOption">— belirtilmedi</option>
                            <?php foreach ($channelLabels as $value => $label): ?>
                                <option value="<?= $value ?>" <?= $formData["channel"] === $value ? "selected" : "" ?> data-i18n="<?= $channelI18n[$value] ?>"><?= htmlspecialchars($label, ENT_QUOTES, "UTF-8") ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="form-field">
                        <span data-i18n="complaintCustomerLabel">Şikayet Eden</span>
                        <input type="text" name="customer_name" maxlength="180" value="<?= htmlspecialchars($formData["customer_name"], ENT_QUOTES, "UTF-8") ?>">
                    </label>
                    <label class="form-field">
                        <span data-i18n="complaintContactLabel">İletişim Bilgisi</span>
                        <input type="text" name="customer_contact" maxlength="180" value="<?= htmlspecialchars($formData["customer_contact"], ENT_QUOTES, "UTF-8") ?>" placeholder="E-posta veya telefon">
                    </label>
                    <label class="form-field">
                        <span data-i18n="complaintSeverityLabel">Önem</span>
                        <select name="severity">
                            <?php foreach ($severityLabels as $value => $label): ?>
                                <option value="<?= $value ?>" <?= $formData["severity"] === $value ? "selected" : "" ?> data-i18n="<?= $severityI18n[$value] ?>"><?= htmlspecialchars($label, ENT_QUOTES, "UTF-8") ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="form-field">
                        <span data-i18n="dueDateLabel">Termin Tarihi</span>
                        <input type="date" name="due_date" value="<?= htmlspecialchars($formData["due_date"], ENT_QUOTES, "UTF-8") ?>">
                    </label>
                    <label class="form-field form-field-wide">
                        <span data-i18n="complaintDescriptionLabel">Şikayet Açıklaması</span>
                        <textarea name="description" rows="5"><?= htmlspecialchars($formData["description"], ENT_QUOTES, "UTF-8") ?></textarea>
                    </label>
                </div>
                <div class="form-actions">
                    <button class="primary-button" type="submit" data-i18n="saveComplaintButton">Şikayeti Kaydet</button>
                    <a class="secondary-button" href="complaints.php" data-i18n="backToComplaintsButton">Şikayetlere Dön</a>
                </div>
            </form>
            <?php endif; ?>
        </section>
    </main>
    <script src="assets/js/theme.js"></script>
    <script src="assets/js/language.js"></script>
    <script src="assets/js/sidebar.js"></script>
    <script src="assets/js/pwa.js"></script>
</body>
</html>
