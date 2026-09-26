<?php
// Header bildirim paneli icin hafif AJAX uc noktasi. Yalniz POST kabul eder ve
// JSON doner (tam sayfa yonlendirmesi yerine). CSRF 'notifications' scope ile
// dogrulanir; app-sidebar bu token'i gizli alan olarak basar.
session_start();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'POST gerekli']);
    exit;
}

if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'oturum yok']);
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/csrf.php';

$userId = (int) ($_SESSION["qms_user_id"] ?? 0);

header('Content-Type: application/json');

try {
    qmsCsrfVerify('notifications', $_POST['csrf'] ?? null);
} catch (Throwable $e) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'csrf']);
    exit;
}

$formType = (string) ($_POST['form_type'] ?? '');

if ($formType === 'mark_all_read') {
    $pdo->prepare(
        "UPDATE notifications SET is_read = 1, read_at = COALESCE(read_at, NOW())
         WHERE user_id = :user_id AND is_read = 0"
    )->execute(['user_id' => $userId]);
    $unread = (int) $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = :user_id AND is_read = 0");
    $unread->execute(['user_id' => $userId]);
    echo json_encode(['ok' => true, 'unread' => (int) $unread->fetchColumn()]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'bilinmeyen islem']);
