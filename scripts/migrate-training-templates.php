<?php
/**
 * Eğitim Şablonları tablosu.
 *
 * Eğitim Şablonları, yeniden kullanılabilir eğitim tanımlari kütüphanesidir
 * (başlık, kategori, varsayilan sure, hedef yetkinlik, içerik). Bir şablondan
 * gercek eğitim kaydi (trainings) olusturulabilir.
 */
declare(strict_types=1);

require __DIR__ . '/../config/database.php';

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS training_templates (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        company_id INT UNSIGNED NOT NULL,
        title VARCHAR(255) NOT NULL,
        category VARCHAR(120) NOT NULL DEFAULT '',
        default_duration_hours DECIMAL(6,2) NOT NULL DEFAULT 0,
        target_competency VARCHAR(120) NOT NULL DEFAULT '',
        description TEXT NULL,
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_training_templates_company (company_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

echo "training_templates tablosu hazir." . PHP_EOL;
