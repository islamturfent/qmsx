<?php

session_start();
if (!isset($_SESSION["qms_logged_in"]) || $_SESSION["qms_logged_in"] !== true) {
    exit('Yetkilendirme gerekli.');
}
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/language-functions.php';

// Dil veri uc noktasi: ?lang=X  -> secili dilin DB cevirileri (JSON)
//                        ?meta=1 -> aktif diller listesi (JSON)
$pdo = $GLOBALS['pdo'];
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

if (isset($_GET['meta']) && $_GET['meta'] === '1') {
    echo json_encode(['languages' => qmsLanguages($pdo, true)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$lang = strtolower(trim((string) ($_GET['lang'] ?? 'tr')));
$base = ['en', 'tr'];
if ($lang === 'en' || $lang === 'tr') {
    // Yerlesik diller: cevirileri istemci sabitinde; JSON bos doner.
    echo json_encode(['lang' => $lang, 'translations' => new stdClass()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
echo json_encode(['lang' => $lang, 'translations' => (object) qmsLanguageTranslations($pdo, $lang)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;
