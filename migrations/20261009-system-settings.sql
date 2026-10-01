-- Sistem geneli ayarlar (key/value). Varsayilanlar PHP tarafinda
-- includes/settings-functions.php icinde tutulur; bu tabloda sadece
-- adminin degistirdigi degerler saklanir.
CREATE TABLE IF NOT EXISTS system_settings (
    `key` VARCHAR(80) NOT NULL PRIMARY KEY,
    `value` TEXT NULL,
    updated_by INT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
