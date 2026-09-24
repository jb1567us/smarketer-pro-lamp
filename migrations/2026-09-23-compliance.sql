-- Compliance foundation migration (2026-09-23).
-- Safe to run multiple times: every statement is IF NOT EXISTS / guarded.
-- Fresh installs get these from schema.sql; run this on existing databases.

CREATE TABLE IF NOT EXISTS suppression_list (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    reason VARCHAR(50) NOT NULL DEFAULT 'unsubscribe',
    source VARCHAR(100) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_suppression_email (email),
    INDEX idx_suppression_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Leads: consent + verification + provenance columns.
-- MySQL/MariaDB have no ADD COLUMN IF NOT EXISTS, so guard via procedure.
DELIMITER $$
DROP PROCEDURE IF EXISTS add_col_if_missing$$
CREATE PROCEDURE add_col_if_missing(IN t VARCHAR(64), IN c VARCHAR(64), IN ddl TEXT)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = t AND COLUMN_NAME = c
    ) THEN
        SET @sql = CONCAT('ALTER TABLE `', t, '` ADD COLUMN ', ddl);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$
DELIMITER ;

CALL add_col_if_missing('leads', 'consent_status', "`consent_status` ENUM('unknown','implied','express') NOT NULL DEFAULT 'unknown'");
CALL add_col_if_missing('leads', 'consent_proof', "`consent_proof` TEXT NULL");
CALL add_col_if_missing('leads', 'verification_status', "`verification_status` ENUM('unverified','evidence_backed','dns_confirmed','cross_source_matched','gold_standard','unknown','valid','invalid','risky') NOT NULL DEFAULT 'unknown'");
CALL add_col_if_missing('leads', 'verified_at', "`verified_at` TIMESTAMP NULL");
CALL add_col_if_missing('leads', 'is_role_based', "`is_role_based` TINYINT(1) NOT NULL DEFAULT 0");
CALL add_col_if_missing('leads', 'target_persona', "`target_persona` VARCHAR(255) NULL");
CALL add_col_if_missing('leads', 'email_source', "`email_source` VARCHAR(50) NULL");
CALL add_col_if_missing('leads', 'source_url', "`source_url` TEXT NULL");

DROP PROCEDURE IF EXISTS add_col_if_missing;
