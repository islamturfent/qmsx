-- Kalibrasyon / Ekipman modulu - Task 1.
-- Ekipman kaydi + kalibrasyon gecmisi; kalibrasyon durumu sonraki termin tarihinden turetilir.

CREATE TABLE IF NOT EXISTS equipment (
    id INT NOT NULL AUTO_INCREMENT,
    company_id INT NOT NULL,
    asset_code VARCHAR(60) NULL,
    name VARCHAR(255) NOT NULL,
    category VARCHAR(100) NULL,
    manufacturer VARCHAR(150) NULL,
    model VARCHAR(150) NULL,
    serial_number VARCHAR(120) NULL,
    location VARCHAR(150) NULL,
    responsible_user_id INT NULL,
    calibration_interval_days INT NULL,
    last_calibration_date DATE NULL,
    next_calibration_date DATE NULL,
    notes TEXT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'operational',
    created_by INT NULL,
    updated_by INT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_equipment_company (company_id),
    KEY idx_equipment_next_cal (next_calibration_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS calibrations (
    id INT NOT NULL AUTO_INCREMENT,
    equipment_id INT NOT NULL,
    calibrated_on DATE NULL,
    next_date DATE NULL,
    result VARCHAR(30) NOT NULL DEFAULT 'ok',
    certificate_no VARCHAR(120) NULL,
    performed_by VARCHAR(150) NULL,
    note TEXT NULL,
    created_by INT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_calibrations_equipment (equipment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
