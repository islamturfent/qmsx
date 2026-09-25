-- Müşteri memnuniyeti ankette modulu: sirkete bagli anket sablonlari, soru seti,
-- müşteri yanitlari ve soru bazinda 1-5 degerlendirme.
CREATE TABLE IF NOT EXISTS satisfaction_surveys (
  id int(11) NOT NULL AUTO_INCREMENT,
  company_id int(11) NOT NULL,
  title varchar(255) NOT NULL,
  description text DEFAULT NULL,
  active tinyint(1) NOT NULL DEFAULT 1,
  created_at timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  KEY idx_satisfaction_surveys_company (company_id),
  CONSTRAINT fk_satisfaction_surveys_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS satisfaction_questions (
  id int(11) NOT NULL AUTO_INCREMENT,
  survey_id int(11) NOT NULL,
  question_text varchar(255) NOT NULL,
  sort_order int(11) NOT NULL DEFAULT 0,
  active tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY idx_satisfaction_questions_survey (survey_id),
  CONSTRAINT fk_satisfaction_questions_survey FOREIGN KEY (survey_id) REFERENCES satisfaction_surveys (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS satisfaction_responses (
  id int(11) NOT NULL AUTO_INCREMENT,
  survey_id int(11) NOT NULL,
  company_id int(11) NOT NULL,
  customer_name varchar(180) DEFAULT NULL,
  customer_contact varchar(180) DEFAULT NULL,
  responded_at date NOT NULL,
  overall_score tinyint(4) NOT NULL DEFAULT 3,
  comment text DEFAULT NULL,
  active tinyint(1) NOT NULL DEFAULT 1,
  created_at timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  KEY idx_satisfaction_responses_survey (survey_id),
  KEY idx_satisfaction_responses_company (company_id),
  CONSTRAINT fk_satisfaction_responses_survey FOREIGN KEY (survey_id) REFERENCES satisfaction_surveys (id) ON DELETE CASCADE,
  CONSTRAINT fk_satisfaction_responses_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS satisfaction_response_answers (
  id int(11) NOT NULL AUTO_INCREMENT,
  response_id int(11) NOT NULL,
  question_id int(11) NOT NULL,
  rating tinyint(4) NOT NULL,
  active tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY idx_satisfaction_answers_response (response_id),
  KEY idx_satisfaction_answers_question (question_id),
  CONSTRAINT fk_satisfaction_answers_response FOREIGN KEY (response_id) REFERENCES satisfaction_responses (id) ON DELETE CASCADE,
  CONSTRAINT fk_satisfaction_answers_question FOREIGN KEY (question_id) REFERENCES satisfaction_questions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
