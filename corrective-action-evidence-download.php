<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/access.php';

$evidenceId = (int) ($_GET["id"] ?? 0);
$userId = (int) ($_SESSION["qms_user_id"] ?? 0);
$isSuperAdmin = ($_SESSION["qms_role"] ?? "") === "super_admin";

$sql = "SELECT corrective_action_evidence.*, nonconformities.company_id
        FROM corrective_action_evidence
        INNER JOIN corrective_actions ON corrective_actions.id = corrective_action_evidence.corrective_action_id
        INNER JOIN nonconformities ON nonconformities.id = corrective_actions.nonconformity_id
        WHERE corrective_action_evidence.id = ?
          AND corrective_action_evidence.active = 1
          AND corrective_actions.active = 1";
$params = [$evidenceId];
$scope = qmsCompanyScope('nonconformities.company_id', qmsVisibleCompanyIds($pdo, $userId, qmsCurrentRole()));
$sql .= $scope['sql'];
$params = array_merge($params, $scope['params']);
$sql .= " LIMIT 1";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$evidence = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$evidence) {
    http_response_code(404);
    exit("Kanıt dosyası bulunamadı.");
}

$filePath = __DIR__ . DIRECTORY_SEPARATOR . "storage" . DIRECTORY_SEPARATOR . "evidence" . DIRECTORY_SEPARATOR . basename($evidence["stored_file_name"]);
if (!is_file($filePath)) {
    http_response_code(404);
    exit("Kanıt dosyası bulunamadı.");
}

$downloadName = str_replace(["\r", "\n", '"'], "", $evidence["original_file_name"]);
header("Content-Type: " . $evidence["mime_type"]);
header("Content-Length: " . filesize($filePath));
header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($downloadName));
header("X-Content-Type-Options: nosniff");
readfile($filePath);
exit;
