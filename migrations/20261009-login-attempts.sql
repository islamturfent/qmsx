-- Basarisiz login denemeleri (G: Login guvenligi). Kullanici adi/IP bazli
-- kisa sureli kilit icin kullanilir; basarili giris sonrasi temizlenir.
CREATE TABLE IF NOT EXISTS login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(190) NOT NULL,
    ip_address VARCHAR(64) NULL,
    attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_la_user (username, attempted_at),
    KEY idx_la_ip (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
