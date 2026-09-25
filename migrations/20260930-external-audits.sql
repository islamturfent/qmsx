-- Dış denetim & kapama takibi: müşteri/belgelendirme/mevzuat gibi dış kuruluslardan
-- gelen denetimler ve bulgularinin kapanisi. Bulgu gecikmesi durum+termin'den turetilir.
CREATE TABLE IF NOT EXISTS external_audits (
  id int(11) NOT NULL AUTO_INCREMENT,
  company_id int(11) NOT NULL,
  audit_type varchar(30) NOT NULL DEFAULT 'customer',
  title varchar(255) NOT NULL,
  audited_by varchar(180) DEFAULT NULL,
  auditor_name varchar(180) DEFAULT NULL,
  audit_date date DEFAULT NULL,
  status varchar(30) NOT NULL DEFAULT 'planned',
  notes text DEFAULT NULL,
  active tinyint(1) NOT NULL DEFAULT 1,
  created_at timestamp NOT NULL DEFAULT current_timestamp(),
  updated_at timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (id),
  KEY idx_external_audits_company (company_id),
  KEY idx_external_audits_status (status),
  CONSTRAINT fk_external_audits_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS external_audit_findings (
  id int(11) NOT NULL AUTO_INCREMENT,
  external_audit_id int(11) NOT NULL,
  finding_text varchar(255) NOT NULL,
  category varchar(30) NOT NULL DEFAULT 'observation',
  severity varchar(20) NOT NULL DEFAULT 'minor',
  due_date date DEFAULT NULL,
  status varchar(30) NOT NULL DEFAULT 'open',
  closed_date date DEFAULT NULL,
  notes text DEFAULT NULL,
  active tinyint(1) NOT NULL DEFAULT 1,
  created_at timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  KEY idx_external_findings_audit (external_audit_id),
  CONSTRAINT fk_external_findings_audit FOREIGN KEY (external_audit_id) REFERENCES external_audits (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
