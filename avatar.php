<?php

session_start();

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/config/database.php';

$userId = (int) ($_SESSION["qms_user_id"] ?? 0);

// Yalnizca oturum sahibinin kendi avatarini servis eder; id parametresi yok,
// boylece baska hesaplarin dosyalarina erisilemez.
$stmt = $pdo->prepare("SELECT avatar_file FROM users WHERE id = :id AND active = 1 LIMIT 1");
$stmt->execute(["id" => $userId]);
$avatarFile = $stmt->fetchColumn();

if (!is_string($avatarFile) || $avatarFile === '') {
    http_response_code(404);
    exit;
}

$path = __DIR__ . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'avatars' . DIRECTORY_SEPARATOR . basename($avatarFile);
if (!is_file($path)) {
    http_response_code(404);
    exit;
}

$extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$mimeTypes = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
$mimeType = $mimeTypes[$extension] ?? 'application/octet-stream';

header('Content-Type: ' . $mimeType);
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=300');
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;
