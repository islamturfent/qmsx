-- Performans yonetimi modulu (knowhow Faz 3).
--
-- Sirketen yil bazinda KPI hedefleri tutulur; guncel (gerceklesen) deger
-- rapor motorundan okunur, burada saklanmaz. Tek gecerli KPI hesabi rapor motoru
-- oldugu icin bu tablo sadece hedef ve yil bilgisini tasir.

CREATE TABLE IF NOT EXISTS performance_targets (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    kpi_key VARCHAR(60) NOT NULL,
    target_value DECIMAL(8,2) NOT NULL,
    target_year SMALLINT NOT NULL,
    note VARCHAR(255) DEFAULT NULL,
    created_by INT DEFAULT NULL,
    updated_by INT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_performance_target (company_id, kpi_key, target_year),
    INDEX idx_performance_targets_company (company_id),
    CONSTRAINT fk_performance_targets_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    CONSTRAINT fk_performance_targets_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_performance_targets_updater FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
