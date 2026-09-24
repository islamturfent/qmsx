-- Yonetimin gozden gecirmesi modulu (knowhow Faz 3).
--
-- Bir gozden gecirme toplantisi kaydi (management_reviews) ve gundem/karar/
-- aksiyon kalemlerinden (management_review_items) olusur. Degerlendirme girdisi
-- (guncel KPI'lar) rapor motorundan okunur, burada saklanmaz.

CREATE TABLE IF NOT EXISTS management_reviews (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    review_date DATE NOT NULL,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    participants TEXT DEFAULT NULL,
    scope_notes TEXT DEFAULT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'planned',
    next_review_date DATE DEFAULT NULL,
    created_by INT DEFAULT NULL,
    updated_by INT DEFAULT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_management_reviews_company (company_id),
    INDEX idx_management_reviews_status (status),
    INDEX idx_management_reviews_date (review_date),
    CONSTRAINT fk_reviews_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    CONSTRAINT fk_reviews_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_reviews_updater FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS management_review_items (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    review_id INT NOT NULL,
    item_type VARCHAR(30) NOT NULL DEFAULT 'decision',
    topic VARCHAR(255) NOT NULL,
    description TEXT DEFAULT NULL,
    responsible_user_id INT DEFAULT NULL,
    due_date DATE DEFAULT NULL,
    nonconformity_id INT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_review_items_review (review_id),
    INDEX idx_review_items_type (item_type),
    CONSTRAINT fk_review_items_review FOREIGN KEY (review_id) REFERENCES management_reviews(id) ON DELETE CASCADE,
    CONSTRAINT fk_review_items_responsible FOREIGN KEY (responsible_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_review_items_nonconformity FOREIGN KEY (nonconformity_id) REFERENCES nonconformities(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
