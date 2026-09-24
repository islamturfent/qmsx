<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/access.php';

$versionId = (int) ($_GET["id"] ?? 0);
$userId = (int) ($_SESSION["qms_user_id"] ?? 0);
$isSuperAdmin = ($_SESSION["qms_role"] ?? "") === "super_admin";

$sql = "SELECT document_versions.*, documents.company_id
        FROM document_versions INNER JOIN documents ON documents.id = document_versions.document_id
        WHERE document_versions.id = ? AND documents.active = 1";
$params = [$versionId];
$scope = qmsCompanyScope('documents.company_id', qmsVisibleCompanyIds($pdo, $userId, qmsCurrentRole()));
$sql .= $scope['sql'];
$params = array_merge($params, $scope['params']);
$sql .= " LIMIT 1";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$version = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$version) {
    http_response_code(404);
    exit("Dosya bulunamadı.");
}

$filePath = __DIR__ . DIRECTORY_SEPARATOR . "storage" . DIRECTORY_SEPARATOR . "documents" . DIRECTORY_SEPARATOR . basename($version["stored_file_name"]);
if (!is_file($filePath)) {
    http_response_code(404);
    exit("Dosya bulunamadı.");
}

$downloadName = str_replace(["\r", "\n", '"'], "", $version["original_file_name"]);
header("Content-Type: " . $version["mime_type"]);
header("Content-Length: " . filesize($filePath));
header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($downloadName));
header("X-Content-Type-Options: nosniff");
readfile($filePath);
exit;
