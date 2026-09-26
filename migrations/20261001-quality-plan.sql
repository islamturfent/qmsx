CREATE TABLE quality_plans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    plan_year INT NOT NULL,
    title VARCHAR(190) NOT NULL,
    description TEXT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_company_year (company_id, plan_year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE quality_plan_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    plan_id INT NOT NULL,
    category VARCHAR(120) NULL,
    objective VARCHAR(500) NOT NULL,
    target VARCHAR(500) NULL,
    responsible VARCHAR(180) NULL,
    due_date DATE NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'not_started',
    progress INT NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    KEY idx_items_plan (plan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
