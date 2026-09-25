-- Doküman dağıtım kontrolü: dokümanlara dagitilan kontrollü kopyalari izler.
-- Her kopya numarası belirtir; durum dagitildi/iade/gecersiz olabilir.
CREATE TABLE IF NOT EXISTS document_copies (
  id int(11) NOT NULL AUTO_INCREMENT,
  company_id int(11) NOT NULL,
  document_id int(11) NOT NULL,
  copy_no varchar(40) NOT NULL,
  recipient_name varchar(180) NOT NULL,
  location varchar(180) DEFAULT NULL,
  status varchar(30) NOT NULL DEFAULT 'distributed',
  distributed_on date NOT NULL,
  returned_on date DEFAULT NULL,
  notes text DEFAULT NULL,
  active tinyint(1) NOT NULL DEFAULT 1,
  created_at timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY uq_document_copy_no (document_id, copy_no),
  KEY idx_document_copies_company (company_id),
  KEY idx_document_copies_document (document_id),
  CONSTRAINT fk_document_copies_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
  CONSTRAINT fk_document_copies_document FOREIGN KEY (document_id) REFERENCES documents (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
