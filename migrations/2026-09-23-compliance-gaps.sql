-- Compliance gaps migration (2026-09-23).
-- Covers compliance gap items 1-9: webhook events, email_logs attribution,
-- campaign pause/throttle columns, DNS preflight cache, CASL country gate,
-- GDPR suppression hash + lawful basis, verification columns.
--
-- SAFE TO RUN MULTIPLE TIMES: every statement is CREATE TABLE IF NOT EXISTS
-- or guarded by a stored-procedure check. Fresh installs get the same shape
-- from schema.sql; run this file on existing databases.

-- ------------------------------------------------------------------
-- Guard helpers (dropped at the end of this file).
-- MySQL/MariaDB have no ADD COLUMN IF NOT EXISTS, so we probe
-- INFORMATION_SCHEMA instead.
-- ------------------------------------------------------------------
DELIMITER $$
DROP PROCEDURE IF EXISTS gaps_add_col$$
CREATE PROCEDURE gaps_add_col(IN t VARCHAR(64), IN c VARCHAR(64), IN ddl TEXT)
BEGIN
    -- Skip entirely when the table itself is absent (e.g. minimal test DBs).
    IF EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = t
    ) AND NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = t AND COLUMN_NAME = c
    ) THEN
        SET @sql = CONCAT('ALTER TABLE `', t, '` ADD COLUMN ', ddl);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$

DROP PROCEDURE IF EXISTS gaps_add_index$$
CREATE PROCEDURE gaps_add_index(IN t VARCHAR(64), IN idx_name VARCHAR(64), IN idx_ddl TEXT)
BEGIN
    IF EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = t
    ) AND NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = t AND INDEX_NAME = idx_name
    ) THEN
        SET @sql = CONCAT('ALTER TABLE `', t, '` ADD ', idx_ddl);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$
DELIMITER ;

