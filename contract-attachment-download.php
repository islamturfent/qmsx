<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/access.php';
require_once __DIR__ . '/includes/contract-functions.php';

$userId = (int) ($_SESSION["qms_user_id"] ?? 0);
$role = qmsCurrentRole();
if (qmsIsAuditor()) {
    header("Location: my-audits.php");
    exit;
}

$att = qmsContractAttachmentFind($pdo, (int) ($_GET['id'] ?? 0), $userId, $role);
if (!$att) {
    http_response_code(404);
    exit('Dosya bulunamadı.');
}

$path = __DIR__ . '/storage/contracts/' . $att['stored_name'];
if (!is_file($path)) {
    http_response_code(404);
    exit('Dosya depoda mevcut değil.');
}

header('Content-Type: ' . ($att['mime_type'] ?: 'application/octet-stream'));
header('Content-Disposition: attachment; filename="' . rawurlencode((string) $att['original_name']) . '"');
header('Content-Length: ' . (string) (int) $att['file_size']);
header('Cache-Control: private, no-store, max-age=0');
readfile($path);
exit;
