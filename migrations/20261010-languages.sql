-- Dil yonetimi: diller + anahtar-bazli ceviriler. Front-end (language.js)
-- secili dilin cevirilerini language-data.php uzerinden alir ve varsayilan
-- TR/EN ile birlestirir; yeni dil eklenince buradan tanimlanir.
CREATE TABLE IF NOT EXISTS languages (
    code VARCHAR(16) NOT NULL PRIMARY KEY,
    name VARCHAR(80) NOT NULL,
    native_name VARCHAR(80) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS translations (
    lang VARCHAR(16) NOT NULL,
    `key` VARCHAR(120) NOT NULL,
    `value` TEXT NULL,
    PRIMARY KEY (lang, `key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Yerlesik temel diller (tr/en) her zaman mevcut; cevirileri language.js icin
-- sabittir. Bu kayitlar UI listesinde gorunur.
INSERT IGNORE INTO languages (code, name, native_name, is_active, sort_order) VALUES
('tr','Turkish','Türkçe',1,1),
('en','English','English',1,2);
