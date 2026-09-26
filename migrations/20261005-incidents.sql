CREATE TABLE incidents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    incident_code VARCHAR(40) NULL,
    title VARCHAR(190) NOT NULL,
    description TEXT NULL,
    location VARCHAR(190) NULL,
    incident_type VARCHAR(24) NOT NULL DEFAULT 'other',
    severity VARCHAR(20) NOT NULL DEFAULT 'medium',
    reported_at DATE NULL,
    responsible VARCHAR(180) NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'open',
    notes TEXT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_incidents_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
