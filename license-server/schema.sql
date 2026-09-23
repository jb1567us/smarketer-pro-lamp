-- Smarketer Pro license server schema.
-- Standalone: import into its own MySQL/MariaDB database on plain shared hosting.
-- Safe to run multiple times (IF NOT EXISTS / INSERT IGNORE throughout).

CREATE TABLE IF NOT EXISTS licenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    -- Deterministic lookup fingerprint: sha256 of the normalized key.
    -- The key itself is never stored; verification uses key_hash.
    key_fp CHAR(64) NOT NULL,
    key_hash VARCHAR(255) NOT NULL,
    label VARCHAR(255) NOT NULL DEFAULT '',
    buyer_email VARCHAR(255) NULL,
    max_domains TINYINT UNSIGNED NOT NULL DEFAULT 1,
    status ENUM('active','revoked') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_key_fp (key_fp),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS license_domains (
    id INT AUTO_INCREMENT PRIMARY KEY,
    license_id INT NOT NULL,
    -- Normalized: lowercase, no scheme/path/port, leading www. stripped.
    domain VARCHAR(255) NOT NULL,
    first_seen TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_validated TIMESTAMP NULL,
    UNIQUE KEY uq_license_domain (license_id, domain),
    INDEX idx_domain (domain),
    CONSTRAINT fk_license_domains_license
        FOREIGN KEY (license_id) REFERENCES licenses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Simple per-IP rate-limit buckets for the public API.
CREATE TABLE IF NOT EXISTS rate_limits (
    ip VARCHAR(45) NOT NULL PRIMARY KEY,
    window_start INT UNSIGNED NOT NULL,
    hits INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
