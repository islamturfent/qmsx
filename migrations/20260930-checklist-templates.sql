-- Denetim kontrol listesi sablonlari: denetciler standart kontrol maddelerini
-- yeniden kullanabilsin diye sirkete bagli sablon kutuphanesi.
CREATE TABLE IF NOT EXISTS audit_checklist_templates (
  id int(11) NOT NULL AUTO_INCREMENT,
  company_id int(11) NOT NULL,
  title varchar(255) NOT NULL,
  description text DEFAULT NULL,
  active tinyint(1) NOT NULL DEFAULT 1,
  created_at timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  KEY idx_checklist_templates_company (company_id),
  CONSTRAINT fk_checklist_templates_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_checklist_template_items (
  id int(11) NOT NULL AUTO_INCREMENT,
  template_id int(11) NOT NULL,
  item_text varchar(255) NOT NULL,
  requirement_ref varchar(120) DEFAULT NULL,
  sort_order int(11) NOT NULL DEFAULT 0,
  active tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY idx_checklist_template_items_template (template_id),
  CONSTRAINT fk_checklist_template_items_template FOREIGN KEY (template_id) REFERENCES audit_checklist_templates (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
