<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/capa-functions.php';

$evidenceId = (int) ($_GET["id"] ?? 0);
$userId = (int) ($_SESSION["qms_user_id"] ?? 0);

// Kapsamli okuma: baska sirketin kaniti indirilemez.
$evidence = qmsCapaEvidenceFind($pdo, $evidenceId, $userId, qmsCurrentRole());

if (!$evidence) {
    http_response_code(404);
    exit("Kanıt dosyası bulunamadı.");
}

$filePath = qmsCapaEvidencePath($evidence["stored_file_name"]);
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
