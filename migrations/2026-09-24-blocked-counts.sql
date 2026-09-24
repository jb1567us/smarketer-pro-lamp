-- ITEM A (2026-09-24): per-campaign blocked-send counters.
-- Safe to run multiple times: every column add is guarded by the
-- add_col_if_missing procedure (MySQL/MariaDB have no ADD COLUMN IF NOT EXISTS).
-- Fresh installs get these from schema.sql; run this on existing databases.
--
-- Semantics: one counter per send-refusal reason, incremented at the moment
-- a send is refused. A lost increment on crash is acceptable; the columns
-- live on campaigns so counts survive page reloads and cron restarts.

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

CALL add_col_if_missing('campaigns', 'blocked_invalid_verification', "`blocked_invalid_verification` INT NOT NULL DEFAULT 0");
CALL add_col_if_missing('campaigns', 'blocked_suppression', "`blocked_suppression` INT NOT NULL DEFAULT 0");
CALL add_col_if_missing('campaigns', 'blocked_compliance_pause', "`blocked_compliance_pause` INT NOT NULL DEFAULT 0");
CALL add_col_if_missing('campaigns', 'blocked_throttle', "`blocked_throttle` INT NOT NULL DEFAULT 0");
CALL add_col_if_missing('campaigns', 'blocked_license_revoked', "`blocked_license_revoked` INT NOT NULL DEFAULT 0");
CALL add_col_if_missing('campaigns', 'blocked_placeholder', "`blocked_placeholder` INT NOT NULL DEFAULT 0");

DROP PROCEDURE IF EXISTS add_col_if_missing;
