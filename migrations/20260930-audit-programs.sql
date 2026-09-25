-- Ic denetim programi modulu - Task 3.
-- Yillik denetim programi: denetimlerin bir planda bir araya getirilmesi.

CREATE TABLE IF NOT EXISTS audit_programs (
    id INT NOT NULL AUTO_INCREMENT,
    company_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    year SMALLINT NOT NULL,
    description TEXT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'draft',
    approved_date DATE NULL,
    created_by INT NULL,
    updated_by INT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_programs_company (company_id),
    KEY idx_audit_programs_year (year),
    KEY idx_audit_programs_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS audit_program_audits (
    program_id INT NOT NULL,
    audit_id INT NOT NULL,
    added_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (program_id, audit_id),
    KEY idx_apa_audit (audit_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
