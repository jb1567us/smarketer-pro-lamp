-- Item 4 (2026-09-24): TrustScorer persistence columns on leads.
-- Safe to run multiple times: every statement is guarded via procedure.
-- Fresh installs get these from schema.sql; run this on existing databases
-- (phpMyAdmin / cPanel SQL runner) so harvest-time trust scores persist.
--
-- Context: SimpleHarvester::scoreHarvestResults() scores harvest results with
-- the real App\Verification\TrustScorer and SimpleHarvester::stageResults()
-- persists trust_score / trust_breakdown / verification_status when the
-- columns exist. stageResults() also probes for the columns at runtime, so a
-- database without this migration still harvests fine (trust persistence is
-- skipped, never fatal).

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

CALL add_col_if_missing('leads', 'trust_score', "`trust_score` INT DEFAULT 0 COMMENT 'Item 4: genuine TrustScorer result (0-100), 0 = unscored'");
CALL add_col_if_missing('leads', 'trust_breakdown', "`trust_breakdown` JSON DEFAULT NULL COMMENT 'Item 4: TrustScorer per-level breakdown'");

DROP PROCEDURE IF EXISTS add_col_if_missing;

-- Optional DNS kill-switch for harvest-time scoring (default ON).
-- Set to '0' on hosts with pathological DNS resolvers to skip the only
-- network I/O in scoring. See docs/ITEM4_TRUSTSCORER.md.
INSERT INTO settings (setting_key, setting_value)
VALUES ('trustscorer_harvest_dns', '1')
ON DUPLICATE KEY UPDATE setting_value = setting_value;
