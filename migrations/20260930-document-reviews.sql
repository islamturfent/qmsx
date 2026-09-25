-- Doküman gözden geçirme merkezi: her gözden geçirme islemi kayit altina alinir
-- (karar, not, sonraki gözden geçirme tarihi) ve dokümanin review_date ilerletilir.
CREATE TABLE IF NOT EXISTS document_reviews (
  id int(11) NOT NULL AUTO_INCREMENT,
  document_id int(11) NOT NULL,
  company_id int(11) NOT NULL,
  reviewer_user_id int(11) DEFAULT NULL,
  reviewed_at date NOT NULL,
  outcome varchar(20) NOT NULL DEFAULT 'ok',
  notes text DEFAULT NULL,
  next_review_date date DEFAULT NULL,
  active tinyint(1) NOT NULL DEFAULT 1,
  created_at timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  KEY idx_document_reviews_document (document_id),
  KEY idx_document_reviews_company (company_id),
  KEY idx_document_reviews_reviewer (reviewer_user_id),
  CONSTRAINT fk_document_reviews_document FOREIGN KEY (document_id) REFERENCES documents (id) ON DELETE CASCADE,
  CONSTRAINT fk_document_reviews_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
  CONSTRAINT fk_document_reviews_reviewer FOREIGN KEY (reviewer_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
