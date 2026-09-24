<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/supplier-functions.php';

$supplierId = (int) ($_GET["id"] ?? $_POST["supplier_id"] ?? 0);
$userId = (int) ($_SESSION["qms_user_id"] ?? 0);
$role = qmsCurrentRole();
$csrfScope = 'supplier_detail';

// Kapsamli okuma: id degistirilerek baska sirketin tedarikcisi acilamaz.
$supplier = qmsSupplierFind($pdo, $supplierId, $userId, $role);

if (!$supplier) {
    header("Location: suppliers.php");
    exit;
}

$companyId = (int) $supplier["company_id"];
$statusLabels = qmsSupplierStatusLabels();
$statusI18n = qmsSupplierStatusI18nKeys();
$riskLabels = qmsSupplierRiskLabels();
$riskI18n = qmsSupplierRiskI18nKeys();
$resultLabels = qmsSupplierResultLabels();
$resultI18n = qmsSupplierResultI18nKeys();

$formError = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    qmsCsrfVerify($csrfScope, $_POST["csrf"] ?? null);

    $formType = (string) ($_POST["form_type"] ?? "");
    $redirect = "supplier-detail.php?id=" . $supplierId;

    if ($formType === "update_supplier") {
        $formData = [
            "name" => qmsSupplierText($_POST["name"] ?? "", 200),
            "supplier_code" => qmsSupplierText($_POST["supplier_code"] ?? "", 60),
            "category" => qmsSupplierText($_POST["category"] ?? "", 100),
            "risk_class" => (string) ($_POST["risk_class"] ?? "medium"),
            "tax_number" => qmsSupplierText($_POST["tax_number"] ?? "", 30),
            "contact_name" => qmsSupplierText($_POST["contact_name"] ?? "", 150),
            "contact_email" => qmsSupplierText($_POST["contact_email"] ?? "", 150),
            "contact_phone" => qmsSupplierText($_POST["contact_phone"] ?? "", 30),
            "city" => qmsSupplierText($_POST["city"] ?? "", 80),
            "status" => (string) ($_POST["status"] ?? "candidate"),
            "notes" => qmsSupplierText($_POST["notes"] ?? "", 4000)
        ];

        if ($formData["name"] === "") {
            $formError = "Lütfen tedarikçi adını girin.";
        } elseif (!in_array($formData["status"], QMS_SUPPLIER_STATUSES, true)) {
            $formError = "Geçerli bir onay durumu seçin.";
        } elseif (!in_array($formData["risk_class"], QMS_SUPPLIER_RISK_CLASSES, true)) {
            $formError = "Geçerli bir risk sınıfı seçin.";
        } elseif ($formData["contact_email"] !== "" && filter_var($formData["contact_email"], FILTER_VALIDATE_EMAIL) === false) {
            $formError = "İletişim e-postası geçerli değil.";
        } else {
            $duplicateCode = false;
            if ($formData["supplier_code"] !== "") {
                $duplicateStmt = $pdo->prepare(
                    "SELECT COUNT(*) FROM suppliers
                     WHERE company_id = :company_id AND supplier_code = :supplier_code
                       AND id <> :id AND active = 1"
                );
                $duplicateStmt->execute([
                    "company_id" => $companyId,
                    "supplier_code" => $formData["supplier_code"],
                    "id" => $supplierId
                ]);
                $duplicateCode = (int) $duplicateStmt->fetchColumn() > 0;
            }

            if ($duplicateCode) {
                $formError = "Bu tedarikçi kodu şirkette zaten kayıtlı.";
            } else {
                // Onay tarihi duruma baglidir: ilk onay tarihi korunur.
                $approvedDate = $formData["status"] === "approved"
                    ? ($supplier["approved_date"] ?: date("Y-m-d"))
                    : null;

                $updateStmt = $pdo->prepare(
                    "UPDATE suppliers
                     SET name = :name,
                         supplier_code = :supplier_code,
                         category = :category,
                         risk_class = :risk_class,
                         tax_number = :tax_number,
                         contact_name = :contact_name,
                         contact_email = :contact_email,
                         contact_phone = :contact_phone,
                         city = :city,
                         status = :status,
                         approved_date = :approved_date,
                         notes = :notes,
                         updated_by = :updated_by
                     WHERE id = :id"
                );
                $updateStmt->execute([
                    "name" => $formData["name"],
                    "supplier_code" => $formData["supplier_code"] !== "" ? $formData["supplier_code"] : null,
                    "category" => $formData["category"] !== "" ? $formData["category"] : null,
                    "risk_class" => $formData["risk_class"],
                    "tax_number" => $formData["tax_number"] !== "" ? $formData["tax_number"] : null,
                    "contact_name" => $formData["contact_name"] !== "" ? $formData["contact_name"] : null,
                    "contact_email" => $formData["contact_email"] !== "" ? $formData["contact_email"] : null,
                    "contact_phone" => $formData["contact_phone"] !== "" ? $formData["contact_phone"] : null,
                    "city" => $formData["city"] !== "" ? $formData["city"] : null,
                    "status" => $formData["status"],
                    "approved_date" => $approvedDate,
                    "notes" => $formData["notes"] !== "" ? $formData["notes"] : null,
                    "updated_by" => $userId ?: null,
                    "id" => $supplierId
                ]);

                // Onay ve askiya alma kararlari sirketin sistem adminlerine bildirilir.
                qmsSupplierNotifyStatusChange($pdo, [
                    "company_id" => $companyId,
                    "name" => $formData["name"],
                    "link" => "supplier-detail.php?id=" . $supplierId,
                    "previous_status" => (string) $supplier["status"],
                    "new_status" => $formData["status"],
                    "actor_user_id" => $userId
                ]);

                header("Location: " . $redirect . "&updated=1");
                exit;
            }
        }
    } elseif ($formType === "add_evaluation") {
        $evaluationData = [
            "evaluated_on" => trim((string) ($_POST["evaluated_on"] ?? "")),
            "quality_score" => trim((string) ($_POST["quality_score"] ?? "")),
            "delivery_score" => trim((string) ($_POST["delivery_score"] ?? "")),
            "service_score" => trim((string) ($_POST["service_score"] ?? "")),
            "result" => (string) ($_POST["result"] ?? "acceptable"),
            "evaluator_name" => qmsSupplierText($_POST["evaluator_name"] ?? "", 150),
            "note" => qmsSupplierText($_POST["note"] ?? "", 255)
        ];

        $evaluationError = qmsSupplierEvaluationError($evaluationData);
        if ($evaluationError !== "") {
            $formError = $evaluationError;
        } else {
            $insertStmt = $pdo->prepare(
                "INSERT INTO supplier_evaluations
                    (supplier_id, evaluated_on, quality_score, delivery_score, service_score,
                     result, evaluator_name, evaluator_user_id, note)
                 VALUES
                    (:supplier_id, :evaluated_on, :quality_score, :delivery_score, :service_score,
                     :result, :evaluator_name, :evaluator_user_id, :note)"
            );
            $insertStmt->execute([
                "supplier_id" => $supplierId,
                "evaluated_on" => $evaluationData["evaluated_on"],
                "quality_score" => qmsSupplierScore($evaluationData["quality_score"]),
                "delivery_score" => qmsSupplierScore($evaluationData["delivery_score"]),
                "service_score" => qmsSupplierScore($evaluationData["service_score"]),
                "result" => $evaluationData["result"],
                "evaluator_name" => $evaluationData["evaluator_name"] !== "" ? $evaluationData["evaluator_name"] : null,
                "evaluator_user_id" => $userId ?: null,
                "note" => $evaluationData["note"] !== "" ? $evaluationData["note"] : null
            ]);

            qmsSupplierNotifyEvaluation($pdo, [
                "company_id" => $companyId,
                "name" => $supplier["name"],
                "link" => "supplier-detail.php?id=" . $supplierId,
                "result" => $evaluationData["result"],
                "actor_user_id" => $userId
            ]);

            header("Location: " . $redirect . "&evaluation=added");
            exit;
        }
    } elseif ($formType === "update_evaluation" || $formType === "remove_evaluation") {
        $evaluationId = (int) ($_POST["evaluation_id"] ?? 0);
        $evaluation = qmsSupplierEvaluationFind($pdo, $evaluationId, $userId, $role);

        if (!$evaluation || (int) $evaluation["supplier_id"] !== $supplierId) {
            $formError = "Değerlendirme kaydı bulunamadı.";
        } elseif ($formType === "remove_evaluation") {
            $pdo->prepare("DELETE FROM supplier_evaluations WHERE id = :id")->execute(["id" => $evaluationId]);

            header("Location: " . $redirect . "&evaluation=removed");
            exit;
        } else {
            $evaluationData = [
                "evaluated_on" => trim((string) ($_POST["evaluated_on"] ?? "")),
                "quality_score" => trim((string) ($_POST["quality_score"] ?? "")),
                "delivery_score" => trim((string) ($_POST["delivery_score"] ?? "")),
                "service_score" => trim((string) ($_POST["service_score"] ?? "")),
                "result" => (string) ($_POST["result"] ?? "acceptable"),
                "evaluator_name" => qmsSupplierText($_POST["evaluator_name"] ?? "", 150),
                "note" => qmsSupplierText($_POST["note"] ?? "", 255)
            ];

            $evaluationError = qmsSupplierEvaluationError($evaluationData);
            if ($evaluationError !== "") {
                $formError = $evaluationError;
            } else {
                $updateEvaluationStmt = $pdo->prepare(
                    "UPDATE supplier_evaluations
                     SET evaluated_on = :evaluated_on,
                         quality_score = :quality_score,
                         delivery_score = :delivery_score,
                         service_score = :service_score,
                         result = :result,
                         evaluator_name = :evaluator_name,
                         note = :note
                     WHERE id = :id"
                );
                $updateEvaluationStmt->execute([
                    "evaluated_on" => $evaluationData["evaluated_on"],
                    "quality_score" => qmsSupplierScore($evaluationData["quality_score"]),
                    "delivery_score" => qmsSupplierScore($evaluationData["delivery_score"]),
                    "service_score" => qmsSupplierScore($evaluationData["service_score"]),
                    "result" => $evaluationData["result"],
                    "evaluator_name" => $evaluationData["evaluator_name"] !== "" ? $evaluationData["evaluator_name"] : null,
                    "note" => $evaluationData["note"] !== "" ? $evaluationData["note"] : null,
                    "id" => $evaluationId
                ]);

                header("Location: " . $redirect . "&evaluation=updated");
                exit;
            }
        }
    } else {
        $formError = "Geçersiz istek.";
    }

    // Kaydetme basarisizsa kaydin guncel halini yeniden oku.
    $supplier = qmsSupplierFind($pdo, $supplierId, $userId, $role);
}

