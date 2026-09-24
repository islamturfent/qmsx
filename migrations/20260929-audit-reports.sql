-- Denetim raporu taslaklari - Task 1 (denetim raporu yuzeyi).
-- Denetim + kontrol listesi + uygunsuzluklarin kuralli derlemesi; kayit olarak saklanir.

CREATE TABLE IF NOT EXISTS audit_reports (
    id INT NOT NULL AUTO_INCREMENT,
    audit_id INT NOT NULL,
    company_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'draft',
    report_date DATE NULL,
    scope_text TEXT NULL,
    methodology_text TEXT NULL,
    findings_text TEXT NULL,
    nonconformity_summary TEXT NULL,
    conclusion TEXT NULL,
    recommendations TEXT NULL,
    source_snapshot JSON NULL,
    generated_by INT NULL,
    generated_at TIMESTAMP NULL,
    approved_by INT NULL,
    approved_at DATETIME NULL,
    created_by INT NULL,
    updated_by INT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_audit_reports_audit (audit_id),
    KEY idx_audit_reports_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
