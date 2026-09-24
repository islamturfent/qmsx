-- Tedarikci yonetimi modulu (knowhow Faz 3).
--
-- Onay durumu akisi: aday -> onayli -> askida -> cikarildi.
-- Degerlendirme puanlari ayri tabloda tutulur; tedarikcinin guncel puani ve son
-- degerlendirme tarihi bu kayitlardan hesaplanir, kolonda kopyalanmaz.

CREATE TABLE IF NOT EXISTS suppliers (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    name VARCHAR(200) NOT NULL,
    supplier_code VARCHAR(60) DEFAULT NULL,
    category VARCHAR(100) DEFAULT NULL,
    risk_class VARCHAR(20) NOT NULL DEFAULT 'medium',
    tax_number VARCHAR(30) DEFAULT NULL,
    contact_name VARCHAR(150) DEFAULT NULL,
    contact_email VARCHAR(150) DEFAULT NULL,
    contact_phone VARCHAR(30) DEFAULT NULL,
    city VARCHAR(80) DEFAULT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'candidate',
    approved_date DATE DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    created_by INT DEFAULT NULL,
    updated_by INT DEFAULT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_suppliers_company (company_id),
    INDEX idx_suppliers_status (status),
    INDEX idx_suppliers_risk (risk_class),
    CONSTRAINT fk_suppliers_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    CONSTRAINT fk_suppliers_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_suppliers_updater FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS supplier_evaluations (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    supplier_id INT NOT NULL,
    evaluated_on DATE NOT NULL,
    quality_score DECIMAL(5,2) DEFAULT NULL,
    delivery_score DECIMAL(5,2) DEFAULT NULL,
    service_score DECIMAL(5,2) DEFAULT NULL,
    result VARCHAR(30) NOT NULL DEFAULT 'acceptable',
    evaluator_name VARCHAR(150) DEFAULT NULL,
    evaluator_user_id INT DEFAULT NULL,
    note VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_supplier_evaluations_supplier (supplier_id, evaluated_on),
    CONSTRAINT fk_supplier_evaluations_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE,
    CONSTRAINT fk_supplier_evaluations_user FOREIGN KEY (evaluator_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