$evaluations = qmsSupplierEvaluations($pdo, $supplierId);
$evaluationSummary = qmsSupplierEvaluationSummary($evaluations);

$activeNav = "suppliers";

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f9fafb">
    <title>QMS Tedarikçi Detayı</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/qms-icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="has-sidebar">
    <?php require __DIR__ . '/includes/app-sidebar.php'; ?>
    <header class="topbar">
        <div class="topbar-inner">
            <div class="page-title-block">
                <strong data-i18n="supplierDetailTitle">Tedarikçi Detayı</strong>
                <span><?= htmlspecialchars($supplier["company_name"], ENT_QUOTES, "UTF-8") ?> · <?= htmlspecialchars($supplier["name"], ENT_QUOTES, "UTF-8") ?></span>
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
                <span class="section-kicker" data-i18n="supplierWorkspaceKicker">Tedarikçi Çalışma Alanı</span>
                <h1 data-i18n="supplierDetailTitle">Tedarikçi Detayı</h1>
                <p><?= htmlspecialchars($supplier["name"], ENT_QUOTES, "UTF-8") ?><?= $supplier["category"] ? " · " . htmlspecialchars($supplier["category"], ENT_QUOTES, "UTF-8") : "" ?></p>
            </div>
            <a class="secondary-button" href="suppliers.php" data-i18n="backToSuppliersButton">Tedarikçilere Dön</a>
        </section>

        <section class="dashboard-grid compact-dashboard-grid">
            <div class="dashboard-card metric-blue">
                <?= appIcon("suppliers", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="supplierStatusLabel">Onay Durumu</span>
                    <strong class="dashboard-card-number detail-card-value" data-i18n="<?= $statusI18n[$supplier["status"]] ?? "" ?>"><?= htmlspecialchars($statusLabels[$supplier["status"]] ?? $supplier["status"], ENT_QUOTES, "UTF-8") ?></strong>
                </div>
            </div>
            <div class="dashboard-card metric-orange">
                <?= appIcon("warning", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="supplierRiskClassLabel">Risk Sınıfı</span>
                    <strong class="dashboard-card-number detail-card-value" data-i18n="<?= $riskI18n[$supplier["risk_class"]] ?? "" ?>"><?= htmlspecialchars($riskLabels[$supplier["risk_class"]] ?? $supplier["risk_class"], ENT_QUOTES, "UTF-8") ?></strong>
                </div>
            </div>
            <div class="dashboard-card metric-violet">
                <?= appIcon("trend", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="supplierAverageScoreLabel">Ortalama Puan</span>
                    <strong class="dashboard-card-number detail-card-value"><?= $evaluationSummary["average"] === null ? '<span data-i18n="supplierNotEvaluatedValue">Değerlendirilmedi</span>' : htmlspecialchars((string) $evaluationSummary["average"], ENT_QUOTES, "UTF-8") ?></strong>
                </div>
            </div>
            <div class="dashboard-card metric-teal">
                <?= appIcon("clock", "dashboard-card-icon") ?>
                <div class="dashboard-card-content">
                    <span class="dashboard-card-label" data-i18n="supplierLastEvaluationLabel">Son Değerlendirme</span>
                    <strong class="dashboard-card-number detail-card-value"><?= htmlspecialchars($evaluationSummary["latest_date"] ?: "-", ENT_QUOTES, "UTF-8") ?></strong>
                </div>
            </div>
        </section>

        <section class="form-panel">
            <?php if (($_GET["created"] ?? "") === "1"): ?>
                <div class="form-message success" data-i18n="supplierCreatedMessage">Tedarikçi kaydı oluşturuldu.</div>
            <?php endif; ?>
            <?php if (($_GET["updated"] ?? "") === "1"): ?>
                <div class="form-message success" data-i18n="supplierUpdatedMessage">Tedarikçi kaydı güncellendi.</div>
            <?php endif; ?>
            <?php if (($_GET["evaluation"] ?? "") === "added"): ?>
                <div class="form-message success" data-i18n="supplierEvaluationAddedMessage">Değerlendirme eklendi.</div>
            <?php elseif (($_GET["evaluation"] ?? "") === "updated"): ?>
                <div class="form-message success" data-i18n="supplierEvaluationUpdatedMessage">Değerlendirme güncellendi.</div>
            <?php elseif (($_GET["evaluation"] ?? "") === "removed"): ?>
                <div class="form-message success" data-i18n="supplierEvaluationRemovedMessage">Değerlendirme kaldırıldı.</div>
            <?php endif; ?>
            <?php if ($formError !== ""): ?>
                <div class="form-message error"><?= htmlspecialchars($formError, ENT_QUOTES, "UTF-8") ?></div>
            <?php endif; ?>

            <form class="auditor-form" method="post" action="supplier-detail.php?id=<?= $supplierId ?>">
                <?= qmsCsrfField($csrfScope) ?>
                <input type="hidden" name="form_type" value="update_supplier">
                <input type="hidden" name="supplier_id" value="<?= $supplierId ?>">
                <div class="form-grid">
                    <label class="form-field">
                        <span data-i18n="companySelectLabel">Şirket</span>
                        <input type="text" value="<?= htmlspecialchars($supplier["company_name"], ENT_QUOTES, "UTF-8") ?>" readonly>
                    </label>
                    <label class="form-field">
                        <span data-i18n="supplierNameLabel">Tedarikçi Adı</span>
                        <input type="text" name="name" maxlength="200" value="<?= htmlspecialchars($supplier["name"], ENT_QUOTES, "UTF-8") ?>" required>
                    </label>
                    <label class="form-field">
                        <span data-i18n="supplierCodeLabel">Tedarikçi Kodu</span>
                        <input type="text" name="supplier_code" maxlength="60" value="<?= htmlspecialchars((string) ($supplier["supplier_code"] ?? ""), ENT_QUOTES, "UTF-8") ?>">
                    </label>
                    <label class="form-field">
                        <span data-i18n="supplierCategoryLabel">Kategori</span>
                        <input type="text" name="category" maxlength="100" value="<?= htmlspecialchars((string) ($supplier["category"] ?? ""), ENT_QUOTES, "UTF-8") ?>">
                    </label>
                    <label class="form-field">
                        <span data-i18n="supplierRiskClassLabel">Risk Sınıfı</span>
                        <select name="risk_class">
                            <?php foreach ($riskLabels as $value => $label): ?>
                                <option value="<?= $value ?>" <?= $supplier["risk_class"] === $value ? "selected" : "" ?> data-i18n="<?= $riskI18n[$value] ?>"><?= htmlspecialchars($label, ENT_QUOTES, "UTF-8") ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="form-field">
                        <span data-i18n="supplierStatusLabel">Onay Durumu</span>
                        <select name="status">
                            <?php foreach ($statusLabels as $value => $label): ?>
                                <option value="<?= $value ?>" <?= $supplier["status"] === $value ? "selected" : "" ?> data-i18n="<?= $statusI18n[$value] ?>"><?= htmlspecialchars($label, ENT_QUOTES, "UTF-8") ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="form-field">
                        <span data-i18n="supplierApprovedDateLabel">Onay Tarihi</span>
                        <input type="text" value="<?= htmlspecialchars((string) ($supplier["approved_date"] ?: "-"), ENT_QUOTES, "UTF-8") ?>" readonly>
                        <small data-i18n="supplierApprovedDateHelp">Durum "Onaylı" olduğunda otomatik yazılır.</small>
                    </label>
                    <label class="form-field">
                        <span data-i18n="supplierTaxNumberLabel">Vergi No</span>
                        <input type="text" name="tax_number" maxlength="30" value="<?= htmlspecialchars((string) ($supplier["tax_number"] ?? ""), ENT_QUOTES, "UTF-8") ?>">
                    </label>
                    <label class="form-field">
                        <span data-i18n="supplierContactNameLabel">İlgili Kişi</span>
                        <input type="text" name="contact_name" maxlength="150" value="<?= htmlspecialchars((string) ($supplier["contact_name"] ?? ""), ENT_QUOTES, "UTF-8") ?>">
                    </label>
                    <label class="form-field">
                        <span data-i18n="supplierContactEmailLabel">İletişim E-postası</span>
                        <input type="email" name="contact_email" maxlength="150" value="<?= htmlspecialchars((string) ($supplier["contact_email"] ?? ""), ENT_QUOTES, "UTF-8") ?>">
                    </label>
                    <label class="form-field">
                        <span data-i18n="supplierContactPhoneLabel">Telefon</span>
                        <input type="text" name="contact_phone" maxlength="30" value="<?= htmlspecialchars((string) ($supplier["contact_phone"] ?? ""), ENT_QUOTES, "UTF-8") ?>">
                    </label>
                    <label class="form-field">
                        <span data-i18n="supplierCityLabel">Şehir</span>
                        <input type="text" name="city" maxlength="80" value="<?= htmlspecialchars((string) ($supplier["city"] ?? ""), ENT_QUOTES, "UTF-8") ?>">
                    </label>
                    <label class="form-field form-field-wide">
                        <span data-i18n="supplierNotesLabel">Notlar</span>
                        <textarea name="notes" rows="4"><?= htmlspecialchars((string) ($supplier["notes"] ?? ""), ENT_QUOTES, "UTF-8") ?></textarea>
                    </label>
                </div>
                <div class="form-actions">
                    <button class="primary-button" type="submit" data-i18n="saveSupplierButton">Tedarikçiyi Kaydet</button>
                </div>
            </form>
        </section>

        <section class="console-card checklist-section">
            <div class="section-heading compact-heading">
                <div>
                    <h3 data-i18n="supplierEvaluationsTitle">Değerlendirmeler</h3>
                    <p data-i18n="supplierEvaluationsText">Kalite, teslimat ve hizmet puanları; toplam puan girilen puanların ortalamasıdır.</p>
                </div>
            </div>

            <div class="admin-list">
                <?php if (!$evaluations): ?>
                    <div class="empty-state" data-i18n="noSupplierEvaluationsText">Bu tedarikçi için değerlendirme girilmedi.</div>
                <?php endif; ?>

                <?php foreach ($evaluations as $evaluation): $evaluationTotal = qmsSupplierEvaluationTotal($evaluation); ?>
                    <form class="checklist-item-form" method="post" action="supplier-detail.php?id=<?= $supplierId ?>">
                        <?= qmsCsrfField($csrfScope) ?>
                        <input type="hidden" name="supplier_id" value="<?= $supplierId ?>">
                        <input type="hidden" name="evaluation_id" value="<?= (int) $evaluation["id"] ?>">
                        <div class="checklist-item-heading">
                            <div>
                                <strong><?= htmlspecialchars($evaluation["evaluated_on"], ENT_QUOTES, "UTF-8") ?> · <?= htmlspecialchars($evaluation["evaluator_name"] ?: (string) ($evaluation["evaluator_full_name"] ?? "-"), ENT_QUOTES, "UTF-8") ?></strong>
                                <span><span data-i18n="supplierEvaluationTotalLabel">Toplam Puan</span>: <?= $evaluationTotal === null ? "-" : htmlspecialchars((string) $evaluationTotal, ENT_QUOTES, "UTF-8") ?></span>
                            </div>
                            <span class="status-pill" data-i18n="<?= $resultI18n[$evaluation["result"]] ?? "" ?>"><?= htmlspecialchars($resultLabels[$evaluation["result"]] ?? $evaluation["result"], ENT_QUOTES, "UTF-8") ?></span>
                        </div>
                        <div class="form-grid checklist-edit-grid">
                            <label class="form-field">
                                <span data-i18n="supplierEvaluationDateLabel">Değerlendirme Tarihi</span>
                                <input type="date" name="evaluated_on" value="<?= htmlspecialchars($evaluation["evaluated_on"], ENT_QUOTES, "UTF-8") ?>" required>
                            </label>
                            <label class="form-field">
                                <span data-i18n="supplierQualityScoreLabel">Kalite Puanı</span>
                                <input type="number" name="quality_score" min="0" max="100" step="0.01" value="<?= htmlspecialchars((string) ($evaluation["quality_score"] ?? ""), ENT_QUOTES, "UTF-8") ?>">
                            </label>
                            <label class="form-field">
                                <span data-i18n="supplierDeliveryScoreLabel">Teslimat Puanı</span>
                                <input type="number" name="delivery_score" min="0" max="100" step="0.01" value="<?= htmlspecialchars((string) ($evaluation["delivery_score"] ?? ""), ENT_QUOTES, "UTF-8") ?>">
                            </label>
                            <label class="form-field">
                                <span data-i18n="supplierServiceScoreLabel">Hizmet Puanı</span>
                                <input type="number" name="service_score" min="0" max="100" step="0.01" value="<?= htmlspecialchars((string) ($evaluation["service_score"] ?? ""), ENT_QUOTES, "UTF-8") ?>">
                            </label>
                            <label class="form-field">
                                <span data-i18n="supplierEvaluationResultLabel">Karar</span>
                                <select name="result">
                                    <?php foreach ($resultLabels as $value => $label): ?>
                                        <option value="<?= $value ?>" <?= $evaluation["result"] === $value ? "selected" : "" ?> data-i18n="<?= $resultI18n[$value] ?>"><?= htmlspecialchars($label, ENT_QUOTES, "UTF-8") ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="form-field">
                                <span data-i18n="supplierEvaluatorLabel">Değerlendiren</span>
                                <input type="text" name="evaluator_name" maxlength="150" value="<?= htmlspecialchars((string) ($evaluation["evaluator_name"] ?? ""), ENT_QUOTES, "UTF-8") ?>">
                            </label>
                            <label class="form-field form-field-wide">
                                <span data-i18n="supplierEvaluationNoteLabel">Not</span>
                                <input type="text" name="note" maxlength="255" value="<?= htmlspecialchars((string) ($evaluation["note"] ?? ""), ENT_QUOTES, "UTF-8") ?>">
                            </label>
                        </div>
                        <div class="form-actions">
                            <button class="primary-button" type="submit" name="form_type" value="update_evaluation" data-i18n="saveEvaluationButton">Değerlendirmeyi Kaydet</button>
                            <button class="danger-button" type="submit" name="form_type" value="remove_evaluation" data-i18n="removeEvaluationButton">Kaldır</button>
                        </div>
                    </form>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="page-section form-panel">
            <div class="section-heading compact-heading">
                <div>
                    <h3 data-i18n="addEvaluationTitle">Değerlendirme Ekle</h3>
                    <p data-i18n="addEvaluationText">Puanlar 0–100 arasındadır. "Kabul edilemez" kararı şirketin sistem adminlerine bildirilir.</p>
                </div>
            </div>
            <form class="auditor-form" method="post" action="supplier-detail.php?id=<?= $supplierId ?>">
                <?= qmsCsrfField($csrfScope) ?>
                <input type="hidden" name="form_type" value="add_evaluation">
                <input type="hidden" name="supplier_id" value="<?= $supplierId ?>">
                <div class="form-grid">
                    <label class="form-field">
                        <span data-i18n="supplierEvaluationDateLabel">Değerlendirme Tarihi</span>
                        <input type="date" name="evaluated_on" value="<?= htmlspecialchars(date("Y-m-d"), ENT_QUOTES, "UTF-8") ?>" required>
                    </label>
                    <label class="form-field">
                        <span data-i18n="supplierQualityScoreLabel">Kalite Puanı</span>
                        <input type="number" name="quality_score" min="0" max="100" step="0.01">
                    </label>
                    <label class="form-field">
                        <span data-i18n="supplierDeliveryScoreLabel">Teslimat Puanı</span>
                        <input type="number" name="delivery_score" min="0" max="100" step="0.01">
                    </label>
                    <label class="form-field">
                        <span data-i18n="supplierServiceScoreLabel">Hizmet Puanı</span>
                        <input type="number" name="service_score" min="0" max="100" step="0.01">
                    </label>
                    <label class="form-field">
                        <span data-i18n="supplierEvaluationResultLabel">Karar</span>
                        <select name="result">
                            <?php foreach ($resultLabels as $value => $label): ?>
                                <option value="<?= $value ?>" data-i18n="<?= $resultI18n[$value] ?>"><?= htmlspecialchars($label, ENT_QUOTES, "UTF-8") ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="form-field">
                        <span data-i18n="supplierEvaluatorLabel">Değerlendiren</span>
                        <input type="text" name="evaluator_name" maxlength="150">
                    </label>
                    <label class="form-field form-field-wide">
                        <span data-i18n="supplierEvaluationNoteLabel">Not</span>
                        <input type="text" name="note" maxlength="255">
                    </label>
                </div>
                <div class="form-actions">
                    <button class="primary-button" type="submit" data-i18n="addEvaluationButton">Değerlendirme Ekle</button>
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
