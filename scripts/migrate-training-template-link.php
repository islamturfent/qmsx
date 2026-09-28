<?php
/**
 * Eğitim Şablonu -> Eğitim kaydı bağlantısı.
 *
 * `trainings` tablosuna seçimlik `template_id` (kaynak şablon) ve eğitimin
 * taşıdığı `target_competency` (hedef yetkinlik adı) sütunlarını ekler.
 * Şablondan türetilen eğitimler bu sütunları doldurur.
 */
declare(strict_types=1);

require __DIR__ . '/../config/database.php';

$cols = $pdo->query('SHOW COLUMNS FROM trainings')->fetchAll(PDO::FETCH_COLUMN);

if (!in_array('template_id', $cols, true)) {
    $pdo->exec('ALTER TABLE trainings ADD COLUMN template_id INT UNSIGNED NULL AFTER category');
}
if (!in_array('target_competency', $cols, true)) {
    $pdo->exec("ALTER TABLE trainings ADD COLUMN target_competency VARCHAR(120) NOT NULL DEFAULT '' AFTER template_id");
}

echo "trainings.template_id / target_competency eklendi." . PHP_EOL;
