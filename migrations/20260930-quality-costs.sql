-- Kalite maliyeti (COQ) modulu: onleme / degerlendirme / ic hata / dis hata
-- maliyetlerini kayit altina alir; donemsel toplam raporlanir.
CREATE TABLE IF NOT EXISTS quality_costs (
  id int(11) NOT NULL AUTO_INCREMENT,
  company_id int(11) NOT NULL,
  cost_type varchar(30) NOT NULL DEFAULT 'prevention',
  title varchar(255) NOT NULL,
  amount decimal(12,2) NOT NULL DEFAULT 0.00,
  incurred_on date NOT NULL,
  notes text DEFAULT NULL,
  active tinyint(1) NOT NULL DEFAULT 1,
  created_by int(11) DEFAULT NULL,
  created_at timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  KEY idx_quality_costs_company (company_id),
  KEY idx_quality_costs_type (cost_type),
  CONSTRAINT fk_quality_costs_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
  CONSTRAINT fk_quality_costs_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
