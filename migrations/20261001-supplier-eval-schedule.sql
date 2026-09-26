CREATE TABLE supplier_evaluation_schedule (
    id INT AUTO_INCREMENT PRIMARY KEY,
    supplier_id INT NOT NULL,
    company_id INT NOT NULL,
    cycle_label VARCHAR(80) NOT NULL,
    due_date DATE NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'planned',
    result VARCHAR(100) NULL,
    notes TEXT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_sched_supplier (supplier_id),
    KEY idx_sched_company (company_id),
    KEY idx_sched_due (due_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
