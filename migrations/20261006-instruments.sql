CREATE TABLE instruments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    instrument_code VARCHAR(40) NULL,
    name VARCHAR(190) NOT NULL,
    instrument_type VARCHAR(120) NULL,
    location VARCHAR(190) NULL,
    interval_months INT NOT NULL DEFAULT 12,
    last_calibration_date DATE NULL,
    next_calibration_date DATE NULL,
    responsible VARCHAR(180) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    notes TEXT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_instruments_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
