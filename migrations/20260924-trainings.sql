-- Egitim yonetimi modulu (knowhow Faz 3).
--
-- Katilimcilar, sirketin sistem kullanicilaridir (sirket kullanicisi + o sirkete
-- atanmis sistem adminleri). Bu, CAPA sorumlu-kullanici modeliyle ayni yaklasimdir;
-- ileride giris yapmayan personel icin ayri bir personel kaydi eklenebilir.

CREATE TABLE IF NOT EXISTS trainings (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    category VARCHAR(100) DEFAULT NULL,
    provider VARCHAR(180) DEFAULT NULL,
    trainer_name VARCHAR(150) DEFAULT NULL,
    planned_date DATE DEFAULT NULL,
    completed_date DATE DEFAULT NULL,
    duration_hours DECIMAL(6,2) DEFAULT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'planned',
    description TEXT DEFAULT NULL,
    created_by INT DEFAULT NULL,
    updated_by INT DEFAULT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_trainings_company (company_id),
    INDEX idx_trainings_status (status),
    INDEX idx_trainings_planned (planned_date),
    CONSTRAINT fk_trainings_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    CONSTRAINT fk_trainings_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_trainings_updater FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS training_participants (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    training_id INT NOT NULL,
    user_id INT NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'assigned',
    score DECIMAL(5,2) DEFAULT NULL,
    note VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_training_participant (training_id, user_id),
    INDEX idx_training_participants_user (user_id),
    CONSTRAINT fk_training_participants_training FOREIGN KEY (training_id) REFERENCES trainings(id) ON DELETE CASCADE,
    CONSTRAINT fk_training_participants_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
