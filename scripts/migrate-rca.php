<?php
/**
 * Kök Neden Analizi (RCA) tablosu.
 *
 * Olay / uygunsuzluk / iyileştirme fırsati için yapilandirilmis kök neden
 * oturumu: 5-Neden (JSON), kök neden, önerilen düzeltici faaliyet ve sorumlu.
 * Kaynak kayda (nonconformity / incident / improvement) baglanir; CAPA'nin
 * açilması uygunsuzluk kaynaginda desteklenir.
 */
declare(strict_types=1);

require __DIR__ . '/../config/database.php';

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS rca_analyses (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        company_id INT UNSIGNED NOT NULL,
        source_type VARCHAR(20) NOT NULL DEFAULT 'nonconformity',
        source_id INT UNSIGNED NULL,
        title VARCHAR(255) NOT NULL,
        description TEXT NULL,
        five_why TEXT NULL,
        root_cause TEXT NULL,
        corrective_action_text TEXT NULL,
        owner VARCHAR(180) NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'draft',
        created_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        active TINYINT(1) NOT NULL DEFAULT 1,
        KEY idx_rca_company (company_id),
        KEY idx_rca_source (source_type, source_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

echo "rca_analyses tablosu hazir." . PHP_EOL;