-- ------------------------------------------------------------------
-- Item 1+2: webhook_events (bounce + complaint webhooks).
-- Canonical DDL mirrors ComplaintHandler::TABLE_DDL exactly.
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS webhook_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    provider VARCHAR(50) NOT NULL,
    event_type VARCHAR(50) NOT NULL,
    email VARCHAR(255) NOT NULL DEFAULT '',
    payload_json TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_we_email (email),
    INDEX idx_we_provider_event (provider, event_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------------
-- Item 6: domain_auth_cache (SPF/DKIM/DMARC preflight result cache).
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS domain_auth_cache (
    domain VARCHAR(255) NOT NULL PRIMARY KEY,
    result_json TEXT NOT NULL,
    checked_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------------
-- Item 8: casl_decisions (auditable CASL recipient-country decisions).
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS casl_decisions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    country_code CHAR(2) NULL,
    decision ENUM('allow','block') NOT NULL,
    rule VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_casl_email (email),
    INDEX idx_casl_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------------
-- leads: campaign attribution, CASL country, GDPR lawful basis,
-- and the 'Drafted' pipeline stage the drafting agents already write.
-- ------------------------------------------------------------------
CALL gaps_add_col('leads', 'campaign_id', '`campaign_id` INT NULL');
CALL gaps_add_index('leads', 'idx_leads_campaign', 'INDEX idx_leads_campaign (`campaign_id`)');

CALL gaps_add_col('leads', 'country_code', '`country_code` CHAR(2) NULL');
CALL gaps_add_index('leads', 'idx_leads_country', 'INDEX idx_leads_country (`country_code`)');

CALL gaps_add_col('leads', 'lawful_basis', '`lawful_basis` VARCHAR(50) NULL');

-- DraftOutreachAction, EmailDraftingAgent and runner.php write
-- leads.status='Drafted'; the ENUM must accept it (strict mode would
-- otherwise reject those UPDATEs).
DELIMITER $$
DROP PROCEDURE IF EXISTS gaps_enum_add$$
CREATE PROCEDURE gaps_enum_add(IN t VARCHAR(64), IN c VARCHAR(64), IN new_val VARCHAR(64), IN full_enum TEXT)
BEGIN
    IF EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = t
    ) AND NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = t AND COLUMN_NAME = c
          AND COLUMN_TYPE LIKE CONCAT('%''', new_val, '''%')
    ) THEN
        SET @sql = CONCAT('ALTER TABLE `', t, '` MODIFY COLUMN `', c, '` ', full_enum);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$
DELIMITER ;

CALL gaps_enum_add('leads', 'status', 'Drafted',
    "ENUM('New','Enriched','Contacted','Qualified','Unqualified','Converted','Drafted') DEFAULT 'New'");

-- ------------------------------------------------------------------
-- campaigns: auto-pause state (item 4), per-campaign daily cap (item 4),
-- DNS preflight override (item 6).
-- ------------------------------------------------------------------
CALL gaps_add_col('campaigns', 'status', "`status` VARCHAR(20) NOT NULL DEFAULT 'active'");
CALL gaps_add_col('campaigns', 'paused_reason', '`paused_reason` VARCHAR(255) NULL');
CALL gaps_add_col('campaigns', 'paused_at', '`paused_at` TIMESTAMP NULL');
CALL gaps_add_col('campaigns', 'daily_send_cap', '`daily_send_cap` INT NULL');
CALL gaps_add_col('campaigns', 'dns_preflight_override', '`dns_preflight_override` TINYINT(1) NOT NULL DEFAULT 0');

-- ------------------------------------------------------------------
-- email_logs: campaign attribution (item 4 caps + monitor need it) and
-- the 'bounced' status the bounce webhook writes.
-- ------------------------------------------------------------------
CALL gaps_add_col('email_logs', 'campaign_id', '`campaign_id` INT NULL');
CALL gaps_add_index('email_logs', 'idx_email_campaign', 'INDEX idx_email_campaign (`campaign_id`, `timestamp`)');

CALL gaps_enum_add('email_logs', 'status', 'bounced',
    "ENUM('sent','failed','queued','bounced') DEFAULT 'sent'");

-- ------------------------------------------------------------------
-- Item 5 (GDPR): suppression_list redesign.
--   - email becomes NULLABLE (erasure removes plaintext PII);
--   - email_hash VARCHAR(64) holds the keyed hash retained for
--     "do not contact" enforcement, with unique uq_suppression_hash;
--   - keep uq_suppression_email; drop the redundant non-unique
--     idx_suppression_email (the unique key already indexes email).
-- ------------------------------------------------------------------
DELIMITER $$
DROP PROCEDURE IF EXISTS gaps_suppression_redesign$$
CREATE PROCEDURE gaps_suppression_redesign()
proc: BEGIN
    -- No-op when the table is absent (minimal test DBs).
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'suppression_list'
    ) THEN
        LEAVE proc;
    END IF;

    -- 1. email -> NULL (only when it is still NOT NULL).
    IF EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'suppression_list'
          AND COLUMN_NAME = 'email' AND IS_NULLABLE = 'NO'
    ) THEN
        ALTER TABLE `suppression_list` MODIFY COLUMN `email` VARCHAR(255) NULL;
    END IF;

    -- 2. email_hash column.
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'suppression_list'
          AND COLUMN_NAME = 'email_hash'
    ) THEN
        ALTER TABLE `suppression_list` ADD COLUMN `email_hash` VARCHAR(64) NULL;
    END IF;

    -- 3. Unique key on email_hash.
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'suppression_list'
          AND INDEX_NAME = 'uq_suppression_hash'
    ) THEN
        ALTER TABLE `suppression_list` ADD UNIQUE KEY `uq_suppression_hash` (`email_hash`);
    END IF;

    -- 4. Drop the redundant non-unique email index (uq_suppression_email
    --    already covers email lookups). Only when the plain index exists.
    IF EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'suppression_list'
          AND INDEX_NAME = 'idx_suppression_email' AND NON_UNIQUE = 1
    ) THEN
        ALTER TABLE `suppression_list` DROP INDEX `idx_suppression_email`;
    END IF;
END$$
DELIMITER ;

CALL gaps_suppression_redesign();

-- ------------------------------------------------------------------
-- Cleanup: drop the guard helpers.
-- ------------------------------------------------------------------
DROP PROCEDURE IF EXISTS gaps_add_col;
DROP PROCEDURE IF EXISTS gaps_add_index;
DROP PROCEDURE IF EXISTS gaps_enum_add;
DROP PROCEDURE IF EXISTS gaps_suppression_redesign;
