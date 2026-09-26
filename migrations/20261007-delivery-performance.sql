CREATE TABLE delivery_performance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    customer_name VARCHAR(190) NOT NULL,
    period VARCHAR(7) NOT NULL,
    orders_total INT NOT NULL DEFAULT 0,
    on_time_orders INT NOT NULL DEFAULT 0,
    quantity_delivered INT NOT NULL DEFAULT 0,
    quantity_rejected INT NOT NULL DEFAULT 0,
    notes TEXT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_delivery_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
