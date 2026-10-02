<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/config/database.php';

$additions = [
    'logo_file'           => "VARCHAR(255) DEFAULT NULL",
    'brand_name'          => "VARCHAR(160) DEFAULT NULL",
    'brand_slogan'        => "VARCHAR(255) DEFAULT NULL",
    'brand_description'   => "TEXT DEFAULT NULL",
    'website'             => "VARCHAR(255) DEFAULT NULL",
    'phone'               => "VARCHAR(60) DEFAULT NULL",
    'address'             => "VARCHAR(255) DEFAULT NULL",
    'contact_person'      => "VARCHAR(160) DEFAULT NULL",
];

$existing = array_map('strtolower', $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='companies'")->fetchAll(PDO::FETCH_COLUMN));

foreach ($additions as $col => $def) {
    if (in_array($col, $existing, true)) { echo "exists: $col\n"; continue; }
    $pdo->exec("ALTER TABLE companies ADD COLUMN `$col` $def");
    echo "added: $col\n";
}

$dir = dirname(__DIR__) . '/storage/company-logos';
if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
echo "Company profile columns ready. Logo dir ensured.\n";
