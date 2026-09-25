-- Denetim izi (audit trail) - Task 2 (guvenlik yuzeyi).
-- Kim/ne/ne zaman/ne degisti bilgisini tutan, salt-ekle (append-only) kayit.
-- Bu tabloya guncelleme/silme yapilmaz; gorunum okunur-yalnizdir.

CREATE TABLE IF NOT EXISTS audit_log (
    id BIGINT NOT NULL AUTO_INCREMENT,
    company_id INT NULL,
    actor_user_id INT NOT NULL,
    entity_type VARCHAR(40) NOT NULL,
    entity_id INT NULL,
    action VARCHAR(30) NOT NULL,
    summary VARCHAR(500) NOT NULL,
    details JSON NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_log_company (company_id),
    KEY idx_audit_log_entity (entity_type, entity_id),
    KEY idx_audit_log_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
