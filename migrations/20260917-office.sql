CREATE TABLE IF NOT EXISTS office_sessions (
    file_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
    document_id INT NOT NULL,
    company_id INT NOT NULL,
    user_id INT NOT NULL,
    version_id INT NOT NULL,
    can_write TINYINT(1) NOT NULL DEFAULT 0,
    config_hash CHAR(64) NOT NULL,
    expires_at BIGINT NOT NULL,
    revoked TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX office_session_expiry (expires_at),
    INDEX office_session_document (document_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS office_locks (
    document_id INT PRIMARY KEY,
    file_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    lock_value VARBINARY(1024) NOT NULL,
    expires_at BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS office_audit (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id INT NULL,
    document_id INT NULL,
    user_id INT NULL,
    version_id INT NULL,
    event VARCHAR(60) NOT NULL,
    outcome VARCHAR(20) NOT NULL,
    source_ip VARCHAR(45) NOT NULL DEFAULT '',
    details VARCHAR(500) NOT NULL DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX office_audit_document (document_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
