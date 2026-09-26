-- Kalibrasyon & Metroloji sertifika/gecmis: alet basina kalibrasyon kaydi
-- ve sertifika dosyasi. Alet silindiginde kayitlar CASCADE ile temizlenir.
CREATE TABLE IF NOT EXISTS instrument_calibrations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  instrument_id INT NOT NULL,
  company_id INT NOT NULL,
  calibration_date DATE NULL,
  due_date DATE NULL,
  result VARCHAR(20) NOT NULL DEFAULT 'pass',
  cert_number VARCHAR(80) NULL,
  lab_name VARCHAR(180) NULL,
  certificate_file VARCHAR(255) NULL,
  performed_by VARCHAR(180) NULL,
  notes TEXT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_instcal_instrument (instrument_id),
  KEY idx_instcal_company (company_id),
  CONSTRAINT fk_instcal_instrument FOREIGN KEY (instrument_id) REFERENCES instruments (id) ON DELETE CASCADE,
  CONSTRAINT fk_instcal_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
