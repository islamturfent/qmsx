-- Sikayet yonetimi modulu (knowhow Faz 3).
--
-- Zincir korunur: sikayet kaydi, ayni sirketteki mevcut bir uygunsuzluga
-- baglanabilir (nonconformity_id). Boylece sikayet -> uygunsuzluk -> duzeltici
-- faaliyet izi tek yerden okunur.
--
-- Not: uygunsuzluklar bir denetime bagli olmak zorundadir (nonconformities.audit_id
-- NOT NULL). Sikayetten yeni uygunsuzluk uretmek bu yuzden ayri bir karar gerektirir;
-- simdilik yalnizca var olan kayit baglaniyor.

CREATE TABLE IF NOT EXISTS complaints (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    complaint_code VARCHAR(60) DEFAULT NULL,
    source VARCHAR(30) NOT NULL DEFAULT 'customer',
    channel VARCHAR(30) DEFAULT NULL,
    customer_name VARCHAR(180) DEFAULT NULL,
    customer_contact VARCHAR(180) DEFAULT NULL,
    received_date DATE NOT NULL,
    subject VARCHAR(255) NOT NULL,
    description TEXT DEFAULT NULL,
    severity VARCHAR(30) NOT NULL DEFAULT 'major',
    status VARCHAR(30) NOT NULL DEFAULT 'new',
    responsible_user_id INT DEFAULT NULL,
    responsible_person VARCHAR(150) DEFAULT NULL,
    due_date DATE DEFAULT NULL,
    root_cause TEXT DEFAULT NULL,
    action_note TEXT DEFAULT NULL,
    resolution_note TEXT DEFAULT NULL,
    closed_date DATE DEFAULT NULL,
    nonconformity_id INT DEFAULT NULL,
    created_by INT DEFAULT NULL,
    updated_by INT DEFAULT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_complaints_company (company_id),
    INDEX idx_complaints_status (status),
    INDEX idx_complaints_severity (severity),
    INDEX idx_complaints_received (received_date),
    INDEX idx_complaints_nonconformity (nonconformity_id),
    CONSTRAINT fk_complaints_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    CONSTRAINT fk_complaints_responsible FOREIGN KEY (responsible_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_complaints_nonconformity FOREIGN KEY (nonconformity_id) REFERENCES nonconformities(id) ON DELETE SET NULL,
    CONSTRAINT fk_complaints_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_complaints_updater FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
