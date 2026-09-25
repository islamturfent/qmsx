-- Personel & Yetkinlik modulu: sirkete bagli personel kaydi ve yetkinlik matrisi.
-- Yetkinlik seviyesi 1-5; durum sonraki degerlendirme tarihinden turetilir.
CREATE TABLE IF NOT EXISTS staff_members (
  id int(11) NOT NULL AUTO_INCREMENT,
  company_id int(11) NOT NULL,
  first_name varchar(80) NOT NULL,
  last_name varchar(80) NOT NULL,
  employee_code varchar(60) DEFAULT NULL,
  department varchar(100) DEFAULT NULL,
  position varchar(120) DEFAULT NULL,
  email varchar(180) DEFAULT NULL,
  phone varchar(60) DEFAULT NULL,
  hired_date date DEFAULT NULL,
  active tinyint(1) NOT NULL DEFAULT 1,
  created_at timestamp NOT NULL DEFAULT current_timestamp(),
  updated_at timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY uq_staff_company_code (company_id, employee_code),
  KEY idx_staff_company (company_id),
  CONSTRAINT fk_staff_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS staff_competencies (
  id int(11) NOT NULL AUTO_INCREMENT,
  staff_id int(11) NOT NULL,
  competency_name varchar(180) NOT NULL,
  level tinyint(4) NOT NULL DEFAULT 3,
  achieved_date date DEFAULT NULL,
  next_assessment_date date DEFAULT NULL,
  notes text DEFAULT NULL,
  active tinyint(1) NOT NULL DEFAULT 1,
  created_at timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  KEY idx_staff_competencies_staff (staff_id),
  CONSTRAINT fk_staff_competencies_staff FOREIGN KEY (staff_id) REFERENCES staff_members (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
