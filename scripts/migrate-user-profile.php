<?php
/**
 * Kullanici profili alanlarini ekler.
 *
 * Idempotent calisir: yalnizca eksik kolonlari ekler, mevcut kayitlari
 * degistirmez. Ilk ad/soyad alanlari bos olan hesaplar icin full_name'den
 * tek seferlik bir dolgu yapilir (full_name korunur).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/config/database.php';

$columns = [
    'first_name' => 'VARCHAR(100) DEFAULT NULL',
    'last_name' => 'VARCHAR(100) DEFAULT NULL',
    'email' => 'VARCHAR(150) DEFAULT NULL',
    'phone' => 'VARCHAR(30) DEFAULT NULL',
    'position_title' => 'VARCHAR(150) DEFAULT NULL',
    'bio' => 'TEXT DEFAULT NULL',
    'social_facebook' => 'VARCHAR(255) DEFAULT NULL',
    'social_x' => 'VARCHAR(255) DEFAULT NULL',
    'social_linkedin' => 'VARCHAR(255) DEFAULT NULL',
    'social_instagram' => 'VARCHAR(255) DEFAULT NULL',
    'country' => 'VARCHAR(100) DEFAULT NULL',
    'city' => 'VARCHAR(150) DEFAULT NULL',
    'postal_code' => 'VARCHAR(30) DEFAULT NULL',
    'tax_id' => 'VARCHAR(50) DEFAULT NULL',
    'avatar_file' => 'VARCHAR(255) DEFAULT NULL'
];

$existing = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'")
    ->fetchAll(PDO::FETCH_COLUMN);

$added = [];
foreach ($columns as $name => $definition) {
    if (in_array($name, $existing, true)) {
        continue;
    }
    $pdo->exec("ALTER TABLE users ADD COLUMN $name $definition");
    $added[] = $name;
}

// Tek seferlik dolgu: ad/soyad bos ise full_name'den turet (full_name korunur).
$filled = 0;
if (in_array('first_name', $added, true) || in_array('last_name', $added, true)) {
    $filled = $pdo->exec(
        "UPDATE users
         SET first_name = SUBSTRING_INDEX(TRIM(full_name), ' ', 1),
             last_name = NULLIF(TRIM(SUBSTRING(TRIM(full_name), LENGTH(SUBSTRING_INDEX(TRIM(full_name), ' ', 1)) + 1)), '')
         WHERE (first_name IS NULL OR first_name = '') AND full_name <> ''"
    );
}

echo 'Eklenen kolonlar: ' . ($added ? implode(', ', $added) : 'yok (zaten tam)') . PHP_EOL;
echo 'Ad/soyad dolgusu yapilan hesap: ' . (int) $filled . PHP_EOL;
echo "Mevcut kayitlar korundu." . PHP_EOL;
