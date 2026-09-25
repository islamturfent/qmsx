-- Onay & imza workflow: kayitlara bagli cok adimli onay/imza akisi.
-- Her imza, akistaki bir adimi onaylar; adimlar sirasiyla tamamlanir.
CREATE TABLE IF NOT EXISTS approval_runs (
  id int(11) NOT NULL AUTO_INCREMENT,
  company_id int(11) NOT NULL,
  subject varchar(255) NOT NULL,
  entity_type varchar(60) DEFAULT NULL,
  entity_id int(11) DEFAULT NULL,
  status varchar(30) NOT NULL DEFAULT 'in_progress',
  active tinyint(1) NOT NULL DEFAULT 1,
  created_by int(11) DEFAULT NULL,
  created_at timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  KEY idx_approval_runs_company (company_id),
  KEY idx_approval_runs_status (status),
  CONSTRAINT fk_approval_runs_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
  CONSTRAINT fk_approval_runs_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS approval_run_steps (
  id int(11) NOT NULL AUTO_INCREMENT,
  run_id int(11) NOT NULL,
  step_order int(11) NOT NULL,
  step_name varchar(180) NOT NULL,
  approver_user_id int(11) NOT NULL,
  decision varchar(20) NOT NULL DEFAULT 'pending',
  comment text DEFAULT NULL,
  signed_at datetime DEFAULT NULL,
  active tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY idx_approval_steps_run (run_id),
  KEY idx_approval_steps_approver (approver_user_id),
  CONSTRAINT fk_approval_steps_run FOREIGN KEY (run_id) REFERENCES approval_runs (id) ON DELETE CASCADE,
  CONSTRAINT fk_approval_steps_approver FOREIGN KEY (approver_user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
