CREATE TABLE IF NOT EXISTS corrective_action_evidence (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    corrective_action_id INT NOT NULL,
    original_file_name VARCHAR(255) NOT NULL,
    stored_file_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(150) NOT NULL,
    file_size INT UNSIGNED NOT NULL DEFAULT 0,
    note VARCHAR(255) DEFAULT NULL,
    uploaded_by INT DEFAULT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_capa_evidence_action (corrective_action_id, created_at),
    CONSTRAINT fk_capa_evidence_action FOREIGN KEY (corrective_action_id) REFERENCES corrective_actions(id) ON DELETE CASCADE,
    CONSTRAINT fk_capa_evidence_user FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
