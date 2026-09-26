CREATE TABLE processes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    process_code VARCHAR(40) NULL,
    process_name VARCHAR(190) NOT NULL,
    department VARCHAR(120) NULL,
    owner_name VARCHAR(180) NULL,
    objective TEXT NULL,
    inputs TEXT NULL,
    outputs TEXT NULL,
    kpi VARCHAR(500) NULL,
    review_date DATE NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_processes_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
